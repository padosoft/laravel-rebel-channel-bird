<?php

declare(strict_types=1);

use Padosoft\Rebel\Channel\Bird\Contracts\BirdGateway;
use Padosoft\Rebel\Channel\Bird\Testing\FakeBirdGateway;
use Padosoft\Rebel\Channel\Bird\Verification\BirdVerifyProvider;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

function ctx(): SecurityContext
{
    return new SecurityContext('req-1');
}

it('starts a verification (pending + reference) and approves the correct token', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway('123456'));
    $phone = PhoneIdentifier::from('+393331234567');

    $start = $provider->start($phone, Channel::Sms, ctx());
    expect($start->pending())->toBeTrue()
        ->and($start->provider)->toBe('bird')
        ->and($start->reference)->toStartWith('verify-');

    expect($provider->check($phone, '123456', $start->reference, ctx())->approved())->toBeTrue()
        ->and($provider->check($phone, '000000', $start->reference, ctx())->failed())->toBeTrue();
});

it('supports sms by default', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway);

    expect($provider->supports(Channel::Sms))->toBeTrue()
        ->and($provider->supports(Channel::WhatsApp))->toBeFalse()
        ->and($provider->supports(Channel::Voice))->toBeFalse();
});

it('only advertises the configured channels', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway, ['sms', 'voice']);

    expect($provider->supports(Channel::Sms))->toBeTrue()
        ->and($provider->supports(Channel::Voice))->toBeTrue()
        ->and($provider->supports(Channel::WhatsApp))->toBeFalse();
});

it('denies a check with no reference (nothing to verify against)', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway('123456'));

    $result = $provider->check(PhoneIdentifier::from('+393331234567'), '123456', null, ctx());

    expect($result->approved())->toBeFalse()
        ->and($result->reason)->toBe('missing_reference');
});

it('does not approve a token for an unknown verify id', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway('123456'));
    $provider->start(PhoneIdentifier::from('+393331111111'), Channel::Sms, ctx());

    // Correct token, but a verify id that was never issued → must not approve.
    expect($provider->check(PhoneIdentifier::from('+393331111111'), '123456', 'verify-99999999', ctx())->approved())->toBeFalse();
});

it('treats an unexpected start status as a failure (not a bogus pending)', function (): void {
    $gateway = new class implements BirdGateway
    {
        public function startVerification(string $recipient, string $type): array
        {
            return ['id' => 'verify-1', 'status' => 'failed'];
        }

        public function checkVerification(string $id, string $token): string
        {
            return 'failed';
        }

        public function sendMessage(string $recipient, string $body): array
        {
            return ['id' => 'message-1', 'status' => 'sent'];
        }
    };

    $result = (new BirdVerifyProvider($gateway))->start(PhoneIdentifier::from('+393331234567'), Channel::Sms, ctx());

    expect($result->failed())->toBeTrue()
        ->and($result->reason)->toBe('provider_status');
});

it('returns a provider_error (not an exception) when Bird is down', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway('123456', healthy: false));
    $phone = PhoneIdentifier::from('+393331234567');

    expect($provider->start($phone, Channel::Sms, ctx())->reason)->toBe('provider_error')
        ->and($provider->check($phone, '123456', 'verify-00000001', ctx())->reason)->toBe('provider_error');
});

it('sends a plain SMS and reports the message as queued', function (): void {
    $gateway = new FakeBirdGateway;
    $provider = new BirdVerifyProvider($gateway);

    $result = $provider->send(PhoneIdentifier::from('+393331234567'), 'hello', Channel::Sms, ctx());

    expect($result->accepted())->toBeTrue()
        ->and($result->provider)->toBe('bird')
        ->and($result->reference)->toStartWith('message-')
        ->and($gateway->sent)->toHaveCount(1)
        ->and($gateway->sent[0]['body'])->toBe('hello');
});

it('returns a delivery provider_error when sending while Bird is down', function (): void {
    $provider = new BirdVerifyProvider(new FakeBirdGateway('123456', healthy: false));

    $result = $provider->send(PhoneIdentifier::from('+393331234567'), 'hello', Channel::Sms, ctx());

    expect($result->failed())->toBeTrue()
        ->and($result->reason)->toBe('provider_error');
});
