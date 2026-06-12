<?php
/**
 * Prompt Composer — builds the final system prompt from persona + context + memory.
 *
 * @package RaiLabs\Sathi\Personas
 */

namespace RaiLabs\Sathi\Personas;

class PromptComposer {

    /**
     * Compose a full system prompt for the AI.
     *
     * @param  string $persona_slug
     * @param  array  $context      Arbitrary context keys (site_name, page_title, user_name, etc.)
     * @return string
     */
    public function compose( string $persona_slug, array $context = [] ): string {
        $persona = ( new PersonaRegistry() )->get( $persona_slug );

        if ( ! $persona ) {
            $persona = ( new PersonaRegistry() )->get( 'sathi-guru' );
        }

        // If there's a fully custom system prompt, use it directly.
        if ( ! empty( $persona['system_prompt'] ) ) {
            return $this->interpolate( $persona['system_prompt'], $context );
        }

        $lines = [];

        // Identity
        $lines[] = sprintf(
            'You are %s, %s. %s',
            $persona['name'],
            $persona['role'],
            $persona['description']
        );

        // Tone and style
        $lines[] = sprintf(
            'Speak in a %s tone. %s',
            $persona['tone'],
            $persona['style']
        );

        // Site context
        if ( ! empty( $context['site_name'] ) ) {
            $lines[] = 'You are currently assisting visitors on ' . $context['site_name'] . '.';
            if ( ! empty( $context['site_description'] ) ) {
                $lines[] = 'Site description: ' . $context['site_description'];
            }
        }

        // Page context
        if ( ! empty( $context['current_page'] ) ) {
            $lines[] = 'The user is viewing: ' . $context['current_page'];
        }

        // Memory context
        if ( ! empty( $context['memory'] ) ) {
            $lines[] = 'Information about this user from previous conversations:';
            $lines[] = $context['memory'];
        }

        // Knowledge context
        if ( ! empty( $context['knowledge_summary'] ) ) {
            $lines[] = 'Relevant site content you can reference (use this to answer):';
            $lines[] = $context['knowledge_summary'];
        }

        // Strict scope — answer only from this website's content/products.
        $strict = (bool) ( new \RaiLabs\Sathi\Core\Settings() )->get( \RaiLabs\Sathi\Core\Settings::KEY_STRICT_SCOPE, true );
        if ( $strict ) {
            $site = $context['site_name'] ?? 'this website';
            $lines[] = sprintf(
                'SCOPE — IMPORTANT: You are strictly an assistant for %1$s. Answer only using this website\'s content, products, and the information provided above. '
                . 'If the visitor asks about anything unrelated to %1$s (general knowledge, world facts, other companies, coding help, etc.), do NOT answer it — reply exactly: '
                . '"I can only help with questions about this website, its products, and its content." Never invent facts that are not supported by the site content above.',
                $site
            );
        }

        // Available actions
        if ( ! empty( $context['allowed_actions'] ) ) {
            $lines[] = 'You can guide the user through the site using these safe actions:';
            $lines[] = $context['allowed_actions'];
        }

        // Constraints
        $lines[] = $this->get_constraints();

        return implode( "\n\n", $lines );
    }

    /**
     * Standard constraints applied to all personas.
     */
    private function get_constraints(): string {
        return implode( "\n", [
            'Important guidelines:',
            '- Be helpful, accurate, and concise.',
            '- If you don\'t know the answer, say so honestly — don\'t make things up.',
            '- When referencing site content, be specific and cite the page or source.',
            '- Respect user privacy — never ask for sensitive personal information.',
            '- If the user needs human help, guide them to the site\'s contact page or support channels.',
            '- Keep responses relevant to the site\'s purpose and the user\'s question.',
        ] );
    }

    /**
     * Interpolate {{placeholders}} in a custom system prompt.
     *
     * @param  string $template
     * @param  array  $context
     * @return string
     */
    private function interpolate( string $template, array $context ): string {
        $replace = [];
        foreach ( $context as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $replace[ '{{' . $key . '}}' ] = $value;
            }
        }
        return strtr( $template, $replace );
    }
}
