<?php
/**
 * Knowledge Manager — site content indexing, chunking, and semantic search.
 *
 * Coordinates SiteCrawler (content extraction), chunk storage, InternalVectorStore
 * (embedding storage / cosine search), and the AI provider Factory (embedding
 * generation). Supports three search modes: keyword, semantic, and hybrid.
 *
 * Auto-indexes content on save_post and runs a background cron for embedding
 * generation so that large sites are indexed progressively.
 *
 * @package RaiLabs\Sathi\Knowledge
 */

namespace RaiLabs\Sathi\Knowledge;

use RaiLabs\Sathi\Providers\Factory;
use RaiLabs\Sathi\Support\Helpers;
use WP_Post;

class KnowledgeManager {

    /** @var string Fully-qualified chunks table name. */
    private string $chunks_table;

    /** @var SiteCrawler */
    private SiteCrawler $crawler;

    /** @var Factory Provider factory for embed() calls. */
    private Factory $factory;

    /** @var InternalVectorStore */
    private InternalVectorStore $vector_store;

    /** @var int How many chunks to embed per cron batch. */
    private int $embed_batch_size;

    public function __construct() {
        global $wpdb;
        $this->chunks_table    = $wpdb->prefix . 'sathi_knowledge_chunks';
        $this->crawler         = new SiteCrawler();
        $this->factory         = new Factory( new \RaiLabs\Sathi\Core\Settings() );
        $this->vector_store    = new InternalVectorStore();
        $this->embed_batch_size = (int) apply_filters( 'sathi_embed_batch_size', 20 );
    }

    // ──────────────────────────────────────────────────────────
    //  Hook registration
    // ──────────────────────────────────────────────────────────

    /**
     * Register cron hooks and auto-index handlers.
     *
     * Called from Plugin::on_init().
     */
    public function register_cron(): void {
        // Register the 'every_minute' schedule if not already present
        add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_intervals' ] );

        // Batched full-site crawl (existing)
        add_action( 'sathi_knowledge_crawl', [ $this, 'crawl_batch' ] );

        if ( ! wp_next_scheduled( 'sathi_knowledge_crawl' ) && get_option( 'sathi_knowledge_auto_crawl', true ) ) {
            $interval = get_option( 'sathi_knowledge_crawl_interval', 'daily' );
            wp_schedule_event( time(), $interval, 'sathi_knowledge_crawl' );
        }

        // Background embedding generation
        add_action( 'sathi_knowledge_generate_embeddings', [ $this, 'generate_embeddings_batch' ] );

        if ( ! wp_next_scheduled( 'sathi_knowledge_generate_embeddings' ) ) {
            wp_schedule_event( time(), 'every_minute', 'sathi_knowledge_generate_embeddings' );
        }

        // Auto-index on post save/update
        add_action( 'save_post', [ $this, 'on_save_post' ], 20, 3 );
    }

    /**
     * Returns whether the custom cron intervals include our 'every_minute' schedule.
     *
     * Call this from a 'cron_schedules' filter registration if needed.
     *
     * @param  array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function register_cron_intervals( array $schedules ): array {
        if ( ! isset( $schedules['every_minute'] ) ) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every Minute', 'sathi-agentic-ai' ),
            ];
        }
        return $schedules;
    }

    // ──────────────────────────────────────────────────────────
    //  Batched crawling (existing, unchanged)
    // ──────────────────────────────────────────────────────────

    /**
     * Index the entire site in batches (used by cron).
     */
    public function crawl_batch(): void {
        $batch_size = apply_filters( 'sathi_knowledge_batch_size', 20 );
        $offset     = (int) get_transient( 'sathi_knowledge_offset' );
        if ( $offset < 0 ) {
            $offset = 0;
        }

        $chunks = $this->crawler->crawl_all( $batch_size, $offset );

        if ( empty( $chunks ) ) {
            delete_transient( 'sathi_knowledge_offset' );
            update_option( 'sathi_knowledge_last_crawl', current_time( 'mysql' ) );
            return;
        }

        $this->store_chunks( $chunks );

        set_transient( 'sathi_knowledge_offset', $offset + $batch_size, HOUR_IN_SECONDS );
    }

