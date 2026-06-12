<?php
/**
 * Agent Manager — orchestrates the agent loop with tool execution.
 *
 * @package RaiLabs\Sathi\Agent
 */

namespace RaiLabs\Sathi\Agent;

use RaiLabs\Sathi\Core\Data\Conversation;
use RaiLabs\Sathi\Core\Data\Message;
use RaiLabs\Sathi\Core\Data\FunctionCall;
use RaiLabs\Sathi\Core\Data\FunctionResult;
use RaiLabs\Sathi\Personas\PersonaRegistry;
use RaiLabs\Sathi\Personas\PromptComposer;
use RaiLabs\Sathi\Providers\Factory;
use RaiLabs\Sathi\Support\Helpers;

class AgentManager {

    private Factory $factory;
    private PersonaRegistry $personas;
    private PromptComposer $composer;
    private int $max_rounds;

    public function __construct( Factory $factory, PersonaRegistry $personas ) {
        $this->factory    = $factory;
        $this->personas   = $personas;
        $this->composer   = new PromptComposer();
        $this->max_rounds = apply_filters( 'sathi_agent_max_rounds', 5 );
    }

    /**
     * Process a conversation through the agent loop.
     *
     * Runs: LLM call → parse tool_calls → execute tools → feed results → repeat
     * until the model returns a plain text response or max rounds reached.
     *
     * @param  Conversation $conv
     * @param  array        $available_tools Tool definitions { name, description, parameters, callback }.
     * @param  callable     $on_token        Streaming callback.
     * @return Message                       Final assistant response.
     * @throws \RuntimeException
     */
    public function process( Conversation $conv, array $available_tools = [], callable $on_token = null ): Message {
        $provider      = $this->factory->for_task( 'chat' );
        $system_prompt = $this->composer->compose( $conv->persona_id, $this->get_context( $conv ) );

        $messages = $conv->messages;
        array_unshift( $messages, Message::system( $system_prompt ) );

        $tools    = $this->prepare_tools( $available_tools );
        $options  = [ 'system_prompt' => $system_prompt, 'tools' => $tools ];

        // First LLM call
        $response = $on_token
            ? $provider->chat_stream( $messages, $on_token, $options )
            : $provider->chat( $messages, $options );

        $rounds = 0;

        // Agent loop — process tool calls
        while ( $this->has_tool_calls( $response ) && $rounds < $this->max_rounds ) {
            $rounds++;

            $tool_calls = $this->parse_tool_calls( $response );
            $results    = [];

            foreach ( $tool_calls as $call ) {
                $result = $this->execute_tool( $call, $available_tools );
                $results[] = $result;
            }

            // Append assistant message (with tool calls) + tool results to history
            $messages[] = $response;
            foreach ( $results as $result ) {
                $messages[] = Message::tool(
                    $result->id,
                    '', // name not needed for tool result
                    $result->as_string()
                );
            }

            // Run LLM again with tool results
            $response = $provider->chat( $messages, $options );
        }

        if ( $this->has_tool_calls( $response ) && $rounds >= $this->max_rounds ) {
            // Max rounds reached — add a note
            $response->content .= "\n\n" . __( '(Agent loop limit reached — some tools may not have completed.)', 'sathi-agentic-ai' );
        }

        return $response;
    }

    /**
     * Check if a message contains tool calls.
     */
    private function has_tool_calls( Message $msg ): bool {
        return ! empty( $msg->tool_calls );
    }

    /**
     * Parse FunctionCall objects from the provider response.
     *
     * @param  Message $msg
     * @return FunctionCall[]
     */
    private function parse_tool_calls( Message $msg ): array {
        if ( ! $msg->tool_calls ) {
            return [];
        }

        return array_map( function ( array $tc ) {
            return new FunctionCall(
                $tc['id'],
                $tc['function']['name'],
                $tc['function']['arguments']
            );
        }, $msg->tool_calls );
    }

    /**
     * Execute a single tool call and return the result.
     *
     * @param  FunctionCall $call
     * @param  array        $available_tools
     * @return FunctionResult
     */
    private function execute_tool( FunctionCall $call, array $available_tools ): FunctionResult {
        // Find matching tool
        $tool = $this->find_tool( $call->name, $available_tools );

        if ( ! $tool ) {
            return FunctionResult::failure(
                $call->id,
                sprintf( __( 'Tool "%s" not found.', 'sathi-agentic-ai' ), $call->name )
            );
        }

        try {
            $args = $call->args();

            // Validate arguments against JSON Schema if provided
            if ( isset( $tool['parameters']['properties'] ) ) {
                $args = $this->validate_args( $args, $tool['parameters'] );
            }

            $result = call_user_func( $tool['callback'], $args );

            return FunctionResult::success( $call->id, $result );
        } catch ( \Throwable $e ) {
            return FunctionResult::failure( $call->id, $e->getMessage() );
        }
    }

    /**
     * Find a tool definition by name.
     */
    private function find_tool( string $name, array $tools ): ?array {
        foreach ( $tools as $tool ) {
            if ( ( $tool['name'] ?? '' ) === $name ) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Validate arguments against a JSON Schema definition.
     *
     * @param  array $args
     * @param  array $schema
     * @return array
     */
    private function validate_args( array $args, array $schema ): array {
        $validated = [];
        $properties = $schema['properties'] ?? [];

        foreach ( $properties as $name => $prop ) {
            if ( isset( $args[ $name ] ) ) {
                $validated[ $name ] = $this->cast_value( $args[ $name ], $prop['type'] ?? 'string' );
            } elseif ( in_array( $name, $schema['required'] ?? [] ) ) {
                throw new \InvalidArgumentException(
                    sprintf( __( 'Missing required parameter: %s', 'sathi-agentic-ai' ), $name )
                );
            }
        }

        return $validated;
    }

    /**
     * Cast a value to the expected type.
     */
    private function cast_value( $value, string $type ) {
        return match ( $type ) {
            'integer', 'number' => is_numeric( $value ) ? ( $type === 'integer' ? (int) $value : (float) $value ) : $value,
            'boolean'           => filter_var( $value, FILTER_VALIDATE_BOOLEAN ),
            'array'             => is_array( $value ) ? $value : [ $value ],
            default             => (string) $value,
        };
    }

    /**
     * Prepare tools for provider consumption.
     *
     * @param  array $raw_tools
     * @return array
     */
    private function prepare_tools( array $raw_tools ): array {
        return array_map( function ( array $tool ) {
            return [
                'name'        => $tool['name'] ?? '',
                'description' => $tool['description'] ?? '',
                'parameters'  => $tool['parameters'] ?? [
                    'type'       => 'object',
                    'properties' => (object) [],
                ],
            ];
        }, $raw_tools );
    }

    /**
     * Build context for the prompt composer.
     */
    private function get_context( Conversation $conv ): array {
        return [
            'site_name'        => get_bloginfo( 'name' ),
            'site_description' => get_bloginfo( 'description' ),
            'site_url'         => home_url(),
            'conversation_id'  => $conv->uuid,
            'user_id'          => $conv->user_id,
        ];
    }
}
