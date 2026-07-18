<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Onboarding\Backfill\BackfillBlockedException;
use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillService;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;

/**
 * REST endpoints the React onboarding app calls (ONB-S1b/S2; DECISION W (a)/(b)/(c)/(d)/(f);
 * DECISION V (b)).
 *
 * The React client is UNTRUSTED, so WPCS security applies at this JSON boundary (DECISION W (a)
 * extends DECISION V (b) to the endpoints the React app calls): every endpoint verifies the wp
 * REST nonce, checks the operator capability, and sanitizes input before use.
 *
 * Endpoints (registered under hsp/v1 by {@see OnboardingRestRegistrar}):
 *   GET  onboarding/preflight        — run the four hard-blocking environment checks (DECISION W
 *                                       (f), amended v1.22) and return them plus the current
 *                                       onboarding state. Read-only.
 *   POST onboarding/complete         — flip `hsp_onboarding_state` to COMPLETE (guarded on
 *                                       preflight). Retained from ONB-S1b; NOT used by the ONB-S2
 *                                       UI (completion is convergence-driven). Writes only the WP
 *                                       option (no schema change, no PG write).
 *   POST onboarding/migrate          — ONB-S2 self-remediation (DECISION W (e)/(f) v1.23). Apply the
 *                                       outstanding core + content migrations through the EXISTING
 *                                       engine (thin delegation to MigrationApplier). GUARDED on the
 *                                       four environment preflight checks passing; a failing env
 *                                       preflight returns 409 (the migrate action needs a reachable,
 *                                       correctly-configured PG). Re-evaluates the migrations gate
 *                                       after and returns it. Zero-config first-run action (Principle
 *                                       8) — the gate keeps its hard block, it just gains an action.
 *   POST onboarding/spawn-worker     — ONB-S2 self-remediation (DECISION W (c); DECISION X (4)). Issue
 *                                       a NON-BLOCKING WP-Cron spawn so a processing cycle runs and a
 *                                       heartbeat appears (thin delegation to WorkerCronSpawner). NO
 *                                       in-request drain — the cycle runs only inside WP-Cron
 *                                       execution. Warns when DISABLE_WP_CRON is set. Returns the
 *                                       refreshed backfill gate so the client can re-check.
 *   POST onboarding/backfill         — ONB-S2 (DECISION W (b)/(c)). Trigger the first-run backfill:
 *                                       a thin delegation to BackfillService → reconcileFull()
 *                                       re-emission. GUARDED on the backfill gate (live worker
 *                                       heartbeat + applied migrations); a blocked gate returns 409
 *                                       with per-gate remediation. Writes NO projection (re-emission
 *                                       only — the worker pipeline projects).
 *   GET  onboarding/backfill/progress — ONB-S2 (DECISION W (d)). Derived-on-demand progress +
 *                                       gate status. On CONVERGENCE it flips
 *                                       `hsp_onboarding_state` → COMPLETE (the console un-gates)
 *                                       and reports the redirect target. Read-derived; the only
 *                                       write is the single WP-option completion flag.
 *
 * Delegate-only (DECISION W (e)): the controller holds the runner, state, backfill service and
 * progress deriver — it opens no PG handle (all DB reads reuse the delivery handle via the reader/
 * probe) and touches no schema. Constructor injection only (ADR-012 / Rule 7).
 */
final class OnboardingRestController
{
    /** Capability required for every onboarding endpoint. */
    private const CAPABILITY = 'manage_options';

    /** Nonce action WordPress uses for REST requests (matches the localized `wp_rest` nonce). */
    private const NONCE_ACTION = 'wp_rest';

    public function __construct(
        private readonly PreflightRunner $preflight,
        private readonly OnboardingStateInterface $state,
        private readonly BackfillService $backfill,
        private readonly BackfillProgress $progress,
        private readonly MigrationApplier $migrationApplier,
        private readonly MigrationsAppliedCheck $migrationsCheck,
        private readonly WorkerCronSpawner $workerSpawner,
    ) {}

    /**
     * GET onboarding/preflight — return the preflight summary + current onboarding state.
     */
    public function handlePreflight(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        $summary = $this->preflight->summary();

        return rest_ensure_response([
            'state'  => $this->state->current(),
            'ok'     => $summary['ok'],
            'checks' => $summary['checks'],
        ]);
    }

