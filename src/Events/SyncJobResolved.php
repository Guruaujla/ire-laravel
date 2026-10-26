<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/** An operator closed the job manually. It is not proof that QuickBooks has the document. */
final class SyncJobResolved extends SyncJobFinished {}
