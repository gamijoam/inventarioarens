<?php

namespace Tests\Feature\AdminPortal;

use Tests\TestCase;

class AdminPortalWebTest extends TestCase
{
    public function test_admin_portal_page_is_available(): void
    {
        $this
            ->get('/admin')
            ->assertOk()
            ->assertSee('Portal administrativo')
            ->assertSee('admin-login-form')
            ->assertSee('admin-tenant-switcher')
            ->assertSee('Productos y precios')
            ->assertSee('admin-inventory-module')
            ->assertSee('admin-inventory-new')
            ->assertSee('admin-inventory-search')
            ->assertSee('admin-inventory-active')
            ->assertSee('admin-inventory-quick-status')
            ->assertSee('admin-inventory-filter-summary')
            ->assertSee('admin-inventory-name')
            ->assertSee('admin-inventory-sku')
            ->assertSee('admin-inventory-tracking-edit')
            ->assertSee('admin-inventory-rate-type')
            ->assertSee('admin-inventory-warranty')
            ->assertSee('admin-inventory-active-edit')
            ->assertSee('admin-inventory-deactivate')
            ->assertSee('admin-inventory-detail')
            ->assertSee('admin-inventory-detail-stock')
            ->assertSee('admin-inventory-detail-prices')
            ->assertSee('admin-inventory-detail-changes')
            ->assertSee('admin-inventory-table')
            ->assertSee('Precios por lista')
            ->assertSee('admin-price-list-rows')
            ->assertSee('admin-price-list-save')
            ->assertSee('admin-price-copy-base')
            ->assertSee('Historial de movimientos')
            ->assertSee('admin-movements-module')
            ->assertSee('admin-movements-search')
            ->assertSee('admin-movements-table')
            ->assertSee('Proveedores')
            ->assertSee('admin-suppliers-module')
            ->assertSee('admin-suppliers-search')
            ->assertSee('admin-suppliers-table')
            ->assertSee('admin-supplier-name')
            ->assertSee('admin-supplier-document-type')
            ->assertSee('admin-supplier-save')
            ->assertSee('admin-supplier-deactivate')
            ->assertSee('Usuarios y permisos')
            ->assertSee('admin-users-module')
            ->assertSee('admin-access-users-table')
            ->assertSee('admin-access-roles-table')
            ->assertSee('admin-access-permissions-grid')
            ->assertSee('data-access-tab="users"', false)
            ->assertSee('data-access-tab="profiles"', false)
            ->assertSee('data-access-tab="permissions"', false)
            ->assertSee('Perfil Cajero')
            ->assertSee('Perfil Inventario')
            ->assertSee('Perfil Gerente');
    }
}
