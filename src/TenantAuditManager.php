<?php

namespace Ahmed3bead\TenantAudit;

use Ahmed3bead\TenantAudit\Contracts\AuditableContract;
use Ahmed3bead\TenantAudit\Contracts\TenantResolverContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

class TenantAuditManager
{
    /**
     * When true, log() is a no-op. Used by withoutAudit().
     */
    private bool $paused = false;

    public function __construct(protected Application $app) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Write an audit log entry.
     *
     * @param  string            $event      Eloquent event name or custom event name
     * @param  Model             $model      The model being audited
     * @param  array             $oldValues  Attribute state before the change
     * @param  array             $newValues  Attribute state after the change
     * @param  int|string|null   $tenantId   Override resolved tenant ID
     * @param  array             $metadata   Free-form context stored in metadata column
     *
     * @throws InvalidArgumentException if the event name is not allowed
     */
    public function log(
        string $event,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        int|string|null $tenantId = null,
        array $metadata = [],
    ): ?object {
        if ($this->paused) {
            return null;
        }

        $this->validateEvent($event);

        [$filteredOld, $filteredNew] = $this->filterAttributes($model, $oldValues, $newValues);

        // For updated events, skip writing if filtering left nothing to record
        if ($event === 'updated' && empty($filteredOld) && empty($filteredNew)) {
            return null;
        }

        $actor = $this->resolveActor();

        $data = [
            config('tenant-audit.tenant_id_column', 'tenant_id') => $tenantId ?? $this->resolveTenantId(),
            config('tenant-audit.user_type_column', 'user_type') => $actor['type'] ?? null,
            config('tenant-audit.user_id_column', 'user_id')     => $actor['id'] ?? null,
            'event'          => $event,
            'auditable_type' => $this->resolveMorphType($model),
            'auditable_id'   => $model->getKey(),
            'old_values'     => $filteredOld ?: null,
            'new_values'     => $filteredNew ?: null,
            'ip_address'     => $this->resolveIpAddress(),
            'user_agent'     => $this->resolveUserAgent(),
            'metadata'       => $metadata ?: null,
        ];

        $modelClass = config('tenant-audit.model');

        if (config('tenant-audit.queue', false)) {
            return $this->dispatchQueued($modelClass, $data);
        }

        return $modelClass::create($data);
    }

    /**
     * Pause audit logging. All log() calls are no-ops until resume() is called.
     */
    public function pause(): void
    {
        $this->paused = true;
    }

    /**
     * Resume audit logging after a pause() call.
     */
    public function resume(): void
    {
        $this->paused = false;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    // -------------------------------------------------------------------------
    // Attribute filtering
    // -------------------------------------------------------------------------

    /**
     * Apply allowlist and excludelist filtering to old/new value arrays.
     *
     * Rules (in order):
     *  1. If model has a non-empty $auditable allowlist, keep only those keys
     *  2. Remove globally and per-model excluded attributes
     */
    protected function filterAttributes(Model $model, array $old, array $new): array
    {
        $excluded = $this->resolveExcludedAttributes($model);
        $allowlist = $model instanceof AuditableContract
            ? $model->getAuditableAttributes()
            : [];

        $filter = function (array $values) use ($excluded, $allowlist): array {
            if (! empty($allowlist)) {
                $values = array_intersect_key($values, array_flip($allowlist));
            }

            return array_diff_key($values, array_flip($excluded));
        };

        return [$filter($old), $filter($new)];
    }

    protected function resolveExcludedAttributes(Model $model): array
    {
        $modelExclusions = $model instanceof AuditableContract
            ? $model->getAuditExcludedAttributes()
            : [];

        $globalExclusions = config('tenant-audit.excluded_attributes', []);

        return array_values(array_unique(array_merge($globalExclusions, $modelExclusions)));
    }

    // -------------------------------------------------------------------------
    // Context resolvers
    // -------------------------------------------------------------------------

    protected function resolveTenantId(): int|string|null
    {
        $resolverClass = config('tenant-audit.tenant_resolver');

        if (! $resolverClass) {
            return null;
        }

        $resolver = $this->app->bound($resolverClass)
            ? $this->app->make($resolverClass)
            : $this->app->make($resolverClass);

        if (! $resolver instanceof TenantResolverContract) {
            return null;
        }

        return $resolver->getTenantId();
    }

    /**
     * Resolve the current actor as ['type' => ..., 'id' => ...] or null.
     *
     * Tries (in order):
     *  1. user_resolver callable from config
     *  2. Default: auth()->user() with class + key
     */
    protected function resolveActor(): array
    {
        $resolver = config('tenant-audit.user_resolver');

        if ($resolver && is_callable($resolver)) {
            $result = $resolver();

            if (is_array($result) && isset($result['type'], $result['id'])) {
                return $result;
            }

            return ['type' => null, 'id' => null];
        }

        $user = $this->app['auth']->user();

        if (! $user) {
            return ['type' => null, 'id' => null];
        }

        return [
            'type' => get_class($user),
            'id'   => $user->getKey(),
        ];
    }

    protected function resolveIpAddress(): ?string
    {
        if (! config('tenant-audit.capture_ip', true)) {
            return null;
        }

        if ($this->app->runningInConsole()) {
            return null;
        }

        return $this->app['request']->ip();
    }

    protected function resolveUserAgent(): ?string
    {
        if (! config('tenant-audit.capture_user_agent', true)) {
            return null;
        }

        if ($this->app->runningInConsole()) {
            return null;
        }

        return $this->app['request']->userAgent();
    }

    /**
     * Resolve the auditable_type value.
     *
     * When use_morph_map is true and the model has a morph alias registered,
     * the alias is stored instead of the fully-qualified class name.
     */
    protected function resolveMorphType(Model $model): string
    {
        if (! config('tenant-audit.use_morph_map', true)) {
            return get_class($model);
        }

        $alias = Relation::getMorphAlias(get_class($model));

        return $alias !== get_class($model) ? $alias : get_class($model);
    }

    // -------------------------------------------------------------------------
    // Event validation
    // -------------------------------------------------------------------------

    protected function validateEvent(string $event): void
    {
        $allowedCustom = config('tenant-audit.custom_events', []);

        if (empty($allowedCustom)) {
            return; // permissive mode
        }

        $builtIn = array_keys(config('tenant-audit.events', []));

        if (in_array($event, $builtIn, strict: true)) {
            return;
        }

        if (! in_array($event, $allowedCustom, strict: true)) {
            throw new InvalidArgumentException(
                "Audit event \"{$event}\" is not in the allowed custom_events list. "
                . 'Add it to config(\'tenant-audit.custom_events\') or leave the list empty to allow any event.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Queue
    // -------------------------------------------------------------------------

    protected function dispatchQueued(string $modelClass, array $data): null
    {
        dispatch(function () use ($modelClass, $data) {
            $modelClass::create($data);
        })
            ->onConnection(config('tenant-audit.queue_connection'))
            ->onQueue(config('tenant-audit.queue_name'));

        return null;
    }
}
