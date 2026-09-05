<?php

declare(strict_types=1);

namespace HSP\Core\Operations\UI;

use HSP\Core\Contracts\Operations\ConsoleAction;

/**
 * Pure server-side renderer for the console's operational actions (DECISION V (d); OPSC-S4).
 *
 * The Replay and Reconcile endpoint (`wp_ajax_hsp_ops_action`) was registered, guarded and fully
 * working, but nothing rendered a control that called it and the console JS had no client for it —
 * so the two ratified actions DECISION V (d) requires were unreachable from the console. This view
 * is that missing surface.
 *
 * The action SET is discovered, never hardcoded: it renders whatever OperationsService reports from
 * the Action Registry, so an action that is not registered has no control (and OperationsActionService
 * rejects any key but 'replay' and 'reconcile' regardless — DECISION V (e)/(f): no Flush Queue, no
 * Restart Workers). Each action's own parameter shape is inherent to the ratified strategy behind
 * it: Reconcile takes a mode plus a dry-run flag (DECISION U), Replay takes entity or range
 * coordinates (DECISION T). An unrecognized key still renders, with the common mode field only.
 *
 * Dry-run defaults to CHECKED on Reconcile: the safe reading is the one an operator should reach
 * first, and a repair pass is opt-in rather than the default click.
 *
 * PURE, like the other views (ADR-053): it takes descriptors plus plain wiring values and returns
 * escaped HTML. It performs no I/O, invokes nothing, and every dynamic value goes through
 * {@see Html} (DECISION V (b)). The confirmation each descriptor requires is enforced server-side
 * by ConsoleActionController; the `data-confirm` attribute below only drives the client prompt.
 */
final class ActionsView
{
    /**
     * @param ConsoleAction[] $actions the registered actions (from OperationsService)
     * @param string $ajaxUrl the admin-ajax endpoint the vanilla JS posts to (already a URL)
     * @param string $nonce   the nonce the JS sends with each invoke request
     * @param string $action  the wp_ajax action name for invocation
     */
    public function render(array $actions, string $ajaxUrl, string $nonce, string $action): string
    {
        if ($actions === []) {
            return '';
        }

        $html  = '<section class="hsp-ops-actions"';
        $html .= ' data-ajax-url="' . Html::attr($ajaxUrl) . '"';
        $html .= ' data-nonce="' . Html::attr($nonce) . '"';
        $html .= ' data-action="' . Html::attr($action) . '">';
        $html .= '<h3>' . Html::text('Actions') . '</h3>';
        $html .= '<p class="hsp-ops-actions-note">'
            . Html::text(
                'Replay re-emits events through the normal pipeline; Reconcile repairs the '
                . 'delivery side to match WordPress. Both run through the existing services — '
                . 'neither writes a projection directly.'
            )
            . '</p>';

        foreach ($actions as $descriptor) {
            $html .= $this->action($descriptor);
        }

        $html .= $this->resultViewer();
        $html .= '</section>';

        return $html;
    }

    private function action(ConsoleAction $descriptor): string
    {
        $html  = '<form class="hsp-ops-action"'
            . ' data-op-action="' . Html::attr($descriptor->key) . '"'
            . ' data-confirm="' . ($descriptor->confirmationRequired ? '1' : '0') . '">';
        $html .= '<h4>' . Html::text($descriptor->label) . '</h4>';
        $html .= $this->fields($descriptor->key);
        $html .= '<button type="submit" class="button hsp-ops-action-run">'
            . Html::text('Run ' . strtolower($descriptor->label))
            . '</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * The parameter fields for one action key.
     *
     * Names match exactly the keys ConsoleActionController::sanitizeParams() whitelists — anything
     * else the form posted would be dropped there, so the two lists are kept deliberately aligned.
     */
    private function fields(string $key): string
    {
        return match ($key) {
            'reconcile' => $this->select('mode', 'Mode', ['drift', 'incremental', 'full'])
                . $this->checkbox('dry_run', 'Dry run (detect only, repair nothing)', true),
            'replay' => $this->select('mode', 'Mode', ['entity', 'range'])
                . $this->text('aggregate_type', 'Aggregate type (entity mode)', 'post')
                . $this->text('aggregate_id', 'Aggregate ID (entity mode)', '42')
                . $this->text('from', 'From (range mode, UTC)', '2026-01-01T00:00:00Z')
                . $this->text('to', 'To (range mode, UTC)', '2026-01-02T00:00:00Z'),
            default => $this->text('mode', 'Mode', ''),
        };
    }

    /**
     * @param list<string> $options
     */
    private function select(string $name, string $label, array $options): string
    {
        $html = '<label class="hsp-ops-field">' . Html::text($label) . ' '
            . '<select name="' . Html::attr($name) . '">';

        foreach ($options as $option) {
            $html .= '<option value="' . Html::attr($option) . '">' . Html::text($option) . '</option>';
        }

        return $html . '</select></label>';
    }

    private function text(string $name, string $label, string $placeholder): string
    {
        return '<label class="hsp-ops-field">' . Html::text($label) . ' '
            . '<input type="text" name="' . Html::attr($name) . '"'
            . ' placeholder="' . Html::attr($placeholder) . '"></label>';
    }

    private function checkbox(string $name, string $label, bool $checked): string
    {
        return '<label class="hsp-ops-field hsp-ops-field--check">'
            . '<input type="checkbox" name="' . Html::attr($name) . '" value="1"'
            . ($checked ? ' checked' : '') . '> '
            . Html::text($label) . '</label>';
    }

    private function resultViewer(): string
    {
        return '<div class="hsp-ops-action-result">'
            . '<p class="hsp-ops-action-summary">' . Html::text('No action run yet.') . '</p>'
            . '<pre class="hsp-ops-action-detail"></pre>'
            . '</div>';
    }
}
