<?php

namespace Tests;

use App\Modules\Auth\Services\CookieIssuer;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Configura el testing para que las cookies viajen en texto plano.
     *
     * Laravel testing normalmente encripta las cookies con el encrypter
     * de la app antes de enviarlas al "servidor" simulado. Eso rompe el
     * flujo con withCookie() porque el server luego intenta des-encriptar
     * un valor que nunca fue encriptado por el cliente real.
     *
     * disableCookieEncryption() = el cookie se envia tal cual lo pasaste.
     * EncryptCookies::except([...]) = el server NO intenta des-encriptarlo.
     *
     * La cookie auth_token es httpOnly (mitigacion XSS) y se transmite
     * en texto plano entre browser y server. En testing, replicamos eso.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->disableCookieEncryption();
        EncryptCookies::except([CookieIssuer::COOKIE_NAME]);
    }
}
