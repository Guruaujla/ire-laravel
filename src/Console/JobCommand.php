<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Console;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Jobs;
use Illuminate\Console\Command;

/** Print one job's current state. Read-only. */
final class JobCommand extends Command
{
    protected $signature = 'integration-runtime:job {tenant : Runtime tenant_id} {job : Job ID}';

    protected $description = 'Show an Integration Runtime sync job (read-only)';

    public function handle(Jobs $jobs): int
    {
        $tenant = $this->argument('tenant');
        $id = $this->argument('job');
        if (! is_string($tenant) || ! is_string($id)) {
            return self::INVALID;
        }
        try {
            $job = $jobs->find($tenant, $id);
        } catch (RuntimeProblemException $e) {
            $this->error('HTTP ' . $e->httpStatus . ' ' . $e->problemCode . ($e->requestId !== null ? ' (request ' . $e->requestId . ')' : ''));

            return self::FAILURE;
        }
        $this->line((string) json_encode($job->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
