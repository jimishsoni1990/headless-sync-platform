<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Cli;

use HSP\Core\Cli\ReconcileCommand;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconciliationSource;
use HSP\Tests\Unit\Reconciliation\FakeReconConnection;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * ReconcileCommand — WP-CLI surface for reconciliation (DECISION U).
 *
 * Verifies mode routing, the `reconcile` structured-counter emission (DECISION Q) on a real
 * pass, no emission on dry-run, and unknown-mode rejection. No WP-CLI runtime.
 */
final class ReconcileCommandTest extends TestCase
{
    private FakeReconConnection $conn;
    private FakeReconciliationSource $source;
    private FakeReplayEmitter $emitter;
    /** @var list<string> */
    private array $logLines = [];
    private ReconcileCommand $command;

    protected function setUp(): void
    {
        $this->conn    = new FakeReconConnection();
        $this->source  = new FakeReconciliationSource();
        $this->emitter = new FakeReplayEmitter();
        $replay        = new ReplayService(new FakeDbConnection(), [$this->emitter]);
        $service       = new ReconciliationService($this->conn, $this->source, $replay, 500);
        $strategy      = new ReconciliationWorkerStrategy($service);

        $this->logLines = [];
        $logger = new StructuredLogger(function (string $line): void {
            $this->logLines[] = $line;
        });

        $this->command = new ReconcileCommand($strategy, $logger);
    }

    public function testRunDriftEmitsReconcileCounter(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '1', true, new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $this->conn->projectionRows['post:1'] = null; // missed create → 1 repair

        $result = $this->command->run('drift');

        self::assertSame('drift', $result->mode);
        self::assertSame(1, $result->repairedCount());
        self::assertCount(1, $this->logLines);
        self::assertStringContainsString('"event":"reconcile"', $this->logLines[0]);
        self::assertStringContainsString('"reconcile":1', $this->logLines[0]);
    }

    public function testDryRunEmitsNoCounter(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '1', true, new \DateTimeImmutable('2026-07-02T00:00:00Z'));
        $this->conn->projectionRows['post:1'] = null;

        $result = $this->command->run('drift', dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertCount(0, $this->logLines, 'dry run emits no runtime counter');
        self::assertCount(0, $this->emitter->calls, 'dry run does not re-emit');
    }

    public function testFullModeRoutes(): void
    {
        $this->source->withType('post');
        $result = $this->command->run('full');
        self::assertSame('full', $result->mode);
    }

    public function testUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown reconcile mode 'nope'");
        $this->command->run('nope');
    }
}
