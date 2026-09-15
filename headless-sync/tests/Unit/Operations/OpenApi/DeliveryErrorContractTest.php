<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Operations\OpenApi\OpenApiEndpointProvider;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Core\Rest\DeliveryErrorBoundary;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Resources\CategoryResource;
use HSP\Modules\Content\Resources\MediaResource;
use HSP\Modules\Content\Resources\PageResource;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Rest\ContentRestRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runtime ⇄ documented-contract parity for `hsp/v1` ERROR responses (CCF-003).
 *
 * The rule this file exists to enforce: the documented error body and the runtime error body are
 * the same thing. CCF-003 is not solved by publishing an idealised schema — it is solved when the
 * bytes a consumer actually receives validate against the schema the generator produced for that
 * operation and that status.
 *
 * So every payload below is produced by calling a REAL registrar handler and converting its result
 * exactly as WordPress does, and every schema is read out of the GENERATED document — never
 * hand-written here, never a fixture.
 *
 * Message text is deliberately NOT pinned. `code` is the contractual discriminator; `message` is
 * for humans and may be reworded or localised. Pinning English strings would make translation a
 * breaking change, which is the opposite of what the contract says.
 */
final class DeliveryErrorContractTest extends TestCase
{
    private const VALIDATOR_SCRIPT = __DIR__ . '/../../../../tools/openapi-validator/validate-openapi.mjs';

    private const UUID = '01a09ec2-5241-7f5f-9c54-bf145f4e8bc7';

    // =========================================================================
    // The envelope itself
    // =========================================================================

    /**
     * @return array<string,array{0:string,1:int,2:string}> label → [route key, status, code]
     */
    public static function runtimeErrorCases(): array
    {
        return [
            'content 400 invalid status' => ['/hsp/v1/posts', 400, 'hsp_invalid_status'],
            'content 400 invalid cursor' => ['/hsp/v1/posts', 400, 'hsp_invalid_cursor'],
            'content 400 invalid path'   => ['/hsp/v1/pages/{path}', 400, 'hsp_invalid_path'],
            'content 404 missing post'   => ['/hsp/v1/posts/{slug}', 404, 'hsp_not_found'],
            'content 404 missing page'   => ['/hsp/v1/pages/{path}', 404, 'hsp_not_found'],
            'content 500 contained'      => ['/hsp/v1/posts', 500, 'hsp_internal_error'],
            'commerce 400 invalid cursor' => ['/hsp/v1/products', 400, 'hsp_invalid_cursor'],
            'commerce 400 invalid filter' => ['/hsp/v1/products', 400, 'hsp_invalid_filter'],
            'commerce 404 missing product' => ['/hsp/v1/products/{slug}', 404, 'hsp_not_found'],
            'commerce 404 missing taxonomy' => ['/hsp/v1/product-attributes/{taxonomy}', 404, 'hsp_not_found'],
            'commerce 500 contained'     => ['/hsp/v1/products', 500, 'hsp_internal_error'],
        ];
    }

    /**
     * Every HSP application error — across both modules, across 400/404/500 — publishes the SAME
     * three top-level keys with the same types, and `data.status` agrees with the HTTP status.
     */
    #[DataProvider('runtimeErrorCases')]
    public function test_every_runtime_error_uses_one_envelope(string $route, int $status, string $code): void
    {
        $payload = self::payloadFor($code, $status);

        self::assertSame(['code', 'message', 'data'], array_keys($payload), 'Top-level keys are closed.');
        self::assertIsString($payload['code']);
        self::assertIsString($payload['message']);
        self::assertIsArray($payload['data']);
        self::assertSame($code, $payload['code']);
        self::assertIsInt($payload['data']['status']);
        self::assertSame(
            $status,
            $payload['data']['status'],
            'data.status must never contradict the HTTP status.',
        );
        self::assertNotSame('', $payload['message']);
    }

    // =========================================================================
    // Runtime payload ⇄ generated OpenAPI schema (ajv instance validation)
    // =========================================================================

    /**
     * The gate that makes CCF-003 honest: the body the runtime produces is validated by ajv against
     * the schema the generator published for that exact operation and status.
     */
    #[DataProvider('runtimeErrorCases')]
    public function test_runtime_payload_validates_against_the_generated_schema(
        string $route,
        int $status,
        string $code
    ): void {
        $schema  = self::schemaFor($route, $status);
        $payload = self::payloadFor($code, $status);

        $validator = new OpenApiMetaSchemaValidator(self::VALIDATOR_SCRIPT);
        $error     = null;
        $result    = $validator->instanceStatus($schema, $payload, $error);

        if ($result === OpenApiMetaSchemaValidator::GATE_SKIPPED) {
            if (getenv('HSP_REQUIRE_NODE_GATE') === '1') {
                self::fail(
                    'HSP_REQUIRE_NODE_GATE=1 requires the ajv instance gate, but it could not run: '
                    . (string) $error,
                );
            }

            self::markTestSkipped(
                'Error-payload instance gate SKIPPED — node unavailable (' . (string) $error
                . '). Set HSP_REQUIRE_NODE_GATE=1 (CI) to make this a hard failure.',
            );
        }

        self::assertSame(
            OpenApiMetaSchemaValidator::GATE_VALID,
            $result,
            "Runtime {$status} payload for {$route} does not validate against its generated schema: "
            . (string) $error,
        );
    }

