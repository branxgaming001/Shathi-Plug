<?php
/**
 * License REST controller — status / activate / deactivate (admin only).
 *
 * @package NeerMedia\Sathi\Rest
 */

namespace NeerMedia\Sathi\Rest;

use NeerMedia\Sathi\License\LicenseManager;
use WP_REST_Request;
use WP_REST_Response;

class LicenseController {

    public const NAMESPACE = 'sathi/v1';

    public function register_routes(): void {
        $perm = [ $this, 'check_admin' ];

        register_rest_route( self::NAMESPACE, '/license/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => $perm,
        ] );

        register_rest_route( self::NAMESPACE, '/license/activate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'activate' ],
            'permission_callback' => $perm,
        ] );

        register_rest_route( self::NAMESPACE, '/license/deactivate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'deactivate' ],
            'permission_callback' => $perm,
        ] );
    }

    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    public function get_status( WP_REST_Request $request ): WP_REST_Response {
        $lm     = new LicenseManager();
        $force  = (bool) $request->get_param( 'refresh' );
        $status = $lm->status( $force );
        return new WP_REST_Response( $this->envelope( $lm, $status ) );
    }

    public function activate( WP_REST_Request $request ): WP_REST_Response {
        $key = sanitize_text_field( (string) ( $request->get_json_params()['key'] ?? $request->get_param( 'key' ) ?? '' ) );
        if ( $key === '' ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'License key required.' ], 400 );
        }
        $lm     = new LicenseManager();
        $status = $lm->activate( $key );
        return new WP_REST_Response( $this->envelope( $lm, $status ) );
    }

    public function deactivate(): WP_REST_Response {
        $lm     = new LicenseManager();
        $status = $lm->deactivate();
        return new WP_REST_Response( $this->envelope( $lm, [ 'status' => 'inactive' ] + (array) $status ) );
    }

    /** Build a key-free response envelope for the admin UI. */
    private function envelope( LicenseManager $lm, array $status ): array {
        return [
            'success'     => ( $status['status'] ?? '' ) === 'active',
            'status'      => $status['status'] ?? 'inactive',
            'plan'        => $status['plan'] ?? '',
            'expires'     => $status['expires'] ?? '',
            'domains'     => $status['domains'] ?? [],
            'max_domains' => $status['max_domains'] ?? 1,
            'message'     => $status['message'] ?? '',
            'enforced'    => $lm->enforcement_enabled(),
            'has_key'     => $lm->get_key() !== '',
            'server_url'  => $lm->server_url(),
            'domain'      => $lm->domain(),
        ];
    }
}
