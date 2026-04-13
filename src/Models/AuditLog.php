<?php

namespace Ahmed3bead\TenantAudit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_type',
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata'   => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return config('tenant-audit.connection') ?? parent::getConnectionName();
    }

    public function getTable(): string
    {
        return config('tenant-audit.table', 'tenant_audit_logs');
    }

    public function prunable(): Builder
    {
        $days = config('tenant-audit.prune_after_days');

        return static::where('created_at', '<', now()->subDays($days));
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Polymorphic relation to the actor (Admin, Vendor, Customer, etc.).
     * Reads column names from config so they stay consistent with the migration.
     */
    public function user(): MorphTo
    {
        return $this->morphTo(
            'user',
            config('tenant-audit.user_type_column', 'user_type'),
            config('tenant-audit.user_id_column', 'user_id'),
        );
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where(config('tenant-audit.tenant_id_column', 'tenant_id'), $tenantId);
    }

    /**
     * Filter by a specific actor. Pass the model instance for the most
     * accurate match (both type + id), or pass type + id explicitly.
     *
     * Usage:
     *   AuditLog::forUser($admin)
     *   AuditLog::forUser(Admin::class, 42)
     */
    public function scopeForUser(Builder $query, object|string $userOrType, int|string|null $id = null): Builder
    {
        if (is_object($userOrType)) {
            $type = get_class($userOrType);
            $id   = $userOrType->getKey();
        } else {
            $type = $userOrType;
        }

        return $query
            ->where(config('tenant-audit.user_type_column', 'user_type'), $type)
            ->where(config('tenant-audit.user_id_column', 'user_id'), $id);
    }

    public function scopeForModel(Builder $query, string $type, int|string $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    public function scopeByEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeOldestFirst(Builder $query): Builder
    {
        return $query->orderBy('created_at');
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
