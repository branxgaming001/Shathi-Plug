# Sathi — Marketing Website

The official product website for **Sathi**, the AI support companion for WordPress (a product by RAI Labs).

This is a **single, self-contained `index.html`** — no build step, no external assets, no npm. All 8 mascots are embedded inline as optimized WebP data URIs, so the page loads instantly even on shared hosting.

## What's inside

- Hero with cursor-parallax mascot + click-to-swap (8 mascots)
- Scroll-reveal sections, animated stat counters, provider marquee, 3D tilt cards
- Confetti on the **Buy** action, floating sparkles, rotating speech bubbles
- Pricing / Buy License section
- Docs: Installation, Configuration, Providers, Knowledge Base, FAQ
- Two-role demo login:
  - **admin / admin123** → admin dashboard (full backend view)
  - **user / user123** → user dashboard (account + buy)
- `prefers-reduced-motion` respected for accessibility

## Performance

- Page weight ≈ **80 KB total** (HTML + all 8 mascots inline as WebP).
- Zero render-blocking remote images. Fonts load async from Google Fonts.
- No JS frameworks — vanilla JS + IntersectionObserver.

## Deploy to Hostinger (WordPress)

You can host this as a standalone landing page alongside WordPress:

1. **As a static page in WP:** Use a "Custom HTML" / full-page template plugin (e.g. a blank page template) and paste the contents of `index.html`. Because everything is inline, it just works.
2. **As a static file (recommended, fastest):**
   - Log into Hostinger **hPanel → File Manager** (or use SFTP).
   - Go to `public_html/`.
   - Create a folder, e.g. `public_html/sathi/`.
   - Upload `index.html` into it.
   - Visit `https://yourdomain.com/sathi/`.
3. **As the site root:** upload `index.html` to `public_html/` directly (note: this can conflict with a WP install at the root — prefer a subfolder or subdomain like `sathi.yourdomain.com`).

### Subdomain (cleanest)

1. hPanel → **Subdomains** → create `sathi.yourdomain.com`.
2. Upload `index.html` to that subdomain's document root.
3. Done.

## Preview with GitHub Pages

1. Repo **Settings → Pages**.
2. Source: deploy from branch `main`, folder `/website` (or move `index.html` to `/docs` and select `/docs`).
3. Wait ~1 min → GitHub gives you a live URL.

> Note: GitHub Pages serves the folder root. If you keep the site under `/website`, set the Pages source to that folder; otherwise the page will appear under the `/website/` path.

## Connecting the Buy button

The **Buy License** button currently runs a local confetti demo. To wire it to the real
license server, point it at your license server checkout URL (Stripe/Razorpay) once that
server is live. Search the JS for `function buy(` and replace the demo with a redirect to
your checkout endpoint.

---

© RAI Labs P. Ltd. — Sathi is a product by RAI.
