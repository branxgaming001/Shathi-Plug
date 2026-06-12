<?php
/**
 * WooCommerce Abilities — AI-callable functions for WooCommerce stores.
 *
 * Registers all 6 WooCommerce tools with proper JSON Schema parameters and
 * capability gating. Automatically detects WooCommerce availability and
 * silently skips registration when WooCommerce is absent.
 *
 * @package RaiLabs\Sathi\Abilities
 */

namespace RaiLabs\Sathi\Abilities;

class WooCommerceAbilities {

    /** @var bool Whether WooCommerce is active and fully loaded */
    private readonly bool $available;

    /** @var AbilityRegistry */
    private AbilityRegistry $registry;

    /**
     * @param AbilityRegistry|null $registry If null, uses the singleton instance.
     */
    public function __construct( ?AbilityRegistry $registry = null ) {
        $this->registry  = $registry ?? AbilityRegistry::instance();
        $this->available = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
    }

    // ── Registration ───────────────────────────────────────────────

    /**
     * Register all WooCommerce abilities if WooCommerce is active.
     *
     * Called during plugin boot. Safe to call multiple times — duplicates are overwritten.
     */
    public function register(): void {
        if ( ! $this->available ) {
            return;
        }

        $this->register_product_search();
        $this->register_get_product();
        $this->register_get_cart();
        $this->register_get_orders();
        $this->register_check_stock();
        $this->register_get_categories();
    }

    // ── Individual Ability Registrations ───────────────────────────

