<?php
/**
 * Memory Manager — LLM-powered memory extraction, semantic recall, and
 * context generation on top of MemoryStore.
 *
 * @package NeerMedia\Sathi\Memory
 */

namespace NeerMedia\Sathi\Memory;

use NeerMedia\Sathi\Core\Data\Conversation;
use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Providers\Factory;
use NeerMedia\Sathi\Support\Helpers;

class MemoryManager {

    /** @var MemoryStore */
    private MemoryStore $store;

    /** @var Factory */
    private Factory $factory;

    /** @var string Table name */
    private string $table;

    public function __construct( MemoryStore $store, Factory $factory ) {
        global $wpdb;
        $this->store   = $store;
        $this->factory = $factory;
        $this->table   = $wpdb->prefix . 'sathi_memory_entries';
    }

    // ── LLM-Powered Extraction ───────────────────────────────────────

    /**
     * Use the LLM to extract structured facts from a conversation.
     *
     * Collects all user and assistant messages, sends them to the provider with
     * an extraction system prompt, parses the JSON response, and stores each
     * extracted fact via MemoryStore.
     *
     * @param  Conversation $conv Fully loaded conversation with messages.
     * @return array{extracted: array<int, array{key: string, value: mixed, importance: int}>, summary: string}
     */
    public function extractFromConversation( Conversation $conv ): array {
        $dialogue = $this->build_dialogue_text( $conv );

        if ( empty( $dialogue ) ) {
            return [ 'extracted' => [], 'summary' => '' ];
        }

        $system_prompt = $this->extraction_system_prompt();

        try {
            $provider    = $this->factory->for_task( 'chat' );
            $messages    = [
                Message::system( $system_prompt ),
                Message::user( $dialogue ),
            ];
            $response    = $provider->chat( $messages, [
                'temperature' => 0.2,
                'max_tokens'  => 1024,
            ] );
        } catch ( \Throwable $e ) {
            // Fallback: use regex-based extraction from MemoryStore
            $this->store->ingest_conversation( $conv );
            return [
                'extracted' => [],
                'summary'   => '',
                'fallback'  => true,
                'error'     => $e->getMessage(),
            ];
        }

        $content = trim( $response->content );
        $parsed  = $this->parse_extraction_json( $content );

        if ( empty( $parsed['facts'] ) && empty( $parsed['summary'] ) ) {
            // LLM returned unusable output; fall back to regex
            $this->store->ingest_conversation( $conv );
            return [
                'extracted' => [],
                'summary'   => '',
                'fallback'  => true,
                'raw'       => $content,
            ];
        }

        $uid    = $conv->user_id;
        $gid    = $conv->guest_id;
        $stored = [];

        foreach ( $parsed['facts'] as $fact ) {
            $key        = sanitize_key( $fact['key'] ?? '' );
            $value      = $fact['value'] ?? '';
            $importance = min( 10, max( 1, (int) ( $fact['importance'] ?? 5 ) ) );

            if ( empty( $key ) || empty( $value ) ) {
                continue;
            }

            $this->store->set( $uid, $gid, $key, $value, $importance, $conv->id );
            $stored[] = [
                'key'        => $key,
                'value'      => $value,
                'importance' => $importance,
            ];
        }

        return [
            'extracted' => $stored,
            'summary'   => $parsed['summary'] ?? '',
        ];
    }

    // ── Semantic Recall ──────────────────────────────────────────────

