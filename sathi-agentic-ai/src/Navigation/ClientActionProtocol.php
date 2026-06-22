<?php
/**
 * Client Action Protocol — safe, constrained actions the AI can instruct the browser to perform.
 *
 * Every action is validated against an allowlist before serialization.
 * The React widget interprets these on the client side.
 *
 * @package NeerMedia\Sathi\Navigation
 */

namespace NeerMedia\Sathi\Navigation;

class ClientActionProtocol {

    /** @var string Site domain for URL validation */
    private string $site_domain;

    /** @var array Allowlisted CSS selector patterns */
    private array $selector_allowlist;

    public function __construct() {
        $this->site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
        $this->selector_allowlist = apply_filters( 'sathi_allowed_selectors', [
            '#main',
            '#content',
            '#contact',
            '#footer',
            '.hero',
            '.pricing',
            '.features',
            '.testimonials',
            '.contact-form',
            '.product-grid',
            '.search-form',
            'input',
            'textarea',
            'select',
            'button',
            'nav',
            'header',
            'footer',
            'main',
        ] );
    }

    /**
     * Build an action payload that the frontend React widget can interpret.
     *
     * @param  string $action Action type (navigate, scroll_to, highlight, focus_input, open_contact).
     * @param  array  $params Action parameters.
     * @return array{type: string, params: array, timestamp: string}|null Null if invalid.
     */
    public function build( string $action, array $params = [] ): ?array {
        if ( ! $this->is_action_allowed( $action ) ) {
            return null;
        }

        $validated = $this->validate_params( $action, $params );
        if ( $validated === null ) {
            return null;
        }

        return [
            'type'      => $action,
            'params'    => $validated,
            'timestamp' => gmdate( 'c' ),
        ];
    }

    /**
     * Check whether an action type is in the allowlist.
     */
    public function is_action_allowed( string $action ): bool {
        return in_array( $action, [
            'navigate',
            'scroll_to',
            'highlight',
            'focus_input',
            'open_contact',
            'show_tooltip',
        ], true );
    }

    /**
     * Validate and sanitise parameters for a given action.
     *
     * @param  string $action
     * @param  array  $params
     * @return array|null Validated params or null if invalid.
     */
    private function validate_params( string $action, array $params ): ?array {
        return match ( $action ) {
            'navigate'     => $this->validate_navigate( $params ),
            'scroll_to'    => $this->validate_selector( $params ),
            'highlight'    => $this->validate_selector( $params ),
            'focus_input'  => $this->validate_input_selector( $params ),
            'open_contact' => [], // No params needed
            'show_tooltip' => $this->validate_tooltip( $params ),
            default        => null,
        };
    }

    /**
     * Validate a navigate action — URL must belong to this site.
     *
     * @param  array $params
     * @return array|null
     */
    private function validate_navigate( array $params ): ?array {
        $url = $params['url'] ?? '';
        if ( empty( $url ) ) {
            return null;
        }

        $parsed = wp_parse_url( $url );
        if ( ! $parsed ) {
            return null;
        }

        // Relative URLs are fine
        if ( empty( $parsed['host'] ) ) {
            return [ 'url' => esc_url_raw( home_url( $url ) ) ];
        }

        // Absolute URLs must be same domain
        if ( $parsed['host'] === $this->site_domain ) {
            return [ 'url' => esc_url_raw( $url ) ];
        }

        return null; // External URL — rejected
    }

    /**
     * Validate a CSS selector against the allowlist.
     *
     * @param  array $params
     * @return array|null
     */
    private function validate_selector( array $params ): ?array {
        $selector = $params['selector'] ?? '';
        if ( empty( $selector ) ) {
            return null;
        }

        foreach ( $this->selector_allowlist as $allowed ) {
            if ( str_contains( $selector, $allowed ) ) {
                return [ 'selector' => sanitize_text_field( $selector ) ];
            }
        }

        // Element-only selectors (no classes/IDs) are safe-ish
        if ( preg_match( '/^[a-z][a-z0-9]*$/i', trim( $selector ) ) ) {
            return [ 'selector' => sanitize_text_field( $selector ) ];
        }

        return null;
    }

    /**
     * Validate selector specifically for input elements.
     */
    private function validate_input_selector( array $params ): ?array {
        $selector = $params['selector'] ?? '';
        if ( empty( $selector ) ) {
            return null;
        }

        $safe = [ 'input', 'textarea', 'select' ];
        foreach ( $safe as $el ) {
            if ( str_starts_with( $selector, $el ) ) {
                return [ 'selector' => sanitize_text_field( $selector ) ];
            }
        }

        return null;
    }

    /**
     * Validate tooltip parameters.
     */
    private function validate_tooltip( array $params ): ?array {
        $element = $params['element'] ?? '';
        $message = $params['message'] ?? '';

        if ( empty( $element ) || empty( $message ) ) {
            return null;
        }

        return [
            'element' => sanitize_text_field( $element ),
            'message' => wp_kses_post( $message ),
        ];
    }

    /**
     * Generate a description of available actions for inclusion in the system prompt.
     *
     * @return string
     */
    public function prompt_description(): string {
        return "You can guide users through the site with these actions:\n"
            . "- navigate: Open a page on this site (e.g., /contact, /pricing)\n"
            . "- scroll_to: Scroll to a section (e.g., #pricing, .features)\n"
            . "- highlight: Highlight an element to draw attention\n"
            . "- focus_input: Focus the user's cursor on a form field\n"
            . "- open_contact: Open the contact form\n"
            . "Use these sparingly and only when genuinely helpful to the user.";
    }

    /**
     * Get the site domain for client-side validation.
     */
    public function get_site_domain(): string {
        return $this->site_domain;
    }
}
