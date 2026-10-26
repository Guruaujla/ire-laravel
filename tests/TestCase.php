<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Tests;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Laravel\IntegrationRuntimeServiceProvider;
use Ezee\IntegrationRuntime\RetryPolicy;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    public const WEBHOOK_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    protected function getPackageProviders($app): array
    {
        return [IntegrationRuntimeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('integration-runtime.base_url', 'https://runtime.example.invalid');
        $app['config']->set('integration-runtime.app_id', 'app_erp');
        $app['config']->set('integration-runtime.key_id', 'key_test');
        $app['config']->set('integration-runtime.secret', self::SECRET);
        $app['config']->set('integration-runtime.webhooks.enabled', true);
        $app['config']->set('integration-runtime.webhooks.secrets', ['whk_test' => self::WEBHOOK_SECRET]);
        $app['config']->set('cache.default', 'array');
    }

    /** Swap the runtime client for one answering from a script. */
    protected function fakeRuntime(Response ...$responses): FakeTransport
    {
        $transport = new FakeTransport(array_values($responses));
        $factory = new HttpFactory();
        $this->app->instance(Client::class, new Client($transport, $factory, $factory, 'https://runtime.example.invalid', 'app_erp', 'key_test', self::SECRET, RetryPolicy::disabled()));

        return $transport;
    }

    /** @param array<mixed> $body */
    protected static function runtimeJson(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['content-type' => 'application/json', ...$headers], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
