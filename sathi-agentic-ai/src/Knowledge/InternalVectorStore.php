<?php
/**
 * Internal Vector Store — MySQL-backed embedding storage with PHP cosine similarity.
 *
 * Stores vectors as JSON in the sathi_knowledge_chunks.embedding column.
 * Performs brute-force cosine similarity search in PHP — best for sites
 * with fewer than ~5 000 chunks. For larger sites, use an external adapter
 * (Pinecone, Qdrant, Chroma) that implements VectorStoreInterface.
 *
 * @package NeerMedia\Sathi\Knowledge
 */

namespace NeerMedia\Sathi\Knowledge;

class InternalVectorStore implements VectorStoreInterface {

    /** @var string WordPress table name (with prefix). */
    private string $table;

    /** @var int Expected embedding dimension (1536 for text-embedding-3-small). */
    private int $dimension;

    /** @var int Maximum rows fetched for brute-force comparison. */
    private int $search_cap;

    /**
     * @param int $dimension   Expected embedding vector length.
     * @param int $search_cap  Max rows to pull for brute-force search (default 2000).
     */
    public function __construct( int $dimension = 1536, int $search_cap = 2000 ) {
        global $wpdb;
        $this->table      = $wpdb->prefix . 'sathi_knowledge_chunks';
        $this->dimension  = $dimension;
        $this->search_cap = (int) apply_filters( 'sathi_vector_search_cap', $search_cap );
    }

    // ──────────────────────────────────────────────────────
    //  VectorStoreInterface implementation
    // ──────────────────────────────────────────────────────

    /**
     * Batch upsert vectors within a database transaction.
     *
     * Each $vectors entry MUST contain:
     *   - 'id'     => int          chunk ID (the database primary key).
     *   - 'vector' => float[]       embedding array.
     *   - 'metadata' => array|null  (optional, ignored by this store — metadata
     *                                lives on the chunks row itself).
     *
     * @param  array[] $vectors
     * @return int     Number of rows updated.
     */
    public function upsert( array $vectors ): int {
        global $wpdb;

        if ( empty( $vectors ) ) {
            return 0;
        }

        $count = 0;

        // Begin transaction for atomicity
        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $vectors as $vec ) {
                $chunk_id  = $vec['id'] ?? null;
                $embedding = $vec['vector'] ?? [];

                if ( ! $chunk_id || empty( $embedding ) ) {
                    continue;
                }

                // Validate dimension — skip mismatches silently; caller
                // can detect them by checking pending_count() after upsert.
                $actual_dim = count( $embedding );
                if ( $actual_dim !== $this->dimension ) {
                    continue;
                }

                // Validate all values are numeric
                $embedding = array_map( 'floatval', $embedding );

                $result = $wpdb->update(
                    $this->table,
                    [
                        'embedding'  => wp_json_encode( $embedding, JSON_UNESCAPED_UNICODE ),
                        'updated_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => (int) $chunk_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                if ( $result !== false ) {
                    $count++;
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw new \RuntimeException(
                sprintf( __( 'Vector upsert failed: %s', 'sathi-agentic-ai' ), $e->getMessage() ),
                (int) $e->getCode(),
                $e
            );
        }

        return $count;
    }

    /**
     * Brute-force cosine similarity search against all active chunks that
     * have embeddings.
     *
     * @param  float[] $query_vector
     * @param  int     $limit
     * @param  array   $filters  Optional: ['source_type' => 'product', 'source_id' => 42]
     * @return array[]
     */
    public function search( array $query_vector, int $limit = 5, array $filters = [] ): array {
        global $wpdb;

        // ── Build WHERE clause ──────────────────────────────────
        $where  = [ "status = 'active'", 'embedding IS NOT NULL' ];
        $params = [];

        if ( ! empty( $filters['source_type'] ) ) {
            $where[]  = 'source_type = %s';
            $params[] = $filters['source_type'];
        }

        if ( ! empty( $filters['source_id'] ) ) {
            $where[]  = 'source_id = %d';
            $params[] = (int) $filters['source_id'];
        }

        $where_clause = implode( ' AND ', $where );
        $params[]     = $this->search_cap;

        // Fetch all candidate chunks (capped for performance)
        $chunks = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, content, source_url, source_type, source_id, token_count, embedding
                 FROM {$this->table}
                 WHERE {$where_clause}
                 ORDER BY id DESC
                 LIMIT %d",
                $params
            ),
            ARRAY_A
        );

        if ( empty( $chunks ) ) {
            return [];
        }

        // ── Pre-compute query vector magnitude once ──────────────
        $query_norm = $this->magnitude( $query_vector );

        if ( $query_norm < 0.000001 ) {
            return [];
        }

        // ── Compute cosine similarity for every candidate ────────
        $scored = [];

        foreach ( $chunks as $chunk ) {
            $embedding = json_decode( $chunk['embedding'], true );

            if (
                ! is_array( $embedding )
                || count( $embedding ) < 2
                || count( $embedding ) !== $this->dimension
            ) {
                continue; // Skip malformed or dimension-mismatched vectors
            }

            $score = $this->cosine_similarity( $query_vector, $embedding, $query_norm );

            // Only include positive matches (cosine > 0 means some semantic overlap)
            if ( $score > 0.0 ) {
                $scored[] = [
                    'id'       => (int) $chunk['id'],
                    'score'    => round( $score, 6 ),
                    'metadata' => [
                        'content'     => $chunk['content'],
                        'source_url'  => $chunk['source_url'],
                        'source_type' => $chunk['source_type'],
                        'source_id'   => $chunk['source_id'] ? (int) $chunk['source_id'] : null,
                        'tokens'      => (int) $chunk['token_count'],
                    ],
                ];
            }
        }

