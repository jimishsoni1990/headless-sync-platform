import { Rocket } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import type { OnboardingBootstrap } from '@/bootstrap';

/**
 * ONB-S1a onboarding page skeleton.
 *
 * This is the React shell only — the frontend toolchain + mount seam. The five preflight
 * checks, nav gating, and the `hsp_onboarding_state` flow are ONB-S1b and are intentionally
 * absent here. The `bootstrap` prop carries the values the PHP registrar localized (nonce +
 * REST base) so ONB-S1b can call server endpoints without a second wiring pass.
 */
export function App({ bootstrap }: { bootstrap: OnboardingBootstrap }): JSX.Element {
  return (
    <div className="mx-auto max-w-3xl p-6">
      <div className="mb-6 flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Rocket className="h-5 w-5" />
        </div>
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            Welcome to Headless Sync
          </h1>
          <p className="text-sm text-muted-foreground">
            First-run setup for the Headless Sync Platform.
          </p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Get started</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            This wizard will guide you through connecting WordPress to your PostgreSQL
            delivery store and backfilling your existing content. Setup steps arrive in the
            next release.
          </p>
          <div>
            <Button disabled title="Available in the next onboarding release">
              Begin setup
            </Button>
          </div>
        </CardContent>
      </Card>

      <p className="mt-4 text-xs text-muted-foreground">
        Platform version {bootstrap.version}
      </p>
    </div>
  );
}
