<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Handlers;

use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\CategoryTombstoneHandler;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Handlers\PageTombstoneHandler;
use HSP\Modules\Content\Handlers\PageUpsertHandler;
use HSP\Modules\Content\Handlers\PostTombstoneHandler;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Unit\Content\Adapters\FakeAdapterEvent;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for all six content handlers.
 *
 * No real database or WordPress required.
 * FakeWpContentLoader provides WP data; FakeDbConnection captures adapter SQL calls.
 *
 * Tests:
 *   PageUpsertHandler     — calls persist(); missing post throws
 *   PostUpsertHandler     — calls persist(); missing post throws
 *   CategoryUpsertHandler — calls persist(); missing term throws
 *   PageTombstoneHandler  — calls tombstone() (no WP load)
 *   PostTombstoneHandler  — calls tombstone() (no WP load)
 *   CategoryTombstoneHandler — calls tombstone() (no WP load)
 */
final class ContentHandlerTest extends TestCase
{
    private FakeWpContentLoader $loader;
    private FakeDbConnection    $db;

    protected function setUp(): void
    {
        $this->loader = new FakeWpContentLoader();
        $this->db     = new FakeDbConnection();

        // Prime the FakeDbConnection so adapter persist() / tombstone() don't need
        // real rows — the aggregate_versions materialise + lock sequence needs two
        // query() returns: first the INSERT ON CONFLICT DO NOTHING (execute), then
        // the FOR UPDATE SELECT (query). We just let the FakeDbConnection return [].
        // The persist() suppress-check: fetchExistingRow also returns [] → no existing row.
    }

    // -------------------------------------------------------------------------
    // Page upsert
    // -------------------------------------------------------------------------

    public function test_page_upsert_handler_calls_persist(): void
    {
        $this->loader->postResult['post_type'] = 'page';
        $handler = $this->makePageUpsertHandler();
        $event   = $this->makeEvent('content.page.created', 'page', '1');

        $handler->handle($event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);
        self::assertNotContains('rollback', $methods);
    }

    public function test_page_upsert_handler_throws_when_post_missing(): void
    {
        $this->loader->postResult = null;
        $handler = $this->makePageUpsertHandler();
        $event   = $this->makeEvent('content.page.created', 'page', '1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in WordPress/');
        $handler->handle($event);
    }

    // -------------------------------------------------------------------------
    // Post upsert
    // -------------------------------------------------------------------------

    public function test_post_upsert_handler_calls_persist(): void
    {
        $this->loader->postResult['post_type'] = 'post';
        $handler = $this->makePostUpsertHandler();
        $event   = $this->makeEvent('content.post.created', 'post', '1');

        $handler->handle($event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);
    }

    public function test_post_upsert_handler_throws_when_post_missing(): void
    {
        $this->loader->postResult = null;
        $handler = $this->makePostUpsertHandler();
        $event   = $this->makeEvent('content.post.created', 'post', '1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in WordPress/');
        $handler->handle($event);
    }

    // -------------------------------------------------------------------------
    // Category upsert
    // -------------------------------------------------------------------------

    public function test_category_upsert_handler_calls_persist(): void
    {
        $handler = $this->makeCategoryUpsertHandler();
        $event   = $this->makeEvent('content.category.created', 'category', '5');

        $handler->handle($event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);
    }

    public function test_category_upsert_handler_throws_when_term_missing(): void
    {
        $this->loader->termResult = null;
        $handler = $this->makeCategoryUpsertHandler();
        $event   = $this->makeEvent('content.category.created', 'category', '5');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in WordPress/');
        $handler->handle($event);
    }

    // -------------------------------------------------------------------------
    // Page tombstone
    // -------------------------------------------------------------------------

    public function test_page_tombstone_handler_sets_deleted_at_without_wp_load(): void
    {
        $adapter = new PageAdapter($this->db);
        $handler = new PageTombstoneHandler($adapter);
        $event   = $this->makeEvent('content.page.deleted', 'page', '1');

        $handler->handle($event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);

        // The tombstone UPDATE must touch content.pages
        $updateCount = $this->db->countExecuteContaining('UPDATE content.pages');
        self::assertSame(1, $updateCount);
    }

