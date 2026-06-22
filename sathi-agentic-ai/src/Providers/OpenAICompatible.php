<?php
/**
 * Universal OpenAI-compatible provider adapter.
 *
 * Drives any provider that speaks the OpenAI Chat Completions dialect — OpenAI,
 * OpenRouter, Groq, Together, Fireworks, DeepSeek, Mistral, Perplexity, xAI Grok,
 * Ollama, LM Studio, and any custom OpenAI-compatible endpoint. Per-provider
 * differences (base URL, default model, extra headers) come from config.
 *
 * @package NeerMedia\Sathi\Providers
 */

namespace NeerMedia\Sathi\Providers;

use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Providers\Contracts\ProviderInterface;
use NeerMedia\Sathi\Support\Helpers;

class OpenAICompatible implements ProviderInterface {

    protected string $provider_key;
    protected string $provider_label;
    protected string $base_url;
    protected string $api_key;
    protected string $model;
    protected string $default_model;
    protected int $timeout;
    protected float $temperature;
    protected int $max_tokens;
    protected array $extra_headers;
    protected bool $needs_key;

    public function __construct( array $config = [] ) {
        $this->provider_key   = $config['key'] ?? 'openai_compatible';
        $this->provider_label = $config['label'] ?? 'OpenAI-compatible';
        $this->base_url       = rtrim( (string) ( $config['base_url'] ?? 'https://api.openai.com/v1' ), '/' );
        $this->api_key        = (string) ( $config['api_key'] ?? '' );
        $this->default_model  = (string) ( $config['default_model'] ?? 'gpt-4o-mini' );
        $this->model          = (string) ( ( $config['model'] ?? '' ) ?: $this->default_model );
        $this->timeout        = (int) ( $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT );
        $this->temperature    = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens     = (int) ( $config['max_tokens'] ?? 4096 );
        $this->extra_headers  = (array) ( $config['extra_headers'] ?? [] );
        $this->needs_key      = (bool) ( $config['needs_key'] ?? true );
    }

    public function key(): string { return $this->provider_key; }
    public function label(): string { return $this->provider_label; }
    public function supports_streaming(): bool { return true; }
    public function supports_function_calling(): bool { return true; }
    public function supports_vision(): bool { return true; }
    public function default_model(): string { return $this->default_model; }

    public function chat( array $messages, array $options = [] ): Message {
        $body = $this->build_body( $messages, $options, false );
        $response = $this->request( 'POST', '/chat/completions', $body );
        return $this->parse_response( $response );
    }

