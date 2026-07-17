import { useCallback, useEffect, useMemo, useState } from 'react';
import { Rocket, RefreshCw, CheckCircle2 } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { PreflightList } from '@/components/PreflightList';
import { OnboardingApi, type PreflightResponse } from '@/api';
import type { OnboardingBootstrap } from '@/bootstrap';

/**
 * ONB-S1b onboarding page — preflight prerequisites (read-only status surface).
 *
 * Loads the five hard-blocking preflight checks (DECISION W (f)) from the server and renders them
 * with remediation. There is deliberately NO "complete onboarding" action in ONB-S1b: completion
 * (`hsp_onboarding_state = complete`) is wired to backfill convergence in ONB-S2 (DECISION W (b)/
 * (d)), not to a button here. The server keeps its POST onboarding/complete endpoint + 409 guard,
 * but this UI does not call it. When every check passes, the page shows a ready-to-proceed state;
 * the actual "Begin setup / backfill" action lands in ONB-S2.
 */
export function App({ bootstrap }: { bootstrap: OnboardingBootstrap }): JSX.Element {
  const api = useMemo(() => new OnboardingApi(bootstrap), [bootstrap]);

  const [data, setData] = useState<PreflightResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await api.preflight());
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load preflight checks.');
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  const isComplete = data?.state === 'complete';
  const allPassed = data?.ok === true;

  return (
    <div className="mx-auto max-w-3xl p-6">
      <div className="mb-6 flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Rocket className="h-5 w-5" />
        </div>
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Welcome to Headless Sync</h1>
          <p className="text-sm text-muted-foreground">
            First-run setup for the Headless Sync Platform.
          </p>
        </div>
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <CardTitle>Prerequisites</CardTitle>
          <Button
            variant="outline"
            size="sm"
            onClick={() => void load()}
            disabled={loading}
            title="Re-run the preflight checks"
          >
            <RefreshCw className={loading ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            Re-check
          </Button>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            These prerequisites must all pass before setup can continue.
          </p>

          {error !== null && (
            <p className="rounded-md border border-destructive p-3 text-sm text-destructive">
              {error}
            </p>
          )}

          {loading && data === null ? (
            <p className="text-sm text-muted-foreground">Running preflight checks…</p>
          ) : (
            data !== null && <PreflightList checks={data.checks} />
          )}

          {data !== null && !isComplete && (
            <p className="text-sm text-muted-foreground">
              {allPassed
                ? 'All prerequisites are met. Content backfill arrives in the next onboarding release.'
                : 'Resolve the failing checks above to continue.'}
            </p>
          )}

          {isComplete && (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <CheckCircle2 className="h-4 w-4" />
              Onboarding is complete — the Operations console is available in the WordPress admin
              menu.
            </p>
          )}
        </CardContent>
      </Card>

      <p className="mt-4 text-xs text-muted-foreground">Platform version {bootstrap.version}</p>
    </div>
  );
}