    /**
     * The negative control. Without it the test above could pass against a schema so loose that
     * anything validates — which is exactly the "documented but not enforced" failure mode.
     */
    public function test_a_body_missing_the_guaranteed_fields_is_rejected_by_the_schema(): void
    {
        $validator = new OpenApiMetaSchemaValidator(self::VALIDATOR_SCRIPT);

        if (! $validator->nodeAvailable()) {
            if (getenv('HSP_REQUIRE_NODE_GATE') === '1') {
                self::fail('HSP_REQUIRE_NODE_GATE=1 requires the ajv instance gate; node is unavailable.');
            }
            self::markTestSkipped('node unavailable.');
        }

        $schema = self::schemaFor('/hsp/v1/posts/{slug}', 404);

        foreach ([
            'no code'            => ['message' => 'x', 'data' => ['status' => 404]],
            'no message'         => ['code' => 'hsp_not_found', 'data' => ['status' => 404]],
            'no data'            => ['code' => 'hsp_not_found', 'message' => 'x'],
            'no data.status'     => ['code' => 'hsp_not_found', 'message' => 'x', 'data' => []],
            'status not integer' => ['code' => 'hsp_not_found', 'message' => 'x', 'data' => ['status' => '404']],
            // The shapes CCF-003 forbids: a second, different top-level envelope.
            'foreign envelope'   => ['error' => 'not found'],
        ] as $label => $bad) {
            self::assertSame(
                OpenApiMetaSchemaValidator::GATE_INVALID,
                $validator->instanceStatus($schema, $bad),
                "The error schema must reject '{$label}'.",
            );
        }
    }

    /**
     * `data` stays OPEN so a safe WordPress diagnostic extension does not invalidate the contract —
     * permitted, but never promised.
     */
    public function test_an_extra_key_inside_data_is_permitted(): void
    {
        $validator = new OpenApiMetaSchemaValidator(self::VALIDATOR_SCRIPT);

        if (! $validator->nodeAvailable()) {
            self::markTestSkipped('node unavailable.');
        }

        $result = $validator->instanceStatus(
            self::schemaFor('/hsp/v1/posts', 400),
            ['code' => 'rest_invalid_param', 'message' => 'x', 'data' => ['status' => 400, 'params' => ['per_page' => 'bad']]],
        );

        self::assertSame(OpenApiMetaSchemaValidator::GATE_VALID, $result);
    }

    // =========================================================================
    // The generated document
    // =========================================================================

