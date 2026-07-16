/**
 * The bootstrap payload the PHP registrar localizes onto the page (via wp_localize_script,
 * object name `HSP_ONBOARDING`). It carries only what the client needs to reach the server
 * securely — the nonce and REST base — plus display facts. ONB-S1b uses `nonce`/`restUrl` to
 * call the WPCS-guarded endpoints; ONB-S1a ships no such endpoint yet.
 */
export interface OnboardingBootstrap {
  /** wp REST nonce for authenticated requests the React app will make (ONB-S1b). */
  nonce: string;
  /** REST base URL for HSP onboarding endpoints (ONB-S1b). */
  restUrl: string;
  /** Plugin/platform version, for display. */
  version: string;
}

declare global {
  interface Window {
    HSP_ONBOARDING?: Partial<OnboardingBootstrap>;
  }
}

/** Read the localized bootstrap with safe defaults (page renders even if localization is absent). */
export function readBootstrap(): OnboardingBootstrap {
  const raw = window.HSP_ONBOARDING ?? {};

  return {
    nonce: typeof raw.nonce === 'string' ? raw.nonce : '',
    restUrl: typeof raw.restUrl === 'string' ? raw.restUrl : '',
    version: typeof raw.version === 'string' ? raw.version : '',
  };
}
