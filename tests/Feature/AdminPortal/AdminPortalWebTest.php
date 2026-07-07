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
            ->assertSee('Productos y precios')
            ->assertSee('admin-inventory-module')
            ->assertSee('admin-inventory-search')
            ->assertSee('admin-inventory-table');
    }
}
