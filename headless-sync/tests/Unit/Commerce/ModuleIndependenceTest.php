<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — Commerce must not depend on Content (DECISION AG AG-10, CLAUDE.md Rule 5).
 *
 * `content.media` remains the single projection of WordPress attachment state, so Commerce
 * stores attachment REFERENCES and never duplicates that projection. But the independence
 * rule cuts the other way too: **Commerce product synchronisation must still succeed when the
 * Content media capability is unavailable.** Modules will be enabled and disabled in
 * combinations nobody tested, and a store running Commerce without Content must work.
 *
 * Two forms of coupling would break that, and both are checked as source facts because both
 * are invisible until the exact combination that fails:
 *
 *   - a PHP import of a Content class, which would fatal outright; and
 *   - a query reaching into `content.media`, which would fail at runtime on a database where
 *     the content schema was never migrated — the "hidden required dependency" AG-10 names.
 *
 * If Phase 2 later needs expanded media objects rather than references, AG-10 routes that
 * through a narrow Core-owned capability contract with BULK resolution, designed to tolerate
 * the capability being absent. Nothing here forbids that; it forbids reaching directly.
 */
final class ModuleIndependenceTest extends TestCase
{
    /** @return list<string> Absolute paths of every PHP file in the Commerce module. */
    private function commerceFiles(): array
    {
        $root  = \dirname(__DIR__, 3) . '/modules/Commerce';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testTheScanActuallyReachesTheModule(): void
    {
        // A scan that silently matches nothing proves nothing.
        self::assertGreaterThan(15, count($this->commerceFiles()));
    }

    public function testCommerceImportsNoContentClass(): void
    {
        $offenders = [];

        foreach ($this->commerceFiles() as $path) {
            $contents = (string) file_get_contents($path);

            if (preg_match('/^\s*use\s+HSP\\\\Modules\\\\Content\\\\/m', $contents) === 1) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'a module-to-module import would fatal on a site running Commerce without Content',
        );
    }

    /**
     * No Commerce query may reach into `content.media`. String literals only — the docblocks
     * discuss `content.media` constantly, and prose is not coupling.
     */
    public function testCommerceQueriesNoContentTable(): void
    {
        $offenders = [];

        foreach ($this->commerceFiles() as $path) {
            $contents = (string) file_get_contents($path);

            foreach (token_get_all($contents) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] !== T_CONSTANT_ENCAPSED_STRING && $token[0] !== T_ENCAPSED_AND_WHITESPACE) {
                    continue;
                }

                if (str_contains($token[1], 'content.')) {
                    $offenders[] = basename($path) . ': ' . trim($token[1]);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'reading a sibling module\'s projection is the hidden required dependency AG-10 forbids',
        );
    }

    /**
     * The positive half: product media are carried as plain integer references, which is what
     * lets the module work with no Content module present at all.
     */
    public function testProductMediaAreCarriedAsReferences(): void
    {
        $model = new \HSP\Modules\Commerce\CanonicalModels\CanonicalProduct(
            sourceProductId: 1,
            sku: '',
            slug: 's',
            name: 'n',
            description: '',
            shortDescription: '',
            status: 'publish',
            productType: 'simple',
            catalogVisibility: 'visible',
            featured: false,
            price: null,
            regularPrice: null,
            salePrice: null,
            featuredMediaId: 7,
            galleryMediaIds: [8, 9],
            publishedAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            meta: [],
            categoryIds: [],
            attributeTermIds: [],
        );

        self::assertSame(7, $model->featuredMediaId);
        self::assertSame([8, 9], $model->galleryMediaIds);
    }
}
