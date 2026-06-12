<?php
/**
 * Centralised settings registry.
 *
 * All plugin options flow through this class — never call get_option('sathi_*') directly.
 *
 * @package RaiLabs\Sathi\Core
 */

namespace RaiLabs\Sathi\Core;

class Settings {

    /** @var array<string, mixed> Cached options */
    private array $cache = [];

    /** @var array<string, array> Registered setting definitions */
    private array $registered = [];

    /** @var bool Whether settings have been registered with the WP Settings API */
    private bool $api_registered = false;

    // ── Well-known option keys ────────────────────────────────────────

    public const KEY_DEFAULT_PROVIDER   = 'sathi_default_provider';
    public const KEY_ENABLED_PROVIDERS  = 'sathi_enabled_providers';
    public const KEY_STREAMING_ENABLED  = 'sathi_streaming_enabled';
    public const KEY_MAX_HISTORY        = 'sathi_max_history';
    public const KEY_DEFAULT_TIMEOUT    = 'sathi_default_timeout';
    public const KEY_FLOATING_WIDGET    = 'sathi_floating_widget';
    public const KEY_FLOATING_POSITION  = 'sathi_floating_position';
    public const KEY_ACCENT_COLOR       = 'sathi_accent_color';
    public const KEY_CHAT_GREETING      = 'sathi_chat_greeting';
    public const KEY_KNOWLEDGE_AUTO     = 'sathi_knowledge_auto_crawl';
    public const KEY_KNOWLEDGE_INTERVAL = 'sathi_knowledge_crawl_interval';
    public const KEY_MEMORY_ENABLED     = 'sathi_memory_enabled';
    public const KEY_MEMORY_TTL         = 'sathi_memory_ttl_days';
    public const KEY_LOG_LEVEL          = 'sathi_log_level';
    public const KEY_PROVIDER_CONFIGS   = 'sathi_provider_configs';
    public const KEY_DEFAULT_PERSONA    = 'sathi_default_persona';
    public const KEY_MODERATION_ENABLED = 'sathi_moderation_enabled';

    // ── Widget appearance ─────────────────────────────────────────────
    public const KEY_WIDGET_TITLE         = 'sathi_widget_title';
    public const KEY_WIDGET_THEME         = 'sathi_widget_theme';          // light | dark | auto
    public const KEY_WIDGET_LAUNCHER_ICON = 'sathi_widget_launcher_icon';  // emoji or "chat"
    public const KEY_WIDGET_AVATAR        = 'sathi_widget_avatar';         // mascot-1..5 | spark | none
    public const KEY_WIDGET_AUTO_OPEN     = 'sathi_widget_auto_open';
    public const KEY_WIDGET_AUTO_OPEN_DELAY = 'sathi_widget_auto_open_delay';

    // ── Widget placement ──────────────────────────────────────────────
    public const KEY_WIDGET_DISPLAY_MODE  = 'sathi_widget_display_mode';   // all | include | exclude
    public const KEY_WIDGET_DISPLAY_PAGES = 'sathi_widget_display_pages';  // int[] of post/page IDs
    public const KEY_WIDGET_POST_TYPES    = 'sathi_widget_post_types';     // string[] of post types
    public const KEY_WIDGET_LOGGED_IN_ONLY = 'sathi_widget_logged_in_only';

    // ── Embeddings (separate, often cheaper provider/model) ───────────
    public const KEY_EMBED_PROVIDER = 'sathi_embed_provider';
    public const KEY_EMBED_MODEL    = 'sathi_embed_model';

    // ── Knowledge scope + commerce ────────────────────────────────────
    public const KEY_STRICT_SCOPE  = 'sathi_strict_scope';   // answer only from site content
    public const KEY_PRODUCT_CARDS = 'sathi_product_cards';  // show WooCommerce product cards in chat

    // ── Licensing ─────────────────────────────────────────────────────
    public const KEY_LICENSE_KEY        = 'sathi_license_key';        // encrypted; NOT registered (kept out of generic settings)
    public const KEY_LICENSE_SERVER_URL = 'sathi_license_server_url';
    public const KEY_LICENSE_ENFORCE    = 'sathi_license_enforce';    // gating OFF by default

    /**
     * Get a single option with type-safe default.
     *
     * @template T
     * @param  string $key     Option name.
     * @param  T|null $default Fallback if unset.
     * @return T|mixed
     */
    public function get( string $key, $default = null ) {
        if ( isset( $this->cache[ $key ] ) ) {
            return $this->cache[ $key ];
        }

        $value = get_option( $key, $default );
        $this->cache[ $key ] = $value;
        return $value;
    }

    /**
     * Set an option and bust local cache.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function set( string $key, $value ): bool {
        $updated = update_option( $key, $value );
        if ( $updated ) {
            $this->cache[ $key ] = $value;
        }
        return $updated;
    }

    /**
     * Get entire provider configuration array.
     *
     * @return array<string, array>
     */
    public function get_provider_configs(): array {
        return $this->get( self::KEY_PROVIDER_CONFIGS, [] );
    }

