<?php
/**
 * Persona Registry — CRUD for agent mascots and custom personas.
 *
 * @package NeerMedia\Sathi\Personas
 */

namespace NeerMedia\Sathi\Personas;

class PersonaRegistry {

    /** @var string Table name */
    private string $table;

    /** @var array Predefined mascot definitions */
    private array $defaults;

    public function __construct() {
        global $wpdb;
        $this->table    = $wpdb->prefix . 'sathi_personas';
        $this->defaults = $this->define_defaults();
    }

    /**
     * Register hooks and post type.
     */
    public function register(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_filter( 'sathi_personas', [ $this, 'merge_defaults' ] );
    }

    /**
     * Register the sathi_persona custom post type for admin editing.
     */
    public function register_post_type(): void {
        register_post_type( 'sathi_persona', [
            'labels'              => [
                'name'          => __( 'Personas', 'sathi-agentic-ai' ),
                'singular_name' => __( 'Persona', 'sathi-agentic-ai' ),
                'add_new'       => __( 'Add Persona', 'sathi-agentic-ai' ),
                'add_new_item'  => __( 'Add New Persona', 'sathi-agentic-ai' ),
                'edit_item'     => __( 'Edit Persona', 'sathi-agentic-ai' ),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'sathi-settings',
            'show_in_rest'        => true,
            'supports'            => [ 'title', 'excerpt', 'thumbnail' ],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );
    }

    /**
     * Define the 6 predefined mascot personas.
     */
    private function define_defaults(): array {
        return [
            'sathi-guru' => [
                'slug'        => 'sathi-guru',
                'name'        => __( 'Sathi Guru', 'sathi-agentic-ai' ),
                'role'        => __( 'Mentor', 'sathi-agentic-ai' ),
                'description' => __( 'Wise, patient, and philosophical support agent who guides users with thoughtful questions and deep understanding.', 'sathi-agentic-ai' ),
                'tone'        => __( 'calm and reflective', 'sathi-agentic-ai' ),
                'style'       => __( 'Uses metaphors and storytelling to explain complex topics. Asks clarifying questions before answering.', 'sathi-agentic-ai' ),
                'avatar'      => '🎓',
                'color'       => '#6366f1',
                'is_default'  => true,
            ],
            'sathi-ninja' => [
                'slug'        => 'sathi-ninja',
                'name'        => __( 'Sathi Ninja', 'sathi-agentic-ai' ),
                'role'        => __( 'Efficiency Expert', 'sathi-agentic-ai' ),
                'description' => __( 'Fast, precise, no-nonsense support ninja who solves problems in minimum steps.', 'sathi-agentic-ai' ),
                'tone'        => __( 'direct and crisp', 'sathi-agentic-ai' ),
                'style'       => __( 'Short bullets, numbered steps, actionable takeaways. Zero fluff. Gets straight to the point.', 'sathi-agentic-ai' ),
                'avatar'      => '🥷',
                'color'       => '#0ea5e9',
                'is_default'  => true,
            ],
            'sathi-buddy' => [
                'slug'        => 'sathi-buddy',
                'name'        => __( 'Sathi Buddy', 'sathi-agentic-ai' ),
                'role'        => __( 'Friendly Companion', 'sathi-agentic-ai' ),
                'description' => __( 'Cheerful, empathetic friend who makes support feel like a conversation with a pal.', 'sathi-agentic-ai' ),
                'tone'        => __( 'warm and encouraging', 'sathi-agentic-ai' ),
                'style'       => __( 'Conversational, uses emoji liberally, celebrates wins, checks in on how the user is feeling.', 'sathi-agentic-ai' ),
                'avatar'      => '🐶',
                'color'       => '#f59e0b',
                'is_default'  => true,
            ],
            'sathi-sage' => [
                'slug'        => 'sathi-sage',
                'name'        => __( 'Sathi Sage', 'sathi-agentic-ai' ),
                'role'        => __( 'Knowledge Oracle', 'sathi-agentic-ai' ),
                'description' => __( 'Encyclopedic, precise, data-driven expert who cites sources and explains reasoning.', 'sathi-agentic-ai' ),
                'tone'        => __( 'authoritative yet approachable', 'sathi-agentic-ai' ),
                'style'       => __( 'Structured answers with headings, numbered points, references, and clear logic chains. Uses italics for emphasis.', 'sathi-agentic-ai' ),
                'avatar'      => '🦉',
                'color'       => '#10b981',
                'is_default'  => true,
            ],
            'sathi-spark' => [
                'slug'        => 'sathi-spark',
                'name'        => __( 'Sathi Spark', 'sathi-agentic-ai' ),
                'role'        => __( 'Creative Catalyst', 'sathi-agentic-ai' ),
                'description' => __( 'Energetic, imaginative, and bold — turns support into inspiration.', 'sathi-agentic-ai' ),
                'tone'        => __( 'enthusiastic and inventive', 'sathi-agentic-ai' ),
                'style'       => __( 'Brainstorming format, "what if" scenarios, bold ideas. Uses visual language and emoji heavily.', 'sathi-agentic-ai' ),
                'avatar'      => '⚡',
                'color'       => '#ec4899',
                'is_default'  => true,
            ],
            'sathi-guardian' => [
                'slug'        => 'sathi-guardian',
                'name'        => __( 'Sathi Guardian', 'sathi-agentic-ai' ),
                'role'        => __( 'Security Sentinel', 'sathi-agentic-ai' ),
                'description' => __( 'Vigilant, precise, and protective — specializes in security, privacy, and compliance questions.', 'sathi-agentic-ai' ),
                'tone'        => __( 'serious and reassuring', 'sathi-agentic-ai' ),
                'style'       => __( 'Checklist format, references standards (GDPR, PCI, etc.), flags risks clearly. Uses 🛡️ and ⚠️ indicators.', 'sathi-agentic-ai' ),
                'avatar'      => '🛡️',
                'color'       => '#ef4444',
                'is_default'  => true,
            ],
        ];
    }

    /**
     * Get all predefined personas.
     */
    public function get_defaults(): array {
        return $this->defaults;
    }

    /**
     * Merge predefined personas with custom DB personas.
     */
    public function merge_defaults( array $personas ): array {
        return array_merge( $this->defaults, $personas );
    }

    /**
     * Get all active personas (defaults + custom from DB).
     *
     * @return array
     */
    public function get_all(): array {
        global $wpdb;

        $custom = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY is_predefined DESC, name ASC",
            ARRAY_A
        );

        $result = $this->defaults;

        foreach ( ( $custom ?: [] ) as $row ) {
            if ( isset( $result[ $row['slug'] ] ) ) {
                // Custom overrides predefined
                $result[ $row['slug'] ] = array_merge( $result[ $row['slug'] ], $row );
            } else {
                $result[ $row['slug'] ] = $row;
            }
        }

        return array_values( $result );
    }

    /**
     * Get a single persona by slug.
     *
     * @param  string $slug
     * @return array|null
     */
    public function get( string $slug ): ?array {
        // Check defaults first
        if ( isset( $this->defaults[ $slug ] ) ) {
            $persona = $this->defaults[ $slug ];

            // Check for custom override in DB
            global $wpdb;
            $override = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE slug = %s AND is_active = 1",
                $slug
            ), ARRAY_A );

            if ( $override ) {
                $persona = array_merge( $persona, $override );
            }

            return $persona;
        }

