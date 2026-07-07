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
            ->assertSee('admin-inventory-search')
            ->assertSee('admin-inventory-active')
            ->assertSee('admin-inventory-active-edit')
            ->assertSee('admin-inventory-table')
            ->assertSee('Precios por lista')
            ->assertSee('admin-price-list-rows')
            ->assertSee('admin-price-list-save')
            ->assertSee('admin-price-copy-base')
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
