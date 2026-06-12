<?php
/**
 * WordPress 7.0 AI Framework Bridge.
 *
 * Gracefully integrates with wp_ai_client_prompt(), Connectors, and Abilities
 * when they are available. Never hard-depends on them — everything degrades
 * gracefully on WP < 7.0.
 *
 * @package RaiLabs\Sathi\Integration
 */

namespace RaiLabs\Sathi\Integration;

class WP7Bridge {

    /** @var bool Whether WP 7 AI framework is available */
    private bool $available = false;

    /**
     * Boot the bridge if WP 7 APIs are present.
     */
    public function boot_if_available(): void {
        if ( $this->detect_wp7_ai() ) {
            $this->available = true;
            $this->register_connectors();
            $this->register_abilities();
            $this->subscribe_to_events();
        }
    }

    /**
     * Detect whether the WP 7 AI framework is loaded.
     */
    private function detect_wp7_ai(): bool {
        return function_exists( 'wp_ai_client_prompt' )
            && function_exists( 'wp_register_ability' )
            && function_exists( 'wp_connectors_init' );
    }

    /**
     * Check if the bridge is active.
     */
    public function is_available(): bool {
        return $this->available;
    }

    /**
     * Register Sathi as a Connector provider on the WP 7 Connectors page.
     *
     * Overrides the core stubs with actual Sathi-provided connectors
     * for OpenAI, Anthropic, and Google.
     */
    private function register_connectors(): void {
        if ( ! $this->available ) {
            return;
        }

        add_action( 'wp_connectors_init', function () {
            if ( ! function_exists( 'wp_register_connector' ) ) {
                return;
            }

            $providers = [ 'openai', 'anthropic', 'google' ];
            foreach ( $providers as $provider ) {
                $config = $this->get_sathi_provider_config( $provider );
                if ( ! $config || empty( $config['api_key'] ) ) {
                    continue;
                }

                wp_register_connector( "sathi_{$provider}", [
                    'type'        => 'ai_provider',
                    'name'        => $this->get_provider_label( $provider ),
                    'description' => sprintf(
                        __( 'AI Engine provided by RAI Labs Sathi (%s)', 'sathi-agentic-ai' ),
                        $provider
                    ),
                    'auth_method' => 'api_key',
                    'api_key'     => $config['api_key'],
                ] );
            }
        } );
    }

    /**
     * Register Sathi tools as WP 7 Abilities.
     *
     * Each Ability automatically becomes:
     * - An AI function call (via AI Client)
     * - An MCP tool (when MCP Adapter is active)
     * - A REST endpoint (when show_in_rest is true)
     */
    private function register_abilities(): void {
        if ( ! $this->available ) {
            return;
        }

        add_action( 'init', function () {
            if ( ! function_exists( 'wp_register_ability' ) ) {
                return;
            }

            // Expose knowledge base search as an Ability
            wp_register_ability( 'sathi_knowledge_search', [
                'label'             => __( 'Search Knowledge Base', 'sathi-agentic-ai' ),
                'description'       => __( 'Search the site knowledge base for relevant content.', 'sathi-agentic-ai' ),
                'schema'            => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => __( 'Search query', 'sathi-agentic-ai' ),
                        ],
                    ],
                    'required' => [ 'query' ],
                ],
                'callback'          => [ $this, 'ability_knowledge_search' ],
                'show_in_rest'      => true,
                'capability'        => 'read',
            ] );

            // Expose navigation as an Ability
            wp_register_ability( 'sathi_navigate', [
                'label'             => __( 'Navigate Site', 'sathi-agentic-ai' ),
                'description'       => __( 'Find a page or section on this site.', 'sathi-agentic-ai' ),
                'schema'            => [
                    'type'       => 'object',
                    'properties' => [
                        'intent' => [
                            'type'        => 'string',
                            'description' => __( 'What the user wants to find (e.g., "contact page", "pricing")', 'sathi-agentic-ai' ),
                        ],
                    ],
                    'required' => [ 'intent' ],
                ],
                'callback'          => [ $this, 'ability_navigate' ],
                'show_in_rest'      => true,
                'capability'        => 'read',
            ] );
        } );
    }

    /**
     * Subscribe to WP 7 AI Client events for unified statistics.
     */
    private function subscribe_to_events(): void {
        if ( ! $this->available ) {
            return;
        }

        // Cost cap — prevent prompts that would exceed budget
        add_filter( 'wp_ai_client_prevent_prompt', function ( bool $prevent, array $request ) {
            // Hook into Sathi's cost tracking when implemented (Phase 9)
            return apply_filters( 'sathi_cost_cap_check', $prevent, $request );
        }, 10, 2 );

        // Stats events
        $events = [ 'before_prompt', 'after_prompt', 'error' ];
        foreach ( $events as $event ) {
            add_action( "wp_ai_client_{$event}", function ( $data ) use ( $event ) {
                do_action( 'sathi_stats_event', $event, $data );
            } );
        }
    }

    /**
     * Ability callback: knowledge search.
     *
     * @param  array $args { query: string }
     * @return array
     */
    public function ability_knowledge_search( array $args ): array {
        $manager = new \RaiLabs\Sathi\Knowledge\KnowledgeManager();
        $results = $manager->search( $args['query'], 3 );

        return [
            'results' => $results,
            'count'   => count( $results ),
        ];
    }

    /**
     * Ability callback: site navigation.
     *
     * @param  array $args { intent: string }
     * @return array
     */
    public function ability_navigate( array $args ): array {
        $nav     = new \RaiLabs\Sathi\Navigation\NavigationManager();
        $result  = $nav->resolve_url( $args['intent'] );

        if ( $result ) {
            return [
                'found' => true,
                'url'   => $result['url'],
                'title' => $result['title'],
            ];
        }

        return [
            'found'   => false,
            'message' => __( 'No matching page found.', 'sathi-agentic-ai' ),
        ];
    }

    /**
     * Get Sathi provider config for connector bridging.
     */
    private function get_sathi_provider_config( string $provider ): ?array {
        $settings = new \RaiLabs\Sathi\Core\Settings();
        return $settings->get_provider_config( $provider );
    }

    /**
     * Get human-readable label for a provider key.
     */
    private function get_provider_label( string $provider ): string {
        return match ( $provider ) {
            'openai'    => 'OpenAI (via Sathi)',
            'anthropic' => 'Anthropic Claude (via Sathi)',
            'google'    => 'Google Gemini (via Sathi)',
            default     => ucfirst( $provider ) . ' (via Sathi)',
        };
    }
}
