<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird\Testing;

use Padosoft\Rebel\Channel\Bird\Contracts\BirdGateway;
use RuntimeException;

/**
 * Deterministic {@see BirdGateway} for tests: records started verifications and sent
 * messages, "verifies" a fixed expected token, and can simulate an API outage.
 */
final class FakeBirdGateway implements BirdGateway
{
    /** @var list<array{recipient: string, type: string, id: string}> */
    public array $started = [];

    /** @var list<array{recipient: string, body: string}> */
    public array $sent = [];

    public function __construct(
        private readonly string $expectedToken = '123456',
        private readonly bool $healthy = true,
    ) {}

    public function startVerification(string $recipient, string $type): array
    {
        if (! $this->healthy) {
            throw new RuntimeException('bird unavailable');
        }

        $id = 'verify-'.str_pad((string) (count($this->started) + 1), 8, '0', STR_PAD_LEFT);
        $this->started[] = ['recipient' => $recipient, 'type' => $type, 'id' => $id];

        return ['id' => $id, 'status' => 'sent'];
    }

    public function checkVerification(string $id, string $token): string
    {
        if (! $this->healthy) {
            throw new RuntimeException('bird unavailable');
        }

        // Scope the check to a verify that was actually started, mirroring Bird: a check
        // for an unknown id has nothing pending and cannot succeed.
        $known = array_filter($this->started, fn (array $v): bool => $v['id'] === $id);
        if ($known === []) {
            return 'expired';
        }

        return hash_equals($this->expectedToken, $token) ? 'verified' : 'failed';
    }

    public function sendMessage(string $recipient, string $body): array
    {
        if (! $this->healthy) {
            throw new RuntimeException('bird unavailable');
        }

        $this->sent[] = ['recipient' => $recipient, 'body' => $body];

        return ['id' => 'message-'.str_pad((string) count($this->sent), 8, '0', STR_PAD_LEFT), 'status' => 'sent'];
    }
}
