<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Core\Contracts\CursorPage;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Rest\ContentRestRegistrar;
use HSP\Tests\Unit\Content\Rest\FakeQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * The declarations behind related-by-tag composition (Finding 002, defects B1 + B2).
 *
 * Finding 002 asked whether a contract-only consumer can build "related posts" from what HSP
 * already publishes. It can — post tag references are typed, the tag filter works and is
 * taxonomy-discriminated, and the post slug is a public identity. Nothing about the CAPABILITY
 * was missing. What was missing was two statements of fact:
 *
 *   B1 — `?tag=` worked and the generated OpenAPI documented it, but ContentRestRegistrar never
 *        declared it as a route argument, so WordPress's own published route index
 *        (`/wp-json/hsp/v1`) listed `category` and not `tag`. Two machine-readable descriptions
 *        of one endpoint disagreed. The filter still ran — WordPress hands unregistered query
 *        parameters to get_param() and the handler sanitizes them itself — which is exactly why
 *        it went unnoticed from P1B-S3 until a consumer tried to generate a client.
 *
 *   B2 — the listing description said only "cursor-paginated". DECISION F ratified the sort keys
 *        at P1A-S5 and the query provider has always implemented them, but the public contract
 *        never said the listing is newest-first, so "the three most recent posts sharing this
 *        tag" was unbuildable without guessing or reading PHP.
 *
 * What this file therefore pins is the agreement between declarations, and the absence of the
 * semantics HSP deliberately does NOT own: there is no primary tag, no relevance ranking and no
 * related-post contract. HSP publishes tag facts and a filter; which tag to use, how many posts
 * to show and whether to use tags at all are the site's to decide.
 */
final class TagFilterContractTest extends TestCase
{
    // -------------------------------------------------------------------------
    // B1 — the three declarations of `tag` must agree
    // -------------------------------------------------------------------------

    public function test_the_posts_route_declares_the_tag_argument_to_wordpress(): void
    {
        $args = $this->registeredArgsFor('/posts');

        self::assertArrayHasKey(
            'tag',
            $args,
            "WordPress publishes its own route index at /wp-json/hsp/v1, and an undeclared "
            . 'argument is absent from it — so the index denied a filter the OpenAPI document '
            . 'advertised and the handler honoured (Finding 002 B1).',
        );
        self::assertSame('string', $args['tag']['type']);
    }

    public function test_the_declared_tag_sanitizer_is_the_one_the_handler_applies(): void
    {
        // The declaration has to describe the filter that actually runs. handlePostListing()
        // sanitizes with sanitize_title via sanitizeCategorySlug(); a route arg declaring some
        // other callback would publish a second, fictional input policy.
        self::assertSame('sanitize_title', $this->registeredArgsFor('/posts')['tag']['sanitize_callback']);
        self::assertSame(
            $this->registeredArgsFor('/posts')['category']['sanitize_callback'],
            $this->registeredArgsFor('/posts')['tag']['sanitize_callback'],
            'tag and category are the same kind of public slug input and are sanitized alike',
        );
    }

    public function test_route_arguments_and_descriptor_parameters_agree_about_tag(): void
    {
        // The drift this catches runs BOTH ways, which is the point: the guard fails if the
        // descriptor publishes `tag` while the route does not declare it (the shipped B1 defect)
        // AND if the route declares one the descriptor never publishes (an undocumented filter).
        // ADR-055's completeness guard compares routes to descriptors, not route ARGS to
        // descriptor PARAMETERS, so neither direction was covered — see FLAG-RESTARGDRIFT-1.
        $declared  = array_keys($this->registeredArgsFor('/posts'));
        $published = array_map(
            static fn ($p): string => $p->name,
            array_filter(
                $this->descriptorFor('/posts')->parameters,
                static fn ($p): bool => $p->in === 'query',
            ),
        );

        sort($declared);
        sort($published);

        self::assertSame(
            $published,
            $declared,
            'Every query parameter the posts listing publishes must be declared to WordPress, '
            . 'and every one declared must be published. Two descriptions, one endpoint.',
        );
        self::assertContains('tag', $declared);
        self::assertContains('tag', $published);
    }

    public function test_the_runtime_handler_honours_the_declared_tag_parameter(): void
    {
        // Agreement between two declarations would be worthless if the third party — the code —
        // ignored the parameter. This is the arm that proves the filter is really reached.
        $provider  = new FakeQueryProvider(new CursorPage([], null));
        $registrar = $this->makeRegistrar($provider);

        $registrar->handlePostListing(new \WP_REST_Request(['tag' => 'crypto']));

        self::assertInstanceOf(ContentFilterSet::class, $provider->lastFilters);
        self::assertSame('crypto', $provider->lastFilters->tagSlug);
        self::assertNull(
            $provider->lastFilters->categorySlug,
            'a tag filter is not a category filter — the shared taxonomy table makes that '
            . 'confusion a wrong answer rather than an error',
        );
    }

