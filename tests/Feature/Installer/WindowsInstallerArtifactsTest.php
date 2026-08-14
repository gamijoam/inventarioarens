<?php

namespace Tests\Feature\Installer;

use Tests\TestCase;

class WindowsInstallerArtifactsTest extends TestCase
{
    public function test_windows_installer_sources_exist_and_use_the_local_ports(): void
    {
        $root = base_path();

        foreach ([
            'installer/windows/InventarioArens.iss',
            'installer/windows/cacert.pem',
            'installer/windows/install-local.ps1',
            'installer/windows/repair-existing-installation.ps1',
            'installer/windows/start-inventario.ps1',
            'installer/windows/stop-inventario.ps1',
            'installer/windows/router.php',
            'scripts/sync-worker.cmd',
            'scripts/build-windows-installer.ps1',
        ] as $file) {
            $this->assertFileExists($root.'/'.$file);
        }

        $launcher = file_get_contents($root.'/installer/windows/start-inventario.ps1');
        $this->assertStringContainsString('artisan serve --host=127.0.0.1 --port=8787', $launcher);
        $this->assertStringContainsString('127.0.0.1:5173', $launcher);
        $this->assertStringContainsString('LARAVEL_STORAGE_PATH', $launcher);
        $this->assertStringContainsString('curl.cainfo', $launcher);
        $this->assertStringContainsString('openssl.cafile', $launcher);
        $this->assertStringContainsString('PHP_INI_SCAN_DIR', $launcher);
        $this->assertStringContainsString('api/bootstrap/status', $launcher);

        $installer = file_get_contents($root.'/installer/windows/install-local.ps1');
        $this->assertStringContainsString('LARAVEL_STORAGE_PATH', $installer);
        $this->assertStringContainsString('Grant-LocalDataAccess', $installer);
        $this->assertStringContainsString('pdo_sqlite', $installer);
        $this->assertStringContainsString('cacert.pem', $installer);
        $this->assertStringContainsString('curl.cainfo', $installer);
        $this->assertStringContainsString('99-inventarioarens-https.ini', $installer);
        $this->assertStringContainsString('APP_KEY no se genero correctamente', $installer);
        $this->assertStringContainsString('SQLite quedo vacio', $installer);
        $this->assertStringContainsString("'APP_ENV=.*' = 'APP_ENV=local'", $installer);
        $this->assertStringContainsString("'SESSION_SECURE_COOKIE=.*' = 'SESSION_SECURE_COOKIE=false'", $installer);

        $serviceInstaller = file_get_contents($root.'/scripts/install-backend-service.ps1');
        foreach ([
            'storage\\framework\\cache',
            'storage\\framework\\data',
            'storage\\framework\\sessions',
            'storage\\framework\\testing',
            'storage\\framework\\views',
            'storage\\logs',
        ] as $storageDirectory) {
            $this->assertStringContainsString($storageDirectory, $serviceInstaller);
        }

        $client = file_get_contents($root.'/frontend/src/api/client.ts');
        $this->assertStringContainsString(':8787/api', $client);

        $inno = file_get_contents($root.'/installer/windows/InventarioArens.iss');
        $this->assertStringContainsString('users-modify', $inno);
        $this->assertStringContainsString('{sys}\\WindowsPowerShell\\v1.0\\powershell.exe', $inno);

        $workerLauncher = file_get_contents($root.'/scripts/sync-worker.cmd');
        $this->assertStringContainsString('%SystemRoot%\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', $workerLauncher);

        $repair = file_get_contents($root.'/installer/windows/repair-existing-installation.ps1');
        $this->assertStringContainsString('Ejecuta esta reparacion como administrador', $repair);
        $this->assertStringContainsString('env-before-repair', $repair);
        $this->assertStringContainsString('repair-installed.log', $repair);
        $this->assertStringContainsString('scripts\\sync-worker.cmd', $repair);
        $this->assertStringContainsString('scripts\\sync-worker.ps1', $repair);

        $bootstrap = file_get_contents($root.'/bootstrap/app.php');
        $this->assertStringContainsString('LARAVEL_STORAGE_PATH', $bootstrap);
        $this->assertStringContainsString("getenv('LARAVEL_STORAGE_PATH')", $bootstrap);
    }
}
