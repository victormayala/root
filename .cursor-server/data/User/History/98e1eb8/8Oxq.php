<?php

if (!defined('ABSPATH')) {
    exit;
}

class Printonet_Sync_Store
{
    private const SCHEMA_VERSION = '1.0.0';

    public static function init(): void
    {
        $installed = (string) get_site_option('printonet_sync_store_schema_version', '');
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        self::ensure_tables();
        update_site_option('printonet_sync_store_schema_version', self::SCHEMA_VERSION);
    }

    public static function ensure_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id BIGINT UNSIGNED NOT NULL,
            supplier VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            payload LONGTEXT NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY blog_supplier_status (blog_id, supplier, status)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function create_job(int $blog_id, string $supplier, array $payload): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->insert(
            self::table_name(),
            [
                'blog_id' => $blog_id,
                'supplier' => $supplier,
                'status' => 'queued',
                'attempts' => 0,
                'payload' => wp_json_encode($payload),
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function get_job(int $job_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $job_id),
            ARRAY_A
        );

        return $row ?: null;
    }

    public static function mark_processing(int $job_id): void
    {
        self::update_job($job_id, [
            'status' => 'processing',
            'attempts' => self::get_attempts($job_id) + 1,
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function mark_success(int $job_id): void
    {
        self::update_job($job_id, [
            'status' => 'success',
            'last_error' => null,
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function mark_failed(int $job_id, string $message): void
    {
        self::update_job($job_id, [
            'status' => 'failed',
            'last_error' => wp_strip_all_tags($message),
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function mark_queued(int $job_id, string $message): void
    {
        self::update_job($job_id, [
            'status' => 'queued',
            'last_error' => wp_strip_all_tags($message),
            'updated_at' => current_time('mysql'),
        ]);
    }

    public static function get_attempts(int $job_id): int
    {
        $job = self::get_job($job_id);
        return isset($job['attempts']) ? (int) $job['attempts'] : 0;
    }

    private static function update_job(int $job_id, array $data): void
    {
        global $wpdb;

        $formats = [];
        foreach ($data as $value) {
            $formats[] = is_int($value) ? '%d' : '%s';
        }

        $wpdb->update(
            self::table_name(),
            $data,
            ['id' => $job_id],
            $formats,
            ['%d']
        );
    }

    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->base_prefix . 'printonet_sync_jobs';
    }
}