        // Check DB for purely custom personas
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE slug = %s AND is_active = 1",
            $slug
        ), ARRAY_A ) ?: null;
    }

    /**
     * Create a new custom persona.
     *
     * @param  array $data
     * @return int|false Inserted row ID or false on failure.
     */
    public function create( array $data ) {
        global $wpdb;

        $slug = sanitize_title( $data['name'] ?? 'custom-' . uniqid() );

        $result = $wpdb->insert( $this->table, [
            'slug'          => $slug,
            'name'          => sanitize_text_field( $data['name'] ?? '' ),
            'role'          => sanitize_text_field( $data['role'] ?? '' ),
            'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
            'tone'          => sanitize_text_field( $data['tone'] ?? '' ),
            'style'         => sanitize_textarea_field( $data['style'] ?? '' ),
            'avatar'        => sanitize_text_field( $data['avatar'] ?? '🤖' ),
            'color'         => sanitize_hex_color( $data['color'] ?? '#7c3aed' ),
            'system_prompt' => $data['system_prompt'] ?? null,
            'is_predefined' => 0,
            'is_active'     => 1,
            'user_id'       => get_current_user_id(),
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ] );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing persona.
     *
     * @param  string $slug
     * @param  array  $data
     * @return bool
     */
    public function update( string $slug, array $data ): bool {
        global $wpdb;

        $update = [ 'updated_at' => current_time( 'mysql' ) ];

        foreach ( [ 'name', 'role', 'description', 'tone', 'style', 'avatar', 'color', 'system_prompt' ] as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $update[ $field ] = sanitize_textarea_field( $data[ $field ] );
            }
        }

        if ( isset( $data['is_active'] ) ) {
            $update['is_active'] = (int) $data['is_active'];
        }

        return (bool) $wpdb->update( $this->table, $update, [ 'slug' => $slug ] );
    }

    /**
     * Soft-delete a persona.
     *
     * @param  string $slug
     * @return bool
     */
    public function delete( string $slug ): bool {
        // Never delete predefined personas
        if ( isset( $this->defaults[ $slug ] ) ) {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->update(
            $this->table,
            [ 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ],
            [ 'slug' => $slug ]
        );
    }
}
