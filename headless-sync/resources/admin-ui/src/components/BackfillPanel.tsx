import { CheckCircle2, XCircle, Database, PlayCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { BackfillGate, BackfillProgressSnapshot } from '@/api';

/** Gate keys mirror BackfillGate::GATE_* (worker heartbeat + applied migrations). */
const GATE_MIGRATIONS = 'migrations_applied';
const GATE_WORKER = 'worker_heartbeat';

/**
 * ONB-S2 backfill surface — gate status + derived live progress + in-product remediation
 * (DECISION W (b)/(c)/(d)/(e)/(f) v1.23).
 *
 * The two hard prerequisites (processing pipeline advancing + applied migrations) render with inline
 * remediation when blocked. Each blocked gate also gets a SELF-REMEDIATION action (DECISION W (f)
 * v1.23; ADR-054 Principle 8 — zero-config): "Apply migrations" runs the platform's own engine, and
 * "Start processing" nudges WP-Cron so a cycle runs and a heartbeat appears — no CLI needed. The
 * derived progress bar (expected vs projected, DECISION Q) shows the backfill draining through the
 * normal pipeline. Progress is a read-derived snapshot — this component never writes; the parent
 * owns the action handlers.
 */
export function BackfillPanel({
  gates,
  progress,
  running,
  remediating,
  onApplyMigrations,
  onStartProcessing,
}: {
  gates: BackfillGate[];
  progress: BackfillProgressSnapshot | null;
  running: boolean;
  remediating: boolean;
  onApplyMigrations: () => void;
  onStartProcessing: () => void;
}): JSX.Element {
  return (
    <div className="flex flex-col gap-4">
      <ul className="flex flex-col gap-3">
        {gates.map((gate) => (
          <li key={gate.key} className="flex items-start gap-3 rounded-md border border-border p-3">
            <span className="mt-0.5 shrink-0">
              {gate.passed ? (
                <CheckCircle2 className="h-5 w-5 text-primary" aria-hidden />
              ) : (
                <XCircle className="h-5 w-5 text-destructive" aria-hidden />
              )}
            </span>
            <div className="min-w-0">
              <p className="text-sm font-medium">{gate.label}</p>
              <p className="text-sm text-muted-foreground">{gate.detail}</p>
              {!gate.passed && gate.remediation !== '' && (
                <p className="mt-1 text-sm text-destructive">{gate.remediation}</p>
              )}
              {!gate.passed && gate.key === GATE_MIGRATIONS && (
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-2"
                  onClick={onApplyMigrations}
                  disabled={remediating || running}
                >
                  <Database className="h-4 w-4" />
                  Apply migrations
                </Button>
              )}
              {!gate.passed && gate.key === GATE_WORKER && (
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-2"
                  onClick={onStartProcessing}
                  disabled={remediating || running}
                >
                  <PlayCircle className="h-4 w-4" />
                  Start processing
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>

      {progress !== null && (running || progress.projected_total > 0 || progress.converged) && (
        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between text-sm">
            <span className="font-medium">Backfill progress</span>
            <span className="text-muted-foreground">
              {progress.projected_total}/{progress.expected_total} projected · {progress.percent}%
            </span>
          </div>
          <div
            className="h-2 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-valuenow={progress.percent}
            aria-valuemin={0}
            aria-valuemax={100}
          >
            <div
              className="h-full bg-primary transition-all"
              style={{ width: `${progress.percent}%` }}
            />
          </div>
          {progress.in_flight > 0 && (
            <p className="text-xs text-muted-foreground">
              {progress.in_flight} event(s) still draining through the pipeline…
            </p>
          )}
        </div>
      )}
    </div>
  );
}
