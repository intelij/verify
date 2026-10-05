<?php

namespace Tests;

use App\Models\Consumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected string $consumerToken;
    protected Consumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        [$this->consumer, $this->consumerToken] = Consumer::provision('Test', 'test');
    }

    protected function apiHeaders(array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $this->consumerToken,
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * Build a minimal PNG document fixture (synthetic — never a real ID).
     */
    protected function fakePngDocument(): string
    {
        $im = imagecreatetruecolor(800, 500);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $tmp = tempnam(sys_get_temp_dir(), 'testdoc') . '.png';
        imagepng($im, $tmp);

        return 'data:image/png;base64,' . base64_encode(file_get_contents($tmp));
    }
}
