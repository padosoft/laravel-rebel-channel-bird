<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Padosoft\Rebel\Channel\Bird\Gateway\RestBirdGateway;

/**
 * LIVE tests: they hit the real Bird (formerly MessageBird) Verify API and SEND A REAL
 * MESSAGE.
 *
 * They run ONLY when you explicitly opt in with REBEL_BIRD_LIVE=1 AND all credentials
 * (BIRD_ACCESS_KEY, BIRD_ORIGINATOR, BIRD_TEST_PHONE) are present — otherwise they
 * self-skip, so the offline suite and external PRs never trigger a send. In CI, provide
 * the values as secrets and set REBEL_BIRD_LIVE=1.
 */
function liveEnv(string $key): string
{
    $value = getenv($key);

    return is_string($value) ? $value : '';
}

beforeEach(function (): void {
    if (liveEnv('REBEL_BIRD_LIVE') !== '1') {
        test()->markTestSkipped('Live Bird tests are opt-in (set REBEL_BIRD_LIVE=1).');
    }

    foreach (['BIRD_ACCESS_KEY', 'BIRD_ORIGINATOR', 'BIRD_TEST_PHONE'] as $key) {
        if (liveEnv($key) === '') {
            test()->markTestSkipped("Live Bird credentials absent ({$key}).");
        }
    }
});

it('starts a real verification via the Bird Verify API', function (): void {
    $gateway = new RestBirdGateway(
        new HttpFactory,
        liveEnv('BIRD_ACCESS_KEY'),
        liveEnv('BIRD_ORIGINATOR'),
    );

    $result = $gateway->startVerification(liveEnv('BIRD_TEST_PHONE'), 'sms');

    expect($result['id'])->not->toBe('')
        ->and($result['status'])->toBeIn(['sent', 'verified']);
})->group('live');