    private function register_product_search(): void {
        $this->registry->register( 'sathi_wc_search_products', [
            'label'       => __( 'Search Products', 'sathi-agentic-ai' ),
            'description' => __( 'Search WooCommerce products by name or keyword. Returns matching products with id, name, price, stock status, SKU, and URL.', 'sathi-agentic-ai' ),
            'category'    => 'woocommerce',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => 'Search query — matches product titles and descriptions.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of products to return (1–50).',
                        'default'     => 5,
                        'minimum'     => 1,
                        'maximum'     => 50,
                    ],
                ],
                'required' => [ 'query' ],
            ],
            'callback'   => function ( array $args ): array {
                $limit = min( max( (int) ( $args['limit'] ?? 5 ), 1 ), 50 );

                try {
                    $products = wc_get_products( [
                        's'       => sanitize_text_field( $args['query'] ),
                        'limit'   => $limit,
                        'status'  => 'publish',
                        'orderby' => 'relevance',
                    ] );

                    return [
                        'results' => array_map( function ( $product ): array {
                            return [
                                'id'          => $product->get_id(),
                                'name'        => $product->get_name(),
                                'price'       => $product->get_price(),
                                'regular_price' => $product->get_regular_price(),
                                'currency'    => get_woocommerce_currency(),
                                'url'         => get_permalink( $product->get_id() ),
                                'in_stock'    => $product->is_in_stock(),
                                'sku'         => $product->get_sku(),
                                'image'       => wp_get_attachment_url( $product->get_image_id() ) ?: null,
                                'on_sale'     => $product->is_on_sale(),
                            ];
                        }, $products ),
                        'total_found' => count( $products ),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'error'   => __( 'Failed to search products.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_product(): void {
        $this->registry->register( 'sathi_wc_get_product', [
            'label'       => __( 'Get Product Details', 'sathi-agentic-ai' ),
            'description' => __( 'Get comprehensive details about a specific product by ID. Returns name, description, price, stock, categories, SKU, image, and URL.', 'sathi-agentic-ai' ),
            'category'    => 'woocommerce',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [
                        'type'        => 'integer',
                        'description' => 'The WooCommerce product ID to look up.',
                    ],
                ],
                'required' => [ 'product_id' ],
            ],
            'callback'   => function ( array $args ): array {
                $product_id = (int) ( $args['product_id'] ?? 0 );

                if ( $product_id <= 0 ) {
                    return [ 'found' => false, 'message' => __( 'Invalid product ID.', 'sathi-agentic-ai' ) ];
                }

                try {
                    $product = wc_get_product( $product_id );

                    if ( ! $product || $product->get_status() !== 'publish' ) {
                        return [ 'found' => false, 'message' => __( 'Product not found or not published.', 'sathi-agentic-ai' ) ];
                    }

                    $categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
                    if ( is_wp_error( $categories ) ) {
                        $categories = [];
                    }

                    $tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );
                    if ( is_wp_error( $tags ) ) {
                        $tags = [];
                    }

                    // Build attribute summary
                    $attributes = [];
                    foreach ( $product->get_attributes() as $attribute ) {
                        $attributes[] = [
                            'name'    => wc_attribute_label( $attribute->get_name() ),
                            'options' => $attribute->get_options(),
                        ];
                    }

                    return [
                        'found'          => true,
                        'id'             => $product->get_id(),
                        'name'           => $product->get_name(),
                        'description'    => wp_trim_words( wp_strip_all_tags( $product->get_description() ), 100 ),
                        'short_description' => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 50 ),
                        'price'          => $product->get_price(),
                        'regular_price'  => $product->get_regular_price(),
                        'sale_price'     => $product->get_sale_price(),
                        'currency'       => get_woocommerce_currency(),
                        'in_stock'       => $product->is_in_stock(),
                        'stock_quantity' => $product->get_stock_quantity(),
                        'stock_status'   => $product->get_stock_status(),
                        'sku'            => $product->get_sku(),
                        'categories'     => $categories,
                        'tags'           => $tags,
                        'attributes'     => $attributes,
                        'url'            => get_permalink( $product->get_id() ),
                        'image'          => wp_get_attachment_url( $product->get_image_id() ) ?: null,
                        'gallery'        => array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
                        'on_sale'        => $product->is_on_sale(),
                        'type'           => $product->get_type(),
                        'average_rating' => (float) $product->get_average_rating(),
                        'review_count'   => (int) $product->get_review_count(),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'found'   => false,
                        'error'   => __( 'Failed to retrieve product.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_cart(): void {
        $this->registry->register( 'sathi_wc_get_cart', [
            'label'       => __( 'Get Cart', 'sathi-agentic-ai' ),
            'description' => __( 'Get the current user\'s shopping cart contents, including items, quantities, line totals, cart total, discount, and checkout URL. Requires WooCommerce session.', 'sathi-agentic-ai' ),
            'category'    => 'woocommerce',
            'schema'      => [
                'type'       => 'object',
                'properties' => [],
            ],
            'callback'   => function (): array {
                if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
                    return [ 'error' => __( 'Cart is not available — WooCommerce is not fully loaded.', 'sathi-agentic-ai' ) ];
                }

                try {
                    $cart  = WC()->cart;
                    $items = [];

                    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                        /** @var \WC_Product $product */
                        $product = $cart_item['data'];
                        $items[] = [
                            'cart_key'     => $cart_item_key,
                            'product_id'   => $product->get_id(),
                            'product_name' => $product->get_name(),
                            'sku'          => $product->get_sku(),
                            'quantity'     => $cart_item['quantity'],
                            'price'        => (float) $product->get_price(),
                            'line_total'   => (float) $cart_item['line_total'],
                            'line_subtotal'=> (float) ( $cart_item['line_subtotal'] ?? 0 ),
                            'image'        => wp_get_attachment_url( $product->get_image_id() ) ?: null,
                            'url'          => get_permalink( $product->get_id() ),
                        ];
                    }

                    return [
                        'items'           => $items,
                        'item_count'      => $cart->get_cart_contents_count(),
                        'total_items'     => count( $items ),
                        'subtotal'        => $cart->get_subtotal(),
                        'total'           => $cart->get_total(),
                        'discount_total'  => $cart->get_discount_total(),
                        'shipping_total'  => $cart->get_shipping_total(),
                        'tax_total'       => $cart->get_cart_contents_tax(),
                        'currency'        => get_woocommerce_currency(),
                        'cart_url'        => wc_get_cart_url(),
                        'checkout_url'    => wc_get_checkout_url(),
                        'is_empty'        => $cart->is_empty(),
                        'needs_shipping'  => $cart->needs_shipping(),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'error'   => __( 'Failed to retrieve cart.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_orders(): void {
        $this->registry->register( 'sathi_wc_get_orders', [
            'label'       => __( 'Get Orders', 'sathi-agentic-ai' ),
            'description' => __( 'Get recent orders for the current logged-in user. Filterable by status. Admin users can view all orders; regular users see only their own.', 'sathi-agentic-ai' ),
            'category'    => 'woocommerce',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Order status filter. Common values: completed, processing, pending, on-hold, cancelled, refunded, failed. Use "any" for all statuses.',
                        'default'     => 'completed',
                        'enum'        => [ 'any', 'completed', 'processing', 'pending', 'on-hold', 'cancelled', 'refunded', 'failed', 'checkout-draft' ],
                    ],
                    'limit'  => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of orders to return (1–25).',
                        'default'     => 5,
                        'minimum'     => 1,
                        'maximum'     => 25,
                    ],
                ],
            ],
            'callback'   => function ( array $args ): array {
                $user_id = get_current_user_id();

                if ( ! $user_id && ! current_user_can( 'manage_options' ) ) {
                    return [ 'error' => __( 'You must be logged in to view orders.', 'sathi-agentic-ai' ) ];
                }

                $limit  = min( max( (int) ( $args['limit'] ?? 5 ), 1 ), 25 );
                $status = sanitize_text_field( $args['status'] ?? 'completed' );

                $query_args = [
                    'limit'   => $limit,
                    'orderby' => 'date',
                    'order'   => 'DESC',
                ];

                // Admins can see all orders; regular users see only their own
                if ( ! current_user_can( 'manage_options' ) ) {
                    $query_args['customer_id'] = $user_id;
                }

                if ( $status !== 'any' ) {
                    $query_args['status'] = $status;
                }

                try {
                    $orders = wc_get_orders( $query_args );

                    return [
                        'orders' => array_map( function ( \WC_Order $order ): array {
                            $items_summary = [];
                            foreach ( $order->get_items() as $item ) {
                                $items_summary[] = [
                                    'name'     => $item->get_name(),
                                    'quantity' => $item->get_quantity(),
                                    'total'    => (float) $item->get_total(),
                                ];
                            }

                            return [
                                'id'            => $order->get_id(),
                                'order_number'  => $order->get_order_number(),
                                'status'        => $order->get_status(),
                                'status_label'  => wc_get_order_status_name( $order->get_status() ),
                                'total'         => $order->get_total(),
                                'currency'      => $order->get_currency(),
                                'date_created'  => $order->get_date_created()?->date( 'Y-m-d' ) ?? '',
                                'date_modified' => $order->get_date_modified()?->date( 'Y-m-d' ) ?? '',
                                'item_count'    => $order->get_item_count(),
                                'items'         => $items_summary,
                                'payment_method'=> $order->get_payment_method_title(),
                                'shipping_method'=> $order->get_shipping_method(),
                                'billing_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                                'view_url'      => $order->get_view_order_url(),
                            ];
                        }, $orders ),
                        'total_orders' => count( $orders ),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'orders'  => [],
                        'error'   => __( 'Failed to retrieve orders.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_check_stock(): void {
        $this->registry->register( 'sathi_wc_check_stock', [
            'label'       => __( 'Check Stock', 'sathi-agentic-ai' ),
            'description' => __( 'Check detailed stock status for a specific product, including quantity, backorder settings, and stock threshold.', 'sathi-agentic-ai' ),
            'category'    => 'woocommerce',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [
                        'type'        => 'integer',
                        'description' => 'The product ID to check stock for.',
                    ],
                ],
                'required' => [ 'product_id' ],
            ],
            'callback'   => function ( array $args ): array {
                $product_id = (int) ( $args['product_id'] ?? 0 );

                if ( $product_id <= 0 ) {
                    return [ 'error' => __( 'Invalid product ID.', 'sathi-agentic-ai' ) ];
                }

                try {
                    $product = wc_get_product( $product_id );

                    if ( ! $product ) {
                        return [ 'error' => __( 'Product not found.', 'sathi-agentic-ai' ) ];
                    }

                    $managing_stock = $product->managing_stock();

                    return [
                        'product_id'        => $product->get_id(),
                        'product_name'      => $product->get_name(),
                        'sku'               => $product->get_sku(),
                        'in_stock'          => $product->is_in_stock(),
                        'managing_stock'    => $managing_stock,
                        'stock_quantity'    => $managing_stock ? $product->get_stock_quantity() : null,
                        'stock_status'      => $product->get_stock_status(),
                        'backorders'        => $product->get_backorders(),
                        'backorders_allowed'=> $product->backorders_allowed(),
                        'low_stock_amount'  => $product->get_low_stock_amount(),
                        'sold_individually' => $product->is_sold_individually(),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'error'   => __( 'Failed to check stock.', 'sathi-agentic-ai' ),
                        'details' => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    private function register_get_categories(): void {
        $this->registry->register( 'sathi_wc_get_categories', [
            'label'       => __( 'Product Categories', 'sathi-agentic-ai' ),
            'description' => __( 'List all product categories with name, slug, product count, description, and URL. Only returns categories that have products.', 'sathi-agentic-ai' ),
            'category'    => 'woocommerce',
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'limit'       => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of categories to return (1–100).',
                        'default'     => 20,
                        'minimum'     => 1,
                        'maximum'     => 100,
                    ],
                    'hide_empty'  => [
                        'type'        => 'boolean',
                        'description' => 'Whether to hide categories with no products.',
                        'default'     => true,
                    ],
                    'parent'      => [
                        'type'        => 'integer',
                        'description' => 'Parent category ID. Use 0 for top-level categories only. Omit for all.',
                        'default'     => 0,
                    ],
                ],
            ],
            'callback'   => function ( array $args ): array {
                $limit      = min( max( (int) ( $args['limit'] ?? 20 ), 1 ), 100 );
                $hide_empty = (bool) ( $args['hide_empty'] ?? true );
                $parent     = $args['parent'] ?? null;

                $query_args = [
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => $hide_empty,
                    'number'     => $limit,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                ];

                if ( $parent !== null ) {
                    $query_args['parent'] = (int) $parent;
                }

                try {
                    $categories = get_terms( $query_args );

                    if ( is_wp_error( $categories ) ) {
                        return [
                            'categories' => [],
                            'error'      => $categories->get_error_message(),
                        ];
                    }

                    return [
                        'categories' => array_map( function ( \WP_Term $cat ): array {
                            $thumbnail_id = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                            return [
                                'id'          => $cat->term_id,
                                'name'        => $cat->name,
                                'slug'        => $cat->slug,
                                'description' => wp_trim_words( wp_strip_all_tags( $cat->description ), 30 ),
                                'count'       => $cat->count,
                                'url'         => get_term_link( $cat ),
                                'image'       => $thumbnail_id ? wp_get_attachment_url( (int) $thumbnail_id ) : null,
                                'parent'      => $cat->parent,
                            ];
                        }, $categories ),
                        'total' => count( $categories ),
                    ];
                } catch ( \Throwable $e ) {
                    return [
                        'categories' => [],
                        'error'      => __( 'Failed to retrieve categories.', 'sathi-agentic-ai' ),
                        'details'    => $e->getMessage(),
                    ];
                }
            },
            'capability' => 'read',
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Whether WooCommerce is active and available.
     */
    public function is_available(): bool {
        return $this->available;
    }

    /**
     * Check availability of a specific WooCommerce feature.
     *
     * @param  string $feature 'cart', 'orders', 'products', 'categories'.
     * @return bool
     */
    public function has_feature( string $feature ): bool {
        if ( ! $this->available ) {
            return false;
        }

        return match ( $feature ) {
            'cart'       => function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart,
            'orders'     => function_exists( 'wc_get_orders' ),
            'products'   => function_exists( 'wc_get_products' ),
            'categories' => ! is_wp_error( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1 ] ) ),
            default      => false,
        };
    }
}
