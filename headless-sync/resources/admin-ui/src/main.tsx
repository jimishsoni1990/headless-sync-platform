import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/App';
import { readBootstrap } from '@/bootstrap';
import './styles.css';

/**
 * Mount entry for the HSP admin UI (ONB-S1a).
 *
 * The PHP registrar renders a single mount element `<div id="hsp-admin-ui-root">` inside the
 * wp-admin page shell. We attach the `hsp-admin-ui` scope class and the DEFAULT `dark` class on
 * the MOUNT ROOT ONLY — never on the wp-admin <body> — so Tailwind's scoped utilities/tokens
 * apply here and wp-admin's own styling is untouched (DECISION W (a) styling addendum).
 */
const MOUNT_ID = 'hsp-admin-ui-root';

function mount(): void {
  const el = document.getElementById(MOUNT_ID);
  if (el === null) {
    return;
  }

  // Scope + dark default live on the mount root, not on document.body.
  el.classList.add('hsp-admin-ui', 'dark');

  createRoot(el).render(
    <StrictMode>
      <App bootstrap={readBootstrap()} />
    </StrictMode>,
  );
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
