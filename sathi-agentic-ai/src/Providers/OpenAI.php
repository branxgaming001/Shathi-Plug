<?php
/**
 * OpenAI provider adapter.
 *
 * Supports: Chat Completions API, Responses API, streaming, function calling,
 * vision, embeddings (text-embedding-3-*).
 *
 * @package RaiLabs\Sathi\Providers
 */

namespace RaiLabs\Sathi\Providers;

use RaiLabs\Sathi\Core\Data\Message;
use RaiLabs\Sathi\Core\Data\FunctionResult;
use RaiLabs\Sathi\Providers\Contracts\ProviderInterface;
use RaiLabs\Sathi\Support\Helpers;

class OpenAI implements ProviderInterface {

    /** @var string Base URL for API */
    private string $base_url;

    /** @var string API key */
    private string $api_key;

    /** @var string Active model */
    private string $model;

    /** @var int Request timeout in seconds */
    private int $timeout;

    /** @var string|null Organisation ID */
    private ?string $org_id;

    /** @var float Default temperature */
    private float $temperature;

    /** @var int Default max tokens */
    private int $max_tokens;

    /** @var bool Use Responses API (newer) instead of Chat Completions */
    private bool $use_responses_api;

    /**
     * @param array $config Provider config from settings.
     */
    public function __construct( array $config = [] ) {
        $this->base_url   = $config['base_url'] ?? 'https://api.openai.com/v1';
        $this->api_key    = $config['api_key'] ?? '';
        $this->model      = $config['model'] ?? 'gpt-4o';
        $this->timeout    = $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT;
        $this->org_id     = $config['org_id'] ?? null;
        $this->temperature = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens  = (int) ( $config['max_tokens'] ?? 4096 );
        $this->use_responses_api = (bool) ( $config['use_responses_api'] ?? false );
    }

    public function key(): string {
        return 'openai';
    }

    public function label(): string {
        return __( 'OpenAI', 'sathi-agentic-ai' );
    }

    public function supports_streaming(): bool {
        return true;
    }

    public function supports_function_calling(): bool {
        return true;
    }

    public function supports_vision(): bool {
        return true;
    }

