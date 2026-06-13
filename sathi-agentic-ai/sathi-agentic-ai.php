<?php
/**
 * Sathi Agentic AI — WordPress Support Agent Framework
 *
 * @package           SathiAgenticAI
 * @author            RAI Labs P. Ltd.
 * @copyright         2026 RAI Labs P. Ltd.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Sathi Agentic AI
 * Plugin URI:        https://railabs.in/sathi
 * Description:       Intelligent support agent for WordPress — chat, knowledge base, persistent memory, and real-time site navigation. Powered by multiple AI providers with a highly customizable 2026 UI.
 * Version:           1.6.3
 * Author:            RAI Labs P. Ltd.
 * Author URI:        https://railabs.in
 * Text Domain:       sathi-agentic-ai
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      6.8
 *
 * Sathi Agentic AI is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Sathi Agentic AI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

// ── Deny direct access ───────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Plugin constants ───────────────────────────────────────────────────
define( 'SATHI_VERSION', '1.6.3' );
define( 'SATHI_PREFIX', 'sathi' );
define( 'SATHI_DOMAIN', 'sathi-agentic-ai' );
define( 'SATHI_ENTRY', __FILE__ );
define( 'SATHI_PATH', plugin_dir_path( __FILE__ ) );
define( 'SATHI_URL', plugin_dir_url( __FILE__ ) );
define( 'SATHI_SRC', SATHI_PATH . 'src/' );
define( 'SATHI_UI', SATHI_PATH . 'ui/' );
define( 'SATHI_ASSETS', SATHI_URL . 'assets/' );
define( 'SATHI_LABS', SATHI_PATH . 'labs/' );

// Network constants with sensible defaults
if ( ! defined( 'SATHI_DEFAULT_TIMEOUT' ) ) {
    define( 'SATHI_DEFAULT_TIMEOUT', 60 * 5 );
}
if ( ! defined( 'SATHI_STREAM_CHUNK_SIZE' ) ) {
    define( 'SATHI_STREAM_CHUNK_SIZE', 1024 );
}
if ( ! defined( 'SATHI_MAX_HISTORY_LENGTH' ) ) {
    define( 'SATHI_MAX_HISTORY_LENGTH', 50 );
}

// ── Composer autoloader ────────────────────────────────────────────────
$autoloader = SATHI_PATH . 'vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
    require_once $autoloader;
} else {
    // Graceful fallback: register a basic PSR-4 autoloader for development
    spl_autoload_register( function ( $class ) {
        $prefix   = 'RaiLabs\\Sathi\\';
        $prefix_len = strlen( $prefix );

        if ( strncmp( $prefix, $class, $prefix_len ) !== 0 ) {
            return;
        }

        $relative = substr( $class, $prefix_len );
        $parts    = explode( '\\', $relative );
        $file     = SATHI_SRC . implode( '/', $parts ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    } );
}

// ── Bootstrap ──────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'RaiLabs\\Sathi\\Core\\Plugin' ) ) {
        RaiLabs\Sathi\Core\Plugin::instance()->boot();
    }
}, 5 );

// ── Activation / Deactivation ──────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    if ( class_exists( 'RaiLabs\\Sathi\\Core\\Activator' ) ) {
        RaiLabs\Sathi\Core\Activator::activate();
    }
} );

register_deactivation_hook( __FILE__, function () {
    if ( class_exists( 'RaiLabs\\Sathi\\Core\\Activator' ) ) {
        RaiLabs\Sathi\Core\Activator::deactivate();
    }
} );

// ── Uninstall cleanup ──────────────────────────────────────────────────
// See uninstall.php for full cleanup; loaded by WP when plugin is deleted.
