<?php
/**
 * Chat Manager — conversation lifecycle, message routing, shortcode/block registration.
 *
 * @package RaiLabs\Sathi\Chat
 */

namespace RaiLabs\Sathi\Chat;

use RaiLabs\Sathi\Core\Data\Conversation;
use RaiLabs\Sathi\Core\Data\Message;
use RaiLabs\Sathi\Core\Settings;
use RaiLabs\Sathi\Memory\MemoryStore;
use RaiLabs\Sathi\Providers\Factory;
use RaiLabs\Sathi\Support\Helpers;

class ChatManager {

    private Factory $factory;
    private MemoryStore $memory;
    private Settings $settings;

    public function __construct( Factory $factory, MemoryStore $memory ) {
        $this->factory  = $factory;
        $this->memory   = $memory;
        $this->settings = new Settings();
    }

    /**
     * Register shortcodes, blocks, and hooks.
     */
    public function register(): void {
        add_shortcode( 'sathi_chat', [ $this, 'render_shortcode' ] );
        add_action( 'init', [ $this, 'register_block' ] );

        // Register SSE streaming endpoint
        $stream = new StreamEndpoint();
        $stream->register();
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_assets(): void {
        $widget_file = SATHI_PATH . 'assets/chat-widget.js';
        $css_file    = SATHI_PATH . 'assets/chat-widget.css';

        // Self-contained Vite/React ES module — loaded as type="module" via
        // Plugin::filter_module_script_tag(); no WP script dependencies needed.
        wp_register_script(
            'sathi-chat-widget',
            SATHI_ASSETS . 'chat-widget.js',
            [],
            file_exists( $widget_file ) ? filemtime( $widget_file ) : SATHI_VERSION,
            true
        );

        wp_register_style(
            'sathi-chat-widget',
            SATHI_ASSETS . 'chat-widget.css',
            [],
            file_exists( $css_file ) ? filemtime( $css_file ) : SATHI_VERSION
        );

        // Only load the floating widget where placement rules allow it.
        if ( $this->should_display_widget() ) {
            wp_enqueue_script( 'sathi-chat-widget' );
            wp_enqueue_style( 'sathi-chat-widget' );

            // Localize settings for the React widget
            wp_localize_script( 'sathi-chat-widget', 'sathiConfig', $this->get_widget_config() );

            add_action( 'wp_footer', [ $this, 'render_widget_mount' ] );
        }
    }

    /**
     * Decide whether the floating chat widget should render on the current view.
     *
     * Honors the master enable toggle, logged-in-only gate, post-type targeting,
     * and the include/exclude page placement rules configured in the admin.
     *
     * @return bool
     */
    public function should_display_widget(): bool {
        // Master enable toggle.
        if ( ! $this->settings->get( Settings::KEY_FLOATING_WIDGET, true ) ) {
            return false;
        }

        // License gating (no-op unless enforcement is enabled in the License tab).
        if ( ! ( new \RaiLabs\Sathi\License\LicenseManager( $this->settings ) )->is_active() ) {
            return false;
        }

        // Never on admin screens or feeds.
        if ( is_admin() || is_feed() ) {
            return false;
        }

        // Logged-in-only gate.
        if ( $this->settings->get( Settings::KEY_WIDGET_LOGGED_IN_ONLY, false ) && ! is_user_logged_in() ) {
            return false;
        }

        // Post-type targeting (only constrains singular views).
        $post_types = (array) $this->settings->get( Settings::KEY_WIDGET_POST_TYPES, [] );
        if ( ! empty( $post_types ) && is_singular() ) {
            $current_pt = get_post_type();
            if ( $current_pt && ! in_array( $current_pt, $post_types, true ) ) {
                return false;
            }
        }

        $mode  = $this->settings->get( Settings::KEY_WIDGET_DISPLAY_MODE, 'all' );
        $pages = array_map( 'absint', (array) $this->settings->get( Settings::KEY_WIDGET_DISPLAY_PAGES, [] ) );
        $current_id = is_singular() ? (int) get_queried_object_id() : 0;

        if ( 'include' === $mode ) {
            $show = $current_id > 0 && in_array( $current_id, $pages, true );
        } elseif ( 'exclude' === $mode ) {
            $show = ! ( $current_id > 0 && in_array( $current_id, $pages, true ) );
        } else {
            $show = true;
        }

        /**
         * Filter the final decision on whether to render the chat widget.
         *
         * @param bool $show Whether to display the widget.
         */
        return (bool) apply_filters( 'sathi_should_display_widget', $show );
    }

    /**
     * Build the configuration object for the React widget.
     */
    public function get_widget_config(): array {
        $persona = $this->settings->get_persona();
        $accent  = $this->settings->get( Settings::KEY_ACCENT_COLOR, '#6D5DFB' );

        return [
            'restUrl'           => rest_url( 'sathi/v1' ),
            'streamUrl'         => home_url( '/sathi-stream/' ),
            'nonce'             => wp_create_nonce( 'wp_rest' ),
            'siteName'          => get_bloginfo( 'name' ),
            'siteDescription'   => get_bloginfo( 'description' ),
            'position'          => $this->settings->get( Settings::KEY_FLOATING_POSITION, 'bottom-right' ),
            'accentColor'       => $this->settings->get( Settings::KEY_ACCENT_COLOR, '#6D5DFB' ),
            'greeting'          => $this->settings->get( Settings::KEY_CHAT_GREETING, '' ),
            'title'             => $this->settings->get( Settings::KEY_WIDGET_TITLE, '' ) ?: $persona['name'],
            'theme'             => $this->settings->get( Settings::KEY_WIDGET_THEME, 'light' ),
            'launcherIcon'      => $this->settings->get( Settings::KEY_WIDGET_LAUNCHER_ICON, 'chat' ),
            'avatar'            => $this->resolve_avatar(),
            'avatarFrames'      => $this->resolve_avatar_frames(),
            'autoOpen'          => (bool) $this->settings->get( Settings::KEY_WIDGET_AUTO_OPEN, false ),
            'autoOpenDelay'     => (int) $this->settings->get( Settings::KEY_WIDGET_AUTO_OPEN_DELAY, 5 ),
            'persona'           => [ 'name' => $persona['name'], 'color' => $accent ],
            'streamingEnabled'  => $this->settings->get( Settings::KEY_STREAMING_ENABLED, true ),
            'memoryEnabled'     => $this->settings->get( Settings::KEY_MEMORY_ENABLED, true ),
            'guestId'           => Helpers::guest_id(),
            'i18n'              => [
                'title'         => __( 'Sathi Support', 'sathi-agentic-ai' ),
                'placeholder'   => __( 'Type your message…', 'sathi-agentic-ai' ),
                'send'          => __( 'Send', 'sathi-agentic-ai' ),
                'clear'         => __( 'Clear chat', 'sathi-agentic-ai' ),
                'thinking'      => __( 'Sathi is thinking…', 'sathi-agentic-ai' ),
                'error'         => __( 'Something went wrong. Please try again.', 'sathi-agentic-ai' ),
                'newChat'       => __( 'New Chat', 'sathi-agentic-ai' ),
                'copy'          => __( 'Copy', 'sathi-agentic-ai' ),
                'copied'        => __( 'Copied!', 'sathi-agentic-ai' ),
            ],
        ];
    }

    /**
     * Resolve the selected mascot avatar to a data URI (empty for spark/none).
     */
    private function resolve_avatar(): string {
        $id = (string) $this->settings->get( Settings::KEY_WIDGET_AVATAR, 'mascot-1' );
        if ( $id === 'custom' ) {
            return $this->settings->get_custom_avatar();
        }
        if ( strpos( $id, 'mascot-' ) === 0 ) {
            return \RaiLabs\Sathi\Support\Mascots::get( $id );
        }
        return '';
    }

    /**
     * All expression frames for the selected avatar so the widget can animate
     * it (neutral → laughing → …). Single-element or empty arrays are fine.
     *
     * @return string[]
     */
    private function resolve_avatar_frames(): array {
        $id = (string) $this->settings->get( Settings::KEY_WIDGET_AVATAR, 'mascot-1' );
        if ( $id === 'custom' ) {
            $c = $this->settings->get_custom_avatar();
            return $c !== '' ? [ $c ] : [];
        }
        if ( strpos( $id, 'mascot-' ) === 0 ) {
            return \RaiLabs\Sathi\Support\Mascots::frames_for( $id );
        }
        return [];
    }

    /**
     * Render the floating widget mount point in the footer.
     */
    public function render_widget_mount(): void {
        echo '<div id="sathi-chat-root" aria-label="' . esc_attr__( 'Sathi AI Chat', 'sathi-agentic-ai' ) . '"></div>';
    }

    /**
     * Render the [sathi_chat] shortcode.
     *
     * @param  array  $atts Shortcode attributes.
     * @return string HTML mount point.
     */
    public function render_shortcode( array $atts = [] ): string {
        $atts = shortcode_atts( [
            'persona'  => $this->settings->get_default_persona(),
            'position' => 'embedded',
            'width'    => '100%',
            'height'   => '600px',
        ], $atts, 'sathi_chat' );

        // Ensure assets are loaded for embedded mode
        wp_enqueue_script( 'sathi-chat-widget' );
        wp_enqueue_style( 'sathi-chat-widget' );

        return sprintf(
            '<div class="sathi-chat-embedded" data-persona="%s" data-position="%s" style="width:%s;height:%s"></div>',
            esc_attr( $atts['persona'] ),
            esc_attr( $atts['position'] ),
            esc_attr( $atts['width'] ),
            esc_attr( $atts['height'] )
        );
    }

    /**
     * Register the Gutenberg block.
     */
    public function register_block(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        register_block_type( 'sathi/chat', [
            'editor_script'   => 'sathi-chat-block',
            'render_callback' => [ $this, 'render_shortcode' ],
            'attributes'      => [
                'persona'  => [ 'type' => 'string', 'default' => 'sathi-guru' ],
                'position' => [ 'type' => 'string', 'default' => 'embedded' ],
                'width'    => [ 'type' => 'string', 'default' => '100%' ],
                'height'   => [ 'type' => 'string', 'default' => '600px' ],
            ],
        ] );
    }

    /**
     * Start a new conversation.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  string      $persona_id
     * @param  string      $provider
     * @return Conversation
     */
    public function start_conversation(
        ?int $user_id = null,
        ?string $guest_id = null,
        string $persona_id = 'sathi-guru',
        string $provider = 'openai'
    ): Conversation {
        global $wpdb;

        $conv = new Conversation( [
            'uuid'        => Helpers::uuid(),
            'user_id'     => $user_id,
            'guest_id'    => $guest_id ?? Helpers::guest_id(),
            'persona_id'  => $persona_id,
            'provider'    => $provider,
            'status'      => 'active',
        ] );

        $wpdb->insert(
            $wpdb->prefix . 'sathi_conversations',
            [
                'uuid'        => $conv->uuid,
                'user_id'     => $conv->user_id,
                'guest_id'    => $conv->guest_id,
                'persona_id'  => $conv->persona_id,
                'provider'    => $conv->provider,
                'status'      => $conv->status,
                'created_at'  => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $conv->id = (int) $wpdb->insert_id;

        return $conv;
    }

    /**
     * Load a conversation by UUID.
     *
     * @param  string $uuid
     * @return Conversation|null
     */
    public function load_conversation( string $uuid ): ?Conversation {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sathi_conversations WHERE uuid = %s AND status != 'deleted'",
                $uuid
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $conv = new Conversation( $row );

        // Load messages
        $messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sathi_messages WHERE conversation_id = %d ORDER BY created_at ASC",
                $conv->id
            ),
            ARRAY_A
        );

        foreach ( $messages as $msg_row ) {
            $conv->add_message( new Message(
                $msg_row['role'],
                $msg_row['content'],
                $msg_row['tool_calls'] ? json_decode( $msg_row['tool_calls'], true ) : null,
                $msg_row['tool_result'] ? json_decode( $msg_row['tool_result'], true ) : null,
                (int) $msg_row['token_count'],
                $msg_row['metadata'] ? json_decode( $msg_row['metadata'], true ) : [],
                $msg_row['created_at']
            ) );
        }

        return $conv;
    }

    /**
     * Send a message and get the streaming response.
     *
     * @param  Conversation   $conv
     * @param  string         $user_input
     * @param  callable       $on_token Called with each token as it arrives.
     * @param  array          $options
     * @return Message The final assistant response.
     */
    public function send_message( Conversation $conv, string $user_input, callable $on_token, array $options = [] ): Message {
        global $wpdb;

        // Persist user message
        $user_msg = Message::user( $user_input );
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

        // Get provider and build the full system prompt (persona + memory + RAG + safety)
        $provider       = $this->factory->for_task( 'chat' );
        $persona_system = $this->build_system_prompt( $conv, $user_input );

        $messages = $conv->messages;

        // Get optional function tools
        $tools = apply_filters( 'sathi_chat_tools', [], $conv );

        // Send to provider — streaming
        $response = $provider->chat_stream(
            $messages,
            $on_token,
            array_merge( $options, [
                'system_prompt' => $persona_system,
                'tools'         => $tools,
            ] )
        );

        // Persist assistant response
        $wpdb->insert(
            $wpdb->prefix . 'sathi_messages',
            [
                'conversation_id' => $conv->id,
                'role'            => 'assistant',
                'content'         => $response->content,
                'tool_calls'      => $response->tool_calls ? wp_json_encode( $response->tool_calls ) : null,
                'token_count'     => $response->token_count,
                'created_at'      => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        // Update conversation
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

        // Auto-save memory
        if ( $this->settings->get( Settings::KEY_MEMORY_ENABLED, true ) ) {
            $this->memory->ingest_conversation( $conv );
        }

        return $response;
    }

    /**
     * Build the full system prompt: user-defined persona + memory + retrieved
     * site knowledge (RAG) + scope + safety. Delegates to PromptComposer so the
     * widget, REST fallback, and SSE stream all speak with one voice.
     *
     * @param Conversation $conv
     * @param string       $user_input The latest user message (drives RAG retrieval).
     */
    private function build_system_prompt( Conversation $conv, string $user_input = '' ): string {
        $context = [
            'site_name'        => get_bloginfo( 'name' ),
            'site_description' => get_bloginfo( 'description' ),
            'site_url'         => home_url(),
        ];

        // Memory of this visitor.
        if ( $this->settings->get( Settings::KEY_MEMORY_ENABLED, true ) ) {
            $context['memory'] = $this->memory->summarize( $conv->user_id, $conv->guest_id );
        }

        // Retrieve relevant site content for grounding (RAG).
        if ( $user_input !== '' ) {
            try {
                $km    = new \RaiLabs\Sathi\Knowledge\KnowledgeManager();
                $hits  = $km->hybridSearch( $user_input, 5 );
                $parts = [];
                foreach ( $hits as $h ) {
                    $excerpt = trim( (string) ( $h['excerpt'] ?? '' ) );
                    if ( $excerpt !== '' ) {
                        $src     = $h['source_url'] ?? '';
                        $parts[] = '- ' . $excerpt . ( $src ? " (source: {$src})" : '' );
                    }
                }
                if ( $parts ) {
                    $context['knowledge_summary'] = implode( "\n", $parts );
                }
            } catch ( \Throwable $e ) {
                // Knowledge base optional — ignore retrieval failures.
            }
        }

        $prompt = ( new \RaiLabs\Sathi\Personas\PromptComposer() )->compose( '', $context );

        return apply_filters( 'sathi_system_prompt', $prompt, $conv );
    }

    /**
     * Get user's recent conversations with optional search, pagination, and status filter.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  array       $args { search, page, per_page, status }
     * @return array{conversations: Conversation[], total: int, page: int, per_page: int}
     */
    public function get_recent_conversations( ?int $user_id, ?string $guest_id, array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'search'   => '',
            'page'     => 1,
            'per_page' => 20,
            'status'   => 'active',
        ];
        $args = wp_parse_args( $args, $defaults );
        $args['page']     = max( 1, (int) $args['page'] );
        $args['per_page'] = min( 100, max( 1, (int) $args['per_page'] ) );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $where  = 'c.status = %s AND ';
        $params = [ $args['status'] ];

        if ( $user_id ) {
            $where  .= 'c.user_id = %d AND ';
            $params[] = $user_id;
        } elseif ( $guest_id ) {
            $where  .= 'c.guest_id = %s AND ';
            $params[] = $guest_id;
        } else {
            $where  .= '1=0 AND ';
        }

        // Search across title and message content
        if ( ! empty( $args['search'] ) ) {
            $where .= '(c.title LIKE %s OR EXISTS (SELECT 1 FROM ' . $wpdb->prefix . 'sathi_messages m WHERE m.conversation_id = c.id AND m.content LIKE %s LIMIT 1)) AND ';
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where = rtrim( $where, ' AND ' );

        // Get total count
        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sathi_conversations c WHERE {$where}", $params )
        );

        // Get rows
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.* FROM {$wpdb->prefix}sathi_conversations c WHERE {$where} ORDER BY c.updated_at DESC LIMIT %d OFFSET %d",
                array_merge( $params, [ $args['per_page'], $offset ] )
            ),
            ARRAY_A
        );

        return [
            'conversations' => array_map( fn( $r ) => new Conversation( $r ), $rows ?: [] ),
            'total'         => $total,
            'page'          => $args['page'],
            'per_page'      => $args['per_page'],
            'pages'         => (int) ceil( $total / $args['per_page'] ),
        ];
    }

