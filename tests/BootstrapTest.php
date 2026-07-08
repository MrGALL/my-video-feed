<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Bootstrap;
use App\Cli;
use PHPUnit\Framework\TestCase;

/** Config is validated up front: required keys must be present, and absent optional keys default silently. */
final class BootstrapTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    public function testThrowsWhenRequiredBaseUrlIsMissing(): void
    {
        $path = $this->writeConfig(['db' => ['driver' => 'sqlite', 'path' => ':memory:']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('base_url');
        Bootstrap::build($path);
    }

    public function testThrowsWhenRequiredDbDriverIsMissing(): void
    {
        $path = $this->writeConfig(['base_url' => 'https://example.com/', 'db' => ['path' => ':memory:']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db.driver');
        Bootstrap::build($path);
    }

    public function testThrowsWhenConfigDoesNotReturnAnArray(): void
    {
        $path = $this->writeRaw('<?php return 42;');

        $this->expectException(\RuntimeException::class);
        Bootstrap::build($path);
    }

    public function testBuildsFromMinimalConfigApplyingOptionalDefaults(): void
    {
        // Only the two required keys are present; every optional section must default without error.
        $path = $this->writeConfig(['base_url' => 'https://example.com/', 'db' => ['driver' => 'sqlite', 'path' => ':memory:']]);

        $boot = Bootstrap::build($path);

        $this->assertInstanceOf(App::class, $boot->app);
        $this->assertInstanceOf(Cli::class, $boot->cli);
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): string
    {
        return $this->writeRaw('<?php return ' . var_export($config, true) . ';');
    }

    private function writeRaw(string $php): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mvfcfg_') . '.php';
        file_put_contents($path, $php);
        $this->tmpFiles[] = $path;
        return $path;
    }
}
