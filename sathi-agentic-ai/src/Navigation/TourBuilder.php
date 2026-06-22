<?php
/**
 * Tour Builder — constructs guided multi-step tours from the site route map.
 *
 * Analyses user intent against the route map and builds a sequence of
 * client actions (navigate → scroll_to → highlight → show_tooltip) that
 * guide the user step by step through the site.
 *
 * @package NeerMedia\Sathi\Navigation
 */

namespace NeerMedia\Sathi\Navigation;

class TourBuilder {

    /** @var NavigationManager Route-map provider */
    private NavigationManager $nav;

    /** @var ClientActionProtocol Action validator / builder */
    private ClientActionProtocol $actions;

    /** @var int Maximum steps per tour */
    private const MAX_STEPS = 7;

    /**
     * Intent-keyword → element-selector mapping for common WordPress themes.
     *
     * @var array<string, string>
     */
    private const INTENT_SECTION_MAP = [
        'pricing'  => '#pricing, .pricing, .pricing-section, .price-table, [class*="pricing"]',
        'price'    => '#pricing, .pricing, .pricing-section, .price-table, [class*="pricing"]',
        'contact'  => '#contact, .contact, .contact-section, .contact-form, .wpcf7, [class*="contact"]',
        'about'    => '#about, .about, .about-section, .entry-content, [class*="about"]',
        'service'  => '#services, .services, .service-section, .features, [class*="service"]',
        'product'  => '.products, .product-grid, .woocommerce, .shop, [class*="product"]',
        'shop'     => '.products, .product-grid, .woocommerce, .shop, [class*="product"]',
        'blog'     => '#blog, .blog, .posts, .post-grid, .entry-content, [class*="blog"]',
        'team'     => '#team, .team, .team-section, .staff, [class*="team"]',
        'testimonial' => '.testimonials, .testimonial-section, .reviews, [class*="testimonial"]',
        'review'   => '.testimonials, .testimonial-section, .reviews, [class*="testimonial"]',
        'faq'      => '#faq, .faq, .faq-section, .accordion, [class*="faq"]',
        'portfolio' => '#portfolio, .portfolio, .portfolio-grid, [class*="portfolio"]',
        'gallery'  => '#gallery, .gallery, .gallery-section, [class*="gallery"]',
        'location' => '#location, .location, .map, .store-locator, [class*="location"]',
        'login'    => '#login, .login, .login-form, .wp-login, [class*="login"]',
        'account'  => '.account, .my-account, .woocommerce-account, [class*="account"]',
        'cart'     => '.cart, .woocommerce-cart, .shopping-cart, [class*="cart"]',
        'checkout' => '.checkout, .woocommerce-checkout, [class*="checkout"]',
        'search'   => '.search-form, .search, input[type="search"], [class*="search"]',
        'footer'   => '#footer, .footer, footer, .site-footer',
        'header'   => '#header, .header, header, .site-header, nav',
        'menu'     => 'nav, .nav, .menu, .navigation, #site-navigation',
    ];

    public function __construct( NavigationManager $nav ) {
        $this->nav     = $nav;
        $this->actions = new ClientActionProtocol();
    }

