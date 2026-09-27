<?php

namespace Tests\Unit\Support;

use App\Support\Barcode\BarcodeEngine;
use Tests\TestCase;

class BarcodeEngineTest extends TestCase
{
    public function test_generate_code128_creates_valid_png(): void
    {
        $tmpDir = sys_get_temp_dir();
        $outFile = $tmpDir.'/test_bc_'.uniqid().'.png';

        $result = BarcodeEngine::generateCode128('PROD-7788', $outFile, 300, 100);
        $this->assertNotEmpty($result);
        $this->assertFileExists($outFile);

        [$w, $h] = getimagesize($outFile);
        $this->assertEquals(300, $w);
        $this->assertEquals(100, $h);

        @unlink($outFile);
    }

    public function test_generate_qr_creates_valid_png(): void
    {
        $tmpDir = sys_get_temp_dir();
        $outFile = $tmpDir.'/test_qr_'.uniqid().'.png';

        $result = BarcodeEngine::generateQR('https://app.balanzapro.com', $outFile, 150);
        $this->assertNotEmpty($result);
        $this->assertFileExists($outFile);

        [$w, $h] = getimagesize($outFile);
        $this->assertEquals(150, $w);
        $this->assertEquals(150, $h);

        @unlink($outFile);
    }
}