    /**
     * Total indexable items (for scan progress).
     */
    public function count_all(): int {
        return $this->crawler->count_all();
    }

    /**
     * Crawl + index one slice of the site. Client-driven so a full deep scan
     * runs as a series of short requests with a live progress percentage.
     *
     * @param  int $offset
     * @param  int $limit
     * @return array{processed:int,total:int,next:int,done:bool,chunks:int}
     */
    public function scan_slice( int $offset, int $limit = 8 ): array {
        $offset = max( 0, $offset );
        $total  = $this->crawler->count_all();

        // At the start of a full scan, (re)index the sitewide header + footer
        // once so the bot has the real business/contact info that themes keep
        // there (and not only inside individual pages).
        if ( $offset === 0 ) {
            $parts = $this->crawler->crawl_site_parts();
            if ( ! empty( $parts ) ) {
                $this->store_chunks( $parts );
            }
        }

        $chunks = $this->crawler->crawl_all( $limit, $offset );
        if ( ! empty( $chunks ) ) {
            $this->store_chunks( $chunks );
        }
        $next = $offset + $limit;
        $done = $next >= $total;
        if ( $done ) {
            // Drop anything that is no longer published (trashed, set to draft,
            // made private, deleted, or excluded) so the KB reflects ONLY
            // current published content.
            $this->prune_orphans();
            update_option( 'sathi_knowledge_last_crawl', current_time( 'mysql' ) );
        }
        return [
            'processed' => min( $next, $total ),
            'total'     => $total,
            'next'      => $next,
            'done'      => $done,
            'chunks'    => count( $chunks ),
        ];
    }

    /**
     * Soft-delete active chunks whose source is no longer a published,
     * indexable post — i.e. trashed, drafted, made private, deleted, or
     * excluded via _sathi_exclude. The dedicated header/footer site-parts are
     * always kept. Runs at the end of a full deep scan so the knowledge base
     * never serves stale content from pages that have since been removed.
     */
    private function prune_orphans(): void {
        global $wpdb;

        $published = get_posts( [
            'post_type'        => $this->crawler->get_content_types(),
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'has_password'     => false,
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [ [ 'key' => '_sathi_exclude', 'compare' => 'NOT EXISTS' ] ],
        ] );

        $keep = array_map( 'intval', (array) $published );
        // Always keep the sitewide header/footer sources.
        $keep[] = SiteCrawler::SOURCE_HEADER;
        $keep[] = SiteCrawler::SOURCE_FOOTER;

        $placeholders = implode( ',', array_fill( 0, count( $keep ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->chunks_table}
             SET status = 'deleted', updated_at = %s
             WHERE status = 'active' AND ( source_id IS NULL OR source_id NOT IN ( {$placeholders} ) )",
            array_merge( [ current_time( 'mysql' ) ], $keep )
        ) );
    }

    /**
     * Index a single post immediately.
     *
     * @param  int   $post_id
     * @param  bool  $force Force re-index even if unchanged.
     * @return int   Number of chunks stored.
     */
    public function index_post( int $post_id, bool $force = false ): int {
        global $wpdb;

        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' || post_password_required( $post ) ) {
            return 0;
        }

        $checksum = hash( 'sha256', $post->post_content . $post->post_title . $post->post_modified );

