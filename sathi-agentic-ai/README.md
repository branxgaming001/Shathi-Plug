# Sathi Agentic AI

> Intelligent WordPress Support Agent Framework — chat, knowledge base, persistent memory, real-time site navigation. Multi-provider, highly customizable 2026 UI.

**By [NEER Media](https://neermedia.com)** | License: GPL v2+ | Requires: PHP 8.1+, WP 6.4+

---

## Features

### Core
- **Multi-Provider AI** — OpenAI, Anthropic Claude, Google Gemini, OpenRouter, and local/Ollama models. One interface, swap freely.
- **Streaming Chat** — Real-time streaming responses with markdown rendering, syntax highlighting, and client-side actions.
- **Persistent Memory** — Per-user long-term memory store. Remembers names, preferences, topics, and conversation summaries across sessions.
- **Knowledge Base** — Automatic site crawler that indexes all posts, pages, and products into searchable chunks. Scheduled via cron.
- **Real-Time Navigation** — AI can guide visitors through the site with safe, allowlisted actions: navigate, scroll, highlight, focus inputs.
- **Persona System** — 6 predefined mascots (Guru, Ninja, Buddy, Sage, Spark, Guardian) plus custom personas with full system prompt control.

### WordPress Integration
- **WP 7 AI Framework Bridge** — Gracefully integrates with Connectors, Abilities, and AI Client when available on WP 7.0+. Never hard-depends.
- **Shortcode `[sathi_chat]`** — Embed the chat widget anywhere.
- **Gutenberg Block `sathi/chat`** — Drop the agent into any page.
- **Floating Widget** — Configurable position (bottom-right, bottom-left, top-right, top-left).
- **REST API** — Full REST API for chat, settings, personas, knowledge, and memory.

### Admin
- **Dashboard** — Overview of providers, personas, knowledge base, and usage.
- **Settings** — Configure providers, models, streaming, memory TTL, crawl intervals.
- **Persona Editor** — View and customize all personas. Create new ones.
- **Knowledge Manager** — Index site content, search chunks, clear index.
- **Memory Viewer** — Inspect and manage per-user memory entries.

### Developer
- **PSR-4 Autoloading** — Clean namespaces under `RaiLabs\Sathi`.
- **Provider Interface** — Add new AI providers with a single interface.
- **Extensible Tools** — Register custom tools/functions via `sathi_chat_tools` filter.
- **Action & Filter Hooks** — `sathi_booted`, `sathi_init`, `sathi_chat_tools`, `sathi_system_prompt`, `sathi_provider_{key}`, and more.
- **Typed DTOs** — Message, Conversation, FunctionCall, FunctionResult value objects.

---

## Quick Start

### 1. Install
```bash
# Upload the plugin to /wp-content/plugins/sathi-agentic-ai/
# or unzip into your plugins directory
```

### 2. Configure
1. Go to **Sathi AI → Settings** in your WordPress admin.
2. Select a provider (e.g., OpenAI).
3. Enter your API key.
4. Choose a default persona.
5. Save.

### 3. Use
- The floating chat widget appears on your frontend automatically.
- Use `[sathi_chat]` shortcode to embed the widget in any page.
- Use the `sathi/chat` Gutenberg block in the editor.

---

## Development

### Requirements
- PHP 8.1+
- Node.js 18+
- Composer 2+

### Setup
```bash
cd sathi-agentic-ai

# Install PHP dependencies
composer install

# Install frontend dependencies
npm install

# Build frontend assets
npm run build

# Watch mode (rebuilds on change)
npm run dev
```

### Local WordPress Dev
```bash
# Using wp-env
npx wp-env start

# Or use Local / MAMP / Docker pointing to your plugin directory
```

### Linting
```bash
# PHP
composer lint       # phpcs
composer lint:fix   # phpcbf

# TypeScript
npm run typecheck
npm run lint
```

---

## Architecture

```
sathi-agentic-ai/
├── sathi-agentic-ai.php     # Plugin bootstrap
├── composer.json
├── package.json
├── src/
│   ├── Core/                # Plugin orchestrator, Settings, Database, DTOs
│   ├── Providers/           # AI provider adapters (OpenAI, Anthropic, Gemini, etc.)
│   ├── Agent/               # Agent loop with tool execution
│   ├── Personas/            # Persona registry, prompt composer
│   ├── Memory/              # Persistent user memory store
│   ├── Knowledge/           # Site crawler, chunker, search
│   ├── Navigation/          # Route map, client action protocol
│   ├── Chat/                # Chat manager, shortcode, block
│   ├── Rest/                # REST API controllers
│   ├── Admin/               # Admin boot, menu pages
│   ├── Abilities/           # Tool/function registry
│   ├── Integration/         # WP 7 AI framework bridge
│   └── Support/             # Logger, helpers
├── ui/                      # React + TypeScript + Tailwind source
│   ├── widget/              # Frontend chat widget
│   └── admin/               # Admin dashboard panels
├── assets/                  # Compiled JS/CSS bundles (output)
├── labs/wp7-integration/    # WP 7 experimental integration
├── languages/               # Translation files
├── templates/               # PHP templates
└── tests/                   # PHPUnit tests
```

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full architectural design.

---

## Supported Providers

| Provider   | Chat | Streaming | Tools | Vision | Embeddings |
|------------|------|-----------|-------|--------|------------|
| OpenAI     | ✓    | ✓         | ✓     | ✓      | ✓          |
| Anthropic  | ✓    | ✓         | ✓     | ✓      | —          |
| Google     | ✓    | ✓         | ✓     | ✓      | ✓          |
| OpenRouter | ✓    | ✓         | ✓     | ✓      | ✓*         |
| Local      | ✓    | ✓         | ✓     | ✓      | ✓*         |

\* Available depending on the model/service.

---

## Personas

Sathi ships with 6 predefined mascot personas:

| Mascot   | Name            | Role                | Best For            |
|----------|-----------------|---------------------|---------------------|
| 🎓        | Sathi Guru      | Mentor              | Thoughtful guidance |
| 🥷        | Sathi Ninja     | Efficiency Expert   | Quick answers       |
| 🐶        | Sathi Buddy     | Friendly Companion  | Casual support      |
| 🦉        | Sathi Sage      | Knowledge Oracle    | Technical Q&A       |
| ⚡        | Sathi Spark     | Creative Catalyst   | Brainstorming       |
| 🛡️        | Sathi Guardian  | Security Sentinel   | Compliance/Privacy  |

Custom personas can be created via **Sathi AI → Personas** or the REST API.

---

## REST API

Base: `{site}/wp-json/sathi/v1/`

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/chat/send` | POST | Public | Send a message |
| `/chat/conversations` | GET/POST | Public | List/Create conversations |
| `/chat/conversations/{uuid}` | GET/DELETE | Public | Get/Delete conversation |
| `/settings` | GET/POST | Admin | Read/Update settings |
| `/settings/providers` | GET | Admin | Provider configs |
| `/settings/providers/{key}/test` | POST | Admin | Test connection |
| `/personas` | GET/POST | Public/Admin | List/Create personas |
| `/personas/{slug}` | GET/PUT/DELETE | Public/Admin | CRUD persona |
| `/knowledge/search?q=...` | GET | Public | Search knowledge base |
| `/knowledge/stats` | GET | Admin | KB statistics |
| `/knowledge/index` | POST | Admin | Trigger crawl |
| `/knowledge/clear` | DELETE | Admin | Clear index |
| `/memory` | GET/DELETE | Public | Get/Clear memory |
| `/memory/{key}` | DELETE | Public | Delete entry |

---

## Roadmap

- **Phase 2** (Next): Live multi-provider streaming, message persistence, improved streaming UX
- **Phase 3**: Advanced memory with LLM-based summarization and management UI
- **Phase 4**: External vector stores (Pinecone, Qdrant, Chroma), improved semantic search
- **Phase 5**: Full persona studio, Connectors page takeover
- **Phase 6**: Real-time site navigation with full agent-driven tours
- **Phase 7**: Advanced 2026 UI polish, mobile, accessibility, internationalization
- **Phase 8**: WordPress Abilities, MCP server, WooCommerce function calling
- **Phase 9**: Cost controls, analytics, security, moderation, GDPR
- **Phase 10**: Full test suite, hardening, release packaging

---

## Credits

Built with ❤️ by [NEER Media](https://neermedia.com)

Architectural reference: AI Engine Pro (GPLv2, used for algorithm study only).  
All Sathi code is original and GPLv2 licensed. No license-bypass logic carried over.

---

**Sathi** (साथी) means "companion" in Hindi, Nepali, and many South Asian languages — an AI companion for your WordPress site.
