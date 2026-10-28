<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ezee\IntegrationRuntime\Client client()
 * @method static \Ezee\IntegrationRuntime\Accounting accounting()
 * @method static \Ezee\IntegrationRuntime\QuickBooks quickbooks() Deprecated since 1.3.0; use accounting().
 * @method static \Ezee\IntegrationRuntime\Jobs jobs()
 * @method static \Ezee\IntegrationRuntime\Connections connections()
 * @method static \Ezee\IntegrationRuntime\TenantClient tenants()
 * @method static void track(\Ezee\IntegrationRuntime\OperationReceipt $receipt, string $tenantId)
 *
 * @see \Ezee\IntegrationRuntime\Laravel\IntegrationRuntime
 */
final class IntegrationRuntime extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ezee\IntegrationRuntime\Laravel\IntegrationRuntime::class;
    }
}
