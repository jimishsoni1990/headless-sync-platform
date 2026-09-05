<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Adapters;

use HSP\Modules\Content\Adapters\MediaAdapter;
use HSP\Modules\Content\CanonicalModels\CanonicalMedia;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaAdapter (P1B-S1).
 *
 * Query queue layout per persist() call:
 *   slot 0 — fetchExistingRow (pre-txn)
 *   slot 1 — lockAggregateVersion FOR UPDATE (inside txn)
 *
 * Execute layout per persist() call (non-suppressed):
 *   #1 lockAggregateVersion sentinel INSERT   (contains 'aggregate_versions')
 *   #2 upsertMedia                            (contains 'content.media')
 *   #3 insertProcessedEvent                   (contains 'processed_events')
 *   #4 upsertAggregateVersion GREATEST upsert (contains 'aggregate_versions')
 *
 * When the projection is suppressed #2 is absent; #1, #3, #4 still run (DECISION 3 —
 * a suppressed event is still RECORDED).
 */
final class MediaAdapterTest extends TestCase
{
    private FakeDbConnection $db;
    private MediaAdapter     $adapter;
    private FakeAdapterEvent $event;

    protected function setUp(): void
    {
        $this->db      = new FakeDbConnection();
        $this->adapter = new MediaAdapter($this->db);
        $this->event   = new FakeAdapterEvent(
            eventType:        'content.media.updated',
            aggregateType:    'media',
            aggregateId:      '42',
            aggregateVersion: 2,
        );
    }

    public function test_get_canonical_model_class_returns_canonical_media(): void
    {
        self::assertSame(CanonicalMedia::class, $this->adapter->getCanonicalModelClass());
    }

    public function test_persist_commits_all_three_ops_in_one_transaction(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);

        $this->adapter->persist($this->media(), $this->event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);
        self::assertNotContains('rollback', $methods);

        self::assertSame(1, $this->db->countExecuteContaining('content.media'), 'projection upsert');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'), 'processed_events insert');
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert');
    }

    public function test_unchanged_media_performs_zero_projection_writes(): void
    {
        $model = $this->media();

        // Stored checksum equals the freshly computed projection checksum (OPEN-11).
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-0000000000aa', 'checksum' => $model->getChecksum()]],
            [['latest_processed_version' => '0']],
        );

        $this->adapter->persist($model, $this->event);

        self::assertSame(0, $this->db->countExecuteContaining('content.media'), 'projection upsert suppressed');
        // The event is still recorded even though the projection was not touched.
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
        self::assertContains('commit', $this->db->loggedMethods());
    }

    public function test_a_stale_event_version_suppresses_the_projection_write(): void
    {
        // Locked latest_processed_version (5) is ahead of the incoming event version (2).
        $this->db->queueQueryResults([], [['latest_processed_version' => '5']]);

        $this->adapter->persist($this->media(), $this->event);

        self::assertSame(0, $this->db->countExecuteContaining('content.media'));
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
    }

    public function test_tombstone_soft_deletes_with_the_event_source_timestamp(): void
    {
        $event = new FakeAdapterEvent(
            eventType:       'content.media.deleted',
            aggregateType:   'media',
            aggregateId:     '42',
            sourceUpdatedAt: new \DateTimeImmutable('2024-06-01T10:00:00Z'),
        );

        $this->adapter->tombstone('media', '42', $event);

        $update = null;
        foreach ($this->db->log as $entry) {
            if ($entry['method'] === 'execute' && str_contains($entry['sql'], 'UPDATE content.media')) {
                $update = $entry;
            }
        }

        self::assertNotNull($update, 'tombstone issues an UPDATE against content.media');
        // deleted_at must come from the event, not the worker clock — replays must be
        // deterministic.
        self::assertSame('2024-06-01 10:00:00+00', $update['params'][0]);
        self::assertSame(42, $update['params'][1]);
        self::assertContains('commit', $this->db->loggedMethods());
    }

    public function test_persist_rejects_a_canonical_model_of_the_wrong_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->adapter->persist(
            new CanonicalPage(
                postId: 1, title: 't', content: '', slug: 's', status: 'publish',
                parentId: 0, menuOrder: 0,
                publishedAt: new \DateTimeImmutable('2024-01-01T00:00:00Z'),
                modifiedAt: new \DateTimeImmutable('2024-01-01T00:00:00Z'),
                meta: [],
            ),
            $this->event,
        );
    }

    public function test_a_failed_write_rolls_back(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);
        $this->db->failNextExecute();

        try {
            $this->adapter->persist($this->media(), $this->event);
            self::fail('persist() must not swallow a database failure');
        } catch (\Throwable) {
            // expected
        }

        self::assertContains('rollback', $this->db->loggedMethods());
        self::assertNotContains('commit', $this->db->loggedMethods());
    }

    private function media(): CanonicalMedia
    {
        return new CanonicalMedia(
            postId:       42,
            slug:         'sunset',
            title:        'Sunset',
            mimeType:     'image/jpeg',
            url:          'https://example.test/uploads/sunset.jpg',
            altText:      'A sunset over water',
            caption:      'A caption',
            description:  'A description',
            width:        1600,
            height:       900,
            sizes:        ['thumbnail' => ['url' => 'https://example.test/uploads/sunset-150x150.jpg', 'width' => 150, 'height' => 150, 'mime_type' => 'image/jpeg']],
            attachedToId: 7,
            publishedAt:  new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            modifiedAt:   new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            meta:         ['photographer' => 'Ada'],
        );
    }
}
