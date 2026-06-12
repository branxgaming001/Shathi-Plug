<?php
/**
 * Local / Ollama-compatible provider adapter.
 *
 * Connects to self-hosted models via OpenAI-compatible API (llama.cpp server,
 * Ollama, vLLM, LocalAI, etc.).
 *
 * @package RaiLabs\Sathi\Providers
 */

namespace RaiLabs\Sathi\Providers;

use RaiLabs\Sathi\Core\Data\Message;
use RaiLabs\Sathi\Providers\Contracts\ProviderInterface;
use RaiLabs\Sathi\Support\Helpers;

class Local implements ProviderInterface {

    private string $base_url;
    private string $model;
    private int $timeout;
    private float $temperature;
    private int $max_tokens;
    private string $api_key; // Optional — most local servers don't require one

    public function __construct( array $config = [] ) {
        $this->base_url    = $config['base_url'] ?? 'http://localhost:11434/v1';
        $this->model       = $config['model'] ?? 'llama3.2';
        $this->timeout     = $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT;
        $this->temperature = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens  = (int) ( $config['max_tokens'] ?? 2048 );
        $this->api_key     = $config['api_key'] ?? '';
    }

    public function key(): string {
        return 'local';
    }

    public function label(): string {
        return __( 'Local / Ollama', 'sathi-agentic-ai' );
    }

    public function supports_streaming(): bool {
        return true;
    }

    public function supports_function_calling(): bool {
        // Many local models now support tool calling via OpenAI-compatible API
        return true;
    }

    public function supports_vision(): bool {
        // Depends on the model; assume yes for modern multi-modal local models
        return true;
    }

    public function chat( array $messages, array $options = [] ): Message {
        $model       = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $max_tokens  = $options['max_tokens'] ?? $this->max_tokens;
        $tools       = $options['tools'] ?? [];

        $body = [
            'model'       => $model,
            'messages'    => $this->format_messages( $messages ),
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
        ];

        if ( ! empty( $options['system_prompt'] ) ) {
            array_unshift( $body['messages'], [ 'role' => 'system', 'content' => $options['system_prompt'] ] );
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

        $body = [
            'model'       => $model,
            'messages'    => $this->format_messages( $messages ),
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
            'stream'      => true,
        ];

        if ( ! empty( $options['system_prompt'] ) ) {
            array_unshift( $body['messages'], [ 'role' => 'system', 'content' => $options['system_prompt'] ] );
        }

        $content = '';

        $this->request_stream( 'POST', '/chat/completions', $body, function ( array $chunk ) use ( &$content, $callback ) {
            $delta = $chunk['choices'][0]['delta'] ?? [];
            if ( isset( $delta['content'] ) && $delta['content'] ) {
                $content .= $delta['content'];
                $callback( $delta['content'] );
            }
        } );

        return new Message( 'assistant', $content, null, null, Helpers::estimate_tokens( $content ) );
    }

    public function embed( $input, array $options = [] ): array {
        $model     = $options['model'] ?? 'nomic-embed-text';
        $is_single = ! is_array( $input );
        $inputs    = $is_single ? [ $input ] : $input;

        $response = $this->request( 'POST', '/embeddings', [
            'model' => $model,
            'input' => $inputs,
        ] );

        $vectors = array_map( fn( $item ) => $item['embedding'] ?? [], $response['data'] ?? [] );
        return $is_single ? ( $vectors[0] ?? [] ) : $vectors;
    }

    public function is_configured(): bool {
        return ! empty( $this->base_url );
    }

    public function count_tokens( array $messages ): int {
        $total = 0;
        foreach ( $messages as $msg ) {
            $total += $msg->token_count ?? Helpers::estimate_tokens( $msg->content );
        }
        return $total;
    }

    public function available_models(): array {
        // Local models need discovery — return sensible defaults.
        return [
            'llama3.2'            => [ 'label' => 'Llama 3.2',          'context_window' => 131072, 'streaming' => true, 'vision' => false ],
            'llama3.2-vision'     => [ 'label' => 'Llama 3.2 Vision',  'context_window' => 131072, 'streaming' => true, 'vision' => true ],
            'mistral'             => [ 'label' => 'Mistral 7B',         'context_window' => 32768,  'streaming' => true, 'vision' => false ],
            'mixtral'             => [ 'label' => 'Mixtral 8x7B',      'context_window' => 32768,  'streaming' => true, 'vision' => false ],
            'gemma2'              => [ 'label' => 'Gemma 2',            'context_window' => 8192,   'streaming' => true, 'vision' => false ],
            'phi3'                => [ 'label' => 'Phi-3 Mini',        'context_window' => 4096,   'streaming' => true, 'vision' => false ],
            'nomic-embed-text'    => [ 'label' => 'Nomic Embed Text',  'context_window' => 8192,   'streaming' => false,'vision' => false ],
        ];
    }

    public function default_model(): string {
        return 'llama3.2';
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

    // ── Private ───────────────────────────────────────────────────

    private function format_messages( array $messages ): array {
        return array_map( fn( Message $m ) => $m->to_openai_format(), $messages );
    }

    private function get_headers(): array {
        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $this->api_key ) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }
        return $headers;
    }

    private function request( string $method, string $endpoint, array $body ): array {
        $url = rtrim( $this->base_url, '/' ) . $endpoint;

        $response = wp_remote_request( $url, [
            'method'  => $method,
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout' => $this->timeout,
            // Disable SSL verify for localhost
            'sslverify' => str_contains( $url, 'localhost' ) || str_contains( $url, '127.0.0.1' ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Local: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException( "Local: {$error_msg}", $status );
        }

        return $data;
    }

    private function request_stream( string $method, string $endpoint, array $body, callable $on_chunk ): void {
        $url  = rtrim( $this->base_url, '/' ) . $endpoint;
        $temp = get_temp_dir() . 'sathi_local_stream.tmp';

        $response = wp_remote_request( $url, [
            'method'    => $method,
            'headers'   => $this->get_headers(),
            'body'      => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout'   => $this->timeout + 30,
            'stream'    => true,
            'filename'  => $temp,
            'sslverify' => str_contains( $url, 'localhost' ) || str_contains( $url, '127.0.0.1' ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() );
        }

        $fp = @fopen( $temp, 'r' );
        if ( ! $fp ) {
            throw new \RuntimeException( __( 'Failed to read local stream.', 'sathi-agentic-ai' ) );
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
                    $this->process_sse( $buffer, $on_chunk );
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
        @unlink( $temp );
    }

    private function process_sse( string $data, callable $on_chunk ): void {
        $parsed = json_decode( $data, true );
        if ( $parsed && is_array( $parsed ) && isset( $parsed['choices'] ) ) {
            $on_chunk( $parsed );
        }
    }

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
