<?php
/**
 * Function result value object — the outcome of executing a tool call.
 *
 * @package NeerMedia\Sathi\Core\Data
 */

namespace NeerMedia\Sathi\Core\Data;

class FunctionResult {

    /** @var string Matching call ID */
    public string $id;

    /** @var bool Whether execution succeeded */
    public bool $success;

    /** @var mixed Result payload */
    public $content;

    /** @var string|null Error message on failure */
    public ?string $error;

    /**
     * Private — use named constructors.
     */
    private function __construct( string $id, bool $success, $content, ?string $error = null ) {
        $this->id      = $id;
        $this->success = $success;
        $this->content = $content;
        $this->error   = $error;
    }

    /**
     * Successful result.
     *
     * @param  string $id
     * @param  mixed  $content
     * @return self
     */
    public static function success( string $id, $content ): self {
        return new self( $id, true, $content );
    }

    /**
     * Failed result.
     *
     * @param  string $id
     * @param  string $error
     * @return self
     */
    public static function failure( string $id, string $error ): self {
        return new self( $id, false, null, $error );
    }

    /**
     * Content as string (for API consumption).
     */
    public function as_string(): string {
        if ( $this->error ) {
            return 'Error: ' . $this->error;
        }
        return is_string( $this->content ) ? $this->content : wp_json_encode( $this->content );
    }

    /**
     * Format for OpenAI Responses / Chat Completions API.
     */
    public function to_openai_format(): array {
        return [
            'type'    => 'function_call_output',
            'call_id' => $this->id,
            'output'  => $this->as_string(),
        ];
    }

    /**
     * Format for Anthropic Messages API.
     */
    public function to_anthropic_format(): array {
        return [
            'type'        => 'tool_result',
            'tool_use_id' => $this->id,
            'content'     => [
                [ 'type' => 'text', 'text' => $this->as_string() ],
            ],
        ];
    }

    /**
     * Serialisable array.
     */
    public function to_array(): array {
        return [
            'id'      => $this->id,
            'success' => $this->success,
            'content' => $this->as_string(),
            'error'   => $this->error,
        ];
    }
}
