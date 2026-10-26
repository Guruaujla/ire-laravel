<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/** Terminal failure. Do not resubmit with a new key; read `lastErrorCode` and reconcile. */
final class SyncJobDeadLettered extends SyncJobFinished {}
