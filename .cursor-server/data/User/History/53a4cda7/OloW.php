<?php

if (!defined('ABSPATH')) {
    exit;
}

interface Printonet_Supplier_Adapter_Interface
{
    public function key(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch_catalog(array $credentials): array;

    public function validate_credentials(array $credentials): bool;
}

class Printonet_Dummy_Supplier_Adapter implements Printonet_Supplier_Adapter_Interface
{
    public function key(): string
    {
        return 'dummy';
    }

    public function fetch_catalog(array $credentials): array
    {
        unset($credentials);

        return [
            [
                'sku' => 'TSHIRT-001',
                'name' => 'Soft Cotton T-Shirt',
                'price' => 19.99,
                'stock' => 999,
            ],
        ];
    }

    public function validate_credentials(array $credentials): bool
    {
        return !empty($credentials['api_key']);
    }
}

class Printonet_Internal_Shop_Adapter implements Printonet_Supplier_Adapter_Interface
{
    private const DEFAULT_LIMIT = 200;

    public function key(): string
    {
        return 'printonet_internal';
    }

    public function fetch_catalog(array $credentials): array
    {
        if (!$this->validate_credentials($credentials)) {
            return [];
        }

        $defaults = $this->default_credentials();
        $base_url = untrailingslashit((string) ($credentials['base_url'] ?? $defaults['base_url']));
        $tenant_slug = $this->resolve_tenant_slug();
        if (!empty($credentials['tenant_slug'])) {
            $tenant_slug = sanitize_text_field((string) $credentials['tenant_slug']);
        }

        // Backward + forward compatible tenant identity fields for the internal feed.
        // Older handlers may use tenant_slug; updated handlers can match wp_site_url.
        $wp_site_url = $this->resolve_wp_site_url();
        if (!empty($credentials['wp_site_url'])) {
            $wp_site_url = esc_url_raw((string) $credentials['wp_site_url']);
        }

        $limit = isset($credentials['limit']) ? (int) $credentials['limit'] : self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $timestamp = (string) time();
        $signature = $this->sign_get_request($timestamp);
        $query = [
            'tenant_slug' => $tenant_slug,
            'wp_site_url' => $wp_site_url,
            'limit' => $limit,
        ];
        $url = add_query_arg($query, $base_url);

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'X-Printonet-Timestamp' => $timestamp,
                'X-Printonet-Signature' => $signature,
                'Content-Type' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            throw new RuntimeException('Invalid internal supplier response: missing items array');
        }

        $items = $decoded['items'];
        $rows = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid internal supplier item at index ' . $index);
            }

            $sku = (string) ($item['sku'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $price = $item['price'] ?? null;
            $stock = $item['stock'] ?? null;
            $description = isset($item['description']) ? (string) $item['description'] : '';
            $image = isset($item['image']) ? esc_url_raw((string) $item['image']) : '';

            if ($sku === '' || $name === '') {
                throw new RuntimeException('Invalid internal supplier item: sku and name required');
            }
            if (!is_numeric($price) || !is_numeric($stock)) {
                throw new RuntimeException('Invalid internal supplier item: numeric price and stock required');
            }

            $rows[] = [
                'sku' => $sku,
                'name' => $name,
                'description' => sanitize_textarea_field($description),
                'image' => $image,
                'price' => (float) $price,
                'stock' => (int) $stock,
            ];
        }

        return $rows;
    }

    public function validate_credentials(array $credentials): bool
    {
        $defaults = $this->default_credentials();
        $base_url = (string) ($credentials['base_url'] ?? $defaults['base_url']);
        $hmac_secret = (string) ($credentials['platform_hmac_secret'] ?? $defaults['platform_hmac_secret']);

        return $base_url !== '' && $hmac_secret !== '';
    }

    private function default_credentials(): array
    {
        return [
            'base_url' => defined('PRINTONET_INTERNAL_SUPPLIER_API_URL') ? (string) PRINTONET_INTERNAL_SUPPLIER_API_URL : '',
            'platform_hmac_secret' => defined('PRINTONET_PLATFORM_HMAC_SECRET') ? (string) PRINTONET_PLATFORM_HMAC_SECRET : '',
        ];
    }

    private function sign_get_request(string $timestamp): string
    {
        $secret = defined('PRINTONET_PLATFORM_HMAC_SECRET') ? (string) PRINTONET_PLATFORM_HMAC_SECRET : '';
        return hash_hmac('sha256', $timestamp . '.', $secret);
    }

