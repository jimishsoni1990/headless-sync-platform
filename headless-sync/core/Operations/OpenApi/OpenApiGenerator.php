<?php

declare(strict_types=1);

namespace HSP\Core\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;

/**
 * Produces the OpenAPI 3.1 document for the delivery API FROM the endpoint metadata registry
 * (ADR-055).
 *
 * (a) Registry-only. The document is built entirely from the EndpointDescriptor[] handed in
 *     (aggregated by OperationsService::endpointDescriptors() across every registered
 *     EndpointProviderInterface) — it is NEVER hand-authored and NEVER derived by reflecting
 *     over or enumerating WP REST routes. Route enumeration lives only in the CI drift guard,
 *     as a completeness assertion, never here (ADR-055 (a)/(f)).
 *
 * (d) Public-only scoping (v1.27). Descriptors whose auth field is non-public are EXCLUDED from
 *     the document; the filter reads the metadata auth field, never route inspection. The
 *     generator itself is public + stateless — it performs no capability check (ADR-055 (d)/(e)).
 *
 * (e) Request-time + stateless. Pure array transformation: NO persistence, NO PostgreSQL read,
 *     NO new connection handle (DECISION L Ruling 0), NO pg_* wrapper (DECISION E). It is NOT
 *     part of the ADR-054 processing cycle — it runs synchronously in the web request.
 *
 * ADR-038: transport-agnostic — consumes plain descriptor DTOs, returns a plain array; no
 * WP_REST_* / HTTP types appear here (the REST registrar owns the WordPress boundary).
 */
final class OpenApiGenerator
{
    private const OPENAPI_VERSION = '3.1.0';

    /**
     * Response descriptions for the documented error statuses (CCF-003).
     *
     * Cross-cutting REPRESENTATION only — it says what an HTTP status means on this API, never what
     * counts as a missing product or a legal page path. Those remain module decisions (Rule 5).
     *
     * @var array<int,string>
     */
    private const ERROR_DESCRIPTIONS = [
        400 => 'Invalid request — a parameter, filter, cursor or path was rejected.',
        404 => 'The requested resource does not exist, or is not part of the public set.',
        500 => 'An internal error prevented the request from completing.',
    ];

    public function __construct(
        private readonly string $title = 'Headless Sync Platform — Delivery API',
        private readonly string $apiVersion = '1.0.0',
        private readonly string $description = 'Consumer-facing delivery API for headless content (Rule 6).',
    ) {
    }

    /**
     * Build the OpenAPI 3.1 document from the endpoint registry.
     *
     * @param EndpointDescriptor[] $descriptors the aggregated registry snapshot (all providers)
     * @return array<string,mixed> an OpenAPI 3.1 document (JSON-encodable)
     */
    public function generate(array $descriptors): array
    {
        $paths = [];

        foreach ($descriptors as $descriptor) {
            // (d) Public-only scoping — exclusion driven by the metadata auth field.
            if (! $descriptor->auth->isPublic()) {
                continue;
            }

            $path      = $this->pathKey($descriptor);
            $method    = strtolower($descriptor->method);
            $operation = $this->operation($descriptor);

            // Merge operations that share a path (distinct methods on one route).
            $paths[$path] ??= [];
            $paths[$path][$method] = $operation;
        }

        ksort($paths);

        return [
            'openapi' => self::OPENAPI_VERSION,
            'info'    => [
                'title'       => $this->title,
                'version'     => $this->apiVersion,
                'description' => $this->description,
            ],
            'paths'   => $paths,
        ];
    }

    /**
     * Full path key for the OpenAPI `paths` map: `/{namespace}{route}` with `{slug}`-style
     * templating already carried by the descriptor route (never a WP regex like `(?P<slug>…)`).
     */
    private function pathKey(EndpointDescriptor $descriptor): string
    {
        return '/' . trim($descriptor->namespace, '/') . $descriptor->route;
    }

    /**
     * Build one OpenAPI 3.1 Operation Object from a descriptor.
     *
     * @return array<string,mixed>
     */
    private function operation(EndpointDescriptor $descriptor): array
    {
        $operation = [
            'summary'     => $descriptor->description,
            'description' => $descriptor->description,
            'operationId' => $this->operationId($descriptor),
            'tags'        => [$descriptor->displayGroup],
            'responses'   => $this->responses($descriptor),
        ];

        $parameters = $this->parameters($descriptor->parameters);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($descriptor->requestSchema !== null) {
            $operation['requestBody'] = [
                'required' => true,
                'content'  => [
                    'application/json' => ['schema' => $descriptor->requestSchema->schema],
                ],
            ];
        }

        // Doc 9 §26 lifecycle → OpenAPI `deprecated` (omitted entirely when false, per convention).
        if ($descriptor->deprecated) {
            $operation['deprecated'] = true;
        }

        // Module owner (Doc 9 §6) + contract version (Doc 9 §7) surfaced as spec extensions so the
        // published document carries the ownership/version metadata without polluting the standard
        // Operation fields.
        $operation['x-hsp-module']  = $descriptor->moduleOwner;
        $operation['x-hsp-version'] = $descriptor->version;

        return $operation;
    }

