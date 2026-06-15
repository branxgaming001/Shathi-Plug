<?php
/**
 * Admin Boot — registers admin pages, enqueues admin assets, wires React mount points,
 * and provides the Abilities management page.
 *
 * @package RaiLabs\Sathi\Admin
 */

namespace RaiLabs\Sathi\Admin;

use RaiLabs\Sathi\Core\Settings;
use RaiLabs\Sathi\Abilities\AbilityRegistry;

class AdminBoot {

    /**
     * Paint the full-colour Saathi logo as the admin-menu icon for our top-level
     * menu. Uses a CSS background-image data URI (not esc_url'd, colours kept)
     * and hides WP's fallback dashicon glyph + the empty <img>. Works expanded
     * and collapsed, and on hover/current states.
     */
    public function menu_icon_style(): void {
        $logo = $this->logo_data_uri();
        if ( $logo === '' ) {
            return;
        }
        $sel = '#adminmenu #toplevel_page_sathi-dashboard .wp-menu-image';
        echo '<style id="sathi-menu-icon">'
            . $sel . '{'
            . 'background-image:url("' . esc_attr( $logo ) . '") !important;'
            . 'background-repeat:no-repeat !important;'
            . 'background-position:center !important;'
            . 'background-size:22px 22px !important;'
            . '}'
            // Hide the fallback dashicon glyph so it never shows behind/over the logo.
            . $sel . ':before{content:"" !important;display:none !important;}'
            // Hide the empty <img src=""> WordPress outputs.
            . $sel . ' img{display:none !important;}'
            // Keep full opacity (WP dims menu icons ~0.6 by default).
            . '#adminmenu #toplevel_page_sathi-dashboard .wp-menu-image{opacity:1 !important;}'
            . '</style>';
    }

    /** The Saathi twin-bubbles logo as a PNG data URI (menu icon + dashboard header). */
    private function logo_data_uri(): string {
        return 'data:image/png;base64,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';
    }


    private Settings $settings;

    /** @var AbilityRegistry Cached registry instance */
    private AbilityRegistry $ability_registry;

    public function __construct( Settings $settings ) {
        $this->settings         = $settings;
        $this->ability_registry = AbilityRegistry::instance();
    }

    /**
     * Register admin hooks.
     */
    public function register(): void {
        // These hooks are attached during plugins_loaded (see Plugin::boot) so the
        // admin_menu listener is in place BEFORE WordPress fires admin_menu.
        add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // Paint the full-colour logo as the admin-menu icon. WordPress runs the
        // add_menu_page icon through esc_url(), which strips PNG data: URIs
        // (so the icon came out blank) and recolours SVG icons to monochrome.
        // A CSS background-image is not esc_url'd and keeps the original colours.
        add_action( 'admin_head', [ $this, 'menu_icon_style' ] );
        add_action( 'admin_post_sathi_toggle_ability', [ $this, 'handle_toggle_ability' ] );

        // Settings API registration must run on admin_init, not earlier.
        add_action( 'admin_init', [ $this->settings, 'register_with_wp_api' ] );
    }

    // ── Menu Pages ─────────────────────────────────────────────────

