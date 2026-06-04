<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird\Http;

use Illuminate\Http\Request;

/**
 * Validates the signature Bird (formerly MessageBird) attaches to every webhook
 * request, so a forged delivery-status callback cannot inject fake audit data.
 *
 * Bird's legacy signing scheme sends two headers:
 *   - `MessageBird-Request-Timestamp` — the UNIX timestamp of the request;
 *   - `MessageBird-Signature` — base64( HMAC-SHA256( signedPayload, signingKey ) ).
 *
 * The signed payload is three parts joined by a newline:
 *     timestamp "\n" requestUrlWithSortedQuery "\n" sha256(rawBody, rawOutput=true)
 *
 * Without the signing key the signature cannot be forged, so a matching signature
 * proves the callback really came from Bird. We compare in constant time.
 */
final class BirdSignatureValidator
{
    public function __construct(private readonly string $signingKey) {}

    public function isValid(Request $request): bool
    {
        if ($this->signingKey === '') {
            return false;
        }

        $signature = $this->header($request, 'MessageBird-Signature');
        $timestamp = $this->header($request, 'MessageBird-Request-Timestamp');

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        $expected = base64_decode($signature, true);
        if ($expected === false) {
            return false;
        }

        $bodyHash = hash('sha256', $request->getContent(), true);
        $signedPayload = $timestamp."\n".$request->fullUrl()."\n".$bodyHash;
        $computed = hash_hmac('sha256', $signedPayload, $this->signingKey, true);

        return hash_equals($computed, $expected);
    }

    private function header(Request $request, string $name): string
    {
        $value = $request->header($name);

        return is_string($value) ? $value : '';
    }
}
