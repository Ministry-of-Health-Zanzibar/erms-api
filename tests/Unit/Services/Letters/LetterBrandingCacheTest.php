<?php

namespace Tests\Unit\Services\Letters;

use App\Services\Letters\LetterBrandingService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LetterBrandingCacheTest extends TestCase
{
    private string $assetDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        // No application database or uploaded branding files are used.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        Cache::flush();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Needs GD to create isolated branding fixtures.');
        }
        $this->assetDirectory = sys_get_temp_dir() . '/eris-letter-assets-' . bin2hex(random_bytes(6));
        (new Filesystem())->makeDirectory($this->assetDirectory . '/images/letters', 0755, true);
        $this->app->usePublicPath($this->assetDirectory);
        $this->writeImage('sign_dgs.png', [0, 15, 222]);
        $this->writeImage('stamp.png', [0, 15, 222]);
    }

    protected function tearDown(): void
    {
        if (isset($this->assetDirectory)) {
            (new Filesystem())->deleteDirectory($this->assetDirectory);
        }
        parent::tearDown();
    }

    private function writeImage(string $filename, array $ink): void
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetpixel($image, 1, 1, imagecolorallocate($image, ...$ink));
        imagepng($image, $this->assetDirectory . '/images/letters/' . $filename);
        imagedestroy($image);
    }

    private function cacheEntries(): array
    {
        $storage = (new \ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore());
        return array_filter($storage, fn ($key) => str_starts_with($key, 'letter-branding:'), ARRAY_FILTER_USE_KEY);
    }

    public function test_prepared_signature_and_stamp_are_reused_without_changing_their_appearance(): void
    {
        $service = new LetterBrandingService();
        $first = $service->effectiveAssets();
        $this->assertSame($first, $service->effectiveAssets());
        $this->assertCount(1, $this->cacheEntries());
        $this->assertStringStartsWith('data:image/png;base64,', $first['stampData']);
        $stamp = imagecreatefromstring(base64_decode(substr($first['stampData'], strlen('data:image/png;base64,'))));
        $this->assertSame(127, (imagecolorat($stamp, 0, 0) >> 24) & 127);
        $this->assertSame(222, imagecolorat($stamp, 1, 1) & 255);
        imagedestroy($stamp);
    }

    public function test_same_path_and_timestamp_replacement_invalidates_branding_immediately(): void
    {
        $service = new LetterBrandingService();
        $first = $service->effectiveAssets();
        $path = $this->assetDirectory . '/images/letters/sign_dgs.png';
        $mtime = filemtime($path);
        $this->writeImage('sign_dgs.png', [80, 30, 150]);
        touch($path, $mtime);
        $changed = $service->effectiveAssets();
        $this->assertNotSame($first['signatureData'], $changed['signatureData']);
        $this->assertNotSame($first['stampData'], $changed['stampData']);
        $this->assertCount(2, $this->cacheEntries());
        $this->assertSame($changed, $service->effectiveAssets());
    }
}
