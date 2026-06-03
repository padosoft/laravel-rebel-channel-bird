<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\Rebel\Channel\Bird\Gateway\RestBirdGateway;

function gateway(): RestBirdGateway
{
    return new RestBirdGateway(app(HttpFactory::class), 'test_access_key', 'Rebel');
}

it('starts a verification through the Bird Verify endpoint with the AccessKey header', function (): void {
    Http::fake([
        'rest.messagebird.com/verify' => Http::response(['id' => 'verify-abc', 'status' => 'sent'], 201),
    ]);

    $result = gateway()->startVerification('+393331234567', 'sms');

    expect($result)->toBe(['id' => 'verify-abc', 'status' => 'sent']);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://rest.messagebird.com/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'AccessKey test_access_key')
            && $request['recipient'] === '+393331234567'
            && $request['type'] === 'sms'
            && $request['originator'] === 'Rebel';
    });
});

it('checks a token through the Bird Verify endpoint and returns the status', function (): void {
    Http::fake([
        'rest.messagebird.com/verify/*' => Http::response(['id' => 'verify-abc', 'status' => 'verified'], 200),
    ]);

    expect(gateway()->checkVerification('verify-abc', '123456'))->toBe('verified');

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://rest.messagebird.com/verify/verify-abc')
            && $request->method() === 'GET'
            && str_contains($request->url(), 'token=123456');
    });
});

it('sends an SMS and extracts the first recipient status', function (): void {
    Http::fake([
        'rest.messagebird.com/messages' => Http::response([
            'id' => 'message-xyz',
            'recipients' => ['items' => [['recipient' => 393331234567, 'status' => 'sent']]],
        ], 201),
    ]);

    $result = gateway()->sendMessage('+393331234567', 'hello');

    expect($result)->toBe(['id' => 'message-xyz', 'status' => 'sent']);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://rest.messagebird.com/messages'
            && $request['originator'] === 'Rebel'
            && $request['recipients'] === ['+393331234567']
            && $request['body'] === 'hello';
    });
});

it('returns empty fields gracefully when the API omits them', function (): void {
    Http::fake([
        'rest.messagebird.com/messages' => Http::response([], 200),
    ]);

    expect(gateway()->sendMessage('+393331234567', 'hi'))->toBe(['id' => '', 'status' => '']);
});
