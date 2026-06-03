<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird\Contracts;

/**
 * Thin seam over Bird's (formerly MessageBird) Verify and Messaging REST APIs so the
 * verification provider stays fully unit-testable offline. The real implementation
 * talks HTTP through Laravel's HTTP client; a fake ships for tests, and the opt-in
 * live test-suite uses the real one against the actual API.
 */
interface BirdGateway
{
    /**
     * Start a verification (Bird Verify: POST /verify). Returns the verify id and its
     * status (e.g. 'sent', 'verified', 'failed', 'expired').
     *
     * @param  string  $type  Bird Verify message type: 'sms' | 'flash' | 'tts'
     * @return array{id: string, status: string}
     */
    public function startVerification(string $recipient, string $type): array;

    /**
     * Check a token (Bird Verify: GET /verify/{id}?token=...). Returns the resulting
     * Bird verify status (e.g. 'verified', 'failed', 'expired').
     */
    public function checkVerification(string $id, string $token): string;

    /**
     * Send a plain SMS (Bird Messaging: POST /messages). Returns the message id and the
     * first recipient's status (e.g. 'sent', 'buffered', 'delivered', 'delivery_failed').
     *
     * @return array{id: string, status: string}
     */
    public function sendMessage(string $recipient, string $body): array;
}
