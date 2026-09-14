<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Delivery;

use HSP\Core\Delivery\JsonMap;
use PHPUnit\Framework\TestCase;

/**
 * The empty-map serialization rule.
 *
 * PHP cannot distinguish an empty list from an empty map — both are `[]` — so every Resource that
 * decoded an empty JSON map published `"meta": []` where the contract says `{}`. These tests pin
 * the JSON that actually goes over the wire, because asserting on the PHP value would miss the
 * whole point: the bug lives in json_encode, not in the array.
 */
final class JsonMapTest extends TestCase
{
    /** The defect: an empty map must encode as `{}`, never `[]`. */
    public function test_an_empty_map_encodes_as_a_json_object(): void
    {
        foreach ([null, '', '{}', '[]', 'not json', []] as $empty) {
            $json = json_encode(['meta' => JsonMap::decode($empty)], JSON_THROW_ON_ERROR);

            self::assertSame(
                '{"meta":{}}',
                $json,
                'empty input ' . var_export($empty, true) . ' must publish {} not []',
            );
        }
    }

    /** A populated map keeps its keys and still encodes as an object. */
    public function test_a_populated_map_keeps_its_entries(): void
    {
        $decoded = JsonMap::decode('{"seo_title":"Hello","hits":3}');

        self::assertSame(['seo_title' => 'Hello', 'hits' => 3], $decoded);
        self::assertSame(
            '{"meta":{"seo_title":"Hello","hits":3}}',
            json_encode(['meta' => $decoded], JSON_THROW_ON_ERROR),
        );
    }

    /** Array access is preserved for a populated map, so callers and tests read it normally. */
    public function test_a_populated_map_is_still_an_array_for_callers(): void
    {
        $decoded = JsonMap::decode('{"seo_title":"Hello"}');

        self::assertIsArray($decoded);
        self::assertSame('Hello', $decoded['seo_title']);
    }

    /** An already-decoded array is accepted, not only a JSON string. */
    public function test_accepts_an_already_decoded_array(): void
    {
        self::assertSame(['a' => 1], JsonMap::decode(['a' => 1]));
    }

    /**
     * A JSON LIST under a field whose contract is a map is published as `{}` rather than as the
     * wrong JSON type — publishing a list where the schema promises an object is the bug, not a
     * thing to pass through.
     */
    public function test_a_list_is_not_published_under_a_map_contract(): void
    {
        self::assertSame(
            '{"meta":{}}',
            json_encode(['meta' => JsonMap::decode('["a","b"]')], JSON_THROW_ON_ERROR),
        );
    }
}
