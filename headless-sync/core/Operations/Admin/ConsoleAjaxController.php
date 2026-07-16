<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Admin;

use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\QueueStatus;
use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Operations\Services\OperationsService;

/**
 * Nonce- and capability-protected admin-ajax endpoints for the Operations Console (OPSC-S3;
 * DECISION V (a)/(b); ADR-053).
 *
 * The MVP console is server-rendered PHP + MINIMAL vanilla JS whose ONLY job is polling
 * (DECISION V (a)). Two admin-ajax actions back that JS:
 *   - ACTION_POLL    — returns the current dashboard provider snapshots as JSON so the page
 *                      can refresh widgets without a full reload.
 *   - ACTION_EXECUTE — runs one live GET against a published hsp/v1 endpoint for the API
 *                      Playground (ADR-050) and returns the response as JSON.
 *
 * Both handlers, at the wp-admin boundary (DECISION V (b)):
 *   - verify the request nonce (check_ajax_referer),
 *   - enforce a capability (current_user_can),
 *   - sanitize every input before use,
 *   - and go THROUGH OperationsService — never touching a DatabaseConnectionInterface, the
 *     reader, or a concrete provider (ADR-053). The Playground executor re-resolves the target
 *     endpoint from OperationsService's EndpointProviderInterface metadata (read-only, GET only).
 *
 * No state-changing action exists here (DECISION V — read-only console; actions are OPSC-S4).
 */
final class ConsoleAjaxController
{
    public const ACTION_POLL    = 'hsp_ops_poll';
    public const ACTION_EXECUTE = 'hsp_ops_execute';
    public const NONCE_ACTION   = 'hsp_ops_nonce';

    /** Capability required for every console AJAX request. */
    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly OperationsService $operations,
        private readonly PlaygroundRequestExecutor $executor,
    ) {}

    /**
     * Handler for ACTION_POLL — refresh + return dashboard snapshots as JSON.
     */
    public function handlePoll(): void
    {
        $this->authorize();

        $snapshots = $this->operations->refreshAll();

        wp_send_json_success(['snapshots' => $this->serializeSnapshots($snapshots)]);
    }

    /**
     * Handler for ACTION_EXECUTE — run one live GET for the Playground and return the response.
     */
    public function handleExecute(): void
    {
        $this->authorize();

        // Sanitize every input at the boundary (WPCS — DECISION V (b)).
        // The endpoint is selected by its stable route key (METHOD /namespace/route), not a
        // positional index, so a registration-order shift can't retarget a different route.
        $key   = isset($_POST['endpoint'])
            ? sanitize_text_field(wp_unslash($_POST['endpoint']))
            : '';
        $slug  = isset($_POST['slug'])
            ? sanitize_text_field(wp_unslash($_POST['slug']))
            : '';
        $query = $this->sanitizeQuery(
            isset($_POST['query']) ? (string) wp_unslash($_POST['query']) : ''
        );

        $endpoints = $this->operations->endpointDescriptors();

        try {
            $result = $this->executor->execute($endpoints, $key, $slug, $query);
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);

            return;
        }

        wp_send_json_success($result);
    }

    /**
     * The admin-ajax URL the vanilla JS posts to.
     */
    public function url(): string
    {
        return admin_url('admin-ajax.php');
    }

    /**
     * A fresh nonce for the console's AJAX actions.
     */
    public function nonce(): string
    {
        return wp_create_nonce(self::NONCE_ACTION);
    }

    /**
     * Verify nonce + capability, or terminate the request (WPCS — DECISION V (b)).
     */
    private function authorize(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Insufficient capability.'], 403);
        }
    }

    /**
     * Turn a `key=value&k2=v2` string into a sanitized associative array.
     *
     * @return array<string,string>
     */
    private function sanitizeQuery(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $pairs = [];
        parse_str($raw, $pairs);

        $clean = [];
        foreach ($pairs as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $clean[sanitize_key($key)] = sanitize_text_field((string) $value);
        }

        return $clean;
    }

    /**
     * Convert provider-snapshot DTOs into plain JSON-friendly arrays for the poll response.
     *
     * @param array<string,mixed> $snapshots provider key → snapshot
     *
     * @return array<string,mixed>
     */
    private function serializeSnapshots(array $snapshots): array
    {
        $out = [];
        foreach ($snapshots as $key => $snapshot) {
            $out[$key] = $this->serialize($snapshot);
        }

        return $out;
    }

    private function serialize(mixed $snapshot): mixed
    {
        if ($snapshot instanceof QueueStatus) {
            return [
                'depth'            => $snapshot->depth,
                'dead_letter_depth' => $snapshot->deadLetterDepth,
                'oldest_pending'   => $snapshot->oldestPendingAge !== null,
            ];
        }

        if (is_array($snapshot)) {
            return array_map($this->serialize(...), $snapshot);
        }

        if ($snapshot instanceof MetricSample) {
            return ['name' => $snapshot->name, 'value' => $snapshot->value, 'unit' => $snapshot->unit];
        }

        if ($snapshot instanceof HealthReport) {
            return [
                'component' => $snapshot->component,
                'severity'  => $snapshot->severity->value,
                'summary'   => $snapshot->summary,
            ];
        }

        if ($snapshot instanceof WorkerStatus) {
            return [
                'worker_id'   => $snapshot->workerId,
                'worker_type' => $snapshot->workerType,
                'online'      => $snapshot->online,
                'last_heartbeat' => $snapshot->lastHeartbeatAt?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $snapshot;
    }
}
