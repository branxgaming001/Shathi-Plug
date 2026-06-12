<?php
/**
 * Memory REST Controller.
 *
 * Public endpoints for the chat widget, admin endpoints for the memory
 * management page.
 *
 * @package RaiLabs\Sathi\Rest
 */

namespace RaiLabs\Sathi\Rest;

use RaiLabs\Sathi\Core\Settings;
use RaiLabs\Sathi\Core\Data\Conversation;
use RaiLabs\Sathi\Memory\MemoryManager;
use RaiLabs\Sathi\Memory\MemoryStore;
use RaiLabs\Sathi\Providers\Factory;
use RaiLabs\Sathi\Support\Helpers;
use WP_REST_Request;
use WP_REST_Response;

class MemoryController {

    private const NAMESPACE = 'sathi/v1';

    public function register_routes(): void {
        // ── Public routes (current user's own memory) ────────────
        register_rest_route( self::NAMESPACE, '/memory', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_memory' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/memory', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_memory' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/memory/(?P<key>[a-z_]+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_entry' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Public: LLM-generated user profile ────────────────────
        register_rest_route( self::NAMESPACE, '/memory/profile', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_profile' ],
            'permission_callback' => '__return_true',
        ] );

        // ── Admin: memory extraction ──────────────────────────────
        register_rest_route( self::NAMESPACE, '/memory/extract', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'extract_memories' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'conversation_uuid' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // ── Admin: search all memories ────────────────────────────
        register_rest_route( self::NAMESPACE, '/memory/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'search_memories' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // ── Admin: paginated entries table ────────────────────────
        register_rest_route( self::NAMESPACE, '/memory/entries', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_entries' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // ── Admin: get stats ──────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/memory/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_stats' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        // ── Admin: delete by database ID ──────────────────────────
        register_rest_route( self::NAMESPACE, '/memory/entry/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_entry_by_id' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );
    }

    // ── Public Endpoints ─────────────────────────────────────────────

    /**
     * GET /memory — get memory for current user/guest.
     */
    public function get_memory( WP_REST_Request $request ): WP_REST_Response {
        $user_id  = get_current_user_id() ?: null;
        // Bind to the server-derived guest identity. Never trust a client-supplied
        // guest_id — doing so would let a visitor read or delete another visitor's
        // memory by forging the parameter (IDOR). The widget already sends this same
        // server-computed value, so legitimate behaviour is unchanged.
        $guest_id = Helpers::guest_id();
        $store    = new MemoryStore();

        $entries = $store->get_all( $user_id, $guest_id );

        return new WP_REST_Response( [
            'entries'  => $entries,
            'count'    => count( $entries ),
            'guest_id' => $guest_id,
        ] );
    }

    /**
     * DELETE /memory — delete all memory for current user/guest.
     */
    public function delete_memory( WP_REST_Request $request ): WP_REST_Response {
        $user_id  = get_current_user_id() ?: null;
        // Bind to the server-derived guest identity. Never trust a client-supplied
        // guest_id — doing so would let a visitor read or delete another visitor's
        // memory by forging the parameter (IDOR). The widget already sends this same
        // server-computed value, so legitimate behaviour is unchanged.
        $guest_id = Helpers::guest_id();
        $store    = new MemoryStore();

        $count = $store->forget_all( $user_id, $guest_id );

        return new WP_REST_Response( [
            'success'    => true,
            'deleted'    => $count,
            'message'    => sprintf(
                /* translators: %d: number of deleted memory entries */
                __( '%d memory entries deleted.', 'sathi-agentic-ai' ),
                $count
            ),
        ] );
    }

    /**
     * DELETE /memory/{key} — delete a specific entry for current user/guest.
     */
    public function delete_entry( WP_REST_Request $request ): WP_REST_Response {
        $user_id  = get_current_user_id() ?: null;
        // Bind to the server-derived guest identity. Never trust a client-supplied
        // guest_id — doing so would let a visitor read or delete another visitor's
        // memory by forging the parameter (IDOR). The widget already sends this same
        // server-computed value, so legitimate behaviour is unchanged.
        $guest_id = Helpers::guest_id();
        $key      = sanitize_key( $request->get_param( 'key' ) );
        $store    = new MemoryStore();

        $deleted = $store->forget( $user_id, $guest_id, $key );

        return new WP_REST_Response( [ 'success' => $deleted ] );
    }

    /**
     * GET /memory/profile — get the LLM-generated 3-sentence user profile.
     */
    public function get_profile( WP_REST_Request $request ): WP_REST_Response {
        $user_id  = get_current_user_id() ?: null;
        // Bind to the server-derived guest identity. Never trust a client-supplied
        // guest_id — doing so would let a visitor read or delete another visitor's
        // memory by forging the parameter (IDOR). The widget already sends this same
        // server-computed value, so legitimate behaviour is unchanged.
        $guest_id = Helpers::guest_id();

        $manager = $this->make_manager();
        $profile = $manager->smartSummarize( $user_id, $guest_id );

        return new WP_REST_Response( [
            'profile' => $profile,
            'has_memories' => ! empty( $profile ),
        ] );
    }

    // ── Admin Endpoints ───────────────────────────────────────────────

    /**
     * POST /memory/extract — use LLM to extract memories from a conversation.
     */
    public function extract_memories( WP_REST_Request $request ): WP_REST_Response {
        $uuid = $request->get_param( 'conversation_uuid' );

        // Load the conversation with its messages
        $conv = $this->load_conversation( $uuid );

        if ( ! $conv ) {
            return new WP_REST_Response( [
                'success' => false,
                'error'   => 'not_found',
                'message' => __( 'Conversation not found.', 'sathi-agentic-ai' ),
            ], 404 );
        }

        $manager = $this->make_manager();
        $result  = $manager->extractFromConversation( $conv );

        return new WP_REST_Response( [
            'success'       => true,
            'extracted'     => $result['extracted'],
            'summary'       => $result['summary'],
            'extracted_count' => count( $result['extracted'] ),
            'fallback'      => $result['fallback'] ?? false,
            'error'         => $result['error'] ?? null,
        ] );
    }

    /**
     * GET /memory/search — keyword search across all memory entries (admin).
     */
    public function search_memories( WP_REST_Request $request ): WP_REST_Response {
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
        $user_id  = $request->get_param( 'user_id' ) ? (int) $request->get_param( 'user_id' ) : null;
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
        $per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) );

        $manager = $this->make_manager();
        $result  = $manager->getAdminEntries( [
            'search'   => $search,
            'user_id'  => $user_id,
            'page'     => $page,
            'per_page' => $per_page,
        ] );

        return new WP_REST_Response( $result );
    }

    /**
     * GET /memory/entries — paginated entries for the admin table.
     */
    public function get_entries( WP_REST_Request $request ): WP_REST_Response {
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
        $user_id  = $request->get_param( 'user_id' ) ? (int) $request->get_param( 'user_id' ) : null;
        $page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
        $per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) );

