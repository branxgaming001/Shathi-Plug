<?php
/**
 * Google Gemini provider adapter.
 *
 * Supports: Gemini API, streaming, function calling, vision, embeddings.
 *
 * @package RaiLabs\Sathi\Providers
 */

namespace RaiLabs\Sathi\Providers;

use RaiLabs\Sathi\Core\Data\Message;
use RaiLabs\Sathi\Providers\Contracts\ProviderInterface;
use RaiLabs\Sathi\Support\Helpers;

class Gemini implements ProviderInterface {

    private string $base_url;
    private string $api_key;
    private string $model;
    private int $timeout;
    private float $temperature;
    private int $max_tokens;

    public function __construct( array $config = [] ) {
        $this->base_url    = $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';
        $this->api_key     = $config['api_key'] ?? '';
        $this->model       = $config['model'] ?? 'gemini-2.5-pro';
        $this->timeout     = $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT;
        $this->temperature = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens  = (int) ( $config['max_tokens'] ?? 4096 );
    }

    public function key(): string {
        return 'google';
    }

    public function label(): string {
        return __( 'Google Gemini', 'sathi-agentic-ai' );
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

        $contents   = $this->format_messages( $messages );
        $system_instruction = null;

        // Extract system message for Gemini's separate parameter
        if ( ! empty( $options['system_prompt'] ) ) {
            $system_instruction = [ 'parts' => [ [ 'text' => $options['system_prompt'] ] ] ];
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'    => $temperature,
                'maxOutputTokens'=> $max_tokens,
            ],
        ];

        if ( $system_instruction ) {
            $body['systemInstruction'] = $system_instruction;
        }

        if ( ! empty( $tools ) ) {
            $body['tools'] = [ [ 'functionDeclarations' => $this->format_tools( $tools ) ] ];
        }

        $endpoint = "/models/{$model}:generateContent";
        $response = $this->request( 'POST', $endpoint, $body );

