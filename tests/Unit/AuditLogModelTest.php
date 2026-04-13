<?php

use Ahmed3bead\TenantAudit\Models\AuditLog;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeLog(array $overrides = []): AuditLog
{
    return AuditLog::create(array_merge([
        'tenant_id'      => 'tenant-1',
        'event'          => 'created',
        'auditable_type' => 'App\\Models\\Order',
        'auditable_id'   => 1,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Casts
// ---------------------------------------------------------------------------

it('casts old_values as array', function () {
    $log = makeLog(['old_values' => ['status' => 'pending']]);

    expect($log->old_values)->toBeArray()->toHaveKey('status', 'pending');
});

it('casts new_values as array', function () {
    $log = makeLog(['new_values' => ['status' => 'paid']]);

    expect($log->new_values)->toBeArray()->toHaveKey('status', 'paid');
});

it('casts metadata as array', function () {
    $log = makeLog(['metadata' => ['source' => 'api', 'version' => 2]]);

    expect($log->metadata)->toBeArray()
        ->toHaveKey('source', 'api')
        ->toHaveKey('version', 2);
});

it('returns null for unset json columns', function () {
    $log = makeLog();

    expect($log->old_values)->toBeNull();
    expect($log->new_values)->toBeNull();
    expect($log->metadata)->toBeNull();
});

// ---------------------------------------------------------------------------
// Timestamps
// ---------------------------------------------------------------------------

it('has no updated_at column', function () {
    $log = makeLog();

    expect($log->updated_at)->toBeNull();
    expect(AuditLog::UPDATED_AT)->toBeNull();
});

it('automatically sets created_at', function () {
    $log = makeLog();

    expect($log->created_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Table & connection from config
// ---------------------------------------------------------------------------

it('reads table name from config', function () {
    $log = new AuditLog;

    expect($log->getTable())->toBe('tenant_audit_logs');
});

it('uses custom table name from config', function () {
    config()->set('tenant-audit.table', 'custom_audit_table');

    $log = new AuditLog;

    expect($log->getTable())->toBe('custom_audit_table');

    config()->set('tenant-audit.table', 'tenant_audit_logs');
});

it('returns null connection name when config is null (uses app default)', function () {
    config()->set('tenant-audit.connection', null);

    $log = new AuditLog;

    // null means Eloquent uses the default DB connection — not a specific name
    expect($log->getConnectionName())->toBeNull();
});

it('returns configured connection name when set', function () {
    config()->set('tenant-audit.connection', 'mysql');

    $log = new AuditLog;

    expect($log->getConnectionName())->toBe('mysql');

    config()->set('tenant-audit.connection', null);
});

// ---------------------------------------------------------------------------
// Scopes — forTenant
// ---------------------------------------------------------------------------

it('scopes by tenant id', function () {
    makeLog(['tenant_id' => 'tenant-1']);
    makeLog(['tenant_id' => 'tenant-2']);

    expect(AuditLog::forTenant('tenant-1')->count())->toBe(1);
    expect(AuditLog::forTenant('tenant-2')->count())->toBe(1);
});

it('uses configurable tenant_id_column in forTenant scope', function () {
    config()->set('tenant-audit.tenant_id_column', 'tenant_id');

    makeLog(['tenant_id' => 'acme']);

    expect(AuditLog::forTenant('acme')->count())->toBe(1);
    expect(AuditLog::forTenant('other')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scopes — forUser (polymorphic)
// ---------------------------------------------------------------------------

it('scopes by user using model instance', function () {
    $admin = new class {
        public function getKey(): int { return 42; }
    };

    makeLog(['user_type' => get_class($admin), 'user_id' => 42]);
    makeLog(['user_type' => get_class($admin), 'user_id' => 99]);

    expect(AuditLog::forUser($admin)->count())->toBe(1);
});

it('scopes by user using class and id', function () {
    makeLog(['user_type' => 'App\\Models\\Admin', 'user_id' => 1]);
    makeLog(['user_type' => 'App\\Models\\Vendor', 'user_id' => 1]);

    expect(AuditLog::forUser('App\\Models\\Admin', 1)->count())->toBe(1);
    expect(AuditLog::forUser('App\\Models\\Vendor', 1)->count())->toBe(1);
});

it('does not mix users of different types with same id', function () {
    makeLog(['user_type' => 'App\\Models\\Admin', 'user_id' => 5]);
    makeLog(['user_type' => 'App\\Models\\Customer', 'user_id' => 5]);

    expect(AuditLog::forUser('App\\Models\\Admin', 5)->count())->toBe(1);
    expect(AuditLog::forUser('App\\Models\\Customer', 5)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scopes — forModel
// ---------------------------------------------------------------------------

it('scopes by auditable model type and id', function () {
    makeLog(['auditable_type' => 'App\\Models\\Order', 'auditable_id' => 1]);
    makeLog(['auditable_type' => 'App\\Models\\Order', 'auditable_id' => 2]);
    makeLog(['auditable_type' => 'App\\Models\\Invoice', 'auditable_id' => 1]);

    expect(AuditLog::forModel('App\\Models\\Order', 1)->count())->toBe(1);
    expect(AuditLog::forModel('App\\Models\\Invoice', 1)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scopes — byEvent
// ---------------------------------------------------------------------------

it('scopes by event name', function () {
    makeLog(['event' => 'created']);
    makeLog(['event' => 'updated']);
    makeLog(['event' => 'deleted']);

    expect(AuditLog::byEvent('created')->count())->toBe(1);
    expect(AuditLog::byEvent('updated')->count())->toBe(1);
    expect(AuditLog::byEvent('deleted')->count())->toBe(1);
    expect(AuditLog::byEvent('restored')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scopes — ordering
// ---------------------------------------------------------------------------

it('orders oldest first', function () {
    $first  = makeLog(['event' => 'created']);
    $second = makeLog(['event' => 'updated']);

    $results = AuditLog::oldestFirst()->pluck('id');

    expect($results->first())->toBe($first->id);
    expect($results->last())->toBe($second->id);
});

it('orders latest first', function () {
    $first  = makeLog(['event' => 'created']);
    $second = makeLog(['event' => 'updated']);

    $results = AuditLog::latestFirst()->pluck('id');

    expect($results->first())->toBe($second->id);
    expect($results->last())->toBe($first->id);
});

// ---------------------------------------------------------------------------
// Prunable
// ---------------------------------------------------------------------------

it('prunable query excludes recent records', function () {
    config()->set('tenant-audit.prune_after_days', 30);

    $old   = makeLog(['event' => 'created']);
    $fresh = makeLog(['event' => 'updated']);

    // Back-date the old record
    $old->forceFill(['created_at' => now()->subDays(31)])->save();

    $prunable = (new AuditLog)->prunable()->pluck('id');

    expect($prunable)->toContain($old->id);
    expect($prunable)->not->toContain($fresh->id);
});

// ---------------------------------------------------------------------------
// Fillable
// ---------------------------------------------------------------------------

it('accepts all expected fillable columns', function () {
    $log = makeLog([
        'user_type'  => 'App\\Models\\Admin',
        'user_id'    => 7,
        'old_values' => ['x' => 1],
        'new_values' => ['x' => 2],
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'metadata'   => ['key' => 'val'],
    ]);

    expect($log->user_type)->toBe('App\\Models\\Admin');
    expect($log->user_id)->toBe(7);
    expect($log->ip_address)->toBe('127.0.0.1');
    expect($log->user_agent)->toBe('PHPUnit');
    expect($log->metadata)->toHaveKey('key', 'val');
});
