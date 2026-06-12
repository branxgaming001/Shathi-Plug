<?php
/**
 * GDPR & Privacy Manager — consent, data export, data erasure.
 *
 * @package RaiLabs\Sathi\Support
 */

namespace RaiLabs\Sathi\Support;

class GDPRManager {

    /** @var string Cookie name for chat consent */
    public const CONSENT_COOKIE = 'sathi_chat_consent';

    /** @var int Consent cookie duration (30 days) */
    private const CONSENT_DAYS = 30;

    /**
     * Register hooks.
     */
    public function register(): void {
        add_action( 'wp_footer', [ $this, 'render_consent_banner' ] );
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
        add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );
    }

    /**
     * Check if the current user/guest has given consent.
     */
    public function has_consent(): bool {
        if ( is_user_logged_in() ) {
            return (bool) get_user_meta( get_current_user_id(), '_sathi_consent', true );
        }
        return isset( $_COOKIE[ self::CONSENT_COOKIE ] ) && $_COOKIE[ self::CONSENT_COOKIE ] === '1';
    }

    /**
     * Record consent.
     *
     * @param int|null    $user_id
     * @param string|null $guest_id
     */
    public function give_consent( ?int $user_id = null ): void {
        if ( $user_id ) {
            update_user_meta( $user_id, '_sathi_consent', 1 );
            update_user_meta( $user_id, '_sathi_consent_date', current_time( 'mysql' ) );
        }
        setcookie( self::CONSENT_COOKIE, '1', time() + ( DAY_IN_SECONDS * self::CONSENT_DAYS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
    }

    /**
     * Revoke consent and optionally delete all user data.
     *
     * @param int|null    $user_id
     * @param string|null $guest_id
     * @param bool        $delete_data Also delete stored data.
     */
    public function revoke_consent( ?int $user_id = null, ?string $guest_id = null, bool $delete_data = false ): void {
        if ( $user_id ) {
            delete_user_meta( $user_id, '_sathi_consent' );
        }
        setcookie( self::CONSENT_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

        if ( $delete_data ) {
            $this->delete_user_data( $user_id, $guest_id );
        }
    }

    /**
     * Delete all Sathi data for a user/guest (GDPR right to erasure).
     */
    public function delete_user_data( ?int $user_id = null, ?string $guest_id = null ): int {
        global $wpdb;
        $deleted = 0;

        if ( $user_id ) {
            // Find conversations
            $conv_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sathi_conversations WHERE user_id = %d",
                $user_id
            ) );

            if ( $conv_ids ) {
                $ids_placeholder = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}sathi_messages WHERE conversation_id IN ($ids_placeholder)",
                    ...$conv_ids
                ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}sathi_conversations WHERE user_id = %d",
                    $user_id
                ) );
                $deleted += count( $conv_ids );
            }

            $wpdb->delete( $wpdb->prefix . 'sathi_memory_entries', [ 'user_id' => $user_id ] );
            $wpdb->delete( $wpdb->prefix . 'sathi_usage', [ 'user_id' => $user_id ] );
        }

        if ( $guest_id ) {
            $conv_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sathi_conversations WHERE guest_id = %s",
                $guest_id
            ) );

            if ( $conv_ids ) {
                $ids_placeholder = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}sathi_messages WHERE conversation_id IN ($ids_placeholder)",
                    ...$conv_ids
                ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}sathi_conversations WHERE guest_id = %s",
                    $guest_id
                ) );
                $deleted += count( $conv_ids );
            }

            $wpdb->delete( $wpdb->prefix . 'sathi_memory_entries', [ 'guest_id' => $guest_id ] );
        }

        return $deleted;
    }

    /**
     * Export all Sathi data for a user (GDPR right to access).
     *
     * @param int $user_id
     * @return array
     */
    public function export_user_data( int $user_id ): array {
        global $wpdb;

        $data = [
            'conversations' => [],
            'memory'        => [],
        ];

        // Conversations with messages
        $convs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sathi_conversations WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A );

        foreach ( $convs ?: [] as $conv ) {
            $messages = $wpdb->get_results( $wpdb->prepare(
                "SELECT role, content, created_at FROM {$wpdb->prefix}sathi_messages WHERE conversation_id = %d ORDER BY created_at ASC",
                $conv['id']
            ), ARRAY_A );

            $data['conversations'][] = [
                'title'    => $conv['title'],
                'date'     => $conv['created_at'],
                'messages' => $messages ?: [],
            ];
        }

        // Memory entries
        $data['memory'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT key_slug, value, created_at FROM {$wpdb->prefix}sathi_memory_entries WHERE user_id = %d",
            $user_id
        ), ARRAY_A ) ?: [];

        return $data;
    }

    /**
     * Register the personal data exporter with WordPress core.
     */
    public function register_exporter( array $exporters ): array {
        $exporters['sathi-agentic-ai'] = [
            'exporter_friendly_name' => __( 'Sathi Agentic AI', 'sathi-agentic-ai' ),
            'callback'               => function ( string $email_address, int $page = 1 ) {
                $user = get_user_by( 'email', $email_address );
                if ( ! $user ) {
                    return [ 'data' => [], 'done' => true ];
                }

                $data   = $this->export_user_data( $user->ID );
                $export = [];

                foreach ( $data['conversations'] as $conv ) {
                    $export[] = [
                        'name'  => __( 'Chat Conversation', 'sathi-agentic-ai' ),
                        'data'  => [
                            [ 'name' => __( 'Title', 'sathi-agentic-ai' ), 'value' => $conv['title'] ?? __( 'Untitled', 'sathi-agentic-ai' ) ],
                            [ 'name' => __( 'Date', 'sathi-agentic-ai' ), 'value' => $conv['date'] ],
                            [ 'name' => __( 'Messages', 'sathi-agentic-ai' ), 'value' => wp_json_encode( $conv['messages'] ) ],
                        ],
                    ];
                }

                foreach ( $data['memory'] as $mem ) {
                    $export[] = [
                        'name' => __( 'Stored Memory', 'sathi-agentic-ai' ),
                        'data' => [
                            [ 'name' => $mem['key_slug'], 'value' => $mem['value'] ],
                        ],
                    ];
                }

                return [ 'data' => $export, 'done' => true ];
            },
        ];

        return $exporters;
    }

    /**
     * Register the personal data eraser with WordPress core.
     */
    public function register_eraser( array $erasers ): array {
        $erasers['sathi-agentic-ai'] = [
            'eraser_friendly_name' => __( 'Sathi Agentic AI', 'sathi-agentic-ai' ),
            'callback'             => function ( string $email_address, int $page = 1 ) {
                $user = get_user_by( 'email', $email_address );
                if ( ! $user ) {
                    return [ 'items_removed' => 0, 'items_retained' => 0, 'done' => true ];
                }

                $deleted = $this->delete_user_data( $user->ID, null );
                return [
                    'items_removed'  => $deleted,
                    'items_retained' => 0,
                    'messages'       => [ sprintf( __( '%d Sathi AI records deleted.', 'sathi-agentic-ai' ), $deleted ) ],
                    'done'           => true,
                ];
            },
        ];

        return $erasers;
    }

    /**
     * Add privacy policy content suggestion.
     */
    public function add_privacy_policy_content(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        wp_add_privacy_policy_content(
            __( 'Sathi Agentic AI', 'sathi-agentic-ai' ),
            __( 'When you use the Sathi AI chat widget, we process your messages through third-party AI providers (OpenAI, Anthropic, Google, etc.) to generate responses. Your chat messages and preferences may be stored on our servers to provide context-aware assistance across sessions. You can request deletion of your chat history at any time through your account privacy settings or by contacting us.', 'sathi-agentic-ai' )
        );
    }

    /**
     * Render the consent banner inline.
     */
    public function render_consent_banner(): void {
        if ( $this->has_consent() ) {
            return;
        }

        $consent_required = apply_filters( 'sathi_require_consent', false );
        if ( ! $consent_required ) {
            return;
        }

        ?>
        <div id="sathi-consent-banner" style="position:fixed;bottom:0;left:0;right:0;background:#1e1e2e;color:#cdd6f4;padding:16px 24px;z-index:9998;display:flex;align-items:center;justify-content:space-between;gap:16px;font-family:Inter,system-ui,sans-serif;font-size:13px;box-shadow:0 -4px 16px rgba(0,0,0,0.2);">
            <div>
                <strong><?php esc_html_e( 'AI Chat Privacy Notice', 'sathi-agentic-ai' ); ?></strong>
                <p style="margin:4px 0 0;opacity:0.8;">
                    <?php esc_html_e( 'This site uses AI-powered chat that processes your messages through third-party AI services. Your conversations may be stored to provide better assistance. By continuing, you consent to this processing.', 'sathi-agentic-ai' ); ?>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <button onclick="sathiAcceptConsent()" style="background:#7c3aed;color:white;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-weight:500;">
                    <?php esc_html_e( 'Accept', 'sathi-agentic-ai' ); ?>
                </button>
                <button onclick="sathiDeclineConsent()" style="background:transparent;color:#cdd6f4;border:1px solid #45475a;padding:8px 16px;border-radius:8px;cursor:pointer;">
                    <?php esc_html_e( 'Decline', 'sathi-agentic-ai' ); ?>
                </button>
            </div>
        </div>
        <script>
        function sathiAcceptConsent() {
            fetch('<?php echo esc_url( rest_url( 'sathi/v1/gdpr/consent' ) ); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
            }).then(() => {
                document.getElementById('sathi-consent-banner').remove();
                location.reload();
            });
        }
        function sathiDeclineConsent() {
            document.cookie = '<?php echo esc_js( self::CONSENT_COOKIE ); ?>=0;path=/;max-age=86400';
            document.getElementById('sathi-consent-banner').remove();
        }
        </script>
        <?php
    }
}
