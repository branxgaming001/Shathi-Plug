<?php
/**
 * Provider-agnostic LLM interface.
 *
 * Every provider adapter MUST implement this contract so the chat engine
 * can swap between OpenAI, Anthropic, Gemini, OpenRouter, local models, etc.
 * without changing any calling code.
 *
 * @package NeerMedia\Sathi\Providers\Contracts
 */

namespace NeerMedia\Sathi\Providers\Contracts;

use NeerMedia\Sathi\Core\Data\Message;
use NeerMedia\Sathi\Core\Data\FunctionCall;
use NeerMedia\Sathi\Core\Data\FunctionResult;

interface ProviderInterface {

    /**
     * Return the provider key (e.g. "openai", "anthropic").
     */
    public function key(): string;

    /**
     * Return a human-readable label for admin UI.
     */
    public function label(): string;

    /**
     * Whether this provider supports streaming responses.
     */
    public function supports_streaming(): bool;

    /**
     * Whether this provider supports native function/tool calling.
     */
    public function supports_function_calling(): bool;

    /**
     * Whether this provider supports vision / image input.
     */
    public function supports_vision(): bool;

    /**
     * Send a chat completion request (non-streaming).
     *
     * @param  Message[] $messages  Conversation history.
     * @param  array     $options   { model, temperature, max_tokens, system_prompt, tools, ... }
     * @return Message              The assistant's response message.
     * @throws \RuntimeException On API failure.
     */
    public function chat( array $messages, array $options = [] ): Message;

    /**
     * Send a streaming chat completion request.
     *
     * The $callback receives each text delta as it arrives.
     *
     * @param  Message[]  $messages
     * @param  callable   $callback  function(string $delta): void
     * @param  array      $options
     * @return Message               Complete assistant response.
     * @throws \RuntimeException On API failure.
     */
    public function chat_stream( array $messages, callable $callback, array $options = [] ): Message;

    /**
     * Generate embeddings for a text (or batch of texts).
     *
     * @param  string|string[] $input
     * @param  array            $options
     * @return array            Single vector (float[]) or batch (float[][]).
     */
    public function embed( $input, array $options = [] ): array;

    /**
     * Validate that this provider is correctly configured.
     *
     * @return bool
     */
    public function is_configured(): bool;

    /**
     * Count tokens for the given messages (approximation acceptable).
     *
     * @param  Message[] $messages
     * @return int
     */
    public function count_tokens( array $messages ): int;

    /**
     * Get the list of available models for this provider.
     *
     * @return array<string, array{label: string, context_window: int, streaming: bool, vision: bool}>
     */
    public function available_models(): array;

    /**
     * Get the default model for this provider.
     */
    public function default_model(): string;

    /**
     * Transform Sathi FunctionCall/FunctioResult objects into the provider-specific
     * tool calling format for the API request.
     *
     * @param  array $tools Array of tool definitions { name, description, parameters (JSON Schema) }.
     * @return array Provider-specific tool array.
     */
    public function format_tools( array $tools ): array;
}
