# Saathi Agentic AI — Hooks Reference

Public extension points for developers and AI assistants. Use these to customise
behaviour without editing core. By **NEER Media** — https://neermedia.com

> Convention: actions are side-effects, filters return a (possibly modified) value.
> All hook names are prefixed `sathi_`.

## Lifecycle actions
| Hook | When | Args |
|---|---|---|
| `sathi_init` | Plugin booted, services wired | — |
| `sathi_booted` | After full boot (REST + hooks ready) | — |
| `sathi_activated` | On plugin activation | — |
| `sathi_deactivated` | On plugin deactivation | — |
| `sathi_rest_routes_registered` | After REST routes registered | `\WP_REST_Server` |

## Chat & agent
| Hook | Type | Purpose |
|---|---|---|
| `sathi_system_prompt` | filter | Modify the system prompt sent to the model. |
| `sathi_chat_tools` | filter | Add/remove tool (function-calling) definitions. |
| `sathi_chat_actions` | filter | Adjust client actions the bot may trigger. |
| `sathi_agent_max_rounds` | filter | Max agent tool-call rounds (default safe cap). |
| `sathi_execute_batch_calls` | filter | Toggle batched tool execution. |
| `sathi_cost_cap_check` | filter | Enforce a per-request cost/usage cap. |

## Knowledge / scanning
| Hook | Type | Purpose |
|---|---|---|
| `sathi_knowledge_post_types` | filter | Which post types are scanned (published only). |
| `sathi_chunk_size` / `sathi_chunk_overlap` | filter | RAG chunking parameters. |
| `sathi_embed_batch_size` / `sathi_knowledge_batch_size` | filter | Batch sizes for embedding/indexing. |
| `sathi_vector_search_cap` | filter | Max vector results per query. |
| `sathi_allowed_selectors` | filter | CSS selectors used by the site crawler. |

## Providers & licensing
| Hook | Type | Purpose |
|---|---|---|
| `sathi_provider_{slug}` | filter | Customise a specific AI provider config. |
| `sathi_license_server_url` | filter | Override the license server URL. |
| `sathi_license_enforce` | filter | Toggle license enforcement (default off). |

## Widget, privacy & telemetry
| Hook | Type | Purpose |
|---|---|---|
| `sathi_should_display_widget` | filter | Show/hide the widget per request. |
| `sathi_module_script_handles` | filter | Registered front-end script handles. |
| `sathi_require_consent` | filter | Require visitor consent before chat. |
| `sathi_moderation_blocked` / `sathi_moderation_warnings` | action | Fired on moderation outcomes. |
| `sathi_stats_event` | action | Emitted for analytics events. |

_When adding a new hook, document it here with an `@since` version._
