# laravel-tenant-audit

Multi-tenant audit logging package for Laravel. Tracks model create/update/delete events per tenant with configurable attribute filtering.

## Current Session
Read this file. Find the first unchecked [ ] step. Do that step only. Then stop and wait.

---

## Package Identity

- **Composer name**: `ahmed3bead/laravel-tenant-audit`
- **Namespace**: `Ahmed3bead\TenantAudit`
- **Author**: Ahmed Abead — this is our own package
- **Supports**: Laravel 10 / 11 / 12 / 13
- **PHP**: 8.1+ (Laravel 10/11/12) — 8.3+ required for Laravel 13

## Architecture

```
src/
├── TenantAuditServiceProvider.php   # Registers manager, publishes config + migrations
├── TenantAuditManager.php           # Core logging logic; resolves tenant, user, request context
├── Concerns/
│   └── Auditable.php                # Trait — boot observer, expose audit helpers
├── Contracts/
│   ├── AuditableContract.php        # Interface for auditable models
│   └── TenantResolverContract.php   # Interface for custom tenant resolvers
├── Facades/
│   └── TenantAudit.php              # Facade → TenantAuditManager
├── Models/
│   └── AuditLog.php                 # Eloquent model for tenant_audit_logs table
└── Observers/
    └── AuditObserver.php            # Eloquent observer — created/updated/deleted/restored
```

## Key Concepts

### Auditable Models

Apply the trait and interface to any model:

```php
use Ahmed3bead\TenantAudit\Concerns\Auditable;
use Ahmed3bead\TenantAudit\Contracts\AuditableContract;

class Order extends Model implements AuditableContract
{
    use Auditable;

    // Optional: restrict which attributes are audited
    protected array $auditable = ['status', 'total'];

    // Optional: exclude specific attributes (merged with config exclusions)
    protected array $auditExclude = ['internal_notes'];
}
```

### Tenant Resolution

Implement `TenantResolverContract` and bind it in the container, then set in config:

```php
// config/tenant-audit.php
'tenant_resolver' => \App\Services\TenantResolver::class,
```

### Manual Logging

```php
use Ahmed3bead\TenantAudit\Facades\TenantAudit;

TenantAudit::log('exported', $order, [], ['format' => 'csv'], tenantId: 'acme');
```

## Database

Single table: `tenant_audit_logs`

| Column          | Type      | Notes                            |
|-----------------|-----------|----------------------------------|
| id              | bigint    | PK                               |
| tenant_id       | string    | nullable, indexed                |
| user_id         | bigint    | nullable, indexed                |
| event           | string    | created/updated/deleted/restored |
| auditable_type  | string    | morph type                       |
| auditable_id    | bigint    | morph id                         |
| old_values      | json      | nullable                         |
| new_values      | json      | nullable                         |
| ip_address      | inet      | nullable                         |
| user_agent      | text      | nullable                         |
| metadata        | json      | nullable, free-form extras       |
| created_at      | timestamp | no updated_at                    |

## Commands

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run tests with coverage (min 80%)
composer test:coverage

# Format code
composer format
```

## My Stack

- Laravel 10 / 11 / 12 / 13
- PHP 8.1+ (8.3+ required when running on Laravel 13)
- stancl/tenancy for multi-tenancy
- MySQL / GCP Cloud SQL
- GCP Cloud Run deployment
- Pest for testing

## composer.json constraints

```json
"require": {
    "php": "^8.1",
    "illuminate/support": "^10.0|^11.0|^12.0|^13.0"
}
```

## Development Guidelines

- PHP 8.1+ syntax minimum (readonly properties, enums, named args, match expressions)
- Use PHP 8.3+ features only behind a version check — keep 8.1 compat as the baseline
- No static state — all context resolved through the container
- `TenantAuditManager` is the single source of truth for writing logs
- Observer delegates to manager; manager does not depend on observer
- New events (e.g. `forceDeleted`) go in `AuditObserver`, not `TenantAuditManager`
- All new public API must have corresponding Pest tests in `tests/`
- Excluded attributes are always filtered — never logged, even if explicitly dirty
- The `$auditable` allowlist takes precedence over `$auditExclude` if both are set

## Testing

Tests use Pest + Orchestra Testbench with SQLite in-memory.

```bash
vendor/bin/pest
vendor/bin/pest --coverage --min=80
```

Feature tests create their own schema in `beforeEach` and tear it down in `afterEach`.
Unit tests operate on the `tenant_audit_logs` table from the package migration.

---

## Build Checklist

- [x] Step 1: composer.json + package skeleton
- [x] Step 2: config/tenant-audit.php
- [x] Step 3: migration (tenant_audit_logs table)
- [x] Step 4: AuditLog model
- [x] Step 5: AuditableContract + TenantResolverContract interfaces
- [x] Step 6: AuditObserver
- [x] Step 7: Auditable trait (Concerns/Auditable.php)
- [x] Step 8: TenantAuditManager (core logic)
- [x] Step 9: TenantAudit Facade
- [x] Step 10: TenantAuditServiceProvider (wire everything)
- [x] Step 11: Pest tests — Unit
- [x] Step 12: Pest tests — Feature
- [x] Step 13: README.md (installation + usage)
- [ ] Step 14: Tag v1.0.0 + publish to Packagist

---

## Working Rules

1. Do ONE step at a time
2. After each step: show what was created/changed
3. Mark the completed step [x] in the checklist
4. State the next step clearly before stopping
5. Wait for "continue" before moving on
6. Hit a decision point? Ask — never assume
7. Never touch a file already marked [x] unless asked