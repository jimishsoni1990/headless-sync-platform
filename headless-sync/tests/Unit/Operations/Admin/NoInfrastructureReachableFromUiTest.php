<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Admin;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Admin\AdminPageController;
use HSP\Core\Operations\Admin\ConsoleAdminRegistrar;
use HSP\Core\Operations\Admin\ConsoleAjaxController;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Providers\HealthProvider;
use HSP\Core\Operations\Providers\MetricsProvider;
use HSP\Core\Operations\Providers\QueueStatusProvider;
use HSP\Core\Operations\Providers\WorkerStatusProvider;
use HSP\Core\Operations\UI\DashboardView;
use HSP\Core\Operations\UI\PlaygroundView;
use PHPUnit\Framework\TestCase;

/**
 * ADR-053 guard: no infrastructure class is reachable from the console UI code.
 *
 * The DoD requires the UI to read exclusively through OperationsService (the single seam) and
 * the two directly-queried diagnostics services. This test reflects over the constructors of
 * every UI/Admin class and asserts NONE declares a parameter typed as a DatabaseConnectionInterface,
 * the OperationsQueryReader, or any concrete OPSC-S2 provider. If a future edit wires a
 * connection/reader/provider straight into a UI class, this test fails — the ADR-053 boundary
 * is mechanically enforced, not just documented.
 */
final class NoInfrastructureReachableFromUiTest extends TestCase
{
    /** Types a UI/Admin class must never receive by constructor (ADR-053). */
    private const FORBIDDEN = [
        DatabaseConnectionInterface::class,
        OperationsQueryReader::class,
        HealthProvider::class,
        MetricsProvider::class,
        QueueStatusProvider::class,
        WorkerStatusProvider::class,
    ];

    /** Every class that constitutes the OPSC-S3 UI surface. */
    private const UI_CLASSES = [
        AdminPageController::class,
        ConsoleAjaxController::class,
        ConsoleAdminRegistrar::class,
        DashboardView::class,
        PlaygroundView::class,
    ];

    #[\PHPUnit\Framework\Attributes\DataProvider('uiClasses')]
    public function test_ui_class_constructor_receives_no_infrastructure(string $class): void
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();

        if ($ctor === null) {
            $this->addToAssertionCount(1);

            return;
        }

        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            self::assertNotContains(
                $type->getName(),
                self::FORBIDDEN,
                sprintf(
                    'UI class %s must not receive infrastructure type %s (ADR-053).',
                    $class,
                    $type->getName(),
                ),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uiClasses(): iterable
    {
        foreach (self::UI_CLASSES as $class) {
            yield $class => [$class];
        }
    }
}
