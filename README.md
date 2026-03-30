# FlexPick — Affordable Tech Partnership for Growing Businesses

[![Build](https://github.com/serhii-f8/flexpick.net/actions/workflows/actions.yaml/badge.svg)](https://github.com/serhii-f8/flexpick.net/actions/workflows/actions.yaml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](./LICENSE.md)
[![Node.js](https://img.shields.io/badge/Node.js-%3E%3D22.12.0-green.svg)](https://nodejs.org/)
[![Astro](https://img.shields.io/badge/Astro-6-ff5d01.svg)](https://astro.build/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06b6d4.svg)](https://tailwindcss.com/)

**Stop searching the market. Start building.**

FlexPick is a dedicated development team offering end-to-end web and mobile solutions — from initial consultation to launch, hosting, and ongoing support. We work with modern stacks (Laravel, React, Node.js, Flutter) and specialize in transparent, long-term partnerships with no agency bloat.

## What We Do

- **Free consultation & estimation** — turn ideas into tech specs and realistic budgets
- **Greenfield or legacy work** — new projects or rescuing existing codebases
- **Continuous maintenance** — ongoing support during and after development
- **Monthly support packages** — fixed-price plans for steady, affordable help
- **Hosting & server setup** — infrastructure tailored to your project and budget

## Built in a Day

This corporate website was designed, developed, and deployed in a single day — a demonstration of how we work: fast, focused, and production-ready from the start. Built with [Astro 6](https://astro.build/) and [Tailwind CSS 4](https://tailwindcss.com/), it scores top marks on Lighthouse across performance, accessibility, SEO, and best practices.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Astro 6 (static output, zero JS by default) |
| Styling | Tailwind CSS 4 with CSS variable theming and dark mode |
| Build | Vite with custom integration for YAML-driven site config |
| Deployment | Netlify / Vercel / Docker (nginx) |
| CI | GitHub Actions — lint, type-check, and build validation |

## Quick Start

```bash
npm install          # Install dependencies
npm run dev          # Dev server → localhost:4321
npm run build        # Production build → dist/
npm run preview      # Preview production build locally
```

## Available Scripts

| Command | Description |
|---------|-------------|
| `npm run dev` | Start dev server at localhost:4321 |
| `npm run build` | Production build to `dist/` |
| `npm run preview` | Preview production build locally |
| `npm run check` | Run Astro check + ESLint + Prettier validation |
| `npm run fix` | Auto-fix ESLint + Prettier issues |

## Project Structure

```
src/
├── assets/            # Images, styles
├── components/
│   ├── widgets/       # Section-level components (Hero, Features, FAQs…)
│   ├── ui/            # Reusable primitives (Button, Form, Timeline…)
│   └── common/        # Meta tags, analytics, theme
├── content.config.ts  # Astro Content Collections schema
├── config.yaml        # Site-wide configuration (SEO, blog, theme)
├── data/post/         # Blog posts (.md / .mdx)
├── layouts/           # Page layouts
├── navigation.ts      # Header & footer nav definitions
├── pages/             # Routes
└── utils/             # Helpers (permalinks, blog queries, markdown plugins)
```

## License

This project is licensed under the [MIT License](./LICENSE.md).
