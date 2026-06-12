<?php
/**
 * Content Moderator — input/output safety filtering.
 *
 * @package RaiLabs\Sathi\Support
 */

namespace RaiLabs\Sathi\Support;

class ContentModerator {

    /** @var string[] Blocked patterns (regex) */
    private array $blocked_patterns = [];

    /** @var string[] Warning patterns (logged but allowed) */
    private array $warning_patterns = [];

    /** @var bool Whether moderation is enabled */
    private bool $enabled;

    public function __construct() {
        $this->enabled = (bool) get_option( 'sathi_moderation_enabled', false );

        $this->blocked_patterns = apply_filters( 'sathi_moderation_blocked', [
            // SQL injection patterns (defense-in-depth)
            '/(\bUNION\s+SELECT\b|\bDROP\s+TABLE\b|\bINSERT\s+INTO\b.*\bVALUES\b|\bDELETE\s+FROM\b|\bUPDATE\b.*\bSET\b)/i',
            // Shell injection
            '/(\brm\s+-rf\b|\bcurl\b.*\b\|\s*bash\b|\bwget\b.*\b-O\b|\/etc\/passwd)/i',
            // XSS patterns
            '/(<script[\s>]|javascript\s*:|on\w+\s*=\s*["\'])/i',
        ] );

        $this->warning_patterns = apply_filters( 'sathi_moderation_warnings', [
            // Profanity (basic, extend as needed)
            '/\b(fuck|shit|asshole|bastard|damn)\b/i',
            // Personal info patterns
            '/\b(\d{3}-\d{2}-\d{4}|\d{16})\b/', // SSN-like, credit card-like
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', // Email (intent to share)
        ] );
    }

    /**
     * Moderate user input before sending to the AI.
     *
     * @param  string $text
     * @return array{passed: bool, sanitized: string, flags: string[]}
     */
    public function moderate_input( string $text ): array {
        $flags = [];

        if ( ! $this->enabled ) {
            return [ 'passed' => true, 'sanitized' => $text, 'flags' => [] ];
        }

        // Check blocked patterns
        foreach ( $this->blocked_patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                $flags[] = 'blocked:malicious_pattern';
            }
        }

        if ( ! empty( $flags ) ) {
            // Strip potentially dangerous content
            $sanitized = wp_strip_all_tags( $text, true );
            $sanitized = preg_replace( '/[^\x20-\x7E\x{00A0}-\x{00FF}\x{0100}-\x{017F}\x{0180}-\x{024F}\x{1F300}-\x{1F9FF}\x{2000}-\x{206F}\x{2600}-\x{27BF}\n\r\t]/u', '', $sanitized );
            $sanitized = trim( $sanitized );

            return [ 'passed' => false, 'sanitized' => $sanitized, 'flags' => $flags ];
        }

        // Check warning patterns
        foreach ( $this->warning_patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                $flags[] = 'warning:sensitive_content';
            }
        }

        return [ 'passed' => true, 'sanitized' => $text, 'flags' => $flags ];
    }

    /**
     * Screen AI output for problematic content.
     *
     * @param  string $text
     * @return array{passed: bool, text: string, flags: string[]}
     */
    public function moderate_output( string $text ): array {
        if ( ! $this->enabled ) {
            return [ 'passed' => true, 'text' => $text, 'flags' => [] ];
        }

        $flags = [];

        // Check for hallucinated URLs pointing to non-site domains
        if ( preg_match_all( '/https?:\/\/[^\s)]+/', $text, $urls ) ) {
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            foreach ( $urls[0] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( $host && $host !== $site_host && ! str_contains( $host, 'wikipedia' ) && ! str_contains( $host, 'github' ) ) {
                    $flags[] = 'warning:external_url:' . $host;
                }
            }
        }

        return [ 'passed' => empty( $flags ), 'text' => $text, 'flags' => $flags ];
    }

    /**
     * Whether moderation is active.
     */
    public function is_enabled(): bool {
        return $this->enabled;
    }
}
