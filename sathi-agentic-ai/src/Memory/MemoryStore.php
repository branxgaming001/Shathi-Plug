<?php
/**
 * Persistent per-user memory store.
 *
 * Stores and retrieves key-value memory entries, handles summarization of long
 * conversations, and prunes expired entries on schedule.
 *
 * @package NeerMedia\Sathi\Memory
 */

namespace NeerMedia\Sathi\Memory;

use NeerMedia\Sathi\Core\Data\Conversation;
use NeerMedia\Sathi\Support\Helpers;

class MemoryStore {

    /** @var string Table name */
    private string $table;

    /** @var int Default TTL in days */
    private int $ttl_days;

    public function __construct() {
        global $wpdb;
        $this->table    = $wpdb->prefix . 'sathi_memory_entries';
        $this->ttl_days = (int) get_option( 'sathi_memory_ttl_days', 90 );
    }

    /**
     * Store a memory entry.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  string      $key    Memory key slug.
     * @param  mixed       $value  Value (string or array — will be JSON-encoded).
     * @param  int         $importance 1-10 priority score.
     * @param  int|null    $conv_id Source conversation ID.
     * @return bool
     */
    public function set( ?int $user_id, ?string $guest_id, string $key, $value, int $importance = 1, ?int $conv_id = null ): bool {
        global $wpdb;

        $serialised = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
        $expires_at = $this->ttl_days > 0
            ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$this->ttl_days} days" ) )
            : null;

        // Upsert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE user_id <=> %d AND guest_id <=> %s AND key_slug = %s",
            $user_id, $guest_id, $key
        ) );

        if ( $existing ) {
            return (bool) $wpdb->update( $this->table, [
                'value'                 => $serialised,
                'importance'            => $importance,
                'source_conversation_id'=> $conv_id,
                'expires_at'            => $expires_at,
                'updated_at'            => current_time( 'mysql' ),
            ], [ 'id' => $existing ] );
        }

        return (bool) $wpdb->insert( $this->table, [
            'user_id'               => $user_id,
            'guest_id'              => $guest_id,
            'key_slug'              => $key,
            'value'                 => $serialised,
            'importance'            => $importance,
            'source_conversation_id'=> $conv_id,
            'expires_at'            => $expires_at,
            'created_at'            => current_time( 'mysql' ),
            'updated_at'            => current_time( 'mysql' ),
        ] );
    }

    /**
     * Get a single memory entry.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  string      $key
     * @return mixed|null
     */
    public function get( ?int $user_id, ?string $guest_id, string $key ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT value FROM {$this->table} WHERE user_id <=> %d AND guest_id <=> %s AND key_slug = %s AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id, $guest_id, $key
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $decoded = json_decode( $row['value'], true );
        return $decoded !== null ? $decoded : $row['value'];
    }

    /**
     * Get all memory entries for a user/guest.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  int         $limit
     * @return array<int, array{key: string, value: mixed, importance: int}>
     */
    public function get_all( ?int $user_id, ?string $guest_id, int $limit = 50 ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT key_slug, value, importance FROM {$this->table} WHERE user_id <=> %d AND guest_id <=> %s AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY importance DESC, updated_at DESC LIMIT %d",
            $user_id, $guest_id, $limit
        ), ARRAY_A );

        return array_map( function ( $row ) {
            $decoded = json_decode( $row['value'], true );
            return [
                'key'        => $row['key_slug'],
                'value'      => $decoded !== null ? $decoded : $row['value'],
                'importance' => (int) $row['importance'],
            ];
        }, $rows ?: [] );
    }

    /**
     * Delete a specific memory entry.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  string      $key
     * @return bool
     */
    public function forget( ?int $user_id, ?string $guest_id, string $key ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [
            'user_id'  => $user_id,
            'guest_id' => $guest_id,
            'key_slug' => $key,
        ] );
    }

    /**
     * Delete all memory for a user/guest.
     */
    public function forget_all( ?int $user_id, ?string $guest_id ): int {
        global $wpdb;
        return $wpdb->delete( $this->table, [
            'user_id'  => $user_id,
            'guest_id' => $guest_id,
        ] );
    }

    /**
     * Prune expired memory entries.
     *
     * @return int Number of pruned entries.
     */
    public function prune_expired(): int {
        global $wpdb;
        return (int) $wpdb->query(
            "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at < NOW()"
        );
    }

    /**
     * Generate a text summary of user memory for injection into the system prompt.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @return string
     */
    public function summarize( ?int $user_id, ?string $guest_id ): string {
        $entries = $this->get_all( $user_id, $guest_id, 20 );

        if ( empty( $entries ) ) {
            return '';
        }

        $lines = [];
        foreach ( $entries as $entry ) {
            $val = is_array( $entry['value'] )
                ? wp_json_encode( $entry['value'], JSON_UNESCAPED_UNICODE )
                : (string) $entry['value'];

            $lines[] = sprintf( '- %s: %s', $entry['key'], Helpers::clean_text( $val, 200 ) );
        }

        return __( 'Previously remembered information about this user:', 'sathi-agentic-ai' )
            . "\n" . implode( "\n", $lines );
    }

    /**
     * Automatically extract and store key memories from a completed conversation.
     *
     * Uses heuristics: extracts user name, preferences, topics, and questions.
     *
     * @param Conversation $conv
     */
    public function ingest_conversation( Conversation $conv ): void {
        $uid    = $conv->user_id;
        $gid    = $conv->guest_id;
        $user_msgs = [];

        // Collect all user messages
        foreach ( $conv->messages as $msg ) {
            if ( $msg->role === 'user' ) {
                $user_msgs[] = $msg->content;
            }
        }

        if ( empty( $user_msgs ) ) {
            return;
        }

        $all_text = implode( ' ', $user_msgs );

        // Extract name patterns: "my name is X", "I'm X", "call me X"
        if ( preg_match( '/(?:my name is|i\'?m|call me)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $all_text, $m ) ) {
            $this->set( $uid, $gid, 'user_name', $m[1], 9, $conv->id );
        }

        // Store last topic
        if ( count( $user_msgs ) > 0 ) {
            $last = end( $user_msgs );
            $topic = Helpers::clean_text( $last, 100 );
            $this->set( $uid, $gid, 'last_topic', $topic, 5, $conv->id );
        }

        // Store conversation summary if long
        if ( Helpers::estimate_tokens( $all_text ) > 500 ) {
            $summary = Helpers::clean_text(
                __( 'User discussed: ', 'sathi-agentic-ai' ) . Helpers::clean_text( $all_text, 500 ),
                600
            );
            $this->set( $uid, $gid, 'conversation_summary_' . $conv->id, $summary, 3, $conv->id );
        }

        // Store user language preference
        if ( ! $this->get( $uid, $gid, 'preferred_language' ) ) {
            // Simple heuristic — WordPress site language
            $locale = get_locale();
            $this->set( $uid, $gid, 'preferred_language', $locale, 4, $conv->id );
        }
    }
}