    /**
     * Auto-generate a conversation title from the first user message.
     *
     * Uses a fast/cheap model call to summarize in 5 words.
     *
     * @param Conversation $conv
     */
    public function auto_title( Conversation $conv ): void {
        if ( $conv->title ) {
            return; // Already titled
        }

        // Only auto-title after the first exchange
        if ( $conv->message_count < 2 ) {
            return;
        }

        global $wpdb;

        // Get first user message
        $first_msg = $wpdb->get_var( $wpdb->prepare(
            "SELECT content FROM {$wpdb->prefix}sathi_messages WHERE conversation_id = %d AND role = 'user' ORDER BY created_at ASC LIMIT 1",
            $conv->id
        ) );

        if ( ! $first_msg ) {
            return;
        }

        // Fallback: use first 60 characters
        $title = \RaiLabs\Sathi\Support\Helpers::clean_text( $first_msg, 60 );

        // Try LLM-based titling (best-effort)
        try {
            $provider = $this->factory->make( $conv->provider );
            if ( $provider ) {
                $titling_msg = \RaiLabs\Sathi\Core\Data\Message::user(
                    sprintf( 'Generate a 5-word title for this user message: "%s". Reply with ONLY the title, no quotes.', $title )
                );
                $response = $provider->chat( [ $titling_msg ], [
                    'max_tokens'  => 20,
                    'temperature' => 0.3,
                ] );
                if ( $response && ! empty( $response->content ) ) {
                    $title = \RaiLabs\Sathi\Support\Helpers::clean_text( $response->content, 100 );
                }
            }
        } catch ( \Throwable $e ) {
            // Fallback title is fine
        }

        $wpdb->update(
            $wpdb->prefix . 'sathi_conversations',
            [ 'title' => $title, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $conv->id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        $conv->title = $title;
    }

    /**
     * Rename a conversation.
     *
     * @param string $uuid
     * @param string $new_title
     * @return bool
     */
    public function rename_conversation( string $uuid, string $new_title ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'sathi_conversations',
            [ 'title' => sanitize_text_field( $new_title ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'uuid' => $uuid ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }

    /**
     * Archive or unarchive a conversation.
     *
     * @param string $uuid
     * @param bool   $archive
     * @return bool
     */
    public function archive_conversation( string $uuid, bool $archive = true ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'sathi_conversations',
            [
                'status'     => $archive ? 'archived' : 'active',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'uuid' => $uuid ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }

    /**
     * Delete a conversation (soft delete).
     */
    public function delete_conversation( string $uuid ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'sathi_conversations',
            [ 'status' => 'deleted', 'updated_at' => current_time( 'mysql' ) ],
            [ 'uuid' => $uuid ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }
}
