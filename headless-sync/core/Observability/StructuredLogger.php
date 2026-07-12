<?php

declare(strict_types=1);

namespace HSP\Core\Observability;

/**
 * Minimal structured-log emitter (DECISION Q clause 2).
 *
 * DECISION Q (v1.16): runtime counters (processed/retry/failure/replay) are emitted
 * as STRUCTURED LOG OUTPUT from the worker runtime — not stored in a metrics table.
 * This class emits one JSON object per event to the PHP error log (WordPress routes
 * error_log() to debug.log under WP_DEBUG_LOG). No external telemetry backend (statsd,
 * Prometheus, OpenSearch) is introduced — that is explicitly out of MVP scope.
 *
 * Format: {"hsp":"metric","event":"<name>","ts":"<ISO8601>", ...context}
 *
 * A test/CLI sink can be injected to capture emissions instead of writing to error_log.
 */
final class StructuredLogger
{
    /** @var callable(string):void */
    private $sink;

    /**
     * @param (callable(string):void)|null $sink Custom sink; defaults to error_log().
     */
    public function __construct(?callable $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * Emit one structured metric log line.
     *
     * @param array<string,mixed> $context
     */
    public function metric(string $event, array $context = []): void
    {
        $payload = array_merge(
            [
                'hsp'   => 'metric',
                'event' => $event,
                'ts'    => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->format(\DateTimeInterface::ATOM),
            ],
            $context,
        );

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            $line = '{"hsp":"metric","event":"' . $event . '","error":"json_encode_failed"}';
        }

        ($this->sink)($line);
    }
}
