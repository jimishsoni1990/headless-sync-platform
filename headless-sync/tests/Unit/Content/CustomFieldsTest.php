<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Extractors\ProtectedMeta;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Modules\Content\WpContentLoaderImpl;
use PHPUnit\Framework\TestCase;

/**
 * Basic ACF / custom fields (P1B-S4).
 *
 * ACF stores each field's VALUE under a public meta key and its field-key twin under the
 * `_`-prefixed name, so simple text fields already flowed through `meta_jsonb`. What did not
 * work was everything else, and that is what these tests pin:
 *
 *   - the all-meta form of get_post_meta() does NOT unserialize, so a repeater / gallery /
 *     checkbox value reached the projection as a raw PHP-serialized string and was published
 *     verbatim — a Rule 2 replica and a Rule 6 leak (a consumer would need to parse PHP's
 *     serialization format);
 *   - values were force-cast with (string), so an array published the literal text "Array";
 *   - the checksum sorted only the top level of meta despite its docblock claiming recursion,
 *     which only mattered once meta could nest.
 */
final class CustomFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_post_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_post_meta']);
    }

    // -------------------------------------------------------------------------
    // The loader unserializes — the WP boundary, exercised for real
    // -------------------------------------------------------------------------

    public function test_the_loader_unserializes_structured_values(): void
    {
        // A repeater/gallery value as WordPress actually stores it.
        $GLOBALS['_hsp_stub_post_meta'][7] = [
            'gallery'  => [serialize(['12', '34'])],
            'subtitle' => ['A plain string'],
        ];

        $meta = (new WpContentLoaderImpl())->loadPostMeta(7);

        self::assertSame(['12', '34'], $meta['gallery'], 'structured values arrive as arrays');
        self::assertSame('A plain string', $meta['subtitle'], 'scalars are untouched');
    }

    public function test_the_loader_leaves_a_non_serialized_string_alone(): void
    {
        // A field whose literal value merely looks structured must not be mangled.
        $GLOBALS['_hsp_stub_post_meta'][7] = ['note' => ['a:1 is a great seat']];

        self::assertSame('a:1 is a great seat', (new WpContentLoaderImpl())->loadPostMeta(7)['note']);
    }

    public function test_the_loader_reads_all_meta_in_one_call(): void
    {
        // PERFORMANCE DoD: one read per entity, never one per field. get_post_meta($id) with no
        // key is the all-meta form, so field count cannot multiply the query count.
        $GLOBALS['_hsp_stub_post_meta'][7] = [];
        for ($i = 1; $i <= 50; $i++) {
            $GLOBALS['_hsp_stub_post_meta'][7]['field_' . $i] = ['value ' . $i];
        }

        $meta = (new WpContentLoaderImpl())->loadPostMeta(7);

        self::assertCount(50, $meta);
    }

    // -------------------------------------------------------------------------
    // Publishable shapes
    // -------------------------------------------------------------------------

    public function test_structured_values_survive_as_arrays(): void
    {
        $meta = ProtectedMeta::publicOnly(['gallery' => ['12', '34']]);

        // Before P1B-S4 this published the literal string "Array".
        self::assertSame(['12', '34'], $meta['gallery']);
    }

    public function test_nested_structures_survive(): void
    {
        $rows = [
            ['title' => 'One', 'link' => ['url' => 'https://example.test/1']],
            ['title' => 'Two', 'link' => ['url' => 'https://example.test/2']],
        ];

        self::assertSame($rows, ProtectedMeta::publicOnly(['rows' => $rows])['rows']);
    }

    public function test_scalars_are_unchanged_so_existing_consumers_are_unaffected(): void
    {
        // WordPress stores postmeta as strings, so a "number" field is still the string "5"
        // after unserializing — dropping the (string) cast changes nothing for scalar fields.
        $meta = ProtectedMeta::publicOnly(['count' => '5', 'flag' => '1', 'text' => 'hello']);

        self::assertSame(['count' => '5', 'flag' => '1', 'text' => 'hello'], $meta);
    }

    public function test_objects_are_dropped_not_published(): void
    {
        // maybe_unserialize() can return an object graph; that has no place in a public JSON
        // contract, and would make the adapter's json_encode fail and silently empty ALL meta.
        $meta = ProtectedMeta::publicOnly([
            'good'   => 'kept',
            'object' => new \stdClass(),
        ]);

        self::assertSame(['good' => 'kept'], $meta);
    }

    public function test_a_nested_object_is_dropped_without_losing_its_siblings(): void
    {
        $meta = ProtectedMeta::publicOnly([
            'rows' => ['title' => 'One', 'bad' => new \stdClass()],
        ]);

        self::assertSame(['rows' => ['title' => 'One']], $meta);
    }

    public function test_protected_meta_is_still_stripped(): void
    {
        // REGRESSION (AUDIT-ONB-OPSC-S1): _edit_lock / _edit_last disclose who edited a post and
        // when. Widening the value types must not widen WHICH keys are published. ACF's own
        // field-key twins (_subtitle => field_abc123) are stripped by the same rule.
        $meta = ProtectedMeta::publicOnly([
            '_edit_lock'   => '1699999999:1',
            '_edit_last'   => '1',
            '_thumbnail_id' => '42',
            '_subtitle'    => 'field_abc123',
            'subtitle'     => 'Published',
        ]);

        self::assertSame(['subtitle' => 'Published'], $meta);
    }

    // -------------------------------------------------------------------------
    // The write-suppress trap, once more
    // -------------------------------------------------------------------------

    public function test_changing_a_custom_field_moves_the_checksum(): void
    {
        $a = $this->canonicalWithMeta(['subtitle' => 'Before']);
        $b = $this->canonicalWithMeta(['subtitle' => 'After']);

        self::assertNotSame(
            $a->getChecksum(),
            $b->getChecksum(),
            'an ACF value change must move the checksum, or DECISION 3 suppresses the write.',
        );
    }

    public function test_changing_a_value_deep_inside_a_repeater_moves_the_checksum(): void
    {
        $a = $this->canonicalWithMeta(['rows' => [['title' => 'One']]]);
        $b = $this->canonicalWithMeta(['rows' => [['title' => 'Two']]]);

        self::assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_the_checksum_ignores_nested_key_order(): void
    {
        // The docblock always claimed recursive ksort; until P1B-S4 only the top level was
        // sorted. WordPress does not guarantee nested key order, so without recursion two
        // identical repeater values could hash differently and the projection would churn.
        $a = $this->canonicalWithMeta(['rows' => ['alpha' => '1', 'beta' => '2']]);
        $b = $this->canonicalWithMeta(['rows' => ['beta' => '2', 'alpha' => '1']]);

        self::assertSame($a->getChecksum(), $b->getChecksum());
    }

    // -------------------------------------------------------------------------
    // ACF absent is the normal case, not an error
    // -------------------------------------------------------------------------

    public function test_a_post_with_no_custom_fields_extracts_exactly_as_before(): void
    {
        // ACF is not installed in this suite — which IS the test. Extraction must behave
        // identically and never reach for an ACF function.
        $source = (new PostExtractor(new PostValidator()))->extract($this->rawPost(), []);

        self::assertSame([], $source->meta);
    }

    public function test_no_acf_function_is_called_anywhere_in_the_content_module(): void
    {
        // The projection reads postmeta directly, so an absent ACF plugin cannot fatal the
        // pipeline. A get_field()/have_rows() call would make ACF a hard dependency.
        $offenders = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../modules/Content')
        );

        foreach ($dir as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(get_field|have_rows|the_field|get_sub_field)\s*\(/', $code) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'ACF must never be a hard dependency of the pipeline');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $meta */
    private function canonicalWithMeta(array $meta): CanonicalPost
    {
        return (new PostTransformer())->transform(
            (new PostExtractor(new PostValidator()))->extract($this->rawPost(), $meta)
        );
    }

    /** @return array<string,mixed> */
    private function rawPost(): array
    {
        return [
            'ID' => 7, 'post_title' => 'A Post', 'post_content' => '', 'post_excerpt' => '',
            'post_name' => 'a-post', 'post_status' => 'publish', 'post_type' => 'post',
            'post_author' => '1', 'post_date_gmt' => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-01-01 00:00:00',
        ];
    }
}
