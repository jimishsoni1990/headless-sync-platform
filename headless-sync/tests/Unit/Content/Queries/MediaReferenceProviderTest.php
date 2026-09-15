<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Contracts\MediaReferenceProviderInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\Queries\MediaReferenceProvider;
use HSP\Modules\Content\Resources\MediaReference;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * The AG-10 media capability — Content's implementation of the Core contract (Finding 011).
 *
 * The contract exists so that a module holding attachment REFERENCES (a Commerce product carries
 * a featured id and a gallery list) can publish usable images without importing Content, without
 * querying `content.media`, and without duplicating attachment state. These tests pin the two
 * properties that make that safe: the lookup is BULK however many references are asked for, and
 * an unresolvable reference is absent rather than fabricated.
 */
final class MediaReferenceProviderTest extends TestCase
{
    private FakeDbConnection $db;
    private MediaReferenceProvider $provider;

    protected function setUp(): void
    {
        $this->db       = new FakeDbConnection();
        $this->provider = new MediaReferenceProvider($this->db);
    }

    /**
     * @param  array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function mediaRow(int $sourceId, array $overrides = []): array
    {
        return $overrides + [
            'source_post_id' => $sourceId,
            'slug'           => 'image-' . $sourceId,
            'url'            => 'https://example.test/uploads/' . $sourceId . '.jpg',
            'alt_text'       => 'alt ' . $sourceId,
            'mime_type'      => 'image/jpeg',
            'width'          => 800,
            'height'         => 600,
            'sizes_jsonb'    => '{"thumbnail":{"url":"https://example.test/t.jpg",'
                . '"width":150,"height":150,"mime_type":"image/jpeg"}}',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function queries(): array
    {
        return array_values(array_filter(
            $this->db->log,
            static fn (array $entry): bool => $entry['method'] === 'query',
        ));
    }

    public function testItImplementsTheCoreContractNotAContentSpecificOne(): void
    {
        self::assertInstanceOf(MediaReferenceProviderInterface::class, $this->provider);
    }

    // -------------------------------------------------------------------------
    // Bulk — the property AG-10 names
    // -------------------------------------------------------------------------

    /**
     * Twelve references, ONE query. This is the assertion AG-10 exists for: a product page with
     * galleries must not become a lookup per image.
     */
    public function testManyReferencesCostExactlyOneQuery(): void
    {
        $ids = range(101, 112);
        $this->db->willReturnRows(array_map(fn (int $id): array => $this->mediaRow($id), $ids));

        $resolved = $this->provider->resolveMany($ids);

        self::assertCount(1, $this->queries());
        self::assertCount(12, $resolved);
    }

    public function testOneReferenceCostsTheSameSingleQueryAsTwelve(): void
    {
        $this->db->willReturnRows([$this->mediaRow(101)]);
        $this->provider->resolveMany([101]);

        self::assertCount(1, $this->queries());
    }

    /** No references, NO query — an image-less page must not touch the database at all. */
    public function testAnEmptyReferenceListIssuesNoQuery(): void
    {
        self::assertSame([], $this->provider->resolveMany([]));
        self::assertSame([], $this->queries());
    }

    /**
     * 0 is WooCommerce's "no image", not an id. It must never reach the database, or every
     * product without an image would add a parameter that can match nothing.
     */
    public function testZeroAndNegativeIdsAreDroppedBeforeTheQuery(): void
    {
        self::assertSame([], $this->provider->resolveMany([0, -5]));
        self::assertSame([], $this->queries());

        $this->db->willReturnRows([$this->mediaRow(7)]);
        $this->provider->resolveMany([0, 7, 0]);

        self::assertSame([7], $this->queries()[0]['params']);
    }

    /**
     * A page where one image is a product's featured AND appears in another product's gallery
     * asks for it once. De-duplication applies to the QUERY only; the caller's own list keeps
     * its duplicates, because the result is a map the caller walks itself.
     */
    public function testRepeatedIdsAreAskedForOnce(): void
    {
        $this->db->willReturnRows([$this->mediaRow(9)]);
        $this->provider->resolveMany([9, 9, 9]);

        self::assertSame([9], $this->queries()[0]['params']);
    }

    // -------------------------------------------------------------------------
    // The lookup itself
    // -------------------------------------------------------------------------

