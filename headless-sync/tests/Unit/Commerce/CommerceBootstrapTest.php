<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Module\ModuleBootstrapState;
use HSP\Core\Module\ModuleLifecycleCoordinator;
use HSP\Core\Projection\ProjectionRegistry;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Reconciliation\ReconciliationSourceRegistry;
use HSP\Core\Replay\ReplayEmitterRegistry;
use HSP\Core\Replay\ReplayService;
use HSP\Tests\Integration\Reconciliation\WriteSpyConnection;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — the zero-configuration upgrade path (DECISION AG AG-12).
 *
 * THE SCENARIO Doc 11 §11 never covered, and the reason AG-12 exists: HSP is already
 * installed, global onboarding is already complete, and THEN WooCommerce is activated on a
 * store whose catalog is already full of products. Nothing in that sequence involves
 * reactivating HSP, so without an explicit lifecycle only FUTURE product edits would ever
 * synchronise and the existing catalog would sit unprojected until reconciliation happened to
 * reach it — if it ever did, since before P2-S1 no Commerce source could even be registered.
 *
 * The three properties asserted here are what make that path safe:
 *
 *   1. A newly ready module becomes bootstrap-PENDING, and only after its migrations applied —
 *      never while its schema might be missing.
 *   2. The bootstrap repairs by RE-EMISSION ONLY. A write-spy proves zero direct projection
 *      writes, so there is no second repair path (DECISION T/U, DECISION W (b)).
 *   3. Global onboarding is never reset, and Content's own state is untouched throughout.
 */
