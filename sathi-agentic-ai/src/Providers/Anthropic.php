<?php
/**
 * Anthropic (Claude) provider adapter.
 *
 * Supports: Messages API, streaming, tool use, vision.
 *
 * @package NeerMedia\Sathi\Providers
 */

namespace NeerMedia\Sathi\Providers;

use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Providers\Contracts\ProviderInterface;
use NeerMedia\Sathi\Support\Helpers;

class Anthropic implements ProviderInterface {

    private string $base_url;
    private string $api_key;
    private string $model;
    private int $timeout;
    private float $temperature;
    private int $max_tokens;
    private string $api_version;

    public function __construct( array $config = [] ) {
        $this->base_url    = $config['base_url'] ?? 'https://api.anthropic.com/v1';
        $this->api_key     = $config['api_key'] ?? '';
        $this->model       = $config['model'] ?? 'claude-sonnet-4-6';
        $this->timeout     = $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT;
        $this->temperature = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens  = (int) ( $config['max_tokens'] ?? 4096 );
        $this->api_version = $config['api_version'] ?? '2023-06-01';
    }

    public function key(): string {
        return 'anthropic';
    }

    public function label(): string {
        return __( 'Anthropic (Claude)', 'sathi-agentic-ai' );
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
        $system      = $options['system_prompt'] ?? null;
        $tools       = $options['tools'] ?? [];

        // Anthropic separates system prompt from messages
        $body = [
            'model'       => $model,
            'max_tokens'  => $max_tokens,
            'messages'    => $this->format_messages( $messages ),
        ];

        if ( $system ) {
            $body['system'] = $system;
        }

        if ( $temperature > 0 ) {
            $body['temperature'] = $temperature;
        }

        if ( ! empty( $tools ) ) {
            $body['tools'] = $this->format_tools( $tools );
        }

        $response = $this->request( 'POST', '/messages', $body );

        return $this->parse_response( $response );
    }

    public function chat_stream( array $messages, callable $callback, array $options = [] ): Message {
        $model       = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $max_tokens  = $options['max_tokens'] ?? $this->max_tokens;
        $system      = $options['system_prompt'] ?? null;
        $tools       = $options['tools'] ?? [];

        $body = [
            'model'       => $model,
            'max_tokens'  => $max_tokens,
            'messages'    => $this->format_messages( $messages ),
            'stream'      => true,
        ];

        if ( $system ) {
            $body['system'] = $system;
        }

        if ( $temperature > 0 ) {
            $body['temperature'] = $temperature;
        }

        if ( ! empty( $tools ) ) {
            $body['tools'] = $this->format_tools( $tools );
        }

        $content    = '';
        $tool_uses  = [];

        $this->request_stream( 'POST', '/messages', $body, function ( array $event ) use ( &$content, &$tool_uses, $callback ) {
            $type = $event['type'] ?? '';

            if ( $type === 'content_block_delta' ) {
                $delta = $event['delta'] ?? [];

                if ( ( $delta['type'] ?? '' ) === 'text_delta' ) {
                    $text = $delta['text'] ?? '';
                    $content .= $text;
                    $callback( $text );
                }

                if ( ( $delta['type'] ?? '' ) === 'input_json_delta' ) {
                    // Accumulating tool input
                    $idx = $event['index'] ?? 0;
                    if ( ! isset( $tool_uses[ $idx ] ) ) {
                        $tool_uses[ $idx ] = [ 'id' => '', 'name' => '', 'input' => '' ];
                    }
                    $tool_uses[ $idx ]['input'] .= $delta['partial_json'] ?? '';
                }
            }

            if ( $type === 'content_block_start' ) {
                $block = $event['content_block'] ?? [];
                if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
                    $idx = $event['index'] ?? 0;
                    $tool_uses[ $idx ] = [
                        'id'    => $block['id'] ?? '',
                        'name'  => $block['name'] ?? '',
                        'input' => '',
                    ];
                }
            }
        } );

        $calls = array_map( function ( $tu ) {
            return [
                'id'       => $tu['id'],
                'type'     => 'function',
                'function' => [
                    'name'      => $tu['name'],
                    'arguments' => $tu['input'],
                ],
            ];
        }, $tool_uses );

