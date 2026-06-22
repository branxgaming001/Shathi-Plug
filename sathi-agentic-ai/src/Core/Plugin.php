<?php
/**
 * Main Plugin bootstrap orchestrator.
 *
 * Singleton that wires the DI container, registers service providers,
 * and boots all Sathi subsystems.
 *
 * @package NeerMedia\Sathi\Core
 */

namespace NeerMedia\Sathi\Core;

use NeerMedia\Sathi\Providers\Factory;
use NeerMedia\Sathi\Agent\AgentManager;
use NeerMedia\Sathi\Personas\PersonaRegistry;
use NeerMedia\Sathi\Memory\MemoryStore;
use NeerMedia\Sathi\Memory\MemoryManager;
use NeerMedia\Sathi\Knowledge\KnowledgeManager;
use NeerMedia\Sathi\Chat\ChatManager;
use NeerMedia\Sathi\Navigation\NavigationManager;
use NeerMedia\Sathi\Rest\RestServer;
use NeerMedia\Sathi\Admin\AdminBoot;
use NeerMedia\Sathi\Integration\WP7Bridge;
use NeerMedia\Sathi\Support\Logger;

/**
 * @psalm-import-type SathiEnv from Plugin
 */
final class Plugin {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var array<string, object> Service container */
    private array $services = [];

    /** @var bool Boot flag */
    private bool $booted = false;

    /** @var \Throwable|null Captured boot failure, surfaced as an admin notice. */
    private ?\Throwable $boot_error = null;

    /**
     * Get singleton instance.
     */
    public static function instance(): self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot the plugin — register hooks, init services.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        // ── Load text domain ─────────────────────────────────────
        load_plugin_textdomain(
            SATHI_DOMAIN,
            false,
            dirname( plugin_basename( SATHI_ENTRY ) ) . '/languages'
        );

        // ── Wire services ────────────────────────────────────────
        // Guard construction: a single failing service must not silently kill
        // the whole plugin (which previously showed up as a missing admin menu
        // with no error). On failure, surface an admin notice and bail out.
        try {
            $this->init_services();
        } catch ( \Throwable $e ) {
            $this->boot_error = $e;
            error_log( 'Saathi Agentic AI failed to boot: ' . $e->getMessage() );
            add_action( 'admin_notices', [ $this, 'render_boot_error_notice' ] );
            return;
        }