        $manager = $this->make_manager();
        $result  = $manager->getAdminEntries( [
            'search'   => $search,
            'user_id'  => $user_id,
            'page'     => $page,
            'per_page' => $per_page,
        ] );

        return new WP_REST_Response( $result );
    }

    /**
     * GET /memory/stats — aggregate memory statistics.
     */
    public function get_stats( WP_REST_Request $request ): WP_REST_Response {
        $manager = $this->make_manager();
        $stats   = $manager->getStats();

        return new WP_REST_Response( $stats );
    }

    /**
     * DELETE /memory/entry/{id} — delete by database ID (admin).
     */
    public function delete_entry_by_id( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $manager = $this->make_manager();
        $deleted = $manager->deleteById( $id );

        if ( ! $deleted ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Entry not found or could not be deleted.', 'sathi-agentic-ai' ),
            ], 404 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => __( 'Memory entry deleted.', 'sathi-agentic-ai' ),
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────────

    /**
     * Admin-only permission check.
     */
    public function check_admin_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Public permission check — allows both authenticated users and guests.
     */
    public function check_public_permission(): bool {
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Create a MemoryManager with its dependencies.
     */
    private function make_manager(): MemoryManager {
        $settings = new Settings();
        $factory  = new Factory( $settings );
        $store    = new MemoryStore();
        return new MemoryManager( $store, $factory );
    }

    /**
     * Load a conversation by UUID including its messages.
     *
     * @param  string $uuid
     * @return Conversation|null
     */
    private function load_conversation( string $uuid ): ?Conversation {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sathi_conversations WHERE uuid = %s",
            $uuid
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $conv = new Conversation( $row );

        // Load messages
        $msg_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT role, content, tool_calls, tool_result, token_count, metadata, created_at
            FROM {$wpdb->prefix}sathi_messages
            WHERE conversation_id = %d
            ORDER BY created_at ASC
            LIMIT %d",
            $conv->id,
            100
        ), ARRAY_A );

        foreach ( $msg_rows ?: [] as $mr ) {
            $conv->add_message( new \RaiLabs\Sathi\Core\Data\Message(
                $mr['role'],
                $mr['content'],
                $mr['tool_calls'] ? json_decode( $mr['tool_calls'], true ) : null,
                $mr['tool_result'] ? json_decode( $mr['tool_result'], true ) : null,
                $mr['token_count'] ? (int) $mr['token_count'] : null,
                $mr['metadata'] ? json_decode( $mr['metadata'], true ) : [],
                $mr['created_at']
            ) );
        }

        return $conv;
    }
}