final class CommerceBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_options'] = [];
    }

    private function coordinator(bool $migrationsApplied): ModuleLifecycleCoordinator
    {
        return new ModuleLifecycleCoordinator(
            new ModuleBootstrapState(),
            static fn (string $m): bool => $migrationsApplied,
            static fn (string $m, string $v) => null,
        );
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * The upgrade case end to end at the lifecycle level: an already-onboarded site where
     * Commerce has only just become ready still owes a bootstrap.
     */
    public function testCommerceBecomingReadyOnAnAlreadyOnboardedSiteOwesABootstrap(): void
    {
        // The site finished global onboarding long ago, with Content only.
        $GLOBALS['_hsp_stub_options']['hsp_onboarding_state'] = 'complete';
        (new ModuleBootstrapState())->markComplete('content');

        $status = $this->coordinator(migrationsApplied: true)->evaluate('commerce', '1.0.0');

        self::assertTrue($status->ready);
        self::assertTrue($status->needsBootstrap(), 'the existing catalog has never been projected');

        // Global onboarding is NOT reset — Content's API and Operations stay online.
        self::assertSame('complete', $GLOBALS['_hsp_stub_options']['hsp_onboarding_state']);
        self::assertSame(ModuleBootstrapState::COMPLETE, (new ModuleBootstrapState())->get('content'));
    }

    /**
     * Availability is not readiness: WooCommerce present but Commerce migrations not applied
     * must NOT schedule a bootfill against schema that does not exist.
     */
    public function testAnUnmigratedCommerceDoesNotOweABootstrapYet(): void
    {
        $status = $this->coordinator(migrationsApplied: false)->evaluate('commerce', '1.0.0');

        self::assertFalse($status->ready);
        self::assertFalse($status->needsBootstrap());
    }

    public function testConvergenceCompletesTheModuleWithoutTouchingSiblings(): void
    {
        (new ModuleBootstrapState())->markComplete('content');

        $coordinator = $this->coordinator(migrationsApplied: true);
        $coordinator->evaluate('commerce', '1.0.0');
        $coordinator->markBootstrapComplete('commerce');

        $state = new ModuleBootstrapState();
        self::assertSame(ModuleBootstrapState::COMPLETE, $state->get('commerce'));
        self::assertSame(ModuleBootstrapState::COMPLETE, $state->get('content'));
    }

    // -------------------------------------------------------------------------
    // The bootstrap itself — re-emission only
    // -------------------------------------------------------------------------

    /**
     * The write-spy proof (DECISION W (b) / AG-12): a full reconcile over a Commerce source
     * repairs by RE-EMISSION and performs ZERO direct projection writes. A direct WordPress →
     * PostgreSQL copy would be a second repair path, which every ruling since DECISION T
     * forbids.
     */
    public function testBootstrapRepairsByReEmissionWithZeroDirectProjectionWrites(): void
    {
        $emitter = new RecordingCommerceEmitter();
        $spy     = new WriteSpyConnection(new BootstrapReadConnection());

        $service = $this->reconciliation($spy, $emitter);

        $result = $service->reconcile(ReconciliationService::MODE_FULL);

        self::assertSame(
            ['1', '2', '3'],
            $emitter->emitted,
            'every existing product must be re-emitted through the normal pipeline',
        );

        self::assertSame(
            0,
            $spy->executeCount,
            'a bootstrap that writes a projection directly is a second repair path',
        );
    }

    /**
     * The seam AG-2 closed: a Commerce aggregate must be COVERED, not silently skipped. If
     * `product` were missing a projection descriptor the pass would report it uncovered
     * instead of quietly claiming success over an empty catalog.
     */
    public function testTheCommerceAggregateIsCoveredNotSkipped(): void
    {
        $result = $this->reconciliation(
            new WriteSpyConnection(new BootstrapReadConnection()),
            new RecordingCommerceEmitter(),
        )->reconcile(ReconciliationService::MODE_FULL);

        self::assertSame([], $result->uncovered);
        self::assertTrue($result->isComplete());
        self::assertSame(3, $result->scanned);
    }

    public function testAnAggregateWithNoProjectionIsReportedRatherThanSilentlySkipped(): void
    {
        $sources = new ReconciliationSourceRegistry();
        $sources->register(new BootstrapCommerceSource());

        $emitters = new ReplayEmitterRegistry();
        $emitters->register(new RecordingCommerceEmitter());

        $service = new ReconciliationService(
            new WriteSpyConnection(new BootstrapReadConnection()),
            $sources,
            new ReplayService(new BootstrapReadConnection(), $emitters),
            new ProjectionRegistry(), // deliberately EMPTY
        );

        $result = $service->reconcile(ReconciliationService::MODE_FULL);

        self::assertSame(['product'], $result->uncovered);
        self::assertFalse(
            $result->isComplete(),
            'a pass that covered nothing must not read as a clean result',
        );
    }

    private function reconciliation(
        WriteSpyConnection $conn,
        RecordingCommerceEmitter $emitter,
    ): ReconciliationService {
        $sources = new ReconciliationSourceRegistry();
        $sources->register(new BootstrapCommerceSource());

        $emitters = new ReplayEmitterRegistry();
        $emitters->register($emitter);

        $projections = new ProjectionRegistry();
        $projections->register(new ProjectionDescriptor('product', 'commerce.products', 'source_product_id'));

        return new ReconciliationService(
            $conn,
            $sources,
            new ReplayService(new BootstrapReadConnection(), $emitters),
            $projections,
        );
    }
}

/** Three products exist in WordPress; none is projected yet — the upgrade case. */
final class BootstrapCommerceSource implements WpReconciliationSourceInterface
{
    /** @return list<string> */
    public function getSupportedAggregateTypes(): array
    {
        return ['product'];
    }

    /** @return list<string> */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        return $afterId === 0 ? ['1', '2', '3'] : [];
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        return new SourceState(true, true, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        return str_repeat('a', 64);
    }

    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool
    {
        return false;
    }
}

/** Records what was re-emitted instead of writing an outbox row. */
final class RecordingCommerceEmitter implements ReplayEmitterInterface
{
    /** @var list<string> */
    public array $emitted = [];

    /** @return list<string> */
    public function getSupportedAggregateTypes(): array
    {
        return ['product'];
    }

    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface {
        $this->emitted[] = $aggregateId;

        return new FakeCommerceEvent('commerce.product.updated', 'product', $aggregateId);
    }
}

/**
 * Read-only stand-in for the delivery handle.
 *
 * Returns no projection rows, which is exactly the upgrade case: the products exist in
 * WordPress and nothing has been projected yet, so every one is a missed capture the bootstrap
 * must repair.
 */
final class BootstrapReadConnection implements \HSP\Core\Database\DatabaseConnectionInterface
{
    /** @return array<int, array<string,mixed>> */
    public function query(string $sql, array $params = []): array
    {
        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function beginTransaction(): void {}

    public function commit(): void {}

    public function rollback(): void {}
}
