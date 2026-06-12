<?php
/**
 * Sathi Provider — bridges wp_ai_client_prompt() through Sathi's provider layer.
 *
 * @package RaiLabs\Sathi\Labs\WP7Integration\Providers
 */

namespace RaiLabs\Sathi\Labs\WP7Integration\Providers;

class SathiProvider {

    public function register(): void {
        if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
            return;
        }

        add_filter( 'wp_ai_client_prevent_prompt', [ $this, 'check_cost_cap' ], 10, 2 );

        add_action( 'wp_ai_client_before_prompt', [ $this, 'log_prompt' ] );
        add_action( 'wp_ai_client_after_prompt', [ $this, 'log_response' ] );
        add_action( 'wp_ai_client_error', [ $this, 'log_error' ] );
    }

    /**
     * Check Sathi cost cap before allowing prompt.
     *
     * @param  bool  $prevent
     * @param  array $request
     * @return bool
     */
    public function check_cost_cap( bool $prevent, array $request ): bool {
        if ( $prevent ) {
            return $prevent;
        }

        $cap = get_option( 'sathi_cost_cap_monthly', 0 );
        if ( $cap <= 0 ) {
            return false; // No cap set
        }

        $monthly_spend = (float) get_transient( 'sathi_monthly_spend' );
        if ( $monthly_spend >= $cap ) {
            return true; // Block — cap reached
        }

        return false;
    }

    /**
     * Log an outgoing prompt for cost tracking.
     */
    public function log_prompt( array $data ): void {
        $logger = new \RaiLabs\Sathi\Support\Logger();
        $logger->info( 'WP7 AI prompt sent', [
            'model'   => $data['model'] ?? 'unknown',
            'tokens'  => $data['token_count'] ?? 0,
        ] );
    }

    /**
     * Log a received response for cost tracking.
     */
    public function log_response( array $data ): void {
        $logger = new \RaiLabs\Sathi\Support\Logger();
        $logger->info( 'WP7 AI response received', [
            'tokens' => $data['token_count'] ?? 0,
        ] );
    }

    /**
     * Log an AI client error.
     */
    public function log_error( array $data ): void {
        $logger = new \RaiLabs\Sathi\Support\Logger();
        $logger->error( 'WP7 AI client error', [
            'error' => $data['error'] ?? 'Unknown error',
        ] );
    }
}
