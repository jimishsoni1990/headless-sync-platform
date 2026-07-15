<?php

declare(strict_types=1);

namespace HSP\Core\Operations\UI;

use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\ModuleInspection;
use HSP\Core\Contracts\Operations\QueueStatus;
use HSP\Core\Contracts\Operations\Severity;
use HSP\Core\Contracts\Operations\WorkerStatus;
use HSP\Core\Operations\Diagnostics\SystemInformation;

/**
 * Pure server-side renderer for the Operations dashboard (Doc 12 §6 MVP nav item 1).
 *
 * Renders read-only widgets over the OPSC-S2 provider snapshots plus the System Information
 * and Module Inspector diagnostics surfaces. This class is PURE: it takes already-resolved
 * DTOs/snapshots (never a DatabaseConnectionInterface, reader, or concrete provider — ADR-053)
 * and returns an escaped HTML string. All dynamic output goes through {@see Html} (DECISION V
 * (b) — escape at output), so the renderer is WordPress-free and unit-testable.
 *
 * The controller (AdminPageController) resolves snapshots via OperationsService and hands them
 * here as arrays; this renderer performs no I/O and no state change.
 */
final class DashboardView
{
    /**
     * Render the whole dashboard.
     *
     * @param ConsoleWidget[]       $widgets    widgets placed on the dashboard page (in order)
     * @param array<string, mixed>  $snapshots  provider key → snapshot (from OperationsService)
     * @param SystemInformation|null $system    system-information snapshot, or null if unavailable
     * @param ModuleInspection[]    $modules    module inspector descriptors
     */
    public function render(
        array $widgets,
        array $snapshots,
        ?SystemInformation $system,
        array $modules,
    ): string {
        $html  = '<div class="hsp-ops-dashboard">';
        $html .= '<div class="hsp-ops-widgets">';

        foreach ($widgets as $widget) {
            $html .= $this->widget($widget, $snapshots[$widget->providerKey] ?? null);
        }

        $html .= '</div>'; // .hsp-ops-widgets

        if ($system !== null) {
            $html .= $this->systemInformation($system);
        }

        $html .= $this->moduleInspector($modules);
        $html .= '</div>'; // .hsp-ops-dashboard

        return $html;
    }

    /**
     * Render one widget from its descriptor and the snapshot its providerKey resolves to.
     */
    private function widget(ConsoleWidget $widget, mixed $snapshot): string
    {
        $html  = '<section class="hsp-ops-widget" data-provider="' . Html::attr($widget->providerKey) . '">';
        $html .= '<h3>' . Html::text($widget->title) . '</h3>';
        $html .= $this->snapshotBody($snapshot);
        $html .= '</section>';

        return $html;
    }

    /**
     * Render a snapshot body by its concrete shape (the same shapes RefreshCoordinator emits).
     */
    private function snapshotBody(mixed $snapshot): string
    {
        // QueueStatus — a single current-state DTO.
        if ($snapshot instanceof QueueStatus) {
            return $this->queue($snapshot);
        }

        // HealthReport[] — one or more component reports.
        if (is_array($snapshot) && $snapshot !== [] && $snapshot[0] instanceof HealthReport) {
            return $this->health($snapshot);
        }

        // MetricSample[] — derived-on-demand samples.
        if (is_array($snapshot) && $snapshot !== [] && $snapshot[0] instanceof MetricSample) {
            return $this->metrics($snapshot);
        }

        // WorkerStatus[] — current-state worker rows.
        if (is_array($snapshot) && $snapshot !== [] && $snapshot[0] instanceof WorkerStatus) {
            return $this->workers($snapshot);
        }

        if (is_array($snapshot) && $snapshot === []) {
            return '<p class="hsp-ops-empty">' . Html::text('No data.') . '</p>';
        }

        return '<p class="hsp-ops-empty">' . Html::text('No data available.') . '</p>';
    }

    private function queue(QueueStatus $status): string
    {
        $age = $status->oldestPendingAge === null
            ? '—'
            : $this->interval($status->oldestPendingAge);

        return '<ul class="hsp-ops-kv">'
            . $this->kv('Queue depth', (string) $status->depth)
            . $this->kv('Dead-letter depth', (string) $status->deadLetterDepth)
            . $this->kv('Oldest pending', $age)
            . '</ul>';
    }

