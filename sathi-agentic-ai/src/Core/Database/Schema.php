<?php
/**
 * Database schema definition and table management.
 *
 * @package NeerMedia\Sathi\Core\Database
 */

namespace NeerMedia\Sathi\Core\Database;

class Schema {

    /** @var string Current schema version */
    private const VERSION = '1.1.0';

    /**
     * Create (or update) all custom tables.
     */
    public static function create_tables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        // ── Conversations ────────────────────────────────────────
        $sql = "CREATE TABLE {$prefix}sathi_conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL COMMENT 'Public-facing UUID',
            user_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'WP user ID, NULL for guests',
            guest_id CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of IP+UA for guest sessions',
            persona_id VARCHAR(64) DEFAULT 'sathi-guru' COMMENT 'Active persona slug',
            provider VARCHAR(32) NOT NULL DEFAULT 'openai',
            model VARCHAR(128) DEFAULT NULL,
            status ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
            title VARCHAR(255) DEFAULT NULL COMMENT 'Auto-generated summary title',
            message_count INT UNSIGNED NOT NULL DEFAULT 0,
            metadata JSON DEFAULT NULL COMMENT 'Extensible metadata blob',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_user (user_id),
            KEY idx_guest (guest_id),
            KEY idx_status_created (status, created_at),
            KEY idx_updated (updated_at)
        ) {$charset};";

        dbDelta( $sql );

        // ── Messages ─────────────────────────────────────────────
        $sql = "CREATE TABLE {$prefix}sathi_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            role ENUM('system','user','assistant','tool','function') NOT NULL,
            content LONGTEXT NOT NULL COMMENT 'Message body — text, markdown, or HTML',
            tool_calls JSON DEFAULT NULL COMMENT 'Serialised function/tool calls',
            tool_result JSON DEFAULT NULL COMMENT 'Function call result',
            token_count INT UNSIGNED DEFAULT NULL COMMENT 'Estimated token usage',
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conversation (conversation_id),
            KEY idx_created (created_at),
            CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id)
                REFERENCES {$prefix}sathi_conversations(id) ON DELETE CASCADE
        ) {$charset};";

        dbDelta( $sql );

        // ── Memory entries (persistent user memory) ─────────────
        $sql = "CREATE TABLE {$prefix}sathi_memory_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            guest_id CHAR(64) DEFAULT NULL,
            key_slug VARCHAR(128) NOT NULL COMMENT 'Memory key (e.g. user_name, last_topic)',
            value LONGTEXT NOT NULL COMMENT 'Stored value (text or JSON)',
            importance TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1-10 priority score',
            source_conversation_id BIGINT UNSIGNED DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_key (user_id, guest_id, key_slug),
            KEY idx_user (user_id),
            KEY idx_guest (guest_id),
            KEY idx_importance (importance),
            KEY idx_expires (expires_at)
        ) {$charset};";

        dbDelta( $sql );

        // ── Knowledge chunks (site content KB) ──────────────────
        $sql = "CREATE TABLE {$prefix}sathi_knowledge_chunks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(2048) NOT NULL COMMENT 'Origin URL of the chunk',
            source_type ENUM('post','page','product','custom','site_part') NOT NULL DEFAULT 'post',
            source_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'WordPress post ID',
            chunk_index INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Position within source',
            content LONGTEXT NOT NULL,
            token_count INT UNSIGNED DEFAULT NULL,
            embedding JSON DEFAULT NULL COMMENT 'Cached vector embedding',
            checksum CHAR(64) DEFAULT NULL COMMENT 'SHA-256 of content for change detection',
            status ENUM('active','stale','deleted') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_type_status (source_type, status),
            KEY idx_checksum (checksum)
        ) {$charset};";

        dbDelta( $sql );

        // FULLTEXT index powers relevance-ranked keyword search (BM25-style),
        // so the knowledge base stays accurate even with NO embeddings provider
        // (free models). dbDelta can't reliably manage FULLTEXT keys, so add it
        // explicitly and idempotently.
        self::ensure_chunks_fulltext();

        // ── Personas (custom user-created personas) ─────────────
        $sql = "CREATE TABLE {$prefix}sathi_personas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            role VARCHAR(64) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            tone VARCHAR(64) DEFAULT NULL,
            style TEXT DEFAULT NULL,
            avatar VARCHAR(255) DEFAULT NULL COMMENT 'Emoji or URL',
            color CHAR(7) DEFAULT '#7c3aed',
            system_prompt LONGTEXT DEFAULT NULL COMMENT 'Full custom system prompt',
            is_predefined TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'Owner, NULL = site-wide',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_slug (slug),
            KEY idx_active (is_active)
        ) {$charset};";

        dbDelta( $sql );

        update_option( 'sathi_db_version', self::VERSION );
    }

    /**
     * Check whether schema is current.
     */
    public static function needs_update(): bool {
        $installed = get_option( 'sathi_db_version', '0' );
        return version_compare( $installed, self::VERSION, '<' );
    }

    /**
     * Ensure the knowledge-chunks table has a FULLTEXT index on `content`.
     * Idempotent and safe to call on any request (checks information_schema
     * first). InnoDB supports FULLTEXT on MySQL 5.6+ / MariaDB 10.0.5+; on the
     * rare engine that doesn't, the ALTER simply fails and search falls back to
     * LIKE — so this never breaks the plugin.
     */
    public static function ensure_chunks_fulltext(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sathi_knowledge_chunks';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Make sure 'site_part' is a valid source_type (header/footer sources).
        // Older installs had an ENUM without it, which silently coerced those
        // rows to '' and broke contact-info boosting.
        $col = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'source_type'" );
        if ( $col && isset( $col->Type ) && strpos( (string) $col->Type, 'site_part' ) === false ) {
            $prev = $wpdb->suppress_errors( true );
            $wpdb->query( "ALTER TABLE {$table} MODIFY source_type ENUM('post','page','product','custom','site_part') NOT NULL DEFAULT 'post'" );
            $wpdb->suppress_errors( $prev );
        }

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = %s AND table_name = %s AND index_name = 'ft_content'",
            DB_NAME, $table
        ) );
        if ( $exists > 0 ) {
            return;
        }

        // Suppress errors: a failed ALTER (unsupported engine) is non-fatal.
        $prev = $wpdb->suppress_errors( true );
        $wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_content (content)" );
        $wpdb->suppress_errors( $prev );
    }
}