        return new Message(
            'assistant',
            $content,
            $calls ? array_values( $calls ) : null,
            null,
            Helpers::estimate_tokens( $content )
        );
    }

    public function embed( $input, array $options = [] ): array {
        // Anthropic does not offer a public embeddings API.
        throw new \RuntimeException(
            __( 'Anthropic does not provide embeddings. Use OpenAI or Gemini for embeddings.', 'sathi-agentic-ai' )
        );
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
            'claude-opus-4-8'     => [ 'label' => 'Claude Opus 4.8',     'context_window' => 200000, 'streaming' => true, 'vision' => true ],
            'claude-sonnet-4-6'   => [ 'label' => 'Claude Sonnet 4.6',    'context_window' => 200000, 'streaming' => true, 'vision' => true ],
            'claude-haiku-4-5'    => [ 'label' => 'Claude Haiku 4.5',     'context_window' => 200000, 'streaming' => true, 'vision' => true ],
            'claude-3.5-sonnet'   => [ 'label' => 'Claude 3.5 Sonnet',    'context_window' => 200000, 'streaming' => true, 'vision' => true ],
            'claude-3.5-haiku'    => [ 'label' => 'Claude 3.5 Haiku',     'context_window' => 200000, 'streaming' => true, 'vision' => false ],
        ];
    }

    public function default_model(): string {
        return 'claude-sonnet-4-6';
    }

    public function format_tools( array $tools ): array {
        return array_map( function ( array $tool ) {
            return [
                'name'         => $tool['name'],
                'description'  => $tool['description'] ?? '',
                'input_schema' => $tool['parameters'] ?? [ 'type' => 'object', 'properties' => (object) [] ],
            ];
        }, $tools );
    }

    // ── Private ───────────────────────────────────────────────────

    private function format_messages( array $messages ): array {
        return array_map( fn( Message $m ) => $m->to_anthropic_format(), $messages );
    }

    private function get_headers(): array {
        return [
            'x-api-key'         => $this->api_key,
            'anthropic-version' => $this->api_version,
            'Content-Type'      => 'application/json',
        ];
    }

    private function request( string $method, string $endpoint, array $body ): array {
        $response = wp_remote_request( $this->base_url . $endpoint, [
            'method'  => $method,
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout' => $this->timeout,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                sprintf( __( 'Anthropic request failed: %s', 'sathi-agentic-ai' ), $response->get_error_message() )
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException( "Anthropic: {$error_msg}", $status );
        }

        return $data;
    }

    private function request_stream( string $method, string $endpoint, array $body, callable $on_event ): void {
        $temp_file = get_temp_dir() . 'sathi_anthropic_stream.tmp';

        $response = wp_remote_request( $this->base_url . $endpoint, [
            'method'   => $method,
            'headers'  => $this->get_headers(),
            'body'     => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout'  => $this->timeout + 30,
            'stream'   => true,
            'filename' => $temp_file,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() );
        }

        $fp = @fopen( $temp_file, 'r' );
        if ( ! $fp ) {
            throw new \RuntimeException( __( 'Failed to read Anthropic stream.', 'sathi-agentic-ai' ) );
        }

        $buffer = '';
        while ( ! feof( $fp ) ) {
            $line = fgets( $fp );
            if ( $line === false ) {
                break;
            }
            $line = trim( $line );

            if ( $line === '' ) {
                if ( $buffer ) {
                    $this->process_sse( $buffer, $on_event );
                    $buffer = '';
                }
                continue;
            }

            if ( str_starts_with( $line, 'data: ' ) ) {
                $data = substr( $line, 6 );
                $buffer .= $data;
            }
        }

        fclose( $fp );
        @unlink( $temp_file );
    }

    private function process_sse( string $data, callable $on_event ): void {
        $parsed = json_decode( $data, true );
        if ( $parsed && is_array( $parsed ) ) {
            $on_event( $parsed );
        }
    }

    private function parse_response( array $response ): Message {
        $content = '';
        $tool_uses = [];

        foreach ( $response['content'] ?? [] as $block ) {
            if ( $block['type'] === 'text' ) {
                $content .= $block['text'];
            } elseif ( $block['type'] === 'tool_use' ) {
                $tool_uses[] = [
                    'id'       => $block['id'],
                    'type'     => 'function',
                    'function' => [
                        'name'      => $block['name'],
                        'arguments' => is_string( $block['input'] )
                            ? $block['input']
                            : wp_json_encode( $block['input'] ),
                    ],
                ];
            }
        }

        return new Message(
            'assistant',
            $content,
            $tool_uses ?: null,
            null,
            Helpers::estimate_tokens( $content )
        );
    }
}
