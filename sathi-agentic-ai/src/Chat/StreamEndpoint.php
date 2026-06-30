<?php
/**
 * SSE Streaming Endpoint — delivers real-time token streaming to the browser.
 *
 * Bypasses the WP REST API (which buffers all output) and uses template_redirect
 * to intercept a sathi-stream/* URL. Outputs raw text/event-stream.
 *
 * @package NeerMedia\Sathi\Chat
 */

namespace NeerMedia\Sathi\Chat;

use NeerMedia\Sathi\Core\Data\Conversation;
use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Core\Settings;
use NeerMedia\Sathi\Memory\MemoryStore;
use NeerMedia\Sathi\Providers\Factory;
use NeerMedia\Sathi\Personas\PromptComposer;
use NeerMedia\Sathi\Support\Helpers;

class StreamEndpoint {

    /** @var string Query var used for the stream endpoint */
    public const QUERY_VAR = 'sathi_stream';

    /** @var string Regex pattern for the rewrite rule (accepts UUIDs and "new") */
    private const REWRITE_RULE = '^sathi-stream/([a-zA-Z0-9\-]+)/?$';

    /**
     * Register rewrite rule and template_redirect hook.
     */
    public function register(): void {
        add_filter( 'query_vars', [ $this, 'add_query_var' ] );
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_action( 'template_redirect', [ $this, 'maybe_stream' ], 0 );
    }

    /**
     * Register the sathi_stream query variable.
     *
     * @param  string[] $vars
     * @return string[]
     */
    public function add_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Add the rewrite rule (flushed on activation).
     */
    public function add_rewrite_rule(): void {
        add_rewrite_rule( self::REWRITE_RULE, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
    }

    /**
     * Intercept requests matching the sathi-stream pattern and output SSE.
     */
    public function maybe_stream(): void {
        $conversation_uuid = get_query_var( self::QUERY_VAR );

        if ( empty( $conversation_uuid ) ) {
            return;
        }

        // Only accept POST
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            $this->respond_error( 'Method not allowed. Use POST.', 405 );
            return;
        }

        $this->handle_stream( $conversation_uuid );
    }

    /**
     * Handle the streaming session.
     *
     * @param string $conversation_uuid
     */
    private function handle_stream( string $conversation_uuid ): void {
        // ── Clean output buffers ──────────────────────────────────
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // ── SSE headers ──────────────────────────────────────────
        header( 'Content-Type: text/event-stream; charset=utf-8' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );
        header( 'Access-Control-Allow-Origin: ' . home_url() );
        header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );

