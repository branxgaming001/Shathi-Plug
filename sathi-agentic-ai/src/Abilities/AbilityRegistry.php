<?php
/**
 * Ability Registry — Sathi's tool/ability system (independent of WP 7 Abilities).
 *
 * Auto-discovers registered abilities, gates by capability, persists enable/disable
 * state, formats for provider consumption, and executes single or chained calls.
 *
 * @package NeerMedia\Sathi\Abilities
 */

namespace NeerMedia\Sathi\Abilities;

use NeerMedia\Sathi\Core\Data\FunctionResult;

class AbilityRegistry {

    /** @var self|null Singleton */
    private static ?self $instance = null;

    /** @var array<string, array> Full ability definitions keyed by name */
    private array $abilities = [];

    /** @var array<string, bool> Cached disabled set from WP option */
    private array $disabled_cache = [];

    /** @var bool Whether the disabled list has been loaded */
    private bool $disabled_loaded = false;

    /** @var string Option key for disabled abilities list */
    private const OPTION_DISABLED = 'sathi_disabled_abilities';

    // ── Singleton ──────────────────────────────────────────────────

    /**
     * Get the singleton instance.
     */
    public static function instance(): self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the singleton (useful in tests).
     */
    public static function reset(): void {
        self::$instance = null;
    }

    // ── Registration ───────────────────────────────────────────────

    /**
     * Register an ability (tool/function for the AI to call).
     *
     * @param  string $name       Unique ability name (e.g. "sathi_wp_search_posts").
     * @param  array  $definition {
     *     label:       string  Human-readable label for admin UI.
     *     description: string  Natural-language description for the LLM.
     *     schema:      array   JSON Schema for parameters.
     *     callback:    callable Callable that receives (array $args): array.
     *     capability:  string  WP capability required to execute.
     *     category:    string  Optional grouping (e.g. "woocommerce", "wordpress").
     * }
     */
    public function register( string $name, array $definition ): void {
        // Ensure required keys exist with sensible defaults
        $this->abilities[ $name ] = array_merge( [
            'label'       => $name,
            'description' => '',
            'schema'      => [ 'type' => 'object', 'properties' => (object) [] ],
            'callback'    => null,
            'capability'  => 'read',
            'category'    => 'general',
        ], $definition );
    }

    /**
     * Bulk-register abilities from an associative array.
     *
     * @param array<string, array> $abilities
     */
    public function register_many( array $abilities ): void {
        foreach ( $abilities as $name => $def ) {
            $this->register( $name, $def );
        }
    }

    /**
     * Unregister an ability by name.
     */
    public function unregister( string $name ): void {
        unset( $this->abilities[ $name ] );
    }

    // ── Auto-Discovery / Querying ──────────────────────────────────

    /**
     * Get all registered abilities with full metadata.
     *
     * @param  string|null $category Filter by category (null = all).
     * @return array<string, array>
     */
    public function get_all( ?string $category = null ): array {
        if ( $category === null ) {
            return $this->abilities;
        }

        return array_filter( $this->abilities, function ( array $def ) use ( $category ): bool {
            return ( $def['category'] ?? 'general' ) === $category;
        } );
    }

    /**
     * Get only enabled abilities (respects disable toggles and capability checks).
     *
     * @param  string|null $category Filter by category.
     * @return array<string, array>
     */
    public function get_enabled( ?string $category = null ): array {
        $this->load_disabled();

        $enabled = [];
        foreach ( $this->abilities as $name => $def ) {
            // Skip if manually disabled by admin
            if ( isset( $this->disabled_cache[ $name ] ) && $this->disabled_cache[ $name ] ) {
                continue;
            }
            // Skip if current user lacks capability
            if ( isset( $def['capability'] ) && ! current_user_can( $def['capability'] ) ) {
                continue;
            }
            // Skip if category filter doesn't match
            if ( $category !== null && ( $def['category'] ?? 'general' ) !== $category ) {
                continue;
            }
            $enabled[ $name ] = $def;
        }

        return $enabled;
    }

    /**
     * Get a single ability definition.
     *
     * @return array|null Null if not found.
     */
    public function get( string $name ): ?array {
        return $this->abilities[ $name ] ?? null;
    }

    /**
     * Check if an ability exists (registered).
     */
    public function has( string $name ): bool {
        return isset( $this->abilities[ $name ] );
    }

    /**
     * Get all registered ability names.
     *
     * @return string[]
     */
    public function names(): array {
        return array_keys( $this->abilities );
    }

    /**
     * Count registered abilities.
     */
    public function count(): int {
        return count( $this->abilities );
    }

