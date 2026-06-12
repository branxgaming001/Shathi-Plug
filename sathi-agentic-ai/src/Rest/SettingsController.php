<?php
/**
 * Settings REST Controller — read/write plugin settings.
 *
 * @package RaiLabs\Sathi\Rest
 */

namespace RaiLabs\Sathi\Rest;

use RaiLabs\Sathi\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

class SettingsController {

    private const NAMESPACE = 'sathi/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_settings' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_settings' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/providers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_providers' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/providers/(?P<provider>[a-z]+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_provider' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/providers/(?P<provider>[a-z]+)/test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test_provider' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/providers/(?P<provider>[a-z]+)/models', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_models' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/settings/mascots', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_mascots' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );
    }

    /**
     * Return the bundled mascots (data URIs) + labels for the avatar picker.
     */
    public function list_mascots( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( [
            'mascots' => \RaiLabs\Sathi\Support\Mascots::all(),     // id => first (neutral) frame
            'frames'  => \RaiLabs\Sathi\Support\Mascots::frames(),  // id => [expression frames]
            'labels'  => \RaiLabs\Sathi\Support\Mascots::labels(),
        ] );
    }

    /**
     * Fetch the live model list for a provider (best-effort).
     */
    public function list_models( WP_REST_Request $request ): WP_REST_Response {
        $provider = sanitize_text_field( $request->get_param( 'provider' ) );
        $settings = new Settings();
        $factory  = new \RaiLabs\Sathi\Providers\Factory( $settings );

        $catalog = \RaiLabs\Sathi\Providers\ProviderCatalog::get( $provider );
        $fallback = $catalog['models'] ?? [];

        try {
            $adapter = $factory->make( $provider );
            $models  = method_exists( $adapter, 'fetch_models' ) ? $adapter->fetch_models() : [];
            if ( empty( $models ) ) {
                $models = $fallback;
            }
            return new WP_REST_Response( [ 'models' => array_values( $models ), 'source' => empty( $models ) ? 'none' : 'live' ] );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'models' => array_values( $fallback ), 'source' => 'fallback', 'error' => $e->getMessage() ] );
        }
    }

    /**
     * Get all settings.
     */
    public function get_settings( WP_REST_Request $request ): WP_REST_Response {
        $settings = new Settings();
        $data     = [];

        foreach ( $settings->get_registered_keys() as $key ) {
            $data[ $key ] = $settings->get( $key );
        }

        return new WP_REST_Response( [ 'settings' => $data ] );
    }

    /**
     * Update settings.
     */
    public function update_settings( WP_REST_Request $request ): WP_REST_Response {
        $settings = new Settings();
        $data     = $request->get_json_params();

        $updated = [];
        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $settings->get_registered_keys() ) ) {
                $settings->set( $key, $value );
                $updated[] = $key;
            }
        }

        $settings->invalidate();

        return new WP_REST_Response( [
            'success' => true,
            'updated' => $updated,
        ] );
    }

    /**
     * Get all provider configurations.
     */
    public function get_providers( WP_REST_Request $request ): WP_REST_Response {
        $settings = new Settings();
        $configs  = $settings->get_provider_configs();

        // Mask API keys for security
        foreach ( $configs as $provider => $config ) {
            if ( ! empty( $config['api_key'] ) ) {
                $configs[ $provider ]['api_key'] = \RaiLabs\Sathi\Support\Helpers::mask_key( $config['api_key'] );
            }
        }

        return new WP_REST_Response( [
            'providers'      => $configs,
            'available'      => \RaiLabs\Sathi\Providers\ProviderCatalog::keys(),
            'catalog'        => \RaiLabs\Sathi\Providers\ProviderCatalog::all(),
            'embedding_keys' => \RaiLabs\Sathi\Providers\ProviderCatalog::embedding_keys(),
            'default'        => $settings->get( Settings::KEY_DEFAULT_PROVIDER ),
            'enabled'        => $settings->get( Settings::KEY_ENABLED_PROVIDERS ),
            'embed_provider' => $settings->get( Settings::KEY_EMBED_PROVIDER, '' ),
            'embed_model'    => $settings->get( Settings::KEY_EMBED_MODEL, 'text-embedding-3-small' ),
        ] );
    }

    /**
     * Update a provider's configuration.
     */
    public function update_provider( WP_REST_Request $request ): WP_REST_Response {
        $provider = $request->get_param( 'provider' );
        $allowed  = \RaiLabs\Sathi\Providers\ProviderCatalog::keys();

        if ( ! in_array( $provider, $allowed, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid provider' ], 400 );
        }

        $settings = new Settings();
        $data     = (array) $request->get_json_params();

        // Preserve the stored API key when the incoming value is empty or masked
        // (the admin UI sends the masked key back unless the user typed a new one).
        $existing     = $settings->get_provider_config( $provider ) ?? [];
        $incoming_key = (string) ( $data['api_key'] ?? '' );
        if ( '' === $incoming_key || str_contains( $incoming_key, '*' ) || str_contains( $incoming_key, '•' ) ) {
            $data['api_key'] = $existing['api_key'] ?? '';
        }

        $settings->set_provider_config( $provider, $data );

        // Auto-manage the enabled list + a sensible default so the chatbot works
        // as soon as any provider has a key — no separate enable step needed.
        $configs = $settings->get_provider_configs();
        $enabled = [];
        foreach ( $configs as $p => $c ) {
            if ( ! empty( $c['api_key'] ) ) {
                $enabled[] = $p;
            }
        }
        $settings->set( Settings::KEY_ENABLED_PROVIDERS, $enabled );

        if ( ! empty( $data['api_key'] ) ) {
            $current_default = (string) $settings->get( Settings::KEY_DEFAULT_PROVIDER, '' );
            if ( '' === $current_default || ! in_array( $current_default, $enabled, true ) ) {
                $settings->set( Settings::KEY_DEFAULT_PROVIDER, $provider );
            }
        }

        $settings->invalidate();

        return new WP_REST_Response( [
            'success' => true,
            'enabled' => $enabled,
            'default' => $settings->get( Settings::KEY_DEFAULT_PROVIDER ),
        ] );
    }

    /**
     * Test a provider's connection.
     */
    public function test_provider( WP_REST_Request $request ): WP_REST_Response {
        $provider = $request->get_param( 'provider' );
        $settings = new Settings();
        $factory  = new \RaiLabs\Sathi\Providers\Factory( $settings );

        try {
            $adapter = $factory->make( $provider );
            if ( ! $adapter->is_configured() ) {
                return new WP_REST_Response( [
                    'success' => false,
                    'error'   => __( 'Provider is not configured — API key missing.', 'sathi-agentic-ai' ),
                ], 400 );
            }

            // Send a minimal test request
            $test_msg = \RaiLabs\Sathi\Core\Data\Message::user( 'Hello, reply with just "OK".' );
            $response = $adapter->chat( [ $test_msg ], [
                'max_tokens'  => 10,
                'temperature' => 0,
            ] );

            return new WP_REST_Response( [
                'success'  => true,
                'response' => $response->content,
                'tokens'   => $response->token_count,
            ] );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500 );
        }
    }

    /**
     * Admin-only permission check.
     */
    public function check_admin_permission(): bool {
        return current_user_can( 'manage_options' );
    }
}