        // ── Fire WP hooks ────────────────────────────────────────
        add_action( 'init', [ $this, 'on_init' ] );
        add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );
        add_action( 'rest_api_init', [ $this, 'on_rest_init' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );

        // Admin UI hooks. CRITICAL: WordPress fires `admin_menu` BEFORE
        // `admin_init`, so the admin menu MUST be registered now (during
        // plugins_loaded) — not from an admin_init callback, which attaches the
        // `admin_menu` listener too late and leaves the sidebar menu missing.
        // Registered unconditionally: every hook inside is admin-context only and
        // inert on the front end, so this is safe and bulletproof.
        $this->get( 'admin' )->register();

        // License: daily re-check cron + admin notice (gating is OFF by default).
        $this->get( 'license' )->register();

        // Sathi's React bundles are ES modules — make WordPress emit them as such.
        add_filter( 'script_loader_tag', [ $this, 'filter_module_script_tag' ], 10, 2 );

        $this->booted = true;

        do_action( 'sathi_booted' );
    }

    /**
     * Create and store service instances.
     */
    private function init_services(): void {
        $this->services['logger']      = new Logger();
        $this->services['settings']    = new Settings();
        $this->services['factory']     = new Factory( $this->get( 'settings' ) );
        $this->services['memory']      = new MemoryStore();
        $this->services['memory_manager'] = new MemoryManager( $this->get( 'memory' ), $this->get( 'factory' ) );
        $this->services['personas']    = new PersonaRegistry();
        $this->services['knowledge']   = new KnowledgeManager();
        $this->services['chat']        = new ChatManager( $this->get( 'factory' ), $this->get( 'memory' ) );
        $this->services['agent']       = new AgentManager( $this->get( 'factory' ), $this->get( 'personas' ) );
        $this->services['navigation']  = new NavigationManager();
        $this->services['rest']        = new RestServer();
        $this->services['admin']       = new AdminBoot( $this->get( 'settings' ) );
        $this->services['license']     = new \NeerMedia\Sathi\License\LicenseManager( $this->get( 'settings' ) );
        $this->services['wp7bridge']   = new WP7Bridge();
        $this->services['usage']       = new \NeerMedia\Sathi\Support\UsageTracker();
        $this->services['gdpr']        = new \NeerMedia\Sathi\Support\GDPRManager();
        $this->services['moderator']   = new \NeerMedia\Sathi\Support\ContentModerator();
    }

    /**
     * Retrieve a service from the container.
     *
     * @template T of object
     * @param  class-string<T>|string $name
     * @return T|object|null
     */
    public function get( string $name ): ?object {
        return $this->services[ $name ] ?? null;
    }

    /**
     * WordPress 'init' handler.
     */
    public function on_init(): void {
        // Register post types, shortcodes, Gutenberg blocks
        $this->get( 'chat' )->register();
        $this->get( 'personas' )->register();
        $this->get( 'knowledge' )->register_cron();
        $this->get( 'wp7bridge' )->boot_if_available();
        $this->get( 'navigation' )->register();

        // Phase 9: GDPR
        $this->get( 'gdpr' )->register();

        do_action( 'sathi_init' );
    }

    /**
     * REST API init handler.
     */
    public function on_rest_init(): void {
        $this->get( 'rest' )->register_routes();

        // GDPR consent endpoint
        register_rest_route( 'sathi/v1', '/gdpr/consent', [
            'methods'             => 'POST',
            'callback'            => function () {
                $user_id = get_current_user_id() ?: null;
                $this->get( 'gdpr' )->give_consent( $user_id );
                return new \WP_REST_Response( [ 'success' => true ] );
            },
            'permission_callback' => '__return_true',
        ] );

        // Usage analytics endpoint
        register_rest_route( 'sathi/v1', '/settings/usage', [
            'methods'             => 'GET',
            'callback'            => function ( \WP_REST_Request $request ) {
                $range = sanitize_text_field( $request->get_param( 'range' ) ?? '30d' );
                $days  = (int) str_replace( 'd', '', $range ) ?: 30;
                $from  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
                return new \WP_REST_Response( $this->get( 'usage' )->get_stats( $from ) );
            },
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ] );
    }

    /**
     * Flush rewrite rules once per version so the /sathi-stream/ SSE endpoint
     * keeps working after a plugin update — even if the user updated the files
     * without deactivating/reactivating (which would otherwise leave a 404 and
     * a chatbot that "stops working").
     */
    public function maybe_flush_rewrites(): void {
        if ( get_option( 'sathi_rewrites_version' ) !== SATHI_VERSION ) {
            flush_rewrite_rules( false );
            update_option( 'sathi_rewrites_version', SATHI_VERSION );
        }
    }

    /**
     * Render an admin notice when the plugin failed to boot.
     *
     * Prevents silent failures (e.g. a missing dependency or a fatal during
     * service construction) from manifesting only as a missing admin menu.
     */
    public function render_boot_error_notice(): void {
        if ( ! $this->boot_error || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s<br><code>%s</code></p></div>',
            esc_html__( 'Saathi Agentic AI could not start.', 'sathi-agentic-ai' ),
            esc_html__( 'Please check your server error log for details:', 'sathi-agentic-ai' ),
            esc_html( $this->boot_error->getMessage() )
        );
    }

    /**
     * Frontend asset enqueue hook.
     */
    public function enqueue_frontend(): void {
        $this->get( 'chat' )->enqueue_assets();
    }

    /**
     * Force Sathi's Vite-built bundles to load as native ES modules.
     *
     * The admin dashboard and chat-widget bundles are emitted by Vite as ES
     * modules (they use `import` / `export` and code-split into chunks). When
     * WordPress prints them with a classic `<script>` tag the browser throws
     * "Uncaught SyntaxError: Cannot use import statement outside a module",
     * the bundle never executes, and the React mount points stay empty —
     * producing the blank admin dashboard and a missing chat widget.
     *
     * Adding `type="module"` lets the browser execute the bundle and resolve
     * its relative chunk imports correctly.
     *
     * @param string $tag    The full `<script>` HTML tag generated by WP.
     * @param string $handle The registered script handle.
     * @return string The (possibly rewritten) tag.
     */
    public function filter_module_script_tag( string $tag, string $handle ): string {
        $module_handles = apply_filters( 'sathi_module_script_handles', [
            'sathi-admin',
            'sathi-chat-widget',
        ] );

        if ( ! in_array( $handle, $module_handles, true ) ) {
            return $tag;
        }

        // Replace an existing type attribute, otherwise inject one.
        if ( preg_match( '/\stype=([\'"]).*?\1/', $tag ) ) {
            $tag = preg_replace( '/\stype=([\'"]).*?\1/', ' type="module"', $tag, 1 );
        } else {
            $tag = preg_replace( '/^<script\s/', '<script type="module" ', $tag, 1 );
        }

        return $tag;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserialize.
     */
    public function __wakeup() {
        throw new \RuntimeException( 'Cannot unserialize singleton' );
    }

    /**
     * Private constructor — use instance().
     */
    private function __construct() {}
}
