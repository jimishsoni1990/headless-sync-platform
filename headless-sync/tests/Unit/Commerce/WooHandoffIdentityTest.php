<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use PHPUnit\Framework\TestCase;

/**
 * Finding 009 — the WooCommerce cart-handoff identifiers.
 *
 * HSP is the catalogue; WooCommerce stays the transactional authority. A storefront that browses
 * a product here and then hands it to a native Woo cart needs the ids Woo itself accepts:
 * `WC_Cart::add_to_cart($product_id, $qty, $variation_id, $variation)` (verified against
 * WooCommerce 11.1.0, `class-wc-cart.php:1149`), the `?add-to-cart=` + `variation_id` form
 * handler, and the Store API. Those values were already in the projection and already published
 * as `source_id` / `product_id` — generic names a consumer could only interpret by reading HSP's
 * internals. These tests pin the explicit contract instead.
 *
 * The values below are deliberately spread apart — the Woo ids share no digits with the
 * projection uuid, the slug, the sku or the checksum — so an assertion cannot pass by accident on
 * a coincidence of numbers.
 */
final class WooHandoffIdentityTest extends TestCase
{
    /** A simple product: one Woo id, and no variation identity invented for it. */
    public function test_a_simple_product_publishes_its_woo_product_id(): void
    {
        $published = (new ProductResource())->toArray(self::productRow(123, 'simple'));

        self::assertSame(123, $published['woo_product_id']);

        // NOT a nullable or zeroed variation field bolted onto a resource that has no variation.
        self::assertArrayNotHasKey('woo_variation_id', $published);
    }

    /**
     * A variable parent publishes the same explicit field — the id a cart handoff needs
     * ALONGSIDE the chosen variation, not instead of it.
     */
    public function test_a_variable_parent_publishes_its_woo_product_id(): void
    {
        $published = (new ProductResource())->toArray(self::productRow(200, 'variable'));

        self::assertSame(200, $published['woo_product_id']);
        self::assertSame('variable', $published['type']);
        self::assertArrayNotHasKey('woo_variation_id', $published);
    }

    /**
     * The published id is the SOURCE identity, not any HSP-side identity that happens to sit on
     * the same row. Every other identifier in the fixture carries digits of its own, and none of
     * them is what comes out.
     */
    public function test_the_woo_product_id_is_not_derived_from_any_hsp_identity(): void
    {
        $row             = self::productRow(123, 'simple');
        $row['id']       = '00000777-0000-7000-8000-000000000999';
        $row['slug']     = 'hat-456';
        $row['sku']      = 'SKU-789';
        $row['checksum'] = str_repeat('5', 64);

        $published = (new ProductResource())->toArray($row);

        self::assertSame(123, $published['woo_product_id']);

        // Not the uuid, not a digit-bearing slug, not the sku, not the checksum.
        self::assertNotSame($published['id'], (string) $published['woo_product_id']);
        self::assertNotSame('456', (string) $published['woo_product_id']);
        self::assertNotSame('789', (string) $published['woo_product_id']);
        self::assertArrayNotHasKey('checksum', $published);
    }

    /**
     * The pair a variable-product handoff needs, and the reason they are two fields: Woo takes the
     * parent id and the variation id in different argument positions, so a single overloaded id
     * would silently add the wrong thing to the cart.
     */
    public function test_a_variation_publishes_both_its_own_woo_id_and_its_parents(): void
    {
        $published = (new VariationResource())->toArray(self::variationRow(245, 200));

        self::assertSame(200, $published['woo_product_id']);
        self::assertSame(245, $published['woo_variation_id']);
    }

    /**
     * The swap test. Both values are integers on the same row, so a transposed mapping type-checks
     * perfectly and would otherwise only surface in a live cart.
     */
    public function test_the_parent_and_variation_ids_cannot_be_read_the_wrong_way_round(): void
    {
        $published = (new VariationResource())->toArray(self::variationRow(245, 200));

        self::assertNotSame(
            $published['woo_product_id'],
            $published['woo_variation_id'],
            'a variation and its parent are different Woo entities',
        );
        self::assertNotSame(245, $published['woo_product_id'], 'parent field holding the variation id');
        self::assertNotSame(200, $published['woo_variation_id'], 'variation field holding the parent id');

        // And each value tracks its own column: re-parenting moves one field and not the other.
        $reparented = (new VariationResource())->toArray(self::variationRow(245, 311));
        self::assertSame(311, $reparented['woo_product_id']);
        self::assertSame(245, $reparented['woo_variation_id']);
    }

