<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/** The job completed. For a write, `externalObjectId` is the QuickBooks ID. */
final class SyncJobCompleted extends SyncJobFinished {}
