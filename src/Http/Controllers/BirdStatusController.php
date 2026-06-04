<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Padosoft\Rebel\Channel\Bird\Http\BirdSignatureValidator;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Contracts\AuditLogger;
use Padosoft\Rebel\Core\Contracts\KeyedHasher;

/**
 * Receives Bird (formerly MessageBird) delivery-status webhooks and records a Rebel
 * audit event so the admin panel's Channel Performance can show real delivered /
 * failed figures and cost per channel.
 *
 * Bird posts server-to-server, so there is no user session to authenticate. Instead
 * we (optionally) verify the `MessageBird-Signature` header. The endpoint is
 * deliberately defensive: a malformed or empty payload is acknowledged with a 204 and
 * records nothing — Bird retries on non-2xx, and we never want a bad payload to 500
 * (which would trigger pointless retries / alerting).
 */
final class BirdStatusController
{
    /** Bird statuses that mean the message reached the handset. */
    private const DELIVERED = ['delivered'];

    /** Bird statuses that mean delivery failed. */
    private const UNDELIVERED = ['delivery_failed', 'failed', 'expired'];

    public function __invoke(
        Request $request,
        Repository $config,
        AuditLogger $audit,
        KeyedHasher $hasher,
    ): Response {
        if ($this->signatureRequired($config) && ! $this->signatureValid($request, $config)) {
            // 403 (not 204): a forged/unsigned callback is a security signal, and we do
            // NOT want Bird to treat it as accepted.
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $status = strtolower($this->stringField($request, 'status'));
        $recipient = $this->recipient($request);

        // Nothing we can attribute → acknowledge and drop (never 500 on junk).
        if ($status === '' || $recipient === '') {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $hash = $hasher->hash($recipient);

        $audit->record(new AuditEvent(
            type: $this->eventType($status),
            identifierHmac: $hash->hash,
            keyVersion: $hash->keyVersion,
            channel: 'sms',
            provider: 'bird',
            metadata: [
                'message_status' => $status,
                'price' => $this->price($request),
                'price_unit' => $this->priceUnit($request),
                'message_sid' => $this->nullableField($request, 'id'),
                'error_code' => $this->errorCode($request),
            ],
        ));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function eventType(string $status): string
    {
        if (in_array($status, self::DELIVERED, true)) {
            return 'channel.verification.delivered';
        }

        if (in_array($status, self::UNDELIVERED, true)) {
            return 'channel.verification.undelivered';
        }

        // sent / buffered / scheduled / accepted / … — an in-flight dispatch.
        return 'channel.verification.dispatched';
    }

    /**
     * Bird identifies the destination as `recipient` (a phone number). Some payload
     * shapes nest it under `recipients`; accept a plain string field first.
     */
    private function recipient(Request $request): string
    {
        return $this->stringField($request, 'recipient');
    }

    /**
     * Bird quotes the price either as a flat numeric `price` or nested under
     * `price.amount`. We store the absolute value so the admin can sum spend directly.
     * Null/empty/non-numeric price stays null.
     */
    private function price(Request $request): ?float
    {
        $raw = $request->input('price');

        if (is_array($raw)) {
            $raw = $raw['amount'] ?? null;
        }

        if (! is_scalar($raw) || ! is_numeric($raw)) {
            return null;
        }

        return abs((float) $raw);
    }

    /** Bird sends the currency either as `currency` or nested under `price.currency`. */
    private function priceUnit(Request $request): ?string
    {
        $unit = $this->nullableField($request, 'currency');
        if ($unit !== null) {
            return $unit;
        }

        $price = $request->input('price');
        if (is_array($price)) {
            $currency = $price['currency'] ?? null;

            return is_string($currency) && $currency !== '' ? $currency : null;
        }

        return null;
    }

    /** Bird sends a numeric `statusErrorCode` on failed deliveries. */
    private function errorCode(Request $request): ?string
    {
        return $this->nullableField($request, 'statusErrorCode')
            ?? $this->nullableField($request, 'error_code');
    }

    private function signatureRequired(Repository $config): bool
    {
        return $config->get('rebel-channel-bird.webhook.validate_signature', true) === true;
    }

    private function signatureValid(Request $request, Repository $config): bool
    {
        $signingKey = $config->get('rebel-channel-bird.webhook.signing_key');

        return (new BirdSignatureValidator(is_string($signingKey) ? $signingKey : ''))->isValid($request);
    }

    private function stringField(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function nullableField(Request $request, string $key): ?string
    {
        $value = $this->stringField($request, $key);

        return $value === '' ? null : $value;
    }
}
