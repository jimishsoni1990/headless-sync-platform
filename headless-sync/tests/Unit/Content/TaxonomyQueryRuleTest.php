<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;

/**
 * DECISION AA (FLAG-TAXSCHEMA-1) query rule, enforced.
 *
 * Categories, tags and every future Content taxonomy share ONE projection — content.taxonomies —
 * told apart by taxonomy_type. The ruling's whole cost is a single rule: a read of that table
 * must identify BOTH the taxonomy type and the term identity. A read that names only the term
 * (`WHERE slug = 'news'`) silently returns whichever taxonomy sorts first.
 *
 * That is not hypothetical. The same forgotten predicate produced three defects in P1B-S3
 * (`?category=` matching a tag; `/categories/{slug}` resolving a tag) and two more found while
 * applying this ruling (the console's category metric counting tags; the onboarding backfill's
 * projected category count counting tags, which could declare convergence early). A shared table
 * makes that bug class possible — this test is what makes it detectable.
 *
 * Scanned via token_get_all() rather than grep so COMMENTS — which discuss the table constantly —
 * are excluded and only real string literals are judged. The unit is the FILE, not the individual
 * literal, because the SQL is legitimately assembled across literals (CategoryQueryProvider::list
 * builds its predicate list with sprintf, so `content.taxonomies` and `taxonomy_type` land in
 * different tokens). A file that touches the shared table and never mentions the discriminator is
 * the shape every one of the five defects above had.
 *
 * Files keyed only on source_term_id are exempt: a WordPress term id is unique across taxonomies,
 * so identity there is already unambiguous. Migrations are exempt: DDL names the table, never
 * filters on it.
 */
final class TaxonomyQueryRuleTest extends TestCase
{
    private const TABLE = 'content.taxonomies';

    /** Roots that may query the shared taxonomy projection. */
    private const ROOTS = ['/modules/Content', '/core'];

    public function test_every_file_querying_the_shared_taxonomy_table_identifies_the_taxonomy(): void
    {
        $offenders = [];

        foreach ($this->readSites() as $path => $literals) {
            $sql = implode("\n", $literals);
            if (str_contains($sql, 'taxonomy_type') || str_contains($sql, 'source_term_id')) {
                continue;
            }
            $offenders[] = basename($path);
        }

        self::assertSame(
            [],
            $offenders,
            'Every read of content.taxonomies must carry taxonomy_type (or key on the globally '
            . 'unique source_term_id) — DECISION AA query rule.'
        );
    }

    /** The scan must actually reach the known read sites, or it proves nothing. */
    public function test_the_scan_reaches_the_real_read_sites(): void
    {
        $found = array_map('basename', array_keys($this->readSites()));

        foreach ([
            'CategoryQueryProvider.php',   // /categories/{slug} + /tags/{slug}
            'PostQueryProvider.php',       // ?category= / ?tag= filters and the tags array
            'ContentMetricsProvider.php',  // operations console counts
            'ReconciliationService.php',   // orphan sweep, which lists rows BY TABLE
            'BackfillReader.php',          // onboarding progress counts
        ] as $expected) {
            self::assertContains($expected, $found, "scan missed {$expected}");
        }
    }

    /**
     * Every non-migration PHP file under the roots whose STRING LITERALS mention the shared
     * taxonomy table, mapped to those literals.
     *
     * @return array<string, list<string>>
     */
    private function readSites(): array
    {
        $sites = [];

        foreach (self::ROOTS as $root) {
            $dir = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(__DIR__ . '/../../..' . $root)
            );

            foreach ($dir as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains($file->getPathname(), 'Migrations')) {
                    continue;
                }

                $literals = $this->stringLiterals((string) file_get_contents($file->getPathname()));
                $hits     = array_values(array_filter(
                    $literals,
                    static fn (string $s): bool => str_contains($s, self::TABLE)
                ));

                if ($hits !== []) {
                    // Keep every literal in the file: the predicate may live in a sibling token.
                    $sites[$file->getPathname()] = $literals;
                }
            }
        }

        return $sites;
    }

    /** @return list<string> */
    private function stringLiterals(string $code): array
    {
        $literals = [];

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literals[] = $token[1];
            }
        }

        return $literals;
    }
}
