<?php

use Ahmed3bead\TenantAudit\Concerns\Auditable;
use Ahmed3bead\TenantAudit\Contracts\AuditableContract;
use Ahmed3bead\TenantAudit\Models\AuditLog;
use Ahmed3bead\TenantAudit\TenantAuditManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Test models
// ---------------------------------------------------------------------------

class Order extends Model implements AuditableContract
{
    use Auditable;

    protected $table    = 'orders';
    protected $fillable = ['status', 'total', 'internal_notes', 'password'];

    protected array $auditExclude = ['internal_notes'];
}

class Product extends Model implements AuditableContract
{
    use Auditable;

    protected $table    = 'orders'; // reuse same table for simplicity
    protected $fillable = ['status', 'total', 'internal_notes', 'password'];

    // Allowlist — only these attributes are audited
    protected array $auditable = ['status'];
}

class Invoice extends Model implements AuditableContract
{
    use SoftDeletes;
    use Auditable;

    protected $table    = 'orders';
    protected $fillable = ['status', 'total', 'internal_notes', 'password'];
}

class ShipmentOrder extends Model implements AuditableContract
{
    use Auditable;

    protected $table    = 'orders';
    protected $fillable = ['status', 'total', 'internal_notes', 'password'];

    // Only audit created and deleted — skip updated and restored
    protected array $auditEvents = ['created', 'deleted'];
}

// ---------------------------------------------------------------------------
// Schema setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('status')->default('pending');
        $table->decimal('total', 10, 2)->default(0);
        $table->string('internal_notes')->nullable();
        $table->string('password')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('orders');
});

// ---------------------------------------------------------------------------
// Created event
// ---------------------------------------------------------------------------

it('logs a created event', function () {
    Order::create(['status' => 'pending', 'total' => 100.00]);

    expect(AuditLog::count())->toBe(1);
    expect(AuditLog::first()->event)->toBe('created');
});

it('stores new_values on created', function () {
    Order::create(['status' => 'pending', 'total' => 50.00]);

    $log = AuditLog::first();

    expect($log->new_values)->toHaveKey('status', 'pending');
    expect($log->new_values)->toHaveKey('total', '50.00');
    expect($log->old_values)->toBeNull();
});

// ---------------------------------------------------------------------------
// Updated event
// ---------------------------------------------------------------------------

it('logs an updated event with old and new values', function () {
    $order = Order::create(['status' => 'pending', 'total' => 100.00]);
    $order->update(['status' => 'paid']);

    $log = AuditLog::byEvent('updated')->first();

    expect($log)->not->toBeNull();
    expect($log->old_values)->toHaveKey('status', 'pending');
    expect($log->new_values)->toHaveKey('status', 'paid');
});

it('does not log an update when nothing changed', function () {
    $order = Order::create(['status' => 'pending']);
    $count = AuditLog::count();

    $order->update(['status' => 'pending']); // same value

    expect(AuditLog::count())->toBe($count);
});

// ---------------------------------------------------------------------------
// Deleted event
// ---------------------------------------------------------------------------

it('logs a deleted event', function () {
    $order = Order::create(['status' => 'pending']);
    $order->delete();

    expect(AuditLog::byEvent('deleted')->count())->toBe(1);
});

it('stores old_values on deleted', function () {
    $order = Order::create(['status' => 'active']);
    $order->delete();

    $log = AuditLog::byEvent('deleted')->first();

    expect($log->old_values)->toHaveKey('status', 'active');
    expect($log->new_values)->toBeNull();
});

// ---------------------------------------------------------------------------
// Restored event (soft delete)
// ---------------------------------------------------------------------------

