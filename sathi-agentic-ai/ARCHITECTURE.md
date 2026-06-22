# Sathi Agentic AI — Architecture Document

> Clean-room design. AI Engine Pro (GPLv2) studied for algorithmic reference only. All code original.

## Design Principles

1. **Provider-agnostic** — Single interface, swap LLMs freely. No caller changes required.
2. **Graceful degradation** — WP 7 integration is additive; everything works on WP 6.4+.
3. **Separation of concerns** — Chat, Memory, Knowledge, Navigation, Personas are independent modules.
4. **Typed DTOs** — Immutable value objects for messages, function calls, results. No array drift.
5. **12-factor** — Config via options, logs to file, stateless providers, admin UI via REST.
6. **Security-first** — API keys never logged, navigation allowlisted, tools capability-gated.

## Request Flow

```
Browser (React widget)
  │ POST /wp-json/sathi/v1/chat/send
  ▼
ChatController::send_message()
  │ Load/create conversation
  │ Compose system prompt from persona
  │ Retrieve memory context
  ▼
ChatManager::send_message()
  │ Persist user message
  │ Get provider via Factory::for_task('chat')
  │ Call provider->chat_stream() or provider->chat()
  ▼
Provider (OpenAI/Anthropic/etc.)
  │ HTTP request to LLM API
  │ Parse response → Message DTO
  │ Stream deltas to callback
  ▼
Back to ChatManager
  │ Persist assistant response
  │ Ingest conversation into memory
  ▼
ChatController
  │ Return JSON with content + actions
  ▼
React widget renders markdown, executes client actions
```

## Database Schema

### `sathi_conversations`
Core conversation table. Tracks user/guest sessions, active persona, provider, status.

### `sathi_messages`
Individual messages within a conversation. Supports tool_calls and tool_result JSON columns for function calling.

### `sathi_memory_entries`
Persistent key-value store per user/guest. Importance scoring, expiry, source conversation tracking.

### `sathi_knowledge_chunks`
Chunked site content with source tracking, checksums for change detection, cached embeddings.

### `sathi_personas`
Custom persona definitions. Predefined mascots are hard-coded and can be overridden per-row.

## Provider Architecture

```
ProviderInterface (Contracts/)
    ▲
    ├── OpenAI      (api.openai.com/v1)
    ├── Anthropic   (api.anthropic.com/v1)
    ├── Gemini      (generativelanguage.googleapis.com)
    ├── OpenRouter  (openrouter.ai/api/v1)
    └── Local       (localhost:11434/v1, Ollama-compatible)

Factory::for_task('chat')    → OpenAI
Factory::for_task('embed')   → OpenAI (different model)
Factory::for_task('image')   → OpenAI
Factory::for_task('moderation') → OpenAI

This per-task routing is a structural advantage over WP 7's Connectors,
which have no concept of "default per task."
```

## Security Model

### API Keys
- Stored in `wp_options` via Settings class
- Masked in REST responses (always show `sk-...****...ab12`)
- Never logged or exposed in HTML
- Bring-your-own — no hardcoded keys

### Client Actions (Navigation)
- Action types are allowlisted (`navigate`, `scroll_to`, `highlight`, `focus_input`, `open_contact`)
- URLs validated against site domain — no external redirects
- CSS selectors validated against allowlist patterns
- No destructive actions (no `click`, no `submit`, no `delete`)

### REST API
- Admin endpoints: `current_user_can('manage_options')`
- Public endpoints: read-only or scoped to own session (guest_id + user_id matching)
- Nonce validation via `X-WP-Nonce` header

### GDPR / Privacy
- Guest identification via one-way SHA-256(IP+UA) — not stored plaintext
- Memory entries have TTL (default 90 days)
- Clear-all endpoints for user data deletion
- No cookies beyond WordPress session

## WordPress 7 Integration Strategy

All WP 7 code lives in `/labs/wp7-integration/`. The bridge:

1. **Detects** `wp_ai_client_prompt()` — if absent, bridge is inert.
2. **Registers Connectors** — shows Sathi environments on WP's Connectors page.
3. **Maps Abilities** — Sathi tools become WP Abilities (AI function calls + MCP tools + REST endpoints).
4. **Subscribes to events** — for cost tracking and prompt guardrails.

The WP 7 AI framework is young; Sathi's independent path never depends on it.

## Frontend Architecture

### Chat Widget
- **React 18** with functional components and hooks
- **Zustand** for lightweight state management (messages, streaming, UI state)
- **Tailwind CSS** with CSS custom properties for theming (`--sathi-*`)
- **React-Markdown** for assistant responses with `react-syntax-highlighter` for code blocks
- **Client action protocol**: JSON actions attached to messages, executed by the widget

### Admin Panel
- **React 18** with WP components compatibility
- **Tabs**: Overview, Providers, Personas, Knowledge
- **REST-driven** — all data fetched from Sathi REST API
- **Minimal** — designed to feel native in WP admin

### Build Pipeline
- **Vite 5** — fast builds with code splitting
- **TypeScript** — strict mode, no implicit any
- **PostCSS** — Tailwind + Autoprefixer
- **Two entry points**: `chat-widget.js` + `admin.js`

## Testing Strategy

- **PHPUnit** for Core, Providers, DTOs, and REST controllers
- **WP-CLI** for integration testing (activate, deactivate, schema migration)
- **PHPStan** at level 5 for static analysis
- **ESLint** + TypeScript for frontend

## Clean-Room Note

AI Engine Pro (v3.5.3, GPLv2) by Jordy Meow was used as an *algorithmic and architectural reference only*. Specific patterns studied:
- Provider abstraction with factory pattern
- DTO separation (FunctionCall, FunctionResult as value objects)
- REST API structure with per-module controllers
- Streaming SSE parsing approach

No license-bypass logic, no copyrighted UI, no compiled assets, and no proprietary code were carried over. Sathi Agentic AI is 100% original code written by NEER Media and licensed GPLv2.
