<?php
/**
 * Usage & Cost Tracker — per-provider token counting, cost estimation, spend caps.
 *
 * @package RaiLabs\Sathi\Support
 */

namespace RaiLabs\Sathi\Support;

class UsageTracker {

    private string $table;

    /** @var array<string, array{input: float, output: float}> Price per 1K tokens */
    private const PRICING = [
        'openai' => [
            'gpt-4o'          => [ 'input' => 0.0025, 'output' => 0.0100 ],
            'gpt-4o-mini'     => [ 'input' => 0.00015,'output' => 0.00060 ],
            'gpt-4-turbo'     => [ 'input' => 0.0100, 'output' => 0.0300 ],
        ],
        'anthropic' => [
            'claude-sonnet-4-6' => [ 'input' => 0.0030, 'output' => 0.0150 ],
            'claude-haiku-4-5'  => [ 'input' => 0.0008, 'output' => 0.0040 ],
        ],
        'google' => [
            'gemini-2.5-pro'   => [ 'input' => 0.00125,'output' => 0.0050 ],
            'gemini-2.5-flash' => [ 'input' => 0.00015,'output' => 0.00060 ],
        ],
    ];

    /** @var float|null Monthly spend cap (0 = unlimited) */
    private ?float $monthly_cap;

    public function __construct() {
        global $wpdb;
        $this->table      = $wpdb->prefix . 'sathi_usage';
        $this->monthly_cap = (float) get_option( 'sathi_cost_cap_monthly', 0 );

        $this->maybe_create_table();
    }

    /**
     * Ensure the usage tracking table exists.
     */
    private function maybe_create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(32) NOT NULL,
            model VARCHAR(128) DEFAULT NULL,
            task_type ENUM('chat','embed','image','moderation') NOT NULL DEFAULT 'chat',
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            estimated_cost DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
            conversation_id BIGINT UNSIGNED DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_provider_date (provider, created_at),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Record a usage event.
     *
     * @param string $provider   Provider key.
     * @param string $model      Model name.
     * @param string $task       Task type.
     * @param int    $in_tokens  Input tokens used.
     * @param int    $out_tokens Output tokens generated.
     * @param int|null $conv_id  Related conversation ID.
     * @param int|null $user_id  WP user ID.
     */
    public function record(
        string $provider,
        string $model,
        string $task,
        int $in_tokens,
        int $out_tokens,
        ?int $conv_id = null,
        ?int $user_id = null
    ): void {
        global $wpdb;

        $cost = $this->estimate_cost( $provider, $model, $in_tokens, $out_tokens );

        $wpdb->insert( $this->table, [
            'provider'        => $provider,
            'model'           => $model,
            'task_type'       => $task,
            'input_tokens'    => $in_tokens,
            'output_tokens'   => $out_tokens,
            'estimated_cost'  => $cost,
            'conversation_id' => $conv_id,
            'user_id'         => $user_id,
            'created_at'      => current_time( 'mysql' ),
        ] );

        // Update running monthly total
        $this->update_monthly_spend( $cost );
    }

    /**
     * Estimate cost based on provider pricing.
     */
    public function estimate_cost( string $provider, string $model, int $in_tokens, int $out_tokens ): float {
        $pricing = self::PRICING[ $provider ][ $model ] ?? self::PRICING[ $provider ][ array_key_first( self::PRICING[ $provider ] ?? [] ) ] ?? null;

        if ( ! $pricing ) {
            // Fallback for unknown providers: $0.002/1K input, $0.01/1K output
            $pricing = [ 'input' => 0.002, 'output' => 0.01 ];
        }

        return round(
            ( $in_tokens / 1000 ) * $pricing['input'] + ( $out_tokens / 1000 ) * $pricing['output'],
            6
        );
    }

    /**
     * Check if the monthly cap has been reached.
     *
     * @return bool True if further requests should be blocked.
     */
    public function is_cap_reached(): bool {
        if ( $this->monthly_cap <= 0 ) {
            return false;
        }
        $spend = $this->get_monthly_spend();
        return $spend >= $this->monthly_cap;
    }

    /**
     * Get current month's total spend.
     */
    public function get_monthly_spend(): float {
        return (float) get_transient( 'sathi_monthly_spend' );
    }

    /**
     * Increment the monthly spend tracker.
     */
    private function update_monthly_spend( float $cost ): void {
        $current = $this->get_monthly_spend();
        set_transient( 'sathi_monthly_spend', $current + $cost, MONTH_IN_SECONDS );
    }

    /**
     * Get usage statistics for a date range.
     *
     * @param  string $from  Date string (Y-m-d).
     * @param  string $to    Date string (Y-m-d).
     * @return array
     */
    public function get_stats( string $from = '', string $to = '' ): array {
        global $wpdb;

        $from = $from ?: gmdate( 'Y-m-01' ); // First of current month
        $to   = $to   ?: gmdate( 'Y-m-d' );

        // Total stats
        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) as requests, SUM(input_tokens) as total_in, SUM(output_tokens) as total_out, SUM(estimated_cost) as total_cost FROM {$this->table} WHERE created_at >= %s AND created_at <= %s",
            $from, $to . ' 23:59:59'
        ), ARRAY_A );

        // Per-provider breakdown
        $by_provider = $wpdb->get_results( $wpdb->prepare(
            "SELECT provider, COUNT(*) as requests, SUM(input_tokens) as total_in, SUM(output_tokens) as total_out, SUM(estimated_cost) as total_cost FROM {$this->table} WHERE created_at >= %s AND created_at <= %s GROUP BY provider ORDER BY total_cost DESC",
            $from, $to . ' 23:59:59'
        ), ARRAY_A );

        // Daily breakdown (for charts)
        $daily = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as date, SUM(estimated_cost) as cost, SUM(input_tokens + output_tokens) as tokens FROM {$this->table} WHERE created_at >= %s AND created_at <= %s GROUP BY DATE(created_at) ORDER BY date ASC",
            $from, $to . ' 23:59:59'
        ), ARRAY_A );

        return [
            'total_requests' => (int) ( $totals['requests'] ?? 0 ),
            'total_input_tokens'  => (int) ( $totals['total_in'] ?? 0 ),
            'total_output_tokens' => (int) ( $totals['total_out'] ?? 0 ),
            'total_cost'          => (float) ( $totals['total_cost'] ?? 0 ),
            'monthly_cap'         => $this->monthly_cap,
            'cap_reached'         => $this->is_cap_reached(),
            'by_provider'         => $by_provider ?: [],
            'daily'               => $daily ?: [],
        ];
    }

    /**
     * Get available pricing data for UI display.
     */
    public function get_pricing_table(): array {
        return self::PRICING;
    }
}
