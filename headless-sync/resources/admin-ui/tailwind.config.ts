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
        border: 'hsl(var(--border))',
        input: 'hsl(var(--input))',
        ring: 'hsl(var(--ring))',
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        primary: {
          DEFAULT: 'hsl(var(--primary))',
          foreground: 'hsl(var(--primary-foreground))',
        },
        secondary: {
          DEFAULT: 'hsl(var(--secondary))',
          foreground: 'hsl(var(--secondary-foreground))',
        },
        muted: {
          DEFAULT: 'hsl(var(--muted))',
          foreground: 'hsl(var(--muted-foreground))',
        },
        card: {
          DEFAULT: 'hsl(var(--card))',
          foreground: 'hsl(var(--card-foreground))',
        },
        destructive: {
          DEFAULT: 'hsl(var(--destructive))',
          foreground: 'hsl(var(--destructive-foreground))',
        },
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
