<?php
/**
 * License Manager — verifies / activates / deactivates the plugin license
 * against the RAI Labs license server, cryptographically VERIFIES every
 * response (RS256, embedded public key), caches the signed entitlement, and
 * gates premium features.
 *
 * Why signing: a nulled copy or a DNS-redirected fake server cannot forge a
 * "valid" response without the private key (which never leaves our server).
 * Premium grants (system directive) only ever come from a genuinely signed
 * server response, so a cracked plugin cannot unlock paid behaviour.
 *
 * SAFETY: enforcement is OFF by default. With enforcement off, is_active()
 * returns true so the plugin runs normally. Turn it on via the License tab,
 * the SATHI_LICENSE_ENFORCE constant, or the sathi_license_enforce filter.
 *
 * @package RaiLabs\Sathi\License
 */

namespace RaiLabs\Sathi\License;

use RaiLabs\Sathi\Core\Settings;
use RaiLabs\Sathi\Support\Helpers;

class LicenseManager {

    public const TRANSIENT    = 'sathi_license_status';
    public const LASTGOOD_OPT = 'sathi_license_lastgood';
    public const PREMIUM_TRANSIENT = 'sathi_premium_grant';
    public const CRON_HOOK    = 'sathi_license_check';
    public const PRODUCT      = 'sathi-agentic-ai';
    public const DEFAULT_SERVER = 'https://saathi.railabs.in';
    /** Offline grace: keep last verified entitlement working if the server is unreachable. */
    public const GRACE_SECONDS = 14 * DAY_IN_SECONDS;

    /** RS256 public key — pairs with the private key held only on the license server. */
    private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqHHkVBh6xpnXNR8DlckG
cZ80HIZo7VMJnLmXWKGvpzZEYarGzj9ZFbteoYh0gaXs987yaUXV8fHO6hzqHrmn
2s4z+oQ6pSsfdh5tY1jxwRK0sB6j41SBJSV5hII+tEmQDifNZ+2DaXkSYr/uT/lX
aAZfaw6wvhXwg+flm3r4b7yyrFd+/34ZhunaHDqgQdTX7ZaGi1yCqXvSMm1QJp2A
RE0oRYTUtsXzU1iEcajugpmQymXQOVq+tpM+XdQdnQaUCP+ffaFHsXDlA7giDUBc
PN+RxQBKPdKgODsyVREn7SiIsmJB/PUYJNB3+WNlWW7mHq5Q8OIPyEgYFviTEp9T
RQIDAQAB
-----END PUBLIC KEY-----
PEM;

    private Settings $settings;

    public function __construct( ?Settings $settings = null ) {
        $this->settings = $settings ?: new Settings();
    }

