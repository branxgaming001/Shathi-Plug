<?php
/**
 * Knowledge Base REST Controller.
 *
 * Endpoints:
 *   GET  /knowledge/search?q=&limit=&mode=        Search (keyword/semantic/hybrid).
 *   POST /knowledge/index                          Trigger batch crawl.
 *   GET  /knowledge/stats                          Knowledge base statistics.
 *   DELETE /knowledge/clear                        Soft-delete all chunks.
 *   POST /knowledge/embeddings/generate            Trigger embedding generation batch.
 *   GET  /knowledge/chunks?source_id=&status=       List chunks for a source.
 *
 * @package RaiLabs\Sathi\Rest
 */

namespace RaiLabs\Sathi\Rest;

use RaiLabs\Sathi\Knowledge\KnowledgeManager;
use WP_REST_Request;
use WP_REST_Response;

class KnowledgeController {

    private const NAMESPACE = 'sathi/v1';

    /**
     * Allowed search modes.
     *
     * @var string[]
     */
    private const ALLOWED_MODES = [ 'keyword', 'semantic', 'hybrid' ];

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        // ── Search ────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'search' ],
            'permission_callback' => [ $this, 'check_public' ],
            'args'                => [
                'q'     => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'limit' => [ 'required' => false, 'type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 50 ],
                'mode'  => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'keyword',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ( $value ) {
                        return in_array( $value, self::ALLOWED_MODES, true );
                    },
                ],
            ],
        ] );

        // ── Index trigger ─────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/index', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'trigger_index' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // ── Statistics ────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'stats' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // ── Clear index ───────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/clear', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'clear' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // ── Embedding generation (NEW) ────────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/embeddings/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_embeddings' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'max_chunks' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 500,
                    'minimum'           => 1,
                    'maximum'           => 5000,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // ── Chunks by source (NEW) ────────────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/chunks', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_chunks' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'source_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // ── Re-index a single source (NEW) ────────────────────
        register_rest_route( self::NAMESPACE, '/knowledge/index/(?P<source_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'index_single' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'source_id' => [ 'required' => true, 'type' => 'integer' ],
                'force'     => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
            ],
        ] );
    }

    // ──────────────────────────────────────────────────────────
    //  Search
    // ──────────────────────────────────────────────────────────

    /**
     * Search the knowledge base.
     *
     * GET /sathi/v1/knowledge/search?q=...&limit=5&mode=keyword|semantic|hybrid
     */
    public function search( WP_REST_Request $request ): WP_REST_Response {
        $query = sanitize_text_field( $request->get_param( 'q' ) );
        $limit = (int) $request->get_param( 'limit' );
        $mode  = sanitize_text_field( $request->get_param( 'mode' ) ?? 'keyword' );

        if ( empty( trim( $query ) ) ) {
            return new WP_REST_Response( [
                'results' => [],
                'mode'    => $mode,
            ], 200 );
        }

        $manager = new KnowledgeManager();

        $results = match ( $mode ) {
            'semantic' => $manager->semanticSearch( $query, $limit ),
            'hybrid'   => $manager->hybridSearch( $query, $limit ),
            default    => $manager->search( $query, $limit ),
        };

        return new WP_REST_Response( [
            'results' => $results,
            'mode'    => $mode,
            'query'   => $query,
        ] );
    }

    // ──────────────────────────────────────────────────────────
    //  Indexing
    // ──────────────────────────────────────────────────────────

    /**
     * Trigger a batched crawl.
     *
     * POST /sathi/v1/knowledge/index
     */
    public function trigger_index( WP_REST_Request $request ): WP_REST_Response {
        $manager = new KnowledgeManager();

        // Client-driven deep scan: the admin calls this repeatedly with an
        // increasing offset and shows a live progress %. Each call does a small
        // slice so the request stays fast and never times out.
        $offset = (int) ( $request->get_param( 'offset' ) ?? 0 );
        $batch  = (int) ( $request->get_param( 'batch' ) ?? 8 );
        $batch  = max( 1, min( 25, $batch ) );

        $result = $manager->scan_slice( $offset, $batch );

        return new WP_REST_Response( array_merge( [ 'success' => true ], $result ) );
    }

    /**
     * Re-index a single source (post/page/product).
     *
     * POST /sathi/v1/knowledge/index/{source_id}?force=true
     */
    public function index_single( WP_REST_Request $request ): WP_REST_Response {
        $source_id = (int) $request->get_param( 'source_id' );
        $force     = (bool) $request->get_param( 'force' );

        $manager = new KnowledgeManager();
        $count   = $manager->index_post( $source_id, $force );

        return new WP_REST_Response( [
            'success'   => true,
            'source_id' => $source_id,
            'chunks'    => $count,
            'message'   => $count > 0
                ? sprintf( __( '%d chunks indexed.', 'sathi-agentic-ai' ), $count )
                : __( 'No changes detected — content is already indexed.', 'sathi-agentic-ai' ),
        ] );
    }

    // ──────────────────────────────────────────────────────────
    //  Embeddings
    // ──────────────────────────────────────────────────────────

    /**
     * Trigger embedding generation.
     *
     * POST /sathi/v1/knowledge/embeddings/generate
     * Body: { "max_chunks": 200 }
     */
    public function generate_embeddings( WP_REST_Request $request ): WP_REST_Response {
        $max_chunks = (int) $request->get_param( 'max_chunks' );

        $manager  = new KnowledgeManager();
        $embedded = $manager->generate_all_embeddings( $max_chunks );

        $pending = $manager->get_vector_store()->pending_count();

        return new WP_REST_Response( [
            'success'    => true,
            'embedded'   => $embedded,
            'pending'    => $pending,
            'max_chunks' => $max_chunks,
            'message'    => $embedded > 0
                ? sprintf(
                    /* translators: 1: number embedded, 2: number still pending */
                    __( '%1$d chunks embedded. %2$d remain pending.', 'sathi-agentic-ai' ),
                    $embedded,
                    $pending
                )
                : __( 'No unembedded chunks found.', 'sathi-agentic-ai' ),
        ] );
    }

    // ──────────────────────────────────────────────────────────
    //  Chunks
    // ──────────────────────────────────────────────────────────

    /**
     * List chunks for a specific source.
     *
     * GET /sathi/v1/knowledge/chunks?source_id=123
     */
    public function get_chunks( WP_REST_Request $request ): WP_REST_Response {
        $source_id = (int) $request->get_param( 'source_id' );

        $manager = new KnowledgeManager();
        $chunks  = $manager->get_chunks_by_source( $source_id );

        // Enrich with source post title
        $post_title = get_the_title( $source_id ) ?: null;

        return new WP_REST_Response( [
            'source_id'   => $source_id,
            'source_title'=> $post_title,
            'source_url'  => get_permalink( $source_id ) ?: null,
            'chunks'      => array_map( function ( $row ) {
                return [
                    'id'            => (int) $row['id'],
                    'chunk_index'   => (int) $row['chunk_index'],
                    'content'       => $row['content'],
                    'token_count'   => (int) $row['token_count'],
                    'has_embedding' => (bool) $row['has_embedding'],
                    'status'        => $row['status'],
                    'created_at'    => $row['created_at'],
                    'updated_at'    => $row['updated_at'],
                ];
            }, $chunks ),
            'total'       => count( $chunks ),
        ] );
    }

    // ──────────────────────────────────────────────────────────
    //  Stats / Clear
    // ──────────────────────────────────────────────────────────

    /**
     * Get knowledge base statistics.
     *
     * GET /sathi/v1/knowledge/stats
     */
    public function stats( WP_REST_Request $request ): WP_REST_Response {
        $manager = new KnowledgeManager();
        return new WP_REST_Response( $manager->get_stats() );
    }

    /**
     * Clear the entire knowledge base.
     *
     * DELETE /sathi/v1/knowledge/clear
     */
    public function clear( WP_REST_Request $request ): WP_REST_Response {
        $manager  = new KnowledgeManager();
        $affected = $manager->clear_index();

        return new WP_REST_Response( [
            'success'  => true,
            'affected' => $affected,
            'message'  => sprintf(
                __( '%d chunks marked as deleted.', 'sathi-agentic-ai' ),
                $affected
            ),
        ] );
    }

    // ──────────────────────────────────────────────────────────
    //  Permissions
    // ──────────────────────────────────────────────────────────

    /**
     * Public endpoint — no authentication required.
     */
    public function check_public(): bool {
        return true;
    }

    /**
     * Admin-only endpoint — requires manage_options capability.
     */
    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }
}
