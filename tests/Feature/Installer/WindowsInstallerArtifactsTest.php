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
            'installer/windows/install-local.ps1',
            'installer/windows/start-inventario.ps1',
            'installer/windows/stop-inventario.ps1',
            'installer/windows/router.php',
            'scripts/build-windows-installer.ps1',
        ] as $file) {
            $this->assertFileExists($root.'/'.$file);
        }

        $launcher = file_get_contents($root.'/installer/windows/start-inventario.ps1');
        $this->assertStringContainsString('127.0.0.1", "--port=8787', $launcher);
        $this->assertStringContainsString('127.0.0.1:5173', $launcher);
    }
}
