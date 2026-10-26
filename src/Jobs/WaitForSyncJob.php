<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Jobs;

use Ezee\IntegrationRuntime\Exception\RuntimeProblemException;
use Ezee\IntegrationRuntime\Jobs;
use Ezee\IntegrationRuntime\Laravel\Events\Factory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-reads one runtime job until it is terminal, then fires the matching
 * SyncJob* event. It never resubmits the operation.
 */
final class WaitForSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Releases are bounded by `polling.max_attempts`; exceptions are not retried blindly. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $pollingUrl,
        public readonly string $tenantId,
        public readonly string $jobId,
    ) {
        $queue = config('integration-runtime.polling.queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(Jobs $jobs, Dispatcher $events, Config $config): void
    {
        $maxAttempts = (int) $config->get('integration-runtime.polling.max_attempts', 60);
        $initial = (int) $config->get('integration-runtime.polling.initial_delay_seconds', 5);
        $cap = (int) $config->get('integration-runtime.polling.max_delay_seconds', 300);
        $attempt = $this->attempts();

        try {
            $job = $jobs->get($this->pollingUrl);
        } catch (RuntimeProblemException $problem) {
            if ($problem->httpStatus !== 429 && $problem->httpStatus < 500) {
                throw $problem;
            }
            $this->releaseOrGiveUp($attempt, $maxAttempts, max($problem->retryAfterSeconds ?? 0, $initial));

            return;
        }

        if ($job->isTerminal()) {
            $finished = Factory::forStatus($this->tenantId, $job->id, $job->status, 'poll', $job->raw);
            if ($finished !== null) {
                $events->dispatch($finished);
            }

            return;
        }

        $this->releaseOrGiveUp($attempt, $maxAttempts, min($cap, (int) ($initial * 1.5 ** max(0, $attempt - 1))));
    }

    private function releaseOrGiveUp(int $attempt, int $maxAttempts, int $delay): void
    {
        if ($attempt >= $maxAttempts) {
            // Still running is not failure. Keep the polling URL and check later.
            $this->fail(new \RuntimeException('Integration Runtime job ' . $this->jobId . ' not terminal after ' . $attempt . ' polls; keep its polling_url and check again later.'));

            return;
        }
        $this->release($delay);
    }
}
