import type { Config } from 'tailwindcss';

/**
 * Tailwind config for the HSP admin UI (DECISION W (a) styling addendum, 2026-07-16).
 *
 * Two hard requirements from the ruling:
 *
 * 1. wp-admin must be untouched. Tailwind's global `preflight` reset would restyle every
 *    wp-admin element, so it is DISABLED here (`corePlugins.preflight = false`) and replaced by
 *    a scoped base layer (src/styles.css) that lives entirely under `.hsp-admin-ui`. Every
 *    generated utility is also scoped: `important: '.hsp-admin-ui'` prefixes each utility
 *    selector with the mount container, so no utility can leak onto a wp-admin node outside the
 *    mount.
 *
 * 2. Dark theme is the DEFAULT, applied on the mount root only. `darkMode: ['class', ...]`
 *    keys dark tokens off a `.dark` class; the mount root carries `.dark` (see src/main.tsx),
 *    never the wp-admin `<body>`.
 */
export default {
  darkMode: ['class', '.hsp-admin-ui.dark'],
  // Scope every utility under the mount container so nothing leaks into wp-admin.
  important: '.hsp-admin-ui',
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  corePlugins: {
    // wp-admin styles must be untouched — no global reset. src/styles.css supplies a
    // mount-scoped base instead.
    preflight: false,
  },
  theme: {
    extend: {
      colors: {
        // shadcn token surface, driven by CSS custom properties (src/styles.css).
        // The tokens carry complete oklch() colors, so they are read as var(--token)
        // directly (NOT wrapped in hsl()). Kept in lockstep with the supplied scheme.
        border: 'var(--border)',
        input: 'var(--input)',
        ring: 'var(--ring)',
        background: 'var(--background)',
        foreground: 'var(--foreground)',
        primary: {
          DEFAULT: 'var(--primary)',
          foreground: 'var(--primary-foreground)',
        },
        secondary: {
          DEFAULT: 'var(--secondary)',
          foreground: 'var(--secondary-foreground)',
        },
        muted: {
          DEFAULT: 'var(--muted)',
          foreground: 'var(--muted-foreground)',
        },
        accent: {
          DEFAULT: 'var(--accent)',
          foreground: 'var(--accent-foreground)',
        },
        popover: {
          DEFAULT: 'var(--popover)',
          foreground: 'var(--popover-foreground)',
        },
        card: {
          DEFAULT: 'var(--card)',
          foreground: 'var(--card-foreground)',
        },
        // The supplied scheme has no --destructive-foreground; destructive is used only as a
        // text/border color in the UI, so no foreground pairing is mapped.
        destructive: 'var(--destructive)',
      },
      borderRadius: {
        lg: 'var(--radius)',
        md: 'calc(var(--radius) - 2px)',
        sm: 'calc(var(--radius) - 4px)',
      },
    },
  },
  plugins: [],
} satisfies Config;