    /** Every declared non-2xx response carries a JSON body schema, not just a description. */
    public function test_every_documented_error_response_has_a_body_schema(): void
    {
        $document = self::document();
        $checked  = 0;

        foreach ($document['paths'] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                foreach ($operation['responses'] as $status => $response) {
                    if ((int) $status < 300) {
                        continue;
                    }

                    self::assertArrayHasKey(
                        'content',
                        $response,
                        "{$method} {$path} documents {$status} with no body — the runtime returns JSON.",
                    );
                    self::assertArrayHasKey('application/json', $response['content']);
                    self::assertArrayHasKey('schema', $response['content']['application/json']);
                    $checked++;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'No error responses were documented at all.');
    }

    /** One envelope means one schema: every error response must reference an identical shape. */
    public function test_every_error_response_uses_the_same_schema(): void
    {
        $document = self::document();
        $schemas  = [];

        foreach ($document['paths'] as $pathItem) {
            foreach ($pathItem as $operation) {
                foreach ($operation['responses'] as $status => $response) {
                    if ((int) $status >= 300) {
                        $schemas[] = $response['content']['application/json']['schema'];
                    }
                }
            }
        }

        self::assertNotSame([], $schemas);
        foreach ($schemas as $schema) {
            self::assertSame($schemas[0], $schema, 'The delivery API has ONE error envelope.');
        }
    }

    /**
     * The statuses each operation declares, pinned against what the routes can actually emit.
     *
     * Two failure modes this guards. Under-declaring hides a status a consumer will meet — the
     * defect CCF-003 opened with. Over-declaring is just as bad: bolting 400/404/500 onto every
     * operation would make the document say nothing, since a listing cannot 404 and a
     * single-resource route with a regex-constrained slug cannot 400.
     */
    public function test_declared_error_statuses_match_the_routes(): void
    {
        $expected = [
            // Content
            '/hsp/v1/pages'          => [400, 500],
            '/hsp/v1/pages/{path}'   => [400, 404, 500],
            '/hsp/v1/posts'          => [400, 500],
            '/hsp/v1/posts/{slug}'   => [404, 500],
            '/hsp/v1/categories'     => [400, 500],
            '/hsp/v1/categories/{slug}' => [404, 500],
            '/hsp/v1/media'          => [400, 500],
            '/hsp/v1/media/{slug}'   => [404, 500],
            '/hsp/v1/tags'           => [400, 500],
            '/hsp/v1/tags/{slug}'    => [404, 500],
            // Commerce
            '/hsp/v1/products'       => [400, 500],
            '/hsp/v1/products/{slug}' => [404, 500],
            '/hsp/v1/products/{slug}/variations' => [400, 404, 500],
            '/hsp/v1/product-categories' => [400, 500],
            '/hsp/v1/product-categories/{slug}' => [404, 500],
            '/hsp/v1/product-attributes' => [400, 500],
            '/hsp/v1/product-attributes/{taxonomy}' => [404, 500],
            '/hsp/v1/product-attributes/{taxonomy}/terms' => [400, 404, 500],
            // Core
            '/hsp/v1/openapi.json'   => [500],
        ];

        $document = self::document();

        foreach ($expected as $path => $statuses) {
            self::assertArrayHasKey($path, $document['paths'], "Missing path {$path}.");

            $documented = array_values(array_filter(
                array_map('intval', array_keys($document['paths'][$path]['get']['responses'])),
                static fn (int $s): bool => $s >= 300,
            ));
            sort($documented);

            self::assertSame($statuses, $documented, "Declared error statuses for {$path}.");
        }
    }

    /** No descriptor may declare a status the shared envelope has no description for. */
    public function test_no_operation_declares_an_undescribed_status(): void
    {
        foreach (self::descriptors() as $descriptor) {
            foreach ($descriptor->errorStatuses as $status) {
                self::assertContains(
                    $status,
                    [400, 404, 500],
                    "{$descriptor->route} declares an unsupported error status {$status}.",
                );
            }
        }
    }

    // =========================================================================
    // Runtime payload production — real handlers, WordPress's own conversion
    // =========================================================================

    /**
     * Produce the exact JSON body a consumer receives for a given error, by running the REAL
     * handler and converting the WP_Error the way WordPress does.
     *
     * @return array<string,mixed>
     */
    private static function payloadFor(string $code, int $status): array
    {
        return self::wireBody(match ($code) {
            'hsp_invalid_status' => self::contentRegistrar()->handlePostListing(new \WP_REST_Request(['status' => 'draft'])),
            'hsp_invalid_path'   => self::contentRegistrar()->handlePageSingle(new \WP_REST_Request(['path' => 'a//b'])),
            'hsp_invalid_filter' => self::commerceRegistrar()->handleProductListing(new \WP_REST_Request(['min_price' => 'abc'])),
            'hsp_internal_error' => self::boundary()->execute(static function (): never {
                throw new \RuntimeException('a contained internal failure');
            }),
            // Both modules' cursor rejection is exercised and compared inside the helper.
            'hsp_invalid_cursor' => self::cursorError(),
            // Both modules' single-resource miss, likewise.
            'hsp_not_found'      => self::notFoundFor($status),
            default              => throw new \LogicException("unmapped code {$code}"),
        });
    }

    /**
     * Both modules' invalid-cursor path, through their real listing handlers.
     *
     * Commerce used to answer 200 here — it dropped the unusable token and restarted at page 1,
     * handing the consumer rows it had already paged past. Both modules are compared against each
     * other so the two never drift apart again.
     */
    private static function cursorError(): mixed
    {
        $content  = self::contentRegistrar()->handlePostListing(new \WP_REST_Request(['cursor' => 'not-a-cursor']));
        $commerce = self::commerceRegistrar()->handleProductListing(new \WP_REST_Request(['cursor' => 'not-a-cursor']));

        self::assertInstanceOf(\WP_Error::class, $content, 'Content must reject an invalid cursor.');
        self::assertInstanceOf(\WP_Error::class, $commerce, 'Commerce must reject an invalid cursor.');
        self::assertSame($content->code, $commerce->code, 'Both modules use the same cursor error code.');
        self::assertSame($content->data['status'], $commerce->data['status']);

        return $commerce;
    }

    /** A Content miss and a Commerce miss must publish the same envelope (different messages). */
    private static function notFoundFor(int $status): mixed
    {
        $content  = self::contentRegistrar()->handlePostSingle(new \WP_REST_Request(['slug' => 'no-such-post']));
        $commerce = self::commerceRegistrar()->handleProductSingle(new \WP_REST_Request(['slug' => 'no-such-product']));

        self::assertInstanceOf(\WP_Error::class, $content);
        self::assertInstanceOf(\WP_Error::class, $commerce);
        self::assertSame($content->code, $commerce->code, 'Both modules use hsp_not_found.');
        self::assertSame($status, $content->data['status']);
        self::assertSame($status, $commerce->data['status']);

        return $content;
    }

    /**
     * WordPress's own WP_Error → response body conversion, single-error case.
     *
     * Mirrors `rest_convert_error_to_response()` (wp-includes/rest-api.php): `code`, `message` and
     * `data` from the first error, the status read out of `data['status']`. The multi-error keys
     * (`additional_errors`, `additional_data`) are unreachable from HSP routes — every HSP WP_Error
     * carries exactly one code with one data array — which is why they are not documented as part
     * of the contract.
     *
     * @return array<string,mixed>
     */
    private static function wireBody(mixed $result): array
    {
        self::assertInstanceOf(
            \WP_Error::class,
            $result,
            'Expected an application error; the handler returned a success.',
        );

        return [
            'code'    => $result->code,
            'message' => $result->message,
            'data'    => $result->data,
        ];
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private static function boundary(): DeliveryErrorBoundary
    {
        return new DeliveryErrorBoundary(
            new StructuredLogger(static function (string $line): void {
            }),
        );
    }

    /** Providers that return nothing — every case below is an error path, not a data path. */
    private static function emptyProvider(): QueryProviderInterface
    {
        return new class implements QueryProviderInterface {
            public function list(QueryFilterInterface $filters): CursorPage
            {
                return new CursorPage([], null);
            }

            public function findBySlug(string $slug): ?array
            {
                return null;
            }
        };
    }

    private static function contentRegistrar(): ContentRestRegistrar
    {
        return new ContentRestRegistrar(
            pageQueryProvider:     self::hierarchicalProvider(),
            postQueryProvider:     self::emptyProvider(),
            categoryQueryProvider: self::emptyProvider(),
            mediaQueryProvider:    self::emptyProvider(),
            tagQueryProvider:      self::emptyProvider(),
            pageResource:          new PageResource(),
            postResource:          new PostResource(),
            categoryResource:      new CategoryResource(),
            mediaResource:         new MediaResource(),
            tagResource:           new CategoryResource(),
            errorBoundary:         self::boundary(),
        );
    }

    private static function hierarchicalProvider(): QueryProviderInterface&\HSP\Core\Contracts\HierarchicalQueryProviderInterface
    {
        return new class implements QueryProviderInterface, \HSP\Core\Contracts\HierarchicalQueryProviderInterface {
            public function list(QueryFilterInterface $filters): CursorPage
            {
                return new CursorPage([], null);
            }

            public function findBySlug(string $slug): ?array
            {
                return null;
            }

            public function findByPath(string $path): ?array
            {
                return null;
            }
        };
    }

    private static function commerceRegistrar(): CommerceRestRegistrar
    {
        $terms = self::emptyProvider();

        return new CommerceRestRegistrar(
            self::emptyProvider(),
            new ProductResource(),
            self::emptyProvider(),
            new TermResource(),
            self::emptyProvider(),
            new AttributeResource(),
            static fn (string $taxonomy): QueryProviderInterface => $terms,
            self::emptyProvider(),
            new VariationResource(),
            self::boundary(),
        );
    }

    /** @return EndpointDescriptor[] */
    private static function descriptors(): array
    {
        return [
            ...(new ContentEndpointProvider())->endpoints(),
            ...(new CommerceEndpointProvider())->endpoints(),
            ...(new OpenApiEndpointProvider())->endpoints(),
        ];
    }

    /** @return array<string,mixed> */
    private static function document(): array
    {
        return (new OpenApiGenerator())->generate(self::descriptors());
    }

    /**
     * The schema the GENERATED document publishes for one operation and status.
     *
     * @return array<string,mixed>
     */
    private static function schemaFor(string $path, int $status): array
    {
        $document = self::document();

        self::assertArrayHasKey($path, $document['paths'], "No documented path {$path}.");
        self::assertArrayHasKey(
            (string) $status,
            $document['paths'][$path]['get']['responses'],
            "{$path} does not document a {$status} response.",
        );

        return $document['paths'][$path]['get']['responses'][(string) $status]['content']['application/json']['schema'];
    }
}
