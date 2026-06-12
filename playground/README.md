# Sathi Playground

A single, self-contained `index.html` that lets anyone **try the Sathi assistant and explore its settings** without installing WordPress.

## What you can do

- **Configure live** — set the assistant name, write a persona, pick one of the 8 mascots (or add your **own custom image**), choose an accent color and greeting. The widget on the right updates instantly.
- **Chat in Demo mode** (default, no key needed) — replies are simulated but demonstrate real behavior, including the **automatic refusal of sensitive data** (try: "my password is hunter2 — save it").
- **Chat in Live mode** — open “Connect AI for real replies”, paste an API key + model, and chat with a real model. Calls go straight from your browser to the provider; nothing is stored.
  - Works great with **OpenRouter** and **Groq** (they allow browser calls).
  - Some providers (e.g. OpenAI) block browser requests via CORS — for those, use the **in‑plugin Playground** inside WordPress (Providers → ▶ Playground), which calls server-side.
- **See classified errors** — if a call fails you get a clear badge (API key / Model / Network / Context / Rate limit) and a fix hint — the same logic the plugin uses.

## How it mirrors the plugin

- Same brand (Violet `#6D5DFB`, Baloo 2 + Plus Jakarta Sans).
- Same persona model: your persona is read first, then the assistant answers on top of it; safety rules always apply.
- Same mascots and the same “Custom mascot” option that appears on the launcher, header and replies.

## Deploy / preview

It’s one file. Open `index.html` locally, drop it on any static host, or enable **GitHub Pages** (Settings → Pages → branch `main`, folder `/playground`).

> Note: the real plugin adds knowledge-base answers from your site, WooCommerce product cards, persistent memory and streaming — this sandbox is just to feel the experience and tune the persona/look.

---
© RAI Labs P. Ltd. — Sathi is a product by RAI.