    /**
     * Get configuration for a specific provider.
     *
     * @param  string $provider Provider key (openai, anthropic, etc.)
     * @return array|null
     */
    public function get_provider_config( string $provider ): ?array {
        $configs = $this->get_provider_configs();
        $cfg = $configs[ $provider ] ?? null;
        if ( is_array( $cfg ) && isset( $cfg['api_key'] ) ) {
            // Decrypt the at-rest key for adapter use.
            $cfg['api_key'] = \RaiLabs\Sathi\Support\Helpers::decrypt( (string) $cfg['api_key'] );
        }
        return $cfg;
    }

    /**
     * Set configuration for a specific provider.
     *
     * @param string $provider
     * @param array  $config   { api_key, model, temperature, max_tokens, ... }
     * @return bool
     */
    public function set_provider_config( string $provider, array $config ): bool {
        // Note: get_provider_configs() returns raw (still-encrypted) values for
        // other providers, so we only (re)encrypt the one being saved.
        $configs = $this->get_provider_configs();
        if ( ! empty( $config['api_key'] ) && strncmp( (string) $config['api_key'], 'enc:', 4 ) !== 0 ) {
            $config['api_key'] = \RaiLabs\Sathi\Support\Helpers::encrypt( (string) $config['api_key'] );
        }
        $configs[ $provider ] = $config;
        return $this->set( self::KEY_PROVIDER_CONFIGS, $configs );
    }

    /**
     * Get the default persona slug.
     */
    public function get_default_persona(): string {
        return $this->get( self::KEY_DEFAULT_PERSONA, 'sathi-guru' );
    }

    /**
     * Whether a provider is enabled.
     */
    public function is_provider_enabled( string $provider ): bool {
        $enabled = $this->get( self::KEY_ENABLED_PROVIDERS, [ 'openai' ] );
        return in_array( $provider, $enabled, true );
    }

