<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Events;

/**
 * The customer must reconnect: the provider authorization of the connection
 * stopped working (`reason` invalid_grant or refresh_token_expired). Calls on
 * the connection fail until they do. Show a Reconnect button or send an email
 * whose link starts `IntegrationRuntime::connections()->reconnect($tenantId,
 * $connectionId, $returnUrl, $state)` when the customer clicks it.
 *
 * Fired together with {@see ConnectionStatusChanged} for every
 * `connection.reconnect_required` delivery.
 */
final class ConnectionReconnectRequired extends ConnectionStatusChanged {}
