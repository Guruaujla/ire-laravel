<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Console;

use Ezee\IntegrationRuntime\Client;
use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Jobs;
use Illuminate\Console\Command;

/** Proves configuration and signing with read-only calls. Never writes. */
final class CheckCommand extends Command
{
    protected $signature = 'integration-runtime:check {--tenant= : Runtime tenant_id to prove tenant scope with a signed job-list read}';

    protected $description = 'Check Integration Runtime connectivity, credentials and signing (read-only)';

    public function handle(Client $client, Jobs $jobs): int
    {
        $health = $client->send('GET', '/v1/health');
        if ($health->getStatusCode() !== 200) {
            $this->error('Runtime unreachable: GET /v1/health returned HTTP ' . $health->getStatusCode());

            return self::FAILURE;
        }
        $this->info('Runtime reachable.');

        // A signed lookup of a tenant that does not exist: 404 tenant.not_found
        // proves app, key, secret and clock; 401 means signing or credentials.
        $probe = $client->send('GET', '/v1/tenants/by-external-id/integration-runtime-check');
        $status = $probe->getStatusCode();
        if ($status === 401) {
            $this->error('Signature rejected (HTTP 401). Check INTEGRATION_RUNTIME_APP_ID, _KEY_ID, _SECRET and the server clock.');

            return self::FAILURE;
        }
        if ($status === 403) {
            $this->error('Signed request refused (HTTP 403). Ask the operator to provision this app and key.');

            return self::FAILURE;
        }
        $this->info('Signed request accepted (HTTP ' . $status . ').');

        $tenant = $this->option('tenant');
        if (is_string($tenant) && $tenant !== '') {
            try {
                $jobs->list($tenant, ['limit' => 1]);
                $this->info('Tenant scope OK for ' . $tenant . '.');
            } catch (RuntimeProblemException $e) {
                $this->error('Tenant read failed: HTTP ' . $e->httpStatus . ' ' . $e->problemCode);

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
