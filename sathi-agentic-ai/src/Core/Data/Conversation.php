<?php
/**
 * Conversation value object.
 *
 * @package RaiLabs\Sathi\Core\Data
 */

namespace RaiLabs\Sathi\Core\Data;

class Conversation {

    public ?int $id;
    public string $uuid;
    public ?int $user_id;
    public ?string $guest_id;
    public string $persona_id;
    public string $provider;
    public ?string $model;
    public string $status;
    public ?string $title;
    public int $message_count;
    public ?array $metadata;
    public string $created_at;
    public string $updated_at;

    /** @var Message[] */
    public array $messages;

    /**
     * @param array $row Database row.
     */
    public function __construct( array $row = [] ) {
        $this->id            = isset( $row['id'] ) ? (int) $row['id'] : null;
        $this->uuid          = $row['uuid'] ?? '';
        $this->user_id       = isset( $row['user_id'] ) ? (int) $row['user_id'] : null;
        $this->guest_id      = $row['guest_id'] ?? null;
        $this->persona_id    = $row['persona_id'] ?? 'sathi-guru';
        $this->provider      = $row['provider'] ?? 'openai';
        $this->model         = $row['model'] ?? null;
        $this->status        = $row['status'] ?? 'active';
        $this->title         = $row['title'] ?? null;
        $this->message_count = (int) ( $row['message_count'] ?? 0 );
        $this->metadata      = isset( $row['metadata'] )
            ? ( is_array( $row['metadata'] ) ? $row['metadata'] : json_decode( $row['metadata'], true ) )
            : null;
        $this->created_at    = $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' );
        $this->updated_at    = $row['updated_at'] ?? $this->created_at;
        $this->messages      = [];
    }

    /**
     * Add a message to the conversation.
     */
    public function add_message( Message $message ): void {
        $this->messages[]    = $message;
        $this->message_count = count( $this->messages );
    }

    /**
     * Get messages formatted for the active provider.
     *
     * @param  string $format 'openai'|'anthropic'
     * @return array
     */
    public function messages_for( string $format = 'openai' ): array {
        return array_map( function ( Message $m ) use ( $format ) {
            return $format === 'anthropic'
                ? $m->to_anthropic_format()
                : $m->to_openai_format();
        }, $this->messages );
    }

    /**
     * Serialise to array for JSON responses.
     */
    public function to_array(): array {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'user_id'       => $this->user_id,
            'persona_id'    => $this->persona_id,
            'provider'      => $this->provider,
            'model'         => $this->model,
            'status'        => $this->status,
            'title'         => $this->title,
            'message_count' => $this->message_count,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
