import { CheckCircle2, XCircle } from 'lucide-react';
import type { BackfillGate, BackfillProgressSnapshot } from '@/api';

/**
 * ONB-S2 backfill surface — gate status + derived live progress (DECISION W (b)/(c)/(d)).
 *
 * The two hard prerequisites (live worker heartbeat + applied migrations) render with inline
 * remediation when blocked; the derived progress bar (expected vs projected, DECISION Q) shows the
 * backfill draining through the normal pipeline. Progress is a read-derived snapshot — this
 * component never writes and never triggers the backfill (the parent owns the start action).
 */
export function BackfillPanel({
  gates,
  progress,
  running,
}: {
  gates: BackfillGate[];
  progress: BackfillProgressSnapshot | null;
  running: boolean;
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
