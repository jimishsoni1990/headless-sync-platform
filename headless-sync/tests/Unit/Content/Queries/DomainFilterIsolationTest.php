<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Queries\MediaQueryProvider;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P2-S1 acceptance — domain-neutral filter contract (DECISION AG AG-5).
 *
 * Core used to own a `final` FilterSet whose docblock claimed modules extend it and four of
 * whose seven fields were content-domain. Commerce filters — price range, stock status, SKU —
 * had nowhere to live but appended fields on that core class.
 *
 * Two things must now hold, and the second is the one that prevents a silent bug: each
 * domain's provider accepts the CORE contract (so a second module can implement it) and
 * REJECTS a filter belonging to another domain, rather than reading whichever properties
 * happen to exist and returning plausible nonsense.
 */
final class DomainFilterIsolationTest extends TestCase
{
    /** @return list<array{0:string,1:object}> */
    public static function providers(): array
    {
        $db = new FakeDbConnection();

        return [
            'posts'      => ['posts', new PostQueryProvider($db)],
            'pages'      => ['pages', new PageQueryProvider($db)],
            'categories' => ['categories', new CategoryQueryProvider($db)],
            'media'      => ['media', new MediaQueryProvider($db)],
        ];
    }

    /**
     * The whole point of AG-5: a filter from another domain is refused loudly.
     *
     */
    #[DataProvider("providers")]
    public function testProviderRejectsAForeignDomainFilter(string $name, object $provider): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires a .*ContentFilterSet/');

        /** @psalm-suppress MixedMethodCall */
        $provider->list(new SecondDomainFilterSet());
    }

    #[DataProvider("providers")]
    public function testProviderAcceptsItsOwnDomainFilter(string $name, object $provider): void
    {
        // Acceptance is the subject, not query behaviour — the fake connection returns
        // nothing, so only the absence of a rejection matters here.
        /** @psalm-suppress MixedMethodCall */
        $page = $provider->list(new ContentFilterSet());

        self::assertInstanceOf(\HSP\Core\Contracts\CursorPage::class, $page);
    }

    /**
     * The core contract must carry ONLY the pagination envelope. If a domain field ever
     * reappears on it, Commerce inherits Content's vocabulary again and AG-5 has been undone.
     */
    public function testCoreContractExposesOnlyThePaginationEnvelope(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(QueryFilterInterface::class))->getMethods(),
        );

        sort($methods);

        self::assertSame(['cursor', 'limit'], $methods);
    }

    public function testContentFilterSetSatisfiesTheCoreContract(): void
    {
        $filter = new ContentFilterSet(cursor: 'abc', limit: 25);

        self::assertInstanceOf(QueryFilterInterface::class, $filter);
        self::assertSame('abc', $filter->cursor());
        self::assertSame(25, $filter->limit());
    }

    /**
     * Existing construction must keep working — P1B-S3 appended `tagSlug` as a trailing
     * optional parameter precisely so no caller broke, and moving the class must not undo
     * that (DECISION F).
     */
    public function testExistingPositionalAndNamedConstructionStillWorks(): void
    {
        $positional = new ContentFilterSet('slug', 'publish', 'news', null, null, 10, 'featured');
        $named      = new ContentFilterSet(slug: 'slug', categorySlug: 'news', tagSlug: 'featured');

        self::assertSame('news', $positional->categorySlug);
        self::assertSame('featured', $positional->tagSlug);
        self::assertSame('featured', $named->tagSlug);
    }
}

/** A second domain's filter — no Content vocabulary, exactly as Commerce's will be. */
final class SecondDomainFilterSet implements QueryFilterInterface
{
    public function __construct(
        public readonly ?string $sku   = null,
        public readonly ?int    $limit = null,
    ) {}

    public function cursor(): ?string { return null; }
    public function limit(): ?int { return $this->limit; }
}