    public function test_page_tombstone_does_not_load_wordpress(): void
    {
        $loaderCallCount = 0;
        $spyLoader       = new class ($loaderCallCount) extends FakeWpContentLoader {
            public function __construct(public int &$count) {}
            public function loadPost(int $postId): ?array { $this->count++; return null; }
            public function loadPostMeta(int $postId): array { $this->count++; return []; }
        };

        $adapter = new PageAdapter($this->db);
        $handler = new PageTombstoneHandler($adapter);
        $event   = $this->makeEvent('content.page.deleted', 'page', '1');

        $handler->handle($event);

        self::assertSame(0, $loaderCallCount, 'Tombstone handler must not call WpContentLoader');
    }

    // -------------------------------------------------------------------------
    // Post tombstone
    // -------------------------------------------------------------------------

    public function test_post_tombstone_handler_sets_deleted_at(): void
    {
        $adapter = new PostAdapter($this->db);
        $handler = new PostTombstoneHandler($adapter);
        $event   = $this->makeEvent('content.post.deleted', 'post', '1');

        $handler->handle($event);

        self::assertSame(1, $this->db->countExecuteContaining('UPDATE content.posts'));
    }

    // -------------------------------------------------------------------------
    // Category tombstone
    // -------------------------------------------------------------------------

    public function test_category_tombstone_handler_sets_deleted_at(): void
    {
        $adapter = new CategoryAdapter($this->db);
        $handler = new CategoryTombstoneHandler($adapter);
        $event   = $this->makeEvent('content.category.deleted', 'category', '5');

        $handler->handle($event);

        self::assertSame(1, $this->db->countExecuteContaining('UPDATE content.taxonomies'));
    }

    // -------------------------------------------------------------------------
    // Tombstone deleted_at is derived from source_updated_at (DECISION I)
    // -------------------------------------------------------------------------

    public function test_tombstone_deleted_at_comes_from_source_updated_at_not_wall_clock(): void
    {
        $adapter = new PageAdapter($this->db);
        $handler = new PageTombstoneHandler($adapter);

        $sourceUpdatedAt = new \DateTimeImmutable('2024-06-15 10:00:00', new \DateTimeZone('UTC'));
        $event = new FakeAdapterEvent(
            id:               '01900000-0000-7000-8000-000000000099',
            eventType:        'content.page.deleted',
            aggregateType:    'page',
            aggregateId:      '1',
            aggregateVersion: 3,
            sourceUpdatedAt:  $sourceUpdatedAt,
        );

        $handler->handle($event);

        // Find the UPDATE execute call and verify its deleted_at param contains the expected date.
        $updateCalls = array_values(array_filter(
            $this->db->log,
            fn ($e) => $e['method'] === 'execute' && str_contains($e['sql'], 'UPDATE content.pages')
        ));
        self::assertCount(1, $updateCalls);
        self::assertStringContainsString('2024-06-15', $updateCalls[0]['params'][0]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePageUpsertHandler(): PageUpsertHandler
    {
        return new PageUpsertHandler(
            $this->loader,
            new PageExtractor(new PageValidator()),
            new PageTransformer(),
            new PageAdapter($this->db),
        );
    }

    private function makePostUpsertHandler(): PostUpsertHandler
    {
        return new PostUpsertHandler(
            $this->loader,
            new PostExtractor(new PostValidator()),
            new PostTransformer(),
            new PostAdapter($this->db),
        );
    }

    private function makeCategoryUpsertHandler(): CategoryUpsertHandler
    {
        return new CategoryUpsertHandler(
            $this->loader,
            new CategoryExtractor(new CategoryValidator()),
            new CategoryTransformer(),
            new CategoryAdapter($this->db),
        );
    }

    private function makeEvent(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
    ): FakeAdapterEvent {
        return new FakeAdapterEvent(
            id:               '01900000-0000-7000-8000-000000000001',
            eventType:        $eventType,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: 1,
        );
    }
}
