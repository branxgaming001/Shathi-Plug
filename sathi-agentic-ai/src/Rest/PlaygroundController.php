<?php
/**
 * Playground REST Controller — a safe, admin-only test chat.
 *
 * Lets the site owner chat with their configured AI right inside wp-admin to
 * confirm the API key + model actually work, and — when something fails —
 * returns a *classified* error (auth / model / network / context / rate-limit)
 * with a plain-language hint, instead of a raw stack trace.
 *
 * @package NeerMedia\Sathi\Rest
 */

namespace NeerMedia\Sathi\Rest;

use NeerMedia\Sathi\Core\Settings;
use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Providers\Factory;
use NeerMedia\Sathi\Personas\PromptComposer;
use WP_REST_Request;
use WP_REST_Response;

class PlaygroundController {

    private const NAMESPACE = 'sathi/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/playground/chat', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'chat' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/persona/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_persona' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
        ] );
    }

    /**
     * Generate a ready-to-use persona (name + instructions) from the owner's
     * plain-language description and optional guided answers, using the
     * configured AI. Returns { success, name, persona } or a friendly error.
     */
    public function generate_persona( WP_REST_Request $request ): WP_REST_Response {
        $body        = (array) $request->get_json_params();
        $description = sanitize_textarea_field( (string) ( $body['description'] ?? '' ) );
        $answers     = is_array( $body['answers'] ?? null ) ? $body['answers'] : [];

        $clean = [];
        foreach ( $answers as $q => $a ) {
            $a = trim( sanitize_textarea_field( (string) $a ) );
            if ( $a !== '' ) {
                $clean[] = '- ' . sanitize_text_field( (string) $q ) . ' ' . $a;
            }
        }

        if ( $description === '' && empty( $clean ) ) {
            return $this->fail( 'input', __( 'Tell me a little about your assistant first.', 'sathi-agentic-ai' ), __( 'Describe how you want it to behave, or answer a couple of the questions.', 'sathi-agentic-ai' ), 400 );
        }

        $settings = new Settings();
        $provider = (string) $settings->get( Settings::KEY_DEFAULT_PROVIDER, '' );
        if ( $provider === '' ) {
            return $this->fail( 'config', __( 'No AI provider connected.', 'sathi-agentic-ai' ), __( 'Add an API key under AI Providers first, then generate.', 'sathi-agentic-ai' ), 400 );
        }

        $site = get_bloginfo( 'name' );
        $sys  = "You are an expert at writing system-prompt personas for a website's AI support assistant. "
            . "Given the owner's wishes, write a persona the assistant will follow. Output STRICT JSON only, no markdown, no preamble, exactly: "
            . '{"name":"<short assistant name>","persona":"<persona instructions, 4-8 sentences>"}. '
            . "The persona must: define the assistant's role for the site \"{$site}\", its tone and style, what it should help with, how to greet, and to stay on-topic and hand off to humans when unsure. "
            . 'Write it in clear, simple English. Do not include any sensitive-data handling (that is added automatically). Do not include <think> tags.';

        $user = "Owner's description: " . ( $description !== '' ? $description : '(none)' );
        if ( $clean ) {
            $user .= "\n\nOwner's answers:\n" . implode( "\n", $clean );
        }
        $user .= "\n\nReturn ONLY the JSON object.";

        $factory = new Factory( $settings );
        try {
            $adapter = $factory->make( $provider );
            if ( ! $adapter->is_configured() ) {
                return $this->fail( 'auth', __( 'The default provider has no API key.', 'sathi-agentic-ai' ), __( 'Add a valid key under AI Providers and save.', 'sathi-agentic-ai' ), 400, $provider );
            }
            $resp = $adapter->chat(
                [ Message::system( $sys ), Message::user( $user ) ],
                [ 'max_tokens' => 700, 'temperature' => 0.7 ]
            );
            $text = \NeerMedia\Sathi\Support\Helpers::strip_reasoning( (string) $resp->content );

            // Pull the JSON object out of the reply.
            $name = '';
            $persona = '';
            if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
                $json = json_decode( $m[0], true );
                if ( is_array( $json ) ) {
                    $name    = trim( (string) ( $json['name'] ?? '' ) );
                    $persona = trim( (string) ( $json['persona'] ?? '' ) );
                }
            }
            if ( $persona === '' ) {
                // Model didn't return JSON — use the whole cleaned reply as the persona.
                $persona = $text;
            }
            if ( $persona === '' ) {
                return $this->fail( 'empty', __( 'The model returned nothing usable.', 'sathi-agentic-ai' ), __( 'Try again, or pick a more capable model under AI Providers.', 'sathi-agentic-ai' ), 200, $provider );
            }

            return new WP_REST_Response( [
                'success' => true,
                'name'    => $name !== '' ? $name : 'Saathi',
                'persona' => $persona,
            ] );
        } catch ( \Throwable $e ) {
            [ $stage, $hint ] = $this->classify( $e );
            return $this->fail( $stage, $e->getMessage(), $hint, 200, $provider );
        }
    }

    /**
     * Run a single test turn against the chosen (or default) provider/model.
     */
    public function chat( WP_REST_Request $request ): WP_REST_Response {
        $body    = (array) $request->get_json_params();
        $message = sanitize_textarea_field( (string) ( $body['message'] ?? '' ) );

        if ( $message === '' ) {
            return $this->fail( 'input', __( 'Type a message to test.', 'sathi-agentic-ai' ), __( 'Enter any question, e.g. "What do you sell?"', 'sathi-agentic-ai' ), 400 );
        }

        $settings = new Settings();
        $provider = sanitize_text_field( (string) ( $body['provider'] ?? '' ) );
        if ( $provider === '' ) {
            $provider = (string) $settings->get( Settings::KEY_DEFAULT_PROVIDER, '' );
        }
        if ( $provider === '' ) {
            return $this->fail( 'config', __( 'No AI provider selected.', 'sathi-agentic-ai' ), __( 'Add an API key for any provider in the AI Providers tab first.', 'sathi-agentic-ai' ), 400 );
        }

        $model = sanitize_text_field( (string) ( $body['model'] ?? '' ) );

        // Build the conversation (recent history + this message).
        $messages = [];
        $history  = is_array( $body['history'] ?? null ) ? $body['history'] : [];
        foreach ( array_slice( $history, -10 ) as $turn ) {
            $role    = ( ( $turn['role'] ?? '' ) === 'assistant' ) ? 'assistant' : 'user';
            $content = sanitize_textarea_field( (string) ( $turn['content'] ?? '' ) );
            if ( $content !== '' ) {
                $messages[] = ( $role === 'assistant' ) ? Message::assistant( $content ) : Message::user( $content );
            }
        }
        $messages[] = Message::user( $message );

        // System prompt mirrors production behaviour (persona + scope + safety).
        $system_prompt = ( new PromptComposer() )->compose( '', [
            'site_name'        => get_bloginfo( 'name' ),
            'site_description' => get_bloginfo( 'description' ),
            'site_url'         => home_url(),
        ] );

        $factory = new Factory( $settings );

        try {
            $adapter = $factory->make( $provider );
        } catch ( \Throwable $e ) {
            return $this->fail( 'config', $e->getMessage(), __( 'This provider could not be initialised. Re-check the provider settings.', 'sathi-agentic-ai' ), 500, $provider, $model );
        }

        if ( ! $adapter->is_configured() ) {
            return $this->fail(
                'auth',
                __( 'API key missing for this provider.', 'sathi-agentic-ai' ),
                __( 'Paste a valid API key in the AI Providers tab and click Save, then test again.', 'sathi-agentic-ai' ),
                400,
                $provider,
                $model
            );
        }

        $options = [
            'system_prompt' => $system_prompt,
            'max_tokens'    => 512,
            'temperature'   => isset( $body['temperature'] ) ? (float) $body['temperature'] : 0.7,
        ];
        if ( $model !== '' ) {
            $options['model'] = $model;
        }

        $started = microtime( true );
        try {
            $response = $adapter->chat( $messages, $options );
            $reply    = trim( (string) $response->content );

            if ( $reply === '' ) {
                return $this->fail(
                    'empty',
                    __( 'The model returned an empty response.', 'sathi-agentic-ai' ),
                    __( 'Try a different model, or lower max tokens. Some free models occasionally return nothing — retry once.', 'sathi-agentic-ai' ),
                    200,
                    $provider,
                    $model
                );
            }

            return new WP_REST_Response( [
                'success'  => true,
                'reply'    => $reply,
                'provider' => $provider,
                'model'    => $model !== '' ? $model : ( method_exists( $adapter, 'get_model' ) ? $adapter->get_model() : '' ),
                'tokens'   => $response->token_count,
                'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
            ] );
        } catch ( \Throwable $e ) {
            [ $stage, $hint ] = $this->classify( $e );
            return $this->fail( $stage, $e->getMessage(), $hint, 200, $provider, $model );
        }
    }

    /**
     * Map an exception to a (stage, friendly-hint) pair. The adapter throws
     * RuntimeExceptions whose code carries the HTTP status and whose message
     * carries the provider's error text.
     *
     * @return array{0:string,1:string}
     */
    private function classify( \Throwable $e ): array {
        $code = (int) $e->getCode();
        $msg  = strtolower( $e->getMessage() );

        $has = static function ( array $needles ) use ( $msg ): bool {
            foreach ( $needles as $n ) {
                if ( strpos( $msg, $n ) !== false ) {
                    return true;
                }
            }
            return false;
        };

        if ( $code === 401 || $has( [ 'invalid api key', 'incorrect api key', 'unauthorized', 'invalid_api_key', 'authentication' ] ) ) {
            return [ 'auth', __( 'The API key was rejected. Copy a fresh key from the provider dashboard and save it again.', 'sathi-agentic-ai' ) ];
        }
        if ( $code === 403 || $has( [ 'forbidden', 'permission', 'not allowed' ] ) ) {
            return [ 'auth', __( 'Access denied for this key — it may lack permission or billing. Check your provider account.', 'sathi-agentic-ai' ) ];
        }
        if ( $code === 404 || $has( [ 'model not found', 'no such model', 'does not exist', 'unknown model', 'model_not_found' ] ) ) {
            return [ 'model', __( 'That model name was not found. Click the ⟳ button to load available models and pick a valid one.', 'sathi-agentic-ai' ) ];
        }
        if ( $code === 429 || $has( [ 'rate limit', 'too many requests', 'quota', 'insufficient_quota' ] ) ) {
            return [ 'rate_limit', __( 'Rate limit or quota hit. Wait a moment, add billing, or try a free model.', 'sathi-agentic-ai' ) ];
        }
        if ( $has( [ 'context length', 'maximum context', 'context_length_exceeded', 'too many tokens', 'reduce the length' ] ) ) {
            return [ 'context', __( 'The request was too long for this model. Lower max tokens or shorten the conversation.', 'sathi-agentic-ai' ) ];
        }
        if ( $code === 0 || $has( [ 'could not resolve', 'timed out', 'timeout', 'connection', 'curl', 'ssl', 'network', 'failed to read stream' ] ) ) {
            return [ 'network', __( 'Could not reach the provider. Check the Base URL, your server\'s outbound internet, and firewall.', 'sathi-agentic-ai' ) ];
        }
        if ( $code === 400 ) {
            return [ 'request', __( 'The provider rejected the request. Often the model name or a parameter is off — verify the model.', 'sathi-agentic-ai' ) ];
        }
        return [ 'unknown', __( 'Unexpected error. The provider\'s message above usually explains what to fix.', 'sathi-agentic-ai' ) ];
    }

    /**
     * Build a structured failure response.
     */
    private function fail( string $stage, string $error, string $hint, int $http = 200, string $provider = '', string $model = '' ): WP_REST_Response {
        return new WP_REST_Response( [
            'success'  => false,
            'stage'    => $stage,
            'error'    => $error,
            'hint'     => $hint,
            'provider' => $provider,
            'model'    => $model,
        ], $http );
    }

    public function check_admin_permission(): bool {
        return current_user_can( 'manage_options' );
    }
}