    /** Rides uq_content_media_source_post_id — no new index, and no sequential scan. */
    public function testTheLookupIsBySourceAttachmentIdAndExcludesTombstones(): void
    {
        $this->db->willReturnRows([]);
        $this->provider->resolveMany([1, 2, 3]);

        $sql = (string) $this->queries()[0]['sql'];

        self::assertStringContainsString('FROM content.media', $sql);
        self::assertStringContainsString('source_post_id IN ($1, $2, $3)', $sql);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
    }

    // -------------------------------------------------------------------------
    // Absence
    // -------------------------------------------------------------------------

    /**
     * Unresolved references are ABSENT from the map, never present as null. Three causes —
     * never projected, tombstoned, deleted outright — and one answer, because a caller can do
     * nothing different about any of them.
     */
    public function testUnresolvedReferencesAreAbsentRatherThanNull(): void
    {
        $this->db->willReturnRows([$this->mediaRow(101)]);

        $resolved = $this->provider->resolveMany([101, 102, 103]);

        self::assertSame([101], array_keys($resolved));
        self::assertArrayNotHasKey(102, $resolved);
    }

    /**
     * An infrastructure failure PROPAGATES; it is not flattened into "there are no images".
     * A broken connection returning an empty map would publish a catalog with every image
     * silently missing and no error raised anywhere.
     */
    public function testADatabaseFailurePropagatesRatherThanResolvingToNothing(): void
    {
        $db = new class implements \HSP\Core\Database\DatabaseConnectionInterface {
            /** @param list<mixed> $params */
            public function execute(string $sql, array $params = []): int
            {
                return 0;
            }

            /**
             * @param  list<mixed> $params
             * @return array<int, array<string, mixed>>
             */
            public function query(string $sql, array $params = []): array
            {
                throw new DatabaseException('connection lost');
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function rollback(): void
            {
            }
        };

        $this->expectException(DatabaseException::class);

        (new MediaReferenceProvider($db))->resolveMany([1]);
    }

    // -------------------------------------------------------------------------
    // Shape
    // -------------------------------------------------------------------------

    /**
     * The capability returns the SAME object Content publishes as a post's `featured_media`.
     * One platform representation of an attachment — asserted against the Resource itself, so a
     * change to one side that is not made to the other fails here.
     */
    public function testTheResolvedObjectIsTheSameShapePostsPublish(): void
    {
        $row = $this->mediaRow(101);
        $this->db->willReturnRows([$row]);
        $resolved = $this->provider->resolveMany([101])[101];

        $postRow = ['slug' => 'p', 'title' => 't', 'content' => 'c', 'status' => 'publish'];
        foreach ($row as $column => $value) {
            $postRow['fm_' . $column] = $value;
        }

        self::assertSame((new PostResource())->toArray($postRow)['featured_media'], $resolved);
        self::assertSame(
            ['slug', 'url', 'alt_text', 'mime_type', 'width', 'height', 'sizes'],
            array_keys($resolved),
        );
    }

    /**
     * Alt text is published exactly as WordPress holds it — empty stays empty. Substituting a
     * title or a product name would be HSP inventing accessibility copy; choosing a fallback is
     * the consumer's presentation decision.
     */
    public function testEmptyAltTextIsPublishedEmptyAndNeverSubstituted(): void
    {
        $this->db->willReturnRows([$this->mediaRow(101, ['alt_text' => ''])]);

        self::assertSame('', $this->provider->resolveMany([101])[101]['alt_text']);
    }

    /** A row with no URL is not a usable image, so it resolves to nothing rather than to a husk. */
    public function testARowWithNoUrlResolvesToNothing(): void
    {
        $this->db->willReturnRows([$this->mediaRow(101, ['url' => ''])]);

        self::assertSame([], $this->provider->resolveMany([101]));
    }

    /**
     * The shaping rule lives in ONE place. It was duplicated verbatim in PostResource and
     * PageResource before this capability existed, and Finding 011 was about to add a third and
     * fourth copy for product featured images and gallery items.
     */
    public function testTheShapingRuleIsSharedBetweenTheJoinAndTheCapability(): void
    {
        $row = $this->mediaRow(101);

        $prefixed = [];
        foreach ($row as $column => $value) {
            $prefixed['fm_' . $column] = $value;
        }

        self::assertSame(
            MediaReference::fromRow($row),
            MediaReference::fromRow($prefixed, 'fm_'),
        );
    }
}
