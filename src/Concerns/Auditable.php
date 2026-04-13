<?php

namespace Ahmed3bead\TenantAudit\Concerns;

use Ahmed3bead\TenantAudit\Observers\AuditObserver;
use Ahmed3bead\TenantAudit\TenantAuditManager;
use Closure;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add audit logging to any Eloquent model.
 *
 * Usage:
 *
 *   use Ahmed3bead\TenantAudit\Concerns\Auditable;
 *   use Ahmed3bead\TenantAudit\Contracts\AuditableContract;
 *
 *   class Order extends Model implements AuditableContract
 *   {
 *       use Auditable;
 *
 *       // Optional: only audit these attributes (allowlist)
 *       protected array $auditable = ['status', 'total'];
 *
 *       // Optional: never audit these attributes on this model
 *       protected array $auditExclude = ['internal_notes'];
 *
 *       // Optional: only fire on these events (overrides global config)
 *       protected array $auditEvents = ['created', 'deleted'];
 *   }
 */
trait Auditable
{
    /**
     * Per-instance flag. When false the observer skips this model.
     * Not static — only affects the current instance, not all instances.
     */
    private bool $auditingEnabled = true;

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function bootAuditable(): void
    {
        static::observe(AuditObserver::class);
    }

    // -------------------------------------------------------------------------
    // AuditableContract implementation
    // -------------------------------------------------------------------------

    public function getAuditableAttributes(): array
    {
        return $this->auditable ?? [];
    }

    public function getAuditExcludedAttributes(): array
    {
        $modelExclusions  = $this->auditExclude ?? [];
        $globalExclusions = config('tenant-audit.excluded_attributes', []);

        return array_values(array_unique(array_merge($globalExclusions, $modelExclusions)));
    }

    public function getAuditableEvents(): array
    {
        return $this->auditEvents ?? [];
    }

    public function isAuditingEnabled(): bool
    {
        return $this->auditingEnabled;
    }

    public function disableAudit(): static
    {
        $this->auditingEnabled = false;

        return $this;
    }

    public function enableAudit(): static
    {
        $this->auditingEnabled = true;

        return $this;
    }

    /**
     * Log a custom business event against this model instance.
     *
     * Examples:
     *   $order->auditEvent('approved', metadata: ['note' => 'passed review']);
     *   $order->auditEvent('status_changed',
     *       oldValues: ['status' => 'pending'],
     *       newValues: ['status' => 'approved'],
     *   );
     */
    public function auditEvent(
        string $event,
        array $metadata = [],
        array $oldValues = [],
        array $newValues = [],
    ): void {
        app(TenantAuditManager::class)->log(
            $event,
            $this,
            $oldValues,
            $newValues,
            metadata: $metadata,
        );
    }

    // -------------------------------------------------------------------------
    // Relation
    // -------------------------------------------------------------------------

    /**
     * All audit log entries for this model.
     * Uses the configurable model class so users can swap it.
     */
    public function auditLogs(): MorphMany
    {
        $modelClass = config('tenant-audit.model');

        return $this->morphMany($modelClass, 'auditable');
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Run a closure without triggering any audit logs.
     *
     * This disables auditing globally for the duration of the closure by
     * temporarily pausing the manager, then restores the previous state.
     * Useful for seeding, migrations, or bulk operations.
     *
     * Usage:
     *   Order::withoutAudit(fn () => Order::query()->update(['status' => 'archived']));
     */
    public static function withoutAudit(Closure $callback): mixed
    {
        $manager = app(TenantAuditManager::class);

        $manager->pause();

        try {
            return $callback();
        } finally {
            $manager->resume();
        }
    }
}
