<?php
/**
 * Database configuration.
 *
 * Resolves credentials in this order so the SAME code runs locally and on the
 * cloud host (Railway) without edits:
 *   1) A full connection URL  (Railway: MYSQL_URL / DATABASE_URL)
 *   2) Individual env vars     (Railway: MYSQLHOST… or generic DB_*)
 *   3) Local sandbox dev defaults
 */
if (!function_exists('sathi_db_config')):
function sathi_db_config(): array
{
    // 1) Full URL, e.g. mysql://user:pass@host:port/dbname
    $url = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: '';
    if ($url !== '') {
        $p = parse_url($url);
        if (is_array($p) && !empty($p['host'])) {
            return [
                'host'    => $p['host'],
                'port'    => (string) ($p['port'] ?? '3306'),
                'name'    => ltrim($p['path'] ?? '', '/') ?: 'railway',
                'user'    => $p['user'] ?? 'root',
                'pass'    => $p['pass'] ?? '',
                'charset' => 'utf8mb4',
            ];
        }
    }

    // 2) Individual variables (Railway MYSQL* or generic DB_*)
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST');
    if ($host) {
        return [
            'host'    => $host,
            'port'    => getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306',
            'name'    => getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway',
            'user'    => getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root',
            'pass'    => getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ];
    }

    // 3) Local sandbox development fallback
    return [
        'host'    => '127.0.0.1',
        'port'    => '3306',
        'name'    => 'saathi_site',
        'user'    => 'saathi',
        'pass'    => 'saathi_dev_pw',
        'charset' => 'utf8mb4',
    ];
}
endif;

return ['db' => sathi_db_config()];
