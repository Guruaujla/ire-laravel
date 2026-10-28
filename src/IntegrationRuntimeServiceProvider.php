<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel;

use Ezee\IntegrationRuntime\Accounting;
use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Connections;
use Ezee\IntegrationRuntime\Jobs;
use Ezee\IntegrationRuntime\Laravel\Console\CheckCommand;
use Ezee\IntegrationRuntime\Laravel\Console\InstallAgentRulesCommand;
use Ezee\IntegrationRuntime\Laravel\Console\JobCommand;
use Ezee\IntegrationRuntime\Laravel\Http\Controllers\WebhookController;
use Ezee\IntegrationRuntime\QuickBooks;
use Ezee\IntegrationRuntime\TenantClient;
use Ezee\IntegrationRuntime\WebhookVerifier;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class IntegrationRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/integration-runtime.php', 'integration-runtime');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            $config = $app->make(Config::class);
            $setting = static function (string $key) use ($config): string {
                $value = $config->get('integration-runtime.' . $key);
                if (! is_string($value) || $value === '') {
                    $env = ['base_url' => 'INTEGRATION_RUNTIME_URL', 'app_id' => 'INTEGRATION_RUNTIME_APP_ID', 'key_id' => 'INTEGRATION_RUNTIME_KEY_ID', 'secret' => 'INTEGRATION_RUNTIME_SECRET'][$key] ?? 'INTEGRATION_RUNTIME_' . strtoupper($key);
                    throw new \RuntimeException('Set integration-runtime.' . $key . ' (' . $env . ') before calling the runtime.');
                }

                return $value;
            };
            $factory = new HttpFactory();
            $timeout = $config->get('integration-runtime.timeout', 15);

            return new Client(
                new Guzzle(['timeout' => is_numeric($timeout) ? (float) $timeout : 15.0, 'http_errors' => false, 'allow_redirects' => false]),
                $factory,
                $factory,
                $setting('base_url'),
                $setting('app_id'),
                $setting('key_id'),
                $setting('secret'),
            );
        });
        $this->app->singleton(QuickBooks::class, static fn(Application $app) => new QuickBooks($app->make(Client::class)));
        $this->app->singleton(Accounting::class, static fn(Application $app) => new Accounting($app->make(Client::class)));
        $this->app->singleton(Jobs::class, static fn(Application $app) => new Jobs($app->make(Client::class)));
        $this->app->singleton(Connections::class, static fn(Application $app) => new Connections($app->make(Client::class)));
        $this->app->singleton(TenantClient::class, static fn(Application $app) => new TenantClient($app->make(Client::class)));
        $this->app->singleton(IntegrationRuntime::class);
        $this->app->singleton(WebhookVerifier::class, static function (Application $app): WebhookVerifier {
            $config = $app->make(Config::class);
            $secrets = $config->get('integration-runtime.webhooks.secrets', []);
            $valid = [];
            foreach (is_array($secrets) ? $secrets : [] as $keyId => $secret) {
                if (is_string($secret) && $secret !== '') {
                    $valid[(string) $keyId] = $secret;
                }
            }
            if ($valid === []) {
                throw new \RuntimeException('Set INTEGRATION_RUNTIME_WEBHOOK_SECRET (and _KEY_ID) to receive webhooks.');
            }
            $skew = $config->get('integration-runtime.webhooks.max_skew_seconds', 300);

            return new WebhookVerifier($valid, is_numeric($skew) ? (int) $skew : 300);
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/integration-runtime.php' => $this->app->configPath('integration-runtime.php')], 'integration-runtime-config');

        $config = $this->app->make(Config::class);
        if ($config->get('integration-runtime.webhooks.enabled') === true) {
            $path = $config->get('integration-runtime.webhooks.path', 'integration-runtime/webhooks');
            $middleware = $config->get('integration-runtime.webhooks.middleware', []);
            Route::post(is_string($path) ? $path : 'integration-runtime/webhooks', WebhookController::class)
                ->middleware(is_array($middleware) ? $middleware : [])
                ->name('integration-runtime.webhooks');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, JobCommand::class, InstallAgentRulesCommand::class]);
        }
    }
}