    /**
     * Count enabled abilities.
     */
    public function count_enabled(): int {
        return count( $this->get_enabled() );
    }

    // ── Enable / Disable ───────────────────────────────────────────

    /**
     * Load disabled set from the WordPress option.
     */
    private function load_disabled(): void {
        if ( $this->disabled_loaded ) {
            return;
        }

        $raw = get_option( self::OPTION_DISABLED, [] );
        $this->disabled_cache = is_array( $raw ) ? $raw : [];
        $this->disabled_loaded = true;
    }

    /**
     * Persist disabled set to the WordPress option.
     */
    private function save_disabled(): void {
        update_option( self::OPTION_DISABLED, $this->disabled_cache, false );
    }

    /**
     * Disable an ability by name.
     */
    public function disable( string $name ): void {
        $this->load_disabled();
        $this->disabled_cache[ $name ] = true;
        $this->save_disabled();
    }

    /**
     * Enable an ability by name.
     */
    public function enable( string $name ): void {
        $this->load_disabled();
        unset( $this->disabled_cache[ $name ] );
        $this->save_disabled();
    }

    /**
     * Check whether an ability is manually disabled.
     */
    public function is_disabled( string $name ): bool {
        $this->load_disabled();
        return $this->disabled_cache[ $name ] ?? false;
    }

