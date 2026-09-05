<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\UI;

use HSP\Core\Contracts\Operations\ConsoleAction;
use HSP\Core\Operations\UI\ActionsView;
use PHPUnit\Framework\TestCase;

/**
 * ActionsView — the console's Replay/Reconcile controls (DECISION V (d)).
 *
 * The gap this closes: the `hsp_ops_action` endpoint was registered and fully working, but no
 * markup ever invoked it, so the two ratified actions were unreachable from the console.
 *
 * Proves: the action SET is discovered from the registry (never hardcoded); each action's field
 * names match exactly the parameter keys ConsoleActionController whitelists; the wiring values the
 * client needs are emitted; and every dynamic value is escaped at output (DECISION V (b)).
 */
final class ActionsViewTest extends TestCase
{
    private ActionsView $view;

    protected function setUp(): void
    {
        $this->view = new ActionsView();
    }

    /** @param ConsoleAction[] $actions */
    private function render(array $actions): string
    {
        return $this->view->render($actions, 'https://example.test/wp-admin/admin-ajax.php', 'n0nce', 'hsp_ops_action');
    }

    private function ratified(): array
    {
        return [
            new ConsoleAction('replay', 'Replay', 'manage_options'),
            new ConsoleAction('reconcile', 'Reconcile', 'manage_options'),
        ];
    }

    public function test_renders_the_wiring_the_client_needs(): void
    {
        $html = $this->render($this->ratified());

        self::assertStringContainsString('data-ajax-url="https://example.test/wp-admin/admin-ajax.php"', $html);
        self::assertStringContainsString('data-nonce="n0nce"', $html);
        self::assertStringContainsString('data-action="hsp_ops_action"', $html);
    }

    public function test_renders_a_form_per_registered_action(): void
    {
        $html = $this->render($this->ratified());

        self::assertStringContainsString('data-op-action="replay"', $html);
        self::assertStringContainsString('data-op-action="reconcile"', $html);
        self::assertSame(2, substr_count($html, 'class="hsp-ops-action"'));
    }

    /** The set is discovered, so an empty registry renders nothing at all — not an empty panel. */
    public function test_renders_nothing_without_registered_actions(): void
    {
        self::assertSame('', $this->render([]));
    }

    /** An action that is not registered gets no control, however the endpoint behaves. */
    public function test_only_registered_actions_are_rendered(): void
    {
        $html = $this->render([new ConsoleAction('reconcile', 'Reconcile', 'manage_options')]);

        self::assertStringContainsString('data-op-action="reconcile"', $html);
        self::assertStringNotContainsString('data-op-action="replay"', $html);
    }

    /**
     * Field names must stay in lockstep with ConsoleActionController::sanitizeParams(), which
     * whitelists exactly these keys — anything else the form posted would be silently dropped.
     */
    public function test_reconcile_fields_match_the_whitelisted_parameters(): void
    {
        $html = $this->render([new ConsoleAction('reconcile', 'Reconcile', 'manage_options')]);

        self::assertStringContainsString('name="mode"', $html);
        self::assertStringContainsString('value="drift"', $html);
        self::assertStringContainsString('value="incremental"', $html);
        self::assertStringContainsString('value="full"', $html);
        self::assertStringContainsString('name="dry_run"', $html);
    }

    /** Dry-run is pre-checked: detection is the click an operator should reach first. */
    public function test_reconcile_defaults_to_dry_run(): void
    {
        $html = $this->render([new ConsoleAction('reconcile', 'Reconcile', 'manage_options')]);

        self::assertMatchesRegularExpression('/name="dry_run"[^>]*checked/', $html);
    }

    public function test_replay_fields_match_the_whitelisted_parameters(): void
    {
        $html = $this->render([new ConsoleAction('replay', 'Replay', 'manage_options')]);

        foreach (['mode', 'aggregate_type', 'aggregate_id', 'from', 'to'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $html);
        }
        self::assertStringContainsString('value="entity"', $html);
        self::assertStringContainsString('value="range"', $html);
    }

    public function test_confirmation_requirement_is_carried_to_the_client(): void
    {
        $html = $this->render([
            new ConsoleAction('reconcile', 'Reconcile', 'manage_options', confirmationRequired: false),
        ]);

        self::assertStringContainsString('data-confirm="0"', $html);
    }

    /** Escape at output (DECISION V (b)) — a descriptor value never breaks out of its attribute. */
    public function test_escapes_dynamic_values(): void
    {
        $html = $this->view->render(
            [new ConsoleAction('reconcile', '<script>alert(1)</script>', 'manage_options')],
            'https://example.test/x?a=1&b=2',
            '"><script>',
            'hsp_ops_action',
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('data-nonce=""><script>', $html);
    }
}
