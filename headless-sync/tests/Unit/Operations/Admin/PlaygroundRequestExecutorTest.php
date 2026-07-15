<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Admin;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Operations\Admin\PlaygroundRequestExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlaygroundRequestExecutor (Doc 12 §15 Request Execution; ADR-050).
 *
 * The executor dispatches a live GET against a PUBLISHED endpoint selected by index into the
 * EndpointProviderInterface metadata — never a raw client-supplied route. It is GET-only
 * (read-only console — DECISION V). `rest_do_request` is stubbed (tests/bootstrap.php) so the
 * dispatched WP_REST_Request can be captured and asserted without loading WordPress.
 */
final class PlaygroundRequestExecutorTest extends TestCase
{
    private PlaygroundRequestExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new PlaygroundRequestExecutor();

        // Capture the dispatched request and echo a canned response.
        $GLOBALS['_hsp_stub_rest_do_request'] = static function (\WP_REST_Request $req): \WP_REST_Response {
            $GLOBALS['_hsp_last_rest_request'] = $req;

            return new \WP_REST_Response(['ok' => true, 'route' => $req->get_route()], 200);
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_rest_do_request'], $GLOBALS['_hsp_last_rest_request']);
    }

    /** @return EndpointDescriptor[] */
    private function endpoints(): array
    {
        return [
            new EndpointDescriptor('GET', '/posts', 'hsp/v1', 'Content', 'List posts.'),
            new EndpointDescriptor('GET', '/posts/{slug}', 'hsp/v1', 'Content', 'One post.'),
        ];
    }

    public function test_dispatches_a_list_endpoint_and_returns_status_path_body(): void
    {
        $result = $this->executor->execute($this->endpoints(), 0, '', ['limit' => '5']);

        self::assertSame(200, $result['status']);
        self::assertSame('/hsp/v1/posts', $result['path']);
        self::assertSame(['ok' => true, 'route' => '/hsp/v1/posts'], $result['body']);

        /** @var \WP_REST_Request $req */
        $req = $GLOBALS['_hsp_last_rest_request'];
        self::assertSame('GET', $req->get_method());
        self::assertSame('5', $req->get_param('limit'));
    }

    public function test_substitutes_the_slug_placeholder(): void
    {
        $result = $this->executor->execute($this->endpoints(), 1, 'hello-world');

        self::assertSame('/hsp/v1/posts/hello-world', $result['path']);
    }

    public function test_rejects_an_out_of_range_index(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown endpoint selection.');

        $this->executor->execute($this->endpoints(), 99);
    }

    public function test_rejects_a_non_get_endpoint(): void
    {
        // Defence in depth: even if a non-GET descriptor were registered, execution is refused.
        $endpoints = [new EndpointDescriptor('POST', '/posts', 'hsp/v1', 'Content', 'create')];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only GET endpoints');

        $this->executor->execute($endpoints, 0);
    }
}
