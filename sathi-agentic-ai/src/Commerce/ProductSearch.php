<?php
/**
 * Product Search — WooCommerce product lookup + cart actions for the chat widget.
 *
 * Degrades gracefully: every method is a no-op (returns empty / unavailable)
 * when WooCommerce is not active, so the plugin works on non-Woo sites.
 *
 * @package RaiLabs\Sathi\Commerce
 */

namespace RaiLabs\Sathi\Commerce;

class ProductSearch {

    /** Whether WooCommerce is active. */
    public function available(): bool {
        return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' );
    }

    /**
     * Search products and return up to $limit display-ready cards.
     *
     * @param  string $query
     * @param  int    $limit
     * @return array<int, array<string, mixed>>
     */
    public function search( string $query, int $limit = 3 ): array {
        if ( ! $this->available() ) {
            return [];
        }
        $query = trim( $query );
        if ( $query === '' ) {
            return [];
        }

        $products = wc_get_products( [
            'status'   => 'publish',
            'limit'    => $limit,
            's'        => $query,
            'orderby'  => 'relevance',
        ] );

        // Fallback to a core search if the Woo query came up empty.
        if ( empty( $products ) ) {
            $ids = get_posts( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                's'              => $query,
                'posts_per_page' => $limit,
                'fields'         => 'ids',
            ] );
            $products = array_filter( array_map( 'wc_get_product', $ids ) );
        }

        $cards = [];
        foreach ( $products as $product ) {
            if ( ! $product ) {
                continue;
            }
            $cards[] = $this->format_card( $product );
        }
        return $cards;
    }

    /**
     * Build one product card payload.
     *
     * @param \WC_Product $product
     * @return array<string, mixed>
     */
    private function format_card( $product ): array {
        $id  = $product->get_id();
        $img = '';
        if ( $product->get_image_id() ) {
            $img = wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ?: '';
        }
        if ( ! $img && function_exists( 'wc_placeholder_img_src' ) ) {
            $img = wc_placeholder_img_src( 'medium' );
        }

        $excerpt = $product->get_short_description();
        if ( ! $excerpt ) {
            $excerpt = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 26 );
        } else {
            $excerpt = wp_strip_all_tags( $excerpt );
        }

        $checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );

        return [
            'id'          => $id,
            'name'        => $product->get_name(),
            'price_html'  => wp_strip_all_tags( $product->get_price_html() ),
            'price'       => $product->get_price(),
            'regular'     => $product->get_regular_price(),
            'sale'        => $product->get_sale_price(),
            'on_sale'     => $product->is_on_sale(),
            'image'       => $img,
            'permalink'   => get_permalink( $id ),
            'excerpt'     => $excerpt,
            'in_stock'    => $product->is_in_stock(),
            'purchasable' => $product->is_purchasable() && $product->is_in_stock(),
            'type'        => $product->get_type(),
            // Variable products can't be added by ID alone — send shoppers to the page.
            'buy_now_url' => $product->is_type( 'simple' )
                ? add_query_arg( 'add-to-cart', $id, $checkout )
                : get_permalink( $id ),
        ];
    }

    /**
     * Add a product to the current visitor's cart.
     *
     * @param  int $product_id
     * @param  int $quantity
     * @return array{success: bool, message?: string, count?: int, cart_url?: string, checkout_url?: string}
     */
    public function add_to_cart( int $product_id, int $quantity = 1 ): array {
        if ( ! $this->available() || ! function_exists( 'WC' ) ) {
            return [ 'success' => false, 'message' => __( 'Store is unavailable.', 'sathi-agentic-ai' ) ];
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return [ 'success' => false, 'message' => __( 'This product cannot be added to the cart.', 'sathi-agentic-ai' ) ];
        }
        if ( ! $product->is_type( 'simple' ) ) {
            return [ 'success' => false, 'message' => __( 'Please choose options on the product page.', 'sathi-agentic-ai' ), 'redirect' => get_permalink( $product_id ) ];
        }

        // Ensure the cart/session is loaded in this (REST) request context.
        if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
            wc_load_cart();
        }
        if ( ! WC()->cart ) {
            return [ 'success' => false, 'message' => __( 'Cart could not be initialized.', 'sathi-agentic-ai' ) ];
        }

        $added = WC()->cart->add_to_cart( $product_id, max( 1, $quantity ) );
        if ( ! $added ) {
            return [ 'success' => false, 'message' => __( 'Could not add to cart.', 'sathi-agentic-ai' ) ];
        }

        // CRITICAL: persist the cart to the visitor's BROWSER session so the item
        // is still there when they open the cart/checkout. Without forcing the
        // session cookie on this REST response, the add happens server-side but
        // the browser's checkout shows an empty cart.
        if ( WC()->session ) {
            WC()->session->set_customer_session_cookie( true );
            if ( method_exists( WC()->session, 'save_data' ) ) {
                WC()->session->save_data();
            }
        }
        WC()->cart->set_session();
        WC()->cart->maybe_set_cart_cookies();

        // A reliable one-click link: WooCommerce adds the item in the BROWSER
        // (proper session) and lands on checkout.
        $checkout    = wc_get_checkout_url();
        $buy_now_url = $product->is_type( 'simple' ) ? add_query_arg( 'add-to-cart', $product_id, $checkout ) : get_permalink( $product_id );

        return [
            'success'      => true,
            'count'        => WC()->cart->get_cart_contents_count(),
            'cart_url'     => wc_get_cart_url(),
            'checkout_url' => $checkout,
            'buy_now_url'  => $buy_now_url,
            'message'      => sprintf( __( '%s added to cart.', 'sathi-agentic-ai' ), $product->get_name() ),
        ];
    }
}
