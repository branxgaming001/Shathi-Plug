<?php
/**
 * Site Crawler — extracts and chunks all publishable WordPress content for the KB.
 *
 * @package NeerMedia\Sathi\Knowledge
 */

namespace NeerMedia\Sathi\Knowledge;

use NeerMedia\Sathi\Support\Helpers;

class SiteCrawler {

    /** Synthetic source IDs for the site-wide header & footer (not real posts). */
    public const SOURCE_HEADER = 999000001;
    public const SOURCE_FOOTER = 999000002;

    /** @var string[] Post types to crawl */
    private array $content_types;

    /** @var int Chunk size in tokens */
    private int $chunk_tokens;

    /** @var int Overlap between chunks */
    private int $overlap_tokens;

    public function __construct() {
        // Index ALL public content (posts, pages, products AND any public custom
        // post types used by themes/page-builders) so Saathi reads the owner's
        // real content, not just the default post/page set.
        $public = get_post_types( [ 'public' => true, 'exclude_from_search' => false ], 'names' );
        unset( $public['attachment'] );
        $defaults = ! empty( $public ) ? array_values( $public ) : [ 'post', 'page', 'product' ];
        $this->content_types = apply_filters( 'sathi_knowledge_post_types', $defaults );
        $this->chunk_tokens  = apply_filters( 'sathi_chunk_size', 512 );
        $this->overlap_tokens = apply_filters( 'sathi_chunk_overlap', 50 );
    }

    /**
     * Total number of indexable items (for scan progress).
     */
    public function count_all(): int {
        $q = new \WP_Query( [
            'post_type'      => $this->content_types,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'has_password'   => false,
            'meta_query'     => [ [ 'key' => '_sathi_exclude', 'compare' => 'NOT EXISTS' ] ],
        ] );
        return (int) $q->found_posts;
    }

