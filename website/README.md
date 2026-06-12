# Sathi — Product Website

A complete, deployable marketing + docs + pricing website for **Sathi** (a product by RAI), in the new Sathi brand.

- `index.html` — self-contained single file (HTML/CSS/JS, GSAP via CDN). Host it anywhere: a subdomain, a WordPress page/template, or GitHub Pages.

## Features
- Hero with a **scroll-linked moving mascot**, floating cards, animated rings.
- **Micro-interactions** + scroll-reveal everywhere, scroll progress bar, count-up stats.
- Sections: Features, How it works, **5 mascots**, conversational-commerce showcase, full **Docs/Installation**, **Pricing** (monthly/yearly toggle) with **Buy** buttons.
- **Login with two demo roles** (client-side, for preview):
  - User → `user` / `user123` → account dashboard (license key, domains, buy more, download).
  - Admin → `admin` / `admin123` → backend dashboard (revenue, active licenses, customers, chart, licenses table).

## Deploy
Upload `index.html` to your subdomain's `public_html`, or enable GitHub Pages on this repo (Settings → Pages → branch `main`, folder `/website`). For real auth + payments, connect it to the PHP license server in `../sathi-license-server` (Buy buttons → checkout).

— by RAI · The Conscious Intelligence