        if ( ! $force ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->chunks_table} WHERE source_id = %d AND checksum = %s AND status = 'active'",
                $post_id, $checksum
            ) );
            if ( $existing > 0 ) {
                return 0;
            }
        }

        // Mark old chunks stale
        $wpdb->update(
            $this->chunks_table,
            [ 'status' => 'stale' ],
            [ 'source_id' => $post_id, 'status' => 'active' ]
        );

        // Crawl and store
        $chunks = $this->crawler->crawl_post( $post_id );
        $this->store_chunks( $chunks );

        return count( $chunks );
    }

    /**
     * Store chunks in the database.
     *
     * @param array[] $chunks
     */
    private function store_chunks( array $chunks ): void {
        global $wpdb;

        if ( empty( $chunks ) ) {
            return;
        }

        // Per-source replace: before inserting a source's fresh chunks, retire
        // its previous active chunks. This keeps re-scans idempotent (no
        // duplicates) and guarantees stale text for a source is dropped.
        $source_ids = array_unique( array_filter(
            array_map( static fn( $c ) => $c['source_id'] ?? null, $chunks ),
            static fn( $v ) => $v !== null
        ) );
        foreach ( $source_ids as $sid ) {
            $wpdb->update(
                $this->chunks_table,
                [ 'status' => 'deleted', 'updated_at' => current_time( 'mysql' ) ],
                [ 'source_id' => (int) $sid, 'status' => 'active' ]
            );
        }

        foreach ( $chunks as $chunk ) {
            $wpdb->insert( $this->chunks_table, [
                'source_url'   => $chunk['source_url'],
                'source_type'  => $chunk['source_type'] ?? 'post',
                'source_id'    => $chunk['source_id'] ?? null,
                'chunk_index'  => $chunk['chunk_index'] ?? 0,
                'content'      => $chunk['content'],
                'token_count'  => Helpers::estimate_tokens( $chunk['content'] ),
                'checksum'     => hash( 'sha256', $chunk['content'] ),
                'status'       => 'active',
                'created_at'   => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' ),
            ] );
        }
    }

    // ──────────────────────────────────────────────────────────
    //  save_post auto-index
    // ──────────────────────────────────────────────────────────

    /**
     * Automatically re-index a post when it is saved or published.
     *
     * Hooked to 'save_post' at priority 20.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update  Whether this is an existing post being updated.
     */
    public function on_save_post( int $post_id, WP_Post $post, bool $update ): void {
        // Ignore autosaves, revisions, and trashed items
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( $post->post_status === 'trash' ) {
            // Soft-delete chunks for trashed posts
            $this->vector_store->delete( [ 'source_id' => $post_id ] );
            global $wpdb;
            $wpdb->update(
                $this->chunks_table,
                [ 'status' => 'deleted', 'updated_at' => current_time( 'mysql' ) ],
                [ 'source_id' => $post_id ]
            );
            return;
        }

        if ( $post->post_status !== 'publish' ) {
            return;
        }

        $allowed_types = apply_filters( 'sathi_knowledge_post_types', [ 'post', 'page', 'product' ] );
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return;
        }

        // Check if post is excluded from indexing
        if ( get_post_meta( $post_id, '_sathi_exclude', true ) ) {
            return;
        }

        // Index (won't re-index if checksum is unchanged)
        $this->index_post( $post_id, false );
    }

    // ──────────────────────────────────────────────────────────
    //  Embedding generation
    // ──────────────────────────────────────────────────────────

    /**
     * Generate and store an embedding for a single chunk.
     *
     * @param  int  $chunk_id  The chunk database ID.
     * @return bool            True on success, false on failure.
     */
    public function embedAndStore( int $chunk_id ): bool {
        global $wpdb;

        $chunk = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, content FROM {$this->chunks_table} WHERE id = %d AND status = 'active'",
            $chunk_id
        ), ARRAY_A );

        if ( ! $chunk ) {
            return false;
        }

        try {
            $provider = $this->factory->for_embeddings();
            $vector   = $provider->embed( $chunk['content'], [ 'model' => $this->factory->embedding_model() ] );
        } catch ( \Throwable $e ) {
            // Log failure but don't crash — the chunk remains in the
            // unembedded queue for the next cron run.
            return false;
        }

        if ( empty( $vector ) ) {
            return false;
        }

        $result = $this->vector_store->upsert( [
            [
                'id'     => (int) $chunk_id,
                'vector' => $vector,
            ],
        ] );

        return $result > 0;
    }

    /**
     * Cron callback: generate embeddings for a batch of unembedded chunks.
     *
     * Processes up to $embed_batch_size chunks per invocation so that
     * large sites are embedded gradually without timing out.
     */
    public function generate_embeddings_batch(): void {
        $chunks = $this->vector_store->get_unembedded_chunks( $this->embed_batch_size );

        if ( empty( $chunks ) ) {
            return;
        }

        // Build a batch of texts for the provider
        $texts = array_column( $chunks, 'content' );
        $ids   = array_column( $chunks, 'id' );

        try {
            $provider = $this->factory->for_embeddings();
        } catch ( \Throwable $e ) {
            return; // No embed provider configured — nothing to do
        }

        try {
            $vectors = $provider->embed( $texts, [ 'model' => $this->factory->embedding_model() ] ); // Batch embed
        } catch ( \Throwable $e ) {
            // If batch API fails, fall back to one-at-a-time
            $vectors = [];
            foreach ( $chunks as $chunk ) {
                try {
                    $vectors[] = $provider->embed( (string) $chunk['content'], [ 'model' => $this->factory->embedding_model() ] );
                } catch ( \Throwable $e2 ) {
                    $vectors[] = null; // Placeholder for failed
                }
            }
        }

        // Build upsert payload
        $upsert_payload = [];
        foreach ( $ids as $i => $chunk_id ) {
            $vector = $vectors[ $i ] ?? null;
            if ( ! is_array( $vector ) || empty( $vector ) ) {
                continue;
            }
            $upsert_payload[] = [
                'id'     => (int) $chunk_id,
                'vector' => $vector,
            ];
        }

        if ( ! empty( $upsert_payload ) ) {
            $this->vector_store->upsert( $upsert_payload );
        }
    }

    /**
     * Manually trigger embedding generation for all unembedded chunks.
     *
     * Used by the REST endpoint to force full re-embedding.
     *
     * @param  int $max_chunks  Upper limit (safety cap, default 500).
     * @return int              Number of chunks embedded.
     */
    public function generate_all_embeddings( int $max_chunks = 500 ): int {
        $total   = 0;
        $per_run = min( $this->embed_batch_size, $max_chunks );

        try {
            $provider = $this->factory->for_embeddings();
        } catch ( \Throwable $e ) {
            return 0; // No embed provider configured
        }

        while ( $total < $max_chunks ) {
            $chunks = $this->vector_store->get_unembedded_chunks( $per_run );
            if ( empty( $chunks ) ) {
                break;
            }

            $texts = array_column( $chunks, 'content' );
            $ids   = array_column( $chunks, 'id' );

            try {
                $vectors = $provider->embed( $texts, [ 'model' => $this->factory->embedding_model() ] );
            } catch ( \Throwable $e ) {
                $vectors = [];
                foreach ( $chunks as $chunk ) {
                    try {
                        $vectors[] = $provider->embed( (string) $chunk['content'], [ 'model' => $this->factory->embedding_model() ] );
                    } catch ( \Throwable $e2 ) {
                        $vectors[] = null;
                    }
                }
            }

            $upsert_payload = [];
            foreach ( $ids as $i => $chunk_id ) {
                if ( ! is_array( $vectors[ $i ] ?? null ) || empty( $vectors[ $i ] ) ) {
                    continue;
                }
                $upsert_payload[] = [
                    'id'     => (int) $chunk_id,
                    'vector' => $vectors[ $i ],
                ];
            }

            if ( ! empty( $upsert_payload ) ) {
                $this->vector_store->upsert( $upsert_payload );
                $total += count( $upsert_payload );
            }

            // Safety sleep to avoid hammering the provider API
            if ( $total + $per_run <= $max_chunks && ! empty( $chunks ) ) {
                usleep( 100000 ); // 100ms between batches
            }
        }

        return $total;
    }

    // ──────────────────────────────────────────────────────────
    //  Search methods
    // ──────────────────────────────────────────────────────────

    /**
     * Keyword search (existing, unchanged).
     *
     * @param  string $query
     * @param  int    $limit
     * @return array[]
     */
    public function search( string $query, int $limit = 5 ): array {
        global $wpdb;

        $search_terms = array_filter( explode( ' ', trim( $query ) ) );
        if ( empty( $search_terms ) ) {
            return [];
        }

        $conditions = [];
        $params     = [ 'active' ];

        foreach ( $search_terms as $term ) {
            $conditions[] = 'content LIKE %s';
            $params[]     = '%' . $wpdb->esc_like( $term ) . '%';
        }

        $where = implode( ' OR ', $conditions );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_url, source_type, source_id, chunk_index, content, token_count
             FROM {$this->chunks_table}
             WHERE status = %s AND ({$where})
             ORDER BY token_count DESC
             LIMIT %d",
            array_merge( $params, [ $limit ] )
        ), ARRAY_A );

        return array_map( function ( $row ) {
            $row['id']         = (int) $row['id'];
            $row['source_id']  = $row['source_id'] ? (int) $row['source_id'] : null;
            $row['chunk_index'] = (int) $row['chunk_index'];
            $row['token_count'] = (int) $row['token_count'];
            $row['excerpt']     = Helpers::clean_text( $row['content'], 300 );
            return $row;
        }, $results ?: [] );
    }

    /**
     * Semantic (embedding-based) search.
     *
     * Generates an embedding for the query text and performs cosine
     * similarity search against all stored chunk vectors.
     *
     * @param  string $query  Natural-language query.
     * @param  int    $limit  Max results to return.
     * @param  array  $filters Optional: ['source_type' => 'product']
     * @return array<int, array{id: int, score: float, metadata: array}>
     */
    public function semanticSearch( string $query, int $limit = 5, array $filters = [] ): array {
        try {
            $provider = $this->factory->for_embeddings();
            $query_vector = $provider->embed( $query, [ 'model' => $this->factory->embedding_model() ] );
        } catch ( \Throwable $e ) {
            return []; // Provider unavailable — fall back gracefully
        }

        if ( empty( $query_vector ) ) {
            return [];
        }

        return $this->vector_store->search( $query_vector, $limit, $filters );
    }

    /**
     * Hybrid search — combines keyword and semantic results with dedup.
     *
     * Algorithm:
     *   1. Run keyword search (1.5x requested limit for wider recall).
     *   2. Run semantic search (1.5x requested limit for wider recall).
     *   3. Score keyword results by position (later = lower score).
     *   4. Score semantic results by cosine similarity.
     *   5. Merge: for chunks appearing in BOTH, keep the higher score and
     *      add a +0.15 boost (they matched both signals).
     *   6. Sort by combined score descending, return top $limit.
     *
     * @param  string $query
     * @param  int    $limit
     * @param  array  $filters Optional filters passed to semantic search.
     * @return array<int, array>
     */
    public function hybridSearch( string $query, int $limit = 5, array $filters = [] ): array {
        $recall_limit = max( $limit * 2, 10 );

        // ── Run both searches in parallel (sequential in PHP, but
        //     semantic failure won't block keyword) ─────────────────
        $keyword_results   = $this->search( $query, $recall_limit );
        $semantic_results  = $this->semanticSearch( $query, $recall_limit, $filters );

        // ── Build unified scored map keyed by chunk ID ────────────
        $combined = [];

        // Score keyword results by position:
        //   position 0 → 1.00, position N → 1.00 / (1 + N * 0.1)
        foreach ( $keyword_results as $pos => $row ) {
            $chunk_id            = $row['id'];
            $score               = 1.0 / ( 1.0 + $pos * 0.1 );
            $combined[ $chunk_id ] = [
                'id'            => $chunk_id,
                'score'         => $score,
                'semantic_score'=> 0.0,
                'keyword_score' => $score,
                'excerpt'       => $row['excerpt'] ?? Helpers::clean_text( $row['content'] ?? '', 300 ),
                'source_url'    => $row['source_url'] ?? '',
                'source_type'   => $row['source_type'] ?? 'post',
                'source_id'     => $row['source_id'] ?? null,
                'tokens'        => $row['token_count'] ?? 0,
            ];
        }

        // Merge semantic results
        foreach ( $semantic_results as $result ) {
            $chunk_id = $result['id'];
            $cos_score = (float) ( $result['score'] ?? 0 );

            if ( isset( $combined[ $chunk_id ] ) ) {
                // Chunk appears in both — boost it
                $existing = $combined[ $chunk_id ];
                $combined[ $chunk_id ]['semantic_score']  = $cos_score;
                $combined[ $chunk_id ]['score']           = max( $existing['score'], $cos_score ) + 0.15;
                $combined[ $chunk_id ]['matched_by']      = 'both';
                // Prefer semantic metadata when available
                if ( ! empty( $result['metadata']['content'] ) ) {
                    $combined[ $chunk_id ]['excerpt'] = Helpers::clean_text(
                        $result['metadata']['content'], 300
                    );
                }
            } else {
                $combined[ $chunk_id ] = [
                    'id'             => $chunk_id,
                    'score'          => $cos_score,
                    'semantic_score' => $cos_score,
                    'keyword_score'  => 0.0,
                    'matched_by'     => 'semantic',
                    'excerpt'        => Helpers::clean_text( $result['metadata']['content'] ?? '', 300 ),
                    'source_url'     => $result['metadata']['source_url'] ?? '',
                    'source_type'    => $result['metadata']['source_type'] ?? 'post',
                    'source_id'      => $result['metadata']['source_id'] ?? null,
                    'tokens'         => (int) ( $result['metadata']['tokens'] ?? 0 ),
                ];
            }
        }

        // ── Sort by combined score descending ─────────────────────
        $sorted = array_values( $combined );
        usort( $sorted, fn( array $a, array $b ) => $b['score'] <=> $a['score'] );

        // ── Return top N with unified shape ───────────────────────
        $results = array_slice( $sorted, 0, $limit );

        return array_map( function ( array $item ) {
            return [
                'id'             => $item['id'],
                'score'          => round( $item['score'], 6 ),
                'semantic_score' => round( $item['semantic_score'], 6 ),
                'keyword_score'  => round( $item['keyword_score'], 6 ),
                'matched_by'     => $item['matched_by'] ?? 'keyword',
                'excerpt'        => $item['excerpt'],
                'source_url'     => $item['source_url'],
                'source_type'    => $item['source_type'],
                'source_id'      => $item['source_id'],
                'tokens'         => $item['tokens'],
            ];
        }, $results );
    }

    // ──────────────────────────────────────────────────────────
    //  Statistics and maintenance
    // ──────────────────────────────────────────────────────────

    /**
     * Get knowledge base statistics.
     *
     * @return array{total_chunks: int, total_sources: int, total_tokens: int,
     *               embedded_chunks: int, pending_embeddings: int,
     *               last_crawl: string|null}
     */
    public function get_stats(): array {
        global $wpdb;

        $vector_stats = $this->vector_store->stats();

        return [
            'total_chunks'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->chunks_table} WHERE status = 'active'" ),
            'total_sources'      => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT source_id) FROM {$this->chunks_table} WHERE status = 'active'" ),
            'total_tokens'       => (int) $wpdb->get_var( "SELECT COALESCE(SUM(token_count), 0) FROM {$this->chunks_table} WHERE status = 'active'" ),
            'embedded_chunks'    => $vector_stats['total_vectors'],
            'pending_embeddings' => $this->vector_store->pending_count(),
            'last_crawl'         => get_option( 'sathi_knowledge_last_crawl' ),
        ];
    }

    /**
     * Clear the entire knowledge base (soft-delete all active chunks).
     *
     * @return int Number of chunks marked deleted.
     */
    public function clear_index(): int {
        global $wpdb;
        return $wpdb->update(
            $this->chunks_table,
            [ 'status' => 'deleted' ],
            [ 'status' => 'active' ]
        );
    }

    /**
     * Get chunks belonging to a specific source.
     *
     * @param  int $source_id
     * @return array<int, array<string, mixed>>
     */
    public function get_chunks_by_source( int $source_id ): array {
        return $this->vector_store->get_chunks_by_source( $source_id );
    }

    /**
     * Get the vector store instance (for admin diagnostics).
     *
     * @return InternalVectorStore
     */
    public function get_vector_store(): InternalVectorStore {
        return $this->vector_store;
    }
}
