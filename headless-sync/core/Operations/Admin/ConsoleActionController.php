<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Admin;

use HSP\Core\Contracts\Operations\ActionResult;
use HSP\Core\Contracts\Operations\ConsoleAction;
use HSP\Core\Operations\Services\OperationsActionService;
use HSP\Core\Operations\Services\OperationsService;

/**
 * The wp-admin boundary for Operations Console operational ACTIONS (OPSC-S4; DECISION V (b)/(d);
 * ADR-053).
 *
 * This is the action-side sibling of {@see ConsoleAjaxController} (which serves the read-only
 * poll + Playground-execute endpoints). It exposes ONE nonce-protected admin-ajax action that
 * runs a registered Replay or Reconcile action. State change is always explicit, discoverable,
 * and auditable (ADR-053): the descriptor for every action carries a required capability and a
 * confirmation flag, and this boundary enforces BOTH before delegating.
 *
 * At the wp-admin boundary (WPCS — DECISION V (b)) every request:
 *   0. must be a POST — a state-changing action is rejected outright on any other method
 *      (400), before the nonce check; GET must never mutate,
 *   1. verifies the console nonce (check_ajax_referer),
 *   2. resolves the target ConsoleAction from the Action Registry via OperationsService (so the
 *      per-action capability is DISCOVERED from the descriptor, never hardcoded here),
 *   3. enforces that action's required capability (current_user_can),
 *   4. enforces confirmation when the descriptor requires it (a 'confirm' flag must be truthy) —
 *      the confirmation half of the OPSC-S4 DoD,
 *   5. sanitizes every input before use,
 *   6. and delegates THROUGH OperationsActionService — which routes to the ratified
 *      ReplayWorkerStrategy / ReconciliationWorkerStrategy only (DECISION T/S/U). This boundary
 *      never touches a DatabaseConnectionInterface, an adapter, a reader, or a concrete provider
 *      (ADR-053); it holds no repair primitive, so it cannot write a projection directly.
 *
 * No Flush Queue / Restart Workers action can be dispatched here: the Action Registry only ever
 * holds 'replay' and 'reconcile' (DECISION V (e)/(f)), and OperationsActionService rejects any
 * other key. The audit line is emitted inside OperationsActionService (existing observability
 * path — no new persistence).
 *
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class ConsoleActionController
{
    /** The single admin-ajax action name the console's action requests post to. */
    public const ACTION_INVOKE = 'hsp_ops_action';

    /** Reuses the console nonce action so one nonce covers poll / execute / action. */
    public const NONCE_ACTION = ConsoleAjaxController::NONCE_ACTION;

    public function __construct(
        private readonly OperationsService       $operations,
        private readonly OperationsActionService $actions,
    ) {}

    /**
     * Handler for ACTION_INVOKE — run one registered Replay/Reconcile action and return the
     * ActionResult as JSON. Bound to `wp_ajax_hsp_ops_action`.
     */
    public function handleInvoke(): void
    {
        // (0) POST-only — a state-changing action must never be driven by a GET (which is
        // cacheable, loggable, prefetchable, and CSRF-easy). Reject any non-POST outright,
        // before the nonce check (WPCS boundary hardening — DECISION V (b)).
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            wp_send_json_error(['message' => 'This action requires a POST request.'], 400);

            return;
        }

        // (1) Nonce — WPCS (DECISION V (b)).
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        // (5, partial) Sanitize the action key + confirmation flag at the boundary.
        $actionKey = isset($_POST['op_action'])
            ? sanitize_key(wp_unslash($_POST['op_action']))
            : '';
        $confirmed = isset($_POST['confirm']) && $this->isTruthy(wp_unslash($_POST['confirm']));

        // (2) Resolve the descriptor from the registry (capability + confirmation policy source).
        $descriptor = $this->descriptorFor($actionKey);
        if ($descriptor === null) {
            wp_send_json_error(['message' => 'Unknown or unregistered action.'], 400);

            return;
        }

        // (3) Capability — the descriptor's required capability, discovered not hardcoded.
        if (! current_user_can($descriptor->capability)) {
            wp_send_json_error(['message' => 'Insufficient capability.'], 403);

            return;
        }

        // (4) Confirmation — the OPSC-S4 DoD confirmation half.
        if ($descriptor->confirmationRequired && ! $confirmed) {
            wp_send_json_error(['message' => 'This action requires confirmation.'], 400);

            return;
        }

        // (5) Sanitize the action parameters.
        $params = $this->sanitizeParams();

        // (6) Delegate through the action service (thin delegator → ratified strategies only).
        try {
            $result = $this->actions->execute($actionKey, $params);
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);

            return;
        }

        wp_send_json_success($this->serialize($result));
    }

    /**
     * The admin-ajax URL the vanilla JS posts to.
     */
    public function url(): string
    {
        return admin_url('admin-ajax.php');
    }

    /**
     * A fresh nonce for the console's action requests (same nonce action as the read endpoints).
     */
    public function nonce(): string
    {
        return wp_create_nonce(self::NONCE_ACTION);
    }

    /**
     * The registered ConsoleAction for a key, or null if not registered (via OperationsService —
     * ADR-053; this controller never touches the registry directly).
     */
    private function descriptorFor(string $key): ?ConsoleAction
    {
        if ($key === '') {
            return null;
        }

        foreach ($this->operations->actions() as $action) {
            if ($action->key === $key) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Sanitize the whitelisted action parameters at the boundary (WPCS — DECISION V (b)).
     *
     * Only known parameter keys are read; each is passed through sanitize_text_field. This is a
     * fixed, small surface (replay/reconcile modes + their arguments) — no arbitrary input is
     * forwarded to the service.
     *
     * @return array<string,mixed>
     */
    private function sanitizeParams(): array
    {
        $params = [];

        foreach (['mode', 'aggregate_type', 'aggregate_id', 'from', 'to'] as $key) {
            if (isset($_POST[$key])) {
                $params[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }

        if (isset($_POST['dry_run'])) {
            $params['dry_run'] = $this->isTruthy(wp_unslash($_POST['dry_run']));
        }

        return $params;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Convert an ActionResult into a plain JSON-friendly array for the response.
     *
     * @return array<string,mixed>
     */
    private function serialize(ActionResult $result): array
    {
        return [
            'action'  => $result->action,
            'ok'      => $result->ok,
            'summary' => $result->summary,
            'count'   => $result->count,
            'detail'  => $result->detail,
        ];
    }
}
