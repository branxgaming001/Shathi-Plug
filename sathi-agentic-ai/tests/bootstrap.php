<?php
/**
 * PHPUnit bootstrap for Sathi Agentic AI.
 *
 * Loads the plugin classes (without WordPress) for unit testing.
 */

// Define plugin constants that don't require WordPress
if ( ! defined( 'SATHI_VERSION' ) ) {
    define( 'SATHI_VERSION', '1.0.0' );
}
if ( ! defined( 'SATHI_PREFIX' ) ) {
    define( 'SATHI_PREFIX', 'sathi' );
}
if ( ! defined( 'SATHI_DOMAIN' ) ) {
    define( 'SATHI_DOMAIN', 'sathi-agentic-ai' );
}
if ( ! defined( 'SATHI_PATH' ) ) {
    define( 'SATHI_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SATHI_URL' ) ) {
    define( 'SATHI_URL', 'http://example.com/wp-content/plugins/sathi/' );
}
if ( ! defined( 'SATHI_SRC' ) ) {
    define( 'SATHI_SRC', SATHI_PATH . 'src/' );
}
if ( ! defined( 'SATHI_DEFAULT_TIMEOUT' ) ) {
    define( 'SATHI_DEFAULT_TIMEOUT', 300 );
}
if ( ! defined( 'SATHI_STREAM_CHUNK_SIZE' ) ) {
    define( 'SATHI_STREAM_CHUNK_SIZE', 1024 );
}
if ( ! defined( 'SATHI_MAX_HISTORY_LENGTH' ) ) {
    define( 'SATHI_MAX_HISTORY_LENGTH', 50 );
}

// Load the fallback PSR-4 autoloader from the main plugin file
require_once SATHI_PATH . 'sathi-agentic-ai.php';

// Composer autoloader (for PHPUnit)
$autoloader = SATHI_PATH . 'vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
    require_once $autoloader;
}
