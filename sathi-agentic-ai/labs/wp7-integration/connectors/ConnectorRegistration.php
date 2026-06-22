<?php
/**
 * Registers Sathi environments as WP 7 Connectors.
 *
 * Overrides core stubs so the Connectors page shows Sathi-managed
 * providers instead of the WP AI Team's shims.
 *
 * @package NeerMedia\Sathi\Labs\WP7Integration\Connectors
 */

namespace NeerMedia\Sathi\Labs\WP7Integration\Connectors;

class ConnectorRegistration {

    public function register(): void {
        if ( ! function_exists( 'wp_register_connector' ) ) {
            return;
        }

        $settings = new \NeerMedia\Sathi\Core\Settings();
        $enabled  = $settings->get( \NeerMedia\Sathi\Core\Settings::KEY_ENABLED_PROVIDERS, [ 'openai' ] );

        foreach ( $enabled as $provider ) {
            $config = $settings->get_provider_config( $provider );
            if ( ! $config || empty( $config['api_key'] ) ) {
                continue;
            }

            wp_register_connector( "sathi_{$provider}", [
                'type'        => 'ai_provider',
                'name'        => $this->label( $provider ),
                'description' => sprintf(
                    __( 'Powered by Sathi Agentic AI — %s', 'sathi-agentic-ai' ),
                    $this->label( $provider )
                ),
                'auth_method' => 'api_key',
                'api_key'     => $config['api_key'],
            ] );
        }

        do_action( 'sathi_connectors_registered' );
    }

    private function label( string $provider ): string {
        return match ( $provider ) {
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic Claude',
            'google'    => 'Google Gemini',
            'openrouter'=> 'OpenRouter',
            'local'     => 'Local Model',
            default     => ucfirst( $provider ),
        };
    }
}
