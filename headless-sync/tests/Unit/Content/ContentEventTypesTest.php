<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\Events\ContentEventTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all nine Content event type constants match the OPEN-1 canon:
 * fully-qualified <domain>.<aggregate>.<action> names.
 *
 * Authority: ARCHITECTURE_DECISIONS.md OPEN-1.
 */
final class ContentEventTypesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // OPEN-1 canon: all nine event types must be present
    // -------------------------------------------------------------------------

    public function test_all_constant_contains_exactly_nine_types(): void
    {
        self::assertCount(9, ContentEventTypes::ALL);
    }

    #[DataProvider('provideExpectedEventTypes')]
    public function test_event_type_matches_open1_canon(string $expectedType): void
    {
        self::assertContains(
            $expectedType,
            ContentEventTypes::ALL,
            "OPEN-1 requires '{$expectedType}' to be registered.",
        );
    }

    /** @return array<string, array{string}> */
    public static function provideExpectedEventTypes(): array
    {
        return [
            'page created'     => ['content.page.created'],
            'page updated'     => ['content.page.updated'],
            'page deleted'     => ['content.page.deleted'],
            'post created'     => ['content.post.created'],
            'post updated'     => ['content.post.updated'],
            'post deleted'     => ['content.post.deleted'],
            'category created' => ['content.category.created'],
            'category updated' => ['content.category.updated'],
            'category deleted' => ['content.category.deleted'],
        ];
    }

    // -------------------------------------------------------------------------
    // OPEN-1 naming format: <domain>.<aggregate>.<action> — three segments
    // -------------------------------------------------------------------------

    #[DataProvider('provideAllEventTypes')]
    public function test_event_type_has_three_dot_segments(string $eventType): void
    {
        $parts = explode('.', $eventType);
        self::assertCount(
            3,
            $parts,
            "Event type '{$eventType}' must follow <domain>.<aggregate>.<action> (OPEN-1).",
        );
    }

    #[DataProvider('provideAllEventTypes')]
    public function test_event_type_domain_is_content(string $eventType): void
    {
        $parts = explode('.', $eventType);
        self::assertSame('content', $parts[0], "Domain segment must be 'content' (OPEN-1).");
    }

    #[DataProvider('provideAllEventTypes')]
    public function test_event_type_aggregate_is_valid(string $eventType): void
    {
        $parts     = explode('.', $eventType);
        $aggregate = $parts[1];
        self::assertContains(
            $aggregate,
            ['page', 'post', 'category'],
            "Aggregate '{$aggregate}' in '{$eventType}' is not in MVP scope.",
        );
    }

    #[DataProvider('provideAllEventTypes')]
    public function test_event_type_action_is_valid(string $eventType): void
    {
        $parts  = explode('.', $eventType);
        $action = $parts[2];
        self::assertContains(
            $action,
            ['created', 'updated', 'deleted'],
            "Action '{$action}' in '{$eventType}' is not a recognised CRUD action.",
        );
    }

    #[DataProvider('provideAllEventTypes')]
    public function test_event_type_contains_no_bare_name(string $eventType): void
    {
        // Bare names (e.g. 'post_created', 'PostCreated') are superseded by OPEN-1.
        self::assertStringContainsString(
            '.',
            $eventType,
            "Event type '{$eventType}' must be fully-qualified — bare names are prohibited (OPEN-1).",
        );
    }

    // -------------------------------------------------------------------------
    // Named constant accessors match the ALL list
    // -------------------------------------------------------------------------

    public function test_named_constants_are_in_all(): void
    {
        self::assertContains(ContentEventTypes::PAGE_CREATED,     ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::PAGE_UPDATED,     ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::PAGE_DELETED,     ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::POST_CREATED,     ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::POST_UPDATED,     ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::POST_DELETED,     ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::CATEGORY_CREATED, ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::CATEGORY_UPDATED, ContentEventTypes::ALL);
        self::assertContains(ContentEventTypes::CATEGORY_DELETED, ContentEventTypes::ALL);
    }

    public function test_all_contains_no_duplicates(): void
    {
        self::assertSame(
            ContentEventTypes::ALL,
            array_values(array_unique(ContentEventTypes::ALL)),
            'ContentEventTypes::ALL must not contain duplicate entries.',
        );
    }

    // -------------------------------------------------------------------------
    // Data providers
    // -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function provideAllEventTypes(): array
    {
        return array_combine(
            ContentEventTypes::ALL,
            array_map(fn(string $t) => [$t], ContentEventTypes::ALL),
        );
    }
}
