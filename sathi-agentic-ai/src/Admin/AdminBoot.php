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
        add_action( 'admin_post_sathi_toggle_ability', [ $this, 'handle_toggle_ability' ] );

        // Settings API registration must run on admin_init, not earlier.
        add_action( 'admin_init', [ $this->settings, 'register_with_wp_api' ] );
    }

    // ── Menu Pages ─────────────────────────────────────────────────

    /**
     * Add menu pages to the WordPress admin sidebar.
     */
    public function add_menu_pages(): void {
        // Saathi mark — a friendly chat bubble with a spark. Monochrome so the
        // WordPress admin menu can tint it like the other icons.
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad">'
            . '<path d="M12 3.6C6.6 3.6 2.5 7.1 2.5 11.4c0 2.4 1.3 4.6 3.4 6-.2 1.2-.8 2.4-1.7 3.3 1.6-.2 3.1-.8 4.3-1.7 1.1.3 2.2.4 3.5.4 5.4 0 9.5-3.5 9.5-7.9S17.4 3.6 12 3.6z"/>'
            . '<path d="M12 6.7l1.05 2.32 2.55.26-1.9 1.72.53 2.5L12 12.96 9.77 14.5l.53-2.5-1.9-1.72 2.55-.26z" fill="#1e1e2e"/>'
            . '</svg>'
        );

        // Main dashboard
        add_menu_page(
            __( 'Saathi Agentic AI', 'sathi-agentic-ai' ),
            __( 'Saathi AI', 'sathi-agentic-ai' ),
            'manage_options',
            'sathi-dashboard',
            [ $this, 'render_dashboard' ],
            $icon_svg,
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
