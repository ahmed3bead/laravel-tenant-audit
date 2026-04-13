<?php

namespace Ahmed3bead\TenantAudit\Contracts;

interface TenantResolverContract
{
    /**
     * Resolve and return the current tenant identifier.
     *
     * Return null when no tenant context is active (e.g. central domain,
     * CLI commands, or unauthenticated requests).
     *
     * The return type is int|string|null because tenant IDs may be integers
     * (auto-increment PKs), UUID strings, or short slug strings depending
     * on the tenancy implementation (stancl/tenancy, Spatie, custom, etc.).
     */
    public function getTenantId(): int|string|null;
}