    /**
     * Crawl all content in batches.
     *
     * @param  int $limit  Posts per batch.
     * @param  int $offset Starting offset.
     * @return array[]     Chunked content.
     */
    public function crawl_all( int $limit = 20, int $offset = 0 ): array {
        $posts = get_posts( [
            'post_type'      => $this->content_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'has_password'   => false,
            'meta_query'     => [
                [
                    'key'     => '_sathi_exclude',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        $all_chunks = [];
        foreach ( $posts as $post ) {
            $chunks = $this->crawl_post( $post->ID );
            $all_chunks = array_merge( $all_chunks, $chunks );
        }

        return $all_chunks;
    }

    /**
     * Crawl a single post.
     *
     * @param  int   $post_id
     * @return array[]
     */
    public function crawl_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' || post_password_required( $post ) ) {
            return [];
        }

        $source_url = get_permalink( $post );
        $source_type = $post->post_type;

        // Build rich text from title + content + excerpt + metadata
        $text_parts = [];

        if ( $post->post_title ) {
            $text_parts[] = '# ' . $post->post_title;
        }

        if ( $post->post_excerpt ) {
            $text_parts[] = wp_strip_all_tags( $post->post_excerpt, true );
        }

        // Content extraction. post_content + the_content captures classic and
        // most shortcode/Gutenberg content. But page-builders (Elementor, Divi,
        // WPBakery, Beaver, SiteOrigin, Bricks, Oxygen) store the real content in
        // postmeta, so the_content can be thin/stale ("reads the theme default,
        // not the updated content"). In those cases we fetch the LIVE rendered
        // page and extract the main content — exactly what visitors see.
        $content = apply_filters( 'the_content', $post->post_content );
        $content = $this->clean_html( $content );

        if ( $this->is_builder_post( $post->ID ) || mb_strlen( $content ) < 160 ) {
            $rendered = $this->fetch_rendered_content( $source_url );
            if ( mb_strlen( $rendered ) > mb_strlen( $content ) ) {
                $content = $rendered;
            }
        }

        if ( $content ) {
            $text_parts[] = $content;
        }

        // Add rich fields for WooCommerce products so the bot can answer about
        // and sell them accurately.
        if ( $source_type === 'product' && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post_id );
            if ( $product ) {
                $desc  = wp_strip_all_tags( $product->get_description(), true );
                $sdesc = wp_strip_all_tags( $product->get_short_description(), true );
                if ( $sdesc ) { $text_parts[] = $sdesc; }
                if ( $desc )  { $text_parts[] = $desc; }
                $text_parts[] = 'Price: ' . wp_strip_all_tags( $product->get_price_html() ?: (string) $product->get_price() );
                if ( $product->get_sku() ) { $text_parts[] = 'SKU: ' . $product->get_sku(); }
                $text_parts[] = 'Availability: ' . ( $product->is_in_stock() ? 'In stock' : 'Out of stock' );
                $cats = wp_strip_all_tags( wc_get_product_category_list( $post_id ) );
                if ( $cats ) { $text_parts[] = 'Categories: ' . $cats; }
                $text_parts[] = 'Product link: ' . get_permalink( $post_id );
            }
        }

        $full_text = implode( "\n\n", array_filter( $text_parts ) );

        // Chunk
        $chunks = Helpers::chunk_text( $full_text, $this->chunk_tokens, $this->overlap_tokens );

        return array_map( function ( string $text, int $index ) use ( $source_url, $source_type, $post_id ) {
            return [
                'source_url'    => $source_url,
                'source_type'   => $source_type,
                'source_id'     => $post_id,
                'chunk_index'   => $index,
                'content'       => $text,
            ];
        }, $chunks, array_keys( $chunks ) );
    }

    /** Strip HTML to clean readable text (no leaked CSS/JS). */
    private function clean_html( string $html ): string {
        $html = (string) $html;
        // Safe preg_replace: if PCRE bails on very large input (backtrack
        // limit), keep the previous string instead of nulling everything out.
        $rep = static function ( string $pattern, string $subject ): string {
            $out = preg_replace( $pattern, ' ', $subject );
            return is_string( $out ) ? $out : $subject;
        };
        // 1) Remove code/markup blocks ENTIRELY — content included. Critical:
        //    wp_strip_all_tags() removes <style>/<script> TAGS but keeps the
        //    CSS/JS text between them, which otherwise pollutes the KB
        //    (e.g. Elementor's inline ".elementor-widget{…}" rules).
        $html = $rep( '#<(script|style|noscript|svg|template|iframe|select|option)\b[^>]*>.*?</\1>#is', $html );
        // 2) Drop HTML comments (page-builders leave large comment blocks).
        $html = $rep( '#<!--.*?-->#s', $html );
        // 2b) Separate adjacent elements so text nodes don't fuse
        //     ("Nilesh Engineers" + "Call us" → "Nilesh EngineersCall us").
        $html = str_replace( '<', ' <', $html );
        // 3) Strip remaining tags.
        $html = wp_strip_all_tags( $html, true );
        // 4) Scrub any residual CSS that builders embed as plain text: block
        //    comments and flat "selector { … }" rule blocks.
        $html = $rep( '~/\*.*?\*/~s', $html );
        $html = $rep( '~[.#]?[A-Za-z0-9_\-]+\s*\{[^{}]*\}~', $html );
        // 5) Decode entities + collapse whitespace.
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $html = $rep( '/\s+/', $html );
        return trim( $html );
    }

    /** Does this post use a page builder (content lives in postmeta)? */
    private function is_builder_post( int $post_id ): bool {
        foreach ( [ '_elementor_data', '_et_pb_use_builder', 'panels_data', '_fl_builder_enabled', '_wpb_shortcodes_custom_css', '_themify_builder_settings_json', '_oxygen_data', '_brick_data', 'ct_builder_shortcodes' ] as $meta ) {
            if ( get_post_meta( $post_id, $meta, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch a page's LIVE rendered HTML (loopback) and extract the main content,
     * stripping nav/header/footer/scripts so we keep the real, current content.
     */
    private function fetch_rendered_content( string $url ): string {
        if ( ! $url ) {
            return '';
        }
        $res = wp_remote_get( $url, [
            'timeout'     => 15,
            'redirection' => 2,
            'sslverify'   => false,
            'user-agent'  => 'SaathiBot/1.0 (+knowledge-scan)',
            'headers'     => [ 'Accept' => 'text/html' ],
        ] );
        if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
            return '';
        }
        $html = (string) wp_remote_retrieve_body( $res );
        if ( $html === '' ) {
            return '';
        }

        // Drop non-content regions entirely.
        $html = preg_replace( '#<(script|style|noscript|svg|nav|header|footer|aside|form|template)\b[^>]*>.*?</\1>#is', ' ', $html );

        // Prefer the main content region if the theme marks one.
        if ( preg_match( '#<main\b[^>]*>(.*?)</main>#is', $html, $m ) ) {
            $html = $m[1];
        } elseif ( preg_match( '#<article\b[^>]*>(.*?)</article>#is', $html, $m ) ) {
            $html = $m[1];
        } elseif ( preg_match( '#<body\b[^>]*>(.*?)</body>#is', $html, $m ) ) {
            $html = $m[1];
        }

        return $this->clean_html( $html );
    }

    /**
     * Index the site-wide HEADER and FOOTER once. Themes put the real,
     * sitewide info there — business name, navigation, and especially the
     * genuine contact details (phone, email, address, hours, social links).
     * Stored as two dedicated sources so the bot has them without repeating
     * them on every page.
     *
     * @return array[] chunks (0–2 entries)
     */
    public function crawl_site_parts(): array {
        $home = home_url( '/' );
        $res  = wp_remote_get( $home, [
            'timeout'     => 15,
            'redirection' => 2,
            'sslverify'   => false,
            'user-agent'  => 'SaathiBot/1.0 (+knowledge-scan)',
            'headers'     => [ 'Accept' => 'text/html' ],
        ] );
        if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
            return [];
        }
        $html = (string) wp_remote_retrieve_body( $res );
        if ( $html === '' ) {
            return [];
        }

        $chunks = [];

        $header = $this->extract_region( $html, 'header', [ 'site-header', 'masthead', 'main-header', 'topbar', 'top-bar', 'navbar' ] );
        if ( $header !== '' ) {
            $chunks[] = [
                'source_url'  => $home,
                'source_type' => 'site_part',
                'source_id'   => self::SOURCE_HEADER,
                'chunk_index' => 0,
                'content'     => "# Site header & navigation\n\n" . $header,
            ];
        }

        $footer = $this->extract_region( $html, 'footer', [ 'site-footer', 'colophon', 'main-footer', 'footer-widgets', 'footer' ] );
        if ( $footer !== '' ) {
            $chunks[] = [
                'source_url'  => $home,
                'source_type' => 'site_part',
                'source_id'   => self::SOURCE_FOOTER,
                'chunk_index' => 0,
                'content'     => "# Site footer — contact details, address & links\n\n" . $footer,
            ];
        }

        return $chunks;
    }

    /**
     * Extract a sitewide region (header/footer) from raw homepage HTML.
     * Prefers the semantic <header>/<footer> tag, then falls back to common
     * id/class hints. Returns cleaned, length-capped text.
     *
     * @param string   $html        Raw homepage HTML.
     * @param string   $tag         'header' or 'footer'.
     * @param string[] $class_hints id/class substrings to try as a fallback.
     */
    private function extract_region( string $html, string $tag, array $class_hints ): string {
        $region = '';
        if ( preg_match( '#<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>#is', $html, $m ) ) {
            $region = $m[1];
        } else {
            foreach ( $class_hints as $hint ) {
                if ( preg_match( '#<([a-z0-9]+)\b[^>]*(?:id|class)="[^"]*' . preg_quote( $hint, '#' ) . '[^"]*"[^>]*>(.*?)</\1>#is', $html, $m ) ) {
                    $region = $m[2];
                    break;
                }
            }
        }
        if ( $region === '' ) {
            return '';
        }
        $text = $this->clean_html( $region );
        // Cap so sitewide nav/footer never dominates the knowledge base.
        if ( mb_strlen( $text ) > 1500 ) {
            $text = mb_substr( $text, 0, 1500 );
        }
        return $text;
    }

    /**
     * Get the content types being crawled.
     *
     * @return string[]
     */
    public function get_content_types(): array {
        return $this->content_types;
    }
}