    public function chat_stream( array $messages, callable $callback, array $options = [] ): Message {
        $body = $this->build_body( $messages, $options, true );
        $content = '';
        $tool_calls = [];

        try {
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
                            $tool_calls[ $idx ] = [ 'id' => $tc['id'] ?? '', 'type' => 'function', 'function' => [ 'name' => '', 'arguments' => '' ] ];
                        }
                        if ( isset( $tc['id'] ) ) { $tool_calls[ $idx ]['id'] = $tc['id']; }
                        if ( isset( $tc['function']['name'] ) ) { $tool_calls[ $idx ]['function']['name'] .= $tc['function']['name']; }
                        if ( isset( $tc['function']['arguments'] ) ) { $tool_calls[ $idx ]['function']['arguments'] .= $tc['function']['arguments']; }
                    }
                }
            } );
        } catch ( \Throwable $e ) {
            // Streaming failed entirely — handled by the fallback below.
            $content = '';
        }

        // RESILIENCE: many hosts (LiteSpeed, restrictive proxies, no temp-file
        // write) silently break the streaming transport even though the normal
        // completion endpoint works. If streaming produced nothing, fall back to
        // a non-streaming completion so the visitor always gets a reply.
        if ( $content === '' && empty( $tool_calls ) ) {
            $message = $this->chat( $messages, $options );
            if ( $message->content !== '' ) {
                $callback( $message->content );
            }
            return $message;
        }

        $content = Helpers::strip_reasoning( $content );
        return new Message( 'assistant', $content, $tool_calls ? array_values( $tool_calls ) : null, null, Helpers::estimate_tokens( $content ) );
    }

    public function embed( $input, array $options = [] ): array {
        $model     = $options['model'] ?? ( $this->provider_key === 'openai' ? 'text-embedding-3-small' : $this->model );
        $is_single = ! is_array( $input );
        $inputs    = $is_single ? [ $input ] : $input;
        $response  = $this->request( 'POST', '/embeddings', [ 'model' => $model, 'input' => $inputs ] );
        $vectors   = array_map( fn( $item ) => $item['embedding'] ?? [], $response['data'] ?? [] );
        return $is_single ? ( $vectors[0] ?? [] ) : $vectors;
    }

    public function is_configured(): bool {
        return $this->needs_key ? ! empty( $this->api_key ) : ! empty( $this->base_url );
    }

    public function count_tokens( array $messages ): int {
        $total = 0;
        foreach ( $messages as $m ) { $total += $m->token_count ?? Helpers::estimate_tokens( $m->content ); }
        return $total;
    }

    public function available_models(): array {
        $models = [];
        $catalog = ProviderCatalog::get( $this->provider_key );
        foreach ( (array) ( $catalog['models'] ?? [] ) as $m ) {
            $models[ $m ] = [ 'label' => $m, 'context_window' => 0, 'streaming' => true, 'vision' => false ];
        }
        return $models;
    }

    /**
     * Fetch the live model list from the provider (GET /models). Best-effort.
     *
     * @return string[]
     */
    public function fetch_models(): array {
        try {
            $response = $this->request( 'GET', '/models', [] );
            $data = $response['data'] ?? $response['models'] ?? [];
            $ids = [];
            foreach ( $data as $m ) {
                $id = $m['id'] ?? $m['name'] ?? null;
                if ( $id ) { $ids[] = $id; }
            }
            sort( $ids );
            return $ids;
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    public function format_tools( array $tools ): array {
        return array_map( fn( array $t ) => [
            'type' => 'function',
            'function' => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'parameters' => $t['parameters'] ?? [ 'type' => 'object', 'properties' => (object) [] ],
            ],
        ], $tools );
    }

    // ── Internals ───────────────────────────────────────────────────

    protected function build_body( array $messages, array $options, bool $stream ): array {
        $body = [
            'model'       => $options['model'] ?? $this->model,
            'messages'    => array_map( fn( Message $m ) => $m->to_openai_format(), $messages ),
            'temperature' => $options['temperature'] ?? $this->temperature,
            'max_tokens'  => $options['max_tokens'] ?? $this->max_tokens,
        ];
        if ( $stream ) { $body['stream'] = true; }
        if ( ! empty( $options['system_prompt'] ) ) {
            array_unshift( $body['messages'], [ 'role' => 'system', 'content' => $options['system_prompt'] ] );
        }
        if ( ! empty( $options['tools'] ) ) {
            $body['tools'] = $this->format_tools( $options['tools'] );
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }
        return $body;
    }

    protected function get_headers(): array {
        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $this->api_key ) {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }
        return array_merge( $headers, $this->extra_headers );
    }

    protected function is_local(): bool {
        return str_contains( $this->base_url, 'localhost' ) || str_contains( $this->base_url, '127.0.0.1' );
    }

    protected function request( string $method, string $endpoint, array $body ): array {
        $args = [
            'method'    => $method,
            'headers'   => $this->get_headers(),
            'timeout'   => $this->timeout,
            'sslverify' => ! $this->is_local(),
        ];
        if ( $method !== 'GET' ) {
            $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
        }
        $response = wp_remote_request( $this->base_url . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $this->provider_label . ': ' . $response->get_error_message() );
        }
        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status >= 400 ) {
            $msg = $data['error']['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : "HTTP {$status}" );
            throw new \RuntimeException( $this->provider_label . ": {$msg}", (int) $status );
        }
        return is_array( $data ) ? $data : [];
    }

    protected function request_stream( string $method, string $endpoint, array $body, callable $on_chunk ): void {
        $temp = get_temp_dir() . 'sathi_stream_' . md5( $this->provider_key . $this->base_url ) . '.tmp';
        $response = wp_remote_request( $this->base_url . $endpoint, [
            'method'    => $method,
            'headers'   => $this->get_headers(),
            'body'      => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout'   => $this->timeout + 30,
            'stream'    => true,
            'filename'  => $temp,
            'sslverify' => ! $this->is_local(),
        ] );
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $this->provider_label . ': ' . $response->get_error_message() );
        }
        $fp = @fopen( $temp, 'r' );
        if ( ! $fp ) {
            throw new \RuntimeException( __( 'Failed to read stream response.', 'sathi-agentic-ai' ) );
        }
        $buffer = '';
        while ( ! feof( $fp ) ) {
            $line = fgets( $fp );
            if ( $line === false ) { break; }
            $line = trim( $line );
            if ( $line === '' ) {
                if ( $buffer ) { $this->process_sse( $buffer, $on_chunk ); $buffer = ''; }
                continue;
            }
            if ( str_starts_with( $line, 'data: ' ) ) {
                $data = substr( $line, 6 );
                if ( $data === '[DONE]' ) { break; }
                $buffer .= $data;
            }
        }
        fclose( $fp );
        @unlink( $temp );
    }

    protected function process_sse( string $data, callable $on_chunk ): void {
        $parsed = json_decode( $data, true );
        if ( $parsed && is_array( $parsed ) && isset( $parsed['choices'] ) ) {
            $on_chunk( $parsed );
        }
    }

    protected function parse_response( array $response ): Message {
        $choice  = $response['choices'][0] ?? [];
        $msg     = $choice['message'] ?? [];
        $content = Helpers::strip_reasoning( (string) ( $msg['content'] ?? '' ) );
        return new Message(
            $msg['role'] ?? 'assistant',
            $content,
            $msg['tool_calls'] ?? null,
            null,
            Helpers::estimate_tokens( $content )
        );
    }
}
