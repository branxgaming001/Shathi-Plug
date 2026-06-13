<?php
/**
 * Chat REST Controller — message send, streaming, conversation CRUD.
 *
 * @package RaiLabs\Sathi\Rest
 */

namespace RaiLabs\Sathi\Rest;

use RaiLabs\Sathi\Chat\ChatManager;
use RaiLabs\Sathi\Core\Settings;
use RaiLabs\Sathi\Memory\MemoryStore;
use RaiLabs\Sathi\Providers\Factory;
use RaiLabs\Sathi\Support\Helpers;
use WP_REST_Request;
use WP_REST_Response;

class ChatController {

    private const NAMESPACE = 'sathi/v1';

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        // Send a message (non-streaming)
        register_rest_route( self::NAMESPACE, '/chat/send', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'send_message' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
            'args'                => $this->send_message_args(),
        ] );

        // Start a new conversation
        register_rest_route( self::NAMESPACE, '/chat/conversations', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start_conversation' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );

        // Get conversation history
        register_rest_route( self::NAMESPACE, '/chat/conversations/(?P<uuid>[a-zA-Z0-9\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_conversation' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
            'args'                => [ 'uuid' => [ 'required' => true ] ],
        ] );

        // List user conversations
        register_rest_route( self::NAMESPACE, '/chat/conversations', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_conversations' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );

        // Delete a conversation
        register_rest_route( self::NAMESPACE, '/chat/conversations/(?P<uuid>[a-zA-Z0-9\-]+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_conversation' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );

        // Rename conversation (PATCH title)
        register_rest_route( self::NAMESPACE, '/chat/conversations/(?P<uuid>[a-zA-Z0-9\-]+)', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'rename_conversation' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );

        // Archive conversation
        register_rest_route( self::NAMESPACE, '/chat/conversations/(?P<uuid>[a-zA-Z0-9\-]+)/archive', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'archive_conversation' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );

        // Unarchive conversation
        register_rest_route( self::NAMESPACE, '/chat/conversations/(?P<uuid>[a-zA-Z0-9\-]+)/unarchive', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'unarchive_conversation' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );

        // Message feedback
        register_rest_route( self::NAMESPACE, '/chat/feedback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_feedback' ],
            'permission_callback' => [ $this, 'check_public_permission' ],
        ] );
    }

    /**
     * Send a chat message and get the assistant's response.
     */
    public function send_message( WP_REST_Request $request ): WP_REST_Response {
        $message        = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );
        $conversation_id = sanitize_text_field( $request->get_param( 'conversation_id' ) ?? '' );
        $persona        = sanitize_text_field( $request->get_param( 'persona' ) ?? 'sathi-guru' );
        $stream         = (bool) $request->get_param( 'stream' );
        $user_id        = get_current_user_id() ?: null;
        $guest_id       = sanitize_text_field( $request->get_param( 'guest_id' ) ?? Helpers::guest_id() );

        if ( empty( $message ) ) {
            return new WP_REST_Response( [
                'error'   => 'empty_message',
                'message' => __( 'Message cannot be empty.', 'sathi-agentic-ai' ),
            ], 400 );
        }

        // License gating (no-op unless enforcement is enabled).
        if ( ! ( new \RaiLabs\Sathi\License\LicenseManager() )->is_active() ) {
            return new WP_REST_Response( [
                'success' => true,
                'conversation_id' => '',
                'message' => [ 'role' => 'assistant', 'content' => __( 'Saathi AI is not activated yet. Please ask the site owner to activate the license.', 'sathi-agentic-ai' ) ],
            ] );
        }

        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );

        // Load or create conversation
        $conv = null;
        if ( $conversation_id ) {
            $conv = $chat->load_conversation( $conversation_id );
            if ( ! $conv ) {
                return new WP_REST_Response( [
                    'error'   => 'not_found',
                    'message' => __( 'Conversation not found.', 'sathi-agentic-ai' ),
                ], 404 );
            }
        }

        if ( ! $conv ) {
            $conv = $chat->start_conversation( $user_id, $guest_id, $persona );
        }

        // Get available tools
        $tools = apply_filters( 'sathi_chat_tools', [], $conv );

        // Always answer with a helpful message — never a bare 500. If the model
        // call fails (bad/expired model, no quota, network) we surface a clear,
        // actionable reason instead of a generic "something went wrong".
        try {
            $full_response = '';
            $response = $chat->send_message(
                $conv,
                $message,
                function ( string $delta ) use ( &$full_response ) { $full_response .= $delta; },
                [ 'tools' => $tools ]
            );
            $content = trim( (string) ( $full_response ?: $response->content ) );
            if ( $content === '' ) {
                $content = __( "I didn't get a reply from the AI model just now — it may be busy or unavailable. Please try again, or pick a different model in Saathi AI → AI Providers.", 'sathi-agentic-ai' );
            }

            return new WP_REST_Response( [
                'success'         => true,
                'conversation_id' => $conv->uuid,
                'message'         => [
                    'role'       => 'assistant',
                    'content'    => $content,
                    'tool_calls' => $response->tool_calls,
                    'tokens'     => $response->token_count,
                ],
                'actions'         => apply_filters( 'sathi_chat_actions', [], $content ),
            ] );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [
                'success'         => true,
                'conversation_id' => $conv->uuid,
                'message'         => [ 'role' => 'assistant', 'content' => $this->friendly_error( $e ) ],
            ] );
        }
    }

    /**
     * Turn a provider/runtime exception into a short, friendly, actionable
     * message the visitor (or admin) can understand.
     */
    private function friendly_error( \Throwable $e ): string {
        $code = (int) $e->getCode();
        $msg  = strtolower( $e->getMessage() );
        $has  = static function ( array $n ) use ( $msg ) { foreach ( $n as $x ) { if ( strpos( $msg, $x ) !== false ) { return true; } } return false; };

        if ( $code === 401 || $has( [ 'invalid api key', 'unauthorized', 'authentication', 'no auth' ] ) ) {
            return __( "⚠️ The AI provider rejected the API key. Please re-enter a valid key in Saathi AI → AI Providers and save again.", 'sathi-agentic-ai' );
        }
        if ( $code === 404 || $has( [ 'model not found', 'no such model', 'does not exist', 'not a valid model', 'invalid model' ] ) ) {
            return __( "⚠️ The selected AI model isn't available. Open Saathi AI → AI Providers, click the ⟳ button to load models, and pick a valid one.", 'sathi-agentic-ai' );
        }
        if ( $code === 429 || $has( [ 'rate limit', 'too many requests', 'quota', 'insufficient' ] ) ) {
            return __( "⚠️ The AI provider is rate-limited or out of quota right now. Please wait a moment and try again, or switch to another provider/model.", 'sathi-agentic-ai' );
        }
        if ( $code === 0 || $has( [ 'could not resolve', 'timed out', 'timeout', 'curl', 'connection', 'ssl' ] ) ) {
            return __( "⚠️ I couldn't reach the AI provider from the server. Please check the site's internet/firewall and try again.", 'sathi-agentic-ai' );
        }
        // Include the provider's own message — it usually says exactly what's wrong.
        $detail = trim( $e->getMessage() );
        return sprintf( __( "⚠️ I hit an error reaching the AI model: %s. Please try again, or check Saathi AI → AI Providers.", 'sathi-agentic-ai' ), $detail !== '' ? $detail : 'unknown error' );
    }

    /**
     * Start a new conversation.
     */
    public function start_conversation( WP_REST_Request $request ): WP_REST_Response {
        $persona  = sanitize_text_field( $request->get_param( 'persona' ) ?? 'sathi-guru' );
        $user_id  = get_current_user_id() ?: null;
        $guest_id = sanitize_text_field( $request->get_param( 'guest_id' ) ?? Helpers::guest_id() );

        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $conv     = $chat->start_conversation( $user_id, $guest_id, $persona );

        return new WP_REST_Response( [
            'success' => true,
            'conversation' => $conv->to_array(),
        ], 201 );
    }

    /**
     * Get conversation history.
     */
    public function get_conversation( WP_REST_Request $request ): WP_REST_Response {
        $uuid = $request->get_param( 'uuid' );

        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $conv     = $chat->load_conversation( $uuid );

        if ( ! $conv ) {
            return new WP_REST_Response( [
                'error' => 'not_found',
            ], 404 );
        }

        return new WP_REST_Response( [
            'conversation' => $conv->to_array(),
            'messages'     => array_map( fn( $m ) => $m->to_array(), $conv->messages ),
        ] );
    }

    /**
     * List recent conversations for the user.
     */
    public function list_conversations( WP_REST_Request $request ): WP_REST_Response {
        $user_id  = get_current_user_id() ?: null;
        $guest_id = sanitize_text_field( $request->get_param( 'guest_id' ) ?? Helpers::guest_id() );
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
        $page     = (int) ( $request->get_param( 'page' ) ?? 1 );
        $per_page = (int) ( $request->get_param( 'per_page' ) ?? 20 );
        $status   = sanitize_text_field( $request->get_param( 'status' ) ?? 'active' );

        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $result   = $chat->get_recent_conversations( $user_id, $guest_id, [
            'search'   => $search,
            'page'     => $page,
            'per_page' => $per_page,
            'status'   => $status,
        ] );

        return new WP_REST_Response( [
            'conversations' => array_map( fn( $c ) => $c->to_array(), $result['conversations'] ),
            'total'         => $result['total'],
            'page'          => $result['page'],
            'per_page'      => $result['per_page'],
            'pages'         => $result['pages'],
        ] );
    }

    /**
     * Rename a conversation.
     */
    public function rename_conversation( WP_REST_Request $request ): WP_REST_Response {
        $uuid  = $request->get_param( 'uuid' );
        $title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );

        if ( empty( $title ) ) {
            return new WP_REST_Response( [ 'error' => 'Title required' ], 400 );
        }

        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $updated  = $chat->rename_conversation( $uuid, $title );

        return new WP_REST_Response( [ 'success' => $updated ] );
    }

    /**
     * Archive a conversation.
     */
    public function archive_conversation( WP_REST_Request $request ): WP_REST_Response {
        $uuid = $request->get_param( 'uuid' );
        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $updated  = $chat->archive_conversation( $uuid, true );

        return new WP_REST_Response( [ 'success' => $updated ] );
    }

    /**
     * Unarchive a conversation.
     */
    public function unarchive_conversation( WP_REST_Request $request ): WP_REST_Response {
        $uuid = $request->get_param( 'uuid' );
        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $updated  = $chat->archive_conversation( $uuid, false );

        return new WP_REST_Response( [ 'success' => $updated ] );
    }

    /**
     * Save message feedback (thumbs up/down).
     */
    public function save_feedback( WP_REST_Request $request ): WP_REST_Response {
        $message_id      = sanitize_text_field( $request->get_param( 'message_id' ) ?? '' );
        $rating          = sanitize_text_field( $request->get_param( 'rating' ) ?? '' );
        $conversation_id = sanitize_text_field( $request->get_param( 'conversation_id' ) ?? '' );

        if ( ! $message_id || ! in_array( $rating, [ 'up', 'down' ] ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid parameters' ], 400 );
        }

        global $wpdb;
        // Store feedback by updating conversation metadata
        $conv = $wpdb->get_row( $wpdb->prepare(
            "SELECT metadata FROM {$wpdb->prefix}sathi_conversations WHERE uuid = %s",
            $conversation_id
        ) );

        if ( ! $conv ) {
            return new WP_REST_Response( [ 'error' => 'Conversation not found' ], 404 );
        }

        $metadata = $conv->metadata ? json_decode( $conv->metadata, true ) : [];
        if ( ! isset( $metadata['feedback'] ) ) {
            $metadata['feedback'] = [];
        }
        $metadata['feedback'][ $message_id ] = $rating;

        $wpdb->update(
            $wpdb->prefix . 'sathi_conversations',
            [ 'metadata' => wp_json_encode( $metadata ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'uuid' => $conversation_id ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        return new WP_REST_Response( [ 'success' => true ] );
    }

    /**
     * Delete (soft-delete) a conversation.
     */
    public function delete_conversation( WP_REST_Request $request ): WP_REST_Response {
        $uuid = $request->get_param( 'uuid' );

        $settings = new Settings();
        $factory  = new Factory( $settings );
        $memory   = new MemoryStore();
        $chat     = new ChatManager( $factory, $memory );
        $deleted  = $chat->delete_conversation( $uuid );

        return new WP_REST_Response( [
            'success' => $deleted,
        ], $deleted ? 200 : 404 );
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Public permission check — allows both authenticated users and guests.
     */
    public function check_public_permission(): bool {
        return true;
    }

    /**
     * Arguments schema for send_message.
     */
    private function send_message_args(): array {
        return [
            'message'         => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
            'conversation_id' => [ 'required' => false, 'type' => 'string' ],
            'persona'         => [ 'required' => false, 'type' => 'string', 'default' => 'sathi-guru' ],
            'guest_id'        => [ 'required' => false, 'type' => 'string' ],
            'stream'          => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
        ];
    }

}