    /**
     * @param HealthReport[] $reports
     */
    private function health(array $reports): string
    {
        $html = '<ul class="hsp-ops-health">';
        foreach ($reports as $report) {
            $badge = $this->severityBadge($report->severity);
            $html .= '<li>'
                . $badge . ' '
                . '<strong>' . Html::text($report->component) . '</strong>: '
                . Html::text($report->summary)
                . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @param MetricSample[] $samples
     */
    private function metrics(array $samples): string
    {
        $html = '<ul class="hsp-ops-kv">';
        foreach ($samples as $sample) {
            $value = $sample->unit === null
                ? (string) $sample->value
                : $sample->value . ' ' . $sample->unit;
            $html .= $this->kv($sample->name, $value);
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @param WorkerStatus[] $workers
     */
    private function workers(array $workers): string
    {
        $html = '<table class="hsp-ops-table"><thead><tr>'
            . '<th>' . Html::text('Worker') . '</th>'
            . '<th>' . Html::text('Type') . '</th>'
            . '<th>' . Html::text('State') . '</th>'
            . '<th>' . Html::text('Last heartbeat') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($workers as $worker) {
            $state = $worker->online ? 'online' : 'offline';
            $beat  = $worker->lastHeartbeatAt?->format('Y-m-d H:i:s') ?? '—';
            $html .= '<tr>'
                . '<td>' . Html::text($worker->workerId) . '</td>'
                . '<td>' . Html::text($worker->workerType) . '</td>'
                . '<td class="hsp-ops-state hsp-ops-state--' . Html::attr($state) . '">'
                . Html::text($state) . '</td>'
                . '<td>' . Html::text($beat) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function systemInformation(SystemInformation $info): string
    {
        $html  = '<section class="hsp-ops-system"><h3>' . Html::text('System Information') . '</h3>';
        $html .= '<ul class="hsp-ops-kv">'
            . $this->kv('Platform version', $info->platformVersion)
            . $this->kv('PHP version', $info->phpVersion)
            . $this->kv('WordPress version', $info->wordpressVersion ?? '—')
            . $this->kv('PostgreSQL version', $info->postgresVersion)
            . $this->kv('Queue provider', $info->queueProvider)
            . $this->kv('Applied migrations', (string) $info->appliedMigrationCount)
            . $this->kv('Latest migration', $info->latestMigration ?? '—')
            . '</ul>';

        if ($info->moduleVersions !== []) {
            $html .= '<h4>' . Html::text('Module versions') . '</h4><ul class="hsp-ops-kv">';
            foreach ($info->moduleVersions as $module => $version) {
                $html .= $this->kv($module, $version);
            }
            $html .= '</ul>';
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * @param ModuleInspection[] $modules
     */
    private function moduleInspector(array $modules): string
    {
        if ($modules === []) {
            return '';
        }

        $html = '<section class="hsp-ops-modules"><h3>' . Html::text('Module Inspector') . '</h3>';

        foreach ($modules as $module) {
            $html .= '<div class="hsp-ops-module">';
            $html .= '<h4>' . Html::text($module->name) . ' '
                . '<span class="hsp-ops-version">v' . Html::text($module->version) . '</span></h4>';
            $html .= '<ul class="hsp-ops-kv">'
                . $this->kv('Events', $this->count($module->eventTypes))
                . $this->kv('Endpoints', $this->count($module->endpoints))
                . $this->kv('Transformers', $this->count($module->transformers))
                . $this->kv('Adapters', $this->count($module->adapters))
                . $this->kv('Provider keys', $this->list($module->providerKeys))
                . '</ul>';
            $html .= '</div>';
        }

        $html .= '</section>';

        return $html;
    }

    // -------------------------------------------------------------------------
    // Small formatting helpers (all escape at output).
    // -------------------------------------------------------------------------

    private function kv(string $label, string $value): string
    {
        return '<li><span class="hsp-ops-k">' . Html::text($label) . '</span>'
            . '<span class="hsp-ops-v">' . Html::text($value) . '</span></li>';
    }

    private function severityBadge(Severity $severity): string
    {
        return '<span class="hsp-ops-badge hsp-ops-badge--' . Html::attr($severity->value) . '">'
            . Html::text(strtoupper($severity->value)) . '</span>';
    }

    /**
     * @param list<string> $items
     */
    private function count(array $items): string
    {
        return (string) count($items);
    }

    /**
     * @param list<string> $items
     */
    private function list(array $items): string
    {
        return $items === [] ? '—' : implode(', ', $items);
    }

    private function interval(\DateInterval $interval): string
    {
        $seconds = ($interval->days ?? 0) * 86400
            + $interval->h * 3600
            + $interval->i * 60
            + $interval->s;

        return $seconds . 's';
    }
}