    /** Siblings share one parent id and keep distinct variation ids. */
    public function test_two_variations_of_one_product_share_the_parent_id_only(): void
    {
        $resource = new VariationResource();

        $first  = $resource->toArray(self::variationRow(245, 200));
        $second = $resource->toArray(self::variationRow(246, 200));

        self::assertSame(200, $first['woo_product_id']);
        self::assertSame(200, $second['woo_product_id']);
        self::assertSame(245, $first['woo_variation_id']);
        self::assertSame(246, $second['woo_variation_id']);
    }

    /**
     * Additive, not a rename. `source_id` and `product_id` are existing published fields and
     * removing one is a separate compatibility decision (Doc 9 §26); they carry the same values as
     * before, and the new fields alias them rather than replacing them.
     */
    public function test_the_existing_identity_fields_are_unchanged(): void
    {
        $product   = (new ProductResource())->toArray(self::productRow(123, 'simple'));
        $variation = (new VariationResource())->toArray(self::variationRow(245, 200));

        self::assertSame(123, $product['source_id']);
        self::assertSame(245, $variation['source_id']);
        self::assertSame(200, $variation['product_id']);
    }

    /** No cart, checkout or URL crept in alongside the identifiers. */
    public function test_no_woo_url_or_cart_field_is_published(): void
    {
        $published = array_merge(
            (new ProductResource())->toArray(self::productRow(123, 'simple')),
            (new VariationResource())->toArray(self::variationRow(245, 200)),
        );

        $forbidden = [
            'add_to_cart_url', 'cart_url', 'checkout_url', 'woo_url', 'wordpress_url',
            'permalink', 'url', 'nonce',
        ];

        foreach ($forbidden as $field) {
            self::assertArrayNotHasKey($field, $published);
        }
    }

    // -------------------------------------------------------------------------
    // The generated contract (ADR-055)
    // -------------------------------------------------------------------------

    /**
     * A generated client must see a concrete integer field rather than infer meaning from a
     * generic id — which is the whole point of the finding.
     */
    public function test_the_generated_schema_publishes_concrete_woo_identity_fields(): void
    {
        $product   = self::itemProperties('/products/{slug}');
        $variation = self::itemProperties('/products/{slug}/variations');

        self::assertSame('integer', $product['woo_product_id']['type']);
        self::assertSame('integer', $variation['woo_product_id']['type']);
        self::assertSame('integer', $variation['woo_variation_id']['type']);

        // A WordPress post id is a positive integer, and the projection column is BIGINT NOT NULL
        // with a UNIQUE constraint — so the bound is a guarantee the data actually keeps.
        self::assertSame(1, $product['woo_product_id']['minimum']);
        self::assertSame(1, $variation['woo_product_id']['minimum']);
        self::assertSame(1, $variation['woo_variation_id']['minimum']);

        // A product resource carries no variation identity at all.
        self::assertArrayNotHasKey('woo_variation_id', $product);
    }

    /**
     * The names alone would still leave a consumer guessing WHICH id — parent or variation — so
     * the descriptions carry the semantics, and the drift guard ships them into `openapi.json`.
     */
    public function test_the_descriptions_state_the_woocommerce_semantics(): void
    {
        $product   = self::itemProperties('/products/{slug}');
        $variation = self::itemProperties('/products/{slug}/variations');

        self::assertStringContainsString('WooCommerce product id', $product['woo_product_id']['description']);
        self::assertStringContainsString('cart', $product['woo_product_id']['description']);

        // The parent/variation distinction is explicit on the variation, not left to the name.
        self::assertStringContainsString('PARENT', $variation['woo_product_id']['description']);
        self::assertStringContainsString(
            'WooCommerce variation id',
            $variation['woo_variation_id']['description'],
        );

        // Site-scoped, never advertised as globally unique — no cross-site identity federation.
        $siteScoped = [
            $product['woo_product_id'],
            $variation['woo_product_id'],
            $variation['woo_variation_id'],
        ];

        foreach ($siteScoped as $field) {
            self::assertStringContainsString('Site-specific', $field['description']);
        }

        // The generic fields now point at the explicit ones instead of sitting undocumented.
        self::assertStringContainsString('woo_product_id', $product['source_id']['description']);
        self::assertStringContainsString('woo_variation_id', $variation['source_id']['description']);
        self::assertStringContainsString('woo_product_id', $variation['product_id']['description']);
    }

