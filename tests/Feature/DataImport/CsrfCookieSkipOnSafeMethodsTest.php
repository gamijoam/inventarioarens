<?php

namespace Tests\Feature\DataImport;

use App\Models\User;
use App\Modules\Auth\Services\CookieIssuer;
use App\Modules\DataImport\Models\DataImport;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifica que el middleware de auth aplica proteccion CSRF solo a metodos
 * que mutan estado (POST/PUT/PATCH/DELETE) cuando el token llega por cookie.
 * Los GET / HEAD / OPTIONS autenticados por cookie deben pasar sin requerir
 * X-Requested-With (caso real: link <a download> o window.open a
 * /api/import/templates).
 *
 * Ver tambien docs/DATA_IMPORT_API.md seccion "Wizard por entidad".
 */
class CsrfCookieSkipOnSafeMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('data_import.view', 'web');
        Permission::findOrCreate('data_import.create', 'web');

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.test',
            'password' => bcrypt('secret123'),
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['status' => 'active']);
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->admin->givePermissionTo(['data_import.view', 'data_import.create']);
    }

    private function loginToken(): string
    {
        return $this
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/login', [
                'email' => $this->admin->email,
                'password' => 'secret123',
            ])
            ->assertCreated()
            ->json('data.token');
    }

    private function makeSession(): DataImport
    {
        app(TenantManager::class)->set($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        return DataImport::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'status' => 'pending',
        ]);
    }

    public function test_get_template_with_cookie_auth_works_without_x_requested_with(): void
    {
        $token = $this->loginToken();

        // Simulamos una navegacion directa del browser (link <a download>
        // o window.open): solo cookie, sin X-Requested-With, sin Origin.
        $response = $this
            ->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get('/api/import/templates/branches');

        // CSRF NO debe aplicar a GET. Esperado 200 con el CSV de la plantilla.
        $response->assertOk();
        $this->assertStringStartsWith('code,name,status', $response->getContent());
    }

    public function test_get_report_with_cookie_auth_works_without_x_requested_with(): void
    {
        $token = $this->loginToken();
        $session = $this->makeSession();

        $response = $this
            ->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->get("/api/import/sessions/{$session->id}/report");

        $response->assertOk();
        $this->assertStringContainsString('fila,entidad,estado', $response->getContent());
    }

    public function test_post_upload_with_cookie_still_requires_csrf(): void
    {
        $token = $this->loginToken();
        $session = $this->makeSession();
        $csv = new UploadedFile(tempnam(sys_get_temp_dir(), 'csv'), 'test.csv', 'text/csv', null, true);

        // POST sin X-Requested-With debe ser rechazado por CSRF.
        $response = $this
            ->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->call('POST', "/api/import/sessions/{$session->id}/entities/branches/upload", [], [
                CookieIssuer::COOKIE_NAME => $token,
            ], [], [
                'HTTP_X_TENANT' => $this->tenant->slug,
            ]);

        $response->assertForbidden();
        $this->assertStringContainsString('CSRF', $response->json('message') ?? '');
    }

    public function test_post_upload_with_x_requested_with_passes_csrf(): void
    {
        $token = $this->loginToken();
        $session = $this->makeSession();
        $csv = new UploadedFile(tempnam(sys_get_temp_dir(), 'csv'), 't.csv', 'text/csv', null, true);
        file_put_contents($csv->getPathname(), "code,name\nTEST,Test\n");

        // El mismo request SIN X-Requested-With arriba fue rechazado por CSRF.
        // Aqui lo mandamos CON X-Requested-With y verificamos que el rechazo
        // (si lo hay) NO mencione CSRF. Cualquier otro 4xx/5xx es OK: lo
        // importante es que el CSRF ya paso.
        $response = $this
            ->withCredentials()
            ->withCookie(CookieIssuer::COOKIE_NAME, $token)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withHeader('Origin', config('app.url'))
            ->post("/api/import/sessions/{$session->id}/entities/branches/upload", [
                'file' => $csv,
            ]);

        $message = (string) ($response->json('message') ?? '');
        $this->assertStringNotContainsString(
            'CSRF',
            $message,
            "CSRF debe pasar con X-Requested-With presente. Got status={$response->status()} message={$message}"
        );
    }
}
