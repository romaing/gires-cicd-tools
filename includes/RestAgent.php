<?php

namespace GiresCICD;

class RestAgent {
    private $settings;
    private $migrations;
    private $replication;

    public function __construct(Settings $settings, Migrations $migrations, Replication $replication) {
        $this->settings = $settings;
        $this->migrations = $migrations;
        $this->replication = $replication;
    }

    public function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('gires-cicd/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/migrations', [
            'methods' => 'GET',
            'callback' => [$this, 'list_migrations'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/migrations/run', [
            'methods' => 'POST',
            'callback' => [$this, 'run_migrations'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/replication/export', [
            'methods' => 'POST',
            'callback' => [$this, 'export_replication'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/replication/download', [
            'methods' => 'GET',
            'callback' => [$this, 'download_replication'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/replication/import', [
            'methods' => 'POST',
            'callback' => [$this, 'import_replication'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/media/export', [
            'methods' => 'POST',
            'callback' => [$this, 'export_media'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/media/download', [
            'methods' => 'GET',
            'callback' => [$this, 'download_media'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/media/import', [
            'methods' => 'POST',
            'callback' => [$this, 'import_media'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/maintenance', [
            'methods' => 'POST',
            'callback' => [$this, 'set_maintenance'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/replication/swap', [
            'methods' => 'POST',
            'callback' => [$this, 'swap_replication'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('gires-cicd/v1', '/replication/cleanup', [
            'methods' => 'POST',
            'callback' => [$this, 'cleanup_replication'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);
    }

    public function status() {
        return rest_ensure_response([
            'status' => 'ok',
            'site_url' => site_url(),
            'migrations_path' => $this->settings->get('migrations_path'),
            'pending_count' => count($this->migrations->get_pending()),
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }

    public function list_migrations() {
        $files = $this->migrations->list_files();
        $applied = $this->migrations->get_applied();

        $items = [];
        foreach ($files as $file) {
            $name = basename($file);
            $items[] = [
                'name' => $name,
                'status' => in_array($name, $applied, true) ? 'applied' : 'pending',
            ];
        }

        return rest_ensure_response([
            'migrations' => $items,
        ]);
    }

    public function run_migrations() {
        $result = $this->migrations->apply_all();
        return rest_ensure_response([
            'applied' => $result['applied'],
            'errors' => $result['errors'],
        ]);
    }

    public function export_replication(\WP_REST_Request $request) {
        $set_name = sanitize_text_field($request->get_param('set_name'));
        $set = $this->replication->get_set_by_name($set_name);
        if (!$set) {
            return rest_ensure_response(['success' => false, 'message' => 'Set inconnu']);
        }

        $tables = $request->get_param('tables');
        if (is_array($tables)) {
            $set['tables'] = array_values(array_filter(array_map('sanitize_text_field', $tables)));
        }

        $override = [
            'search' => $request->get_param('search') ?? [],
            'replace' => $request->get_param('replace') ?? [],
            'exclude_option_prefix' => $request->get_param('exclude_option_prefix') ?? '',
            'search_only_tables' => $request->get_param('search_only_tables') ?? [],
        ];

        $sql = $this->replication->export_sql($set, $override);
        $token = bin2hex(random_bytes(16));
        $path = $this->store_dump($sql, $token);

        if (!$path) {
            return rest_ensure_response(['success' => false, 'message' => 'Impossible de sauvegarder le dump']);
        }

        return rest_ensure_response([
            'success' => true,
            'download_token' => $token,
            'size' => filesize($path),
        ]);
    }

    public function download_replication(\WP_REST_Request $request) {
        $token = sanitize_text_field($request->get_param('token'));
        $path = $this->get_dump_path($token);
        if (!$path || !is_file($path)) {
            return rest_ensure_response(['success' => false, 'message' => 'Dump introuvable']);
        }

        $sql = file_get_contents($path);
        return new \WP_REST_Response($sql, 200);
    }

    public function import_replication(\WP_REST_Request $request) {
        $set_name = sanitize_text_field($request->get_param('set_name'));
        $set = $this->replication->get_set_by_name($set_name);
        if (!$set) {
            return rest_ensure_response(['success' => false, 'message' => 'Set inconnu']);
        }

        $tables = $request->get_param('tables');
        if (is_array($tables)) {
            $set['tables'] = array_values(array_filter(array_map('sanitize_text_field', $tables)));
        }

        $encoding = sanitize_text_field($request->get_param('encoding') ?? '');
        $sql = $request->get_param('sql') ?? '';

        if ($encoding === 'base64') {
            $sql = base64_decode($sql);
        }

        if (empty($sql)) {
            return rest_ensure_response(['success' => false, 'message' => 'SQL manquant']);
        }

        $options = [
            'skip_rename' => !empty($request->get_param('skip_rename')),
            'temp_prefix' => $request->get_param('temp_prefix'),
        ];
        $result = $this->replication->import_sql($sql, $set, $options);
        return rest_ensure_response($result);
    }

    public function export_media(\WP_REST_Request $request) {
        $max_mb = (int) ($request->get_param('max_mb') ?? 512);
        $uploads_dir = Sync::uploads_dir();
        $dest_dir = $uploads_dir . '/gires-cicd';
        $result = Sync::create_media_archives($uploads_dir, $dest_dir, $max_mb);
        if (empty($result['success'])) {
            return rest_ensure_response($result);
        }

        $token = $result['token'];
        $archives = $result['archives'];
        set_transient('gires_cicd_media_' . $token, $archives, 30 * MINUTE_IN_SECONDS);

        return rest_ensure_response([
            'success' => true,
            'token' => $token,
            'parts' => count($archives),
        ]);
    }

    public function download_media(\WP_REST_Request $request) {
        $token = sanitize_text_field($request->get_param('token'));
        $part = (int) ($request->get_param('part') ?? 1);
        $archives = get_transient('gires_cicd_media_' . $token);
        if (!is_array($archives) || empty($archives[$part - 1])) {
            return rest_ensure_response(['success' => false, 'message' => 'Archive introuvable']);
        }

        $path = $archives[$part - 1];
        if (!is_file($path)) {
            return rest_ensure_response(['success' => false, 'message' => 'Archive manquante']);
        }

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        @unlink($path);
        if ($part >= count($archives)) {
            delete_transient('gires_cicd_media_' . $token);
        }
        exit;
    }

    public function import_media(\WP_REST_Request $request) {
        $token = sanitize_text_field($request->get_param('token'));
        $part = (int) ($request->get_param('part') ?? 1);
        $suffix = sanitize_text_field($request->get_param('suffix') ?? '');
        if (empty($suffix)) {
            $suffix = $token ?: 'remote';
        }
        $uploads_dir = Sync::uploads_dir();
        $dest_dir = $uploads_dir . '/gires-cicd';
        if (!Sync::ensure_dir($dest_dir)) {
            return rest_ensure_response(['success' => false, 'message' => 'Dossier gires-cicd non accessible']);
        }

        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return rest_ensure_response(['success' => false, 'message' => 'Zip manquant']);
        }

        $zip_path = $dest_dir . '/import_' . $suffix . '_part' . $part . '.zip';
        if (file_put_contents($zip_path, $raw) === false) {
            return rest_ensure_response(['success' => false, 'message' => 'Impossible d’écrire le zip']);
        }

        $tmp_upload = Sync::tmp_uploads_dir($suffix);
        $unzip = Sync::unzip_archive($zip_path, $tmp_upload);
        @unlink($zip_path);
        if (empty($unzip['success'])) {
            return rest_ensure_response($unzip);
        }

        return rest_ensure_response(['success' => true]);
    }

    public function set_maintenance(\WP_REST_Request $request) {
        $enabled = !empty($request->get_param('enabled'));
        Sync::set_maintenance($enabled);
        return rest_ensure_response(['success' => true]);
    }

    public function swap_replication(\WP_REST_Request $request) {
        $tables = $request->get_param('tables') ?? [];
        $temp_prefix = sanitize_text_field($request->get_param('temp_prefix') ?? 'tmp_');
        $backup_prefix = sanitize_text_field($request->get_param('backup_prefix') ?? 'bak_');
        $swap_uploads = !empty($request->get_param('swap_uploads'));
        $suffix = sanitize_text_field($request->get_param('suffix') ?? '');

        $tables = is_array($tables) ? array_values(array_filter(array_map('sanitize_text_field', $tables))) : [];
        if (!empty($tables)) {
            Sync::swap_tables($tables, $temp_prefix, $backup_prefix);
        }

        if ($swap_uploads) {
            $tmp = Sync::tmp_uploads_dir($suffix);
            $bak = Sync::bak_uploads_dir($suffix);
            $live = Sync::uploads_dir();
            $swap = Sync::swap_uploads($tmp, $bak, $live);
            if (empty($swap['success'])) {
                return rest_ensure_response($swap);
            }
        }

        return rest_ensure_response(['success' => true]);
    }

    public function cleanup_replication(\WP_REST_Request $request) {
        $tables = $request->get_param('tables') ?? [];
        $temp_prefix = sanitize_text_field($request->get_param('temp_prefix') ?? 'tmp_');
        $backup_prefix = sanitize_text_field($request->get_param('backup_prefix') ?? 'bak_');
        $cleanup_uploads = !empty($request->get_param('cleanup_uploads'));
        $suffix = sanitize_text_field($request->get_param('suffix') ?? '');

        $tables = is_array($tables) ? array_values(array_filter(array_map('sanitize_text_field', $tables))) : [];
        if (!empty($tables)) {
            Sync::cleanup_tables($tables, $temp_prefix, $backup_prefix);
        }

        if ($cleanup_uploads) {
            $tmp = Sync::tmp_uploads_dir($suffix);
            $bak = Sync::bak_uploads_dir($suffix);
            Sync::cleanup_uploads($tmp, $bak);
        }

        return rest_ensure_response(['success' => true]);
    }

    private function store_dump($sql, $token) {
        $upload = wp_upload_dir();
        $dir = rtrim($upload['basedir'], '/') . '/gires-cicd';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }

        $path = $dir . '/dump_' . $token . '.sql';
        $ok = file_put_contents($path, $sql);
        if ($ok === false) {
            return null;
        }

        set_transient('gires_cicd_dump_' . $token, $path, 10 * MINUTE_IN_SECONDS);
        return $path;
    }

    private function get_dump_path($token) {
        $path = get_transient('gires_cicd_dump_' . $token);
        if (!$path) {
            $upload = wp_upload_dir();
            $candidate = rtrim($upload['basedir'], '/') . '/gires-cicd/dump_' . $token . '.sql';
            return is_file($candidate) ? $candidate : null;
        }
        return $path;
    }

    public function authorize_request() {
        if (!$this->settings->get('rest_enabled')) {
            return false;
        }

        $token = $this->settings->get('rest_token');
        $hmac = $this->settings->get('rest_hmac_secret');

        $header_token = $_SERVER['HTTP_X_GIRES_TOKEN'] ?? '';
        $header_sig = $_SERVER['HTTP_X_GIRES_SIGNATURE'] ?? '';
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $allowlist = $this->settings->get('rest_allowlist');
        if (!empty($allowlist)) {
            $allowed = array_filter(array_map('trim', preg_split('/\r?\n/', $allowlist)));
            if (!empty($allowed) && !in_array($remote_ip, $allowed, true)) {
                return false;
            }
        }

        if (empty($token) || empty($hmac)) {
            return false;
        }

        if (!hash_equals($token, $header_token)) {
            return false;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = wp_parse_url($uri, PHP_URL_PATH) ?? $uri;
        $payload = file_get_contents('php://input');
        $data = $method . "\n" . $path . "\n" . $payload;
        $expected = hash_hmac('sha256', $data, $hmac);

        if (!hash_equals($expected, $header_sig)) {
            return false;
        }

        return true;
    }
}