    private function resolve_tenant_slug(): string
    {
        if (defined('PRINTONET_INTERNAL_TENANT_SLUG') && PRINTONET_INTERNAL_TENANT_SLUG !== '') {
            return (string) PRINTONET_INTERNAL_TENANT_SLUG;
        }

        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        return $host;
    }

    private function resolve_wp_site_url(): string
    {
        $url = trailingslashit(home_url('/'));
        return esc_url_raw($url);
    }
}

class Printonet_Printful_Adapter implements Printonet_Supplier_Adapter_Interface
{
    private const API_BASE = 'https://api.printful.com';

    public function key(): string
    {
        return 'printful';
    }

    public function fetch_catalog(array $credentials): array
    {
        if (!$this->validate_credentials($credentials)) {
            return [];
        }

        $response = wp_remote_get(self::API_BASE . '/store/products', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $credentials['api_key'],
                'Content-Type' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['result']) || !is_array($decoded['result'])) {
            return [];
        }

        $rows = [];
        foreach ($decoded['result'] as $item) {
            $variants = isset($item['sync_variants']) && is_array($item['sync_variants']) ? $item['sync_variants'] : [];
            foreach ($variants as $variant) {
                if (empty($variant['sku']) || empty($variant['name'])) {
                    continue;
                }

                $rows[] = [
                    'sku' => (string) $variant['sku'],
                    'name' => (string) $variant['name'],
                    'price' => isset($variant['retail_price']) ? (float) $variant['retail_price'] : 0.0,
                    'stock' => 999, // Printful typically handles fulfillment externally.
                ];
            }
        }

        return $rows;
    }

    public function validate_credentials(array $credentials): bool
    {
        return !empty($credentials['api_key']);
    }
}

class Printonet_Printify_Adapter implements Printonet_Supplier_Adapter_Interface
{
    private const API_BASE = 'https://api.printify.com/v1';

    public function key(): string
    {
        return 'printify';
    }

    public function fetch_catalog(array $credentials): array
    {
        if (!$this->validate_credentials($credentials)) {
            return [];
        }

        if (empty($credentials['shop_id'])) {
            return [];
        }

        $response = wp_remote_get(self::API_BASE . '/shops/' . rawurlencode((string) $credentials['shop_id']) . '/products.json', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $credentials['api_key'],
                'Content-Type' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($decoded['data'] as $item) {
            $title = (string) ($item['title'] ?? 'Printify Item');
            $variants = is_array($item['variants'] ?? null) ? $item['variants'] : [];
            foreach ($variants as $variant) {
                $sku = (string) ($variant['sku'] ?? '');
                if ($sku === '') {
                    continue;
                }

                $variant_title = (string) ($variant['title'] ?? $title);
                $rows[] = [
                    'sku' => $sku,
                    'name' => $variant_title,
                    'price' => isset($variant['price']) ? ((float) $variant['price']) / 100 : 0.0,
                    'stock' => isset($variant['is_enabled']) && $variant['is_enabled'] ? 999 : 0,
                ];
            }
        }

        return $rows;
    }

    public function validate_credentials(array $credentials): bool
    {
        return !empty($credentials['api_key']);
    }
}

class Printonet_Supplier_Sync
{
    private const ACTION_SYNC = 'printonet_supplier_sync_action';
    private const MAX_ATTEMPTS = 3;

    /** @var array<string, Printonet_Supplier_Adapter_Interface> */
    private static array $adapters = [];

    public static function init(): void
    {
        self::register_adapter(new Printonet_Internal_Shop_Adapter());
        self::register_adapter(new Printonet_Dummy_Supplier_Adapter());
        self::register_adapter(new Printonet_Printful_Adapter());
        self::register_adapter(new Printonet_Printify_Adapter());

        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action(self::ACTION_SYNC, [self::class, 'run_sync'], 10, 1);
    }

    public static function register_routes(): void
    {
        register_rest_route('printonet/v1', '/suppliers/sync', [
            'methods' => 'POST',
            'callback' => [self::class, 'queue_sync'],
            'permission_callback' => [Printonet_Provisioning_API::class, 'authenticate'],
        ]);

        register_rest_route('printonet/v1', '/suppliers/validate', [
            'methods' => 'POST',
            'callback' => [self::class, 'validate_supplier'],
            'permission_callback' => [Printonet_Provisioning_API::class, 'authenticate'],
        ]);

        register_rest_route('printonet/v1', '/suppliers/sync-status/(?P<job_id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'sync_status'],
            'permission_callback' => [Printonet_Provisioning_API::class, 'authenticate'],
        ]);