    // -------------------------------------------------------------------------
    // B2 — the ordering the consumer composes against
    // -------------------------------------------------------------------------

    public function test_the_posts_listing_publishes_its_ordering_guarantee(): void
    {
        $description = $this->descriptorFor('/posts')->description;

        self::assertStringContainsString('newest', $description);
        self::assertStringContainsString('deterministic', $description);
        self::assertMatchesRegularExpression(
            '/never .*(shuffle|repeat)/i',
            $description,
            'equal publication timestamps must be stated not to reorder between pages — that is '
            . 'the half a consumer cannot observe from one response',
        );
    }

    public function test_the_ordering_guarantee_leaks_no_internal_identity(): void
    {
        // DECISION F's tie-breaker is the projection UUID, which ADR-040 keeps internal and which
        // the cursor deliberately hides behind an opaque token. Consumers need to know the order
        // is stable; publishing WHAT makes it stable would publish an internal column.
        $description = $this->descriptorFor('/posts')->description;

        foreach (['id DESC', 'published_at DESC', 'uuid', 'ORDER BY'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $description);
        }
    }

    public function test_a_filtered_listing_is_not_published_as_a_ranking(): void
    {
        // The whole risk Finding 002 guards against: HSP supplies the primitives for related
        // content, and a description that implied relevance would silently turn a filter into a
        // recommendation contract nobody ratified.
        $descriptor = $this->descriptorFor('/posts');

        self::assertStringContainsString('never re-rank', $descriptor->description);

        foreach (['relevance', 'related', 'recommend', 'similar', 'score'] as $claim) {
            self::assertStringNotContainsStringIgnoringCase(
                $claim,
                $this->parameterNamed($descriptor, 'tag')->description,
                "the tag filter selects posts; it does not rank them",
            );
        }
    }

    public function test_the_tag_parameter_publishes_its_discrimination(): void
    {
        // WordPress guarantees slug uniqueness only WITHIN a taxonomy, so a site may legally own
        // a tag and a category both slugged `crypto` — as the live site does. Which one `?tag=`
        // means is contract, not an implementation detail.
        $description = $this->parameterNamed($this->descriptorFor('/posts'), 'tag')->description;

        self::assertStringContainsString('tag', $description);
        self::assertStringContainsString('category', $description);
        self::assertStringContainsString('never matches', $description);
    }

    // -------------------------------------------------------------------------
    // No related-post semantics are introduced — the scope rule, asserted
    // -------------------------------------------------------------------------

    public function test_no_related_post_field_is_published(): void
    {
        $published = (new PostResource())->toArray($this->row('a'));

        foreach ([
            'related_posts', 'related', 'similar_posts', 'recommended_posts',
            'primary_tag', 'related_rank', 'similarity_score', 'relevance',
        ] as $forbidden) {
            self::assertArrayNotHasKey(
                $forbidden,
                $published,
                'Related-content policy is the consumer\'s: which tag to use, how many posts to '
                . 'show, and whether to use tags at all. HSP publishes the relationship and the '
                . 'filter, and stops there.',
            );
            self::assertArrayNotHasKey($forbidden, $this->postProperties('/posts'));
            self::assertArrayNotHasKey($forbidden, $this->postProperties('/posts/{slug}'));
        }
    }

    public function test_no_ranking_or_ordering_parameter_is_offered(): void
    {
        // A `sort=relevance` or `related_to=` parameter would be HSP taking the editorial
        // decision. The absence is the contract.
        $declared = array_keys($this->registeredArgsFor('/posts'));

        foreach (['sort', 'order', 'orderby', 'related_to', 'similar_to', 'exclude'] as $forbidden) {
            self::assertNotContains($forbidden, $declared);
        }
    }

    public function test_a_tag_reference_carries_no_primacy_marker(): void
    {
        // post.tags is ordered by slug so the array is stable — a consumer diffing responses or
        // caching them should not see noise. Stable is NOT primary: "first tag" is a site policy
        // a consumer may adopt, never a claim HSP makes about editorial importance.
        $row              = $this->row('a');
        $row['tags_json'] = '[{"slug":"crypto","name":"crypto"},{"slug":"stocks","name":"stocks"}]';

        foreach ((new PostResource())->toArray($row)['tags'] as $reference) {
            self::assertSame(['slug', 'name'], array_keys($reference));
        }

        self::assertStringContainsString(
            'ordered by slug',
            $this->postProperties('/posts')['tags']['description'],
            'the published order is stated, so a consumer choosing "the first tag" knows what '
            . 'it is choosing',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'primary',
            $this->postProperties('/posts')['tags']['description'],
        );
    }

