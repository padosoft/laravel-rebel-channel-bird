<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Padosoft\Rebel\Channel\Bird\Http\BirdSignatureValidator;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Contracts\AuditLogger;

/**
 * In-memory AuditLogger so we can assert exactly what the webhook records
 * without depending on the DB layer.
 */
function fakeAudit(): AuditLogger
{
    $logger = new class implements AuditLogger
    {
        /** @var list<AuditEvent> */
        public array $events = [];

        public function record(AuditEvent $event): void
        {
            $this->events[] = $event;
        }
    };

    app()->instance(AuditLogger::class, $logger);

    return $logger;
}

beforeEach(function (): void {
    // Default: disable signature validation so we exercise the happy path; individual
    // tests that care about signatures flip it back on.
    config()->set('rebel-channel-bird.webhook.validate_signature', false);
});

it('registers the status route named rebel-bird.status at the configured path', function (): void {
    expect(Route::has('rebel-bird.status'))->toBeTrue();

    $route = Route::getRoutes()->getByName('rebel-bird.status');
    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('rebel/bird/status')
        ->and($route?->methods())->toContain('POST');
});

it('records channel.verification.delivered with the absolute price in metadata', function (): void {
    $audit = fakeAudit();

    $response = $this->postJson('rebel/bird/status', [
        'id' => 'message-123',
        'status' => 'delivered',
        'recipient' => '+393331234567',
        'price' => ['amount' => 0.045, 'currency' => 'EUR'],
    ]);

    $response->assertNoContent();

    expect($audit->events)->toHaveCount(1);
    $event = $audit->events[0];

    expect($event->type)->toBe('channel.verification.delivered')
        ->and($event->provider)->toBe('bird')
        ->and($event->channel)->toBe('sms')
        ->and($event->identifierHmac)->not->toBeNull()
        ->and($event->identifierHmac)->not->toBe('+393331234567')
        ->and($event->keyVersion)->toBe(1)
        ->and($event->metadata['message_status'])->toBe('delivered')
        ->and($event->metadata['price'])->toBe(0.045)
        ->and($event->metadata['price_unit'])->toBe('EUR')
        ->and($event->metadata['message_sid'])->toBe('message-123')
        ->and($event->metadata['error_code'])->toBeNull();
});

it('reads a flat numeric price and currency field too', function (): void {
    $audit = fakeAudit();

    $this->postJson('rebel/bird/status', [
        'id' => 'message-7',
        'status' => 'delivered',
        'recipient' => '+393331234567',
        'price' => -0.02,
        'currency' => 'USD',
    ])->assertNoContent();

    expect($audit->events[0]->metadata['price'])->toBe(0.02)
        ->and($audit->events[0]->metadata['price_unit'])->toBe('USD');
});

it('records channel.verification.undelivered for a failed callback with the error code', function (): void {
    $audit = fakeAudit();

    $response = $this->postJson('rebel/bird/status', [
        'id' => 'message-999',
        'status' => 'delivery_failed',
        'recipient' => '+393339999999',
        'statusErrorCode' => '13',
    ]);

    $response->assertNoContent();

    expect($audit->events)->toHaveCount(1)
        ->and($audit->events[0]->type)->toBe('channel.verification.undelivered')
        ->and($audit->events[0]->metadata['error_code'])->toBe('13')
        ->and($audit->events[0]->metadata['message_sid'])->toBe('message-999')
        ->and($audit->events[0]->metadata['price'])->toBeNull();
});

it('treats expired as undelivered as well', function (): void {
    $audit = fakeAudit();

    $this->postJson('rebel/bird/status', [
        'status' => 'expired',
        'recipient' => '+393331112222',
    ])->assertNoContent();

    expect($audit->events[0]->type)->toBe('channel.verification.undelivered');
});

it('records channel.verification.dispatched for buffered/sent statuses', function (): void {
    $audit = fakeAudit();

    $this->postJson('rebel/bird/status', [
        'status' => 'buffered',
        'recipient' => '+393334445555',
    ])->assertNoContent();

    expect($audit->events)->toHaveCount(1)
        ->and($audit->events[0]->type)->toBe('channel.verification.dispatched');
});