    /**
     * DECISION AK-9. The legacy fields are retained for compatibility, and documenting them as the
     * Woo handoff contract would reintroduce exactly the ambiguity this work exists to remove —
     * `source_id` names a term id on /product-categories and an attribute-definition id on
     * /product-attributes, so the name cannot carry Woo semantics. They must read as legacy
     * pointers to the explicit fields, never as the recommended way to hand an item to Woo.
     */
    public function test_the_legacy_fields_are_not_documented_as_the_woo_handoff_contract(): void
    {
        $product   = self::itemProperties('/products/{slug}');
        $variation = self::itemProperties('/products/{slug}/variations');

        $legacy = [
            'product.source_id'   => $product['source_id']['description'],
            'variation.source_id' => $variation['source_id']['description'],
            'variation.product_id' => $variation['product_id']['description'],
        ];

        foreach ($legacy as $field => $description) {
            self::assertStringContainsString('Legacy', $description, "{$field} must read as legacy");
            self::assertStringContainsString(
                'NOT the WooCommerce interoperability contract',
                $description,
                "{$field} must disclaim the handoff contract",
            );
            // Never "the authoritative WooCommerce … id" — that phrasing belongs to woo_* alone.
            self::assertStringNotContainsString('Authoritative WooCommerce', $description, $field);
        }
    }

    /**
     * DECISION AK-3. The Woo ids are handoff fields, never addressing: HSP resources stay
     * slug-addressed, and an id-addressed product route is prohibited. Asserted on the descriptors
     * because that is what generates the published route list.
     */
    public function test_hsp_addressing_stays_slug_based_and_gains_no_id_route(): void
    {
        $routes = array_map(
            static fn (EndpointDescriptor $e): string => $e->route,
            (new CommerceEndpointProvider())->endpoints(),
        );

        self::assertContains('/products/{slug}', $routes);
        self::assertContains('/products/{slug}/variations', $routes);

        foreach ($routes as $route) {
            self::assertStringNotContainsString('{woo_product_id}', $route);
            self::assertStringNotContainsString('{woo_variation_id}', $route);
            self::assertStringNotContainsString('{id}', $route);
            self::assertStringNotContainsString('{source_id}', $route);
        }
    }

    /**
     * DECISION AK-5. The ruling authorises an identifier and nothing else: no cart, checkout or
     * add-to-cart route may appear on the Commerce surface as a side effect of publishing it.
     */
    public function test_no_cart_checkout_or_add_to_cart_route_exists(): void
    {
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            foreach (['cart', 'checkout', 'add-to-cart', 'session', 'order'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $endpoint->route,
                    "Commerce must publish no {$forbidden} route (DECISION AK-5).",
                );
            }
        }
    }

    /** The fields reach the generated document, not merely the descriptor object. */
    public function test_the_fields_reach_the_generated_openapi_document(): void
    {
        $document = (new OpenApiGenerator())->generate((new CommerceEndpointProvider())->endpoints());
        $json     = json_encode($document);

        self::assertIsString($json);
        self::assertStringContainsString('woo_product_id', $json);
        self::assertStringContainsString('woo_variation_id', $json);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function productRow(int $sourceId, string $type): array
    {
        return [
            'id'                => 'b2f1c0de-0000-7000-8000-000000000001',
            'source_product_id' => $sourceId,
            'sku'               => 'hat-sku',
            'slug'              => 'hat',
            'name'              => 'Hat',
            'product_type'      => $type,
            'gallery_media_ids' => '[]',
            'meta_jsonb'        => '{}',
        ];
    }

    /** @return array<string,mixed> */
    private static function variationRow(int $variationId, int $parentId): array
    {
        return [
            'id'                  => 'b2f1c0de-0000-7000-8000-000000000002',
            'source_variation_id' => $variationId,
            'source_parent_id'    => $parentId,
            'sku'                 => 'hat-blue',
            'name'                => 'Hat - Blue',
            'status'              => 'publish',
            'attributes'          => '{"pa_colour":"blue"}',
        ];
    }

    /**
     * The item schema of one endpoint, unwrapped from the cursor envelope for list routes.
     *
     * @return array<string,mixed>
     */
    private static function itemProperties(string $route): array
    {
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            if ($endpoint->route !== $route) {
                continue;
            }

            $schema = $endpoint->responseSchema?->schema ?? [];

            /** @var array<string,mixed> $properties */
            $properties = $endpoint->paginated
                ? $schema['properties']['data']['items']['properties']
                : $schema['properties'];

            return $properties;
        }

        self::fail("No descriptor for {$route}.");
    }
}