    /**
     * Register all settings with the WordPress Settings API.
     */
    public function register_with_wp_api(): void {
        if ( $this->api_registered ) {
            return;
        }

        $this->registered = [
            self::KEY_DEFAULT_PROVIDER => [
                'type'    => 'string',
                'default' => 'openai',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_ENABLED_PROVIDERS => [
                'type'    => 'array',
                'default' => [ 'openai' ],
                'sanitize'=> [ $this, 'sanitize_provider_list' ],
            ],
            self::KEY_STREAMING_ENABLED => [
                'type'    => 'boolean',
                'default' => true,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_MAX_HISTORY => [
                'type'    => 'integer',
                'default' => SATHI_MAX_HISTORY_LENGTH,
                'sanitize'=> 'absint',
            ],
            self::KEY_FLOATING_WIDGET => [
                'type'    => 'boolean',
                'default' => true,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_FLOATING_POSITION => [
                'type'    => 'string',
                'default' => 'bottom-right',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_ACCENT_COLOR => [
                'type'    => 'string',
                'default' => '#6D5DFB',
                'sanitize'=> 'sanitize_hex_color',
            ],
            self::KEY_MEMORY_ENABLED => [
                'type'    => 'boolean',
                'default' => true,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_MEMORY_TTL => [
                'type'    => 'integer',
                'default' => 90,
                'sanitize'=> 'absint',
            ],
            self::KEY_KNOWLEDGE_AUTO => [
                'type'    => 'boolean',
                'default' => true,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_KNOWLEDGE_INTERVAL => [
                'type'    => 'string',
                'default' => 'daily',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_LOG_LEVEL => [
                'type'    => 'string',
                'default' => 'warning',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_DEFAULT_PERSONA => [
                'type'    => 'string',
                'default' => 'sathi-guru',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_MODERATION_ENABLED => [
                'type'    => 'boolean',
                'default' => false,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_PROVIDER_CONFIGS => [
                'type'    => 'array',
                'default' => [],
                'sanitize'=> [ $this, 'sanitize_provider_configs' ],
            ],
            self::KEY_WIDGET_TITLE => [
                'type'    => 'string',
                'default' => '',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_WIDGET_THEME => [
                'type'    => 'string',
                'default' => 'light',
                'sanitize'=> [ $this, 'sanitize_widget_theme' ],
            ],
            self::KEY_WIDGET_LAUNCHER_ICON => [
                'type'    => 'string',
                'default' => 'chat',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_WIDGET_AVATAR => [
                'type'    => 'string',
                'default' => 'mascot-1',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_WIDGET_AUTO_OPEN => [
                'type'    => 'boolean',
                'default' => false,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_WIDGET_AUTO_OPEN_DELAY => [
                'type'    => 'integer',
                'default' => 5,
                'sanitize'=> 'absint',
            ],
            self::KEY_WIDGET_DISPLAY_MODE => [
                'type'    => 'string',
                'default' => 'all',
                'sanitize'=> [ $this, 'sanitize_display_mode' ],
            ],
            self::KEY_WIDGET_DISPLAY_PAGES => [
                'type'    => 'array',
                'default' => [],
                'sanitize'=> [ $this, 'sanitize_id_list' ],
            ],
            self::KEY_WIDGET_POST_TYPES => [
                'type'    => 'array',
                'default' => [],
                'sanitize'=> [ $this, 'sanitize_slug_list' ],
            ],
            self::KEY_WIDGET_LOGGED_IN_ONLY => [
                'type'    => 'boolean',
                'default' => false,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_EMBED_PROVIDER => [
                'type'    => 'string',
                'default' => '',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_EMBED_MODEL => [
                'type'    => 'string',
                'default' => 'text-embedding-3-small',
                'sanitize'=> 'sanitize_text_field',
            ],
            self::KEY_STRICT_SCOPE => [
                'type'    => 'boolean',
                'default' => true,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_PRODUCT_CARDS => [
                'type'    => 'boolean',
                'default' => true,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
            self::KEY_LICENSE_SERVER_URL => [
                'type'    => 'string',
                'default' => '',
                'sanitize'=> 'esc_url_raw',
            ],
            self::KEY_LICENSE_ENFORCE => [
                'type'    => 'boolean',
                'default' => false,
                'sanitize'=> 'rest_sanitize_boolean',
            ],
        ];

        foreach ( $this->registered as $key => $def ) {
            register_setting( 'sathi_settings', $key, [
                'type'              => $def['type'],
                'default'           => $def['default'],
                'sanitize_callback' => $def['sanitize'],
                'show_in_rest'      => true,
            ] );
        }

        $this->api_registered = true;
    }

    /**
     * Get all registered setting keys.
     *
     * Self-initializes the registry if it has not been populated yet. This is
     * essential in REST requests (GET/POST /settings), where register_with_wp_api()
     * — hooked to admin_init — never runs, which previously left this list empty
     * and silently dropped every settings read/write.
     *
     * @return string[]
     */
    public function get_registered_keys(): array {
        if ( empty( $this->registered ) ) {
            $this->register_with_wp_api();
        }
        return array_keys( $this->registered );
    }

    /**
     * Sanitize provider list.
     *
     * @param  mixed $value
     * @return string[]
     */
    public function sanitize_provider_list( $value ): array {
        if ( ! is_array( $value ) ) {
            return [ 'openai' ];
        }
        $allowed = \RaiLabs\Sathi\Providers\ProviderCatalog::keys();
        return array_values( array_intersect( $value, $allowed ) );
    }

    /**
     * Sanitize provider configs — never store raw API keys in plaintext.
     *
     * @param  mixed $value
     * @return array
     */
    public function sanitize_provider_configs( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $clean = [];
        foreach ( $value as $provider => $config ) {
            if ( ! is_array( $config ) ) {
                continue;
            }
            $clean[ $provider ] = [
                'api_key'       => sanitize_text_field( $config['api_key'] ?? '' ),
                'model'         => sanitize_text_field( $config['model'] ?? '' ),
                'temperature'   => (float) ( $config['temperature'] ?? 0.7 ),
                'max_tokens'    => absint( $config['max_tokens'] ?? 4096 ),
                'base_url'      => esc_url_raw( $config['base_url'] ?? '' ),
                'timeout'       => absint( $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT ),
            ];
        }

        return $clean;
    }

    /**
     * Sanitize the widget theme value.
     *
     * @param  mixed $value
     * @return string
     */
    public function sanitize_widget_theme( $value ): string {
        $value = sanitize_text_field( (string) $value );
        return in_array( $value, [ 'light', 'dark', 'auto' ], true ) ? $value : 'light';
    }

    /**
     * Sanitize the placement display mode.
     *
     * @param  mixed $value
     * @return string
     */
    public function sanitize_display_mode( $value ): string {
        $value = sanitize_text_field( (string) $value );
        return in_array( $value, [ 'all', 'include', 'exclude' ], true ) ? $value : 'all';
    }

    /**
     * Sanitize an array of integer IDs (page/post IDs for placement rules).
     *
     * @param  mixed $value
     * @return int[]
     */
    public function sanitize_id_list( $value ): array {
        if ( is_string( $value ) ) {
            $value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
        }
        if ( ! is_array( $value ) ) {
            return [];
        }
        $ids = array_map( 'absint', $value );
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Sanitize an array of post-type slugs.
     *
     * @param  mixed $value
     * @return string[]
     */
    public function sanitize_slug_list( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $slugs = array_map( 'sanitize_key', $value );
        return array_values( array_unique( array_filter( $slugs ) ) );
    }

    /**
     * Warm the cache by loading all registered keys.
     */
    public function warm(): void {
        foreach ( $this->registered as $key => $def ) {
            $this->cache[ $key ] = get_option( $key, $def['default'] );
        }
    }

    /**
     * Invalidate the local cache (force re-read from DB).
     */
    public function invalidate(): void {
        $this->cache = [];
    }
}