        // Prevent WP from killing long requests
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'sathi_stream' );
        }
        @set_time_limit( 0 );

        // ── Read request body ─────────────────────────────────────
        $raw_body = file_get_contents( 'php://input' );
        $body     = json_decode( $raw_body, true );

        if ( ! $body || empty( $body['message'] ) ) {
            $this->emit( 'error', [ 'message' => 'Message is required.' ] );
            $this->done();
            return;
        }

        $message        = sanitize_textarea_field( $body['message'] );
        $persona        = sanitize_text_field( $body['persona'] ?? 'sathi-guru' );
        $guest_id       = sanitize_text_field( $body['guest_id'] ?? Helpers::guest_id() );
        $user_id        = get_current_user_id() ?: null;
        $available_tools = apply_filters( 'sathi_chat_tools', [], null );

        // ── License gating (no-op unless enforcement is enabled) ───
        if ( ! ( new \NeerMedia\Sathi\License\LicenseManager() )->is_active() ) {
            $this->emit( 'token', [ 'token' => __( 'Saathi AI is not activated yet. Please ask the site owner to activate the license.', 'sathi-agentic-ai' ) ] );
            $this->done();
            return;
        }

        $plugin   = \NeerMedia\Sathi\Core\Plugin::instance();
        $settings = $plugin->get( 'settings' ) ?: new Settings();
        $factory  = $plugin->get( 'factory' )  ?: new Factory( $settings );
        $memory   = $plugin->get( 'memory' )   ?: new MemoryStore();
        $chat     = $plugin->get( 'chat' )     ?: new ChatManager( $factory, $memory );

        $conv = null;
        if ( $conversation_uuid && $conversation_uuid !== 'new' ) {
            $conv = $chat->load_conversation( $conversation_uuid );
        }

        if ( ! $conv ) {
            $conv = $chat->start_conversation( $user_id, $guest_id, $persona );
        }

        // ── Build system prompt ───────────────────────────────────
        $composer = new PromptComposer();

        // ── Retrieve relevant site content (RAG) ──────────────────
        $knowledge_summary = '';
        try {
            $km   = new \NeerMedia\Sathi\Knowledge\KnowledgeManager();
            $hits = $km->hybridSearch( $message, 5 );
            $parts = [];
            foreach ( $hits as $h ) {
                $excerpt = trim( (string) ( $h['excerpt'] ?? '' ) );
                if ( $excerpt !== '' ) {
                    $src = $h['source_url'] ?? '';
                    $parts[] = '- ' . $excerpt . ( $src ? " (source: {$src})" : '' );
                }
            }
            $knowledge_summary = implode( "\n", $parts );
        } catch ( \Throwable $e ) {
            $knowledge_summary = '';
        }

        $system_prompt = $composer->compose( $persona, [
            'site_name'         => get_bloginfo( 'name' ),
            'site_description'  => get_bloginfo( 'description' ),
            'site_url'          => home_url(),
            'memory'            => $memory->summarize( $user_id, $guest_id ),
            'knowledge_summary' => $knowledge_summary,
        ] );

        // ── Persist user message ──────────────────────────────────
        global $wpdb;
        $user_msg = Message::user( $message );
        $wpdb->insert(
            $wpdb->prefix . 'sathi_messages',
            [
                'conversation_id' => $conv->id,
                'role'            => $user_msg->role,
                'content'         => $user_msg->content,
                'token_count'     => Helpers::estimate_tokens( $user_msg->content ),
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s' ]
        );
        $conv->add_message( $user_msg );

        // ── Content moderation (defense-in-depth, only when enabled) ──
        $moderator = $plugin->get( 'moderator' ) ?: new \NeerMedia\Sathi\Support\ContentModerator();
        $mod_result = $moderator->moderate_input( $message );
        if ( ! $mod_result['passed'] ) {
            $this->emit( 'error', [ 'message' => 'Your message contains content that cannot be processed.' ] );
            $this->done();
            return;
        }

        // ── Get provider ──────────────────────────────────────────
        $provider = $factory->for_task( 'chat' );

        // ── Stream tokens ─────────────────────────────────────────
        $streamed_content = '';
        $tool_calls = [];
        $error = null;

        try {
            $options = [
                'system_prompt' => $system_prompt,
                'tools'         => $available_tools,
                'max_tokens'    => (int) ( $body['max_tokens'] ?? 4096 ),
                'temperature'   => (float) ( $body['temperature'] ?? 0.7 ),
            ];

            if ( ! empty( $body['model'] ) ) {
                $options['model'] = sanitize_text_field( $body['model'] );
            }

            $response = $provider->chat_stream(
                $conv->messages,
                function ( string $delta ) use ( &$streamed_content ) {
                    $streamed_content .= $delta;
                    $this->emit( 'token', [ 'token' => $delta ] );
                },
                $options
            );

            $streamed_content = $response->content ?: $streamed_content;
            $tool_calls = $response->tool_calls ?? [];

            // ── Emit client actions ──────────────────────────────
            $actions = apply_filters( 'sathi_chat_actions', [], $streamed_content );
            if ( ! empty( $actions ) ) {
                $this->emit( 'actions', [ 'actions' => $actions ] );
            }

            // ── Persist assistant message ────────────────────────
            $wpdb->insert(
                $wpdb->prefix . 'sathi_messages',
                [
                    'conversation_id' => $conv->id,
                    'role'            => 'assistant',
                    'content'         => $streamed_content,
                    'tool_calls'      => $tool_calls ? wp_json_encode( $tool_calls ) : null,
                    'token_count'     => $response->token_count ?? Helpers::estimate_tokens( $streamed_content ),
                    'created_at'      => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%s' ]
            );

            // ── Update conversation ──────────────────────────────
            $wpdb->update(
                $wpdb->prefix . 'sathi_conversations',
                [
                    'message_count' => $conv->message_count + 2,
                    'updated_at'    => current_time( 'mysql' ),
                ],
                [ 'id' => $conv->id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            // ── Emit metadata ────────────────────────────────────
            $this->emit( 'metadata', [
                'conversation_id' => $conv->uuid,
                'tokens'          => $response->token_count ?? Helpers::estimate_tokens( $streamed_content ),
                'model'           => $options['model'] ?? $provider->default_model(),
            ] );

            // ── Emit matching WooCommerce product cards ──────────
            if ( $settings->get( Settings::KEY_PRODUCT_CARDS, true ) ) {
                try {
                    $ps = new \NeerMedia\Sathi\Commerce\ProductSearch();
                    if ( $ps->available() ) {
                        $products = $ps->search( $message, 3 );
                        if ( ! empty( $products ) ) {
                            $this->emit( 'products', [ 'products' => $products ] );
                        }
                    }
                } catch ( \Throwable $e ) {
                    // Non-fatal: product cards are an enhancement.
                }
            }

            // ── Auto-ingest memory ──────────────────────────────
            if ( $settings->get( Settings::KEY_MEMORY_ENABLED, true ) ) {
                $conv->add_message( $response );
                $memory->ingest_conversation( $conv );
            }

        } catch ( \Throwable $e ) {
            $error = $e->getMessage();
            $this->emit( 'error', [ 'message' => $error ] );
        }

        $this->done();
    }

    /**
     * Emit a single SSE event.
     *
     * @param string $type Event type.
     * @param array  $data Event payload.
     */
    private function emit( string $type, array $data ): void {
        $data['type'] = $type;
        echo "data: " . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n";
        if ( ob_get_level() > 0 ) {
            @ob_flush();
        }
        flush();
    }

    /**
     * Signal stream completion.
     */
    private function done(): void {
        echo "data: [DONE]\n\n";
        @ob_flush();
        flush();
        exit;
    }

    /**
     * Emit an error and exit.
     *
     * @param string $message
     * @param int    $status
     */
    private function respond_error( string $message, int $status = 400 ): void {
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        header( 'Content-Type: application/json' );
        http_response_code( $status );
        echo wp_json_encode( [ 'error' => $message ] );
        exit;
    }
}
