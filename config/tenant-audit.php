<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenant Resolver
    |--------------------------------------------------------------------------
    | A class that implements TenantResolverContract, bound in the container.
    | Set to null to skip automatic tenant resolution (resolve manually or
    | pass tenantId explicitly to TenantAudit::log()).
    |
    | Example: \App\Services\TenantResolver::class
    */
    'tenant_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | User Resolver
    |--------------------------------------------------------------------------
    | A callable that returns the currently authenticated actor.
    | Must return an array with 'type' and 'id' keys, or null when there is
    | no authenticated user. This supports multiple user model types (admin,
    | vendor, customer, etc.) via a polymorphic relationship.
    |
    | Return shape: ['type' => 'App\Models\Admin', 'id' => 42]
    |
    | Examples:
    |   // Single user model (default behaviour):
    |   fn () => ($u = auth()->user()) ? ['type' => get_class($u), 'id' => $u->getKey()] : null
    |
    |   // Multi-guard setup:
    |   fn () => ($u = auth()->guard('admin')->user() ?? auth()->guard('vendor')->user())
    |            ? ['type' => get_class($u), 'id' => $u->getKey()] : null
    */
    'user_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Audit Log Model
    |--------------------------------------------------------------------------
    | Swap with your own Eloquent model to extend or customise the log entry.
    | Your model must extend Ahmed3bead\TenantAudit\Models\AuditLog.
    */
    'model' => \Ahmed3bead\TenantAudit\Models\AuditLog::class,

    /*
    |--------------------------------------------------------------------------
    | Database Connection & Table
    |--------------------------------------------------------------------------
    | The connection used for the audit_logs table. Null falls back to the
    | default database connection. Override when audit logs live on a
    | separate DB or schema.
    */
    'connection' => null,

    'table' => 'tenant_audit_logs',

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    | Rename the tenant and user columns to match your schema if needed.
    | These names must match the columns in your migration.
    */
    'tenant_id_column' => 'tenant_id',

    'user_type_column' => 'user_type',

    'user_id_column' => 'user_id',

    /*
    |--------------------------------------------------------------------------
    | Audited Events
    |--------------------------------------------------------------------------
    | Control which Eloquent model ev`ents are audited. Disable any event
    | by removing it or setting its value to false.
    */
    'events' => [
        'created'      => true,
        'updated'      => true,
        'deleted'      => true,
        'restored'     => true,
        'forceDeleted' => false,  // opt-in — permanently destroyed records
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Events
    |--------------------------------------------------------------------------
    | An optional allowlist of custom event names your application may log.
    | When this array is non-empty, TenantAuditManager::log() will reject any
    | event name that is not in this list OR in the standard Eloquent events
    | above. This prevents typos from silently creating junk log entries.
    |
    | Leave empty to allow any custom event name (permissive mode).
    |
    | Example:
    |   'custom_events' => ['exported', 'approved', 'rejected', 'impersonated'],
    */
    'custom_events' => [],

    /*
    |--------------------------------------------------------------------------
    | Excluded Attributes
    |--------------------------------------------------------------------------
    | These attribute names are never written to the audit log, regardless of
    | model-level $auditable allowlists or $auditExclude lists.
    */
    'excluded_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture Request Context
    |--------------------------------------------------------------------------
    | When enabled, the IP address and User-Agent are stored on every log
    | entry. Disable if your deployment strips these headers or if you prefer
    | not to store them for privacy reasons.
    */
    'capture_ip' => true,

    'capture_user_agent' => true,

    /*
    |--------------------------------------------------------------------------
    | Queued Logging
    |--------------------------------------------------------------------------
    | Set 'queue' to true to write audit logs asynchronously via a queue job.
    | This keeps model events fast and offloads I/O to a worker.
    | Requires a working queue driver (not 'sync').
    */
    'queue' => false,

    'queue_connection' => null,  // null = default queue connection

    'queue_name' => null,        // null = default queue name

    /*
    |--------------------------------------------------------------------------
    | Morph Map
    |--------------------------------------------------------------------------
    | When true, the package resolves auditable_type through Laravel's morph
    | map (Relation::getMorphedModel / getMorphAlias) so that short aliases
    | like "order" are stored instead of fully-qualified class names.
    | Set to false to always store the full class name.
    */
    'use_morph_map' => true,

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    | Automatically prune audit logs older than this many days.
    | Set to null to disable automatic pruning.
    | Wire up the Laravel scheduler to call: php artisan model:prune
    */
    'prune_after_days' => null,
];
