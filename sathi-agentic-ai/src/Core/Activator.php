<?php
/**
 * Activation / deactivation hooks.
 *
 * @package RaiLabs\Sathi\Core
 */

namespace RaiLabs\Sathi\Core;

use RaiLabs\Sathi\Core\Database\Schema;

class Activator {

    /**
     * Fires on plugin activation.
     */
    public static function activate(): void {
        // Create / update database tables
        Schema::create_tables();

        // Register default options
        self::seed_options();

        // Register persona defaults
        self::seed_personas();

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set version marker
        update_option( 'sathi_db_version', SATHI_VERSION );

        do_action( 'sathi_activated' );
    }

    /**
     * Fires on plugin deactivation.
     */
    public static function deactivate(): void {
        // Clear scheduled hooks
        wp_clear_scheduled_hook( 'sathi_knowledge_crawl' );
        wp_clear_scheduled_hook( 'sathi_memory_prune' );

        // Flush rewrite rules
        flush_rewrite_rules();

        do_action( 'sathi_deactivated' );
    }

    /**
     * Insert sane default options on fresh install.
     */
    private static function seed_options(): void {
        $defaults = [
            'sathi_default_provider'        => 'openai',
            'sathi_enabled_providers'       => [ 'openai' ],
            'sathi_chat_enabled'            => true,
            'sathi_streaming_enabled'        => true,
            'sathi_max_history'             => SATHI_MAX_HISTORY_LENGTH,
            'sathi_default_timeout'         => SATHI_DEFAULT_TIMEOUT,
            'sathi_log_level'               => 'warning',
            'sathi_floating_widget'         => true,
            'sathi_floating_position'       => 'bottom-right',
            'sathi_accent_color'            => '#1B3A6B',
            'sathi_knowledge_auto_crawl'    => true,
            'sathi_knowledge_crawl_interval'=> 'daily',
            'sathi_memory_enabled'          => true,
            'sathi_memory_ttl_days'         => 90,
        ];

        foreach ( $defaults as $key => $value ) {
            add_option( $key, $value );
        }
    }

    /**
     * Seed the six predefined mascot personas.
     */
    private static function seed_personas(): void {
        if ( get_option( 'sathi_personas_seeded' ) ) {
            return;
        }

        $personas = [
            [
                'id'          => 'sathi-guru',
                'name'        => 'Sathi Guru',
                'role'        => 'Mentor',
                'description' => 'Wise, patient, and philosophical support agent who guides users with thoughtful questions.',
                'tone'        => 'calm and reflective',
                'style'       => 'Uses metaphors and storytelling to explain complex topics.',
                'avatar'      => '🎓',
                'color'       => '#6366f1',
                'is_default'  => true,
            ],
            [
                'id'          => 'sathi-ninja',
                'name'        => 'Sathi Ninja',
                'role'        => 'Efficiency Expert',
                'description' => 'Fast, precise, no-nonsense support ninja who solves problems in minimum steps.',
                'tone'        => 'direct and crisp',
                'style'       => 'Short bullets, actionable steps, zero fluff.',
                'avatar'      => '🥷',
                'color'       => '#0ea5e9',
                'is_default'  => false,
            ],
            [
                'id'          => 'sathi-buddy',
                'name'        => 'Sathi Buddy',
                'role'        => 'Friendly Companion',
                'description' => 'Cheerful, empathetic friend who makes support feel like a conversation with a pal.',
                'tone'        => 'warm and encouraging',
                'style'       => 'Conversational, uses emoji liberally, celebrates wins.',
                'avatar'      => '🐶',
                'color'       => '#f59e0b',
                'is_default'  => false,
            ],
            [
                'id'          => 'sathi-sage',
                'name'        => 'Sathi Sage',
                'role'        => 'Knowledge Oracle',
                'description' => 'Encyclopedic, precise, data-driven expert who cites sources and explains reasoning.',
                'tone'        => 'authoritative yet approachable',
                'style'       => 'Structured answers with headings, evidence, and clear logic chains.',
                'avatar'      => '🦉',
                'color'       => '#10b981',
                'is_default'  => false,
            ],
            [
                'id'          => 'sathi-spark',
                'name'        => 'Sathi Spark',
                'role'        => 'Creative Catalyst',
                'description' => 'Energetic, imaginative, and bold — turns support into inspiration.',
                'tone'        => 'enthusiastic and inventive',
                'style'       => 'Brainstorming format, "what if" scenarios, visual language.',
                'avatar'      => '⚡',
                'color'       => '#ec4899',
                'is_default'  => false,
            ],
            [
                'id'          => 'sathi-guardian',
                'name'        => 'Sathi Guardian',
                'role'        => 'Security Sentinel',
                'description' => 'Vigilant, precise, and protective — specializes in security, privacy, and compliance questions.',
                'tone'        => 'serious and reassuring',
                'style'       => 'Checklist format, references standards, flags risks clearly.',
                'avatar'      => '🛡️',
                'color'       => '#ef4444',
                'is_default'  => false,
            ],
        ];

        foreach ( $personas as $persona ) {
            wp_insert_post( [
                'post_type'    => 'sathi_persona',
                'post_title'   => $persona['name'],
                'post_name'    => $persona['id'],
                'post_status'  => 'publish',
                'post_excerpt' => $persona['description'],
                'meta_input'   => [
                    '_sathi_role'  => $persona['role'],
                    '_sathi_tone'  => $persona['tone'],
                    '_sathi_style' => $persona['style'],
                    '_sathi_avatar'=> $persona['avatar'],
                    '_sathi_color' => $persona['color'],
                    '_sathi_is_default' => $persona['is_default'],
                ],
            ] );
        }

        update_option( 'sathi_personas_seeded', true );
    }
}