it('logs a restored event', function () {
    $invoice = Invoice::create(['status' => 'pending']);
    $invoice->delete();
    $invoice->restore();

    expect(AuditLog::byEvent('restored')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Attribute exclusion
// ---------------------------------------------------------------------------

it('excludes per-model excluded attributes', function () {
    $order = Order::create(['status' => 'pending', 'internal_notes' => 'secret']);
    $order->update(['status' => 'paid', 'internal_notes' => 'changed']);

    $log = AuditLog::byEvent('updated')->first();

    expect($log->old_values)->not->toHaveKey('internal_notes');
    expect($log->new_values)->not->toHaveKey('internal_notes');
});

it('excludes global excluded attributes from config', function () {
    $order = Order::create(['status' => 'pending', 'password' => 'secret']);
    $order->update(['status' => 'paid', 'password' => 'new-secret']);

    $log = AuditLog::byEvent('updated')->first();

    expect($log->old_values)->not->toHaveKey('password');
    expect($log->new_values)->not->toHaveKey('password');
});

it('does not log update when only excluded attributes changed', function () {
    $order = Order::create(['status' => 'pending', 'password' => 'secret']);
    $count = AuditLog::count();

    $order->update(['password' => 'other-secret']);

    expect(AuditLog::count())->toBe($count);
});

// ---------------------------------------------------------------------------
// Attribute allowlist ($auditable)
// ---------------------------------------------------------------------------

it('only logs allowlisted attributes when $auditable is set', function () {
    $product = Product::create(['status' => 'active', 'total' => 99.99]);

    $log = AuditLog::first();

    expect($log->new_values)->toHaveKey('status');
    expect($log->new_values)->not->toHaveKey('total');
});

it('allowlist takes precedence over excluded attributes', function () {
    $product = Product::create(['status' => 'active', 'internal_notes' => 'note']);
    $product->update(['status' => 'inactive', 'internal_notes' => 'changed']);

    $log = AuditLog::byEvent('updated')->first();

    // allowlist only includes 'status', so internal_notes never appears
    expect($log->new_values)->toHaveKey('status', 'inactive');
    expect($log->new_values)->not->toHaveKey('internal_notes');
});

// ---------------------------------------------------------------------------
// Per-model event override ($auditEvents)
// ---------------------------------------------------------------------------

it('respects per-model event override', function () {
    // ShipmentOrder only audits created and deleted
    $shipment = ShipmentOrder::create(['status' => 'pending']);
    $shipment->update(['status' => 'shipped']); // should NOT be logged
    $shipment->delete();                         // should be logged

    expect(AuditLog::byEvent('created')->count())->toBe(1);
    expect(AuditLog::byEvent('updated')->count())->toBe(0);
    expect(AuditLog::byEvent('deleted')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Global event config toggle
// ---------------------------------------------------------------------------

it('skips events disabled in global config', function () {
    config()->set('tenant-audit.events.updated', false);

    $order = Order::create(['status' => 'pending']);
    $order->update(['status' => 'paid']);

    expect(AuditLog::byEvent('updated')->count())->toBe(0);
    expect(AuditLog::byEvent('created')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// disableAudit / enableAudit
// ---------------------------------------------------------------------------

it('skips logging when auditing is disabled on instance', function () {
    $order = Order::create(['status' => 'pending']);
    $count = AuditLog::count();

    $order->disableAudit()->update(['status' => 'paid']);

    expect(AuditLog::count())->toBe($count);
});

it('resumes logging after enableAudit', function () {
    $order = Order::create(['status' => 'pending']);
    $order->disableAudit()->update(['status' => 'paid']);
    $count = AuditLog::count();

    $order->enableAudit()->update(['status' => 'shipped']);

    expect(AuditLog::count())->toBe($count + 1);
});

it('only disables auditing on the specific instance', function () {
    $order1 = Order::create(['status' => 'pending']);
    $order2 = Order::create(['status' => 'pending']);

    $order1->disableAudit()->update(['status' => 'paid']);
    $order2->update(['status' => 'paid']);

    expect(AuditLog::byEvent('updated')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// withoutAudit
// ---------------------------------------------------------------------------

it('suppresses all logs within withoutAudit closure', function () {
    Order::withoutAudit(function () {
        Order::create(['status' => 'pending']);
        Order::create(['status' => 'active']);
    });

    expect(AuditLog::count())->toBe(0);
});

it('resumes logging after withoutAudit closure', function () {
    Order::withoutAudit(fn () => Order::create(['status' => 'pending']));

    Order::create(['status' => 'active']);

    expect(AuditLog::count())->toBe(1);
});

it('resumes logging even when closure throws', function () {
    $manager = app(TenantAuditManager::class);

    try {
        Order::withoutAudit(function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($manager->isPaused())->toBeFalse();
    Order::create(['status' => 'pending']);
    expect(AuditLog::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// auditEvent — custom events
// ---------------------------------------------------------------------------

it('logs a custom event via auditEvent()', function () {
    $order = Order::create(['status' => 'pending']);

    $order->auditEvent('approved');

    expect(AuditLog::byEvent('approved')->count())->toBe(1);
});

it('stores metadata on custom event', function () {
    $order = Order::create(['status' => 'pending']);

    $order->auditEvent('exported', metadata: ['format' => 'csv', 'rows' => 42]);

    $log = AuditLog::byEvent('exported')->first();

    expect($log->metadata)->toHaveKey('format', 'csv');
    expect($log->metadata)->toHaveKey('rows', 42);
});

it('stores old and new values on custom event', function () {
    $order = Order::create(['status' => 'pending']);

    $order->auditEvent('status_changed',
        oldValues: ['status' => 'pending'],
        newValues: ['status' => 'approved'],
    );

    $log = AuditLog::byEvent('status_changed')->first();

    expect($log->old_values)->toHaveKey('status', 'pending');
    expect($log->new_values)->toHaveKey('status', 'approved');
});

it('throws when custom event not in allowlist', function () {
    config()->set('tenant-audit.custom_events', ['approved', 'rejected']);

    $order = Order::create(['status' => 'pending']);

    expect(fn () => $order->auditEvent('typo_event'))
        ->toThrow(InvalidArgumentException::class);
});

it('allows any custom event when allowlist is empty', function () {
    config()->set('tenant-audit.custom_events', []);

    $order = Order::create(['status' => 'pending']);

    $order->auditEvent('anything_goes');

    expect(AuditLog::byEvent('anything_goes')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Polymorphic user resolution
// ---------------------------------------------------------------------------

it('stores user_type and user_id from default auth', function () {
    // Use user_resolver to simulate a logged-in user without needing
    // a real Authenticatable implementation in a package test context
    config()->set('tenant-audit.user_resolver', fn () => [
        'type' => 'App\\Models\\Admin',
        'id'   => 7,
    ]);

    $order = Order::create(['status' => 'pending']);

    $log = AuditLog::first();

    expect($log->user_type)->toBe('App\\Models\\Admin');
    expect($log->user_id)->toBe(7);
});

it('resolves user from custom user_resolver config', function () {
    config()->set('tenant-audit.user_resolver', fn () => [
        'type' => 'App\\Models\\Admin',
        'id'   => 99,
    ]);

    Order::create(['status' => 'pending']);

    $log = AuditLog::first();

    expect($log->user_type)->toBe('App\\Models\\Admin');
    expect($log->user_id)->toBe(99);
});

it('stores null user when not authenticated', function () {
    Order::create(['status' => 'pending']);

    $log = AuditLog::first();

    expect($log->user_type)->toBeNull();
    expect($log->user_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Tenant resolution
// ---------------------------------------------------------------------------

it('stores tenant_id passed explicitly to log()', function () {
    $manager = app(TenantAuditManager::class);
    $order   = Order::create(['status' => 'pending']);

    $manager->log('exported', $order, tenantId: 'acme-corp');

    $log = AuditLog::byEvent('exported')->first();

    expect($log->tenant_id)->toBe('acme-corp');
});

it('stores null tenant_id when no resolver configured', function () {
    Order::create(['status' => 'pending']);

    expect(AuditLog::first()->tenant_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// auditLogs relation
// ---------------------------------------------------------------------------

it('retrieves audit logs via auditLogs() relation', function () {
    $order = Order::create(['status' => 'pending']);
    $order->update(['status' => 'paid']);
    $order->delete();

    expect($order->auditLogs()->count())->toBe(3);
});

it('auditLogs relation uses configurable model class', function () {
    config()->set('tenant-audit.model', AuditLog::class);

    $order = Order::create(['status' => 'pending']);

    expect($order->auditLogs()->getRelated())->toBeInstanceOf(AuditLog::class);
});

// ---------------------------------------------------------------------------
// Manager pause / resume directly
// ---------------------------------------------------------------------------

it('manager pause suppresses all logging', function () {
    $manager = app(TenantAuditManager::class);
    $manager->pause();

    Order::create(['status' => 'pending']);

    $manager->resume();

    expect(AuditLog::count())->toBe(0);
});

it('manager isPaused reflects state', function () {
    $manager = app(TenantAuditManager::class);

    expect($manager->isPaused())->toBeFalse();
    $manager->pause();
    expect($manager->isPaused())->toBeTrue();
    $manager->resume();
    expect($manager->isPaused())->toBeFalse();
});
