<?php

namespace Ahmed3bead\TenantAudit\Observers;

use Ahmed3bead\TenantAudit\Contracts\AuditableContract;
use Ahmed3bead\TenantAudit\TenantAuditManager;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    public function __construct(protected TenantAuditManager $manager) {}

    public function created(Model $model): void
    {
        if (! $this->shouldAudit($model, 'created')) {
            return;
        }

        $this->manager->log('created', $model, [], $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        if (! $this->shouldAudit($model, 'updated')) {
            return;
        }

        // Only the attributes that actually changed — manager will filter exclusions
        $dirty = $model->getDirty();

        if (empty($dirty)) {
            return;
        }

        $old = array_intersect_key($model->getOriginal(), $dirty);

        $this->manager->log('updated', $model, $old, $dirty);
    }

    public function deleted(Model $model): void
    {
        if (! $this->shouldAudit($model, 'deleted')) {
            return;
        }

        $this->manager->log('deleted', $model, $model->getOriginal(), []);
    }

    public function restored(Model $model): void
    {
        if (! $this->shouldAudit($model, 'restored')) {
            return;
        }

        $this->manager->log('restored', $model, [], $model->getAttributes());
    }

    public function forceDeleted(Model $model): void
    {
        if (! $this->shouldAudit($model, 'forceDeleted')) {
            return;
        }

        $this->manager->log('forceDeleted', $model, $model->getOriginal(), []);
    }

    // -------------------------------------------------------------------------

    /**
     * Determine whether this event on this model should be audited.
     *
     * Checks (in order):
     *  1. Model implements AuditableContract
     *  2. Auditing is not disabled on the instance
     *  3. The event is enabled — first checks per-model override, then global config
     */
    protected function shouldAudit(Model $model, string $event): bool
    {
        if (! $model instanceof AuditableContract) {
            return false;
        }

        if (! $model->isAuditingEnabled()) {
            return false;
        }

        return $this->isEventEnabled($model, $event);
    }

    /**
     * Check whether an event is enabled for a given model.
     *
     * Per-model $auditEvents takes precedence over the global config events list.
     * An empty per-model list means "use the global config".
     */
    protected function isEventEnabled(AuditableContract $model, string $event): bool
    {
        $modelEvents = $model->getAuditableEvents();

        if (! empty($modelEvents)) {
            return in_array($event, $modelEvents, strict: true);
        }

        // Fall back to global config
        return (bool) config("tenant-audit.events.{$event}", false);
    }
}
