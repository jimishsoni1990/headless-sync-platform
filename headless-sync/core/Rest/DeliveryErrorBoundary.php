<?php

declare(strict_types=1);

namespace HSP\Core\Rest;

use HSP\Core\Observability\StructuredLogger;

/**
 * The one place an unhandled Throwable from a delivery-API callback becomes a public error
 * response (CCF-003).
 *
 * WHY IT EXISTS. Before this, a Throwable escaping a `hsp/v1` callback was never converted: WordPress
 * has no exception boundary around REST callbacks, so PHP's fatal handler rendered the response. The
 * measured result on an unauthenticated GET was `Content-Type: text/html`, a stack trace, absolute
 * filesystem paths, the plugin path, the exception class and the SQL text. Nothing about that is a
 * contract a consumer can code against, and the disclosure is a defect in its own right.
 *
 * WHAT IT GUARANTEES. A callback that throws yields the SAME public envelope as every other HSP
 * application error — `{"code":"hsp_internal_error","message":…,"data":{"status":500}}` — with a
 * fixed generic message. The Throwable's own message, class, file, line and trace go to the
 * platform's existing structured-log channel (DECISION Q) and NEVER to the response.
 *
 * WHAT IT DOES NOT COVER (stated plainly — CCF-003 must not overclaim). Only Throwables raised
 * INSIDE a wrapped delivery callback. Not PHP engine fatals (OOM, E_ERROR), not failures before
 * WordPress dispatches to the callback, not web-server or proxy errors, not WordPress boot failure.
 * Those happen outside the HSP application boundary and this contract does not speak for them.
 *
 * It is DEFENCE IN DEPTH, not the input-validation mechanism: a malformed cursor or filter is
 * rejected as a 400 at the registrar, before any query runs. Reaching this class at all means
 * something genuinely unexpected happened.
 *
 * AN INJECTED SERVICE, NOT A STATIC. It has a real collaborator (the logger) and real behaviour, so
 * it is constructor-injected into each registrar exactly like every other dependency — no static
 * service access, no container reference, no service locator (ADR-012 / Rule 7).
 *
 * TRANSPORT-SPECIFIC BY DESIGN. WP_Error appears here because this IS the WordPress REST transport
 * boundary, the same role ContentRestRegistrar plays. Nothing WordPress-shaped is pushed down into
 * QueryProviderInterface, ResourceInterface, the FilterSets or CursorPage — ADR-038 holds.
 */
final class DeliveryErrorBoundary
{
    /** The single public code for a contained internal failure (CCF-003). */
    public const CODE = 'hsp_internal_error';

    public function __construct(
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Run one delivery callback, converting any Throwable into the public 500 envelope.
     *
     * @param callable():mixed $callback
     * @return mixed the callback's own result, or a WP_Error on failure
     */
    public function execute(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            // Internals go to the EXISTING observability channel (DECISION Q structured log →
            // error_log → debug.log). No new logging subsystem is introduced here, and none of
            // this reaches the client.
            $this->logger->metric('delivery.callback_failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return new \WP_Error(
                self::CODE,
                // Fixed and generic. Interpolating anything from the Throwable is exactly the
                // disclosure this class was written to stop.
                __('An internal server error occurred.', 'headless-sync'),
                ['status' => 500]
            );
        }
    }

    /**
     * Wrap a REST callback so WordPress invokes it through this boundary.
     *
     * Registrars pass their own handler and register the returned closure, so the try/catch and
     * the 500 representation live HERE and are never re-implemented per module.
     *
     * @param callable(mixed):mixed $handler
     * @return \Closure(mixed):mixed
     */
    public function guard(callable $handler): \Closure
    {
        return fn (mixed $request): mixed => $this->execute(
            static fn (): mixed => $handler($request)
        );
    }
}