    /**
     * @param EndpointParameter[] $params
     * @return array<int,array<string,mixed>>
     */
    private function parameters(array $params): array
    {
        $out = [];
        foreach ($params as $param) {
            $out[] = [
                'name'        => $param->name,
                'in'          => $param->in,
                'required'    => $param->required,
                'description' => $param->description,
                'schema'      => ['type' => $param->type],
            ];
        }

        return $out;
    }

    /**
     * Build the responses map: a 200 with the published response schema (cursor envelope already
     * baked into the descriptor's responseSchema for list operations — ADR-055 (c)), plus one
     * entry per non-2xx status the descriptor declares (CCF-003).
     *
     * Every error response carries the SAME schema, because every HSP application error already
     * carries the same runtime envelope. Documenting them with one shared builder is what keeps a
     * platform-wide error contract from becoming a few hundred lines of copy-pasted schema, while
     * each descriptor still names only the statuses its own route can emit.
     *
     * Note: PHP coerces the numeric-string key '200' to int(200); json_encode restores it to the
     * string "200" the OpenAPI Responses Object requires, so the on-the-wire document is correct.
     *
     * @return array<array-key,mixed>
     */
    private function responses(EndpointDescriptor $descriptor): array
    {
        $ok = [
            'description' => 'Successful response.',
        ];

        if ($descriptor->responseSchema !== null) {
            $ok['content'] = [
                'application/json' => ['schema' => $descriptor->responseSchema->schema],
            ];
        }

        $responses = ['200' => $ok];

        foreach ($descriptor->errorStatuses as $status) {
            $responses[(string) $status] = [
                'description' => self::ERROR_DESCRIPTIONS[$status] ?? 'Error response.',
                'content'     => [
                    'application/json' => ['schema' => self::errorSchema()],
                ],
            ];
        }

        return $responses;
    }

    /**
     * The ONE published delivery-API error schema (CCF-003).
     *
     * It describes what the runtime already emits — WordPress's native `WP_Error` REST
     * representation — rather than a new HSP shape. Consumers branch on the HTTP status and on
     * `code`; `message` is for humans and logs and may be reworded or localised without that being
     * a contract change.
     *
     * The top level is CLOSED (`additionalProperties: false`): three fields, all guaranteed.
     * `data` is OPEN, because WordPress may attach safe diagnostic keys alongside `status` and a
     * closed object would make the published contract disagree with the wire. Those extensions are
     * permitted, not promised — `params`, `details`, `additional_errors` and `additional_data` are
     * deliberately NOT documented as guaranteed, since no HSP route currently produces them.
     *
     * `code` is an open string, not an enum: a future code must not be a breaking schema change,
     * and WordPress's own dispatch-level codes (e.g. `rest_no_route`) must stay structurally valid.
     *
     * @return array<string,mixed>
     */
    private static function errorSchema(): array
    {
        return [
            'type'        => 'object',
            'title'       => 'ErrorResponse',
            'description' => 'The delivery API error envelope. `code` is the stable machine-readable '
                . 'discriminator; `message` is human-readable and MUST NOT be used for control flow; '
                . '`data.status` mirrors the HTTP status.',
            'required'    => ['code', 'message', 'data'],
            'properties'  => [
                'code'    => [
                    'type'        => 'string',
                    'description' => 'Machine-readable error code (e.g. hsp_not_found, '
                        . 'hsp_invalid_cursor, hsp_invalid_status, hsp_invalid_path, '
                        . 'hsp_invalid_filter, hsp_internal_error). Not an enum — new codes may be '
                        . 'added, and WordPress-level codes use the same envelope.',
                ],
                'message' => [
                    'type'        => 'string',
                    'description' => 'Human-readable description, for display fallback and logs. '
                        . 'Wording may change without notice; branch on `code` instead.',
                ],
                'data'    => [
                    'type'                 => 'object',
                    'description'          => 'Error metadata. `status` is guaranteed; any other key '
                        . 'is an optional diagnostic extension and is not part of the contract.',
                    'required'             => ['status'],
                    'properties'           => [
                        'status' => [
                            'type'        => 'integer',
                            'description' => 'The HTTP status of this response.',
                        ],
                    ],
                    'additionalProperties' => true,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Deterministic operationId: `{method}{Namespace}{Route}` slugified (unique per method+route).
     */
    private function operationId(EndpointDescriptor $descriptor): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $descriptor->route) ?? '';
        $slug = trim($slug, '_');

        return strtolower($descriptor->method) . '_' . ($slug === '' ? 'root' : $slug);
    }
}