    public function chat( array $messages, array $options = [] ): Message {
        $model       = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $max_tokens  = $options['max_tokens'] ?? $this->max_tokens;
        $tools       = $options['tools'] ?? [];

        if ( $this->use_responses_api ) {
            return $this->chat_responses_api( $messages, $options );
        }

        $body = [
            'model'       => $model,
            'messages'    => $this->format_messages( $messages ),
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
        ];

        if ( ! empty( $options['system_prompt'] ) ) {
            // Prepend system message
            array_unshift( $body['messages'], [
                'role'    => 'system',
                'content' => $options['system_prompt'],
            ] );
        }

        if ( ! empty( $tools ) ) {
            $body['tools']       = $this->format_tools( $tools );
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $response = $this->request( 'POST', '/chat/completions', $body );

        return $this->parse_response( $response );
    }

    public function chat_stream( array $messages, callable $callback, array $options = [] ): Message {
        $model       = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $max_tokens  = $options['max_tokens'] ?? $this->max_tokens;
        $tools       = $options['tools'] ?? [];

        $body = [
            'model'       => $model,
            'messages'    => $this->format_messages( $messages ),
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
            'stream'      => true,
        ];

        if ( ! empty( $options['system_prompt'] ) ) {
            array_unshift( $body['messages'], [
                'role'    => 'system',
                'content' => $options['system_prompt'],
            ] );
        }

        if ( ! empty( $tools ) ) {
            $body['tools']       = $this->format_tools( $tools );
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $content    = '';
        $tool_calls = [];

        $this->request_stream( 'POST', '/chat/completions', $body, function ( array $chunk ) use ( &$content, &$tool_calls, $callback ) {
            $delta = $chunk['choices'][0]['delta'] ?? [];

            // Regular text delta
            if ( isset( $delta['content'] ) && $delta['content'] ) {
                $content .= $delta['content'];
                $callback( $delta['content'] );
            }

            // Tool call deltas
            if ( isset( $delta['tool_calls'] ) ) {
                foreach ( $delta['tool_calls'] as $tc ) {
                    $idx = $tc['index'] ?? 0;
                    if ( ! isset( $tool_calls[ $idx ] ) ) {
                        $tool_calls[ $idx ] = [
                            'id'       => $tc['id'] ?? '',
                            'type'     => 'function',
                            'function' => [ 'name' => '', 'arguments' => '' ],
                        ];
                    }
                    if ( isset( $tc['id'] ) ) {
                        $tool_calls[ $idx ]['id'] = $tc['id'];
                    }
                    if ( isset( $tc['function']['name'] ) ) {
                        $tool_calls[ $idx ]['function']['name'] .= $tc['function']['name'];
                    }
                    if ( isset( $tc['function']['arguments'] ) ) {
                        $tool_calls[ $idx ]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }
        } );

        $message = new Message(
            'assistant',
            $content,
            $tool_calls ? array_values( $tool_calls ) : null,
            null,
            Helpers::estimate_tokens( $content )
        );

        return $message;
    }

    public function embed( $input, array $options = [] ): array {
        $model  = $options['model'] ?? 'text-embedding-3-small';
        $inputs = is_array( $input ) ? $input : [ $input ];
        $is_single = ! is_array( $input );

        $response = $this->request( 'POST', '/embeddings', [
            'model' => $model,
            'input' => $inputs,
        ] );

        $vectors = array_map( function ( $item ) {
            return $item['embedding'];
        }, $response['data'] ?? [] );

        return $is_single ? ( $vectors[0] ?? [] ) : $vectors;
    }

    public function is_configured(): bool {
        return ! empty( $this->api_key );
    }

    public function count_tokens( array $messages ): int {
        $total = 0;
        foreach ( $messages as $message ) {
            $total += $message->token_count ?? Helpers::estimate_tokens( $message->content );
        }
        return $total;
    }

    public function available_models(): array {
        return [
            'gpt-4o'          => [ 'label' => 'GPT-4o',           'context_window' => 128000, 'streaming' => true, 'vision' => true ],
            'gpt-4o-mini'     => [ 'label' => 'GPT-4o Mini',      'context_window' => 128000, 'streaming' => true, 'vision' => true ],
            'gpt-4-turbo'     => [ 'label' => 'GPT-4 Turbo',      'context_window' => 128000, 'streaming' => true, 'vision' => true ],
            'o1'              => [ 'label' => 'o1',                'context_window' => 200000, 'streaming' => false,'vision' => true ],
            'o1-mini'         => [ 'label' => 'o1 Mini',           'context_window' => 200000, 'streaming' => false,'vision' => true ],
            'o3-mini'         => [ 'label' => 'o3 Mini',           'context_window' => 200000, 'streaming' => true, 'vision' => false ],
            'text-embedding-3-small' => [ 'label' => 'Embed 3 Small', 'context_window' => 8192, 'streaming' => false, 'vision' => false ],
            'text-embedding-3-large' => [ 'label' => 'Embed 3 Large', 'context_window' => 8192, 'streaming' => false, 'vision' => false ],
        ];
    }

    public function default_model(): string {
        return 'gpt-4o';
    }

    public function format_tools( array $tools ): array {
        return array_map( function ( array $tool ) {
            return [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters'  => $tool['parameters'] ?? [ 'type' => 'object', 'properties' => (object) [] ],
                ],
            ];
        }, $tools );
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * Format messages for Chat Completions API.
     *
     * @param  Message[] $messages
     * @return array
     */
    private function format_messages( array $messages ): array {
        return array_map( fn( Message $m ) => $m->to_openai_format(), $messages );
    }

    /**
     * Non-streaming HTTP request.
     *
     * @param  string $method
     * @param  string $endpoint
     * @param  array  $body
     * @return array  Decoded JSON response.
     */
    private function request( string $method, string $endpoint, array $body ): array {
        $url     = $this->base_url . $endpoint;
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
        ];

        if ( $this->org_id ) {
            $headers['OpenAI-Organization'] = $this->org_id;
        }

        $response = wp_remote_request( $url, [
            'method'  => $method,
            'headers' => $headers,
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout' => $this->timeout,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                sprintf( __( 'OpenAI request failed: %s', 'sathi-agentic-ai' ), $response->get_error_message() )
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException( "OpenAI: {$error_msg}", $status );
        }

        return $data;
    }

    /**
     * Streaming HTTP request using WP HTTP API + manual line parsing.
     *
     * @param string   $method
     * @param string   $endpoint
     * @param array    $body
     * @param callable $on_chunk function(array $parsed_chunk): void
     */
    private function request_stream( string $method, string $endpoint, array $body, callable $on_chunk ): void {
        $url     = $this->base_url . $endpoint;
        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'text/event-stream',
        ];

        if ( $this->org_id ) {
            $headers['OpenAI-Organization'] = $this->org_id;
        }

        // Use wp_safe_remote_post for streaming via cURL inside WP
        $response = wp_remote_request( $url, [
            'method'  => $method,
            'headers' => $headers,
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout' => $this->timeout + 30,
            'stream'  => true,
            'filename'=> $this->get_stream_temp_file(),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                sprintf( __( 'OpenAI stream failed: %s', 'sathi-agentic-ai' ), $response->get_error_message() )
            );
        }

        // Read and parse SSE stream from temp file
        $fp = @fopen( $this->get_stream_temp_file(), 'r' );
        if ( ! $fp ) {
            throw new \RuntimeException( __( 'Failed to read stream response.', 'sathi-agentic-ai' ) );
        }

        $buffer = '';
        while ( ! feof( $fp ) ) {
            $line = fgets( $fp );
            if ( $line === false ) {
                break;
            }
            $line = trim( $line );

            if ( $line === '' ) {
                // Empty line = end of event; process buffer
                if ( $buffer ) {
                    $this->process_sse_data( $buffer, $on_chunk );
                    $buffer = '';
                }
                continue;
            }

            if ( str_starts_with( $line, 'data: ' ) ) {
                $data = substr( $line, 6 );
                if ( $data === '[DONE]' ) {
                    break;
                }
                $buffer .= $data;
            }
        }

        fclose( $fp );
        @unlink( $this->get_stream_temp_file() );
    }

    /**
     * Process accumulated SSE data lines.
     */
    private function process_sse_data( string $data, callable $on_chunk ): void {
        $parsed = json_decode( $data, true );
        if ( $parsed && is_array( $parsed ) ) {
            $on_chunk( $parsed );
        }
    }

    /**
     * Temporary file path for streaming.
     */
    private function get_stream_temp_file(): string {
        return get_temp_dir() . 'sathi_stream_' . md5( $this->api_key ) . '.tmp';
    }

    // ── Responses API (newer) ─────────────────────────────────────

    /**
     * Chat using the newer Responses API.
     *
     * @param  Message[] $messages
     * @param  array     $options
     * @return Message
     */
    private function chat_responses_api( array $messages, array $options = [] ): Message {
        $model       = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $max_tokens  = $options['max_tokens'] ?? $this->max_tokens;
        $tools       = $options['tools'] ?? [];

        $body = [
            'model'        => $model,
            'input'        => $this->format_messages_for_responses( $messages ),
            'temperature'  => $temperature,
            'max_output_tokens' => $max_tokens,
        ];

        if ( ! empty( $options['system_prompt'] ) ) {
            $body['instructions'] = $options['system_prompt'];
        }

        if ( ! empty( $tools ) ) {
            $body['tools'] = $this->format_tools_for_responses( $tools );
        }

        $response = $this->request( 'POST', '/responses', $body );

        $output = $response['output'] ?? [];
        $text   = '';
        $calls  = [];

        foreach ( $output as $item ) {
            if ( ( $item['type'] ?? '' ) === 'message' ) {
                foreach ( $item['content'] ?? [] as $content ) {
                    if ( ( $content['type'] ?? '' ) === 'output_text' ) {
                        $text .= $content['text'] ?? '';
                    }
                }
            } elseif ( ( $item['type'] ?? '' ) === 'function_call' ) {
                $calls[] = [
                    'id'       => $item['call_id'],
                    'type'     => 'function',
                    'function' => [
                        'name'      => $item['name'] ?? '',
                        'arguments' => $item['arguments'] ?? '',
                    ],
                ];
            }
        }

        return new Message(
            'assistant',
            $text,
            $calls ?: null,
            null,
            Helpers::estimate_tokens( $text )
        );
    }

    /**
     * Format messages for Responses API.
     */
    private function format_messages_for_responses( array $messages ): array {
        $items = [];
        foreach ( $messages as $msg ) {
            $items[] = [
                'role'    => $msg->role,
                'content' => $msg->content,
            ];
        }
        return $items;
    }

    /**
     * Format tools for Responses API.
     */
    private function format_tools_for_responses( array $tools ): array {
        return array_map( fn( $t ) => [
            'type'        => 'function',
            'name'        => $t['name'],
            'description' => $t['description'] ?? '',
            'parameters'  => $t['parameters'] ?? [ 'type' => 'object', 'properties' => (object) [] ],
        ], $tools );
    }

    /**
     * Parse Chat Completions response into a Message.
     */
    private function parse_response( array $response ): Message {
        $choice = $response['choices'][0] ?? [];
        $msg    = $choice['message'] ?? [];

        return new Message(
            $msg['role'] ?? 'assistant',
            $msg['content'] ?? '',
            $msg['tool_calls'] ?? null,
            null,
            Helpers::estimate_tokens( $msg['content'] ?? '' )
        );
    }
}
