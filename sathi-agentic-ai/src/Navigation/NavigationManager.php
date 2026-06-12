<?php
/**
 * Navigation Manager — builds site route map and serves it for agent-driven navigation.
 *
 * @package RaiLabs\Sathi\Navigation
 */

namespace RaiLabs\Sathi\Navigation;

class NavigationManager {

    /** @var ClientActionProtocol Action protocol instance */
    private ClientActionProtocol $actions;

    /** @var TourBuilder|null Lazy-loaded tour builder */
    private ?TourBuilder $tour_builder = null;

    /** @var array|null Cached route map */
    private ?array $route_map = null;

    public function __construct() {
        $this->actions = new ClientActionProtocol();
    }

    /**
     * Register hooks and REST endpoints.
     */
    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
        add_action( 'rest_api_init', [ $this, 'register_tour_endpoint' ] );
        add_action( 'save_post', [ $this, 'invalidate_cache' ] );
        add_action( 'deleted_post', [ $this, 'invalidate_cache' ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  REST Endpoints
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Register the route-map REST endpoint.
     */
    public function register_rest_route(): void {
        register_rest_route( 'sathi/v1', '/route-map', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'serve_route_map' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Register the tour builder REST endpoint.
     *
     * GET /wp-json/sathi/v1/route-map/tour?intent=X&current_url=Y
     *
     * Returns a guided tour sequence for the given intent.
     */
    public function register_tour_endpoint(): void {
        register_rest_route( 'sathi/v1', '/route-map/tour', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'serve_tour' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'intent'      => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => __( 'Natural language description of what the user wants.', 'sathi-agentic-ai' ),
                ],
                'current_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'default'           => '',
                    'description'       => __( 'The page URL the user is currently on.', 'sathi-agentic-ai' ),
                ],
            ],
        ] );

        // Contextual suggestions sub-endpoint
        register_rest_route( 'sathi/v1', '/route-map/suggestions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'serve_suggestions' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'current_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'default'           => '',
                    'description'       => __( 'The page URL the user is currently on.', 'sathi-agentic-ai' ),
                ],
                'query'       => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                    'description'       => __( 'Optional user query for query-aware suggestions.', 'sathi-agentic-ai' ),
                ],
            ],
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  REST Callbacks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Serve the route map via REST.
     *
     * @return \WP_REST_Response
     */
    public function serve_route_map(): \WP_REST_Response {
        return new \WP_REST_Response( [
            'site_map'            => $this->build_route_map(),
            'allowlisted_actions' => $this->get_allowlisted_actions(),
        ] );
    }

    /**
     * Serve a guided tour sequence via REST.
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function serve_tour( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $intent      = $request->get_param( 'intent' );
        $current_url = $request->get_param( 'current_url' ) ?: '';

        if ( empty( $intent ) ) {
            return new \WP_Error(
                'sathi_missing_intent',
                __( 'The "intent" parameter is required.', 'sathi-agentic-ai' ),
                [ 'status' => 400 ]
            );
        }

        $tour = $this->buildTour( $intent, $current_url );

        return new \WP_REST_Response( $tour );
    }

    /**
     * Serve contextual suggestions via REST.
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function serve_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
        $current_url = $request->get_param( 'current_url' ) ?: '';
        $query       = $request->get_param( 'query' ) ?: null;

        $suggestions = $this->getContextualSuggestions( $current_url, $query );

        return new \WP_REST_Response( [ 'suggestions' => $suggestions ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Tour / Suggestions Public API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a guided tour sequence from a user query.
     *
     * Delegates to TourBuilder to create a step-by-step walkthrough:
     * navigate → scroll_to → highlight → show_tooltip.
     *
     * @param  string $user_query  Natural language description of what the user wants.
     * @param  string $current_url The page URL the user is currently on (optional).
     * @return array{tour_id: string, title: string, description: string, steps: array, suggestions: array}
     */
    public function buildTour( string $user_query, string $current_url = '' ): array {
        return $this->get_tour_builder()->buildTour( $user_query, $current_url );
    }

    /**
     * Return contextual suggestions based on the current page.
     *
     * Each suggestion maps to a safe client action (navigate, scroll_to,
     * highlight, focus_input, open_contact) the user is likely to take next.
     *
     * @param  string      $current_url The page URL the user is on.
     * @param  string|null $user_query  Optional natural-language intent for query-aware results.
     * @return array<int, array{action_type: string, params: array, narration: string, label: string}>
     */
    public function getContextualSuggestions( string $current_url, ?string $user_query = null ): array {
        return $this->get_tour_builder()->getContextualSuggestions( $current_url, $user_query );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Route Map
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a structured map of the site for agent navigation.
     *
     * @return array{home: array, pages: array[], posts: array[], categories: array[], products: array[]}
     */
    public function build_route_map(): array {
        if ( $this->route_map !== null ) {
            return $this->route_map;
        }

        $map = [
            'home' => [
                'url'   => home_url(),
                'title' => get_bloginfo( 'name' ),
            ],
            'pages'    => [],
            'posts'    => [],
            'categories' => [],
            'products' => [],
        ];

        // Pages (hierarchical, published)
        $pages = get_pages( [
            'post_status' => 'publish',
            'sort_column' => 'menu_order',
            'number'      => 100,
        ] );

        foreach ( $pages as $page ) {
            $map['pages'][] = [
                'id'          => $page->ID,
                'title'       => $page->post_title,
                'url'         => get_permalink( $page ),
                'slug'        => $page->post_name,
                'is_front'    => $page->ID === (int) get_option( 'page_on_front' ),
                'short_desc'  => wp_trim_words( wp_strip_all_tags( $page->post_excerpt ?: $page->post_content ), 20 ),
            ];
        }

        // Recent posts
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
        ] );

        foreach ( $posts as $post ) {
            $map['posts'][] = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'url'        => get_permalink( $post ),
                'slug'       => $post->post_name,
                'excerpt'    => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 20 ),
            ];
        }

        // Categories
        $categories = get_categories( [ 'hide_empty' => true, 'number' => 50 ] );
        foreach ( $categories as $cat ) {
            $map['categories'][] = [
                'id'    => $cat->term_id,
                'name'  => $cat->name,
                'url'   => get_category_link( $cat ),
                'count' => $cat->count,
            ];
        }

        // WooCommerce products
        if ( function_exists( 'wc_get_products' ) ) {
            $products = wc_get_products( [
                'status'  => 'publish',
                'limit'   => 100,
                'orderby' => 'menu_order',
            ] );

            foreach ( $products as $product ) {
                $map['products'][] = [
                    'id'    => $product->get_id(),
                    'name'  => $product->get_name(),
                    'url'   => get_permalink( $product->get_id() ),
                    'price' => $product->get_price(),
                    'sku'   => $product->get_sku(),
                ];
            }
        }

        $this->route_map = $map;
        return $map;
    }

    /**
     * Resolve a user query to the best-matching URL on the site.
     *
     * @param  string $query Natural language query like "shipping returns page"
     * @return array{url: string, title: string, confidence: float}|null
     */
    public function resolve_url( string $query ): ?array {
        $map = $this->build_route_map();
        $query_lower = mb_strtolower( $query );
        $best = null;
        $best_score = 0;

        // Search pages, posts, products
        foreach ( array_merge( $map['pages'], $map['posts'], $map['products'] ) as $item ) {
            $title = $item['title'] ?? $item['name'] ?? '';
            $desc  = $item['short_desc'] ?? $item['excerpt'] ?? '';

            $score = $this->relevance_score( $query_lower, mb_strtolower( $title ), mb_strtolower( $desc ) );

            if ( $score > $best_score ) {
                $best_score = $score;
                $best = [
                    'url'        => $item['url'],
                    'title'      => $title,
                    'confidence' => min( $score, 1.0 ),
                ];
            }
        }

        return $best_score > 0.3 ? $best : null;
    }

    /**
     * Simple relevance scoring for URL resolution.
     */
    private function relevance_score( string $query, string $title, string $desc ): float {
        $score = 0;

        // Word-by-word match in title (heavily weighted)
        foreach ( explode( ' ', $query ) as $word ) {
            if ( strlen( $word ) < 3 ) {
                continue;
            }
            if ( str_contains( $title, $word ) ) {
                $score += 0.3;
            }
            if ( str_contains( $desc, $word ) ) {
                $score += 0.1;
            }
        }

        // Full phrase match bonus
        if ( str_contains( $title, $query ) ) {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * Get the allowlisted client actions.
     *
     * @return array<string, array{label: string, description: string, params: array}>
     */
    public function get_allowlisted_actions(): array {
        return [
            'navigate'     => [
                'label'       => __( 'Navigate to URL', 'sathi-agentic-ai' ),
                'description' => __( 'Direct the user\'s browser to a URL on this site.', 'sathi-agentic-ai' ),
                'params'      => [ 'url' => 'string (required, site domain only)' ],
            ],
            'scroll_to'    => [
                'label'       => __( 'Scroll to Element', 'sathi-agentic-ai' ),
                'description' => __( 'Scroll the page to a specific element.', 'sathi-agentic-ai' ),
                'params'      => [ 'selector' => 'string (CSS selector, restricted)' ],
            ],
            'highlight'    => [
                'label'       => __( 'Highlight Element', 'sathi-agentic-ai' ),
                'description' => __( 'Temporarily highlight a page element.', 'sathi-agentic-ai' ),
                'params'      => [ 'selector' => 'string (CSS selector, restricted)' ],
            ],
            'focus_input'  => [
                'label'       => __( 'Focus Input', 'sathi-agentic-ai' ),
                'description' => __( 'Focus the user\'s cursor on a form field.', 'sathi-agentic-ai' ),
                'params'      => [ 'selector' => 'string (input/textarea selectors)' ],
            ],
            'open_contact' => [
                'label'       => __( 'Open Contact', 'sathi-agentic-ai' ),
                'description' => __( 'Open the contact form or modal.', 'sathi-agentic-ai' ),
                'params'      => [],
            ],
            'show_tooltip' => [
                'label'       => __( 'Show Tooltip', 'sathi-agentic-ai' ),
                'description' => __( 'Display a contextual tooltip near an element.', 'sathi-agentic-ai' ),
                'params'      => [ 'element' => 'string (CSS selector)', 'message' => 'string' ],
            ],
        ];
    }

    /**
     * Invalidate the cached route map.
     */
    public function invalidate_cache(): void {
        $this->route_map = null;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Internal
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get or lazily create the TourBuilder instance.
     */
    private function get_tour_builder(): TourBuilder {
        if ( $this->tour_builder === null ) {
            $this->tour_builder = new TourBuilder( $this );
        }
        return $this->tour_builder;
    }
}
