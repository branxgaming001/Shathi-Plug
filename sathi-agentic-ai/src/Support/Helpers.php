<?php
/**
 * Static helper functions — namespaced to avoid global pollution.
 *
 * @package NeerMedia\Sathi\Support
 */

namespace NeerMedia\Sathi\Support;

class Helpers {

    /**
     * Generate a v4 UUID.
     */
    public static function uuid(): string {
        $data    = random_bytes( 16 );
        $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // Version 4
        $data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // Variant

        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
    }

    /**
     * Generate a guest ID hash from IP and User-Agent.
     */
    public static function guest_id(): string {
        // Check for existing cookie first
        if ( ! empty( $_COOKIE['sathi_guest'] ) && preg_match( '/^[a-f0-9]{64}$/', $_COOKIE['sathi_guest'] ) ) {
            return $_COOKIE['sathi_guest'];
        }
        // Generate new, set cookie
        $id = hash( 'sha256', random_bytes( 32 ) );
        setcookie( 'sathi_guest', $id, [
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite'  => 'Lax',
        ] );
        $_COOKIE['sathi_guest'] = $id;
        return $id;
    }

    /**
     * Strip HTML, normalise whitespace, truncate.
     *
     * @param  string $text
     * @param  int    $max_length
     * @return string
     */
    public static function clean_text( string $text, int $max_length = 0 ): string {
        $text = wp_strip_all_tags( $text, true );
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( $text );

        if ( $max_length > 0 && mb_strlen( $text ) > $max_length ) {
            $text = mb_substr( $text, 0, $max_length ) . '…';
        }

        return $text;
    }

    /**
     * Strip a reasoning model's chain-of-thought from a reply so only the final
     * answer is shown. Handles <think>…</think>, <thinking>…</thinking>, the
     * ◁think▷…◁/think▷ delimiters some models use, and truncated/orphaned tags.
     *
     * @param  string $text
     * @return string
     */
    public static function strip_reasoning( string $text ): string {
        if ( $text === '' ) {
            return $text;
        }

        // Normalise the unusual delimiters a few models emit.
        $text = str_ireplace( [ '◁think▷', '◁/think▷', '<|thinking|>', '<|/thinking|>' ], [ '<think>', '</think>', '<think>', '</think>' ], $text );

        // 1) Remove complete <think>…</think> / <thinking>…</thinking> blocks.
        $text = preg_replace( '#<\s*think(?:ing)?\s*>.*?<\s*/\s*think(?:ing)?\s*>#is', '', (string) $text );

        // 2) Orphaned closing tag (reasoning had no opening, or it was trimmed):
        //    drop everything up to and including the first closing tag.
        if ( preg_match( '#<\s*/\s*think(?:ing)?\s*>#i', (string) $text ) ) {
            $text = preg_replace( '#^.*?<\s*/\s*think(?:ing)?\s*>#is', '', (string) $text );
        }

        // 3) Orphaned opening tag (stream cut mid-reasoning): drop from it to end.
        $text = preg_replace( '#<\s*think(?:ing)?\s*>.*$#is', '', (string) $text );

        // 4) Remove any stray tags and tidy whitespace.
        $text = preg_replace( '#<\s*/?\s*think(?:ing)?\s*>#i', '', (string) $text );

        return trim( (string) $text );
    }

    /**
     * Chunk a long string into token-sized pieces.
     *
     * Rough heuristic: 1 token ≈ 4 chars for English text.
     *
     * @param  string $text
     * @param  int    $chunk_tokens Target tokens per chunk.
     * @param  int    $overlap_tokens Overlap between chunks.
     * @return string[]
     */
    public static function chunk_text( string $text, int $chunk_tokens = 512, int $overlap_tokens = 50 ): array {
        $chunk_chars  = $chunk_tokens * 4;
        $overlap_chars = $overlap_tokens * 4;
        $chunks        = [];

        $sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

        $current = '';
        foreach ( $sentences as $sentence ) {
            if ( mb_strlen( $current ) + mb_strlen( $sentence ) > $chunk_chars && $current ) {
                $chunks[] = trim( $current );
                // Keep overlap
                $overlap = mb_substr( $current, -$overlap_chars );
                $current = $overlap . ' ' . $sentence;
            } else {
                $current .= ( $current ? ' ' : '' ) . $sentence;
            }
        }

        if ( trim( $current ) ) {
            $chunks[] = trim( $current );
        }

        return $chunks;
    }

    /**
     * Estimate token count from string.
     *
     * @param  string $text
     * @return int
     */
    public static function estimate_tokens( string $text ): int {
        return (int) ceil( mb_strlen( $text ) / 4 );
    }

    /**
     * Mask an API key for display.
     *
     * @param  string $key
     * @return string
     */
    public static function mask_key( string $key ): string {
        if ( strlen( $key ) <= 8 ) {
            return str_repeat( '*', strlen( $key ) );
        }
        return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
    }

    /**
     * Encrypt a secret (e.g. an API key) for at-rest storage in wp_options.
     *
     * Uses AES-256-CBC with a key derived from the site's auth salt. Output is
     * prefixed with "enc:" so decrypt() can distinguish it from legacy plaintext.
     *
     * @param  string $plain
     * @return string
     */
    public static function encrypt( string $plain ): string {
        if ( $plain === '' ) {
            return '';
        }
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            error_log( 'Saathi: openssl_encrypt unavailable — API keys stored as plaintext.' );
            return $plain; // Graceful fallback if OpenSSL is unavailable.
        }
        $key    = hash( 'sha256', self::crypto_salt(), true );
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( $cipher === false ) {
            return $plain;
        }
        return 'enc:' . base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a value produced by encrypt(). Plaintext (no "enc:" prefix) is
     * returned as-is for backward compatibility with already-stored keys.
     *
     * @param  string $stored
     * @return string
     */
    public static function decrypt( string $stored ): string {
        if ( strncmp( $stored, 'enc:', 4 ) !== 0 ) {
            return $stored;
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $raw = base64_decode( substr( $stored, 4 ), true );
        if ( $raw === false || strlen( $raw ) <= 16 ) {
            return '';
        }
        $iv     = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $key    = hash( 'sha256', self::crypto_salt(), true );
        $plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return $plain === false ? '' : $plain;
    }

    /**
     * Salt source for encryption — uses WP auth salt when available.
     */
    private static function crypto_salt(): string {
        if ( function_exists( 'wp_salt' ) ) {
            return wp_salt( 'auth' );
        }
        return defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sathi-fallback-salt';
    }

    /**
     * Recursively sanitize array data.
     *
     * @param  array  $data
     * @param  string $sanitizer Callable sanitizer name.
     * @return array
     */
    public static function sanitize_recursive( array $data, string $sanitizer = 'sanitize_text_field' ): array {
        $clean = [];
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $clean[ sanitize_key( $key ) ] = self::sanitize_recursive( $value, $sanitizer );
            } else {
                $clean[ sanitize_key( $key ) ] = call_user_func( $sanitizer, $value );
            }
        }
        return $clean;
    }

    /**
     * Get current request context for logging.
     *
     * @return array{method: string, uri: string, ip: string}
     */
    public static function request_context(): array {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri'    => $_SERVER['REQUEST_URI'] ?? '',
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
    }
}
