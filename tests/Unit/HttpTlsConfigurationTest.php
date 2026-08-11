<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica que AppServiceProvider configure TLS para los clientes HTTP
 * (Guzzle vía Laravel Http) usando el cacert del runtime, de modo que los
 * requests HTTPS contra la nube funcionen aunque el proceso PHP arranque sin
 * PHP_INI_SCAN_DIR (bug: cURL error 77/60 -> el binario de las imagenes
 * nunca se publicaba a la nube).
 *
 * Nota: curl.cainfo es PHP_INI_SYSTEM y no se puede cambiar con ini_set en
 * runtime; el mecanismo real de fix es Http::globalOptions(['verify' => $path]).
 */
class HttpTlsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_registers_global_verify_option_with_cacert(): void
    {
        $tmpCert = tempnam(sys_get_temp_dir(), 'cacert_');
        file_put_contents($tmpCert, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        config(['services.sync.tls_cacert' => $tmpCert]);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $globalOptions = $this->readGlobalOptions();
        $this->assertSame($tmpCert, $globalOptions['verify'] ?? null);

        @unlink($tmpCert);
    }

    public function test_boot_is_noop_when_no_cacert_exists(): void
    {
        config(['services.sync.tls_cacert' => '/non/existent/cacert.pem']);

        $provider = new AppServiceProvider($this->app);
        $provider->boot();

        $globalOptions = $this->readGlobalOptions();
        $this->assertArrayNotHasKey('verify', $globalOptions);
    }

    /**
     * Lee las opciones globales registradas en el cliente HTTP de Laravel.
     * Se accede via reflection a la property $globalOptions del Factory.
     */
    private function readGlobalOptions(): array
    {
        $factory = Http::getFacadeRoot();

        $property = new \ReflectionProperty($factory, 'globalOptions');
        $property->setAccessible(true);

        return $property->getValue($factory);
    }
}
