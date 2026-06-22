<?php
/**
 * Function call value object — a tool/function invocation requested by the LLM.
 *
 * @package NeerMedia\Sathi\Core\Data
 */

namespace NeerMedia\Sathi\Core\Data;

class FunctionCall {

    /** @var string Unique call identifier from the provider */
    public string $id;

    /** @var string Function name */
    public string $name;

    /** @var string JSON-encoded arguments */
    public string $arguments;

    /**
     * @param string $id
     * @param string $name
     * @param string $arguments JSON string
     */
    public function __construct( string $id, string $name, string $arguments ) {
        $this->id        = $id;
        $this->name      = $name;
        $this->arguments = $arguments;
    }

    /**
     * Create from OpenAI tool call format.
     *
     * @param  array{id: string, function: array{name: string, arguments: string}} $tool_call
     * @return self
     */
    public static function from_openai( array $tool_call ): self {
        return new self(
            $tool_call['id'],
            $tool_call['function']['name'],
            $tool_call['function']['arguments']
        );
    }

    /**
     * Create from Anthropic tool use block.
     *
     * @param  array{id: string, name: string, input: array} $tool_use
     * @return self
     */
    public static function from_anthropic( array $tool_use ): self {
        return new self(
            $tool_use['id'],
            $tool_use['name'],
            wp_json_encode( $tool_use['input'] )
        );
    }

    /**
     * Get arguments as decoded associative array.
     *
     * @return array
     */
    public function args(): array {
        $decoded = json_decode( $this->arguments, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Get arguments as JSON string.
     */
    public function json(): string {
        return $this->arguments;
    }

    /**
     * Convert to provider-agnostic storage format.
     */
    public function to_array(): array {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
