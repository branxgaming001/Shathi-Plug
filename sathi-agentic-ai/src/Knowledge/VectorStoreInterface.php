<?php
/**
 * Vector Store Interface — standard contract for embedding storage backends.
 *
 * Every vector store adapter (internal MySQL, Pinecone, Qdrant, Chroma, etc.)
 * MUST implement this contract so the KnowledgeManager can swap backends
 * without changing any calling code.
 *
 * @package NeerMedia\Sathi\Knowledge
 */

namespace NeerMedia\Sathi\Knowledge;

interface VectorStoreInterface {

    /**
     * Upsert vectors — insert or update.
     *
     * Each entry in the $vectors array MUST contain:
     *   - 'id'     => mixed   Unique identifier for the vector (chunk ID).
     *   - 'vector' => float[]  The embedding vector (array of floats).
     *   - 'metadata' => array  (optional) Arbitrary key-value metadata.
     *
     * @param  array<int, array{id: mixed, vector: float[], metadata?: array<string, mixed>}> $vectors
     * @return int Number of vectors successfully upserted.
     */
    public function upsert( array $vectors ): int;

    /**
     * Search for the nearest vectors to a query vector.
     *
     * @param  float[] $query_vector The query embedding.
     * @param  int     $limit         Maximum results to return.
     * @param  array<string, mixed> $filters Optional metadata filters
     *                                (e.g. ['source_type' => 'product', 'source_id' => 42]).
     * @return array<int, array{id: mixed, score: float, metadata: array<string, mixed>}>
     *                                Results sorted by relevance score descending.
     */
    public function search( array $query_vector, int $limit = 5, array $filters = [] ): array;

    /**
     * Delete vectors matching the given filters.
     *
     * If no filters are provided, implementations MAY refuse to delete
     * everything (safety catch). Use an explicit filter like
     * ['source_id' => 123] to target specific vectors.
     *
     * @param  array<string, mixed> $filters Key-value pairs to match.
     * @return int Number of vectors deleted (or soft-deleted).
     */
    public function delete( array $filters ): int;

    /**
     * Get store statistics.
     *
     * @return array{total_vectors: int, dimension: int, storage_size_bytes?: int}
     *         total_vectors — count of vectors currently stored.
     *         dimension     — the embedding dimension this store is configured for.
     *         storage_size_bytes — optional, for external stores that report usage.
     */
    public function stats(): array;

    /**
     * Return a human-readable label for the admin UI.
     *
     * @return string e.g. "Internal (MySQL)", "Pinecone", "Qdrant Cloud".
     */
    public function label(): string;

    /**
     * Whether this store is properly configured and reachable.
     *
     * For the internal store this always returns true; for external
     * stores this checks API keys, endpoint connectivity, etc.
     *
     * @return bool
     */
    public function is_configured(): bool;
}
