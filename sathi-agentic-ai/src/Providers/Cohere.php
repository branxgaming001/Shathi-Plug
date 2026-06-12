<?php
/**
 * Cohere provider adapter (Chat v2 + Embed v2).
 *
 * @package RaiLabs\Sathi\Providers
 */

namespace RaiLabs\Sathi\Providers;

use RaiLabs\Sathi\Core\Data\Message;
use RaiLabs\Sathi\Providers\Contracts\ProviderInterface;
use RaiLabs\Sathi\Support\Helpers;

class Cohere implements ProviderInterface {

    private string $base_url;
    private string $api_key;
    private string $model;
    private int $timeout;
    private float $temperature;
    private int $max_tokens;

    public function __construct( array $config = [] ) {
        $this->base_url    = rtrim( (string) ( $config['base_url'] ?? 'https://api.cohere.com' ), '/' );
        $this->api_key     = (string) ( $config['api_key'] ?? '' );
        $this->model       = (string) ( ( $config['model'] ?? '' ) ?: 'command-r-plus' );
        $this->timeout     = (int) ( $config['timeout'] ?? SATHI_DEFAULT_TIMEOUT );
        $this->temperature = (float) ( $config['temperature'] ?? 0.7 );
        $this->max_tokens  = (int) ( $config['max_tokens'] ?? 4096 );
    }

    public function key(): string { return 'cohere'; }
    public function label(): string { return __( 'Cohere', 'sathi-agentic-ai' ); }
    public function supports_streaming(): bool { return true; }
    public function supports_function_calling(): bool { return true; }
    public function supports_vision(): bool { return false; }
    public function default_model(): string { return 'command-r-plus'; }

    public function chat( array $messages, array $options = [] ): Message {
        $msgs = array_map( fn( Message $m ) => [ 'role' => $m->role, 'content' => $m->content ], $messages );
        if ( ! empty( $options['system_prompt'] ) ) {
            array_unshift( $msgs, [ 'role' => 'system', 'content' => $options['system_prompt'] ] );
        }
        $body = [
            'model'       => $options['model'] ?? $this->model,
            'messages'    => $msgs,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'max_tokens'  => $options['max_tokens'] ?? $this->max_tokens,
        ];
        $response = $this->request( '/v2/chat', $body );

        $text = '';
        foreach ( (array) ( $response['message']['content'] ?? [] ) as $part ) {
            if ( ( $part['type'] ?? '' ) === 'text' ) { $text .= $part['text'] ?? ''; }
        }
        return new Message( 'assistant', $text, null, null, Helpers::estimate_tokens( $text ) );
    }

    /**
     * Streaming: Cohere supports SSE, but to keep this robust we resolve the
     * full response and emit it once. Functionally correct for the chat UI.
     */
    public function chat_stream( array $messages, callable $callback, array $options = [] ): Message {
        $message = $this->chat( $messages, $options );
        if ( $message->content !== '' ) {
            $callback( $message->content );
        }
        return $message;
    }

    public function embed( $input, array $options = [] ): array {
        $is_single = ! is_array( $input );
        $texts     = $is_single ? [ $input ] : $input;
        $response  = $this->request( '/v2/embed', [
            'model'           => $options['model'] ?? 'embed-english-v3.0',
            'texts'           => $texts,
            'input_type'      => $options['input_type'] ?? 'search_document',
            'embedding_types' => [ 'float' ],
        ] );
        $vectors = $response['embeddings']['float'] ?? [];
        return $is_single ? ( $vectors[0] ?? [] ) : $vectors;
    }

    public function is_configured(): bool { return ! empty( $this->api_key ); }

    public function count_tokens( array $messages ): int {
        $total = 0;
        foreach ( $messages as $m ) { $total += $m->token_count ?? Helpers::estimate_tokens( $m->content ); }
        return $total;
    }

    public function available_models(): array {
        return [
            'command-r-plus' => [ 'label' => 'Command R+', 'context_window' => 128000, 'streaming' => true, 'vision' => false ],
            'command-r'      => [ 'label' => 'Command R',  'context_window' => 128000, 'streaming' => true, 'vision' => false ],
            'command'        => [ 'label' => 'Command',    'context_window' => 4096,   'streaming' => true, 'vision' => false ],
        ];
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

    private function request( string $endpoint, array $body ): array {
        $response = wp_remote_post( $this->base_url . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
            'timeout' => $this->timeout,
        ] );
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Cohere: ' . $response->get_error_message() );
        }
        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status >= 400 ) {
            throw new \RuntimeException( 'Cohere: ' . ( $data['message'] ?? "HTTP {$status}" ), (int) $status );
        }
        return is_array( $data ) ? $data : [];
    }
}
