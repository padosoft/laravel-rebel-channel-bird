<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird\Gateway;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Padosoft\Rebel\Channel\Bird\Contracts\BirdGateway;
use RuntimeException;

/**
 * Real {@see BirdGateway} backed by Bird's (formerly MessageBird) REST API, called
 * through Laravel's HTTP client so it is faked in tests via `Http::fake()`.
 *
 * Authentication is the legacy MessageBird scheme: an `Authorization: AccessKey <key>`
 * header on `https://rest.messagebird.com`. The originator is the sender id/number
 * shown to the recipient.
 */
final class RestBirdGateway implements BirdGateway
{
    private const BASE_URL = 'https://rest.messagebird.com';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $accessKey,
        private readonly string $originator,
    ) {}

    public function startVerification(string $recipient, string $type): array
    {
        $payload = ['recipient' => $recipient, 'type' => $type];
        if ($this->originator !== '') {
            $payload['originator'] = $this->originator;
        }

        $response = $this->client()->post(self::BASE_URL.'/verify', $payload);

        /** @var array<array-key, mixed> $data */
        $data = $response->json();

        return [
            'id' => $this->stringField($data, 'id'),
            'status' => $this->stringField($data, 'status'),
        ];
    }

    public function checkVerification(string $id, string $token): string
    {
        $response = $this->client()->get(self::BASE_URL.'/verify/'.rawurlencode($id), ['token' => $token]);

        /** @var array<array-key, mixed> $data */
        $data = $response->json();

        return $this->stringField($data, 'status');
    }

    public function sendMessage(string $recipient, string $body): array
    {
        $response = $this->client()->post(self::BASE_URL.'/messages', [
            'originator' => $this->originator,
            'recipients' => [$recipient],
            'body' => $body,
        ]);

        /** @var array<array-key, mixed> $data */
        $data = $response->json();

        return [
            'id' => $this->stringField($data, 'id'),
            'status' => $this->firstRecipientStatus($data),
        ];
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->withHeaders([
                'Authorization' => 'AccessKey '.$this->accessKey,
                'Accept' => 'application/json',
            ])
            ->acceptJson()
            ->asJson();
    }

    /**
     * Bird's send response nests per-recipient delivery status under
     * `recipients.items[0].status`. Extract the first recipient's status.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function firstRecipientStatus(array $data): string
    {
        $recipients = $data['recipients'] ?? null;
        if (! is_array($recipients)) {
            return '';
        }

        $items = $recipients['items'] ?? null;
        if (! is_array($items)) {
            return '';
        }

        $first = $items[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        return $this->stringField($first, 'status');
    }

    /**
     * Narrow a decoded JSON field to a string. Fail loudly on an unexpected (non-scalar)
     * shape rather than silently returning '' for a present-but-malformed field — the
     * provider wraps this in a try/catch and turns it into a clean 'provider_error'.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return '';
        }

        if (! is_scalar($value)) {
            throw new RuntimeException('Unexpected non-scalar value returned by the Bird API.');
        }

        return (string) $value;
    }
}