    // -------------------------------------------------------------------------
    // The consumer's inputs — public payload only
    // -------------------------------------------------------------------------

    public function test_an_untagged_post_publishes_an_empty_list_so_the_policy_short_circuits(): void
    {
        // The first branch of any related-by-tag rule. `[]` and not null is what lets a consumer
        // write `if (post.tags.length === 0)` without a null check first.
        $published = (new PostResource())->toArray($this->row('a'));

        self::assertSame([], $published['tags']);
        self::assertStringContainsString('"tags":[]', (string) json_encode($published));
    }

    public function test_the_consumer_needs_only_slugs_to_compose(): void
    {
        // Everything the reference workflow reads: the post's own public identity and the slug
        // of each tag. No projection UUID, no source_post_id, no WordPress term id — none are
        // published, and the workflow asks for none.
        $row              = $this->row('a');
        $row['tags_json'] = '[{"slug":"crypto","name":"crypto"}]';

        $published = (new PostResource())->toArray($row);

        self::assertSame('post-a', $published['slug']);
        self::assertSame('crypto', $published['tags'][0]['slug']);

        foreach (['id', 'source_post_id', 'source_term_id', 'term_id', 'taxonomy_id'] as $internal) {
            self::assertArrayNotHasKey($internal, $published);
            self::assertArrayNotHasKey($internal, $published['tags'][0]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The args ContentRestRegistrar hands WordPress for one route, captured from the real
     * register() call through the bootstrap's opt-in route-capture stub — the same external
     * ground truth the ADR-055 drift guard uses, never a restatement of the source.
     *
     * @return array<string,array<string,mixed>>
     */
    private function registeredArgsFor(string $route): array
    {
        $GLOBALS['_hsp_stub_rest_routes'] = [];

        $this->makeRegistrar(new FakeQueryProvider(new CursorPage([], null)))->register();

        $captured = $GLOBALS['_hsp_stub_rest_routes'];
        unset($GLOBALS['_hsp_stub_rest_routes']);

        foreach ($captured as $registration) {
            if ($registration['route'] === $route && $registration['namespace'] === 'hsp/v1') {
                return $registration['args']['args'] ?? [];
            }
        }

        self::fail("ContentRestRegistrar registered no hsp/v1 route {$route}");
    }

    private function descriptorFor(string $route): \HSP\Core\Contracts\Operations\EndpointDescriptor
    {
        foreach ((new ContentEndpointProvider())->endpoints() as $descriptor) {
            if ($descriptor->route === $route) {
                return $descriptor;
            }
        }

        self::fail("no descriptor for {$route}");
    }

    private function parameterNamed(
        \HSP\Core\Contracts\Operations\EndpointDescriptor $descriptor,
        string $name
    ): \HSP\Core\Contracts\Operations\EndpointParameter {
        foreach ($descriptor->parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        self::fail("{$descriptor->route} publishes no {$name} parameter");
    }

    /** @return array<string,mixed> */
    private function postProperties(string $route): array
    {
        $schema = $this->descriptorFor($route)->responseSchema?->schema ?? [];

        return $schema['properties']['data']['items']['properties']
            ?? $schema['properties']
            ?? [];
    }

    private function makeRegistrar(FakeQueryProvider $postProvider): ContentRestRegistrar
    {
        $empty = new FakeQueryProvider(new CursorPage([], null));

        return new ContentRestRegistrar(
            pageQueryProvider:     $empty,
            postQueryProvider:     $postProvider,
            categoryQueryProvider: $empty,
            mediaQueryProvider:    $empty,
            tagQueryProvider:      $empty,
            pageResource:          new \HSP\Modules\Content\Resources\PageResource(),
            postResource:          new PostResource(),
            categoryResource:      new \HSP\Modules\Content\Resources\CategoryResource(),
            mediaResource:         new \HSP\Modules\Content\Resources\MediaResource(),
            tagResource:           new \HSP\Modules\Content\Resources\CategoryResource(),
            errorBoundary:         new \HSP\Core\Rest\DeliveryErrorBoundary(
                new \HSP\Core\Observability\StructuredLogger(static function (string $line): void {
                }),
            ),
        );
    }

    /** @return array<string,mixed> */
    private function row(string $seed): array
    {
        return [
            'id'                => '01900000-0000-7000-8000-00000000000' . ord($seed) % 10,
            'slug'              => 'post-' . $seed,
            'title'             => 'Post',
            'content'           => '',
            'excerpt'           => '',
            'status'            => 'publish',
            'author'            => 'editor',
            'published_at'      => '2024-01-02 03:04:05+00',
            'updated_at'        => '2024-01-02 03:04:05+00',
            'meta_jsonb'        => '{}',
            'featured_media_id' => '0',
            'tags_json'         => '[]',
            'categories_json'   => '[]',
        ];
    }
}