    /**
     * Build a guided tour sequence from a user's intent query.
     *
     * Creates a step-by-step walkthrough: navigate to the right page,
     * scroll to the relevant section, highlight key elements, and
     * show contextual tooltips.
     *
     * @param  string $intent      Natural language description of what the user wants.
     * @param  string $current_url The page the user is currently on (empty = unknown).
     * @return array{tour_id: string, title: string, description: string, steps: array, suggestions: array}
     */
    public function buildTour( string $intent, string $current_url = '' ): array {
        $route_map   = $this->nav->build_route_map();
        $steps       = [];
        $intent_lower = mb_strtolower( trim( $intent ) );

        // Resolve intent to a best-matching page / product
        $target  = $this->nav->resolve_url( $intent );
        $tour_id = 'sathi_tour_' . substr( md5( $intent . time() ), 0, 8 );

        // Title / description
        $title = $this->generate_tour_title( $intent, $target );

        // ── Step 1: Navigate to target page (if needed) ────────────
        if ( $target && ! $this->is_current_url( $target['url'], $current_url ) ) {
            $steps[] = $this->make_step(
                'navigate',
                [ 'url' => $target['url'] ],
                sprintf(
                    /* translators: %s: page title */
                    __( 'First, let\'s go to the <strong>%s</strong> page.', 'sathi-agentic-ai' ),
                    esc_html( $target['title'] )
                ),
                800
            );
        }

        // ── Step 2: Scroll to the most relevant section ────────────
        $section_selector = $this->infer_section_selector( $intent_lower );
        if ( $section_selector ) {
            $label = $this->selector_human_label( $section_selector, $intent_lower );
            $steps[] = $this->make_step(
                'scroll_to',
                [ 'selector' => $section_selector ],
                sprintf(
                    /* translators: %s: section name */
                    __( 'Scroll down to the <strong>%s</strong> section.', 'sathi-agentic-ai' ),
                    esc_html( $label )
                ),
                600
            );
        }

        // ── Step 3: Highlight the key area ────────────────────────
        $highlight_selector = $this->infer_highlight_selector( $intent_lower, $route_map );
        if ( $highlight_selector && $highlight_selector !== $section_selector ) {
            $topic = $this->extract_topic( $intent );
            $steps[] = $this->make_step(
                'highlight',
                [ 'selector' => $highlight_selector ],
                sprintf(
                    /* translators: %s: topic keyword */
                    __( 'Here is where you\'ll find <strong>%s</strong>.', 'sathi-agentic-ai' ),
                    esc_html( $topic )
                ),
                1000
            );
        }

        // ── Step 4: Contextual tooltip ────────────────────────────
        $tooltip_text = $this->generate_tooltip_text( $intent_lower, $route_map, $target );
        if ( $tooltip_text ) {
            $tooltip_element = $highlight_selector ?: $section_selector ?: '#main';
            $steps[] = $this->make_step(
                'show_tooltip',
                [
                    'element' => $tooltip_element,
                    'message' => $tooltip_text,
                ],
                $tooltip_text,
                500
            );
        }

        // ── Step 5: Focus a useful input if applicable ─────────────
        $focus_selector = $this->infer_focus_target( $intent_lower, $route_map );
        if ( $focus_selector && count( $steps ) < self::MAX_STEPS - 1 ) {
            $steps[] = $this->make_step(
                'focus_input',
                [ 'selector' => $focus_selector ],
                __( 'Type your query here to find exactly what you need.', 'sathi-agentic-ai' ),
                400
            );
        }

        // ── Contextual suggestions ─────────────────────────────────
        $suggestions = $this->getContextualSuggestions( $current_url, $intent );

        // Append one top suggestion as a step if we have room
        if ( ! empty( $suggestions ) && count( $steps ) < self::MAX_STEPS ) {
            $sug = $suggestions[0];
            $action_payload = $this->actions->build( $sug['action_type'], $sug['params'] );
            if ( $action_payload ) {
                $steps[] = [
                    'action'       => $action_payload,
                    'narration'    => $sug['narration'],
                    'delay_ms'     => 400,
                    'is_suggestion' => true,
                ];
            }
        }

        return [
            'tour_id'     => $tour_id,
            'title'       => $title,
            'description' => sprintf(
                /* translators: %s: user intent description */
                __( 'A guided tour to help you find: %s', 'sathi-agentic-ai' ),
                $intent
            ),
            'steps'       => $steps,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Return contextual suggestions based on the current page and
     * optional user query.
     *
     * Each suggestion maps to a safe client action the user might
     * want to take next.
     *
     * @param  string      $current_url The page URL the user is viewing.
     * @param  string|null $user_query  Optional natural-language intent.
     * @return array<int, array{action_type: string, params: array, narration: string, label: string}>
     */
    public function getContextualSuggestions( string $current_url, ?string $user_query = null ): array {
        $route_map = $this->nav->build_route_map();
        $page_type = $this->classify_page( $current_url, $route_map );
        $suggestions = [];

        switch ( $page_type ) {
            case 'home':
                $suggestions[] = [
                    'action_type' => 'navigate',
                    'params'      => [ 'url' => $this->find_page_url( 'about', $route_map ) ?? home_url( '/about/' ) ],
                    'narration'   => __( 'Learn more about what we do.', 'sathi-agentic-ai' ),
                    'label'       => __( 'About Us', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'navigate',
                    'params'      => [ 'url' => $this->find_page_url( 'service', $route_map ) ?? home_url( '/services/' ) ],
                    'narration'   => __( 'Explore our services and offerings.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Services', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'scroll_to',
                    'params'      => [ 'selector' => '.hero, .features, #main' ],
                    'narration'   => __( 'Let\'s explore the key sections of this page.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Explore Page', 'sathi-agentic-ai' ),
                ];

                if ( ! empty( $route_map['products'] ) ) {
                    $suggestions[] = [
                        'action_type' => 'navigate',
                        'params'      => [ 'url' => $this->find_page_url( 'shop', $route_map ) ?? home_url( '/shop/' ) ],
                        'narration'   => __( 'Browse our product catalog.', 'sathi-agentic-ai' ),
                        'label'       => __( 'Shop', 'sathi-agentic-ai' ),
                    ];
                }
                break;

            case 'product':
            case 'shop':
                $suggestions[] = [
                    'action_type' => 'scroll_to',
                    'params'      => [ 'selector' => '.products, .product-grid, .woocommerce' ],
                    'narration'   => __( 'Browse the available products.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Browse Products', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'navigate',
                    'params'      => [ 'url' => $this->find_page_url( 'cart', $route_map ) ?? home_url( '/cart/' ) ],
                    'narration'   => __( 'Review items in your shopping cart.', 'sathi-agentic-ai' ),
                    'label'       => __( 'View Cart', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'open_contact',
                    'params'      => [],
                    'narration'   => __( 'Need help with a product? Reach out to us.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Contact Support', 'sathi-agentic-ai' ),
                ];
                break;

            case 'post':
            case 'blog':
                $suggestions[] = [
                    'action_type' => 'scroll_to',
                    'params'      => [ 'selector' => '.entry-content, .post-content, #main' ],
                    'narration'   => __( 'Read through the full article here.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Read Article', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'focus_input',
                    'params'      => [ 'selector' => 'input[type="search"], .search-field' ],
                    'narration'   => __( 'Search for more articles on this topic.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Search Posts', 'sathi-agentic-ai' ),
                ];
                if ( ! empty( $route_map['categories'] ) ) {
                    $suggestions[] = [
                        'action_type' => 'highlight',
                        'params'      => [ 'selector' => '.category-list, .blog-categories, .widget_categories' ],
                        'narration'   => __( 'Explore more topics in these categories.', 'sathi-agentic-ai' ),
                        'label'       => __( 'Categories', 'sathi-agentic-ai' ),
                    ];
                }
                break;

            case 'contact':
                $suggestions[] = [
                    'action_type' => 'focus_input',
                    'params'      => [ 'selector' => 'input[name="your-name"], input[type="text"]:first-of-type' ],
                    'narration'   => __( 'Fill in your name to get started.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Start Form', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'navigate',
                    'params'      => [ 'url' => $this->find_page_url( 'faq', $route_map ) ?? home_url( '/faq/' ) ],
                    'narration'   => __( 'Maybe your question is already answered in our FAQ.', 'sathi-agentic-ai' ),
                    'label'       => __( 'View FAQ', 'sathi-agentic-ai' ),
                ];
                break;

            case 'page':
            default:
                $suggestions[] = [
                    'action_type' => 'scroll_to',
                    'params'      => [ 'selector' => '#main, .entry-content, main' ],
                    'narration'   => __( 'Explore the main content of this page.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Explore Content', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'navigate',
                    'params'      => [ 'url' => $this->find_page_url( 'contact', $route_map ) ?? home_url( '/contact/' ) ],
                    'narration'   => __( 'Get in touch with our team.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Contact Us', 'sathi-agentic-ai' ),
                ];
                $suggestions[] = [
                    'action_type' => 'focus_input',
                    'params'      => [ 'selector' => 'input[type="search"], .search-field' ],
                    'narration'   => __( 'Can\'t find something? Try searching.', 'sathi-agentic-ai' ),
                    'label'       => __( 'Search Site', 'sathi-agentic-ai' ),
                ];
                break;
        }

        // If we have a user query, inject one query-aware suggestion at the front
        if ( $user_query ) {
            $resolved = $this->nav->resolve_url( $user_query );
            if ( $resolved && $resolved['confidence'] > 0.5 ) {
                array_unshift( $suggestions, [
                    'action_type' => 'navigate',
                    'params'      => [ 'url' => $resolved['url'] ],
                    'narration'   => sprintf(
                        /* translators: %s: matched page title */
                        __( 'I found a page that matches your query: %s', 'sathi-agentic-ai' ),
                        $resolved['title']
                    ),
                    'label'       => sprintf(
                        /* translators: %s: matched page title */
                        __( 'Go to %s', 'sathi-agentic-ai' ),
                        $resolved['title']
                    ),
                ] );
            }
        }

        return array_values( $suggestions );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a single tour step payload.
     *
     * @param  string $action    Action type (navigate, scroll_to, highlight, etc.).
     * @param  array  $params    Action parameters.
     * @param  string $narration Tooltip / narration text for this step.
     * @param  int    $delay_ms  Millisecond pause before executing the step.
     * @return array{action: array|null, narration: string, delay_ms: int}
     */
    private function make_step( string $action, array $params, string $narration, int $delay_ms ): array {
        $action_payload = $this->actions->build( $action, $params );

        return [
            'action'    => $action_payload,
            'narration' => wp_kses_post( $narration ),
            'delay_ms'  => max( 0, $delay_ms ),
        ];
    }

    /**
     * Generate a human-readable tour title.
     *
     * @param  string     $intent
     * @param  array|null $target Resolved URL target or null.
     * @return string
     */
    private function generate_tour_title( string $intent, ?array $target ): string {
        if ( $target ) {
            return sprintf(
                /* translators: %s: target page title */
                __( 'Tour: %s', 'sathi-agentic-ai' ),
                $target['title']
            );
        }

        $topic = $this->extract_topic( $intent );
        return sprintf(
            /* translators: %s: topic keyword */
            __( 'Tour: Find %s', 'sathi-agentic-ai' ),
            ucfirst( $topic )
        );
    }

    /**
     * Check whether the given URL matches the current page.
     */
    private function is_current_url( string $url, string $current_url ): bool {
        if ( empty( $current_url ) ) {
            return false;
        }

        $url_path  = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
        $curr_path = wp_parse_url( $current_url, PHP_URL_PATH ) ?: '';

        return trailingslashit( $url_path ) === trailingslashit( $curr_path );
    }

    /**
     * Map an intent string to the most likely CSS selector for scrolling.
     *
     * @param  string $intent_lower Lowercase intent string.
     * @return string|null
     */
    private function infer_section_selector( string $intent_lower ): ?string {
        // Try exact keyword mappings first
        foreach ( self::INTENT_SECTION_MAP as $keyword => $selector ) {
            if ( str_contains( $intent_lower, $keyword ) ) {
                return $selector;
            }
        }

        // Fallback: look for matching page titles in the route map
        $words = explode( ' ', $intent_lower );
        foreach ( $words as $word ) {
            if ( strlen( $word ) < 3 ) {
                continue;
            }
            if ( isset( self::INTENT_SECTION_MAP[ $word ] ) ) {
                return self::INTENT_SECTION_MAP[ $word ];
            }
        }

        // Default: main content area
        return '#main, .entry-content, main';
    }

    /**
     * Pick a more specific selector for the highlight step.
     */
    private function infer_highlight_selector( string $intent_lower, array $route_map ): ?string {
        // Products → highlight the product grid
        if ( $this->is_product_intent( $intent_lower ) && ! empty( $route_map['products'] ) ) {
            return '.products, .product-grid, .woocommerce, [class*="product"]';
        }

        // Contact → highlight the form
        if ( str_contains( $intent_lower, 'contact' ) || str_contains( $intent_lower, 'help' ) ) {
            return '.contact-form, .wpcf7, form.wpcf7-form, .contact';
        }

        // Pricing → highlight pricing tables
        if ( str_contains( $intent_lower, 'pric' ) || str_contains( $intent_lower, 'cost' ) ) {
            return '.pricing, .price-table, .price, [class*="pricing"]';
        }

        // Blog / posts → highlight entry content
        if ( str_contains( $intent_lower, 'blog' ) || str_contains( $intent_lower, 'post' ) || str_contains( $intent_lower, 'article' ) ) {
            return '.entry-content, .post-content, .blog, [class*="post"]';
        }

        return null;
    }

    /**
     * Infer a form-input selector to focus.
     */
    private function infer_focus_target( string $intent_lower, array $route_map ): ?string {
        if ( str_contains( $intent_lower, 'search' ) || str_contains( $intent_lower, 'find' ) ) {
            return 'input[type="search"], .search-field';
        }

        if ( str_contains( $intent_lower, 'contact' ) || str_contains( $intent_lower, 'message' ) ) {
            return 'input[name="your-name"], textarea, input[type="text"]';
        }

        if ( str_contains( $intent_lower, 'subscribe' ) || str_contains( $intent_lower, 'newsletter' ) ) {
            return 'input[type="email"], .email-input';
        }

        // Only suggest focus if query seems input-oriented
        $input_words = [ 'search', 'find', 'type', 'enter', 'fill', 'write', 'contact', 'subscribe', 'login', 'sign' ];
        foreach ( $input_words as $w ) {
            if ( str_contains( $intent_lower, $w ) ) {
                return 'input[type="search"], .search-field, input[type="text"]';
            }
        }

        return null;
    }

    /**
     * Generate contextual tooltip text for the tour.
     */
    private function generate_tooltip_text( string $intent_lower, array $route_map, ?array $target ): string {
        $page_count   = count( $route_map['pages'] ?? [] );
        $product_count = count( $route_map['products'] ?? [] );

        if ( $target && $target['confidence'] > 0.7 ) {
            return sprintf(
                /* translators: %1$s: page title, %2$d: total pages on site */
                __( 'This is the <strong>%1$s</strong> page. We have %2$d pages on this site — feel free to explore!', 'sathi-agentic-ai' ),
                esc_html( $target['title'] ),
                $page_count
            );
        }

        if ( $this->is_product_intent( $intent_lower ) && $product_count > 0 ) {
            return sprintf(
                /* translators: %d: number of products */
                _n(
                    'We have <strong>%d product</strong> available. Click any item for details.',
                    'We have <strong>%d products</strong> available. Click any item for details.',
                    $product_count,
                    'sathi-agentic-ai'
                ),
                $product_count
            );
        }

        return sprintf(
            /* translators: %s: site name */
            __( 'Need more help? Just ask me — I\'m here to guide you around %s.', 'sathi-agentic-ai' ),
            get_bloginfo( 'name' )
        );
    }

    /**
     * Extract the key topic word(s) from an intent string.
     */
    private function extract_topic( string $intent ): string {
        // Remove common filler words
        $filler = [ 'i', 'want', 'to', 'find', 'see', 'show', 'me', 'the', 'a', 'an',
                     'how', 'where', 'is', 'are', 'can', 'you', 'help', 'with', 'for',
                     'need', 'looking', 'look', 'get', 'go', 'please', 'what', 'tell' ];

        $words = explode( ' ', mb_strtolower( trim( $intent ) ) );
        $meaningful = array_diff( $words, $filler );

        if ( ! empty( $meaningful ) ) {
            return implode( ' ', array_slice( array_values( $meaningful ), 0, 3 ) );
        }

        return __( 'this content', 'sathi-agentic-ai' );
    }

    /**
     * Convert a CSS selector into a human-readable label.
     */
    private function selector_human_label( string $selector, string $intent_lower ): string {
        foreach ( self::INTENT_SECTION_MAP as $keyword => $sel ) {
            if ( str_contains( $sel, $selector ) || str_contains( $selector, $keyword ) ) {
                return ucfirst( $keyword );
            }
        }

        // Strip CSS syntax
        $cleaned = preg_replace( '/[#\.\[\]\*\=\"\'\:\-\_]/', ' ', $selector );
        $cleaned = preg_replace( '/\s+/', ' ', trim( $cleaned ) );
        $words   = array_filter( explode( ' ', $cleaned ), fn( $w ) => strlen( $w ) > 1 );

        return ! empty( $words ) ? ucfirst( implode( ' ', array_slice( $words, 0, 2 ) ) ) : __( 'content', 'sathi-agentic-ai' );
    }

    /**
     * Find a page URL in the route map matching a slug or keyword.
     */
    private function find_page_url( string $keyword, array $route_map ): ?string {
        foreach ( $route_map['pages'] as $page ) {
            $slug  = mb_strtolower( $page['slug'] ?? '' );
            $title = mb_strtolower( $page['title'] ?? '' );

            if ( str_contains( $slug, $keyword ) || str_contains( $title, $keyword ) ) {
                return $page['url'];
            }
        }

        return null;
    }

    /**
     * Classify the current page into a type bucket for contextual suggestions.
     *
     * @return string 'home' | 'product' | 'shop' | 'post' | 'blog' | 'contact' | 'page'
     */
    private function classify_page( string $current_url, array $route_map ): string {
        $current_path = wp_parse_url( $current_url, PHP_URL_PATH ) ?: '';

        // Home
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '/';
        if ( trailingslashit( $current_path ) === trailingslashit( $home_path ) ) {
            return 'home';
        }

        // Check against route map pages
        foreach ( $route_map['pages'] as $page ) {
            $page_path = wp_parse_url( $page['url'], PHP_URL_PATH ) ?: '';
            if ( trailingslashit( $current_path ) === trailingslashit( $page_path ) ) {
                $title_lower = mb_strtolower( $page['title'] ?? '' );
                $slug_lower  = mb_strtolower( $page['slug'] ?? '' );

                if ( str_contains( $title_lower, 'contact' ) || str_contains( $slug_lower, 'contact' ) ) {
                    return 'contact';
                }
                if ( str_contains( $title_lower, 'shop' ) || str_contains( $slug_lower, 'shop' )
                    || str_contains( $title_lower, 'store' ) || str_contains( $slug_lower, 'store' ) ) {
                    return 'shop';
                }
                if ( str_contains( $title_lower, 'blog' ) || str_contains( $slug_lower, 'blog' ) ) {
                    return 'blog';
                }

                return 'page';
            }
        }

        // Check against products
        foreach ( $route_map['products'] as $product ) {
            $product_path = wp_parse_url( $product['url'], PHP_URL_PATH ) ?: '';
            if ( trailingslashit( $current_path ) === trailingslashit( $product_path ) ) {
                return 'product';
            }
        }

        // Check if it's a post
        foreach ( $route_map['posts'] as $post ) {
            $post_path = wp_parse_url( $post['url'], PHP_URL_PATH ) ?: '';
            if ( trailingslashit( $current_path ) === trailingslashit( $post_path ) ) {
                return 'post';
            }
        }

        // Default
        return 'page';
    }

    /**
     * Check whether the intent is product / shopping related.
     */
    private function is_product_intent( string $intent_lower ): bool {
        $product_words = [ 'buy', 'price', 'product', 'shop', 'purchase', 'order',
                           'cart', 'item', 'cost', 'store', 'catalog', 'collection' ];

        foreach ( $product_words as $word ) {
            if ( str_contains( $intent_lower, $word ) ) {
                return true;
            }
        }

        return false;
    }
}
