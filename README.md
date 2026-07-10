# FlexPick — We Rescue AI-Built Codebases

[![Build](https://github.com/serhii-f8/flexpick.net/actions/workflows/actions.yaml/badge.svg)](https://github.com/serhii-f8/flexpick.net/actions/workflows/actions.yaml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](./LICENSE.md)
[![Node.js](https://img.shields.io/badge/Node.js-%3E%3D22.12.0-green.svg)](https://nodejs.org/)
[![Astro](https://img.shields.io/badge/Astro-6-ff5d01.svg)](https://astro.build/)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06b6d4.svg)](https://tailwindcss.com/)

**Keep building. We'll fix the foundation.**

FlexPick helps companies whose products were built with vibe coding and AI agents — and now struggle with stability, maintainability, or scalability. We audit the codebase, fix the risks, introduce proven engineering practices, and prepare the project for effective AI-assisted development.

## What We Do

- **Free codebase audit** — an honest, plain-language health report on where your code stands
- **Stabilization** — tests around critical behavior, CI, and error monitoring
- **Refactoring & simplification** — collapse duplication, untangle architecture, shrink the codebase
- **Engineering practices** — code review, safe deployments, and documentation habits
- **AI-ready codebase** — conventions and guardrails that make AI coding tools effective at scale
- **Ongoing partnership** — senior engineering support after the rescue, if you want it

## Built in a Day

This corporate website was designed, developed, and deployed in a single day — a demonstration of how we work: fast, focused, and production-ready from the start. Built with [Astro 6](https://astro.build/) and [Tailwind CSS 4](https://tailwindcss.com/), it scores top marks on Lighthouse across performance, accessibility, SEO, and best practices.

## Tech Stack

| Layer      | Technology                                               |
| ---------- | -------------------------------------------------------- |
| Framework  | Astro 6 (static output, zero JS by default)              |
| Styling    | Tailwind CSS 4 with CSS variable theming and dark mode   |
| Build      | Vite with custom integration for YAML-driven site config |
| Deployment | Netlify / Vercel / Docker (nginx)                        |
| CI         | GitHub Actions — lint, type-check, and build validation  |

## Quick Start

```bash
npm install          # Install dependencies
npm run dev          # Dev server → localhost:4321
npm run build        # Production build → dist/
npm run preview      # Preview production build locally
```

## Available Scripts

| Command           | Description                                    |
| ----------------- | ---------------------------------------------- |
| `npm run dev`     | Start dev server at localhost:4321             |
| `npm run build`   | Production build to `dist/`                    |
| `npm run preview` | Preview production build locally               |
| `npm run check`   | Run Astro check + ESLint + Prettier validation |
| `npm run fix`     | Auto-fix ESLint + Prettier issues              |

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
