<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Service provider for the laravel-rebel-channel-bird package (initial skeleton).
 * The full implementation will arrive in its roadmap macro-task.
 */
final class RebelBirdServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-rebel-channel-bird');
    }
}