        // ── Sort by score descending, take top N ─────────────────
        usort( $scored, fn( array $a, array $b ) => $b['score'] <=> $a['score'] );

        return array_slice( $scored, 0, $limit );
    }

    /**
     * Soft-delete vectors matching the given filters.
     *
     * Sets status = 'deleted' rather than physically removing rows.
     *
     * @param  array $filters  e.g. ['source_id' => 123] or ['source_type' => 'product']
     * @return int   Number of rows marked deleted.
     */
    public function delete( array $filters ): int {
        global $wpdb;

        if ( empty( $filters ) ) {
            return 0;
        }

        $where  = [];
        $params = [];

        foreach ( $filters as $key => $value ) {
            if ( $key === 'source_id' ) {
                $where[]  = 'source_id = %d';
                $params[] = (int) $value;
            } elseif ( $key === 'source_type' ) {
                $where[]  = 'source_type = %s';
                $params[] = $value;
            } elseif ( $key === 'id' ) {
                $where[]  = 'id = %d';
                $params[] = (int) $value;
            }
        }

        if ( empty( $where ) ) {
            return 0;
        }

        array_unshift( $params, current_time( 'mysql' ) );

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET status = 'deleted', updated_at = %s WHERE " . implode( ' AND ', $where ),
                $params
            )
        );
    }

    /**
     * Get store statistics.
     *
     * @return array{total_vectors: int, dimension: int, storage_size_bytes: int}
     */
    public function stats(): array {
        global $wpdb;

        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'active' AND embedding IS NOT NULL"
        );

        // Rough estimate: dimension floats * 8 bytes per float + JSON overhead
        $per_vector_bytes = $this->dimension * 8 + 64;

        return [
            'total_vectors'      => (int) $total,
            'dimension'          => $this->dimension,
            'storage_size_bytes' => (int) $total * $per_vector_bytes,
        ];
    }

    public function label(): string {
        return __( 'Internal (MySQL)', 'sathi-agentic-ai' );
    }

    public function is_configured(): bool {
        return true;
    }

    // ──────────────────────────────────────────────────────
    //  Additional store-management helpers
    // ──────────────────────────────────────────────────────

    /**
     * Count how many active chunks still need embeddings.
     *
     * @return int
     */
    public function pending_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'active' AND embedding IS NULL"
        );
    }

    /**
     * Fetch chunks that have not yet been embedded.
     *
     * @param  int $limit
     * @return array<int, array{id: int, content: string}>
     */
    public function get_unembedded_chunks( int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, content FROM {$this->table}
                 WHERE status = 'active' AND embedding IS NULL
                 ORDER BY id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get chunks associated with a specific source.
     *
     * @param  int $source_id
     * @return array<int, array<string, mixed>>
     */
    public function get_chunks_by_source( int $source_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, chunk_index, content, token_count, status,
                        embedding IS NOT NULL AS has_embedding,
                        created_at, updated_at
                 FROM {$this->table}
                 WHERE source_id = %d
                 ORDER BY chunk_index ASC",
                $source_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Bulk-delete all vectors (hard reset).
     *
     * Only runs when the safety flag is explicitly passed.
     *
     * @param  bool $confirm  Must be true to proceed.
     * @return int  Number of rows affected.
     */
    public function clear_all( bool $confirm = false ): int {
        if ( ! $confirm ) {
            return 0;
        }

        global $wpdb;
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET embedding = NULL, updated_at = %s WHERE status = 'active'",
                current_time( 'mysql' )
            )
        );
    }

    // ──────────────────────────────────────────────────────
    //  Vector math (private)
    // ──────────────────────────────────────────────────────

    /**
     * Compute cosine similarity between two vectors.
     *
     * cos(a, b) = dot(a, b) / (|a| * |b|)
     *
     * @param  float[]   $a       Query vector.
     * @param  float[]   $b       Stored vector.
     * @param  float|null $b_norm Pre-computed magnitude of $b (optional optimisation).
     * @return float              Similarity in [0, 1] for non-negative embeddings,
     *                            or [-1, 1] in general.
     */
    private function cosine_similarity( array $a, array $b, ?float $a_norm = null ): float {
        $a_norm = $a_norm ?? $this->magnitude( $a );
        $b_norm = $this->magnitude( $b );

        if ( $a_norm < 0.000001 || $b_norm < 0.000001 ) {
            return 0.0;
        }

        $dot  = 0.0;
        $len  = min( count( $a ), count( $b ) );

        for ( $i = 0; $i < $len; $i++ ) {
            $dot += (float) $a[ $i ] * (float) $b[ $i ];
        }

        return $dot / ( $a_norm * $b_norm );
    }

    /**
     * Compute the Euclidean (L2) norm of a vector.
     *
     * @param  float[] $vec
     * @return float
     */
    private function magnitude( array $vec ): float {
        $sum = 0.0;
        foreach ( $vec as $v ) {
            $sum += (float) $v * (float) $v;
        }
        return sqrt( $sum );
    }
}
