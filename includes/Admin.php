<?php

namespace GiresCICD;

class Admin {
    private $settings;
    private $migrations;
    private $replication;

    public function __construct(Settings $settings, Migrations $migrations, Replication $replication) {
        $this->settings = $settings;
        $this->migrations = $migrations;
        $this->replication = $replication;
    }

    public function init() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_gires_cicd_save', [$this, 'save_settings']);
        add_action('admin_post_gires_cicd_run_migrations', [$this, 'run_migrations']);
        add_action('admin_post_gires_cicd_generate_scripts', [$this, 'generate_scripts']);
        add_action('admin_post_gires_cicd_connect', [$this, 'connect_remote']);
        add_action('admin_post_gires_cicd_run_sync', [$this, 'run_sync']);
        add_action('wp_ajax_gires_cicd_run_job', [$this, 'ajax_run_job']);
        add_action('wp_ajax_gires_cicd_job_step', [$this, 'ajax_job_step']);
        add_action('wp_ajax_gires_cicd_cleanup', [$this, 'ajax_cleanup']);
        add_action('wp_ajax_gires_cicd_table_info', [$this, 'ajax_table_info']);
        add_action('wp_ajax_gires_cicd_test_connection', [$this, 'ajax_test_connection']);
    }

    public function add_menu() {
        add_menu_page(
            'CI/CD Tools',
            'CI/CD Tools',
            'manage_options',
            'gires-cicd-tools',
            [$this, 'render_page'],
            'dashicons-controls-repeat',
            58
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $settings = $this->settings->get_all();
        $files = $this->migrations->list_files();
        $applied = $this->migrations->get_applied();
        $pending = $this->migrations->get_pending();
        $sets = $this->replication->get_sets();
        global $wpdb;
        $all_tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($all_tables)) {
            $all_tables = [];
        }

        require GIRES_CICD_DIR . 'templates/admin.php';
    }

    public function save_settings() {
        check_admin_referer('gires_cicd_save');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $data = $this->build_settings_from_request($_POST);

        if (!empty($_POST['gires_cicd_generate_token'])) {
            $data['rest_token'] = bin2hex(random_bytes(32));
            $generated = 'token=1&';
        }
        if (!empty($_POST['gires_cicd_generate_hmac'])) {
            $data['rest_hmac_secret'] = bin2hex(random_bytes(32));
            $generated = ($generated ?? '') . 'hmac=1&';
        }

        $this->settings->update($data);

        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=config&' . ($generated ?? '') . 'saved=1'));
        exit;
    }

    private function normalize_allowlist($value) {
        if (is_array($value)) {
            $items = array_filter(array_map('sanitize_text_field', $value));
            return implode("\n", $items);
        }

        return sanitize_textarea_field((string) $value);
    }

    public function run_migrations() {
        check_admin_referer('gires_cicd_run_migrations');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $result = $this->migrations->apply_all();
        $errors = !empty($result['errors']);

        $query = $errors ? 'error=1' : 'ran=1';
        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&' . $query));
        exit;
    }

    public function generate_scripts() {
        check_admin_referer('gires_cicd_generate_scripts');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $incoming = [
            'ssh_host' => sanitize_text_field($_POST['ssh_host'] ?? ''),
            'ssh_user' => sanitize_text_field($_POST['ssh_user'] ?? ''),
            'ssh_path' => sanitize_text_field($_POST['ssh_path'] ?? ''),
            'db_name' => sanitize_text_field($_POST['db_name'] ?? ''),
            'db_user' => sanitize_text_field($_POST['db_user'] ?? ''),
            'db_pass' => sanitize_text_field($_POST['db_pass'] ?? ''),
            'db_host' => sanitize_text_field($_POST['db_host'] ?? ''),
        ];

        $settings = $this->settings->update($incoming);
        $include_secrets = !empty($_POST['include_secrets']);

        $scripts = new \GiresCICD\Scripts($this->settings);
        $ok = $scripts->generate($include_secrets, true);
        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&scripts=' . ($ok ? '1' : '0')));
        exit;
    }

    public function connect_remote() {
        check_admin_referer('gires_cicd_save');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $this->settings->update($this->build_settings_from_request($_POST));
        $settings = $this->settings->get_all();
        $result = $this->test_remote_connection($settings);

        if (empty($result['success'])) {
            $reason = rawurlencode($result['message'] ?? 'Connexion impossible.');
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=config&connection=0&connection_error=' . $reason));
            exit;
        }

        $query = 'connection=1';
        if (!empty($result['remote_ip'])) {
            $query .= '&remote_ip=' . urlencode($result['remote_ip']);
        }

        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=config&' . $query));
        exit;
    }

    public function run_sync() {
        check_admin_referer('gires_cicd_run_sync');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $set_name = sanitize_text_field($_POST['replication_set'] ?? '');
        $set = $this->replication->get_set_by_name($set_name);
        if (!$set) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sync&sync=0&reason=set'));
            exit;
        }

        $settings = $this->settings->get_all();
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sync&sync=0&reason=url'));
            exit;
        }

        $mode = $set['type'] ?? ($set['mode'] ?? 'none');
        if ($mode === 'none') {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sync&sync=0&reason=mode'));
            exit;
        }

        if ($mode === 'pull') {
            $result = $this->pull_from_remote($remote, $settings, $set);
        } else {
            $result = $this->push_to_remote($remote, $settings, $set);
        }

        $query = 'sync=' . (!empty($result['success']) ? '1' : '0');
        if (!empty($result['message'])) {
            $query .= '&message=' . rawurlencode($result['message']);
        }
        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sync&' . $query));
        exit;
    }

    private function pull_from_remote($remote, array $settings, array $set) {
        $payload = wp_json_encode([
            'set_name' => $set['id'] ?? $set['name'],
            'tables' => $set['tables'] ?? [],
            'search' => $set['search'] ?? [],
            'replace' => $set['replace'] ?? [],
            'search_only_tables' => $set['tables'] ?? [],
        ]);

        $export = $this->signed_request('POST', $remote . '/wp-json/gires-cicd/v1/replication/export', $payload, $settings);
        if (is_wp_error($export)) {
            return ['success' => false, 'message' => $export->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($export), true);
        $token = $body['download_token'] ?? '';
        if (!$token) {
            return ['success' => false, 'message' => 'Token de téléchargement manquant'];
        }

        $download_url = $remote . '/wp-json/gires-cicd/v1/replication/download?token=' . urlencode($token);
        $download = $this->signed_request('GET', $download_url, '', $settings);
        if (is_wp_error($download)) {
            return ['success' => false, 'message' => $download->get_error_message()];
        }

        $sql = wp_remote_retrieve_body($download);
        $result = $this->replication->import_sql($sql, $set);
        return $result;
    }

    public function ajax_run_job() {
        check_ajax_referer('gires_cicd_job');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        $set_id = sanitize_text_field($_POST['set_id'] ?? '');
        $dry_run = !empty($_POST['dry_run']);
        $set = $this->replication->get_set_by_name($set_id);
        if (!$set) {
            wp_send_json_error(['message' => 'Set introuvable']);
        }

        $job = $this->create_job($set, $dry_run);
        update_option('gires_cicd_job', $job, false);
        wp_send_json_success(['job_id' => $job['id'], 'progress' => $job['progress'], 'message' => 'Job démarré']);
    }

    public function ajax_job_step() {
        check_ajax_referer('gires_cicd_job');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        $job = get_option('gires_cicd_job');
        if (empty($job) || empty($job['steps'])) {
            wp_send_json_error(['message' => 'Aucun job actif']);
        }

        $result = $this->run_next_step($job);
        update_option('gires_cicd_job', $result['job'], false);

        wp_send_json_success([
            'progress' => $result['job']['progress'],
            'status' => $result['job']['status'],
            'message' => $result['message'] ?? '',
        ]);
    }

    public function ajax_cleanup() {
        check_ajax_referer('gires_cicd_job');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        $set_id = sanitize_text_field($_POST['set_id'] ?? '');
        $set = $this->replication->get_set_by_name($set_id);
        if (!$set) {
            wp_send_json_error(['message' => 'Set introuvable']);
        }

        $tables = $this->get_selected_tables($set);
        $temp_prefix = $set['temp_prefix'] ?? 'tmp_';
        $backup_prefix = $set['backup_prefix'] ?? 'bak_';
        Sync::cleanup_tables($tables, $temp_prefix, $backup_prefix);

        if (!empty($set['include_media'])) {
            $suffix = $set['id'] ?? 'local';
            $tmp = Sync::tmp_uploads_dir($suffix);
            $bak = Sync::bak_uploads_dir($suffix);
            Sync::cleanup_uploads($tmp, $bak);
        }

        wp_send_json_success(['message' => 'Nettoyage terminé']);
    }

    public function ajax_table_info() {
        check_ajax_referer('gires_cicd_job');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        global $wpdb;
        $table = sanitize_text_field($_POST['table'] ?? '');
        if (empty($table)) {
            wp_send_json_error(['message' => 'Table manquante']);
        }

        $all = $wpdb->get_col('SHOW TABLES');
        if (!is_array($all) || !in_array($table, $all, true)) {
            wp_send_json_error(['message' => 'Table inconnue']);
        }

        $schema = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
        $example = $wpdb->get_row("SELECT * FROM `{$table}` LIMIT 1", ARRAY_A);

        wp_send_json_success([
            'schema' => $schema ?: [],
            'example' => $example ?: [],
        ]);
    }

    public function ajax_test_connection() {
        check_ajax_referer('gires_cicd_job');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission refusée']);
        }

        $settings = $this->settings->get_all();
        $result = $this->test_remote_connection($settings);
        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Connexion impossible']);
        }
        wp_send_json_success($result);
    }

    private function push_to_remote($remote, array $settings, array $set) {
        $sql = $this->replication->export_sql($set, [
            'search' => $set['search'] ?? [],
            'replace' => $set['replace'] ?? [],
            'search_only_tables' => $set['tables'] ?? [],
        ]);
        $payload = wp_json_encode([
            'set_name' => $set['id'] ?? $set['name'],
            'sql' => base64_encode($sql),
            'encoding' => 'base64',
            'skip_rename' => true,
            'temp_prefix' => $set['temp_prefix'] ?? 'tmp_',
        ]);

        $import = $this->signed_request('POST', $remote . '/wp-json/gires-cicd/v1/replication/import', $payload, $settings);
        if (is_wp_error($import)) {
            return ['success' => false, 'message' => $import->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($import), true);
        if (!empty($body['success'])) {
            return ['success' => true, 'message' => 'Push terminé'];
        }

        return ['success' => false, 'message' => $body['message'] ?? 'Erreur import distante'];
    }

    private function signed_request($method, $url, $payload, array $settings) {
        $token = $settings['rest_token'] ?? '';
        $hmac = $settings['rest_hmac_secret'] ?? '';

        if (empty($token) || empty($hmac)) {
            return new \WP_Error('gires_cicd_no_auth', 'Token ou HMAC manquant');
        }

        $path = wp_parse_url($url, PHP_URL_PATH) ?? '';
        $signature = hash_hmac('sha256', $method . "\n" . $path . "\n" . $payload, $hmac);

        $headers = [
            'X-Gires-Token' => $token,
            'X-Gires-Signature' => $signature,
        ];
        if (!empty($payload)) {
            $headers['Content-Type'] = 'application/json';
        }

        return wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $payload,
            'timeout' => 60,
        ]);
    }

    private function build_settings_from_request(array $request) {
        $current = $this->settings->get_all();
        return [
            'migrations_path' => sanitize_text_field($request['migrations_path'] ?? ''),
            'applied_option' => sanitize_text_field($request['applied_option'] ?? ''),
            'rest_enabled' => !empty($request['rest_enabled']),
            'rest_token' => sanitize_text_field($request['rest_token'] ?? ''),
            'rest_hmac_secret' => sanitize_text_field($request['rest_hmac_secret'] ?? ''),
            'rest_allowlist' => $this->normalize_allowlist($request['rest_allowlist'] ?? []),
            'rest_last_connection_ok' => $current['rest_last_connection_ok'] ?? false,
            'remote_url' => esc_url_raw($request['remote_url'] ?? ''),
            'ssh_host' => sanitize_text_field($request['ssh_host'] ?? ''),
            'ssh_user' => sanitize_text_field($request['ssh_user'] ?? ''),
            'ssh_path' => sanitize_text_field($request['ssh_path'] ?? ''),
            'db_name' => sanitize_text_field($request['db_name'] ?? ''),
            'db_user' => sanitize_text_field($request['db_user'] ?? ''),
            'db_pass' => sanitize_text_field($request['db_pass'] ?? ''),
            'db_host' => sanitize_text_field($request['db_host'] ?? ''),
            'replication_sets' => $this->normalize_replication_sets($request['replication_sets'] ?? ($current['replication_sets'] ?? [])),
        ];
    }

    private function normalize_replication_sets($sets) {
        if (!is_array($sets)) {
            return [];
        }

        $normalized = [];
        $used_ids = [];
        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }
            $search = $set['search'] ?? [];
            $replace = $set['replace'] ?? [];
            if (!is_array($search)) {
                $search = preg_split('/\r?\n/', (string) $search);
            }
            if (!is_array($replace)) {
                $replace = preg_split('/\r?\n/', (string) $replace);
            }
            $tables = $set['tables'] ?? [];
            if (!is_array($tables)) {
                $tables = preg_split('/\r?\n/', (string) $tables);
            }
            $sr = [];
            $max = max(count($search), count($replace));
            for ($i = 0; $i < $max; $i++) {
                $s = sanitize_text_field($search[$i] ?? '');
                $r = sanitize_text_field($replace[$i] ?? '');
                if ($s === '' && $r === '') {
                    continue;
                }
                $sr[] = ['search' => $s, 'replace' => $r];
            }
            $search = array_column($sr, 'search');
            $replace = array_column($sr, 'replace');

            $id = sanitize_key($set['id'] ?? '');
            if (empty($id)) {
                $base = sanitize_key($set['name'] ?? 'set');
                $id = $base ?: ('set_' . bin2hex(random_bytes(3)));
            }
            if (isset($used_ids[$id])) {
                $id .= '_' . bin2hex(random_bytes(2));
            }
            $used_ids[$id] = true;

            $normalized[] = [
                'id' => $id,
                'name' => sanitize_text_field($set['name'] ?? ''),
                'type' => sanitize_text_field($set['type'] ?? 'pull'),
                'tables' => array_values(array_filter(array_map('sanitize_text_field', $tables))),
                'search' => array_values(array_filter(array_map('sanitize_text_field', $search))),
                'replace' => array_values(array_filter(array_map('sanitize_text_field', $replace))),
                'include_media' => !empty($set['include_media']),
                'media_chunk_mb' => (int) ($set['media_chunk_mb'] ?? 512),
                'temp_prefix' => sanitize_text_field($set['temp_prefix'] ?? 'tmp_'),
                'backup_prefix' => sanitize_text_field($set['backup_prefix'] ?? 'bak_'),
                'auto_cleanup' => !empty($set['auto_cleanup']),
            ];
        }
        return $normalized;
    }

    private function create_job(array $set, $dry_run = false) {
        $id = bin2hex(random_bytes(6));
        $type = $set['type'] ?? 'pull';
        $steps = $type === 'push'
            ? [
                'pre_pull_backup',
                'maintenance_on_remote',
                'db_export_local',
                'db_import_remote',
                'media_upload_remote',
                'swap_remote',
                'cleanup_remote',
                'maintenance_off_remote',
            ]
            : [
                'maintenance_on_local',
                'db_export_remote',
                'db_download_remote',
                'db_import_local',
                'media_export_remote',
                'media_download_remote',
                'swap_local',
                'cleanup_local',
                'maintenance_off_local',
            ];

        return [
            'id' => $id,
            'set' => $set,
            'type' => $type,
            'steps' => $steps,
            'step_index' => 0,
            'progress' => 0,
            'status' => 'running',
            'dry_run' => $dry_run,
            'context' => [
                'media_part' => 1,
                'media_parts' => 0,
                'media_token' => '',
                'db_token' => '',
            ],
        ];
    }

    private function run_next_step(array $job) {
        $set = $job['set'];
        $dry_run = !empty($job['dry_run']);
        $steps = $job['steps'];
        $index = (int) $job['step_index'];
        $message = '';

        if ($index >= count($steps)) {
            $job['status'] = 'done';
            $job['progress'] = 100;
            return ['job' => $job, 'message' => 'Terminé'];
        }

        $step = $steps[$index];
        $result = ['success' => true];

        switch ($step) {
            case 'maintenance_on_local':
                if ($dry_run) {
                    $message = 'Dry-run: maintenance locale ignorée';
                    break;
                }
                Sync::set_maintenance(true);
                $message = 'Maintenance locale activée';
                break;
            case 'maintenance_off_local':
                if ($dry_run) {
                    $message = 'Dry-run: maintenance locale ignorée';
                    break;
                }
                Sync::set_maintenance(false);
                $message = 'Maintenance locale désactivée';
                break;
            case 'maintenance_on_remote':
                if ($dry_run) {
                    $message = 'Dry-run: maintenance distante ignorée';
                    break;
                }
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/maintenance', ['enabled' => true]);
                $message = 'Maintenance distante activée';
                break;
            case 'maintenance_off_remote':
                if ($dry_run) {
                    $message = 'Dry-run: maintenance distante ignorée';
                    break;
                }
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/maintenance', ['enabled' => false]);
                $message = 'Maintenance distante désactivée';
                break;
            case 'db_export_remote':
                $tables = $this->get_selected_tables($set);
                $payload = [
                    'set_name' => $set['id'] ?? '',
                    'tables' => $tables,
                    'search' => $set['search'] ?? [],
                    'replace' => $set['replace'] ?? [],
                    'search_only_tables' => $tables,
                ];
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/replication/export', $payload);
                if (!empty($result['download_token'])) {
                    $job['context']['db_token'] = $result['download_token'];
                }
                $message = 'Export DB distant OK';
                break;
            case 'db_download_remote':
                $token = $job['context']['db_token'] ?? '';
                $sql = $this->remote_download('/wp-json/gires-cicd/v1/replication/download?token=' . urlencode($token));
                if ($sql === false) {
                    $result = ['success' => false, 'message' => 'Téléchargement SQL échoué'];
                } else {
                    $dir = Sync::uploads_dir() . '/gires-cicd';
                    Sync::ensure_dir($dir);
                    $path = $dir . '/db_' . $job['id'] . '.sql';
                    file_put_contents($path, $sql);
                    $job['context']['db_path'] = $path;
                }
                $message = 'SQL téléchargé';
                break;
            case 'db_import_local':
                $path = $job['context']['db_path'] ?? '';
                $sql = $path && is_file($path) ? file_get_contents($path) : '';
                $options = [
                    'skip_rename' => true,
                    'temp_prefix' => $set['temp_prefix'] ?? 'tmp_',
                ];
                $result = $this->replication->import_sql($sql, $set, $options);
                if ($path && is_file($path)) {
                    @unlink($path);
                }
                $message = 'Import DB local OK';
                break;
            case 'media_export_remote':
                if (empty($set['include_media'])) {
                    $message = 'Médias ignorés';
                    break;
                }
                $payload = ['max_mb' => (int) ($set['media_chunk_mb'] ?? 512)];
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/media/export', $payload);
                if (!empty($result['token'])) {
                    $job['context']['media_token'] = $result['token'];
                    $job['context']['media_parts'] = (int) ($result['parts'] ?? 0);
                }
                $message = 'Export médias distant OK';
                break;
            case 'media_download_remote':
                if (empty($set['include_media'])) {
                    $message = 'Médias ignorés';
                    break;
                }
                $part = (int) ($job['context']['media_part'] ?? 1);
                $parts = (int) ($job['context']['media_parts'] ?? 0);
                if ($part <= $parts) {
                    $token = $job['context']['media_token'] ?? '';
                    $content = $this->remote_download('/wp-json/gires-cicd/v1/media/download?token=' . urlencode($token) . '&part=' . $part);
                    if ($content === false) {
                        $result = ['success' => false, 'message' => 'Téléchargement ZIP échoué'];
                        break;
                    }
                    $tmp_dir = Sync::tmp_uploads_dir($set['id'] ?? 'local');
                    $zip_path = Sync::uploads_dir() . '/gires-cicd/media_part' . $part . '.zip';
                    Sync::ensure_dir(dirname($zip_path));
                    file_put_contents($zip_path, $content);
                    $unz = Sync::unzip_archive($zip_path, $tmp_dir);
                    @unlink($zip_path);
                    if (empty($unz['success'])) {
                        $result = $unz;
                        break;
                    }
                    $job['context']['media_part'] = $part + 1;
                    $message = 'ZIP médias ' . $part . '/' . $parts . ' téléchargé';
                    // stay on same step until done
                    $job['step_index'] = $index;
                    $job['progress'] = min(95, $job['progress'] + 1);
                    return ['job' => $job, 'message' => $message];
                }
                $message = 'Médias téléchargés';
                break;
            case 'swap_local':
                if ($dry_run) {
                    $message = 'Dry-run: swap local ignoré';
                    break;
                }
                $tables = $this->get_selected_tables($set);
                Sync::swap_tables($tables, $set['temp_prefix'] ?? 'tmp_', $set['backup_prefix'] ?? 'bak_');
                if (!empty($set['include_media'])) {
                    $suffix = $set['id'] ?? 'local';
                    Sync::swap_uploads(Sync::tmp_uploads_dir($suffix), Sync::bak_uploads_dir($suffix), Sync::uploads_dir());
                }
                $message = 'Swap local OK';
                break;
            case 'cleanup_local':
                if ($dry_run) {
                    $message = 'Dry-run: nettoyage local ignoré';
                    break;
                }
                if (!empty($set['auto_cleanup'])) {
                    $tables = $this->get_selected_tables($set);
                    Sync::cleanup_tables($tables, $set['temp_prefix'] ?? 'tmp_', $set['backup_prefix'] ?? 'bak_');
                    if (!empty($set['include_media'])) {
                        $suffix = $set['id'] ?? 'local';
                        Sync::cleanup_uploads(Sync::tmp_uploads_dir($suffix), Sync::bak_uploads_dir($suffix));
                    }
                    $message = 'Nettoyage local OK';
                } else {
                    $message = 'Nettoyage ignoré';
                }
                break;
            case 'pre_pull_backup':
                $backup_dir = ABSPATH . 'backups';
                Sync::ensure_dir($backup_dir);
                $tables = $this->get_selected_tables($set);
                $payload = [
                    'set_name' => $set['id'] ?? '',
                    'tables' => $tables,
                    'search' => [],
                    'replace' => [],
                    'search_only_tables' => $tables,
                ];
                $export = $this->remote_request('POST', '/wp-json/gires-cicd/v1/replication/export', $payload);
                if (empty($export['download_token'])) {
                    $result = ['success' => false, 'message' => 'Backup DB impossible'];
                    break;
                }
                $sql = $this->remote_download('/wp-json/gires-cicd/v1/replication/download?token=' . urlencode($export['download_token']));
                if ($sql === false) {
                    $result = ['success' => false, 'message' => 'Téléchargement backup DB échoué'];
                    break;
                }
                $file = $backup_dir . '/pre_push_' . date('Ymd_His') . '.sql';
                file_put_contents($file, $sql);
                $message = 'Backup DB prod OK';
                break;
            case 'db_export_local':
                $tables = $this->get_selected_tables($set);
                $override = [
                    'search' => $set['search'] ?? [],
                    'replace' => $set['replace'] ?? [],
                    'search_only_tables' => $tables,
                ];
                $sql = $this->replication->export_sql($set, $override);
                $dir = Sync::uploads_dir() . '/gires-cicd';
                Sync::ensure_dir($dir);
                $path = $dir . '/db_' . $job['id'] . '.sql';
                file_put_contents($path, $sql);
                $job['context']['db_path'] = $path;
                $message = 'Export DB local OK';
                break;
            case 'db_import_remote':
                $path = $job['context']['db_path'] ?? '';
                $sql = $path && is_file($path) ? file_get_contents($path) : '';
                $tables = $this->get_selected_tables($set);
                $payload = [
                    'set_name' => $set['id'] ?? '',
                    'tables' => $tables,
                    'sql' => base64_encode($sql),
                    'encoding' => 'base64',
                    'skip_rename' => true,
                    'temp_prefix' => $set['temp_prefix'] ?? 'tmp_',
                ];
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/replication/import', $payload);
                if ($path && is_file($path)) {
                    @unlink($path);
                }
                $message = 'Import DB distant OK';
                break;
            case 'media_upload_remote':
                if (empty($set['include_media'])) {
                    $message = 'Médias ignorés';
                    break;
                }
                $uploads = Sync::uploads_dir();
                $dest_dir = $uploads . '/gires-cicd';
                $archives = Sync::create_media_archives($uploads, $dest_dir, (int) ($set['media_chunk_mb'] ?? 512));
                if (empty($archives['success'])) {
                    $result = $archives;
                    break;
                }
                $parts = $archives['archives'];
                $token = $archives['token'];
                $job['context']['media_token'] = $token;
                $job['context']['media_parts'] = count($parts);
                foreach ($parts as $idx => $path) {
                    $content = file_get_contents($path);
                    $upload = $this->remote_request_raw(
                        'POST',
                        '/wp-json/gires-cicd/v1/media/import?token=' . urlencode($token) . '&part=' . ($idx + 1) . '&suffix=' . urlencode($set['id'] ?? 'remote'),
                        $content,
                        'application/zip'
                    );
                    @unlink($path);
                    if (empty($upload['success'])) {
                        $result = $upload;
                        break 2;
                    }
                }
                $message = 'Médias envoyés';
                break;
            case 'swap_remote':
                if ($dry_run) {
                    $message = 'Dry-run: swap distant ignoré';
                    break;
                }
                $tables = $this->get_selected_tables($set);
                $payload = [
                    'tables' => $tables,
                    'temp_prefix' => $set['temp_prefix'] ?? 'tmp_',
                    'backup_prefix' => $set['backup_prefix'] ?? 'bak_',
                    'swap_uploads' => !empty($set['include_media']),
                    'suffix' => $set['id'] ?? 'remote',
                ];
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/replication/swap', $payload);
                $message = 'Swap distant OK';
                break;
            case 'cleanup_remote':
                if ($dry_run) {
                    $message = 'Dry-run: nettoyage distant ignoré';
                    break;
                }
                if (!empty($set['auto_cleanup'])) {
                    $tables = $this->get_selected_tables($set);
                    $payload = [
                        'tables' => $tables,
                        'temp_prefix' => $set['temp_prefix'] ?? 'tmp_',
                        'backup_prefix' => $set['backup_prefix'] ?? 'bak_',
                        'cleanup_uploads' => !empty($set['include_media']),
                        'suffix' => $set['id'] ?? 'remote',
                    ];
                    $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/replication/cleanup', $payload);
                    $message = 'Nettoyage distant OK';
                } else {
                    $message = 'Nettoyage ignoré';
                }
                break;
            default:
                break;
        }

        if (empty($result['success']) && isset($result['message'])) {
            $job['status'] = 'error';
            return ['job' => $job, 'message' => $result['message']];
        }

        $job['step_index'] = $index + 1;
        $job['progress'] = (int) round((($job['step_index']) / max(1, count($steps))) * 100);
        $job['status'] = $job['step_index'] >= count($steps) ? 'done' : 'running';

        return ['job' => $job, 'message' => $message];
    }

    private function get_selected_tables(array $set) {
        global $wpdb;

        $tables = $set['tables'] ?? [];
        if (empty($tables)) {
            $all = $wpdb->get_col('SHOW TABLES');
            return is_array($all) ? $all : [];
        }
        return array_values(array_filter(array_map('trim', $tables)));
    }

    private function remote_request($method, $path, array $payload = []) {
        $settings = $this->settings->get_all();
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            return ['success' => false, 'message' => 'URL distante manquante'];
        }
        $url = $remote . $path;
        $body = wp_json_encode($payload);
        $response = $this->signed_request($method, $url, $body, $settings);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($data)) {
            return $data;
        }
        return ['success' => false, 'message' => 'Réponse distante invalide'];
    }

    private function remote_request_raw($method, $path, $body, $content_type = 'application/octet-stream') {
        $settings = $this->settings->get_all();
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            return ['success' => false, 'message' => 'URL distante manquante'];
        }

        $url = $remote . $path;
        $token = $settings['rest_token'] ?? '';
        $hmac = $settings['rest_hmac_secret'] ?? '';
        if (empty($token) || empty($hmac)) {
            return ['success' => false, 'message' => 'Token/HMAC manquant'];
        }

        $path_only = wp_parse_url($url, PHP_URL_PATH) ?? '';
        $signature = hash_hmac('sha256', $method . "\n" . $path_only . "\n" . $body, $hmac);

        $headers = [
            'X-Gires-Token' => $token,
            'X-Gires-Signature' => $signature,
            'Content-Type' => $content_type,
        ];

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : ['success' => true];
    }

    private function remote_download($path) {
        $settings = $this->settings->get_all();
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            return false;
        }
        $url = $remote . $path;
        $response = $this->signed_request('GET', $url, '', $settings);
        if (is_wp_error($response)) {
            return false;
        }
        return wp_remote_retrieve_body($response);
    }

    private function test_remote_connection(array $settings) {
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            $settings['rest_last_connection_ok'] = false;
            $this->settings->update($settings);
            return ['success' => false, 'message' => 'URL distante manquante'];
        }

        if (empty($settings['rest_token']) || empty($settings['rest_hmac_secret'])) {
            $settings['rest_last_connection_ok'] = false;
            $this->settings->update($settings);
            return ['success' => false, 'message' => 'Token ou HMAC manquant'];
        }

        $endpoint = $remote . '/wp-json/gires-cicd/v1/status';
        $response = $this->signed_request('GET', $endpoint, '', $settings);
        if (is_wp_error($response)) {
            $settings['rest_last_connection_ok'] = false;
            $this->settings->update($settings);
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $remote_ip = $body['remote_ip'] ?? '';
        $remote_site = $body['site_url'] ?? '';
        $remote_pending = (int) ($body['pending_count'] ?? 0);

        $settings['rest_last_connection_ok'] = true;
        $settings['remote_ip'] = $remote_ip;
        $settings['remote_site_url'] = $remote_site;
        $settings['remote_pending_count'] = $remote_pending;
        $this->settings->update($settings);

        return [
            'success' => true,
            'remote_ip' => $remote_ip,
            'remote_site_url' => $remote_site,
            'remote_pending_count' => $remote_pending,
        ];
    }
}
