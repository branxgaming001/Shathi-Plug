<?php
/**
 * Maps Sathi's tool definitions to WP 7 Abilities.
 *
 * @package NeerMedia\Sathi\Labs\WP7Integration\Abilities
 */

namespace NeerMedia\Sathi\Labs\WP7Integration\Abilities;

class AbilityMapper {

    public function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        $abilities = apply_filters( 'sathi_wp7_abilities', [
            'sathi_search_knowledge' => [
                'label'       => __( 'Search Knowledge Base', 'sathi-agentic-ai' ),
                'description' => __( 'Find relevant content from the site knowledge base.', 'sathi-agentic-ai' ),
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [ 'type' => 'string', 'description' => 'Search query' ],
                        'limit' => [ 'type' => 'integer', 'description' => 'Max results', 'default' => 5 ],
                    ],
                    'required' => [ 'query' ],
                ],
                'callback'    => function ( array $args ): array {
                    $manager = new \NeerMedia\Sathi\Knowledge\KnowledgeManager();
                    return [ 'results' => $manager->search( $args['query'], $args['limit'] ?? 5 ) ];
                },
                'show_in_rest' => true,
                'capability'   => 'read',
            ],

            'sathi_get_site_map' => [
                'label'       => __( 'Get Site Map', 'sathi-agentic-ai' ),
                'description' => __( 'Get the site structure for navigation.', 'sathi-agentic-ai' ),
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [],
                ],
                'callback'    => function (): array {
                    $nav = new \NeerMedia\Sathi\Navigation\NavigationManager();
                    return $nav->build_route_map();
                },
                'show_in_rest' => true,
                'capability'   => 'read',
            ],

            'sathi_get_conversations' => [
                'label'       => __( 'Get Conversations', 'sathi-agentic-ai' ),
                'description' => __( 'Retrieve recent support conversations.', 'sathi-agentic-ai' ),
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'limit' => [ 'type' => 'integer', 'default' => 10 ],
                    ],
                ],
                'callback'    => function ( array $args ): array {
                    $user_id  = get_current_user_id() ?: null;
                    $guest_id = sanitize_text_field( $_REQUEST['guest_id'] ?? '' );
                    $factory  = new \NeerMedia\Sathi\Providers\Factory( new \NeerMedia\Sathi\Core\Settings() );
                    $memory   = new \NeerMedia\Sathi\Memory\MemoryStore();
                    $chat     = new \NeerMedia\Sathi\Chat\ChatManager( $factory, $memory );
                    $convs    = $chat->get_recent_conversations( $user_id, $guest_id, $args['limit'] ?? 10 );
                    return [
                        'conversations' => array_map( fn( $c ) => $c->to_array(), $convs ),
                    ];
                },
                'show_in_rest' => true,
                'capability'   => 'read',
            ],
        ] );

        foreach ( $abilities as $name => $def ) {
            wp_register_ability( $name, $def );
        }
    }
}
