<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channel\Bird;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Padosoft\Rebel\Channel\Bird\Contracts\BirdGateway;
use Padosoft\Rebel\Channel\Bird\Gateway\RestBirdGateway;
use Padosoft\Rebel\Channel\Bird\Http\Controllers\BirdStatusController;
use Padosoft\Rebel\Channel\Bird\Verification\BirdVerifyProvider;
use Padosoft\Rebel\Channels\Routing\ProviderRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the Bird (formerly MessageBird) provider into the Rebel Channels registry
 * (when credentials are configured) and binds the Bird gateway.
 *
 * Credentials are read lazily: the package installs cleanly with no Bird config, and
 * the provider simply does not register until you set the BIRD_* values.
 */
final class RebelBirdServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rebel-channel-bird')
            ->hasConfigFile('rebel-channel-bird');
    }

    public function packageBooted(): void
    {
        $config = $this->app->make(Repository::class);

        // The delivery-status webhook is independent of the Verify provider: it only needs
        // the signing key (to validate signatures), not configured credentials, so we
        // register the route before the credentials gate below.
        $this->registerWebhookRoute($config);

        // No credentials → nothing Bird-backed is wired (no authenticated gateway is
        // ever constructed in the container).
        if (! $this->hasCredentials($config)) {
            return;
        }

        // Bind the real gateway only when not already bound (so a test can bind a fake first).
        if (! $this->app->bound(BirdGateway::class)) {
            $this->app->singleton(BirdGateway::class, function () use ($config): RestBirdGateway {
                return new RestBirdGateway(
                    $this->app->make(HttpFactory::class),
                    $this->stringConfig($config, 'access_key'),
                    $this->stringConfig($config, 'originator'),
                );
            });
        }

        if ($config->get('rebel-channel-bird.register_provider', true) === true && class_exists(ProviderRegistry::class)) {
            $this->app->make(ProviderRegistry::class)->register(
                new BirdVerifyProvider($this->app->make(BirdGateway::class), $this->channels($config)),
            );
        }
    }

    /**
     * Register the POST status-webhook route when enabled. No auth middleware: Bird posts
     * server-to-server and the controller verifies the MessageBird-Signature itself.
     */
    private function registerWebhookRoute(Repository $config): void
    {
        if ($config->get('rebel-channel-bird.webhook.enabled', true) !== true) {
            return;
        }

        Route::post(
            $this->stringConfig($config, 'webhook.path') !== ''
                ? $this->stringConfig($config, 'webhook.path')
                : 'rebel/bird/status',
            BirdStatusController::class,
        )->name('rebel-bird.status');
    }

    private function hasCredentials(Repository $config): bool
    {
        return $this->stringConfig($config, 'access_key') !== '';
    }

    /**
     * @return list<string>
     */
    private function channels(Repository $config): array
    {
        $default = ['sms'];
        $value = $config->get('rebel-channel-bird.channels');

        if (! is_array($value)) {
            return $default;
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        // A misconfigured (all-empty) list would register a provider that supports nothing;
        // fall back to the defaults so the provider stays useful.
        return $out === [] ? $default : $out;
    }

    private function stringConfig(Repository $config, string $key): string
    {
        $value = $config->get("rebel-channel-bird.{$key}");

        return is_string($value) ? $value : '';
    }
}
