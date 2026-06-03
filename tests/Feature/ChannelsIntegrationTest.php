<?php

declare(strict_types=1);

use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Routing\VerificationRouter;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

it('auto-registers the Bird provider into the Channels router', function (): void {
    $router = app(VerificationRouter::class);
    $phone = PhoneIdentifier::from('+393331234567');

    $start = $router->start($phone, Channel::Sms, new SecurityContext('r'));

    expect($start->pending())->toBeTrue()
        ->and($start->provider)->toBe('bird');

    expect($router->check($phone, '123456', (string) $start->reference, new SecurityContext('r'))->approved())->toBeTrue();
});