    /**
     * Check whether an ability is enabled (registered, not disabled, user has capability).
     */
    public function is_enabled( string $name ): bool {
        if ( ! $this->has( $name ) ) {
            return false;
        }
        if ( $this->is_disabled( $name ) ) {
            return false;
        }
        $def = $this->abilities[ $name ];
        if ( isset( $def['capability'] ) && ! current_user_can( $def['capability'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Disable all abilities in a category.
     */
    public function disable_category( string $category ): void {
        $this->load_disabled();
        foreach ( $this->abilities as $name => $def ) {
            if ( ( $def['category'] ?? 'general' ) === $category ) {
                $this->disabled_cache[ $name ] = true;
            }
        }
        $this->save_disabled();
    }

    /**
     * Enable all abilities in a category.
     */
    public function enable_category( string $category ): void {
        $this->load_disabled();
        foreach ( $this->abilities as $name => $def ) {
            if ( ( $def['category'] ?? 'general' ) === $category ) {
                unset( $this->disabled_cache[ $name ] );
            }
        }
        $this->save_disabled();
    }

    /**
     * Get list of disabled ability names.
     *
     * @return string[]
     */
    public function get_disabled_names(): array {
        $this->load_disabled();
        return array_keys( array_filter( $this->disabled_cache ) );
    }

    // ── Provider Tool Formatting ───────────────────────────────────

    /**
     * Format enabled abilities as provider-agnostic tool definitions.
     *
     * This is the canonical format consumed by AgentManager and ProviderInterface::format_tools().
     * Each tool includes { name, description, parameters, callback? }.
     *
     * @param  bool $include_callbacks Whether to include PHP callables (false for serialization / external APIs).
     * @param  bool $enabled_only      Whether to return only enabled abilities.
     * @return array<int, array>
     */
    public function to_provider_tools( bool $include_callbacks = false, bool $enabled_only = true ): array {
        $source = $enabled_only ? $this->get_enabled() : $this->abilities;

        $tools = [];
        foreach ( $source as $name => $def ) {
            $tool = [
                'name'        => $name,
                'description' => $def['description'] ?? '',
                'parameters'  => $def['schema'] ?? [
                    'type'       => 'object',
                    'properties' => (object) [],
                ],
            ];

            if ( $include_callbacks && isset( $def['callback'] ) ) {
                $tool['callback'] = $def['callback'];
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * Format all tools with callbacks for agent consumption (alias with defaults).
     *
     * @return array<int, array>
     */
    public function to_agent_tools(): array {
        return $this->to_provider_tools( include_callbacks: true, enabled_only: true );
    }

    /**
     * Format tools for REST / JSON serialization (no callbacks).
     *
     * @return array<int, array>
     */
    public function to_rest_tools(): array {
        return $this->to_provider_tools( include_callbacks: false, enabled_only: true );
    }

    // ── Execution ──────────────────────────────────────────────────

    /**
     * Execute a single named ability.
     *
     * @param  string $name Ability name.
     * @param  array  $args Arguments to pass to the callback.
     * @return mixed        Return value from the callback.
     * @throws \RuntimeException If ability is not found, not callable, not enabled, or lacking capability.
     */
    public function execute( string $name, array $args ) {
        if ( ! isset( $this->abilities[ $name ] ) ) {
            throw new \RuntimeException(
                sprintf( __( 'Ability "%s" is not registered.', 'sathi-agentic-ai' ), $name )
            );
        }

        $def = $this->abilities[ $name ];

        // Respect disable toggle
        if ( $this->is_disabled( $name ) ) {
            throw new \RuntimeException(
                sprintf( __( 'Ability "%s" is disabled.', 'sathi-agentic-ai' ), $name )
            );
        }

        // Capability gate
        if ( isset( $def['capability'] ) && ! current_user_can( $def['capability'] ) ) {
            throw new \RuntimeException(
                sprintf( __( 'Insufficient permissions for ability "%s".', 'sathi-agentic-ai' ), $name )
            );
        }

        $callback = $def['callback'] ?? null;

        if ( ! is_callable( $callback ) ) {
            throw new \RuntimeException(
                sprintf( __( 'Ability "%s" has no callable callback.', 'sathi-agentic-ai' ), $name )
            );
        }

        // Validate arguments against schema if provided
        $args = $this->apply_schema_defaults( $args, $def['schema'] ?? [] );

        return call_user_func( $callback, $args );
    }

    /**
     * Execute multiple abilities in sequence and return all results.
     *
     * Each call is a dict: { name: string, args?: array }.
     * Returns an array of result dicts: { name, success, data, error? }.
     *
     * Execution continues even if one call fails — failures are captured as error entries.
     *
     * @param  array<int, array{name: string, args?: array}> $calls
     * @return array<int, array{name: string, success: bool, data?: mixed, error?: string}>
     */
    public function execute_chain( array $calls ): array {
        $results = [];

        foreach ( $calls as $index => $call ) {
            $name = $call['name'] ?? '';
            $args = $call['args'] ?? [];

            if ( $name === '' ) {
                $results[] = [
                    'name'    => "(index {$index})",
                    'success' => false,
                    'error'   => __( 'Missing ability name in chain call.', 'sathi-agentic-ai' ),
                ];
                continue;
            }

            try {
                $data = $this->execute( $name, $args );
                $results[] = [
                    'name'    => $name,
                    'success' => true,
                    'data'    => $data,
                ];
            } catch ( \Throwable $e ) {
                $results[] = [
                    'name'    => $name,
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Execute abilities concurrently (where supported) or sequentially.
     *
     * This is an alias for execute_chain in the current implementation but provides
     * a hook point for future async execution.
     *
     * @param  array<int, array{name: string, args?: array}> $calls
     * @return array<int, array{name: string, success: bool, data?: mixed, error?: string}>
     */
    public function execute_batch( array $calls ): array {
        /**
         * Filter: sathi_execute_batch_calls
         *
         * Allows plugins to modify the batch before execution or implement
         * true concurrent execution via a custom runner.
         *
         * @param array $calls  The calls to execute.
         * @param self  $registry This registry instance.
         */
        $calls = apply_filters( 'sathi_execute_batch_calls', $calls, $this );

        return $this->execute_chain( $calls );
    }

    // ── Internal Helpers ───────────────────────────────────────────

    /**
     * Apply default values from JSON Schema to the arguments array.
     *
     * Merges schema defaults for any property not present in args.
     *
     * @param  array $args   Supplied arguments.
     * @param  array $schema JSON Schema definition.
     * @return array         Args with defaults applied.
     */
    private function apply_schema_defaults( array $args, array $schema ): array {
        $properties = $schema['properties'] ?? [];

        if ( ! is_array( $properties ) ) {
            return $args;
        }

        foreach ( $properties as $prop_name => $prop_def ) {
            if ( ! isset( $args[ $prop_name ] ) && array_key_exists( 'default', $prop_def ) ) {
                $args[ $prop_name ] = $prop_def['default'];
            }
        }

        return $args;
    }

    /**
     * Get summary statistics for the admin UI.
     *
     * @return array{total: int, enabled: int, disabled: int, categories: array<string, int>}
     */
    public function get_stats(): array {
        $this->load_disabled();

        $total    = count( $this->abilities );
        $disabled = count( array_intersect_key( $this->disabled_cache, $this->abilities ) );
        $enabled  = $total - $disabled;

        $categories = [];
        foreach ( $this->abilities as $def ) {
            $cat = $def['category'] ?? 'general';
            $categories[ $cat ] = ( $categories[ $cat ] ?? 0 ) + 1;
        }

        return [
            'total'      => $total,
            'enabled'    => $enabled,
            'disabled'   => $disabled,
            'categories' => $categories,
        ];
    }

    /**
     * Prevent cloning of singleton.
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     */
    public function __wakeup() {
        throw new \RuntimeException( 'Cannot unserialize singleton' );
    }

    /**
     * Private constructor — use instance().
     */
    private function __construct() {}
}
