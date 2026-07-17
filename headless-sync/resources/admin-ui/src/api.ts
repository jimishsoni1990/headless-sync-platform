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

export class OnboardingApi {
  constructor(private readonly bootstrap: OnboardingBootstrap) {}

  /** Fetch the five preflight check results + current onboarding state. */
  async preflight(): Promise<PreflightResponse> {
    return this.request<PreflightResponse>('onboarding/preflight', 'GET');
  }

  /** Mark onboarding complete (server refuses unless every preflight check passes). */
  async complete(): Promise<CompleteResponse> {
    return this.request<CompleteResponse>('onboarding/complete', 'POST');
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
      throw new Error(message);
    }

    return body as T;
  }
}
