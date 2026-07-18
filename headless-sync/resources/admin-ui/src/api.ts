import type { OnboardingBootstrap } from '@/bootstrap';

/**
 * Typed client for the ONB-S1b onboarding REST endpoints (under hsp/v1).
 *
 * Every request carries the wp REST nonce in the `X-WP-Nonce` header — the server verifies it,
 * checks capability, and sanitizes input (DECISION W (a) — the client is untrusted). The base URL
 * and nonce come from the bootstrap payload the PHP registrar localized.
 */

/** One preflight check result, mirroring core PreflightResult::toArray(). */
export interface PreflightCheck {
  key: string;
  label: string;
  passed: boolean;
  detail: string;
  remediation: string;
}

/** Response of GET onboarding/preflight. */
export interface PreflightResponse {
  state: string;
  ok: boolean;
  checks: PreflightCheck[];
}

/** Response of POST onboarding/complete. */
export interface CompleteResponse {
  state: string;
  ok: boolean;
}

/** One backfill gate (worker heartbeat / migrations), mirroring BackfillGate::summary(). */
export interface BackfillGate {
  key: string;
  label: string;
  passed: boolean;
  detail: string;
  remediation: string;
}

/** Gate summary block. */
export interface GateSummary {
  ready: boolean;
  gates: BackfillGate[];
}

/** Derived progress snapshot, mirroring BackfillProgress::snapshot(). */
export interface BackfillProgressSnapshot {
  expected: Record<string, number>;
  projected: Record<string, number>;
  expected_total: number;
  projected_total: number;
  in_flight: number;
  converged: boolean;
  percent: number;
}

/** Response of POST onboarding/backfill. */
export interface BackfillStartResponse {
  state: string;
  started: boolean;
  complete?: boolean;
  reemitted?: number;
  scanned?: number;
  progress: BackfillProgressSnapshot;
}

/** Response of GET onboarding/backfill/progress. */
export interface BackfillProgressResponse {
  state: string;
  complete: boolean;
  progress: BackfillProgressSnapshot;
  gate: GateSummary;
  redirect: string | null;
}

/** One migrations-gate result, mirroring MigrationsAppliedCheck::run()->toArray(). */
export interface MigrationGate {
  key: string;
  label: string;
  passed: boolean;
  detail: string;
  remediation: string;
}

/** Response of POST onboarding/migrate. */
export interface MigrateResponse {
  state: string;
  ran: boolean;
  total?: number;
  complete?: boolean;
  gate: MigrationGate;
}

/** Response of POST onboarding/spawn-worker. */
export interface SpawnWorkerResponse {
  state: string;
  spawn: { spawned: boolean; disabled: boolean; warning: string };
  gate: GateSummary;
}

/** Raised on a 409 blocked-gate response so the UI can render per-gate remediation. */
export class BackfillBlockedError extends Error {
  constructor(
    message: string,
    public readonly gate: GateSummary | null,
  ) {
    super(message);
    this.name = 'BackfillBlockedError';
  }
}

export class OnboardingApi {
  constructor(private readonly bootstrap: OnboardingBootstrap) {}

  /** Fetch the preflight check results + current onboarding state. */
  async preflight(): Promise<PreflightResponse> {
    return this.request<PreflightResponse>('onboarding/preflight', 'GET');
  }

  /** Mark onboarding complete (server refuses unless every preflight check passes). */
  async complete(): Promise<CompleteResponse> {
    return this.request<CompleteResponse>('onboarding/complete', 'POST');
  }

  /**
   * Trigger the first-run backfill (reconcileFull re-emission, DECISION W (b)). Throws a
   * {@link BackfillBlockedError} carrying the gate summary when a hard prerequisite (live worker /
   * applied migrations) is unmet (server 409).
   */
  async startBackfill(): Promise<BackfillStartResponse> {
    return this.request<BackfillStartResponse>('onboarding/backfill', 'POST');
  }

  /** Poll derived progress + gate status; the server flips to complete on convergence. */
  async backfillProgress(): Promise<BackfillProgressResponse> {
    return this.request<BackfillProgressResponse>('onboarding/backfill/progress', 'GET');
  }

  /**
   * Apply the outstanding core + content migrations through the platform's own engine (ONB-S2
   * self-remediation, DECISION W (e)/(f) v1.23). Zero-config: no CLI needed. The server blocks with
   * 409 until the environment preflight passes and returns the refreshed migrations gate on success.
   */
  async migrate(): Promise<MigrateResponse> {
    return this.request<MigrateResponse>('onboarding/migrate', 'POST');
  }

  /**
   * Nudge WP-Cron so a processing cycle runs and a heartbeat appears (ONB-S2 self-remediation,
   * DECISION W (c)). Non-blocking — the cycle runs inside WP-Cron, not this request.
   */
  async spawnWorker(): Promise<SpawnWorkerResponse> {
    return this.request<SpawnWorkerResponse>('onboarding/spawn-worker', 'POST');
  }

  private async request<T>(path: string, method: 'GET' | 'POST'): Promise<T> {
    const url = this.bootstrap.restUrl.replace(/\/$/, '') + '/' + path;
    const response = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': this.bootstrap.nonce,
      },
      credentials: 'same-origin',
    });

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      const message =
        body && typeof body === 'object' && 'message' in body
          ? String((body as { message: unknown }).message)
          : `Request failed (${response.status}).`;

      // A 409 from the backfill endpoint carries the gate summary under data.gate.
      if (response.status === 409 && body && typeof body === 'object' && 'data' in body) {
        const data = (body as { data?: { gate?: GateSummary } }).data;
        throw new BackfillBlockedError(message, data?.gate ?? null);
      }

      throw new Error(message);
    }

    return body as T;
  }
}
