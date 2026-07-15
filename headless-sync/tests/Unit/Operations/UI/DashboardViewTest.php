<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\UI;

use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\ModuleInspection;
use HSP\Core\Contracts\Operations\QueueStatus;
use HSP\Core\Contracts\Operations\Severity;
use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Operations\Diagnostics\SystemInformation;
use HSP\Core\Operations\UI\DashboardView;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DashboardView — a PURE server-side renderer over resolved snapshots/DTOs.
 *
 * The renderer never receives infrastructure (no DatabaseConnectionInterface, no reader, no
 * concrete provider — ADR-053); it takes the same snapshot shapes the RefreshCoordinator
 * emits and returns escaped HTML. These tests assert content is rendered for each snapshot
 * shape and that hostile values are escaped at output (DECISION V (b)).
 */
final class DashboardViewTest extends TestCase
{
    private DashboardView $view;

    protected function setUp(): void
    {
        $this->view = new DashboardView();
    }

    public function test_renders_a_widget_per_snapshot_shape(): void
    {
        $widgets = [
            new ConsoleWidget('queue', 'Queue', 'operations', 'queue', 10),
            new ConsoleWidget('health', 'Health', 'operations', 'health', 20),
            new ConsoleWidget('metrics', 'Metrics', 'operations', 'metrics', 30),
            new ConsoleWidget('workers', 'Workers', 'operations', 'workers', 40),
        ];

        $snapshots = [
            'queue'   => new QueueStatus(7, 2, new \DateInterval('PT90S')),
            'health'  => [new HealthReport('database', Severity::OK, 'reachable')],
            'metrics' => [new MetricSample('queue_depth', 7, 'jobs')],
            'workers' => [new WorkerStatus('w-1', 'event', true, new \DateTimeImmutable('2026-07-15 10:00:00'))],
        ];

        $html = $this->view->render($widgets, $snapshots, null, []);

        // Queue widget
        self::assertStringContainsString('Queue depth', $html);
        self::assertStringContainsString('7', $html);
        self::assertStringContainsString('90s', $html);
        // Health widget
        self::assertStringContainsString('database', $html);
        self::assertStringContainsString('OK', $html);
        // Metrics widget
        self::assertStringContainsString('queue_depth', $html);
        // Workers widget
        self::assertStringContainsString('w-1', $html);
        self::assertStringContainsString('online', $html);
        // Each widget titled + tagged with its provider key
        self::assertStringContainsString('data-provider="workers"', $html);
    }

    public function test_escapes_hostile_values_at_output(): void
    {
        $widgets   = [new ConsoleWidget('workers', 'Workers', 'operations', 'workers', 10)];
        $snapshots = [
            'workers' => [new WorkerStatus('<script>x</script>', 'event', false, null)],
        ];

        $html = $this->view->render($widgets, $snapshots, null, []);

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_renders_system_information_and_module_inspector(): void
    {
        $system = new SystemInformation(
            platformVersion: '0.1.0',
            phpVersion: '8.3.0',
            wordpressVersion: '6.5',
            postgresVersion: '16.2',
            queueProvider: 'database',
            appliedMigrationCount: 12,
            latestMigration: '0012_x',
            moduleVersions: ['content' => '1.0.0'],
        );

        $modules = [
            new ModuleInspection(
                name: 'content',
                version: '1.0.0',
                eventTypes: ['content.post.updated'],
                endpoints: ['/posts', '/posts/{slug}'],
                transformers: ['PostTransformer'],
                adapters: ['PostAdapter'],
                providerKeys: ['content.metrics'],
            ),
        ];

        $html = $this->view->render([], [], $system, $modules);

        self::assertStringContainsString('System Information', $html);
        self::assertStringContainsString('0.1.0', $html);
        self::assertStringContainsString('16.2', $html);
        self::assertStringContainsString('Module Inspector', $html);
        self::assertStringContainsString('content', $html);
        self::assertStringContainsString('content.metrics', $html);
    }

    public function test_empty_snapshot_renders_a_placeholder_not_a_fatal(): void
    {
        $widgets   = [new ConsoleWidget('metrics', 'Metrics', 'operations', 'metrics', 10)];
        $html      = $this->view->render($widgets, ['metrics' => []], null, []);

        self::assertStringContainsString('Metrics', $html);
        self::assertStringContainsString('No data', $html);
    }
}