        return $this->parse_response( $response );
    }

    public function chat_stream( array $messages, callable $callback, array $options = [] ): Message {
        $model       = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $max_tokens  = $options['max_tokens'] ?? $this->max_tokens;
        $tools       = $options['tools'] ?? [];
        $system      = $options['system_prompt'] ?? null;

        $contents = $this->format_messages( $messages );

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'    => $temperature,
                'maxOutputTokens'=> $max_tokens,
            ],
        ];

        if ( $system ) {
            $body['systemInstruction'] = [ 'parts' => [ [ 'text' => $system ] ] ];
        }

        if ( ! empty( $tools ) ) {
            $body['tools'] = [ [ 'functionDeclarations' => $this->format_tools( $tools ) ] ];
        }

        $endpoint = "/models/{$model}:streamGenerateContent?alt=sse";
        $content  = '';
        $calls    = [];

        $this->request_stream( 'POST', $endpoint, $body, function ( array $chunk ) use ( &$content, &$calls, $callback ) {
            $candidates = $chunk['candidates'] ?? [];
            foreach ( $candidates as $candidate ) {
                $parts = $candidate['content']['parts'] ?? [];
                foreach ( $parts as $part ) {
                    if ( isset( $part['text'] ) ) {
                        $content .= $part['text'];
                        $callback( $part['text'] );
                    }
                    if ( isset( $part['functionCall'] ) ) {
                        $calls[] = [
                            'id'       => uniqid( 'gemini-fc-' ),
                            'type'     => 'function',
                            'function' => [
                                'name'      => $part['functionCall']['name'],
                                'arguments' => wp_json_encode( $part['functionCall']['args'] ?? [] ),
                            ],
                        ];
                    }
                }
            }
        } );

        return new Message( 'assistant', $content, $calls ?: null, null, Helpers::estimate_tokens( $content ) );
    }

    public function embed( $input, array $options = [] ): array {
        $model  = $options['model'] ?? 'text-embedding-004';
        $is_single = ! is_array( $input );

        $requests = array_map( fn( $text ) => [
            'model' => "models/{$model}",
            'content' => [ 'parts' => [ [ 'text' => is_array( $text ) ? wp_json_encode( $text ) : $text ] ] ],
        ], $is_single ? [ $input ] : $input );

        $response = $this->request( 'POST', "/models/{$model}:batchEmbedContents", [
            'requests' => $requests,
        ] );

        $vectors = array_map( fn( $embedding ) => $embedding['values'] ?? [], $response['embeddings'] ?? [] );

        return $is_single ? ( $vectors[0] ?? [] ) : $vectors;
    }

    public function is_configured(): bool {
        return ! empty( $this->api_key );
    }

    public function count_tokens( array $messages ): int {
        $total = 0;
        foreach ( $messages as $msg ) {
            $total += $msg->token_count ?? Helpers::estimate_tokens( $msg->content );
        }
        return $total;
    }

    public function available_models(): array {
        return [
            'gemini-2.5-pro'     => [ 'label' => 'Gemini 2.5 Pro',     'context_window' => 1048576, 'streaming' => true, 'vision' => true ],
            'gemini-2.5-flash'   => [ 'label' => 'Gemini 2.5 Flash',   'context_window' => 1048576, 'streaming' => true, 'vision' => true ],
            'gemini-2.0-flash'   => [ 'label' => 'Gemini 2.0 Flash',   'context_window' => 1048576, 'streaming' => true, 'vision' => true ],
            'text-embedding-004' => [ 'label' => 'Embed 004',          'context_window' => 2048,    'streaming' => false,'vision' => false ],
        ];
    }

    public function default_model(): string {
        return 'gemini-2.5-pro';
    }

    public function format_tools( array $tools ): array {
        return array_map( function ( array $tool ) {
            return [
                'name'        => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters'  => $tool['parameters'] ?? [ 'type' => 'object', 'properties' => (object) [] ],
            ];
        }, $tools );
    }

    // ── Private ───────────────────────────────────────────────────

    private function format_messages( array $messages ): array {
        $contents = [];
        foreach ( $messages as $msg ) {
            $role = match ( $msg->role ) {
                'assistant' => 'model',
                'system'    => 'model', // Gemini uses systemInstruction separately
                default     => 'user',
            };

            // Merge consecutive same-role messages
            $last = end( $contents );
            if ( $last && ( $last['role'] ?? '' ) === $role ) {
                // Append part
                $contents[ key( $contents ) ]['parts'][] = [ 'text' => $msg->content ];
            } else {
                $contents[] = [
                    'role'  => $role,
                    'parts' => [ [ 'text' => $msg->content ] ],
                ];
            }
        }

        return array_values( $contents );
    }

    private function request( string $method, string $endpoint, array $body ): array {
        $url = $this->base_url . $endpoint . '?key=' . urlencode( $this->api_key );

        $response = wp_remote_request( $url, [
            'method'  => $method,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout' => $this->timeout,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Gemini: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException( "Gemini: {$error_msg}", $status );
        }

        return $data;
    }

    private function request_stream( string $method, string $endpoint, array $body, callable $on_chunk ): void {
        $url  = $this->base_url . $endpoint . '&key=' . urlencode( $this->api_key );
        $temp = get_temp_dir() . 'sathi_gemini_stream.tmp';

        $response = wp_remote_request( $url, [
            'method'   => $method,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout'  => $this->timeout + 30,
            'stream'   => true,
            'filename' => $temp,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() );
        }

        $fp = @fopen( $temp, 'r' );
        if ( ! $fp ) {
            throw new \RuntimeException( __( 'Failed to read Gemini stream.', 'sathi-agentic-ai' ) );
        }

        $text = stream_get_contents( $fp );
        fclose( $fp );
        @unlink( $temp );

        // Gemini SSE may wrap multiple JSON objects; parse line by line
        foreach ( explode( "\n", $text ) as $line ) {
            $line = trim( $line );
            if ( ! $line ) {
                continue;
            }
            // Strip Google's array wrapper "[...]"
            $line = trim( $line, ',[]' );
            $parsed = json_decode( $line, true );
            if ( $parsed ) {
                $on_chunk( $parsed );
            }
        }
    }

    private function parse_response( array $response ): Message {
        $candidate = $response['candidates'][0] ?? [];
        $parts     = $candidate['content']['parts'] ?? [];

        $text  = '';
        $calls = [];

        foreach ( $parts as $part ) {
            if ( isset( $part['text'] ) ) {
                $text .= $part['text'];
            }
            if ( isset( $part['functionCall'] ) ) {
                $calls[] = [
                    'id'       => uniqid( 'gemini-fc-' ),
                    'type'     => 'function',
                    'function' => [
                        'name'      => $part['functionCall']['name'],
                        'arguments' => wp_json_encode( $part['functionCall']['args'] ?? [] ),
                    ],
                ];
            }
        }

        return new Message( 'assistant', $text, $calls ?: null, null, Helpers::estimate_tokens( $text ) );
    }
}
