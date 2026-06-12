<?php
/**
 * Personas REST Controller.
 *
 * @package RaiLabs\Sathi\Rest
 */

namespace RaiLabs\Sathi\Rest;

use RaiLabs\Sathi\Personas\PersonaRegistry;
use WP_REST_Request;
use WP_REST_Response;

class PersonasController {

    private const NAMESPACE = 'sathi/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/personas', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_personas' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/personas/(?P<slug>[a-z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_persona' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/personas', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_persona' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        register_rest_route( self::NAMESPACE, '/personas/(?P<slug>[a-z0-9\-]+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_persona' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        register_rest_route( self::NAMESPACE, '/personas/(?P<slug>[a-z0-9\-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_persona' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
    }

    public function list_personas(): WP_REST_Response {
        $registry = new PersonaRegistry();
        return new WP_REST_Response( [ 'personas' => $registry->get_all() ] );
    }

    public function get_persona( WP_REST_Request $request ): WP_REST_Response {
        $registry = new PersonaRegistry();
        $persona  = $registry->get( $request->get_param( 'slug' ) );

        if ( ! $persona ) {
            return new WP_REST_Response( [ 'error' => 'not_found' ], 404 );
        }

        return new WP_REST_Response( [ 'persona' => $persona ] );
    }

    public function create_persona( WP_REST_Request $request ): WP_REST_Response {
        $registry = new PersonaRegistry();
        $data     = $request->get_json_params();
        $id       = $registry->create( $data );

        if ( ! $id ) {
            return new WP_REST_Response( [ 'error' => 'create_failed' ], 500 );
        }

        return new WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 );
    }

    public function update_persona( WP_REST_Request $request ): WP_REST_Response {
        $registry = new PersonaRegistry();
        $updated  = $registry->update( $request->get_param( 'slug' ), $request->get_json_params() );

        return new WP_REST_Response( [ 'success' => $updated ] );
    }

    public function delete_persona( WP_REST_Request $request ): WP_REST_Response {
        $registry = new PersonaRegistry();
        $deleted  = $registry->delete( $request->get_param( 'slug' ) );

        return new WP_REST_Response( [ 'success' => $deleted ] );
    }

    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }
}
