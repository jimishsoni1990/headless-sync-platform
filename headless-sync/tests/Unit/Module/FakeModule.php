<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\ServiceProviderInterface;

/**
 * Test double that implements the full ModuleInterface union (OPEN-9 v1.4).
 *
 * All lifecycle calls are recorded in $calls in invocation order so tests
 * can assert exact ordering across multiple modules.
 */
final class FakeModule implements ModuleInterface
{
    /** @var string[] Chronological record of lifecycle calls on ALL FakeModule instances. */
    public static array $globalCallLog = [];

    /** @var string[] Lifecycle calls on this specific instance. */
    public array $calls = [];

    public function __construct(private readonly string $moduleName = 'fake') {}

    public function getName(): string
    {
        return $this->moduleName;
    }

    public function getServiceProvider(): ServiceProviderInterface
    {
        return new class implements ServiceProviderInterface {
            public function register(object $container): void {}
            public function boot(object $container): void {}
        };
    }

    public function getMigrations(): array
    {
        return [];
    }

    public function getEventTypes(): array
    {
        return [];
    }

    public function register(): void
    {
        $this->calls[]              = 'register';
        self::$globalCallLog[]      = $this->moduleName . ':register';
    }

    public function boot(): void
    {
        $this->calls[]              = 'boot';
        self::$globalCallLog[]      = $this->moduleName . ':boot';
    }

    public function activate(): void
    {
        $this->calls[]              = 'activate';
        self::$globalCallLog[]      = $this->moduleName . ':activate';
    }

    public function deactivate(): void
    {
        $this->calls[]              = 'deactivate';
        self::$globalCallLog[]      = $this->moduleName . ':deactivate';
    }

    public function upgrade(): void
    {
        $this->calls[]              = 'upgrade';
        self::$globalCallLog[]      = $this->moduleName . ':upgrade';
    }
}
