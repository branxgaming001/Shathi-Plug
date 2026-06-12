<?php
/**
 * Provider Catalog — single source of truth for every supported AI provider.
 *
 * Most providers speak the OpenAI Chat Completions dialect, so they share the
 * OpenAICompatible adapter and only need a config entry here. Anthropic, Gemini
 * and Cohere have their own adapters. Adding a new provider later is usually
 * just one new entry in this array.
 *
 * @package RaiLabs\Sathi\Providers
 */

namespace RaiLabs\Sathi\Providers;

class ProviderCatalog {

    /**
     * Return the full provider catalog.
     *
     * Each entry: label, adapter, base_url, default_model, needs_key,
     * has_model_list (provider exposes GET /models), color (brand-ish dot),
     * group (cloud | aggregator | local | custom), docs (where to get a key).
     *
     * @return array<string, array>
     */
    public static function all(): array {
        return [
            // ── Major cloud providers (OpenAI-compatible) ──────────────
            'openai' => [
                'label' => 'OpenAI', 'adapter' => 'openai_compatible', 'group' => 'cloud',
                'base_url' => 'https://api.openai.com/v1', 'default_model' => 'gpt-4o-mini',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#10a37f',
                'docs' => 'platform.openai.com/api-keys',
                'models' => [ 'gpt-4o', 'gpt-4o-mini', 'o3-mini', 'o1', 'o1-mini' ],
            ],
            'deepseek' => [
                'label' => 'DeepSeek', 'adapter' => 'openai_compatible', 'group' => 'cloud',
                'base_url' => 'https://api.deepseek.com/v1', 'default_model' => 'deepseek-chat',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#4d6bfe',
                'docs' => 'platform.deepseek.com', 'models' => [ 'deepseek-chat', 'deepseek-reasoner' ],
            ],
            'mistral' => [
                'label' => 'Mistral AI', 'adapter' => 'openai_compatible', 'group' => 'cloud',
                'base_url' => 'https://api.mistral.ai/v1', 'default_model' => 'mistral-large-latest',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#fa520f',
                'docs' => 'console.mistral.ai', 'models' => [ 'mistral-large-latest', 'mistral-small-latest', 'open-mistral-nemo' ],
            ],
            'perplexity' => [
                'label' => 'Perplexity', 'adapter' => 'openai_compatible', 'group' => 'cloud',
                'base_url' => 'https://api.perplexity.ai', 'default_model' => 'sonar',
                'needs_key' => true, 'has_model_list' => false, 'color' => '#20808d',
                'docs' => 'perplexity.ai/settings/api', 'models' => [ 'sonar', 'sonar-pro', 'sonar-reasoning' ],
            ],
            'xai' => [
                'label' => 'xAI Grok', 'adapter' => 'openai_compatible', 'group' => 'cloud',
                'base_url' => 'https://api.x.ai/v1', 'default_model' => 'grok-2-latest',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#141414',
                'docs' => 'console.x.ai', 'models' => [ 'grok-2-latest', 'grok-2-vision-latest', 'grok-beta' ],
            ],
            'cohere' => [
                'label' => 'Cohere', 'adapter' => 'cohere', 'group' => 'cloud',
                'base_url' => 'https://api.cohere.com', 'default_model' => 'command-r-plus',
                'needs_key' => true, 'has_model_list' => false, 'color' => '#39594d',
                'docs' => 'dashboard.cohere.com/api-keys', 'models' => [ 'command-r-plus', 'command-r', 'command' ],
            ],

            // ── Dedicated adapters ─────────────────────────────────────
            'anthropic' => [
                'label' => 'Anthropic Claude', 'adapter' => 'anthropic', 'group' => 'cloud',
                'base_url' => 'https://api.anthropic.com/v1', 'default_model' => 'claude-3-5-sonnet-20241022',
                'needs_key' => true, 'has_model_list' => false, 'color' => '#d97757',
                'docs' => 'console.anthropic.com', 'models' => [ 'claude-3-5-sonnet-20241022', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229' ],
            ],
            'google' => [
                'label' => 'Google Gemini', 'adapter' => 'gemini', 'group' => 'cloud',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'default_model' => 'gemini-1.5-flash',
                'needs_key' => true, 'has_model_list' => false, 'color' => '#4285f4',
                'docs' => 'aistudio.google.com/apikey', 'models' => [ 'gemini-1.5-pro', 'gemini-1.5-flash', 'gemini-2.0-flash-exp' ],
            ],

            // ── Aggregators & fast inference (OpenAI-compatible) ───────
            'openrouter' => [
                'label' => 'OpenRouter', 'adapter' => 'openai_compatible', 'group' => 'aggregator',
                'base_url' => 'https://openrouter.ai/api/v1', 'default_model' => 'openai/gpt-4o-mini',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#6566f1',
                'docs' => 'openrouter.ai/keys', 'models' => [ 'openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'meta-llama/llama-3.3-70b-instruct', 'qwen/qwen-2.5-72b-instruct' ],
            ],
            'groq' => [
                'label' => 'Groq', 'adapter' => 'openai_compatible', 'group' => 'aggregator',
                'base_url' => 'https://api.groq.com/openai/v1', 'default_model' => 'llama-3.3-70b-versatile',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#f55036',
                'docs' => 'console.groq.com/keys', 'models' => [ 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768' ],
            ],
            'together' => [
                'label' => 'Together AI', 'adapter' => 'openai_compatible', 'group' => 'aggregator',
                'base_url' => 'https://api.together.xyz/v1', 'default_model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#0f6fff',
                'docs' => 'api.together.ai', 'models' => [ 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'Qwen/Qwen2.5-72B-Instruct-Turbo' ],
            ],
            'fireworks' => [
                'label' => 'Fireworks AI', 'adapter' => 'openai_compatible', 'group' => 'aggregator',
                'base_url' => 'https://api.fireworks.ai/inference/v1', 'default_model' => 'accounts/fireworks/models/llama-v3p3-70b-instruct',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#5019c5',
                'docs' => 'fireworks.ai', 'models' => [ 'accounts/fireworks/models/llama-v3p3-70b-instruct' ],
            ],

            // ── Self-hosted / local (no key) ───────────────────────────
            'ollama' => [
                'label' => 'Ollama (local)', 'adapter' => 'openai_compatible', 'group' => 'local',
                'base_url' => 'http://localhost:11434/v1', 'default_model' => 'llama3.2',
                'needs_key' => false, 'has_model_list' => true, 'color' => '#64748b',
                'docs' => 'localhost:11434', 'models' => [ 'llama3.2', 'mistral', 'gemma2', 'qwen2.5' ],
            ],
            'lmstudio' => [
                'label' => 'LM Studio (local)', 'adapter' => 'openai_compatible', 'group' => 'local',
                'base_url' => 'http://localhost:1234/v1', 'default_model' => 'local-model',
                'needs_key' => false, 'has_model_list' => true, 'color' => '#7b8da9',
                'docs' => 'localhost:1234', 'models' => [ 'local-model' ],
            ],

            // ── Universal fallback ─────────────────────────────────────
            'custom' => [
                'label' => 'Custom (OpenAI-compatible)', 'adapter' => 'openai_compatible', 'group' => 'custom',
                'base_url' => '', 'default_model' => '',
                'needs_key' => true, 'has_model_list' => true, 'color' => '#c9a84c',
                'docs' => 'Any OpenAI-compatible base URL', 'models' => [],
            ],
        ];
    }

    /**
     * Get a single provider's catalog entry (or null).
     *
     * @param string $key
     * @return array|null
     */
    public static function get( string $key ): ?array {
        return self::all()[ $key ] ?? null;
    }

    /**
     * All provider keys.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_keys( self::all() );
    }

    /**
     * Keys that can supply embeddings (OpenAI-compatible + the ones with embed support).
     *
     * @return string[]
     */
    public static function embedding_keys(): array {
        return [ 'openai', 'mistral', 'cohere', 'ollama', 'lmstudio', 'openrouter', 'together', 'custom' ];
    }
}