    public function register(): void {
        add_action( self::CRON_HOOK, [ $this, 'cron_check' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
        add_action( 'admin_notices', [ $this, 'admin_notice' ] );
        // Premium value lives server-side: the signed directive is appended to the system prompt.
        add_filter( 'sathi_system_prompt', [ $this, 'inject_premium_directive' ], 20 );
    }

    // ── Configuration ─────────────────────────────────────────────────

    public function server_url(): string {
        $url = (string) $this->settings->get( Settings::KEY_LICENSE_SERVER_URL, '' );
        if ( '' === $url && defined( 'SATHI_LICENSE_SERVER' ) ) {
            $url = (string) SATHI_LICENSE_SERVER;
        }
        if ( '' === $url ) {
            $url = self::DEFAULT_SERVER;
        }
        return rtrim( (string) apply_filters( 'sathi_license_server_url', $url ), '/' );
    }

    /** Full endpoint. Accepts either a base URL or a direct .../api/license.php. */
    private function endpoint(): string {
        $url = $this->server_url();
        if ( '' === $url ) {
            return '';
        }
        if ( false !== stripos( $url, 'license.php' ) ) {
            return $url;
        }
        return $url . '/api/license.php';
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
        delete_transient( self::PREMIUM_TRANSIENT );
        delete_option( self::LASTGOOD_OPT );
    }

    public function domain(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        return $host ? strtolower( $host ) : home_url();
    }

    public function enforcement_enabled(): bool {
        $on = (bool) $this->settings->get( Settings::KEY_LICENSE_ENFORCE, false );
        if ( defined( 'SATHI_LICENSE_ENFORCE' ) ) {
            $on = (bool) SATHI_LICENSE_ENFORCE;
        }
        return (bool) apply_filters( 'sathi_license_enforce', $on );
    }

    // ── Status / gating ───────────────────────────────────────────────

    public function status( bool $force = false ): array {
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $status = $this->remote( 'validate' );
        // Offline grace: if the server is unreachable, keep the last verified grant alive.
        if ( in_array( $status['status'], [ 'error', 'unknown' ], true ) ) {
            $last = get_option( self::LASTGOOD_OPT );
            if ( is_array( $last ) && ( time() - (int) ( $last['verified_at'] ?? 0 ) ) < self::GRACE_SECONDS ) {
                $last['graced'] = true;
                return $last;
            }
        } elseif ( 'active' === $status['status'] ) {
            $status['verified_at'] = time();
            update_option( self::LASTGOOD_OPT, $status, false );
        }
        set_transient( self::TRANSIENT, $status, DAY_IN_SECONDS );
        return $status;
    }

    /** True when gated features may run (enforcement off => always true). */
    public function is_active(): bool {
        if ( ! $this->enforcement_enabled() ) {
            return true;
        }
        return ( $this->status()['status'] ?? '' ) === 'active';
    }

    /** Entitlement check for a specific premium feature (e.g. 'woocommerce', 'deep_scan'). */
    public function can( string $feature ): bool {
        if ( ! $this->enforcement_enabled() ) {
            return true;
        }
        $s = $this->status();
        if ( ( $s['status'] ?? '' ) !== 'active' ) {
            return false;
        }
        return ! empty( $s['entitlements'][ $feature ] );
    }

    /**
     * Server-side premium grant — the license-validated "value" a nulled copy
     * cannot obtain. Returns the signed system directive, or '' if unlicensed.
     */
    public function premium_directive(): string {
        if ( ! $this->enforcement_enabled() ) {
            return '';
        }
        $cached = get_transient( self::PREMIUM_TRANSIENT );
        if ( is_string( $cached ) ) {
            return $cached;
        }
        $res = $this->remote( 'premium' );
        $d   = ( ( $res['status'] ?? '' ) === 'active' && ! empty( $res['premium']['system_directive'] ) )
            ? (string) $res['premium']['system_directive'] : '';
        set_transient( self::PREMIUM_TRANSIENT, $d, DAY_IN_SECONDS );
        return $d;
    }

    /**
     * Append the server-signed premium directive to the system prompt. The premium
     * "brain" instructions come from our license-validated server, so a nulled copy
     * (no valid signed grant) silently loses the premium behaviour.
     *
     * @param mixed $prompt
     * @return mixed
     */
    public function inject_premium_directive( $prompt ) {
        if ( ! $this->enforcement_enabled() ) {
            return $prompt;
        }
        $d = $this->premium_directive();
        return ( '' !== $d ) ? ( (string) $prompt . "\n\n" . $d ) : $prompt;
    }

    public function activate( string $key ): array {
        $this->set_key( $key );
        delete_transient( self::PREMIUM_TRANSIENT );
        $res = $this->remote( 'activate' );
        if ( 'active' === $res['status'] ) {
            $res['verified_at'] = time();
            update_option( self::LASTGOOD_OPT, $res, false );
        }
        set_transient( self::TRANSIENT, $res, DAY_IN_SECONDS );
        return $res;
    }

    public function deactivate(): array {
        $res = $this->remote( 'deactivate' );
        $this->clear_key();
        return $res;
    }

    public function cron_check(): void {
        delete_transient( self::PREMIUM_TRANSIENT );
        $this->status( true );
    }

    // ── Remote call + signature verification ──────────────────────────

    private function remote( string $action ): array {
        $key = $this->get_key();
        $ep  = $this->endpoint();

        if ( '' === $key ) {
            return [ 'status' => 'inactive', 'message' => __( 'No license key entered.', 'sathi-agentic-ai' ) ];
        }
        if ( '' === $ep ) {
            return [ 'status' => 'unknown', 'message' => __( 'License server URL is not configured.', 'sathi-agentic-ai' ) ];
        }

        $resp = wp_remote_post( $ep, [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/json' ],
            'body'    => [
                'action'  => $action,
                'key'     => $key,
                'domain'  => $this->domain(),
                'product' => self::PRODUCT,
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'status' => 'error', 'message' => $resp->get_error_message() ];
        }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $data ) || empty( $data['signed'] ) ) {
            // No signed envelope => untrusted. Never accept.
            return [ 'status' => 'invalid', 'message' => __( 'Unsigned or invalid license response.', 'sathi-agentic-ai' ) ];
        }

        $verified = $this->verify_envelope( $data['signed'] );
        if ( null === $verified ) {
            return [ 'status' => 'invalid', 'message' => __( 'License signature failed verification.', 'sathi-agentic-ai' ) ];
        }

        // Bind to this product + domain (reject tokens minted for another site).
        if ( ( $verified['product'] ?? '' ) !== self::PRODUCT ) {
            return [ 'status' => 'invalid', 'message' => __( 'License is for a different product.', 'sathi-agentic-ai' ) ];
        }
        $vdom = (string) ( $verified['domain'] ?? '' );
        if ( '' !== $vdom && $vdom !== $this->domain() ) {
            return [ 'status' => 'invalid', 'message' => __( 'License is bound to a different domain.', 'sathi-agentic-ai' ) ];
        }

        $active = ! empty( $verified['valid'] );
        return [
            'status'       => $active ? 'active' : ( $verified['reason'] ?? 'invalid' ),
            'valid'        => $active,
            'plan'         => $verified['plan'] ?? '',
            'expires'      => $verified['expires_at'] ?? '',
            'entitlements' => is_array( $verified['entitlements'] ?? null ) ? $verified['entitlements'] : [],
            'premium'      => is_array( $verified['premium'] ?? null ) ? $verified['premium'] : null,
            'reason'       => $verified['reason'] ?? '',
            'token_exp'    => (int) ( $verified['exp'] ?? 0 ),
            'message'      => $active ? '' : ( $verified['reason'] ?? 'invalid' ),
            'checked_at'   => current_time( 'mysql' ),
        ];
    }

    /** Verify the RS256 signed envelope with the embedded public key. */
    private function verify_envelope( $env ): ?array {
        if ( ! is_array( $env ) || empty( $env['payload'] ) || empty( $env['sig'] ) ) {
            return null;
        }
        if ( ! function_exists( 'openssl_verify' ) ) {
            return null; // No OpenSSL => cannot trust; fail closed.
        }
        $payload = (string) $env['payload'];
        $sig     = self::b64url_decode( (string) $env['sig'] );
        $ok      = openssl_verify( $payload, $sig, self::PUBLIC_KEY, OPENSSL_ALGO_SHA256 );
        if ( 1 !== $ok ) {
            return null;
        }
        $data = json_decode( self::b64url_decode( $payload ), true );
        if ( ! is_array( $data ) ) {
            return null;
        }
        if ( isset( $data['exp'] ) && time() > (int) $data['exp'] + DAY_IN_SECONDS ) {
            return null; // Token too old (small skew allowance).
        }
        return $data;
    }

    private static function b64url_decode( string $s ): string {
        return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
    }

    // ── Admin notice ──────────────────────────────────────────────────

    public function admin_notice(): void {
        if ( ! $this->enforcement_enabled() || $this->is_active() ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $purchase = 'https://saathi.railabs.in/pricing.php';
        printf(
            '<div class="notice notice-warning"><p><strong>Saathi AI</strong> — %s <a href="%s">%s</a> &middot; <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
            esc_html__( 'Activate your Saathi AI license to enable premium features.', 'sathi-agentic-ai' ),
            esc_url( admin_url( 'admin.php?page=sathi-dashboard#license' ) ),
            esc_html__( 'Enter license key', 'sathi-agentic-ai' ),
            esc_url( $purchase ),
            esc_html__( 'Get a license', 'sathi-agentic-ai' )
        );
    }
}
