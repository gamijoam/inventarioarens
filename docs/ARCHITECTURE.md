# Inventory Arens Architecture

## Goal

Inventory Arens is a Laravel monolith designed as a modular SaaS inventory system. Every business record must belong to a tenant through `tenant_id`.

## Tenancy Rules

- One shared PostgreSQL database for all tenants.
- Business tables must include `tenant_id`.
- Queries for tenant-owned models must use `BelongsToTenant`.
- `BelongsToTenant` adds a global Eloquent scope and assigns `tenant_id` automatically during creation.
- Creating tenant-owned data without a resolved tenant fails fast.
- Unique business keys must be scoped by tenant, for example `tenant_id + sku`.

## Request Flow

1. `ResolveTenant` reads the tenant from `X-Tenant`, route/query tenant, or domain.
2. `TenantManager` stores the current tenant for the request.
3. Spatie Permission receives the same tenant id as its team id.
4. Tenant-owned models filter automatically with `TenantScope`.
5. Policies and permissions must still validate user intent before critical actions.

## Modules

Modules live under `app/Modules`.

Suggested module structure:

```txt
ModuleName/
├── Actions/
├── DTOs/
├── Models/
├── Policies/
├── Services/
├── Controllers/
├── Requests/
├── Resources/
├── routes.php
└── ModuleServiceProvider.php
```

The first implemented modules are:

- `Tenancy`: tenants, tenant resolution, request isolation.
- `Products`: initial tenant-scoped model used to prove isolation.

## Permissions

Spatie Laravel Permission is configured with teams enabled. In this project the team foreign key is `tenant_id`, so the same user can have different roles in different tenants.

Base permissions and initial role maps live in `App\Support\Permissions\BasePermissions`.

## Current Safety Tests

`tests/Feature/Tenancy/TenantIsolationTest.php` verifies:

- tenant-scoped queries only return current tenant data;
- tenant-owned records cannot be created without a current tenant;
- the same SKU can exist in different tenants.

## Next Phase

The next phase should add:

- tenant-aware policies;
- deeper permission tests;
- inventory tables based on `stock_movements` and `stock_balances`;
- audit logging for business actions.