    /**
     * Find memories most relevant to a query using keyword matching.
     *
     * Splits the query into keywords and scores each memory entry by how many
     * keywords match in the key_slug or value columns. Ties are broken by
     * importance then recency.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @param  string      $query    Natural-language search query.
     * @param  int         $limit    Max results to return.
     * @return array<int, array{id: int, key: string, value: mixed, importance: int, score: int, updated_at: string}>
     */
    public function recallRelevant( ?int $user_id, ?string $guest_id, string $query, int $limit = 5 ): array {
        global $wpdb;

        $keywords = $this->tokenize_query( $query );
        if ( empty( $keywords ) ) {
            // No meaningful keywords; return top entries by importance
            return $this->store->get_all( $user_id, $guest_id, $limit );
        }

        $conditions = [];
        $params     = [];

        // Build LIKE conditions for each keyword
        foreach ( $keywords as $kw ) {
            $like        = '%' . $wpdb->esc_like( $kw ) . '%';
            $conditions[] = '(key_slug LIKE %s OR value LIKE %s)';
            $params[]     = $like;
            $params[]     = $like;
        }

        $where_user = '(user_id <=> %d AND guest_id <=> %s)';
        $params[]   = $user_id;
        $params[]   = $guest_id;

        $where_clause = '(' . implode( ' OR ', $conditions ) . ') AND ' . $where_user . ' AND (expires_at IS NULL OR expires_at > NOW())';

        // Score: count how many keywords matched, times importance
        $score_expr = '0';
        foreach ( $keywords as $i => $kw ) {
            $like     = '%' . $wpdb->esc_like( $kw ) . '%';
            $score_expr .= sprintf(
                ' + IF(key_slug LIKE %s, 2, 0) + IF(value LIKE %s, 1, 0)',
                $wpdb->prepare( '%s', $like ),
                $wpdb->prepare( '%s', $like )
            );
        }

        $sql = $wpdb->prepare(
            "SELECT id, key_slug, value, importance, (importance * ({$score_expr})) AS score, updated_at
            FROM {$this->table}
            WHERE {$where_clause}
            ORDER BY score DESC, importance DESC, updated_at DESC
            LIMIT %d",
            array_merge( $params, [ $limit ] )
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        return array_map( function ( $row ) {
            $decoded = json_decode( $row['value'], true );
            return [
                'id'         => (int) $row['id'],
                'key'        => $row['key_slug'],
                'value'      => $decoded !== null ? $decoded : $row['value'],
                'importance' => (int) $row['importance'],
                'score'      => (int) $row['score'],
                'updated_at' => $row['updated_at'],
            ];
        }, $rows );
    }

    // ── Context for System Prompt Injection ──────────────────────────

    /**
     * Generate a compact context string for injection into the system prompt.
     *
     * Formats all remembered facts into a concise bullet list optimised for
     * token efficiency. The assistant is instructed to use this context
     * naturally — never reciting it verbatim.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @return string      Empty string if no memories exist.
     */
    public function getMemoryContext( ?int $user_id, ?string $guest_id ): string {
        $entries = $this->store->get_all( $user_id, $guest_id, 30 );

        if ( empty( $entries ) ) {
            return '';
        }

        $lines = [];
        foreach ( $entries as $entry ) {
            $val = is_array( $entry['value'] )
                ? wp_json_encode( $entry['value'], JSON_UNESCAPED_UNICODE )
                : (string) $entry['value'];

            // Truncate long values to save tokens
            $val = Helpers::clean_text( $val, 150 );

            $lines[] = sprintf( '- %s: %s', $entry['key'], $val );
        }

        $header = __( "Information you remember about this user (use naturally; never recite verbatim):", 'sathi-agentic-ai' );

        return $header . "\n" . implode( "\n", $lines );
    }

    // ── LLM-Generated User Profile ───────────────────────────────────

    /**
     * Use the LLM to write a 3-sentence user profile based on all memories.
     *
     * @param  int|null    $user_id
     * @param  string|null $guest_id
     * @return string      Empty string if no memories or LLM fails.
     */
    public function smartSummarize( ?int $user_id, ?string $guest_id ): string {
        $entries = $this->store->get_all( $user_id, $guest_id, 50 );

        if ( empty( $entries ) ) {
            return '';
        }

        $facts_text = '';
        foreach ( $entries as $entry ) {
            $val = is_array( $entry['value'] )
                ? wp_json_encode( $entry['value'], JSON_UNESCAPED_UNICODE )
                : (string) $entry['value'];

            $facts_text .= sprintf( "- %s: %s\n", $entry['key'], Helpers::clean_text( $val, 200 ) );
        }

        $system_prompt = $this->summary_system_prompt();

        try {
            $provider = $this->factory->for_task( 'chat' );
            $messages = [
                Message::system( $system_prompt ),
                Message::user( $facts_text ),
            ];
            $response = $provider->chat( $messages, [
                'temperature' => 0.4,
                'max_tokens'  => 256,
            ] );
        } catch ( \Throwable $e ) {
            // Graceful fallback: return raw fact list
            return trim( $facts_text );
        }

        $profile = trim( $response->content );
        return $profile ?: trim( $facts_text );
    }

    // ── Importance Auto-Scoring ──────────────────────────────────────

    /**
     * Recalculate importance scores based on recency + frequency.
     *
     * Newer entries and entries with similar keys get higher scores.
     * This is called periodically to keep the memory store well-pruned.
     *
     * @param int|null    $user_id
     * @param string|null $guest_id
     * @return int Number of entries updated.
     */
    public function rescoreImportance( ?int $user_id, ?string $guest_id ): int {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, key_slug, importance, created_at FROM {$this->table}
            WHERE user_id <=> %d AND guest_id <=> %s AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id, $guest_id
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return 0;
        }

        // Count frequency of each key_slug
        $key_freq = [];
        foreach ( $rows as $row ) {
            $k = $row['key_slug'];
            $key_freq[ $k ] = ( $key_freq[ $k ] ?? 0 ) + 1;
        }

        $now        = time();
        $max_freq   = max( $key_freq ?: [1] );
        $max_age_days = 1;

        // Find maximum age for normalisation
        foreach ( $rows as $row ) {
            $age = (int) round( ( $now - strtotime( $row['created_at'] ) ) / DAY_IN_SECONDS );
            if ( $age > $max_age_days ) {
                $max_age_days = $age;
            }
        }
        $max_age_days = max( $max_age_days, 1 );

        $updated = 0;
        foreach ( $rows as $row ) {
            $freq = $key_freq[ $row['key_slug'] ] ?? 1;
            $age  = max( 1, (int) round( ( $now - strtotime( $row['created_at'] ) ) / DAY_IN_SECONDS ) );

            // Score formula: 40% recency + 40% frequency + 20% existing importance
            $recency_score = 10 * ( 1 - ( $age / $max_age_days ) );
            $freq_score    = 10 * ( $freq / $max_freq );
            $old_imp       = (int) $row['importance'];

            $new_score = (int) round(
                ( $recency_score * 0.4 ) + ( $freq_score * 0.4 ) + ( $old_imp * 0.2 )
            );
            $new_score = min( 10, max( 1, $new_score ) );

            if ( $new_score !== $old_imp ) {
                $wpdb->update( $this->table, [
                    'importance' => $new_score,
                    'updated_at' => current_time( 'mysql' ),
                ], [ 'id' => $row['id'] ] );
                $updated++;
            }
        }

        return $updated;
    }

