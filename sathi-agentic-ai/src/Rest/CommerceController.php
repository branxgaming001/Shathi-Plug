<?php
/**
 * Commerce REST controller — product search + add-to-cart for the chat widget.
 *
 * @package NeerMedia\Sathi\Rest
 */

namespace NeerMedia\Sathi\Rest;

use NeerMedia\Sathi\Commerce\ProductSearch;
use WP_REST_Request;
use WP_REST_Response;

class CommerceController {

    public const NAMESPACE = 'sathi/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'search' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'q'     => [ 'type' => 'string', 'required' => true ],
                'limit' => [ 'type' => 'integer', 'default' => 3 ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/cart/add', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'add_to_cart' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function search( WP_REST_Request $request ): WP_REST_Response {
        $ps = new ProductSearch();
        if ( ! $ps->available() ) {
            return new WP_REST_Response( [ 'available' => false, 'products' => [] ] );
        }
        $q     = sanitize_text_field( (string) $request->get_param( 'q' ) );
        $limit = min( 5, max( 1, (int) $request->get_param( 'limit' ) ) );
        return new WP_REST_Response( [ 'available' => true, 'products' => $ps->search( $q, $limit ) ] );
    }

    public function add_to_cart( WP_REST_Request $request ): WP_REST_Response {
        // Verify the REST nonce for cart mutations.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid nonce.' ], 403 );
        }

        $body       = (array) $request->get_json_params();
        $product_id = absint( $body['product_id'] ?? 0 );
        $quantity   = max( 1, absint( $body['quantity'] ?? 1 ) );

        if ( ! $product_id ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing product_id.' ], 400 );
        }

        $result = ( new ProductSearch() )->add_to_cart( $product_id, $quantity );
        return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }
}
