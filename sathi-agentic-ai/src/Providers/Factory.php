<?php
/**
 * Provider factory — resolves the correct adapter for a given provider key.
 *
 * Handles per-task default routing: chat vs embeddings vs images can each
 * target a different provider.
 *
 * @package NeerMedia\Sathi\Providers
 */

namespace NeerMedia\Sathi\Providers;

use NeerMedia\Sathi\Core\Settings;
use NeerMedia\Sathi\Providers\Contracts\ProviderInterface;

class Factory {

    /** @var Settings */
    private Settings $settings;

    /** @var array<string, ProviderInterface> Instantiated adapters */
    private array $adapters = [];

    /** @var array<string, string> Task → provider key routing */
    private array $task_routing = [];

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
        $this->task_routing = [
            'chat'       => $settings->get( 'sathi_chat_provider', $settings->get( Settings::KEY_DEFAULT_PROVIDER ) ),
            'embed'      => $settings->get( 'sathi_embed_provider', $settings->get( Settings::KEY_DEFAULT_PROVIDER ) ),
            'image'      => $settings->get( 'sathi_image_provider', $settings->get( Settings::KEY_DEFAULT_PROVIDER ) ),
            'moderation' => $settings->get( 'sathi_moderation_provider', $settings->get( Settings::KEY_DEFAULT_PROVIDER ) ),
        ];
    }

    /**
     * Get a provider adapter for a specific task.
     *
     * @param  string $task 'chat', 'embed', 'image', 'moderation'
     * @return ProviderInterface
     * @throws \RuntimeException If no provider is configured for the task.
     */
    public function for_task( string $task = 'chat' ): ProviderInterface {
        $provider_key = $this->task_routing[ $task ] ?? $this->settings->get( Settings::KEY_DEFAULT_PROVIDER, 'openai' );
        return $this->make( $provider_key );
    }

    /**
     * Get a provider adapter by key.
     *
     * @param  string $key 'openai', 'anthropic', 'google', 'openrouter', 'local'
     * @return ProviderInterface
     * @throws \RuntimeException
     */
    public function make( string $key ): ProviderInterface {
        if ( isset( $this->adapters[ $key ] ) ) {
            return $this->adapters[ $key ];
        }

        $config  = $this->settings->get_provider_config( $key ) ?: [];
        $catalog = ProviderCatalog::get( $key );

        // Merge catalog defaults into the stored config for the adapter.
        $merged = array_merge( [
            'key'           => $key,
            'label'         => $catalog['label'] ?? $key,
            'base_url'      => ! empty( $config['base_url'] ) ? $config['base_url'] : ( $catalog['base_url'] ?? '' ),
            'default_model' => $catalog['default_model'] ?? '',
            'needs_key'     => $catalog['needs_key'] ?? true,
        ], $config );

        // OpenRouter wants attribution headers.
        if ( $key === 'openrouter' ) {
            $merged['extra_headers'] = [
                'HTTP-Referer' => home_url(),
                'X-Title'      => 'Saathi Agentic AI',
            ];
        }

        $adapter_type = $catalog['adapter'] ?? 'openai_compatible';
        $adapter = match ( $adapter_type ) {
            'anthropic' => new Anthropic( $config ),
            'gemini'    => new Gemini( $config ),
            'cohere'    => new Cohere( $config ),
            default     => new OpenAICompatible( $merged ),
        };

        // Unknown key with no catalog entry: allow a filter, else fall back to a
        // generic OpenAI-compatible adapter if a base URL was provided.
        if ( ! $catalog ) {
            $filtered = apply_filters( 'sathi_provider_' . $key, null, $config );
            if ( $filtered instanceof ProviderInterface ) {
                $adapter = $filtered;
            }
        }

        $this->adapters[ $key ] = $adapter;
        return $adapter;
    }

    /**
     * Resolve the provider configured for embeddings (may differ from chat).
     */
    public function for_embeddings(): ProviderInterface {
        $key = $this->settings->get( 'sathi_embed_provider', '' );
        if ( ! $key ) {
            $key = $this->settings->get( Settings::KEY_DEFAULT_PROVIDER, 'openai' );
        }
        return $this->make( $key );
    }

    /**
     * The model to use for embeddings (separate from chat).
     *
     * Provider-aware: picks a sensible default for the resolved embeddings
     * provider, and auto-corrects the common misconfiguration where the embed
     * provider is non-OpenAI but the model still holds the OpenAI default
     * (which would fail). This lets FREE embeddings — notably Google Gemini's
     * `text-embedding-004` — work out of the box with zero extra setup.
     */
    public function embedding_model(): string {
        $model = (string) $this->settings->get( 'sathi_embed_model', '' );
        $key   = $this->settings->get( 'sathi_embed_provider', '' )
            ?: $this->settings->get( Settings::KEY_DEFAULT_PROVIDER, 'openai' );

        $is_openai_default = ( $model === '' || $model === 'text-embedding-3-small' || $model === 'text-embedding-3-large' );

        switch ( $key ) {
            case 'google':
            case 'gemini':
                return $is_openai_default ? 'text-embedding-004' : $model;   // FREE tier
            case 'cohere':
                return $is_openai_default ? 'embed-multilingual-v3.0' : $model;
            case 'local':
                return $model !== '' ? $model : 'nomic-embed-text';
            default: // openai / openai-compatible
                return $model !== '' ? $model : 'text-embedding-3-small';
        }
    }

    /**
     * Set the provider for a specific task at runtime.
     *
     * @param string $task
     * @param string $provider_key
     */
    public function route_task( string $task, string $provider_key ): void {
        $this->task_routing[ $task ] = $provider_key;
    }

    /**
     * Get all instantiated adapters.
     *
     * @return array<string, ProviderInterface>
     */
    public function all(): array {
        return $this->adapters;
    }

    /**
     * List all registered provider keys.
     *
     * @return string[]
     */
    public function available_keys(): array {
        return ProviderCatalog::keys();
    }
}
