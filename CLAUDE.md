# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

Requires Node.js >= 22.12.0.

```bash
npm run dev          # Start dev server at localhost:4321
npm run build        # Production build → dist/
npm run preview      # Preview production build locally
npm run check        # Run astro check + eslint + prettier validation
npm run fix          # Auto-fix eslint + prettier issues
```

Individual lint/check sub-commands:

```bash
npm run check:astro      # Astro type checking only
npm run check:eslint     # ESLint only
npm run check:prettier   # Prettier format check only
npm run fix:eslint       # Auto-fix ESLint issues
npm run fix:prettier     # Auto-fix Prettier formatting
```

## Architecture

This is a static marketing site built on **Astro 6 + Tailwind CSS 4**. Output is fully static HTML — no server runtime.

### Configuration System

The site uses a custom Vite integration (`vendor/integration/`) that loads `src/config.yaml` at build time and exposes it as a virtual module `flexpick:config`. This provides constants: `SITE`, `I18N`, `METADATA`, `APP_BLOG`, `UI`, `ANALYTICS`. Site-wide settings (name, SEO defaults, blog config, theme) are all controlled through this YAML file — not hardcoded in components.

### Component Hierarchy

Pages compose **widgets** (full page sections like Hero, Features, Pricing, FAQs) which use **ui** components (Button, Form, ItemGrid, Headline) and **common** utilities (Metadata, Analytics, ToggleTheme).

- `src/components/widgets/` — Section-level components (Hero, CallToAction, Steps, etc.)
- `src/components/ui/` — Reusable primitives (Button, Form, Timeline, WidgetWrapper)
- `src/components/common/` — Meta tags, analytics, theme scripts
- `src/layouts/` — Layout.astro (main), PageLayout, MarkdownLayout

### Blog System

Blog posts live in `src/data/post/` as `.md`/`.mdx` files, loaded via Astro Content Collections (glob loader). The collection schema is defined in `src/content.config.ts` (project root) with Zod validation. Blog utilities (`src/utils/blog.ts`) handle querying, filtering, and sorting. Dynamic routes in `src/pages/[...blog]/` handle post pages, pagination, categories, and tags.

### Navigation

Header and footer navigation are defined in `src/navigation.ts` using permalink helper functions from `src/utils/permalinks.ts`.

### Path Alias

`~/` maps to `src/` (configured in both tsconfig.json and astro.config.ts).

### Theming

Tailwind CSS 4 is loaded via the `@tailwindcss/vite` plugin (not PostCSS). It uses CSS variables for colors (`primary`, `secondary`, `accent`, `default`, `muted`). Dark mode is class-based. Custom styles and variable definitions are in `src/components/CustomStyles.astro` and `src/assets/styles/tailwind.css`.

### Markdown Plugins

Custom remark/rehype plugins in `src/utils/frontmatter.ts`: reading time estimation, responsive tables, lazy image loading.

## Code Style

- Prettier: 120 char width, single quotes, semicolons, ES5 trailing commas
- ESLint: TypeScript-ESLint recommended + Astro plugin
- Unused variables prefixed with `_` are allowed
- ESM modules (`"type": "module"` in package.json)

## Deployment

Static output to `dist/`. Configured for Netlify (`netlify.toml`), Vercel (`vercel.json`), and Docker (nginx). Astro assets under `/_astro/` get 1-year immutable cache headers. CI runs build validation across Node 18/20/22 and lint checks on Node 22 via GitHub Actions.
