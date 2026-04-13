<?php

namespace Ahmed3bead\TenantAudit\Facades;

use Ahmed3bead\TenantAudit\TenantAuditManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static object|null log(string $event, Model $model, array $oldValues = [], array $newValues = [], int|string|null $tenantId = null, array $metadata = [])
 * @method static void        pause()
 * @method static void        resume()
 * @method static bool        isPaused()
 *
 * @see TenantAuditManager
 */
class TenantAudit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantAuditManager::class;
    }
}