    /**
     * Add menu pages to the WordPress admin sidebar.
     */
    public function add_menu_pages(): void {
        // A dashicon is used as a safe fallback only — the real, full-colour
        // Saathi logo is painted over it via menu_icon_style() (CSS background),
        // because WordPress strips PNG data: URIs passed here through esc_url().
        $icon_fallback = 'dashicons-format-chat';

        // Main dashboard
        add_menu_page(
            __( 'Saathi Agentic AI', 'sathi-agentic-ai' ),
            __( 'Saathi AI', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-dashboard',
            [ $this, 'render_dashboard' ],
            $icon_fallback,
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'sathi-dashboard',
            __( 'Dashboard', 'sathi-agentic-ai' ),
            __( 'Dashboard', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-dashboard',
            [ $this, 'render_dashboard' ]
        );

        // Settings submenu
        add_submenu_page(
            'sathi-dashboard',
            __( 'Settings', 'sathi-agentic-ai' ),
            __( 'Settings', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-settings',
            [ $this, 'render_settings' ]
        );

        // Abilities submenu (NEW)
        add_submenu_page(
            'sathi-dashboard',
            __( 'Abilities', 'sathi-agentic-ai' ),
            __( 'Abilities', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-abilities',
            [ $this, 'render_abilities' ]
        );

        // Personas submenu
        add_submenu_page(
            'sathi-dashboard',
            __( 'Personas', 'sathi-agentic-ai' ),
            __( 'Personas', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-personas',
            [ $this, 'render_personas' ]
        );

        // Knowledge submenu
        add_submenu_page(
            'sathi-dashboard',
            __( 'Knowledge Base', 'sathi-agentic-ai' ),
            __( 'Knowledge Base', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-knowledge',
            [ $this, 'render_knowledge' ]
        );

        // Memory submenu
        add_submenu_page(
            'sathi-dashboard',
            __( 'Memory', 'sathi-agentic-ai' ),
            __( 'Memory', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-memory',
            [ $this, 'render_memory' ]
        );

        // Logs submenu
        add_submenu_page(
            'sathi-dashboard',
            __( 'Logs', 'sathi-agentic-ai' ),
            __( 'Logs', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-logs',
            [ $this, 'render_logs' ]
        );
    }

    // ── Asset Enqueue ──────────────────────────────────────────────

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets( string $hook ): void {
        // Only load on Sathi pages
        if ( ! str_contains( $hook, 'sathi' ) && ! str_contains( $hook, 'sathi_persona' ) ) {
            return;
        }

        $admin_js  = SATHI_PATH . 'assets/admin.js';
        $admin_css = SATHI_PATH . 'assets/admin.css';

        // The bundle is a self-contained Vite/React ES module (it ships its own
        // React and uses the native fetch API), so it needs no WP script deps.
        // It is loaded as type="module" via Plugin::filter_module_script_tag().
        wp_enqueue_script(
            'sathi-admin',
            SATHI_ASSETS . 'admin.js',
            [],
            file_exists( $admin_js ) ? filemtime( $admin_js ) : SATHI_VERSION,
            true
        );

        wp_enqueue_style(
            'sathi-admin',
            SATHI_ASSETS . 'admin.css',
            [],
            file_exists( $admin_css ) ? filemtime( $admin_css ) : SATHI_VERSION
        );

        wp_localize_script( 'sathi-admin', 'sathiAdmin', [
            'restUrl'     => rest_url( 'sathi/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'siteName'    => get_bloginfo( 'name' ),
            'accentColor' => $this->settings->get( Settings::KEY_ACCENT_COLOR, '#7c3aed' ),
            'version'     => SATHI_VERSION,
            'logo'        => $this->logo_data_uri(),
        ] );
    }

    // ── Page Renderers ─────────────────────────────────────────────

    /**
     * Render the main dashboard React mount.
     */
    public function render_dashboard(): void {
        echo '<div id="sathi-admin-dashboard" class="sathi-admin-wrap"></div>';
        $this->render_admin_footer();
    }

    /**
     * Render the settings page React mount.
     */
    public function render_settings(): void {
        echo '<div id="sathi-admin-settings" class="sathi-admin-wrap"></div>';
        $this->render_admin_footer();
    }

    /**
     * Render the Abilities management page.
     *
     * Displays a filterable table of all registered abilities with enable/disable toggles.
     */
    public function render_abilities(): void {
        // Handle filter parameter from GET
        $active_filter = sanitize_text_field( $_GET['filter'] ?? 'all' );
        $category_filter = sanitize_text_field( $_GET['category'] ?? '' );

        // Build URL parts for filter links
        $base_url = admin_url( 'admin.php?page=sathi-abilities' );

        // Get abilities based on filter
        $all_abilities = $this->ability_registry->get_all();
        $stats         = $this->ability_registry->get_stats();

        // Determine which abilities to show
        $displayed = match ( $active_filter ) {
            'enabled'  => array_filter( $all_abilities, fn( string $name ) => ! $this->ability_registry->is_disabled( $name ), ARRAY_FILTER_USE_KEY ),
            'disabled' => array_filter( $all_abilities, fn( string $name ) => $this->ability_registry->is_disabled( $name ), ARRAY_FILTER_USE_KEY ),
            default    => $all_abilities,
        };

        // Apply category filter
        if ( $category_filter !== '' ) {
            $displayed = array_filter( $displayed, function ( array $def ) use ( $category_filter ): bool {
                return ( $def['category'] ?? 'general' ) === $category_filter;
            } );
        }

        // Sort by category then name
        uasort( $displayed, function ( array $a, array $b ): int {
            $cat_cmp = ( $a['category'] ?? 'general' ) <=> ( $b['category'] ?? 'general' );
            return $cat_cmp !== 0 ? $cat_cmp : ( $a['label'] ?? '' ) <=> ( $b['label'] ?? '' );
        } );

        // Toggle URL helper
        $nonce = wp_create_nonce( 'sathi_toggle_ability' );

        ?>
        <div class="wrap sathi-admin-wrap" id="sathi-admin-abilities">
            <h1><?php esc_html_e( 'Abilities', 'sathi-agentic-ai' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'AI-callable tools registered by Sathi and active plugins. Disable abilities to prevent the AI from using them.', 'sathi-agentic-ai' ); ?>
            </p>

            <!-- Stats cards -->
            <div class="sathi-stats-cards" style="display:flex;gap:16px;margin:16px 0;">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 20px;text-align:center;min-width:100px;">
                    <strong style="font-size:24px;display:block;"><?php echo absint( $stats['total'] ); ?></strong>
                    <span style="color:#6b7280;"><?php esc_html_e( 'Total', 'sathi-agentic-ai' ); ?></span>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 20px;text-align:center;min-width:100px;">
                    <strong style="font-size:24px;display:block;color:#059669;"><?php echo absint( $stats['enabled'] ); ?></strong>
                    <span style="color:#6b7280;"><?php esc_html_e( 'Enabled', 'sathi-agentic-ai' ); ?></span>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 20px;text-align:center;min-width:100px;">
                    <strong style="font-size:24px;display:block;color:#dc2626;"><?php echo absint( $stats['disabled'] ); ?></strong>
                    <span style="color:#6b7280;"><?php esc_html_e( 'Disabled', 'sathi-agentic-ai' ); ?></span>
                </div>
                <?php foreach ( $stats['categories'] as $cat => $count ) : ?>
                    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 20px;text-align:center;min-width:80px;">
                        <strong style="font-size:20px;display:block;color:#7c3aed;"><?php echo absint( $count ); ?></strong>
                        <span style="color:#6b7280;text-transform:capitalize;"><?php echo esc_html( $cat ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div style="margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'all', 'category' => $category_filter ], $base_url ) ); ?>"
                   class="button <?php echo $active_filter === 'all' ? 'button-primary' : ''; ?>">
                    <?php esc_html_e( 'All', 'sathi-agentic-ai' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'enabled', 'category' => $category_filter ], $base_url ) ); ?>"
                   class="button <?php echo $active_filter === 'enabled' ? 'button-primary' : ''; ?>">
                    <?php esc_html_e( 'Enabled', 'sathi-agentic-ai' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'disabled', 'category' => $category_filter ], $base_url ) ); ?>"
                   class="button <?php echo $active_filter === 'disabled' ? 'button-primary' : ''; ?>">
                    <?php esc_html_e( 'Disabled', 'sathi-agentic-ai' ); ?>
                </a>

                <span style="margin-left:16px;color:#6b7280;">|</span>

                <a href="<?php echo esc_url( add_query_arg( [ 'filter' => $active_filter, 'category' => '' ], $base_url ) ); ?>"
                   class="button <?php echo $category_filter === '' ? 'button-primary' : ''; ?>">
                    <?php esc_html_e( 'All Categories', 'sathi-agentic-ai' ); ?>
                </a>
                <?php foreach ( array_keys( $stats['categories'] ) as $cat ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'filter' => $active_filter, 'category' => $cat ], $base_url ) ); ?>"
                       class="button <?php echo $category_filter === $cat ? 'button-primary' : ''; ?>">
                        <?php echo esc_html( ucfirst( $cat ) ); ?>
                    </a>
                <?php endforeach; ?>

                <?php if ( $active_filter !== '' || $category_filter !== '' ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button" style="margin-left:8px;">
                        <?php esc_html_e( 'Clear filters', 'sathi-agentic-ai' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Bulk actions -->
            <div style="margin-bottom:12px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'sathi_bulk_toggle', 'sathi_bulk_nonce' ); ?>
                    <input type="hidden" name="action" value="sathi_toggle_ability">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( add_query_arg( [ 'filter' => $active_filter, 'category' => $category_filter ], $base_url ) ); ?>">
                    <?php if ( $category_filter !== '' ) : ?>
                        <input type="hidden" name="bulk_category" value="<?php echo esc_attr( $category_filter ); ?>">
                        <button type="submit" name="bulk_action" value="enable_category" class="button">
                            <?php printf( esc_html__( 'Enable all %s', 'sathi-agentic-ai' ), esc_html( $category_filter ) ); ?>
                        </button>
                        <button type="submit" name="bulk_action" value="disable_category" class="button">
                            <?php printf( esc_html__( 'Disable all %s', 'sathi-agentic-ai' ), esc_html( $category_filter ) ); ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Abilities table -->
            <table class="wp-list-table widefat fixed striped sathi-abilities-table" style="border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="width:30px;"><?php esc_html_e( 'Status', 'sathi-agentic-ai' ); ?></th>
                        <th style="width:200px;"><?php esc_html_e( 'Name', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'sathi-agentic-ai' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Category', 'sathi-agentic-ai' ); ?></th>
                        <th style="width:250px;"><?php esc_html_e( 'Parameters', 'sathi-agentic-ai' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Capability', 'sathi-agentic-ai' ); ?></th>
                        <th style="width:60px;"><?php esc_html_e( 'Action', 'sathi-agentic-ai' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $displayed ) ) : ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:32px;color:#6b7280;">
                                <?php esc_html_e( 'No abilities match the current filter.', 'sathi-agentic-ai' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $displayed as $name => $def ) :
                            $is_disabled = $this->ability_registry->is_disabled( $name );
                            $capability  = $def['capability'] ?? 'read';
                            $category    = $def['category'] ?? 'general';
                            $schema      = $def['schema'] ?? [];
                            $params_summary = $this->summarize_schema_params( $schema );
                            $toggle_url  = add_query_arg( [
                                'action'      => 'sathi_toggle_ability',
                                'ability'     => $name,
                                'enable'      => $is_disabled ? '1' : '0',
                                '_wpnonce'    => $nonce,
                                'redirect_to' => urlencode( add_query_arg( [ 'filter' => $active_filter, 'category' => $category_filter ], $base_url ) ),
                            ], admin_url( 'admin-post.php' ) );
                        ?>
                            <tr<?php echo $is_disabled ? ' style="opacity:0.55;"' : ''; ?>>
                                <!-- Status -->
                                <td style="text-align:center;vertical-align:middle;">
                                    <span class="sathi-status-dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo $is_disabled ? '#d1d5db' : '#10b981'; ?>;" title="<?php echo $is_disabled ? esc_attr__( 'Disabled', 'sathi-agentic-ai' ) : esc_attr__( 'Enabled', 'sathi-agentic-ai' ); ?>"></span>
                                </td>

                                <!-- Name -->
                                <td style="vertical-align:middle;">
                                    <strong><?php echo esc_html( $def['label'] ?? $name ); ?></strong>
                                    <br><code style="font-size:11px;color:#6b7280;"><?php echo esc_html( $name ); ?></code>
                                </td>

                                <!-- Description -->
                                <td style="vertical-align:middle;font-size:13px;">
                                    <?php echo esc_html( $def['description'] ?? '' ); ?>
                                </td>

                                <!-- Category -->
                                <td style="vertical-align:middle;">
                                    <span class="sathi-category-badge" style="display:inline-block;background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:12px;font-size:12px;text-transform:capitalize;">
                                        <?php echo esc_html( $category ); ?>
                                    </span>
                                </td>

                                <!-- Parameters -->
                                <td style="vertical-align:middle;font-size:12px;line-height:1.6;">
                                    <?php echo wp_kses_post( $params_summary ); ?>
                                </td>

                                <!-- Capability -->
                                <td style="vertical-align:middle;font-size:12px;">
                                    <?php if ( $capability === 'manage_options' ) : ?>
                                        <span style="color:#7c3aed;font-weight:600;" title="<?php esc_attr_e( 'Admin only', 'sathi-agentic-ai' ); ?>">
                                            <?php echo esc_html( $capability ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color:#6b7280;"><?php echo esc_html( $capability ); ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Toggle action -->
                                <td style="vertical-align:middle;text-align:center;">
                                    <a href="<?php echo esc_url( $toggle_url ); ?>"
                                       class="button button-small <?php echo $is_disabled ? 'button-primary' : ''; ?>"
                                       style="white-space:nowrap;"
                                       title="<?php echo $is_disabled ? esc_attr__( 'Enable this ability', 'sathi-agentic-ai' ) : esc_attr__( 'Disable this ability', 'sathi-agentic-ai' ); ?>">
                                        <?php echo $is_disabled ? esc_html__( 'Enable', 'sathi-agentic-ai' ) : esc_html__( 'Disable', 'sathi-agentic-ai' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Category', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Parameters', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Capability', 'sathi-agentic-ai' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'sathi-agentic-ai' ); ?></th>
                    </tr>
                </tfoot>
            </table>

            <?php if ( ! empty( $displayed ) ) : ?>
                <p style="margin-top:12px;color:#6b7280;font-size:12px;">
                    <?php
                    printf(
                        /* translators: %d: number of abilities shown */
                        esc_html__( 'Showing %d abilities.', 'sathi-agentic-ai' ),
                        count( $displayed )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <style>
            .sathi-abilities-table th,
            .sathi-abilities-table td {
                padding: 10px 12px;
            }
            .sathi-abilities-table tbody tr:hover {
                opacity: 1 !important;
            }
            .sathi-abilities-table .sathi-param-name {
                font-family: monospace;
                font-weight: 600;
                color: #1e40af;
            }
            .sathi-abilities-table .sathi-param-type {
                color: #6b7280;
                font-size: 11px;
            }
            .sathi-abilities-table .sathi-required-badge {
                display: inline-block;
                background: #fef2f2;
                color: #dc2626;
                padding: 0 4px;
                border-radius: 3px;
                font-size: 10px;
                text-transform: uppercase;
            }
        </style>
        <?php

        $this->render_admin_footer();
    }

    /**
     * Render the knowledge base page.
     */
    public function render_knowledge(): void {
        echo '<div id="sathi-admin-knowledge" class="sathi-admin-wrap">';
        echo '<h1>' . esc_html__( 'Knowledge Base', 'sathi-agentic-ai' ) . '</h1>';

        $stats = ( new \RaiLabs\Sathi\Knowledge\KnowledgeManager() )->get_stats();
        echo '<div class="sathi-stats">';
        echo '<p>' . sprintf( esc_html__( 'Total chunks indexed: %d', 'sathi-agentic-ai' ), $stats['total_chunks'] ) . '</p>';
        echo '<p>' . sprintf( esc_html__( 'Unique sources: %d', 'sathi-agentic-ai' ), $stats['total_sources'] ) . '</p>';
        echo '<p>' . sprintf( esc_html__( 'Estimated total tokens: %d', 'sathi-agentic-ai' ), $stats['total_tokens'] ) . '</p>';
        echo '<p>' . sprintf( esc_html__( 'Last crawl: %s', 'sathi-agentic-ai' ), $stats['last_crawl'] ?: __( 'Never', 'sathi-agentic-ai' ) ) . '</p>';
        echo '</div>';

        echo '<button id="sathi-trigger-index" class="button button-primary">'
            . esc_html__( 'Index Site Now', 'sathi-agentic-ai' ) . '</button>';
        echo '<button id="sathi-clear-index" class="button" style="margin-left:10px;">'
            . esc_html__( 'Clear Index', 'sathi-agentic-ai' ) . '</button>';
        echo '</div>';
        $this->render_admin_footer();
    }

    /**
     * Render the memory management page.
     */
    public function render_memory(): void {
        echo '<div id="sathi-admin-memory" class="sathi-admin-wrap"></div>';
        $this->render_admin_footer();
    }

    /**
     * Render the Persona Studio page.
     */
    public function render_personas(): void {
        echo '<div id="sathi-admin-personas" class="sathi-admin-wrap"></div>';
        $this->render_admin_footer();
    }

    /**
     * Render the logs page.
     */
    public function render_logs(): void {
        $logger = new \RaiLabs\Sathi\Support\Logger();
        $lines  = $logger->tail( 200 );

        echo '<div id="sathi-admin-logs" class="sathi-admin-wrap">';
        echo '<h1>' . esc_html__( 'Sathi Logs', 'sathi-agentic-ai' ) . '</h1>';
        echo '<pre style="background:#1e1e2e;color:#cdd6f4;padding:16px;border-radius:8px;max-height:500px;overflow:auto;font-size:12px;">';
        echo esc_html( implode( "\n", array_reverse( $lines ) ) );
        echo '</pre>';
        echo '</div>';
        $this->render_admin_footer();
    }

    // ── Action Handlers ────────────────────────────────────────────

    /**
     * Handle ability toggle from admin-post.php.
     *
     * Handles both single-ability toggles and bulk category toggles.
     */
    public function handle_toggle_ability(): void {
        // Capability check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage abilities.', 'sathi-agentic-ai' ) );
        }

        $redirect_to = wp_get_referer() ?: admin_url( 'admin.php?page=sathi-abilities' );

        // ── Bulk actions ────────────────────────────────────────
        if ( isset( $_POST['bulk_action'] ) ) {
            check_admin_referer( 'sathi_bulk_toggle', 'sathi_bulk_nonce' );

            $bulk_action = sanitize_text_field( $_POST['bulk_action'] );
            $category    = sanitize_text_field( $_POST['bulk_category'] ?? '' );

            if ( $bulk_action === 'enable_category' && $category !== '' ) {
                $this->ability_registry->enable_category( $category );
            } elseif ( $bulk_action === 'disable_category' && $category !== '' ) {
                $this->ability_registry->disable_category( $category );
            }

            // Get redirect URL from hidden field
            if ( ! empty( $_POST['redirect_to'] ) ) {
                $redirect_to = esc_url_raw( $_POST['redirect_to'] );
            }

            wp_safe_redirect( add_query_arg( 'toggled', 'bulk', $redirect_to ) );
            exit;
        }

        // ── Single toggle ──────────────────────────────────────
        check_admin_referer( 'sathi_toggle_ability' );

        $ability_name = sanitize_text_field( $_GET['ability'] ?? '' );
        $enable       = ( $_GET['enable'] ?? '0' ) === '1';

        if ( $ability_name !== '' && $this->ability_registry->has( $ability_name ) ) {
            if ( $enable ) {
                $this->ability_registry->enable( $ability_name );
            } else {
                $this->ability_registry->disable( $ability_name );
            }
        }

        // Use redirect_to from GET if present
        if ( ! empty( $_GET['redirect_to'] ) ) {
            $redirect_to = esc_url_raw( $_GET['redirect_to'] );
        }

        wp_safe_redirect( add_query_arg( 'toggled', '1', $redirect_to ) );
        exit;
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Summarize JSON Schema parameters into a human-readable HTML string.
     *
     * @param  array  $schema JSON Schema object.
     * @return string HTML summary of parameters.
     */
    private function summarize_schema_params( array $schema ): string {
        $properties = $schema['properties'] ?? [];
        $required   = $schema['required'] ?? [];

        if ( empty( $properties ) ) {
            return '<em style="color:#9ca3af;">' . esc_html__( 'No parameters', 'sathi-agentic-ai' ) . '</em>';
        }

        $lines = [];
        foreach ( $properties as $prop_name => $prop_def ) {
            $type  = $prop_def['type'] ?? 'string';
            $desc  = $prop_def['description'] ?? '';
            $is_req = in_array( $prop_name, $required, true );
            $has_default = array_key_exists( 'default', $prop_def );

            // Truncate long descriptions
            if ( mb_strlen( $desc ) > 60 ) {
                $desc = mb_substr( $desc, 0, 57 ) . '...';
            }

            $line  = '<span class="sathi-param-name">' . esc_html( $prop_name ) . '</span>';
            $line .= ' <span class="sathi-param-type">(' . esc_html( $type ) . ')</span>';

            if ( $is_req ) {
                $line .= ' <span class="sathi-required-badge">' . esc_html__( 'required', 'sathi-agentic-ai' ) . '</span>';
            } elseif ( $has_default ) {
                $line .= ' <span style="font-size:10px;color:#9ca3af;">' . esc_html__( 'optional', 'sathi-agentic-ai' ) . '</span>';
            }

            if ( $desc !== '' ) {
                $line .= '<br><span style="color:#6b7280;font-size:11px;">' . esc_html( $desc ) . '</span>';
            }

            $lines[] = $line;
        }

        return implode( '<br>', $lines );
    }

    /**
     * Shared admin footer with branding.
     */
    private function render_admin_footer(): void {
        echo '<div class="sathi-admin-footer" style="margin-top:40px;padding:16px 0;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">';
        echo esc_html__( 'Saathi Agentic AI', 'sathi-agentic-ai' ) . ' v' . esc_html( SATHI_VERSION );
        echo ' — <a href="https://railabs.in" target="_blank" rel="noopener noreferrer">RAI Labs P. Ltd.</a>';
        echo '</div>';
    }
}