    // ── Admin Helpers ────────────────────────────────────────────────

    /**
     * Get paginated entries for admin table view, with optional search and
     * user_id filter.
     *
     * @param  array{search?: string, user_id?: int, page?: int, per_page?: int} $args
     * @return array{entries: array, total: int, page: int, per_page: int, pages: int}
     */
    public function getAdminEntries( array $args = [] ): array {
        global $wpdb;

        $search   = sanitize_text_field( $args['search'] ?? '' );
        $user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : null;
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where   = [ '1=1' ];
        $params  = [];

        if ( ! empty( $search ) ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(key_slug LIKE %s OR value LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ( $user_id !== null && $user_id > 0 ) {
            $where[]  = 'user_id = %d';
            $params[] = $user_id;
        }

        $where_clause = implode( ' AND ', $where );

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_clause}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Fetch page
        $params[] = $per_page;
        $params[] = $offset;
        $data_sql = $wpdb->prepare(
            "SELECT id, user_id, guest_id, key_slug, value, importance, source_conversation_id, expires_at, created_at, updated_at
            FROM {$this->table}
            WHERE {$where_clause}
            ORDER BY updated_at DESC
            LIMIT %d OFFSET %d",
            ...$params
        );

        $rows = $wpdb->get_results( $data_sql, ARRAY_A );

        $entries = array_map( function ( $row ) {
            $decoded = json_decode( $row['value'], true );
            return [
                'id'                     => (int) $row['id'],
                'user_id'                => $row['user_id'] ? (int) $row['user_id'] : null,
                'guest_id'               => $row['guest_id'],
                'key'                    => $row['key_slug'],
                'value'                  => $decoded !== null ? $decoded : $row['value'],
                'importance'             => (int) $row['importance'],
                'source_conversation_id' => $row['source_conversation_id'] ? (int) $row['source_conversation_id'] : null,
                'expires_at'             => $row['expires_at'],
                'created_at'             => $row['created_at'],
                'updated_at'             => $row['updated_at'],
            ];
        }, $rows ?: [] );

        return [
            'entries'  => $entries,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
        ];
    }

    /**
     * Get aggregate memory statistics.
     *
     * @return array{total_entries: int, unique_users: int, unique_guests: int, top_keys: array, oldest_entry: string|null, newest_entry: string|null}
     */
    public function getStats(): array {
        global $wpdb;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );

        $unique_users = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table} WHERE user_id IS NOT NULL"
        );

        $unique_guests = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT guest_id) FROM {$this->table} WHERE guest_id IS NOT NULL"
        );

        $top_keys_rows = $wpdb->get_results(
            "SELECT key_slug, COUNT(*) AS cnt FROM {$this->table} GROUP BY key_slug ORDER BY cnt DESC LIMIT 10",
            ARRAY_A
        );

        $top_keys = array_map( function ( $r ) {
            return [ 'key' => $r['key_slug'], 'count' => (int) $r['cnt'] ];
        }, $top_keys_rows ?: [] );

        $oldest = $wpdb->get_var( "SELECT created_at FROM {$this->table} ORDER BY created_at ASC LIMIT 1" );
        $newest = $wpdb->get_var( "SELECT created_at FROM {$this->table} ORDER BY created_at DESC LIMIT 1" );

        return [
            'total_entries'  => $total,
            'unique_users'   => $unique_users,
            'unique_guests'  => $unique_guests,
            'top_keys'       => $top_keys,
            'oldest_entry'   => $oldest,
            'newest_entry'   => $newest,
        ];
    }

    /**
     * Delete a memory entry by its database ID.
     *
     * @param  int $id
     * @return bool
     */
    public function deleteById( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Get a single memory entry by database ID.
     *
     * @param  int $id
     * @return array|null
     */
    public function getById( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id, guest_id, key_slug, value, importance, source_conversation_id, expires_at, created_at, updated_at
            FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $decoded = json_decode( $row['value'], true );
        return [
            'id'                     => (int) $row['id'],
            'user_id'                => $row['user_id'] ? (int) $row['user_id'] : null,
            'guest_id'               => $row['guest_id'],
            'key'                    => $row['key_slug'],
            'value'                  => $decoded !== null ? $decoded : $row['value'],
            'importance'             => (int) $row['importance'],
            'source_conversation_id' => $row['source_conversation_id'] ? (int) $row['source_conversation_id'] : null,
            'expires_at'             => $row['expires_at'],
            'created_at'             => $row['created_at'],
            'updated_at'             => $row['updated_at'],
        ];
    }

    // ── Private Helpers ──────────────────────────────────────────────

    /**
     * Build a compact dialogue text from conversation messages.
     *
     * @param  Conversation $conv
     * @return string
     */
    private function build_dialogue_text( Conversation $conv ): string {
        $lines = [];
        foreach ( $conv->messages as $msg ) {
            $role  = $msg->role === 'assistant' ? 'Assistant' : ( $msg->role === 'user' ? 'User' : ucfirst( $msg->role ) );
            $lines[] = "{$role}: " . Helpers::clean_text( $msg->content, 2000 );
        }
        return implode( "\n\n", $lines );
    }

    /**
     * System prompt for LLM-based memory extraction.
     */
    private function extraction_system_prompt(): string {
        return <<<'PROMPT'
You are a memory extraction system for an AI assistant called Sathi. Extract key facts about the user from the conversation below. Return ONLY a valid JSON object like:

{
  "facts": [
    {"key": "user_name", "value": "Raj", "importance": 9},
    {"key": "user_email", "value": "raj@example.com", "importance": 8}
  ],
  "summary": "Brief summary of what the user asked about and what was resolved."
}

Guidelines:
- Use snake_case keys (user_name, user_email, user_phone, user_location, preferences, topics_of_interest, language, last_question, pain_points, goals, etc.)
- Only extract facts EXPLICITLY stated — never guess or infer.
- Importance 1-10: 10 = permanent identity (name, email), 7-9 = strong preferences/goals, 4-6 = topics discussed, 1-3 = transient context.
- Values should be plain strings (not arrays).
- If no facts are found, return {"facts": [], "summary": ""}.
- The summary should be ONE sentence about what was discussed.

Return ONLY the JSON. No markdown, no code fences, no explanation.
PROMPT;
    }

    /**
     * System prompt for LLM-based user profile generation.
     */
    private function summary_system_prompt(): string {
        return <<<'PROMPT'
You are a profile writer for an AI assistant. Given a list of remembered facts about a user, write a concise 3-sentence profile summarizing who they are, what they care about, and what they have discussed. Write in natural, warm prose — do NOT list facts or use bullet points. Do NOT mention that these are "remembered facts."

Example: "Raj is a web developer based in Mumbai who runs a small e-commerce store. He has been exploring AI chatbots for customer support and prefers solutions that integrate with WordPress. His recent conversations focused on reducing support tickets and improving response times."

Return ONLY the 3-sentence profile. No preamble, no labels.
PROMPT;
    }

    /**
     * Parse the LLM's JSON response, handling common formatting issues.
     *
     * @param  string $content Raw LLM output.
     * @return array{facts: array, summary: string}
     */
    private function parse_extraction_json( string $content ): array {
        // Strip markdown code fences if present
        $content = preg_replace( '/^```(?:json)?\s*/i', '', trim( $content ) );
        $content = preg_replace( '/\s*```$/', '', $content );

        // Try to find JSON object in the response
        if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
            $content = $m[0];
        }

        $decoded = json_decode( $content, true );

        if ( ! is_array( $decoded ) ) {
            return [ 'facts' => [], 'summary' => '' ];
        }

        return [
            'facts'   => $decoded['facts'] ?? [],
            'summary' => $decoded['summary'] ?? '',
        ];
    }

    /**
     * Tokenize a search query into meaningful keywords.
     *
     * @param  string $query
     * @return string[]
     */
    private function tokenize_query( string $query ): array {
        $query = Helpers::clean_text( $query, 500 );
        $query = strtolower( $query );

        // Split on word boundaries, filter short words and common stopwords
        $words = preg_split( '/[\s,;.!?]+/', $query, -1, PREG_SPLIT_NO_EMPTY );

        $stopwords = [
            'the', 'is', 'at', 'which', 'on', 'a', 'an', 'and', 'or', 'but',
            'in', 'with', 'to', 'for', 'of', 'from', 'by', 'what', 'how', 'do',
            'does', 'did', 'can', 'will', 'would', 'could', 'should', 'i', 'me',
            'my', 'we', 'our', 'you', 'your', 'he', 'she', 'it', 'they', 'them',
        ];

        $keywords = [];
        foreach ( $words as $word ) {
            $word = trim( $word );
            if ( strlen( $word ) > 2 && ! in_array( $word, $stopwords, true ) ) {
                $keywords[] = $word;
            }
        }

        return array_unique( array_slice( $keywords, 0, 10 ) );
    }
}
