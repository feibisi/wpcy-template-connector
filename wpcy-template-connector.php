<?php
/**
 * Plugin Name: WPCY Template Connector
 * Plugin URI: https://wpcy.net
 * Description: Connects Elementor template library to templates.wpcy.net — replaces my.elementor.com API calls with local template repository.
 * Version: 1.0.3
 * Author: WPCY
 * Requires Plugins: elementor
 * Text Domain: wpcy-template-connector
 * Update URI: https://updates.wenpai.net
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPCY_TEMPLATE_CONNECTOR_VERSION', '1.0.3');

if (is_admin()) {
    require_once __DIR__ . '/includes/class-wenpai-updater.php';
    new WenPai_Updater(plugin_basename(__FILE__), WPCY_TEMPLATE_CONNECTOR_VERSION);
}

class WPCY_Template_Connector {

    private string $api_base = 'https://templates.wpcy.net';

    private int $fetch_timeout = 25;

    public function __construct() {
        add_filter('pre_http_request', [$this, 'intercept_http'], 10, 3);
        add_filter('elementor/connect/additional-connect-info', '__return_empty_array', 999);
        add_filter('get_user_option_elementor_connect_common_data', [$this, 'fake_connect_state']);
        add_action('admin_menu', [$this, 'register_settings_page'], 99);
        add_action('admin_post_wpcy_template_connector_clear_cache', [$this, 'clear_elementor_library_cache']);
        add_action('admin_notices', [$this, 'status_notice']);
    }

    /**
     * Fake Elementor Connect state so Library::get_template_content()
     * passes the is_connected() check and actually makes the HTTP request.
     *
     * Without a fake access_token, get_template_content() returns WP_Error(401)
     * immediately and our pre_http_request filter never fires. The connect data
     * is stored in user option 'elementor_connect_common_data' (Common_App).
     */
    public function fake_connect_state($value) {
        if (empty($value) || !is_array($value) || empty($value['access_token'])) {
            return [
                'access_token' => 'wpcy_mock_token',
                'access_token_secret' => 'wpcy_mock_secret',
                'client_id' => 'wpcy',
            ];
        }
        return $value;
    }

    /**
     * Intercept all Elementor HTTP requests to my.elementor.com template APIs.
     */
    public function intercept_http($pre, $args, $url) {
        if (false !== $pre) {
            return $pre;
        }

        // Only intercept Elementor-related domains
        if (!$this->is_elementor_url($url)) {
            return $pre;
        }

        try {
            // Source_Remote template list
            if (false !== strpos($url, 'my.elementor.com/api/connect/v1/library/templates')) {
                return $this->handle_template_list();
            }

            // Library::get_template_content
            if (false !== strpos($url, 'my.elementor.com/api/connect/v1/library/get_template_content')) {
                return $this->handle_template_content($args);
            }

            // Api::get_library_data — old info API
            if (false !== strpos($url, 'my.elementor.com/api/v1/templates/info')) {
                return $this->handle_library_info();
            }

            // Cloud Library resources list (must NOT match resources/{id})
            if (false !== strpos($url, 'cloud-library.prod.builder.elementor.red/api/v1/cloud-library/resources')
                && false === strpos($url, 'resources/')) {
                return $this->handle_cloud_resources($url);
            }

            // Cloud Library single resource
            if (false !== strpos($url, 'cloud-library.prod.builder.elementor.red/api/v1/cloud-library/resources/')) {
                return $this->handle_cloud_resource($url);
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WPCY Connector] Error intercepting ' . $url . ': ' . $e->getMessage());
            }
        }

        return $pre;
    }

    private function is_elementor_url(string $url): bool {
        return false !== strpos($url, 'my.elementor.com')
            || false !== strpos($url, 'cloud-library.prod.builder.elementor.red');
    }

    // ─── Template List (Source_Remote) ───────────────────────────

    private function handle_template_list(): array {
        $data = $this->fetch_native_json('/api/connect/v1/library/templates');

        if (is_array($data) && $this->is_list_array($data)) {
            return $this->json_response($data);
        }

        $templates_raw = $this->fetch_all_templates();

        if (empty($templates_raw)) {
            return $this->json_response([]);
        }

        return $this->json_response(array_map([$this, 'to_remote_format'], $templates_raw));
    }

    // ─── Template Content (Library app) ──────────────────────────

    private function handle_template_content(array $args): array {
        $template_id = $this->extract_body_param($args, 'id');

        if (!$template_id) {
            return $this->error_response('Template ID missing');
        }

        $response = wp_remote_post(
            "{$this->api_base}/api/connect/v1/library/get_template_content",
            [
                'timeout' => $this->fetch_timeout,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['id' => $template_id]),
            ]
        );

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data) && isset($data['content']) && is_array($data['content'])) {
                return $this->json_response($data);
            }
        }

        return $this->fetch_legacy_template_content($template_id);
    }

    // ─── Old Info API (Api::get_library_data) ────────────────────

    private function handle_library_info(): array {
        $data = $this->fetch_native_json('/api/v1/templates/info/');

        if (is_array($data) && isset($data['types_data']['block']['categories'])) {
            return $this->json_response($data);
        }

        $templates_raw = $this->fetch_all_templates();
        $types_data = $this->build_types_data($templates_raw);

        return $this->json_response([
            'templates'  => array_map([$this, 'to_remote_format'], $templates_raw),
            'categories' => $this->flatten_categories($types_data),
            'types_data' => $types_data,
            'config'     => $types_data,
        ]);
    }

    // ─── Cloud Library Resources ──────────────────────────────────

    private function handle_cloud_resources(string $url): array {
        $query = $this->parse_query_from_url($url);

        $page     = max(1, (int) ($query['offset'] ?? 1));
        $per_page = min(100, (int) ($query['limit'] ?? 50));
        $search   = $query['search'] ?? '';
        $type     = $query['templateType'] ?? '';

        $api_url = "{$this->api_base}/api/templates?page={$page}&per_page={$per_page}";
        if ($search) {
            $api_url .= '&search=' . urlencode($search);
        }
        if ($type) {
            $api_url .= '&type=' . urlencode($type);
        }

        $response = wp_remote_get($api_url, ['timeout' => $this->fetch_timeout]);

        if (is_wp_error($response)) {
            return $this->json_response(['data' => [], 'total' => 0]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['data'])) {
            return $this->json_response(['data' => [], 'total' => 0]);
        }

        $resources = array_map([$this, 'to_cloud_format'], $body['data']);

        return $this->json_response([
            'data'  => $resources,
            'total' => (int) ($body['total'] ?? count($resources)),
        ]);
    }

    private function handle_cloud_resource(string $url): array {
        // Extract resource ID from URL path
        preg_match('#/resources/([a-zA-Z0-9_-]+)#', $url, $matches);
        $template_id = $matches[1] ?? null;

        if (!$template_id) {
            return $this->error_response('Resource ID missing');
        }

        $response = wp_remote_get(
            "{$this->api_base}/api/template/{$template_id}",
            ['timeout' => $this->fetch_timeout]
        );

        if (is_wp_error($response)) {
            return $this->error_response('Resource fetch failed');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['success'])) {
            return $this->error_response('Resource not found');
        }

        $resource = [
            'id'               => $template_id,
            'title'            => html_entity_decode($data['data']['title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'templateType'     => $data['data']['type'] ?? 'page',
            'type'             => $data['data']['subtype'] ?? 'template',
            'content'          => $data['data']['content'] ?? [],
            'status'           => 'published',
            'hasPageSettings'  => !empty($data['data']['page_settings']),
            'parentId'         => null,
            'createdAt'        => '2025-01-01T00:00:00+00:00',
            'authorEmail'      => 'library@wpcy.net',
        ];

        if (!empty($data['data']['page_settings'])) {
            $resource['page_settings'] = $data['data']['page_settings'];
        }

        return $this->json_response($resource);
    }


    // ─── Admin Settings Page ─────────────────────────────────────

    public function register_settings_page(): void {
        $parent_slug = class_exists('\\Elementor\\Modules\\EditorOne\\Classes\\Menu_Config')
            ? \Elementor\Modules\EditorOne\Classes\Menu_Config::ELEMENTOR_HOME_MENU_SLUG
            : 'elementor';

        add_submenu_page(
            $parent_slug,
            __('WPCY Template Connector', 'wpcy-template-connector'),
            __('Connector', 'wpcy-template-connector'),
            'manage_options',
            'wpcy-template-connector',
            [$this, 'render_settings_page'],
            90
        );
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wpcy-template-connector'));
        }

        $run_diagnostics = isset($_POST['wpcy_template_connector_diagnostics'])
            && check_admin_referer('wpcy_template_connector_diagnostics');
        $diagnostics = $run_diagnostics ? $this->run_diagnostics() : [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WPCY Template Connector', 'wpcy-template-connector'); ?></h1>
            <p><?php esc_html_e('Elementor template library requests are routed to templates.wpcy.net.', 'wpcy-template-connector'); ?></p>
            <?php if (isset($_GET['wpcy_cache_cleared'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Elementor library cache cleared.', 'wpcy-template-connector'); ?></p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width: 960px; margin-bottom: 18px;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Plugin version', 'wpcy-template-connector'); ?></th>
                        <td><?php echo esc_html(WPCY_TEMPLATE_CONNECTOR_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API base', 'wpcy-template-connector'); ?></th>
                        <td><code><?php echo esc_html($this->api_base); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('HTTP interception', 'wpcy-template-connector'); ?></th>
                        <td><?php esc_html_e('Enabled for my.elementor.com and Elementor Cloud Library endpoints.', 'wpcy-template-connector'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Elementor Connect mock', 'wpcy-template-connector'); ?></th>
                        <td><?php esc_html_e('Enabled for template import requests.', 'wpcy-template-connector'); ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" style="display:inline-block; margin-right:8px;">
                <?php wp_nonce_field('wpcy_template_connector_diagnostics'); ?>
                <button class="button button-primary" type="submit" name="wpcy_template_connector_diagnostics" value="1">
                    <?php esc_html_e('Run diagnostics', 'wpcy-template-connector'); ?>
                </button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field('wpcy_template_connector_clear_cache'); ?>
                <input type="hidden" name="action" value="wpcy_template_connector_clear_cache">
                <button class="button" type="submit"><?php esc_html_e('Clear Elementor library cache', 'wpcy-template-connector'); ?></button>
            </form>

            <?php if ($run_diagnostics): ?>
                <h2><?php esc_html_e('Diagnostics', 'wpcy-template-connector'); ?></h2>
                <table class="widefat striped" style="max-width: 960px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Check', 'wpcy-template-connector'); ?></th>
                            <th><?php esc_html_e('Status', 'wpcy-template-connector'); ?></th>
                            <th><?php esc_html_e('Details', 'wpcy-template-connector'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diagnostics as $row): ?>
                            <tr>
                                <td><code><?php echo esc_html($row['label']); ?></code></td>
                                <td>
                                    <?php if ($row['ok']): ?>
                                        <span style="color:#008a20;font-weight:600;"><?php esc_html_e('OK', 'wpcy-template-connector'); ?></span>
                                    <?php else: ?>
                                        <span style="color:#b32d2e;font-weight:600;"><?php esc_html_e('Error', 'wpcy-template-connector'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($row['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function clear_elementor_library_cache(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to clear this cache.', 'wpcy-template-connector'));
        }

        check_admin_referer('wpcy_template_connector_clear_cache');
        delete_transient('elementor_remote_templates_data_' . (defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : ''));
        wp_safe_redirect(add_query_arg('wpcy_cache_cleared', '1', admin_url('admin.php?page=wpcy-template-connector')));
        exit;
    }

    private function run_diagnostics(): array {
        $rows = [];
        $health = $this->diagnostic_get('/health');
        $health_data = $health['data'];
        $rows[] = [
            'label' => 'GET /health',
            'ok' => $health['ok'] && isset($health_data['templates']),
            'details' => $health['ok']
                ? sprintf(
                    'visible=%s raw=%s invalid=%s',
                    (string) ($health_data['templates'] ?? 'n/a'),
                    (string) ($health_data['raw_templates'] ?? 'n/a'),
                    (string) ($health_data['invalid_templates'] ?? 'n/a')
                )
                : $health['error'],
        ];

        $templates = $this->diagnostic_get('/api/connect/v1/library/templates');
        $rows[] = [
            'label' => 'GET /api/connect/v1/library/templates',
            'ok' => $templates['ok'] && is_array($templates['data']) && $this->is_list_array($templates['data']),
            'details' => $templates['ok']
                ? sprintf('count=%d first_id=%s', count($templates['data']), (string) ($templates['data'][0]['id'] ?? 'n/a'))
                : $templates['error'],
        ];

        $info = $this->diagnostic_get('/api/v1/templates/info/');
        $info_data = $info['data'];
        $types_data = is_array($info_data) ? ($info_data['types_data'] ?? []) : [];
        $rows[] = [
            'label' => 'GET /api/v1/templates/info/',
            'ok' => $info['ok'] && isset($types_data['block']['categories']) && is_array($types_data['block']['categories']),
            'details' => $info['ok']
                ? sprintf(
                    'templates=%d block=%d popup=%d lp=%d lb=%d',
                    count($info_data['templates'] ?? []),
                    count($types_data['block']['categories'] ?? []),
                    count($types_data['popup']['categories'] ?? []),
                    count($types_data['lp']['categories'] ?? []),
                    count($types_data['lb']['categories'] ?? [])
                )
                : $info['error'],
        ];

        $content = $this->diagnostic_post('/api/connect/v1/library/get_template_content', ['id' => '11034']);
        $content_data = $content['data'];
        $rows[] = [
            'label' => 'POST /api/connect/v1/library/get_template_content',
            'ok' => $content['ok'] && isset($content_data['content']) && is_array($content_data['content']),
            'details' => $content['ok']
                ? sprintf('id=11034 content=%d first_el=%s', count($content_data['content'] ?? []), (string) ($content_data['content'][0]['elType'] ?? 'n/a'))
                : $content['error'],
        ];

        return $rows;
    }

    private function diagnostic_get(string $path): array {
        $response = wp_remote_get($this->api_base . $path, ['timeout' => $this->fetch_timeout]);
        return $this->format_diagnostic_response($response);
    }

    private function diagnostic_post(string $path, array $body): array {
        $response = wp_remote_post(
            $this->api_base . $path,
            [
                'timeout' => $this->fetch_timeout,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($body),
            ]
        );
        return $this->format_diagnostic_response($response);
    }

    private function format_diagnostic_response($response): array {
        if (is_wp_error($response)) {
            return ['ok' => false, 'data' => null, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code) {
            return ['ok' => false, 'data' => $data, 'error' => 'HTTP ' . $code];
        }

        if (null === $data && JSON_ERROR_NONE !== json_last_error()) {
            return ['ok' => false, 'data' => null, 'error' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        return ['ok' => true, 'data' => $data, 'error' => ''];
    }

    // ─── Format Transformers ──────────────────────────────────────

    /**
     * Templates.wpcy.net → Source_Remote::prepare_template() format.
     */
    private function to_remote_format(array $t): array {
        return [
            'id'                => $t['id'],
            'type'              => $t['type'] ?? 'page',
            'subtype'           => $t['subtype'] ?? 'page',
            'title'             => html_entity_decode($t['title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'thumbnail'         => $t['thumbnail'] ?? '',
            'tmpl_created'      => '2025-01-01',
            'author'            => 'WPCY',
            'tags'              => json_encode($t['types'] ?? []),
            'is_pro'            => '0',
            'access_level'      => 0,
            'popularity_index'  => (int) (10000 - ($t['widgets'] ?? 0)),
            'trend_index'       => (int) ($t['widgets'] ?? 0),
            'has_page_settings' => '0',
            'url'               => $t['thumbnail'] ?? '',
        ];
    }

    /**
     * Templates.wpcy.net → Cloud_Library resource format.
     */
    private function to_cloud_format(array $t): array {
        return [
            'id'            => $t['id'],
            'title'         => html_entity_decode($t['title'] ?? '', ENT_QUOTES, 'UTF-8'),
            'templateType'  => $t['type'] ?? 'page',
            'type'          => $t['subtype'] ?? 'template',
            'status'        => 'published',
            'authorEmail'   => 'library@wpcy.net',
            'createdAt'     => '2025-01-01T00:00:00+00:00',
            'hasPageSettings' => false,
            'parentId'      => null,
            'previewUrl'    => $t['thumbnail'] ?? '',
            'thumbnail'     => $t['thumbnail'] ?? '',
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────


    private function fetch_native_json(string $path) {
        $response = wp_remote_get(
            $this->api_base . $path,
            ['timeout' => $this->fetch_timeout]
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    private function fetch_legacy_template_content(string $template_id): array {
        $response = wp_remote_get(
            "{$this->api_base}/api/template/{$template_id}",
            ['timeout' => $this->fetch_timeout]
        );

        if (is_wp_error($response)) {
            return $this->error_response('Template fetch failed');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['success']) || empty($data['data']['content'])) {
            return $this->error_response('Template not found');
        }

        $result = ['content' => $data['data']['content']];
        if (!empty($data['data']['page_settings'])) {
            $result['page_settings'] = $data['data']['page_settings'];
        }

        return $this->json_response($result);
    }

    private function fetch_all_templates(): array {
        $response = wp_remote_get(
            "{$this->api_base}/api/templates?page=1&per_page=2000",
            ['timeout' => $this->fetch_timeout]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data['data'] ?? [];
    }

    private function build_types_data(array $templates): array {
        $types_data = [
            'block' => [
                'categories' => [],
            ],
            'popup' => [
                'categories' => [],
            ],
            'lp' => [
                'categories' => [],
            ],
            'lb' => [
                'categories' => [],
            ],
        ];

        foreach ($templates as $t) {
            $type = (string) ($t['type'] ?? 'page');
            $subtype = trim((string) ($t['subtype'] ?? ''));

            if ('page' === $type) {
                continue;
            }

            if (!isset($types_data[$type])) {
                $types_data[$type] = [
                    'categories' => [],
                ];
            }

            if ('' === $subtype) {
                $subtype = $type;
            }

            if (!in_array($subtype, $types_data[$type]['categories'], true)) {
                $types_data[$type]['categories'][] = $subtype;
            }
        }

        foreach ($types_data as &$type_data) {
            natcasesort($type_data['categories']);
            $type_data['categories'] = array_values($type_data['categories']);
        }
        unset($type_data);

        return $types_data;
    }

    private function flatten_categories(array $types_data): array {
        $categories = [];

        foreach ($types_data as $type => $type_data) {
            foreach ($type_data['categories'] ?? [] as $category) {
                $categories[] = [
                    'name' => ucfirst((string) $category),
                    'category' => (string) $category,
                    'type' => (string) $type,
                ];
            }
        }

        return $categories;
    }

    private function is_list_array(array $array): bool {
        return [] === $array || array_keys($array) === range(0, count($array) - 1);
    }

    private function extract_body_param(array $args, string $key): ?string {
        $body = $args['body'] ?? [];

        if (is_array($body) && isset($body[$key])) {
            return (string) $body[$key];
        }

        if (is_string($body)) {
            parse_str($body, $parsed);
            return isset($parsed[$key]) ? (string) $parsed[$key] : null;
        }

        return null;
    }

    private function parse_query_from_url(string $url): array {
        $query = [];
        $qpos = strpos($url, '?');
        if ($qpos !== false) {
            parse_str(substr($url, $qpos + 1), $query);
        }
        return $query;
    }

    private function json_response($data): array {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => json_encode($data),
        ];
    }

    private function error_response(string $message): array {
        return $this->json_response(['error' => $message]);
    }

    /**
     * Admin notice confirming the connector is active.
     */
    public function status_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html__('WPCY Template Connector: Elementor template library routed to templates.wpcy.net.', 'wpcy-template-connector')
        );
    }
}

new WPCY_Template_Connector();
