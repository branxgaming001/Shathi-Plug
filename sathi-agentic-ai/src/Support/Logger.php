<?php
/**
 * PSR-3 compatible logger for Sathi plugin.
 *
 * @package NeerMedia\Sathi\Support
 */

namespace NeerMedia\Sathi\Support;

class Logger {

    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    /** @var string Log file path */
    private string $log_file;

    /** @var string Minimum level to log */
    private string $min_level;

    /** @var array<int, string> Level hierarchy */
    private const LEVELS = [
        self::DEBUG     => 0,
        self::INFO      => 100,
        self::NOTICE    => 200,
        self::WARNING   => 300,
        self::ERROR     => 400,
        self::CRITICAL  => 500,
        self::ALERT     => 600,
        self::EMERGENCY => 700,
    ];

    public function __construct() {
        $upload_dir      = wp_upload_dir();
        $this->log_file  = trailingslashit( $upload_dir['basedir'] ) . 'sathi-debug.log';
        $this->min_level = get_option( 'sathi_log_level', self::WARNING );
    }

    /**
     * System is unusable.
     */
    public function emergency( string $message, array $context = [] ): void {
        $this->log( self::EMERGENCY, $message, $context );
    }

    /**
     * Action must be taken immediately.
     */
    public function alert( string $message, array $context = [] ): void {
        $this->log( self::ALERT, $message, $context );
    }

    /**
     * Critical conditions.
     */
    public function critical( string $message, array $context = [] ): void {
        $this->log( self::CRITICAL, $message, $context );
    }

    /**
     * Runtime errors that do not require immediate action.
     */
    public function error( string $message, array $context = [] ): void {
        $this->log( self::ERROR, $message, $context );
    }

    /**
     * Exceptional occurrences that are not errors.
     */
    public function warning( string $message, array $context = [] ): void {
        $this->log( self::WARNING, $message, $context );
    }

    /**
     * Normal but significant events.
     */
    public function notice( string $message, array $context = [] ): void {
        $this->log( self::NOTICE, $message, $context );
    }

    /**
     * Interesting events.
     */
    public function info( string $message, array $context = [] ): void {
        $this->log( self::INFO, $message, $context );
    }

    /**
     * Detailed debug information.
     */
    public function debug( string $message, array $context = [] ): void {
        $this->log( self::DEBUG, $message, $context );
    }

    /**
     * Write a log entry if the level meets the threshold.
     */
    private function log( string $level, string $message, array $context = [] ): void {
        $current_level = self::LEVELS[ $this->min_level ] ?? 300;
        $msg_level     = self::LEVELS[ $level ] ?? 0;

        if ( $msg_level < $current_level ) {
            return;
        }

        $entry = sprintf(
            '[%s] [Sathi] [%s] %s',
            gmdate( 'Y-m-d H:i:s' ),
            strtoupper( $level ),
            $message
        );

        if ( $context ) {
            $entry .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
        }

        error_log( $entry . PHP_EOL, 3, $this->log_file );
    }

    /**
     * Get the full log file path.
     */
    public function get_log_path(): string {
        return $this->log_file;
    }

    /**
     * Read recent log entries.
     *
     * @param  int    $lines Number of lines to return.
     * @return string[]
     */
    public function tail( int $lines = 100 ): array {
        if ( ! file_exists( $this->log_file ) ) {
            return [];
        }

        $content = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! $content ) {
            return [];
        }

        return array_slice( $content, -$lines );
    }

    /**
     * Clear the log file.
     */
    public function clear(): void {
        if ( file_exists( $this->log_file ) ) {
            @unlink( $this->log_file );
        }
    }
}
