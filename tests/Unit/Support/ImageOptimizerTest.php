<?php

namespace Tests\Unit\Support;

use App\Support\Media\ImageOptimizer;
use Tests\TestCase;

class ImageOptimizerTest extends TestCase
{
    public function test_optimize_returns_false_for_non_existent_file(): void
    {
        $result = ImageOptimizer::optimize('/non/existent/image.jpg');
        $this->assertFalse($result);
    }

    public function test_optimize_compresses_real_image(): void
    {
        $tmpDir = sys_get_temp_dir();
        $testImgPath = $tmpDir.'/test_opt_'.uniqid().'.jpg';

        // Create a test 1600x1200 dummy image using GD if available, or raw bytes
        $im = imagecreatetruecolor(1600, 1200);
        $red = imagecolorallocate($im, 220, 50, 50);
        imagefilledrectangle($im, 0, 0, 1600, 1200, $red);
        imagejpeg($im, $testImgPath, 100);
        imagedestroy($im);

        $initialSize = filesize($testImgPath);

        $success = ImageOptimizer::optimize($testImgPath, 800, 75);
        $this->assertTrue($success);

        $finalSize = filesize($testImgPath);
        $this->assertLessThan($initialSize, $finalSize);

        // Verify dimensions were resized to 800x600
        [$width, $height] = getimagesize($testImgPath);
        $this->assertEquals(800, $width);
        $this->assertEquals(600, $height);

        @unlink($testImgPath);
    }
}
