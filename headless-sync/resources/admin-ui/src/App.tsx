import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Rocket, RefreshCw, CheckCircle2 } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { PreflightList } from '@/components/PreflightList';
import { BackfillPanel } from '@/components/BackfillPanel';
import {
  OnboardingApi,
  BackfillBlockedError,
  type PreflightResponse,
  type GateSummary,
  type BackfillProgressSnapshot,
} from '@/api';
import type { OnboardingBootstrap } from '@/bootstrap';

/**
 * ONB-S2 onboarding page — preflight → first-run backfill → completion redirect.
 *
 * Flow (DECISION W (b)/(c)/(d)):
 *   1. Load the four hard-blocking environment preflight checks (DECISION W (f) v1.22).
 *   2. Once all pass, offer "Begin setup" — POST onboarding/backfill triggers a
 *      full-reconciliation re-emission through the normal pipeline (no direct WP→PG copy). If a
 *      hard prerequisite (live worker heartbeat / applied migrations) is unmet, the server returns
 *      409 and we render the per-gate remediation (never a Restart Workers action).
 *   3. Poll GET onboarding/backfill/progress on an interval — the bar reflects derived, on-demand
 *      progress (expected vs projected, DECISION Q). The server flips hsp_onboarding_state →
 *      complete on convergence (zero in-flight + all expected content projected).
 *   4. On completion the client redirects to the Operations console (now un-gated).
 */
const POLL_INTERVAL_MS = 2000;

export function App({ bootstrap }: { bootstrap: OnboardingBootstrap }): JSX.Element {
  const api = useMemo(() => new OnboardingApi(bootstrap), [bootstrap]);

  const [preflight, setPreflight] = useState<PreflightResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [gate, setGate] = useState<GateSummary | null>(null);
  const [progress, setProgress] = useState<BackfillProgressSnapshot | null>(null);
  const [running, setRunning] = useState(false);
  const [complete, setComplete] = useState(false);

  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const stopPolling = useCallback(() => {
    if (pollRef.current !== null) {
      clearInterval(pollRef.current);
      pollRef.current = null;
    }
  }, []);

  const redirectToOperations = useCallback(() => {
    if (bootstrap.operationsUrl !== '') {
      window.location.assign(bootstrap.operationsUrl);
    }
  }, [bootstrap.operationsUrl]);

  const loadPreflight = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await api.preflight();
      setPreflight(data);
      if (data.state === 'complete') {
        setComplete(true);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load preflight checks.');
    } finally {
      setLoading(false);
    }
  }, [api]);

  const pollProgress = useCallback(async () => {
    try {
      const data = await api.backfillProgress();
      setProgress(data.progress);
      setGate(data.gate);
      if (data.complete) {
        setComplete(true);
        setRunning(false);
        stopPolling();
        redirectToOperations();
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to read backfill progress.');
    }
  }, [api, stopPolling, redirectToOperations]);

  const startBackfill = useCallback(async () => {
    setError(null);
    setGate(null);
    setRunning(true);
    try {
      const data = await api.startBackfill();
      setProgress(data.progress);
      if (data.complete === true) {
        setComplete(true);
        setRunning(false);
        redirectToOperations();
        return;
      }
      // Begin polling for convergence.
      stopPolling();
      pollRef.current = setInterval(() => void pollProgress(), POLL_INTERVAL_MS);
    } catch (e) {
      setRunning(false);
      if (e instanceof BackfillBlockedError) {
        setGate(e.gate);
        setError(e.message);
        return;
      }
      setError(e instanceof Error ? e.message : 'Failed to start the backfill.');
    }
  }, [api, pollProgress, stopPolling, redirectToOperations]);

  useEffect(() => {
    void loadPreflight();
  }, [loadPreflight]);

  useEffect(() => stopPolling, [stopPolling]);

  const allPassed = preflight?.ok === true;

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

      {error !== null && (
        <p className="mb-4 rounded-md border border-destructive p-3 text-sm text-destructive">
          {error}
        </p>
      )}

      {complete ? (
        <Card>
          <CardHeader>
            <CardTitle>Setup complete</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <CheckCircle2 className="h-4 w-4 text-primary" />
              Onboarding is complete — your content is synced and the Operations console is now
              available.
            </p>
            <div>
              <Button onClick={redirectToOperations} disabled={bootstrap.operationsUrl === ''}>
                Go to Operations
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardHeader className="flex-row items-center justify-between">
              <CardTitle>Prerequisites</CardTitle>
              <Button
                variant="outline"
                size="sm"
                onClick={() => void loadPreflight()}
                disabled={loading || running}
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

              {loading && preflight === null ? (
                <p className="text-sm text-muted-foreground">Running preflight checks…</p>
              ) : (
                preflight !== null && <PreflightList checks={preflight.checks} />
              )}
            </CardContent>
          </Card>

          {allPassed && (
            <Card className="mt-4">
              <CardHeader>
                <CardTitle>Content backfill</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <p className="text-sm text-muted-foreground">
                  Setup copies your existing WordPress content into the delivery store by re-syncing
                  it through the normal pipeline. A running worker is required.
                </p>

                <BackfillPanel gates={gate?.gates ?? []} progress={progress} running={running} />

                <div>
                  <Button onClick={() => void startBackfill()} disabled={running}>
                    <Rocket className={running ? 'h-4 w-4 animate-pulse' : 'h-4 w-4'} />
                    {running ? 'Backfill running…' : 'Begin setup'}
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}
        </>
      )}

      <p className="mt-4 text-xs text-muted-foreground">Platform version {bootstrap.version}</p>
    </div>
  );
}
