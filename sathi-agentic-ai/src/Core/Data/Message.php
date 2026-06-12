<?php
/**
 * Message value object — immutable record of a single chat turn.
 *
 * @package RaiLabs\Sathi\Core\Data
 */

namespace RaiLabs\Sathi\Core\Data;

class Message {

    /** @var string role: system|user|assistant|tool|function */
    public string $role;

    /** @var string Message body */
    public string $content;

    /** @var array|null Serialised tool calls */
    public ?array $tool_calls;

    /** @var array|null Tool result payload */
    public ?array $tool_result;

    /** @var int|null Estimated tokens */
    public ?int $token_count;

    /** @var array Arbitrary metadata */
    public array $metadata;

    /** @var string|null Timestamp */
    public ?string $created_at;

    /**
     * @param string      $role
     * @param string      $content
     * @param array|null  $tool_calls
     * @param array|null  $tool_result
     * @param int|null    $token_count
     * @param array       $metadata
     * @param string|null $created_at
     */
    public function __construct(
        string $role,
        string $content = '',
        ?array $tool_calls = null,
        ?array $tool_result = null,
        ?int $token_count = null,
        array $metadata = [],
        ?string $created_at = null
    ) {
        $this->role        = $role;
        $this->content     = $content;
        $this->tool_calls  = $tool_calls;
        $this->tool_result = $tool_result;
        $this->token_count = $token_count;
        $this->metadata    = $metadata;
        $this->created_at  = $created_at ?? gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * Create a user message.
     */
    public static function user( string $content, array $metadata = [] ): self {
        return new self( 'user', $content, null, null, null, $metadata );
    }

    /**
     * Create an assistant message.
     */
    public static function assistant( string $content, ?int $tokens = null ): self {
        return new self( 'assistant', $content, null, null, $tokens );
    }

    /**
     * Create a system message.
     */
    public static function system( string $content ): self {
        return new self( 'system', $content );
    }

    /**
     * Create a function/tool result message.
     */
    public static function tool( string $call_id, string $name, string $result ): self {
        return new self( 'tool', $result, null, [
            'call_id' => $call_id,
            'name'    => $name,
        ] );
    }

    /**
     * Convert to the OpenAI-compatible array format.
     *
     * @return array{role: string, content: string, tool_calls?: array, tool_call_id?: string, name?: string}
     */
    public function to_openai_format(): array {
        $payload = [
            'role'    => $this->role,
            'content' => $this->content,
        ];

        if ( $this->tool_calls ) {
            $payload['tool_calls'] = $this->tool_calls;
        }

        if ( $this->tool_result ) {
            $payload['tool_call_id'] = $this->tool_result['call_id'] ?? '';
            $payload['name']         = $this->tool_result['name'] ?? '';
        }

        return $payload;
    }

    /**
     * Convert to the Anthropic-compatible array format.
     *
     * @return array{role: string, content: array|string}
     */
    public function to_anthropic_format(): array {
        return [
            'role'    => $this->role,
            'content' => $this->content,
        ];
    }

    /**
     * Convert to serialisable storage format.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'role'        => $this->role,
            'content'     => $this->content,
            'tool_calls'  => $this->tool_calls,
            'tool_result' => $this->tool_result,
            'token_count' => $this->token_count,
            'metadata'    => $this->metadata,
            'created_at'  => $this->created_at,
        ];
    }
}
