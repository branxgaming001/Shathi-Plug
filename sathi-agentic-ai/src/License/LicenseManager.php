<?php
/**
 * License Manager — verifies / activates / deactivates the plugin license
 * against the RAI license server, caches the result, and gates features.
 *
 * SAFETY: enforcement is OFF by default. With enforcement off, is_active()
 * always returns true so the plugin works normally. Turn enforcement on (via
 * the License tab, the SATHI_LICENSE_ENFORCE constant, or the
 * sathi_license_enforce filter) only after the license server is live.
 *
 * @package RaiLabs\Sathi\License
 */

namespace RaiLabs\Sathi\License;

use RaiLabs\Sathi\Core\Settings;
use RaiLabs\Sathi\Support\Helpers;

class LicenseManager {

    public const TRANSIENT = 'sathi_license_status';
    public const CRON_HOOK = 'sathi_license_check';
    public const PRODUCT   = 'sathi-agentic-ai';

    private Settings $settings;

    public function __construct( ?Settings $settings = null ) {
        $this->settings = $settings ?: new Settings();
    }

    /** Register the daily re-check cron + admin notice. */
    public function register(): void {
        add_action( self::CRON_HOOK, [ $this, 'cron_check' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
        add_action( 'admin_notices', [ $this, 'admin_notice' ] );
    }

    // ── Configuration ─────────────────────────────────────────────────

    public function server_url(): string {
        $url = (string) $this->settings->get( Settings::KEY_LICENSE_SERVER_URL, '' );
        if ( '' === $url && defined( 'SATHI_LICENSE_SERVER' ) ) {
            $url = (string) SATHI_LICENSE_SERVER;
        }
        return rtrim( (string) apply_filters( 'sathi_license_server_url', $url ), '/' );
    }

    public function get_key(): string {
        return Helpers::decrypt( (string) $this->settings->get( Settings::KEY_LICENSE_KEY, '' ) );
    }

    public function set_key( string $key ): void {
        $this->settings->set( Settings::KEY_LICENSE_KEY, Helpers::encrypt( trim( $key ) ) );
    }

    public function clear_key(): void {
        $this->settings->set( Settings::KEY_LICENSE_KEY, '' );
        delete_transient( self::TRANSIENT );
    }

    /** Site domain (host) used to bind the license. */
    public function domain(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        return $host ?: home_url();
    }

    /** Whether feature-gating is active. OFF by default. */
    public function enforcement_enabled(): bool {
        $on = (bool) $this->settings->get( Settings::KEY_LICENSE_ENFORCE, false );
        if ( defined( 'SATHI_LICENSE_ENFORCE' ) ) {
            $on = (bool) SATHI_LICENSE_ENFORCE;
        }
        return (bool) apply_filters( 'sathi_license_enforce', $on );
    }

    // ── Status / gating ───────────────────────────────────────────────

    /**
     * Get the cached (or freshly fetched) license status.
     *
     * @param bool $force Bypass the cache.
     * @return array{status:string, plan?:string, expires?:string, domains?:array, max_domains?:int, message?:string}
     */
    public function status( bool $force = false ): array {
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $status = $this->remote( 'verify' );
        set_transient( self::TRANSIENT, $status, DAY_IN_SECONDS );
        return $status;
    }

    /** True when the plugin's gated features may run. */
    public function is_active(): bool {
        if ( ! $this->enforcement_enabled() ) {
            return true; // Gating off — never block.
        }
        return ( $this->status()['status'] ?? '' ) === 'active';
    }

    public function activate( string $key ): array {
        $this->set_key( $key );
        $res = $this->remote( 'activate' );
        set_transient( self::TRANSIENT, $res, DAY_IN_SECONDS );
        return $res;
    }

    public function deactivate(): array {
        $res = $this->remote( 'deactivate' );
        $this->clear_key();
        return $res;
    }

    public function cron_check(): void {
        $this->status( true );
    }

    // ── Remote call ───────────────────────────────────────────────────

    private function remote( string $action ): array {
        $key = $this->get_key();
        $url = $this->server_url();

        if ( '' === $key ) {
            return [ 'status' => 'inactive', 'message' => __( 'No license key entered.', 'sathi-agentic-ai' ) ];
        }
        if ( '' === $url ) {
            return [ 'status' => 'unknown', 'message' => __( 'License server URL is not configured.', 'sathi-agentic-ai' ) ];
        }

        $resp = wp_remote_post( $url . '/api/license/' . $action, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'     => $key,
                'domain'  => $this->domain(),
                'product' => self::PRODUCT,
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'status' => 'error', 'message' => $resp->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        if ( $code >= 400 ) {
            return [ 'status' => $data['status'] ?? 'invalid', 'message' => $data['message'] ?? "HTTP {$code}" ];
        }

        return [
            'status'      => $data['status'] ?? 'invalid',
            'plan'        => $data['plan'] ?? '',
            'expires'     => $data['expires'] ?? '',
            'domains'     => $data['domains'] ?? [],
            'max_domains' => (int) ( $data['max_domains'] ?? 1 ),
            'message'     => $data['message'] ?? '',
            'checked_at'  => current_time( 'mysql' ),
        ];
    }

    // ── Admin notice ──────────────────────────────────────────────────

    public function admin_notice(): void {
        if ( ! $this->enforcement_enabled() || $this->is_active() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $purchase = $this->server_url() ?: 'https://railabs.in/sathi';
        printf(
            '<div class="notice notice-warning"><p><strong>Sathi AI</strong> — %s <a href="%s">%s</a> &middot; <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
            esc_html__( 'Activate your Sathi AI license to enable the chatbot.', 'sathi-agentic-ai' ),
            esc_url( admin_url( 'admin.php?page=sathi-dashboard#license' ) ),
            esc_html__( 'Enter license key', 'sathi-agentic-ai' ),
            esc_url( $purchase ),
            esc_html__( 'Get a license', 'sathi-agentic-ai' )
        );
    }
}
