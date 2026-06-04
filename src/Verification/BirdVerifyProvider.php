<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird\Verification;

use Padosoft\Rebel\Channel\Bird\Contracts\BirdGateway;
use Padosoft\Rebel\Channels\Contracts\MessageDeliveryChannel;
use Padosoft\Rebel\Channels\Contracts\VerificationProvider;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\DeliveryResult;
use Padosoft\Rebel\Channels\Results\VerificationResult;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * Bird (formerly MessageBird) implementation of the Rebel Channels
 * {@see VerificationProvider} and {@see MessageDeliveryChannel}.
 *
 * It maps Rebel channels to Bird Verify message types and never throws out: any
 * transport/API error becomes a generic `provider_error` failure so the router can
 * fall back to another provider. Bird Verify only supports SMS (and flash/tts) — not
 * WhatsApp/voice as a verification channel here — so it advertises `sms` by default.
 */
final class BirdVerifyProvider implements MessageDeliveryChannel, VerificationProvider
{
    /**
     * @param  list<string>  $supported  Rebel channel values this provider may handle
     */
    public function __construct(
        private readonly BirdGateway $gateway,
        private readonly array $supported = ['sms'],
    ) {}

    public function key(): string
    {
        return 'bird';
    }

    public function supports(Channel $channel): bool
    {
        return in_array($channel->value, $this->supported, true);
    }

    public function start(PhoneIdentifier $phone, Channel $channel, SecurityContext $context): VerificationResult
    {
        try {
            $result = $this->gateway->startVerification($phone->normalized(), $this->birdType($channel));
        } catch (\Throwable) {
            return VerificationResult::fail('provider_error', 'bird');
        }

        // Map Bird Verify statuses explicitly: 'sent' is a live challenge; 'verified'
        // (auto-verified) is approved; anything else (failed, expired, …) is a failure,
        // not a bogus "started" carrying a stale reference.
        return match ($result['status']) {
            'sent' => VerificationResult::started('bird', $result['id']),
            'verified' => VerificationResult::approve('bird'),
            default => VerificationResult::fail('provider_status', 'bird'),
        };
    }

    public function check(PhoneIdentifier $phone, string $code, ?string $reference, SecurityContext $context): VerificationResult
    {
        // Bird checks a token against the verify id returned by start(); with no
        // reference there is nothing to check against → fail closed.
        if ($reference === null || $reference === '') {
            return VerificationResult::deny('bird', 'missing_reference');
        }

        try {
            $status = $this->gateway->checkVerification($reference, $code);
        } catch (\Throwable) {
            return VerificationResult::fail('provider_error', 'bird');
        }

        return $status === 'verified'
            ? VerificationResult::approve('bird')
            : VerificationResult::deny('bird', 'not_approved');
    }

    public function send(PhoneIdentifier $phone, string $message, Channel $channel, SecurityContext $context): DeliveryResult
    {
        try {
            $result = $this->gateway->sendMessage($phone->normalized(), $message);
        } catch (\Throwable) {
            return DeliveryResult::fail($channel, 'provider_error', 'bird');
        }

        // Map Bird message statuses: an accepted/in-flight dispatch is 'sent'/'buffered'/
        // 'scheduled'; a 'delivered' is already terminal-good; anything else fails.
        return match ($result['status']) {
            'delivered' => DeliveryResult::sent($channel, 'bird', $result['id']),
            'sent', 'buffered', 'scheduled' => DeliveryResult::queued($channel, 'bird', $result['id']),
            default => DeliveryResult::fail($channel, 'provider_status', 'bird'),
        };
    }

    private function birdType(Channel $channel): string
    {
        // Bird Verify message type. SMS is the only verification channel we advertise;
        // map defensively so an unexpected channel still produces a sane request.
        return match ($channel) {
            Channel::Voice => 'tts',
            default => 'sms',
        };
    }
}