    /**
     * POST onboarding/complete — mark onboarding complete (guarded on preflight passing).
     *
     * This is the completion-flag round-trip (DECISION W (d)); it writes only the WP option. It
     * is NOT the backfill trigger (ONB-S2) — no content re-emission, no PG write happens here.
     */
    public function handleComplete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        if (! $this->preflight->allPassed()) {
            return new \WP_Error(
                'hsp_preflight_failed',
                __('Onboarding prerequisites are not met. Resolve all preflight checks first.', 'headless-sync'),
                ['status' => 409]
            );
        }

        $this->state->markComplete();

        return rest_ensure_response([
            'state' => $this->state->current(),
            'ok'    => true,
        ]);
    }

    /**
     * POST onboarding/migrate — apply the outstanding core + content migrations (ONB-S2
     * self-remediation; DECISION W (e) delegate list; DECISION W (f) v1.23; ADR-054 Principle 8).
     *
     * The in-product action behind the "migrations applied" backfill gate: a thin delegation to
     * MigrationApplier, which runs the EXISTING migration engine over the frozen core + content
     * migrations. GUARDED on the four environment preflight checks (pgsql ext, PG constants, PG
     * reachable, PHP version) — the migrate action needs a reachable, correctly-configured PG, and a
     * failing env preflight returns 409 with the failing checks so the operator fixes the host first.
     * After running, the migrations gate is re-evaluated and returned. Idempotent: a re-run applies
     * nothing new (the engine skips already-applied migrations). The gate KEEPS its hard block — it
     * gains an action, not a bypass; nothing projects until migrations are actually applied.
     */
    public function handleMigrate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        // Already-complete onboarding short-circuits (idempotent — nothing to migrate).
        if ($this->state->isComplete()) {
            return rest_ensure_response([
                'state'    => $this->state->current(),
                'ran'      => false,
                'complete' => true,
                'gate'     => $this->migrationsCheck->run()->toArray(),
            ]);
        }

        // The migrate action requires a reachable, correctly-configured PostgreSQL — that is exactly
        // the four environment preflight checks (DECISION W (f) v1.22). Block until they pass.
        $preflight = $this->preflight->summary();
        if ($preflight['ok'] !== true) {
            return new \WP_Error(
                'hsp_preflight_failed',
                __('Cannot apply migrations until the environment prerequisites pass.', 'headless-sync'),
                ['status' => 409, 'preflight' => $preflight],
            );
        }

        $result = $this->migrationApplier->apply();

        // A caught engine/connection failure is a blocked action with remediation, never a 500.
        if (! $result->ran) {
            return new \WP_Error(
                'hsp_migrate_failed',
                __('The migration engine could not apply the outstanding migrations.', 'headless-sync'),
                ['status' => 409, 'error' => $result->error, 'gate' => $this->migrationsCheck->run()->toArray()],
            );
        }

        return rest_ensure_response([
            'state' => $this->state->current(),
            'ran'   => true,
            'total' => $result->total,
            // Re-evaluate the migrations gate so the client sees it flip to passed.
            'gate'  => $this->migrationsCheck->run()->toArray(),
        ]);
    }

    /**
     * POST onboarding/spawn-worker — nudge WP-Cron so a processing cycle runs and a heartbeat appears
     * (ONB-S2 self-remediation; DECISION W (c); DECISION X (4) Option-C; ADR-054 §5/Principle 8).
     *
     * Thin delegation to WorkerCronSpawner: ensure the processing cron is scheduled and issue a
     * NON-BLOCKING WP-Cron spawn. NO in-request drain — the worker engine never runs inline here; the
     * cycle runs only inside WP-Cron execution (DECISION W (c)). Warns when DISABLE_WP_CRON is set
     * (WP-Cron will not self-fire). Returns the refreshed backfill gate so the client re-checks
     * readiness. Never a Restart Workers action / no supervisor wording (DECISION V (f); ADR-054 §5).
     */
    public function handleSpawnWorker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        $spawn = $this->workerSpawner->spawn();

        return rest_ensure_response([
            'state' => $this->state->current(),
            'spawn' => $spawn->toArray(),
            // The gate won't pass immediately (the spawned cycle runs asynchronously); the client
            // re-polls progress until a heartbeat appears. Returning it now surfaces the live status.
            'gate'  => $this->backfillGateSummary(),
        ]);
    }

    /**
     * POST onboarding/backfill — trigger the first-run content backfill (ONB-S2; DECISION W (b)/(c)).
     *
     * Thin delegation to BackfillService, which re-emits the in-scope corpus via
     * reconcileFull() through the normal pipeline (no direct WP→PG copy, no projection write here).
     * GUARDED on the backfill gate: an unmet hard prerequisite (no live worker heartbeat / required
     * migrations not applied) returns 409 carrying the per-gate remediation — no Restart Workers
     * action (DECISION V (f)). Already-complete onboarding short-circuits (idempotent).
     */
    public function handleBackfill(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        if ($this->state->isComplete()) {
            // Idempotent: onboarding already finished — nothing to re-emit.
            return rest_ensure_response([
                'state'    => $this->state->current(),
                'started'  => false,
                'complete' => true,
                'progress' => $this->progress->snapshot(),
            ]);
        }

        try {
            $result = $this->backfill->start();
        } catch (BackfillBlockedException $blocked) {
            return new \WP_Error(
                'hsp_backfill_blocked',
                __('Backfill prerequisites are not met.', 'headless-sync'),
                ['status' => 409, 'gate' => $blocked->summary()],
            );
        }

        return rest_ensure_response([
            'state'     => $this->state->current(),
            'started'   => true,
            'reemitted' => $result->repairedCount(),
            'scanned'   => $result->scanned,
            'progress'  => $this->progress->snapshot(),
        ]);
    }

    /**
     * GET onboarding/backfill/progress — derived progress + gate status (ONB-S2; DECISION W (d)).
     *
     * Progress is derived on demand (DECISION Q — zero new persistence). When the backfill has
     * CONVERGED (all expected content projected AND no in-flight events draining), this flips
     * `hsp_onboarding_state` → COMPLETE (un-gating the Operations + API Playground pages) and
     * reports the redirect target. The only write on this path is that single WP-option flag; no
     * PG write, no schema change. Guards against flipping complete mid-flight (convergence requires
     * zero in-flight — DECISION U D4 semantics via BackfillProgress).
     */
    public function handleBackfillProgress(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        $snapshot = $this->progress->snapshot();

        // Convergence → complete transition (once). Never regress an already-complete state.
        if (! $this->state->isComplete() && $snapshot['converged'] === true) {
            $this->state->markComplete();
        }

        $complete = $this->state->isComplete();

        return rest_ensure_response([
            'state'    => $this->state->current(),
            'complete' => $complete,
            'progress' => $snapshot,
            'gate'     => $this->backfillGateSummary(),
            // The client redirects to Operations once complete (DECISION W (d)).
            'redirect' => $complete ? 'operations' : null,
        ]);
    }

    /**
     * Gate summary for the progress surface (worker heartbeat + migrations). Read-only.
     *
     * @return array{ready:bool,gates:list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>}
     */
    private function backfillGateSummary(): array
    {
        return $this->backfill->gateSummary();
    }

    /**
     * Verify nonce + capability at the JSON boundary (WPCS — DECISION V (b) / W (a)).
     *
     * Returns a WP_Error (401/403) to short-circuit the handler, or null when authorized. The
     * nonce arrives in the standard `X-WP-Nonce` header (WP maps it to the `_wpnonce` param).
     */
    private function authorize(\WP_REST_Request $request): ?\WP_Error
    {
        $nonce = (string) ($request->get_header('X-WP-Nonce') ?? $request->get_param('_wpnonce') ?? '');
        $nonce = sanitize_text_field($nonce);

        if ($nonce === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new \WP_Error(
                'hsp_invalid_nonce',
                __('Invalid or missing security token.', 'headless-sync'),
                ['status' => 401]
            );
        }

        if (! current_user_can(self::CAPABILITY)) {
            return new \WP_Error(
                'hsp_forbidden',
                __('You do not have permission to perform this action.', 'headless-sync'),
                ['status' => 403]
            );
        }

        return null;
    }
}
