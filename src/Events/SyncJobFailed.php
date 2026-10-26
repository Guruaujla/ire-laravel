<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/** Terminal `failed` or `cancelled` seen by polling. Reconcile before any new submission. */
final class SyncJobFailed extends SyncJobFinished {}
