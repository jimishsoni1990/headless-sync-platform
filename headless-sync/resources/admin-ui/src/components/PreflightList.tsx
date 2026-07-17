import { CheckCircle2, XCircle } from 'lucide-react';
import type { PreflightCheck } from '@/api';

/**
 * Renders the hard-blocking onboarding preflight checks (ONB-S1b; DECISION W (f), four checks as
 * amended v1.22). Also reused for the ONB-S2 backfill gates (worker heartbeat + migrations), which
 * share the same {key,label,passed,detail,remediation} shape.
 *
 * A failed check shows its remediation guidance inline — the operator cannot advance until every
 * check passes, so each failure must tell them exactly what to fix.
 */
export function PreflightList({ checks }: { checks: PreflightCheck[] }): JSX.Element {
  return (
    <ul className="flex flex-col gap-3">
      {checks.map((check) => (
        <li
          key={check.key}
          className="flex items-start gap-3 rounded-md border border-border p-3"
        >
          <span className="mt-0.5 shrink-0">
            {check.passed ? (
              <CheckCircle2 className="h-5 w-5 text-primary" aria-hidden />
            ) : (
              <XCircle className="h-5 w-5 text-destructive" aria-hidden />
            )}
          </span>
          <div className="min-w-0">
            <p className="text-sm font-medium">
              {check.label}
              <span className="sr-only">{check.passed ? ' — passed' : ' — failed'}</span>
            </p>
            <p className="text-sm text-muted-foreground">{check.detail}</p>
            {!check.passed && check.remediation !== '' && (
              <p className="mt-1 text-sm text-destructive">{check.remediation}</p>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}
