# laravel-tenant-audit

Multi-tenant audit logging for Laravel. Tracks model `created`, `updated`, `deleted`, `restored`, and custom business events per tenant, with full control over attribute filtering, actor resolution, and storage.

- Polymorphic actor support — Admin, Vendor, Customer, or any user model
- Per-model attribute allowlists and excludelists
- Per-model and global event toggles
- Custom business events (`approved`, `exported`, `impersonated`, …)
- Configurable column names, DB connection, and table
- Optional async queue support
- Laravel morph map aware
- Auto-pruning via `model:prune`

**Supports:** Laravel 10 / 11 / 12 / 13 · PHP 8.1+

---

## Installation

```bash
composer require ahmed3bead/laravel-tenant-audit
```

### Publish and migrate

```bash
# Publish config
php artisan vendor:publish --tag=tenant-audit-config

# Publish migration then run it
php artisan vendor:publish --tag=tenant-audit-migrations
php artisan migrate

# Optional: publish a TenantResolver stub
php artisan vendor:publish --tag=tenant-audit-stubs
```

---

## Quick start

Add the `Auditable` trait and `AuditableContract` interface to any model you want to track:

```php
use Ahmed3bead\TenantAudit\Concerns\Auditable;
use Ahmed3bead\TenantAudit\Contracts\AuditableContract;

class Order extends Model implements AuditableContract
{
    use Auditable;
}
```

That's it. Every `created`, `updated`, `deleted`, and `restored` event is now logged to `tenant_audit_logs`.

---

## Configuration

All options live in `config/tenant-audit.php`.

### Tenant resolution

Implement `TenantResolverContract` and point the config to it:

```php
// app/Services/TenantResolver.php
use Ahmed3bead\TenantAudit\Contracts\TenantResolverContract;

class TenantResolver implements TenantResolverContract
{
    public function getTenantId(): int|string|null
    {
        return tenant()?->getTenantKey(); // stancl/tenancy example
    }
}
```

```php
// config/tenant-audit.php
'tenant_resolver' => \App\Services\TenantResolver::class,
```

### Actor resolution — multiple user models

The package stores the actor as a polymorphic pair (`user_type` + `user_id`), so Admin, Vendor, Customer, and any other user model are all supported without ambiguity.

**Default behaviour** — uses `auth()->user()` automatically. No config needed for a single-guard app.

**Multi-guard / multi-model** — set `user_resolver` to a callable:

```php
// config/tenant-audit.php
'user_resolver' => fn () => match (true) {
    auth('admin')->check()    => ['type' => \App\Models\Admin::class,    'id' => auth('admin')->id()],
    auth('vendor')->check()   => ['type' => \App\Models\Vendor::class,   'id' => auth('vendor')->id()],
    auth('customer')->check() => ['type' => \App\Models\Customer::class, 'id' => auth('customer')->id()],
    default                   => null,
},
```

Retrieve the actor from a log entry:

```php
$log = AuditLog::find(1);
$log->user;       // returns Admin, Vendor, Customer, etc. — fully resolved
$log->user_type;  // "App\Models\Admin"
$log->user_id;    // 42
```

### Attribute filtering

**Allowlist** — only audit specific attributes:

```php
class Order extends Model implements AuditableContract
{
    use Auditable;

    // Only 'status' and 'total' appear in audit logs — everything else ignored
    protected array $auditable = ['status', 'total'];
}
```

**Excludelist** — never audit specific attributes on this model:

```php
protected array $auditExclude = ['internal_notes', 'cache_key'];
```

**Global excludelist** — applies to every model, regardless of model-level settings:

```php
// config/tenant-audit.php
'excluded_attributes' => [
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
],
```

> When both `$auditable` and `$auditExclude` are set, `$auditable` wins — only allowlisted keys are evaluated.

### Event control

**Global** — disable specific events for all models:

```php
// config/tenant-audit.php
'events' => [
    'created'      => true,
    'updated'      => true,
    'deleted'      => true,
    'restored'     => true,
    'forceDeleted' => false, // opt-in
],
```

**Per-model** — override the global list for a specific model:

