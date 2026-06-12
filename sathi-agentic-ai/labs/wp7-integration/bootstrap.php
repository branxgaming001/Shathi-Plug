<?php
/**
 * WP 7 Integration Bootstrap — gated loader.
 *
 * Only fires when wp_ai_client_prompt() exists (WP >= 7.0).
 * All WP 7 integration code lives under /labs so the core plugin
 * stays stable while the framework API evolves.
 *
 * @package RaiLabs\Sathi\Labs\WP7Integration
 */

namespace RaiLabs\Sathi\Labs\WP7Integration;

// Gate: only load if WP 7 AI framework is present
if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
    return;
}

add_action( 'wp_connectors_init', function () {
    require_once __DIR__ . '/connectors/ConnectorRegistration.php';
    ( new Connectors\ConnectorRegistration() )->register();
} );

add_action( 'init', function () {
    require_once __DIR__ . '/abilities/AbilityMapper.php';
    ( new Abilities\AbilityMapper() )->register();
} );

add_action( 'init', function () {
    require_once __DIR__ . '/providers/SathiProvider.php';
    ( new Providers\SathiProvider() )->register();
} );

do_action( 'sathi_wp7_loaded' );
