<?php
/**
 * WordPress Abilities — AI-callable functions for WP content, navigation, and site info.
 *
 * Registers 4 WordPress-native tools for content search, menu retrieval, user info,
 * and site diagnostics. All tools are capability-gated; site info requires admin access.
 *
 * @package NeerMedia\Sathi\Abilities
 */

namespace NeerMedia\Sathi\Abilities;

class WordPressAbilities {

    /** @var AbilityRegistry */
    private AbilityRegistry $registry;

    /**
     * @param AbilityRegistry|null $registry If null, uses the singleton instance.
     */
    public function __construct( ?AbilityRegistry $registry = null ) {
        $this->registry = $registry ?? AbilityRegistry::instance();
    }

    // ── Registration ───────────────────────────────────────────────

    /**
     * Register all WordPress-native abilities.
     *
     * Called during plugin boot. Safe to call multiple times.
     */
    public function register(): void {
        $this->register_search_posts();
        $this->register_get_menu();
        $this->register_get_user_info();
        $this->register_get_site_info();
    }

    // ── Individual Ability Registrations ───────────────────────────

    private function register_search_posts(): void {
        $this->registry->register( 'sathi_wp_search_posts', [
            'label'       => __( 'Search Posts', 'sathi-agentic-ai' ),
            'description' => __( 'Search WordPress posts, pages, and custom post types by keyword. Returns matching content with title, excerpt, URL, type, and date.', 'sathi-agentic-ai' ),
            'category'    => 'wordpress',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Search keyword or phrase. Matches post titles and content.',
                    ],
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type to search. Use "post" for blog posts, "page" for pages, or "any" for all public types.',
                        'default'     => 'any',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of results (1–50).',
                        'default'     => 5,
                        'minimum'     => 1,
                        'maximum'     => 50,
                    ],
                ],
                'required' => [ 'query' ],
            ],
            'callback'   => function ( array $args ): array {
                $query     = sanitize_text_field( $args['query'] ?? '' );
                $post_type = sanitize_text_field( $args['post_type'] ?? 'any' );
                $limit     = min( max( (int) ( $args['limit'] ?? 5 ), 1 ), 50 );

                if ( $query === '' ) {
                    return [ 'results' => [], 'error' => __( 'Search query cannot be empty.', 'sathi-agentic-ai' ) ];
                }

                // Validate post type
                $allowed_types = get_post_types( [ 'public' => true ], 'names' );
                if ( $post_type !== 'any' && ! in_array( $post_type, $allowed_types, true ) ) {
                    $post_type = 'any';
                }

                try {
                    $posts = get_posts( [
                        's'              => $query,
                        'post_type'      => $post_type,
                        'post_status'    => 'publish',
                        'posts_per_page' => $limit,
                        'orderby'        => 'relevance',
                        'suppress_filters' => false,
                    ] );

                    return [
                        'results' => array_map( function ( \WP_Post $post ): array {
                            $thumbnail_url = has_post_thumbnail( $post->ID )
                                ? get_the_post_thumbnail_url( $post->ID, 'thumbnail' )
                                : null;

                            return [
                                'id'        => $post->ID,
                                'title'     => get_the_title( $post ),
                                'excerpt'   => wp_trim_words(
                                    wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ),
                                    30
                                ),
                                'url'       => get_permalink( $post ),
                                'type'      => $post->post_type,
                                'type_label'=> get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type,
                                'date'      => get_the_date( 'Y-m-d', $post ),
                                'modified'  => get_the_modified_date( 'Y-m-d', $post ),
                                'author'    => get_the_author_meta( 'display_name', $post->post_author ),
                                'thumbnail' => $thumbnail_url,
                                'comment_count' => (int) $post->comment_count,
                            ];
                        }, $posts ),
                        'total_found' => count( $posts ),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'results' => [],
                        'error'   => __( 'Failed to search posts.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_menu(): void {
        $this->registry->register( 'sathi_wp_get_menu', [
            'label'       => __( 'Get Menu', 'sathi-agentic-ai' ),
            'description' => __( 'Get navigation menu items for a registered menu location (e.g., "primary", "footer", "mobile"). Returns menu items with title, URL, and parent/child relationships.', 'sathi-agentic-ai' ),
            'category'    => 'wordpress',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'location' => [
                        'type'        => 'string',
                        'description' => 'Registered menu location slug. Common values: primary, secondary, footer, mobile, social. Leave empty to get all registered locations.',
                        'default'     => 'primary',
                    ],
                ],
            ],
            'callback'   => function ( array $args ): array {
                $location_slug = sanitize_text_field( $args['location'] ?? 'primary' );

                try {
                    $locations = get_nav_menu_locations();

                    // If no location specified or location not found, return available locations
                    if ( $location_slug === '' || ! isset( $locations[ $location_slug ] ) ) {
                        if ( $location_slug !== '' && $location_slug !== 'primary' ) {
                            // User asked for a specific location that doesn't exist
                            return [
                                'items'        => [],
                                'menu_found'   => false,
                                'message'      => sprintf(
                                    /* translators: %s: menu location slug */
                                    __( 'No menu assigned to location "%s".', 'sathi-agentic-ai' ),
                                    $location_slug
                                ),
                                'available_locations' => array_keys( $locations ),
                            ];
                        }
                    }

                    $menu_id = $locations[ $location_slug ] ?? null;

                    if ( ! $menu_id ) {
                        return [
                            'items'      => [],
                            'menu_found' => false,
                            'available_locations' => array_keys( $locations ),
                        ];
                    }

                    $menu_items = wp_get_nav_menu_items( $menu_id );

                    if ( ! is_array( $menu_items ) ) {
                        return [ 'items' => [], 'menu_found' => false ];
                    }

                    // Build parent/child hierarchy info
                    $indexed = [];
                    foreach ( $menu_items as $item ) {
                        $indexed[ $item->ID ] = $item;
                    }

                    return [
                        'items'      => array_map( function ( \WP_Post $item ) use ( $indexed ): array {
                            $entry = [
                                'id'       => $item->ID,
                                'title'    => $item->title,
                                'url'      => $item->url,
                                'target'   => $item->target ?: '_self',
                                'parent'   => $item->menu_item_parent ? (int) $item->menu_item_parent : null,
                                'children' => [],
                                'order'    => $item->menu_order,
                            ];

                            // Include child IDs
                            foreach ( $indexed as $child ) {
                                if ( (int) $child->menu_item_parent === $item->ID ) {
                                    $entry['children'][] = $child->ID;
                                }
                            }

                            return $entry;
                        }, $menu_items ),
                        'menu_found' => true,
                        'menu_name'  => wp_get_nav_menu_object( $menu_id )->name ?? '',
                        'location'   => $location_slug,
                        'total_items'=> count( $menu_items ),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'items'  => [],
                        'error'  => __( 'Failed to retrieve menu.', 'sathi-agentic-ai' ),
                        'details'=> $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_user_info(): void {
        $this->registry->register( 'sathi_wp_get_user_info', [
            'label'       => __( 'Get User Info', 'sathi-agentic-ai' ),
            'description' => __( 'Get information about the currently logged-in user. Returns only safe, non-sensitive fields: display name, roles, avatar URL. No email or capability tokens are exposed.', 'sathi-agentic-ai' ),
            'category'    => 'wordpress',
            'schema'      => [
                'type'       => 'object',
                'properties' => [],
            ],
            'callback'   => function (): array {
                $user_id = get_current_user_id();

                if ( ! $user_id ) {
                    return [
                        'logged_in' => false,
                        'message'   => __( 'No user is currently logged in.', 'sathi-agentic-ai' ),
                    ];
                }

                try {
                    $user = get_userdata( $user_id );

                    if ( ! $user instanceof \WP_User ) {
                        return [ 'logged_in' => false, 'message' => __( 'User data not found.', 'sathi-agentic-ai' ) ];
                    }

                    // Only return safe, non-sensitive fields
                    return [
                        'logged_in'       => true,
                        'user_id'         => $user_id,
                        'display_name'    => $user->display_name,
                        'first_name'      => $user->first_name ?: null,
                        'last_name'       => $user->last_name ?: null,
                        'nickname'        => $user->nickname ?: null,
                        'roles'           => $user->roles,
                        'avatar_url'      => get_avatar_url( $user_id, [ 'size' => 96 ] ),
                        'registered_date' => date( 'Y-m-d', strtotime( $user->user_registered ) ),
                        'is_admin'        => in_array( 'administrator', $user->roles, true ),
                        'locale'          => get_user_locale( $user ),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'logged_in' => false,
                        'error'     => __( 'Failed to retrieve user info.', 'sathi-agentic-ai' ),
                        'details'   => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_site_info(): void {
        $this->registry->register( 'sathi_wp_get_site_info', [
            'label'       => __( 'Site Info', 'sathi-agentic-ai' ),
            'description' => __( 'Get WordPress site diagnostic information including version, active theme, plugin counts, language, timezone, and permalink structure. Restricted to administrators.', 'sathi-agentic-ai' ),
            'category'    => 'wordpress',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'include_plugins' => [
                        'type'        => 'boolean',
                        'description' => 'Whether to include a list of active plugin names (admin only).',
                        'default'     => false,
                    ],
                ],
            ],
            'callback'   => function ( array $args ): array {
                $include_plugins = (bool) ( $args['include_plugins'] ?? false );

                try {
                    $theme        = wp_get_theme();
                    $is_multisite = is_multisite();

                    $data = [
                        'site_name'           => get_bloginfo( 'name' ),
                        'site_description'    => get_bloginfo( 'description' ),
                        'site_url'            => home_url(),
                        'admin_email'         => get_bloginfo( 'admin_email' ),
                        'wp_version'          => get_bloginfo( 'version' ),
                        'theme_name'          => $theme->get( 'Name' ),
                        'theme_version'       => $theme->get( 'Version' ),
                        'theme_author'        => $theme->get( 'Author' ),
                        'language'            => get_locale(),
                        'timezone'            => wp_timezone_string(),
                        'date_format'         => get_option( 'date_format' ),
                        'time_format'         => get_option( 'time_format' ),
                        'permalink_structure' => get_option( 'permalink_structure' ) ?: __( 'Plain', 'sathi-agentic-ai' ),
                        'is_multisite'        => $is_multisite,
                        'is_woocommerce'      => class_exists( 'WooCommerce' ),
                        'memory_limit'        => ini_get( 'memory_limit' ),
                        'php_version'         => PHP_VERSION,
                    ];

                    if ( $include_plugins ) {
                        // Get counts without exposing sensitive plugin paths
                        $all_plugins     = get_plugins();
                        $active_plugins  = get_option( 'active_plugins', [] );
                        $active_names    = [];

                        foreach ( $active_plugins as $plugin_file ) {
                            if ( isset( $all_plugins[ $plugin_file ] ) ) {
                                $active_names[] = $all_plugins[ $plugin_file ]['Name'];
                            }
                        }

                        $data['active_plugins_count'] = count( $active_names );
                        $data['total_plugins_count']  = count( $all_plugins );
                        $data['active_plugins']       = $active_names;
                    }

                    return $data;
                } catch ( \Throwable $e ) {
                    return [
                        'error'   => __( 'Failed to retrieve site info.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'manage_options',
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Get the list of registered menu locations on this site.
     *
     * @return array<string, string> Location slug => description.
     */
    public function get_available_menu_locations(): array {
        $locations = get_registered_nav_menus();
        return is_array( $locations ) ? $locations : [];
    }

    /**
     * Get public post types available on this site.
     *
     * @return array<string, string> Post type slug => label.
     */
    public function get_public_post_types(): array {
        $types = get_post_types( [ 'public' => true ], 'objects' );
        $map   = [];
        foreach ( $types as $slug => $obj ) {
            $map[ $slug ] = $obj->labels->singular_name ?? $slug;
        }
        return $map;
    }
}