```php
class ShipmentOrder extends Model implements AuditableContract
{
    use Auditable;

    // Only log create and delete — skip updated and restored
    protected array $auditEvents = ['created', 'deleted'];
}
```

### Custom events

Log any business action that is not an Eloquent lifecycle event:

```php
// On the model instance
$order->auditEvent('approved');

$order->auditEvent('status_changed',
    oldValues: ['status' => 'pending'],
    newValues: ['status' => 'approved'],
    metadata:  ['reviewed_by' => 'compliance-team'],
);

// Via the Facade (useful when you don't have a model instance)
use Ahmed3bead\TenantAudit\Facades\TenantAudit;

TenantAudit::log('impersonated', $targetUser, metadata: ['by_admin_id' => 1]);
TenantAudit::log('exported', $report, tenantId: 'acme', metadata: ['format' => 'csv']);
```

**Strict mode** — allowlist permitted custom event names to catch typos:

```php
// config/tenant-audit.php
'custom_events' => ['approved', 'rejected', 'exported', 'impersonated'],
```

Leave `custom_events` empty to allow any event name (permissive mode, the default).

---

## Runtime audit control

### Disable on a single instance

```php
// This update is not logged
$order->disableAudit()->update(['cache_key' => '...']);

// Re-enable for subsequent operations
$order->enableAudit()->update(['status' => 'active']);
```

### Disable for a block of code

```php
// Bulk seed or migration — no audit noise
Order::withoutAudit(function () {
    Order::query()->update(['synced_at' => now()]);
});

// Logging resumes normally after the closure — even if it throws
```

---

## Querying audit logs

```php
use Ahmed3bead\TenantAudit\Models\AuditLog;

// All logs for a tenant
AuditLog::forTenant('acme')->latestFirst()->get();

// All logs for a specific model instance
$order->auditLogs()->latestFirst()->get();

// All logs by a specific actor type + id
AuditLog::forUser(Admin::class, 1)->get();
AuditLog::forUser($admin)->get();  // model instance shorthand

// Filter by event
AuditLog::byEvent('approved')->forTenant('acme')->get();

// Filter by auditable model
AuditLog::forModel(Order::class, $order->id)->get();

// Ordering
AuditLog::latestFirst()->limit(50)->get();
AuditLog::oldestFirst()->get();
```

---

## Database schema

Single table: `tenant_audit_logs`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `tenant_id` | string | nullable, indexed |
| `user_type` | string | nullable — morph type of actor |
| `user_id` | bigint | nullable — actor PK |
| `event` | string(50) | created / updated / deleted / restored / custom |
| `auditable_type` | string | morph type of subject model |
| `auditable_id` | bigint | subject model PK |
| `old_values` | json | nullable |
| `new_values` | json | nullable |
| `ip_address` | inet | nullable |
| `user_agent` | text | nullable |
| `metadata` | json | nullable — free-form extras |
| `created_at` | timestamp | no `updated_at` |

---

## Swapping the AuditLog model

Extend `AuditLog` and point the config to your class:

```php
// app/Models/MyAuditLog.php
use Ahmed3bead\TenantAudit\Models\AuditLog;

class MyAuditLog extends AuditLog
{
    // add custom scopes, relations, accessors…
}
```

```php
// config/tenant-audit.php
'model' => \App\Models\MyAuditLog::class,
```

---

## Pruning old logs

Enable pruning in config:

```php
// config/tenant-audit.php
'prune_after_days' => 90,
```

Then schedule Laravel's built-in prune command:

```php
// routes/console.php  (Laravel 11+)
Schedule::command('model:prune')->daily();
```

---

## Async queue support

Write audit logs asynchronously to keep model events fast:

```php
// config/tenant-audit.php
'queue'            => true,
'queue_connection' => 'redis',
'queue_name'       => 'audit',
```

Requires a working queue driver. The `sync` driver will process jobs inline.

---

## Disable IP / User-Agent capture

```php
// config/tenant-audit.php
'capture_ip'         => false,
'capture_user_agent' => false,
```

---

## Testing

```bash
composer test                  # run all tests
composer test:coverage         # run with coverage (min 80%)
composer format                # run Laravel Pint
```

---

## License

MIT — [Ahmed Abead](https://github.com/ahmed3bead)