        register_rest_route('printonet/v1', '/suppliers/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_webhook'],
            'permission_callback' => [self::class, 'authenticate_webhook'],
        ]);
    }

    public static function register_adapter(Printonet_Supplier_Adapter_Interface $adapter): void
    {
        self::$adapters[$adapter->key()] = $adapter;
    }

    public static function queue_sync(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = [];
        }

        $supplier = sanitize_key((string) ($payload['supplier'] ?? 'printonet_internal'));

        if ($supplier === '' || !isset(self::$adapters[$supplier])) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Unknown supplier adapter',
            ], 422);
        }

        $credentials = is_array($payload['credentials'] ?? null) ? $payload['credentials'] : [];

        $tenant_slug = isset($payload['tenant_slug']) ? sanitize_title((string) $payload['tenant_slug']) : '';
        if ($tenant_slug !== '') {
            $bad = self::assert_tenant_slug_matches_request_host($tenant_slug);
            if ($bad instanceof WP_REST_Response) {
                return $bad;
            }

            $target_blog_id = Printonet_Provisioning_API::resolve_tenant_blog_id($tenant_slug);
            if ($target_blog_id < 2) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Tenant not found',
                ], 404);
            }
            $blog_id = $target_blog_id;
        } else {
            $blog_id = get_current_blog_id();
        }

        $job_id = Printonet_Sync_Store::create_job($blog_id, $supplier, [
            'supplier' => $supplier,
            'credentials' => $credentials,
            'tenant_slug' => $tenant_slug,
        ]);
        wp_schedule_single_event(time() + 2, self::ACTION_SYNC, [$job_id]);
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }

        // Make new-tenant product visibility deterministic: run internal sync immediately.
        // Cron remains as fallback for retries and other suppliers.
        if ($supplier === 'printonet_internal') {
            self::run_sync((int) $job_id);
        }

        $job = Printonet_Sync_Store::get_job($job_id);
        $status = is_array($job) ? (string) ($job['status'] ?? 'queued') : 'queued';

        return new WP_REST_Response([
            'success' => true,
            'queued' => true,
            'job_id' => $job_id,
            'supplier' => $supplier,
            'blog_id' => $blog_id,
            'tenant_slug' => $tenant_slug,
            'status' => $status,
        ], 202);
    }

    public static function run_sync(int $job_id): void
    {
        $job = Printonet_Sync_Store::get_job($job_id);
        if (!$job) {
            return;
        }

        $blog_id = (int) $job['blog_id'];
        $payload = json_decode((string) $job['payload'], true);
        $payload = is_array($payload) ? $payload : [];
        $supplier = sanitize_key((string) ($payload['supplier'] ?? ''));
        $credentials = is_array($payload['credentials'] ?? null) ? $payload['credentials'] : [];
        $tenant_slug = isset($payload['tenant_slug']) ? sanitize_title((string) $payload['tenant_slug']) : '';
        if ($tenant_slug !== '') {
            // Ensure adapter requests use canonical slug for internal feed routing.
            $credentials['tenant_slug'] = $tenant_slug;
        }

        if (!isset(self::$adapters[$supplier])) {
            Printonet_Sync_Store::mark_failed($job_id, 'Supplier adapter not found');
            return;
        }

        Printonet_Sync_Store::mark_processing($job_id);
        $attempts = Printonet_Sync_Store::get_attempts($job_id);
        $adapter = self::$adapters[$supplier];

        if (!$adapter->validate_credentials($credentials)) {
            Printonet_Sync_Store::mark_failed($job_id, 'Invalid supplier credentials');
            return;
        }

        $switched_blog = false;

        try {
            switch_to_blog($blog_id);
            $switched_blog = true;
            $products = $adapter->fetch_catalog($credentials);
            foreach ($products as $product_data) {
                self::upsert_simple_product($product_data);
            }
            self::dedupe_products_by_sku();
            update_option('printonet_supplier_last_sync_' . $supplier, current_time('mysql'));
        } catch (Throwable $exception) {
            if ($attempts < self::MAX_ATTEMPTS) {
                Printonet_Sync_Store::mark_queued($job_id, $exception->getMessage());
                wp_schedule_single_event(time() + 30, self::ACTION_SYNC, [$job_id]);
                return;
            }

            Printonet_Sync_Store::mark_failed($job_id, $exception->getMessage());
            return;
        } finally {
            if ($switched_blog) {
                restore_current_blog();
            }
        }

        Printonet_Sync_Store::mark_success($job_id);
    }

    public static function validate_supplier(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $supplier = sanitize_key((string) ($payload['supplier'] ?? 'printonet_internal'));
        $credentials = is_array($payload['credentials'] ?? null) ? $payload['credentials'] : [];

        if ($supplier === '' || !isset(self::$adapters[$supplier])) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Unknown supplier adapter',
            ], 422);
        }

        $valid = self::$adapters[$supplier]->validate_credentials($credentials);

        return new WP_REST_Response([
            'success' => $valid,
            'supplier' => $supplier,
        ], $valid ? 200 : 422);
    }

    public static function sync_status(WP_REST_Request $request): WP_REST_Response
    {
        $job_id = (int) $request->get_param('job_id');
        $job = Printonet_Sync_Store::get_job($job_id);

        if (!$job) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'job' => [
                'id' => (int) $job['id'],
                'blog_id' => (int) $job['blog_id'],
                'supplier' => (string) $job['supplier'],
                'status' => (string) $job['status'],
                'attempts' => (int) $job['attempts'],
                'last_error' => (string) ($job['last_error'] ?? ''),
                'created_at' => (string) $job['created_at'],
                'updated_at' => (string) $job['updated_at'],
            ],
        ], 200);
    }

    public static function authenticate_webhook(WP_REST_Request $request): bool
    {
        $incoming = (string) $request->get_header('x-printonet-webhook-secret');
        $expected = defined('PRINTONET_SUPPLIER_WEBHOOK_SECRET')
            ? (string) PRINTONET_SUPPLIER_WEBHOOK_SECRET
            : '';

        if ($incoming === '' || $expected === '') {
            return false;
        }

        return hash_equals($expected, $incoming);
    }

    public static function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $event = sanitize_key((string) ($payload['event'] ?? ''));
        $blog_id = isset($payload['blog_id']) ? (int) $payload['blog_id'] : get_current_blog_id();
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        if ($event === '' || empty($items)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Invalid webhook payload',
            ], 422);
        }

        switch_to_blog($blog_id);
        foreach ($items as $item) {
            self::apply_webhook_update($item);
        }
        update_option('printonet_supplier_last_webhook', current_time('mysql'));
        restore_current_blog();

        return new WP_REST_Response([
            'success' => true,
            'event' => $event,
            'updated_items' => count($items),
        ], 200);
    }

    private static function apply_webhook_update(array $item): void
    {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return;
        }

        $sku = wc_clean((string) ($item['sku'] ?? ''));
        if ($sku === '') {
            return;
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return;
        }

        if (isset($item['price'])) {
            $price = wc_format_decimal((string) $item['price']);
            update_post_meta((int) $product_id, '_regular_price', $price);
            update_post_meta((int) $product_id, '_price', $price);
        }
        if (isset($item['stock'])) {
            $stock = wc_stock_amount((int) $item['stock']);
            update_post_meta((int) $product_id, '_manage_stock', 'yes');
            update_post_meta((int) $product_id, '_stock', $stock);
            update_post_meta((int) $product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
        }
    }


    /**
     * When the request Host is a tenant subdomain, return that slug; on the network root host return null.
     */
    private static function tenant_slug_from_http_host(): ?string
    {
        if (!is_multisite() || !defined('DOMAIN_CURRENT_SITE')) {
            return null;
        }

        $network = strtolower((string) DOMAIN_CURRENT_SITE);
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if ($host === '') {
            return null;
        }

        $host = (string) preg_replace('/:\d+$/', '', $host);
        if ($host === $network || $host === 'www.' . $network) {
            return null;
        }

        $suffix = '.' . $network;
        if (strlen($host) <= strlen($suffix) || substr($host, -strlen($suffix)) !== $suffix) {
            return null;
        }

        $sub = substr($host, 0, -strlen($suffix));
        if ($sub === '' || strpos($sub, '.') !== false) {
            return null;
        }

        return $sub;
    }

    /**
     * If the client calls a tenant URL but sends a different tenant_slug, reject cross-tenant writes.
     */
    private static function assert_tenant_slug_matches_request_host(string $requested_slug): ?WP_REST_Response
    {
        $host_slug = self::tenant_slug_from_http_host();
        if ($host_slug === null) {
            return null;
        }

        if (sanitize_title($requested_slug) !== $host_slug) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'tenant_slug must match the store hostname, or call the network base URL and pass tenant_slug.',
            ], 403);
        }

        return null;
    }

    private static function upsert_simple_product(array $data): void
    {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return;
        }

        if (empty($data['sku']) || empty($data['name'])) {
            return;
        }

        $sku = wc_clean((string) $data['sku']);
        $existing_id = self::find_existing_product_id_by_sku($sku);
        $title = sanitize_text_field((string) $data['name']);
        $content = !empty($data['description']) ? wp_kses_post((string) $data['description']) : '';

        $product_id = $existing_id ?: wp_insert_post([
            'post_title' => $title,
            'post_status' => 'publish',
            'post_type' => 'product',
            'post_content' => $content,
        ]);

        if (!$product_id || is_wp_error($product_id)) {
            return;
        }

        wp_update_post([
            'ID' => (int) $product_id,
            'post_title' => $title,
            'post_content' => $content,
        ]);

        wp_set_object_terms((int) $product_id, 'simple', 'product_type');
        update_post_meta((int) $product_id, '_sku', $sku);
        update_post_meta((int) $product_id, '_regular_price', wc_format_decimal((string) ($data['price'] ?? '0')));
        update_post_meta((int) $product_id, '_price', wc_format_decimal((string) ($data['price'] ?? '0')));
        update_post_meta((int) $product_id, '_manage_stock', 'yes');
        update_post_meta((int) $product_id, '_stock', wc_stock_amount((int) ($data['stock'] ?? 0)));
        update_post_meta((int) $product_id, '_stock_status', ((int) ($data['stock'] ?? 0)) > 0 ? 'instock' : 'outofstock');

        if (!empty($data['image'])) {
            self::sync_product_featured_image((int) $product_id, (string) $data['image']);
        }
    }

    private static function find_existing_product_id_by_sku(string $sku): int
    {
        if ($sku === '') {
            return 0;
        }

        if (function_exists('wc_get_product_id_by_sku')) {
            $id = (int) wc_get_product_id_by_sku($sku);
            if ($id > 0) {
                return $id;
            }
        }

        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.post_type = 'product' AND p.post_status != 'trash' ORDER BY p.ID DESC LIMIT 1",
            $sku
        ));

        return $post_id > 0 ? $post_id : 0;
    }

    /**
     * Keep one product per SKU to avoid paginated duplicate catalogs.
     */
    private static function dedupe_products_by_sku(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS sku, GROUP_CONCAT(pm.post_id ORDER BY pm.post_id DESC) AS ids, COUNT(*) AS total
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku' AND pm.meta_value <> ''
               AND p.post_type = 'product' AND p.post_status != 'trash'
             GROUP BY pm.meta_value
             HAVING COUNT(*) > 1"
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $row->ids))));
            if (count($ids) <= 1) {
                continue;
            }

            $keep = array_shift($ids);
            foreach ($ids as $dupe_id) {
                if ($dupe_id <= 0 || $dupe_id === $keep) {
                    continue;
                }
                wp_delete_post($dupe_id, true);
            }
        }
    }

    private static function sync_product_featured_image(int $product_id, string $image_url): void
    {
        $image_url = esc_url_raw($image_url);
        if ($image_url === '' || !wp_http_validate_url($image_url)) {
            return;
        }

        $current = (int) get_post_thumbnail_id($product_id);
        $current_src = $current > 0 ? wp_get_attachment_url($current) : '';
        if ($current > 0 && $current_src === $image_url) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $prev_user = get_current_user_id();
        $admin = get_users([
            'blog_id' => get_current_blog_id(),
            'role' => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ID',
        ]);
        if (!empty($admin[0])) {
            wp_set_current_user((int) $admin[0]);
        }

        try {
            $tmp = download_url($image_url);
            if (is_wp_error($tmp)) {
                return;
            }

            $path = wp_parse_url($image_url, PHP_URL_PATH);
            $basename = $path ? sanitize_file_name(basename((string) $path)) : 'product-image.jpg';
            if ($basename === '' || $basename === '.') {
                $basename = 'product-image.jpg';
            }

            $file_array = [
                'name' => $basename,
                'tmp_name' => $tmp,
            ];

            $attach_id = media_handle_sideload($file_array, $product_id, 'Supplier product image');
            if (is_wp_error($attach_id)) {
                @unlink($tmp);
                return;
            }

            set_post_thumbnail($product_id, (int) $attach_id);
        } finally {
            wp_set_current_user($prev_user);
        }
    }
}
