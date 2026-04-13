<?php

namespace Ahmed3bead\TenantAudit\Contracts;

interface AuditableContract
{
    /**
     * Return the attribute allowlist for auditing.
     * When non-empty, only these attributes are logged.
     * When empty, all attributes are logged (minus exclusions).
     *
     * Maps to $auditable on the model.
     */
    public function getAuditableAttributes(): array;

    /**
     * Return attributes that must never appear in the audit log for this model.
     * Merged with the global excluded_attributes from config.
     *
     * Maps to $auditExclude on the model.
     */
    public function getAuditExcludedAttributes(): array;

    /**
     * Return the Eloquent events this model should be audited on.
     * Return an empty array to inherit the global config events list.
     * Return a subset to restrict auditing on this model specifically.
     *
     * Example: ['created', 'deleted']  — skip 'updated' and 'restored'
     *
     * Maps to $auditEvents on the model.
     */
    public function getAuditableEvents(): array;

    /**
     * Whether auditing is currently enabled on this model instance.
     * Allows runtime toggling: $model->disableAudit() before a bulk update.
     */
    public function isAuditingEnabled(): bool;

    /**
     * Disable auditing for the remainder of this model instance's lifetime.
     * Returns $this for fluent chaining.
     */
    public function disableAudit(): static;

    /**
     * Re-enable auditing after a disableAudit() call.
     * Returns $this for fluent chaining.
     */
    public function enableAudit(): static;

    /**
     * Log a custom event against this model instance.
     *
     * Use this for business actions that are not Eloquent lifecycle events:
     * approved, rejected, exported, impersonated, checked-out, etc.
     *
     * @param  string  $event     Custom event name e.g. 'approved'
     * @param  array   $metadata  Free-form extra context stored in the metadata column
     * @param  array   $oldValues Optional snapshot of state before the action
     * @param  array   $newValues Optional snapshot of state after the action
     */
    public function auditEvent(
        string $event,
        array $metadata = [],
        array $oldValues = [],
        array $newValues = [],
    ): void;
}
