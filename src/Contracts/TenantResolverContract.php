<?php

declare(strict_types=1);

namespace Flavytech\Etims\Contracts;

/**
 * TenantResolverContract
 *
 * Implement this interface in your application when using the SDK in
 * a multi-tenant SaaS environment.
 *
 * The SDK calls resolve() before every API operation to get the
 * eTIMS credentials for the currently active tenant. Your implementation
 * should read from your tenants table, a session variable, or a domain
 * resolver — whatever your tenancy strategy uses.
 *
 * Example usage:
 *
 *   class MyTenantResolver implements TenantResolverContract
 *   {
 *       public function resolve(): array
 *       {
 *           $tenant = app('currentTenant');
 *           return [
 *               'pin'           => $tenant->kra_pin,
 *               'branch_id'     => $tenant->branch_id,
 *               'device_serial' => $tenant->device_serial,
 *               'secret'        => $tenant->etims_secret,
 *               'mode'          => $tenant->etims_mode,
 *           ];
 *       }
 *
 *       public function tenantId(): string|int
 *       {
 *           return app('currentTenant')->id;
 *       }
 *   }
 *
 * Register in EtimsServiceProvider (or AppServiceProvider):
 *
 *   $this->app->bind(TenantResolverContract::class, MyTenantResolver::class);
 */
interface TenantResolverContract
{
    /**
     * Resolve and return the current tenant's eTIMS credential configuration.
     *
     * Must return an array with keys matching the etims.credentials config structure:
     *   - pin
     *   - branch_id
     *   - device_serial
     *   - secret
     *   - mode (optional, 'sandbox' or 'production')
     *
     * @return array<string, string>
     */
    public function resolve(): array;

    /**
     * Return the unique identifier for the current tenant.
     *
     * Used for scoping audit logs, failed invoice records, and cache keys.
     */
    public function tenantId(): string|int;
}