it('acknowledges a malformed payload with 204 and records nothing', function (): void {
    $audit = fakeAudit();

    // No status, no recipient.
    $this->postJson('rebel/bird/status', ['foo' => 'bar'])->assertNoContent();
    // Recipient but no status.
    $this->postJson('rebel/bird/status', ['recipient' => '+393330000000'])->assertNoContent();
    // Status but no recipient.
    $this->postJson('rebel/bird/status', ['status' => 'delivered'])->assertNoContent();

    expect($audit->events)->toBeEmpty();
});

it('never stores the raw phone number (identifier is HMAC of recipient)', function (): void {
    $audit = fakeAudit();

    $this->postJson('rebel/bird/status', [
        'status' => 'delivered',
        'recipient' => '+393331234567',
    ])->assertNoContent();

    $hmac = $audit->events[0]->identifierHmac;
    expect($hmac)->not->toBeNull()
        ->and(str_contains((string) $hmac, '393331234567'))->toBeFalse();
});

it('rejects a bad signature with 403 when validation is enabled', function (): void {
    config()->set('rebel-channel-bird.webhook.validate_signature', true);
    config()->set('rebel-channel-bird.webhook.signing_key', 'super-secret-signing-key');
    $audit = fakeAudit();

    $response = $this->call(
        'POST',
        'rebel/bird/status',
        [],
        [],
        [],
        ['HTTP_MESSAGEBIRD_SIGNATURE' => 'totally-bogus', 'HTTP_MESSAGEBIRD_REQUEST_TIMESTAMP' => '1700000000', 'CONTENT_TYPE' => 'application/json'],
        (string) json_encode(['status' => 'delivered', 'recipient' => '+393331234567']),
    );

    $response->assertForbidden();
    expect($audit->events)->toBeEmpty();
});

it('rejects a missing signature with 403 when validation is enabled', function (): void {
    config()->set('rebel-channel-bird.webhook.validate_signature', true);
    config()->set('rebel-channel-bird.webhook.signing_key', 'super-secret-signing-key');
    $audit = fakeAudit();

    $this->postJson('rebel/bird/status', [
        'status' => 'delivered',
        'recipient' => '+393331234567',
    ])->assertForbidden();

    expect($audit->events)->toBeEmpty();
});

it('accepts a valid signature when validation is enabled', function (): void {
    config()->set('rebel-channel-bird.webhook.validate_signature', true);
    $signingKey = 'super-secret-signing-key';
    config()->set('rebel-channel-bird.webhook.signing_key', $signingKey);
    $audit = fakeAudit();

    $body = (string) json_encode(['status' => 'delivered', 'recipient' => '+393331234567']);
    $timestamp = '1700000000';
    $url = url('rebel/bird/status');

    $signedPayload = $timestamp."\n".$url."\n".hash('sha256', $body, true);
    $signature = base64_encode(hash_hmac('sha256', $signedPayload, $signingKey, true));

    $response = $this->call(
        'POST',
        'rebel/bird/status',
        [],
        [],
        [],
        [
            'HTTP_MESSAGEBIRD_SIGNATURE' => $signature,
            'HTTP_MESSAGEBIRD_REQUEST_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ],
        $body,
    );

    $response->assertNoContent();
    expect($audit->events)->toHaveCount(1)
        ->and($audit->events[0]->type)->toBe('channel.verification.delivered');
});

it('returns false from the signature validator when the signing key is empty', function (): void {
    $validator = new BirdSignatureValidator('');
    $request = Request::create('https://example.test/rebel/bird/status', 'POST', [], [], [], [], '{}');
    $request->headers->set('MessageBird-Signature', 'anything');
    $request->headers->set('MessageBird-Request-Timestamp', '1700000000');

    expect($validator->isValid($request))->toBeFalse();
});
