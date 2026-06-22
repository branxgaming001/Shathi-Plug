<?php
/**
 * OpenRouter provider adapter — unified gateway to 200+ models.
 *
 * Uses OpenAI-compatible Chat Completions API.
 *
 * @package NeerMedia\Sathi\Providers
 */

namespace NeerMedia\Sathi\Providers;

use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Providers\Contracts\ProviderInterface;
use NeerMedia\Sathi\Support\Helpers;

class OpenRouter implements ProviderInterface {

    private string $base_url;
    private string $api_key;
    private string $model;
    private int $timeout;
    private float $temperature;
    private int $max_tokens;
    private ?string $app_name;
    private ?string $site_url;

    public function __construct( array $config = [] ) {
        $this->base_url    = 'https://openrouter.ai/api/v1';
        $this->api_key     = $config['api_key'] ?? '';
        $this->model       = $config['model'] ?? 'openai/gpt-4o';
        $this->timeout     = $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT;
        $this->temperature = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens  = (int) ( $config['max_tokens'] ?? 4096 );
        $this->app_name    = $config['app_name'] ?? 'Saathi Agentic AI';
        $this->site_url    = $config['site_url'] ?? home_url();
    }

    public function key(): string {
        return 'openrouter';
    }

    public function label(): string {
        return __( 'OpenRouter', 'sathi-agentic-ai' );
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

        $body = [
            'model'       => $model,
            'messages'    => $this->format_messages( $messages ),
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
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
            array_unshift( $body['messages'], [ 'role' => 'system', 'content' => $options['system_prompt'] ] );
        }

        if ( ! empty( $tools ) ) {
            $body['tools']       = $this->format_tools( $tools );
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $content    = '';
        $tool_calls = [];

        $this->request_stream( 'POST', '/chat/completions', $body, function ( array $chunk ) use ( &$content, &$tool_calls, $callback ) {
            $delta = $chunk['choices'][0]['delta'] ?? [];

            if ( isset( $delta['content'] ) && $delta['content'] ) {
                $content .= $delta['content'];
                $callback( $delta['content'] );
            }

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

        return new Message( 'assistant', $content, $tool_calls ? array_values( $tool_calls ) : null );
    }

    public function embed( $input, array $options = [] ): array {
        // OpenRouter aggregates many providers; embeddings availability varies.
        // Fall back to OpenAI embeddings via OpenRouter.
        $model = $options['model'] ?? 'openai/text-embedding-3-small';
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
            'openai/gpt-4o'                 => [ 'label' => 'GPT-4o',           'context_window' => 128000, 'streaming' => true, 'vision' => true ],
            'openai/gpt-4o-mini'           => [ 'label' => 'GPT-4o Mini',      'context_window' => 128000, 'streaming' => true, 'vision' => true ],
            'anthropic/claude-sonnet-4-6'  => [ 'label' => 'Claude Sonnet 4.6', 'context_window' => 200000, 'streaming' => true, 'vision' => true ],
            'anthropic/claude-haiku-4-5'   => [ 'label' => 'Claude Haiku 4.5',  'context_window' => 200000, 'streaming' => true, 'vision' => true ],
            'google/gemini-2.5-pro'        => [ 'label' => 'Gemini 2.5 Pro',    'context_window' => 1048576,'streaming' => true, 'vision' => true ],
            'google/gemini-2.5-flash'      => [ 'label' => 'Gemini 2.5 Flash',  'context_window' => 1048576,'streaming' => true, 'vision' => true ],
            'meta-llama/llama-4-maverick'  => [ 'label' => 'Llama 4 Maverick',  'context_window' => 131072, 'streaming' => true, 'vision' => true ],
            'meta-llama/llama-4-scout'     => [ 'label' => 'Llama 4 Scout',     'context_window' => 131072, 'streaming' => true, 'vision' => false ],
        ];
    }

    public function default_model(): string {
        return 'openai/gpt-4o';
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
        return [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => $this->site_url,
            'X-Title'       => $this->app_name,
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
            throw new \RuntimeException( 'OpenRouter: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $error_msg = $data['error']['message'] ?? "HTTP {$status}";
            throw new \RuntimeException( "OpenRouter: {$error_msg}", $status );
        }

        return $data;
    }

    private function request_stream( string $method, string $endpoint, array $body, callable $on_chunk ): void {
        $temp = get_temp_dir() . 'sathi_openrouter_stream.tmp';

        $response = wp_remote_request( $this->base_url . $endpoint, [
            'method'   => $method,
            'headers'  => $this->get_headers(),
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
            throw new \RuntimeException( __( 'Failed to read OpenRouter stream.', 'sathi-agentic-ai' ) );
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
