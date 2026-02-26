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
        add_action('admin_post_gires_cicd_delete_migration', [$this, 'delete_migration']);
        add_action('admin_post_gires_cicd_delete_set', [$this, 'delete_set']);
        add_action('admin_post_gires_cicd_delete_sets', [$this, 'delete_sets']);
        add_action('admin_post_gires_cicd_duplicate_sets', [$this, 'duplicate_sets']);
        add_action('admin_post_gires_cicd_generate_scripts', [$this, 'generate_scripts']);
        add_action('admin_post_gires_cicd_connect', [$this, 'connect_remote']);
        add_action('admin_post_gires_cicd_run_sync', [$this, 'run_sync']);
        add_action('wp_ajax_gires_cicd_run_job', [$this, 'ajax_run_job']);
        add_action('wp_ajax_gires_cicd_job_step', [$this, 'ajax_job_step']);
        add_action('wp_ajax_gires_cicd_stop_job', [$this, 'ajax_stop_job']);
        add_action('wp_ajax_gires_cicd_tail_log', [$this, 'ajax_tail_log']);
        add_action('wp_ajax_gires_cicd_generate_ssh_key', [$this, 'ajax_generate_ssh_key']);
        add_action('wp_ajax_gires_cicd_download_ssh_key', [$this, 'ajax_download_ssh_key']);
        add_action('wp_ajax_gires_cicd_test_ssh', [$this, 'ajax_test_ssh']);
        add_action('wp_ajax_gires_cicd_cleanup', [$this, 'ajax_cleanup']);
        add_action('wp_ajax_gires_cicd_table_info', [$this, 'ajax_table_info']);
        add_action('wp_ajax_gires_cicd_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_gires_cicd_preview_job', [$this, 'ajax_preview_job']);
        add_action('gires_cicd_continue_job', [$this, 'continue_job_background']);
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

        $tab = sanitize_text_field($_POST['gires_tab'] ?? 'config');
        if (!in_array($tab, ['sets', 'migrations', 'config'], true)) {
            $tab = 'config';
        }
        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=' . $tab . '&' . ($generated ?? '') . 'saved=1'));
        exit;
    }

    private function normalize_allowlist($value) {
        if (is_array($value)) {
            $items = array_filter(array_map('sanitize_text_field', $value));
            return implode("\n", $items);
        }

        return sanitize_textarea_field((string) $value);
    }

    private function normalize_rsync_excludes($value) {
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

    public function delete_migration() {
        check_admin_referer('gires_cicd_delete_migration');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $file = sanitize_file_name($_POST['migration_file'] ?? '');
        $result = $this->migrations->delete_file($file);

        if (!empty($result['success'])) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=migrations&deleted=1&file=' . rawurlencode($file)));
            exit;
        }

        $message = rawurlencode($result['message'] ?? 'Suppression impossible');
        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=migrations&del_error=1&message=' . $message));
        exit;
    }

    public function delete_set() {
        check_admin_referer('gires_cicd_delete_set');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $set_id = sanitize_key($_POST['set_id'] ?? '');
        if ($set_id === '') {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_deleted=0&reason=invalid'));
            exit;
        }

        $current = $this->settings->get_all();
        $sets = $current['replication_sets'] ?? [];
        if (!is_array($sets)) {
            $sets = [];
        }

        $before = count($sets);
        $sets = array_values(array_filter($sets, function ($set) use ($set_id) {
            return !is_array($set) || sanitize_key($set['id'] ?? '') !== $set_id;
        }));

        if (count($sets) === $before) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_deleted=0&reason=notfound'));
            exit;
        }

        $current['replication_sets'] = $sets;
        $this->settings->update($current);

        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_deleted=1&id=' . rawurlencode($set_id)));
        exit;
    }

    public function delete_sets() {
        check_admin_referer('gires_cicd_delete_sets');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $raw = $_POST['set_ids'] ?? '';
        $ids = array_filter(array_map('sanitize_key', array_map('trim', explode(',', (string) $raw))));
        if (empty($ids)) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_deleted=0&reason=invalid'));
            exit;
        }

        $current = $this->settings->get_all();
        $sets = $current['replication_sets'] ?? [];
        if (!is_array($sets)) {
            $sets = [];
        }

        $before = count($sets);
        $sets = array_values(array_filter($sets, function ($set) use ($ids) {
            $sid = is_array($set) ? sanitize_key($set['id'] ?? '') : '';
            return $sid === '' || !in_array($sid, $ids, true);
        }));

        if (count($sets) === $before) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_deleted=0&reason=notfound'));
            exit;
        }

        $current['replication_sets'] = $sets;
        $this->settings->update($current);

        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_deleted=1&deleted_count=' . (int) ($before - count($sets))));
        exit;
    }

    public function duplicate_sets() {
        check_admin_referer('gires_cicd_duplicate_sets');
        if (!current_user_can('manage_options')) {
            wp_die('Permission refusée');
        }

        $raw = $_POST['set_ids'] ?? '';
        $ids = array_filter(array_map('sanitize_key', array_map('trim', explode(',', (string) $raw))));
        if (empty($ids)) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_duplicated=0&reason=invalid'));
            exit;
        }

        $current = $this->settings->get_all();
        $sets = $current['replication_sets'] ?? [];
        if (!is_array($sets)) {
            $sets = [];
        }

        $existing_ids = [];
        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }
            $sid = sanitize_key($set['id'] ?? '');
            if ($sid !== '') {
                $existing_ids[$sid] = true;
            }
        }

        $duplicates = [];
        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }
            $sid = sanitize_key($set['id'] ?? '');
            if ($sid === '' || !in_array($sid, $ids, true)) {
                continue;
            }

            $clone = $set;
            $clone_name = sanitize_text_field(($set['name'] ?? '') . ' (copie)');
            if ($clone_name === '(copie)') {
                $clone_name = 'set copie';
            }
            $clone['name'] = $clone_name;
            $clone['id'] = $this->build_unique_set_id($sid . '_copy', $existing_ids);
            $duplicates[] = $clone;
        }

        if (empty($duplicates)) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_duplicated=0&reason=notfound'));
            exit;
        }

        $current['replication_sets'] = array_values(array_merge($sets, $duplicates));
        $this->settings->update($current);

        wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sets&set_duplicated=1&duplicated_count=' . count($duplicates)));
        exit;
    }

    private function build_unique_set_id(string $base, array &$existing_ids): string {
        $candidate = sanitize_key($base);
        if ($candidate === '') {
            $candidate = 'set_copy';
        }
        $i = 1;
        $unique = $candidate;
        while (isset($existing_ids[$unique])) {
            $unique = $candidate . '_' . $i;
            $i++;
        }
        $existing_ids[$unique] = true;
        return $unique;
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

        $compat = $this->ensure_remote_plugin_compatible($settings);
        if (empty($compat['success'])) {
            wp_redirect(admin_url('admin.php?page=gires-cicd-tools&tab=sync&sync=0&message=' . rawurlencode($compat['message'] ?? __('Versions plugin incompatibles', 'gires-cicd-tools'))));
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
            'exclude_option_prefix' => $set['exclude_option_prefix'] ?? '',
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
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_run_job: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_run_job: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $set_id = sanitize_text_field($_POST['set_id'] ?? '');
        $dry_run = !empty($_POST['dry_run']);
        $this->log('ajax_run_job: start', [
            'user_id' => get_current_user_id(),
            'set_id' => $set_id,
            'dry_run' => $dry_run,
        ]);

        $current_job = get_option('gires_cicd_job');
        if (is_array($current_job) && ($current_job['status'] ?? '') === 'running') {
            $this->log('ajax_run_job: job already running', [
                'current_job_id' => $current_job['id'] ?? '',
                'requested_set_id' => $set_id,
            ]);
            wp_send_json_error(['message' => __('Un job est déjà en cours. Arrête-le ou attends sa fin.', 'gires-cicd-tools')]);
        }

        $set = $this->replication->get_set_by_name($set_id);
        if (!$set) {
            $this->log('ajax_run_job: set not found', ['set_id' => $set_id]);
            wp_send_json_error(['message' => __('Set introuvable', 'gires-cicd-tools')]);
        }

        $settings = $this->settings->get_all();
        $remote_guard = $this->validate_remote_target($settings);
        if (empty($remote_guard['success'])) {
            $this->log('ajax_run_job: invalid remote target', [
                'set_id' => $set_id,
                'message' => $remote_guard['message'] ?? '',
                'remote_url' => $settings['remote_url'] ?? '',
                'home_url' => home_url('/'),
            ]);
            wp_send_json_error(['message' => $remote_guard['message'] ?? __('URL distante invalide', 'gires-cicd-tools')]);
        }
        $compat = $this->ensure_remote_plugin_compatible($settings);
        if (empty($compat['success'])) {
            $this->log('ajax_run_job: remote plugin version mismatch', [
                'set_id' => $set_id,
                'message' => $compat['message'] ?? '',
            ]);
            wp_send_json_error(['message' => $compat['message'] ?? __('Versions plugin incompatibles', 'gires-cicd-tools')]);
        }

        $job = $this->create_job($set, $dry_run);
        update_option('gires_cicd_job', $job, false);
        $this->schedule_continue_job(1);
        $this->log('ajax_run_job: job created', ['job_id' => $job['id'] ?? '', 'type' => $job['type'] ?? '']);
        $start_message = $this->format_job_message(__('Job démarré', 'gires-cicd-tools'), $dry_run);
        wp_send_json_success([
            'job_id' => $job['id'],
            'progress' => $job['progress'],
            'message' => $start_message,
            'steps' => $job['steps'] ?? [],
            'step_index' => $job['step_index'] ?? 0,
            'status' => $job['status'] ?? 'running',
        ]);
    }

    public function ajax_job_step() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_job_step: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_job_step: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $job = get_option('gires_cicd_job');
        if (empty($job) || empty($job['steps'])) {
            $this->log('ajax_job_step: no active job');
            wp_send_json_error(['message' => __('Aucun job actif', 'gires-cicd-tools')]);
        }
        if (!empty($job['status']) && $job['status'] === 'stopped') {
            $this->log('ajax_job_step: job stopped', ['job_id' => $job['id'] ?? '']);
            wp_send_json_error(['message' => __('Arrêté', 'gires-cicd-tools')]);
        }

        $this->log('ajax_job_step: running', [
            'job_id' => $job['id'] ?? '',
            'step_index' => $job['step_index'] ?? null,
            'status' => $job['status'] ?? '',
        ]);
        if (get_transient('gires_cicd_job_lock')) {
            $status = $job['status'] ?? 'running';
            $msg = __('Step en cours...', 'gires-cicd-tools');
            if ($status === 'stopping' || !empty($job['stop_requested'])) {
                $msg = __('Arrêt demandé...', 'gires-cicd-tools');
            }
            wp_send_json_success([
                'progress' => $job['progress'] ?? 0,
                'status' => $status,
                'message' => $this->format_job_message($msg, !empty($job['dry_run'])),
                'steps' => $job['steps'] ?? [],
                'step_index' => $job['step_index'] ?? 0,
            ]);
        }
        set_transient('gires_cicd_job_lock', 1, 30);
        try {
            $result = $this->run_next_step($job);
            update_option('gires_cicd_job', $result['job'], false);
            if (($result['job']['status'] ?? '') === 'running') {
                $this->schedule_continue_job(1);
            }
        } finally {
            delete_transient('gires_cicd_job_lock');
        }

        wp_send_json_success([
            'progress' => $result['job']['progress'],
            'status' => $result['job']['status'],
            'message' => $result['message'] ?? '',
            'steps' => $result['job']['steps'] ?? [],
            'step_index' => $result['job']['step_index'] ?? 0,
        ]);
    }

    public function continue_job_background() {
        $job = get_option('gires_cicd_job');
        if (empty($job) || !is_array($job)) {
            return;
        }
        if (($job['status'] ?? '') !== 'running') {
            return;
        }
        $lock = get_transient('gires_cicd_job_lock');
        if ($lock) {
            $this->schedule_continue_job(2);
            return;
        }
        set_transient('gires_cicd_job_lock', 1, 30);
        try {
            $result = $this->run_next_step($job);
            update_option('gires_cicd_job', $result['job'], false);
            if (($result['job']['status'] ?? '') === 'running') {
                $this->schedule_continue_job(1);
            }
        } catch (\Throwable $e) {
            $job['status'] = 'error';
            update_option('gires_cicd_job', $job, false);
            $this->release_maintenance_on_error($job, 'background');
            $this->log('continue_job_background: exception', [
                'job_id' => $job['id'] ?? '',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } finally {
            delete_transient('gires_cicd_job_lock');
        }
    }

    private function schedule_continue_job(int $delay_seconds = 1): void {
        $timestamp = time() + max(1, $delay_seconds);
        wp_schedule_single_event($timestamp, 'gires_cicd_continue_job');
    }

    public function ajax_stop_job() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_stop_job: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_stop_job: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $job = get_option('gires_cicd_job');
        if (empty($job)) {
            $this->log('ajax_stop_job: no active job');
            wp_send_json_success([
                'message' => __('Déjà arrêté', 'gires-cicd-tools'),
                'progress' => 0,
                'status' => 'stopped',
            ]);
        }

        $is_running = (($job['status'] ?? '') === 'running');
        $job['stop_requested'] = true;
        $job['status'] = $is_running ? 'stopping' : 'stopped';
        $job['progress'] = $job['progress'] ?? 0;
        update_option('gires_cicd_job', $job, false);
        $this->log('ajax_stop_job: stop requested', [
            'job_id' => $job['id'] ?? '',
            'is_running' => $is_running,
        ]);

        // Best effort: disable maintenance if it was enabled
        $this->force_disable_local_maintenance();
        $set = $job['set'] ?? [];
        $settings = $this->settings->get_all();
        if (!empty($settings['remote_url'])) {
            $response = $this->remote_request('POST', '/wp-json/gires-cicd/v1/maintenance', ['enabled' => false]);
            if (empty($response['success'])) {
                $this->disable_remote_maintenance_via_ssh();
            }
        }

        wp_send_json_success([
            'message' => $is_running ? __('Arrêt demandé...', 'gires-cicd-tools') : __('Arrêté', 'gires-cicd-tools'),
            'progress' => $job['progress'],
            'status' => $job['status'],
        ]);
    }

    public function ajax_tail_log() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_tail_log: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_tail_log: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $lines = (int) ($_POST['lines'] ?? 200);
        if ($lines < 1 || $lines > 2000) {
            $lines = 200;
        }

        $log_path = $this->resolve_debug_log_path();
        if (!$log_path || !is_file($log_path)) {
            wp_send_json_error(['message' => __('debug.log introuvable', 'gires-cicd-tools')]);
        }

        $content = $this->tail_file($log_path, $lines, 'gires-cicd');
        wp_send_json_success([
            'lines' => $content,
            'path' => $log_path,
        ]);
    }

    public function ajax_generate_ssh_key() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }
        $this->log('ajax_generate_ssh_key: start', [
            'user_id' => get_current_user_id(),
        ]);
        $private = '';
        $public = '';
        $fallback = $this->generate_ssh_key_with_ssh_keygen();
        if (!empty($fallback['private']) && !empty($fallback['public'])) {
            $private = $fallback['private'];
            $public = $fallback['public'];
        } else {
            $this->log('ajax_generate_ssh_key: failed', [
                'reason' => 'ssh-keygen failed',
            ]);
            wp_send_json_error(['message' => __('Impossible de générer la clé (ssh-keygen).', 'gires-cicd-tools')]);
        }
        $token = bin2hex(random_bytes(16));
        set_transient('gires_cicd_ssh_key_' . $token, [
            'private' => $private,
            'public' => $public,
        ], 10 * MINUTE_IN_SECONDS);

        $download_private = add_query_arg([
            'action' => 'gires_cicd_download_ssh_key',
            'token' => $token,
            'type' => 'private',
            '_ajax_nonce' => wp_create_nonce('gires_cicd_job'),
        ], admin_url('admin-ajax.php'));
        $download_public = add_query_arg([
            'action' => 'gires_cicd_download_ssh_key',
            'token' => $token,
            'type' => 'public',
            '_ajax_nonce' => wp_create_nonce('gires_cicd_job'),
        ], admin_url('admin-ajax.php'));

        $config_snippet = "Host ssh.cluster102.hosting.ovh.net\n  User gires\n  IdentityFile /path/to/gires_cicd\n  IdentitiesOnly yes\n";

        wp_send_json_success([
            'public_key' => $public,
            'download_private' => $download_private,
            'download_public' => $download_public,
            'config_snippet' => $config_snippet,
        ]);
    }

    public function ajax_download_ssh_key() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            wp_die(__('Nonce invalide', 'gires-cicd-tools'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission refusée', 'gires-cicd-tools'));
        }
        $token = sanitize_text_field($_GET['token'] ?? '');
        if ($token === '') {
            wp_die(__('Token manquant', 'gires-cicd-tools'));
        }
        $payload = get_transient('gires_cicd_ssh_key_' . $token);
        if (!$payload || !is_array($payload)) {
            wp_die(__('Clé expirée ou introuvable', 'gires-cicd-tools'));
        }
        $type = sanitize_text_field($_GET['type'] ?? 'private');
        $content = $type === 'public' ? ($payload['public'] ?? '') : ($payload['private'] ?? '');
        if ($content === '') {
            wp_die(__('Clé expirée ou introuvable', 'gires-cicd-tools'));
        }
        if ($type === 'private') {
            delete_transient('gires_cicd_ssh_key_' . $token);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="gires_cicd' . ($type === 'public' ? '.pub' : '') . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    public function ajax_test_ssh() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }
        if ($this->has_partial_ssh_config()) {
            wp_send_json_error(['message' => __('SSH incomplet: renseigne host/user/path.', 'gires-cicd-tools')]);
        }
        if (!$this->has_ssh_config()) {
            wp_send_json_error(['message' => __('SSH non configuré (host/user/path).', 'gires-cicd-tools')]);
        }
        if (!function_exists('exec')) {
            wp_send_json_error(['message' => __('exec() indisponible', 'gires-cicd-tools')]);
        }

        $settings = $this->settings->get_all();
        $host = $settings['ssh_host'];
        $user = $settings['ssh_user'];
        $cmd = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s@%s "echo OK"',
            escapeshellarg($user),
            escapeshellarg($host)
        );
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $output = trim(implode("\n", $out));
        if ($code !== 0) {
            $this->log('ajax_test_ssh: failed', [
                'code' => $code,
                'output' => $output,
            ]);
            wp_send_json_error(['message' => $output ?: __('Connexion SSH impossible.', 'gires-cicd-tools')]);
        }
        wp_send_json_success(['message' => __('Connexion SSH OK.', 'gires-cicd-tools')]);
    }

    public function ajax_cleanup() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_cleanup: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_cleanup: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $set_id = sanitize_text_field($_POST['set_id'] ?? '');
        $this->log('ajax_cleanup: start', ['set_id' => $set_id, 'user_id' => get_current_user_id()]);
        $set = $this->replication->get_set_by_name($set_id);
        if (!$set) {
            $this->log('ajax_cleanup: set not found', ['set_id' => $set_id]);
            wp_send_json_error(['message' => __('Set introuvable', 'gires-cicd-tools')]);
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

        wp_send_json_success(['message' => __('Nettoyage terminé', 'gires-cicd-tools')]);
    }

    public function ajax_table_info() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_table_info: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_table_info: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        global $wpdb;
        $table = sanitize_text_field($_POST['table'] ?? '');
        $this->log('ajax_table_info: start', ['table' => $table, 'user_id' => get_current_user_id()]);
        if (empty($table)) {
            wp_send_json_error(['message' => __('Table manquante', 'gires-cicd-tools')]);
        }

        $all = $wpdb->get_col('SHOW TABLES');
        if (!is_array($all) || !in_array($table, $all, true)) {
            wp_send_json_error(['message' => __('Table inconnue', 'gires-cicd-tools')]);
        }

        $schema = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
        $example = $wpdb->get_row("SELECT * FROM `{$table}` LIMIT 1", ARRAY_A);

        wp_send_json_success([
            'schema' => $schema ?: [],
            'example' => $example ?: [],
        ]);
    }

    public function ajax_test_connection() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            $this->log('ajax_test_connection: nonce invalid', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            $this->log('ajax_test_connection: permission denied', [
                'user_id' => get_current_user_id(),
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $this->log('ajax_test_connection: start', ['user_id' => get_current_user_id()]);
        $settings = $this->settings->get_all();
        $result = $this->test_remote_connection($settings);
        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? __('Connexion impossible', 'gires-cicd-tools')]);
        }
        wp_send_json_success($result);
    }

    public function ajax_preview_job() {
        if (!check_ajax_referer('gires_cicd_job', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Nonce invalide', 'gires-cicd-tools')]);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission refusée', 'gires-cicd-tools')]);
        }

        $set_id = sanitize_text_field($_POST['set_id'] ?? '');
        $dry_run = !empty($_POST['dry_run']);
        if ($set_id === '') {
            wp_send_json_error(['message' => __('Set manquant', 'gires-cicd-tools')]);
        }

        $set = $this->replication->get_set_by_name($set_id);
        if (!$set) {
            wp_send_json_error(['message' => __('Set introuvable', 'gires-cicd-tools')]);
        }

        $job = $this->create_job($set, $dry_run);
        $tables = $this->get_selected_tables($set);
        $preview_limit = 80;
        $tables_preview = array_slice($tables, 0, $preview_limit);

        $search = is_array($set['search'] ?? null) ? array_values($set['search']) : [];
        $replace = is_array($set['replace'] ?? null) ? array_values($set['replace']) : [];
        $sr = [];
        $max = max(count($search), count($replace));
        for ($i = 0; $i < $max; $i++) {
            $s = trim((string) ($search[$i] ?? ''));
            $r = trim((string) ($replace[$i] ?? ''));
            if ($s === '' && $r === '') {
                continue;
            }
            $sr[] = ['search' => $s, 'replace' => $r];
        }

        wp_send_json_success([
            'set_id' => $set['id'] ?? $set_id,
            'set_name' => $set['name'] ?? $set_id,
            'type' => $set['type'] ?? 'pull',
            'dry_run' => $dry_run,
            'include_code' => !array_key_exists('include_code', $set) || !empty($set['include_code']),
            'include_media' => !empty($set['include_media']),
            'exclude_option_prefix' => (string) ($set['exclude_option_prefix'] ?? ''),
            'steps' => $job['steps'] ?? [],
            'tables_count' => count($tables),
            'tables_preview' => $tables_preview,
            'tables_preview_truncated' => count($tables) > $preview_limit,
            'search_replace' => $sr,
        ]);
    }

    private function log($message, array $context = []) {
        $line = '[gires-cicd] ' . $message;
        if (!empty($context)) {
            $line .= ' ' . wp_json_encode($context);
        }
        error_log($line);
    }

    private function push_to_remote($remote, array $settings, array $set) {
        $sql = $this->replication->export_sql($set, [
            'search' => $set['search'] ?? [],
            'replace' => $set['replace'] ?? [],
            'search_only_tables' => $set['tables'] ?? [],
            'insert_chunk_size' => 1,
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
            $this->log('signed_request: missing token/hmac', [
                'url' => $url,
            ]);
            return new \WP_Error('gires_cicd_no_auth', __('Token ou HMAC manquant', 'gires-cicd-tools'));
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
            'timeout' => 180,
            'connect_timeout' => 10,
        ]);
    }

    private function build_settings_from_request(array $request) {
        $current = $this->settings->get_all();
        return [
            'migrations_path' => sanitize_text_field($request['migrations_path'] ?? ($current['migrations_path'] ?? '')),
            'applied_option' => sanitize_text_field($request['applied_option'] ?? ($current['applied_option'] ?? '')),
            'rest_enabled' => isset($request['rest_enabled']) ? !empty($request['rest_enabled']) : !empty($current['rest_enabled']),
            'rest_token' => sanitize_text_field($request['rest_token'] ?? ($current['rest_token'] ?? '')),
            'rest_hmac_secret' => sanitize_text_field($request['rest_hmac_secret'] ?? ($current['rest_hmac_secret'] ?? '')),
            'rest_allowlist' => $this->normalize_allowlist($request['rest_allowlist'] ?? ($current['rest_allowlist'] ?? [])),
            'rest_last_connection_ok' => $current['rest_last_connection_ok'] ?? false,
            'remote_url' => esc_url_raw($request['remote_url'] ?? ($current['remote_url'] ?? '')),
            'ssh_host' => sanitize_text_field($request['ssh_host'] ?? ($current['ssh_host'] ?? '')),
            'ssh_user' => sanitize_text_field($request['ssh_user'] ?? ($current['ssh_user'] ?? '')),
            'ssh_path' => sanitize_text_field($request['ssh_path'] ?? ($current['ssh_path'] ?? '')),
            'db_name' => sanitize_text_field($request['db_name'] ?? ($current['db_name'] ?? '')),
            'db_user' => sanitize_text_field($request['db_user'] ?? ($current['db_user'] ?? '')),
            'db_pass' => sanitize_text_field($request['db_pass'] ?? ($current['db_pass'] ?? '')),
            'db_host' => sanitize_text_field($request['db_host'] ?? ($current['db_host'] ?? '')),
            'smoke_after_sync' => isset($request['smoke_after_sync']) ? !empty($request['smoke_after_sync']) : !empty($current['smoke_after_sync']),
            'smoke_strict' => isset($request['smoke_strict']) ? !empty($request['smoke_strict']) : !empty($current['smoke_strict']),
            'rsync_excludes' => $this->normalize_rsync_excludes($request['rsync_excludes'] ?? ($current['rsync_excludes'] ?? '')),
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
                'exclude_option_prefix' => sanitize_text_field($set['exclude_option_prefix'] ?? ''),
                'include_code' => array_key_exists('include_code', $set) ? !empty($set['include_code']) : true,
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
        $include_code = !array_key_exists('include_code', $set) || !empty($set['include_code']);
        if ($type === 'push') {
            $steps = [
                'pre_pull_backup',
                'db_export_local',
                'db_import_remote',
                'media_upload_remote',
                'swap_remote',
                'cleanup_remote',
                'smoke_local',
            ];
            if ($include_code) {
                array_unshift($steps, 'code_push');
            }
        } else {
            $steps = [
                'maintenance_on_local',
                'db_export_remote',
                'db_download_remote',
                'db_import_local',
                'media_export_remote',
                'media_download_remote',
                'swap_local',
                'cleanup_local',
                'maintenance_off_local',
                'smoke_local',
            ];
            if ($include_code) {
                array_unshift($steps, 'code_pull');
            }
        }

        return [
            'id' => $id,
            'set' => $set,
            'type' => $type,
            'steps' => $steps,
            'step_index' => 0,
            'progress' => 0,
            'status' => 'running',
            'stop_requested' => false,
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

        if ($this->should_stop_job($job)) {
            $job['status'] = 'stopped';
            $job['stop_requested'] = true;
            return ['job' => $job, 'message' => $this->format_job_message(__('Arrêt demandé', 'gires-cicd-tools'), $dry_run)];
        }

        if ($index >= count($steps)) {
            $job['status'] = 'done';
            $job['progress'] = 100;
            return ['job' => $job, 'message' => $this->format_job_message(__('Terminé', 'gires-cicd-tools'), $dry_run)];
        }

        $step = $steps[$index];
        $result = ['success' => true];

        try {
            switch ($step) {
            case 'code_push':
                if ($dry_run) {
                    $message = __('Test à blanc: sync code ignorée', 'gires-cicd-tools');
                    break;
                }
                if ($this->has_partial_ssh_config()) {
                    $result = ['success' => false, 'message' => __('SSH incomplet: renseigne host/user/path.', 'gires-cicd-tools')];
                    break;
                }
                if (!$this->has_ssh_config()) {
                    $message = __('Sync code ignorée: SSH non configuré', 'gires-cicd-tools');
                    break;
                }
                $result = $this->run_sync_script('push', true, true);
                $message = __('Sync code (WP + plugins + thèmes) OK', 'gires-cicd-tools');
                break;
            case 'code_pull':
                if ($dry_run) {
                    $message = __('Test à blanc: sync code ignorée', 'gires-cicd-tools');
                    break;
                }
                if ($this->has_partial_ssh_config()) {
                    $result = ['success' => false, 'message' => __('SSH incomplet: renseigne host/user/path.', 'gires-cicd-tools')];
                    break;
                }
                if (!$this->has_ssh_config()) {
                    $message = __('Sync code ignorée: SSH non configuré', 'gires-cicd-tools');
                    break;
                }
                $result = $this->run_sync_script('pull', true, true);
                $message = __('Sync code (WP + plugins + thèmes) OK', 'gires-cicd-tools');
                break;
            case 'maintenance_on_local':
                if ($dry_run) {
                    $message = __('Test à blanc: maintenance locale ignorée', 'gires-cicd-tools');
                    break;
                }
                // En mode AJAX (UI admin), activer .maintenance coupe aussi admin-ajax.php
                // et casse le suivi de job. On ignore donc cette étape pour les runs pilotés UI.
                if (wp_doing_ajax()) {
                    $message = __('Maintenance locale ignorée en mode AJAX', 'gires-cicd-tools');
                    break;
                }
                Sync::set_maintenance(true);
                $message = __('Maintenance locale activée', 'gires-cicd-tools');
                break;
            case 'maintenance_off_local':
                if ($dry_run) {
                    $message = __('Test à blanc: maintenance locale ignorée', 'gires-cicd-tools');
                    break;
                }
                if (wp_doing_ajax()) {
                    $message = __('Maintenance locale ignorée en mode AJAX', 'gires-cicd-tools');
                    break;
                }
                Sync::set_maintenance(false);
                $message = __('Maintenance locale désactivée', 'gires-cicd-tools');
                break;
            case 'maintenance_on_remote':
                if ($dry_run) {
                    $message = __('Test à blanc: maintenance distante ignorée', 'gires-cicd-tools');
                    break;
                }
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/maintenance', ['enabled' => true]);
                $message = __('Maintenance distante activée', 'gires-cicd-tools');
                break;
            case 'maintenance_off_remote':
                if ($dry_run) {
                    $message = __('Test à blanc: maintenance distante ignorée', 'gires-cicd-tools');
                    break;
                }
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/maintenance', ['enabled' => false]);
                $message = __('Maintenance distante désactivée', 'gires-cicd-tools');
                break;
            case 'db_export_remote':
                $tables = $this->get_selected_tables($set);
                $payload = [
                    'set_name' => $set['id'] ?? '',
                    'tables' => $tables,
                    'search' => $set['search'] ?? [],
                    'replace' => $set['replace'] ?? [],
                    'search_only_tables' => $tables,
                    'exclude_option_prefix' => $set['exclude_option_prefix'] ?? '',
                    'insert_chunk_size' => 1,
                ];
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/replication/export', $payload);
                if (!empty($result['download_token'])) {
                    $job['context']['db_token'] = $result['download_token'];
                }
                $message = __('Export DB distant OK', 'gires-cicd-tools');
                break;
            case 'db_download_remote':
                $token = $job['context']['db_token'] ?? '';
                $sql = $this->remote_download('/wp-json/gires-cicd/v1/replication/download?token=' . urlencode($token));
                if ($sql === false) {
                    $result = ['success' => false, 'message' => __('Téléchargement SQL échoué', 'gires-cicd-tools')];
                } else {
                    $sql = $this->normalize_sql_payload($sql);
                    $dir = Sync::uploads_dir() . '/gires-cicd';
                    Sync::ensure_dir($dir);
                    $path = $dir . '/db_' . $job['id'] . '.sql';
                    file_put_contents($path, $sql);
                    $job['context']['db_path'] = $path;
                }
                $message = __('SQL téléchargé', 'gires-cicd-tools');
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
                $message = __('Import DB local OK', 'gires-cicd-tools');
                break;
            case 'media_export_remote':
                if (empty($set['include_media'])) {
                    $message = __('Médias ignorés', 'gires-cicd-tools');
                    break;
                }
                $rsync_result = $this->pull_media_diff_from_remote($set);
                if (!empty($rsync_result['success'])) {
                    $job['context']['media_mode'] = 'rsync';
                    $message = __('Médias préparés (différentiel)', 'gires-cicd-tools');
                    break;
                }
                $this->log('media_export_remote: rsync fallback to zip', [
                    'reason' => $rsync_result['message'] ?? '',
                ]);
                $payload = ['max_mb' => (int) ($set['media_chunk_mb'] ?? 512)];
                $result = $this->remote_request('POST', '/wp-json/gires-cicd/v1/media/export', $payload);
                if (!empty($result['token'])) {
                    $job['context']['media_token'] = $result['token'];
                    $job['context']['media_parts'] = (int) ($result['parts'] ?? 0);
                }
                $message = __('Export médias distant OK', 'gires-cicd-tools');
                break;
            case 'media_download_remote':
                if (empty($set['include_media'])) {
                    $message = __('Médias ignorés', 'gires-cicd-tools');
                    break;
                }
                if (($job['context']['media_mode'] ?? '') === 'rsync') {
                    $message = __('Médias téléchargés (différentiel)', 'gires-cicd-tools');
                    break;
                }
                $part = (int) ($job['context']['media_part'] ?? 1);
                $parts = (int) ($job['context']['media_parts'] ?? 0);
                if ($part <= $parts) {
                    $token = $job['context']['media_token'] ?? '';
                    $content = $this->remote_download('/wp-json/gires-cicd/v1/media/download?token=' . urlencode($token) . '&part=' . $part);
                    if ($content === false) {
                        $result = ['success' => false, 'message' => __('Téléchargement ZIP échoué', 'gires-cicd-tools')];
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
                    $message = sprintf(__('ZIP médias %d/%d téléchargé', 'gires-cicd-tools'), $part, $parts);
                    // stay on same step until done
                    $job['step_index'] = $index;
                    $job['progress'] = min(95, $job['progress'] + 1);
                    return ['job' => $job, 'message' => $message];
                }
                $message = __('Médias téléchargés', 'gires-cicd-tools');
                break;
            case 'swap_local':
                if ($dry_run) {
                    $message = __('Test à blanc: swap local ignoré', 'gires-cicd-tools');
                    break;
                }
                $tables = $this->get_selected_tables($set);
                Sync::swap_tables($tables, $set['temp_prefix'] ?? 'tmp_', $set['backup_prefix'] ?? 'bak_');
                if (!empty($set['include_media'])) {
                    $suffix = $set['id'] ?? 'local';
                    Sync::swap_uploads(Sync::tmp_uploads_dir($suffix), Sync::bak_uploads_dir($suffix), Sync::uploads_dir());
                }
                $message = __('Swap local OK', 'gires-cicd-tools');
                break;
            case 'cleanup_local':
                if ($dry_run) {
                    $message = __('Test à blanc: nettoyage local ignoré', 'gires-cicd-tools');
                    break;
                }
                if (!empty($set['auto_cleanup'])) {
                    $tables = $this->get_selected_tables($set);
                    Sync::cleanup_tables($tables, $set['temp_prefix'] ?? 'tmp_', $set['backup_prefix'] ?? 'bak_');
                    if (!empty($set['include_media'])) {
                        $suffix = $set['id'] ?? 'local';
                        Sync::cleanup_uploads(Sync::tmp_uploads_dir($suffix), Sync::bak_uploads_dir($suffix));
                    }
                    $message = __('Nettoyage local OK', 'gires-cicd-tools');
                } else {
                    $message = __('Nettoyage ignoré', 'gires-cicd-tools');
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
                    $result = ['success' => false, 'message' => $export['message'] ?? __('Backup DB impossible', 'gires-cicd-tools')];
                    break;
                }
                $sql = $this->remote_download('/wp-json/gires-cicd/v1/replication/download?token=' . urlencode($export['download_token']));
                if ($sql === false) {
                    $result = ['success' => false, 'message' => __('Téléchargement backup DB échoué', 'gires-cicd-tools')];
                    break;
                }
                $file = $backup_dir . '/pre_push_' . date('Ymd_His') . '.sql';
                file_put_contents($file, $sql);
                $message = __('Backup DB prod OK', 'gires-cicd-tools');
                break;
            case 'db_export_local':
                $tables = $this->get_selected_tables($set);
                $override = [
                    'search' => $set['search'] ?? [],
                    'replace' => $set['replace'] ?? [],
                    'search_only_tables' => $tables,
                    'insert_chunk_size' => 1,
                ];
                $sql = $this->replication->export_sql($set, $override);
                $dir = Sync::uploads_dir() . '/gires-cicd';
                Sync::ensure_dir($dir);
                $path = $dir . '/db_' . $job['id'] . '.sql';
                file_put_contents($path, $sql);
                $job['context']['db_path'] = $path;
                $message = __('Export DB local OK', 'gires-cicd-tools');
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
                $message = __('Import DB distant OK', 'gires-cicd-tools');
                break;
            case 'media_upload_remote':
                if (empty($set['include_media'])) {
                    $message = __('Médias ignorés', 'gires-cicd-tools');
                    break;
                }
                $rsync_result = $this->upload_media_diff_to_remote($set);
                if (!empty($rsync_result['success'])) {
                    $message = __('Médias envoyés (différentiel)', 'gires-cicd-tools');
                    break;
                }
                $this->log('media_upload_remote: rsync fallback to zip', [
                    'reason' => $rsync_result['message'] ?? '',
                ]);
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
                $message = __('Médias envoyés', 'gires-cicd-tools');
                break;
            case 'swap_remote':
                if ($dry_run) {
                    $message = __('Test à blanc: swap distant ignoré', 'gires-cicd-tools');
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
                $message = __('Swap distant OK', 'gires-cicd-tools');
                break;
            case 'cleanup_remote':
                if ($dry_run) {
                    $message = __('Test à blanc: nettoyage distant ignoré', 'gires-cicd-tools');
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
                    $message = __('Nettoyage distant OK', 'gires-cicd-tools');
                } else {
                    $message = __('Nettoyage ignoré', 'gires-cicd-tools');
                }
                break;
            case 'smoke_local':
                $smoke = $this->run_local_smoke_tests($dry_run);
                if (empty($smoke['success'])) {
                    $result = ['success' => false, 'message' => $smoke['message'] ?? __('Smoke tests KO', 'gires-cicd-tools')];
                    break;
                }
                $message = $smoke['message'] ?? __('Smoke tests OK', 'gires-cicd-tools');
                break;
                default:
                    break;
            }
        } catch (\Throwable $e) {
            $this->release_maintenance_on_error($job, $step);
            $this->log('run_next_step: exception', [
                'step' => $step,
                'job_id' => $job['id'] ?? '',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $job['status'] = 'error';
            return [
                'job' => $job,
                'message' => $this->format_job_message(
                    __('Erreur inattendue pendant le job', 'gires-cicd-tools') . ': ' . $e->getMessage(),
                    $dry_run
                ),
            ];
        }

        if (empty($result['success']) && isset($result['message'])) {
            $this->release_maintenance_on_error($job, $step);
            $this->log('run_next_step: error', [
                'step' => $step,
                'message' => $result['message'],
                'job_id' => $job['id'] ?? '',
            ]);
            $job['status'] = 'error';
            return ['job' => $job, 'message' => $this->format_job_message((string) $result['message'], $dry_run)];
        }

        if ($this->should_stop_job($job)) {
            $job['status'] = 'stopped';
            $job['stop_requested'] = true;
            return ['job' => $job, 'message' => $this->format_job_message(__('Arrêt demandé', 'gires-cicd-tools'), $dry_run)];
        }

        $job['step_index'] = $index + 1;
        $job['progress'] = (int) round((($job['step_index']) / max(1, count($steps))) * 100);
        $job['status'] = $job['step_index'] >= count($steps) ? 'done' : 'running';
        $base_message = $message !== '' ? $message : __('Terminé', 'gires-cicd-tools');
        return ['job' => $job, 'message' => $this->format_job_message($base_message, $dry_run)];
    }

    private function should_stop_job(array $job): bool {
        if (!empty($job['stop_requested'])) {
            return true;
        }
        $job_id = (string) ($job['id'] ?? '');
        if ($job_id === '') {
            return false;
        }
        $latest = get_option('gires_cicd_job');
        if (!is_array($latest) || (string) ($latest['id'] ?? '') !== $job_id) {
            return false;
        }
        if (!empty($latest['stop_requested'])) {
            return true;
        }
        $status = (string) ($latest['status'] ?? '');
        return in_array($status, ['stopping', 'stopped'], true);
    }

    private function format_job_message(string $message, bool $dry_run): string {
        $normalized = trim($message);
        $normalized = preg_replace('/^\[(DRY-RUN|REEL)\]\s*/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^Test\s+à\s+blanc\s*:\s*/u', '', $normalized) ?? $normalized;
        if ($normalized === '') {
            $normalized = __('Terminé', 'gires-cicd-tools');
        }
        $mode = $dry_run ? 'DRY-RUN' : 'REEL';
        return '[' . $mode . '] ' . $normalized;
    }

    private function release_maintenance_on_error(array $job, string $failed_step) {
        $steps = $job['steps'] ?? [];
        $index = (int) ($job['step_index'] ?? 0);
        $type = $job['type'] ?? '';

        if ($type === 'push') {
            $maintenance_index = array_search('maintenance_on_remote', $steps, true);
            if ($maintenance_index !== false && $index >= $maintenance_index) {
                $response = $this->remote_request('POST', '/wp-json/gires-cicd/v1/maintenance', ['enabled' => false]);
                if (empty($response['success'])) {
                    $this->disable_remote_maintenance_via_ssh();
                }
                $this->log('release_maintenance_on_error: remote off', [
                    'failed_step' => $failed_step,
                    'job_id' => $job['id'] ?? '',
                ]);
            }
            return;
        }

        if ($type === 'pull') {
            $maintenance_index = array_search('maintenance_on_local', $steps, true);
            if ($maintenance_index !== false && $index >= $maintenance_index) {
                $this->force_disable_local_maintenance();
                $this->log('release_maintenance_on_error: local off', [
                    'failed_step' => $failed_step,
                    'job_id' => $job['id'] ?? '',
                ]);
            }
        }
    }

    private function resolve_debug_log_path() {
        if (defined('WP_DEBUG_LOG')) {
            if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
                return WP_DEBUG_LOG;
            }
            if (WP_DEBUG_LOG === true && defined('WP_CONTENT_DIR')) {
                return WP_CONTENT_DIR . '/debug.log';
            }
        }
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/debug.log';
        }
        return '';
    }

    private function normalize_sql_payload($sql) {
        if (!is_string($sql) || $sql === '') {
            return (string) $sql;
        }
        $decoded = json_decode($sql, true);
        if (is_string($decoded)) {
            return $decoded;
        }
        return $sql;
    }

    private function generate_ssh_key_with_ssh_keygen(): array {
        if (!function_exists('shell_exec')) {
            return [];
        }
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/gires_cicd_ssh_' . bin2hex(random_bytes(4));
        $priv = $tmp;
        $pub = $tmp . '.pub';
        $cmd = 'ssh-keygen -t ed25519 -C "gires-cicd" -f ' . escapeshellarg($priv) . ' -N ""';
        $out = shell_exec($cmd . ' 2>&1');
        if (!is_file($priv) || !is_file($pub)) {
            if (is_file($priv)) @unlink($priv);
            if (is_file($pub)) @unlink($pub);
            return [];
        }
        $private = file_get_contents($priv) ?: '';
        $public = trim((string) file_get_contents($pub));
        @unlink($priv);
        @unlink($pub);
        return ['private' => $private, 'public' => $public, 'output' => $out];
    }
    private function has_ssh_config(): bool {
        $settings = $this->settings->get_all();
        return !empty($settings['ssh_host']) && !empty($settings['ssh_user']) && !empty($settings['ssh_path']);
    }

    private function has_partial_ssh_config(): bool {
        $settings = $this->settings->get_all();
        $values = [
            trim((string) ($settings['ssh_host'] ?? '')),
            trim((string) ($settings['ssh_user'] ?? '')),
            trim((string) ($settings['ssh_path'] ?? '')),
        ];
        $filled = array_filter($values, function ($v) {
            return $v !== '';
        });
        return !empty($filled) && count($filled) < count($values);
    }

    private function disable_remote_maintenance_via_ssh() {
        if (!function_exists('exec')) {
            $this->log('disable_remote_maintenance_via_ssh: exec unavailable');
            return false;
        }
        if (!$this->has_ssh_config()) {
            $this->log('disable_remote_maintenance_via_ssh: ssh not configured');
            return false;
        }

        $settings = $this->settings->get_all();
        $host = $settings['ssh_host'];
        $user = $settings['ssh_user'];
        $path = rtrim((string) $settings['ssh_path'], '/');
        $target = $path . '/.maintenance';

        $cmd = sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=12 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s@%s %s 2>&1',
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg('rm -f ' . $target)
        );

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $this->log('disable_remote_maintenance_via_ssh: failed', [
                'code' => $code,
                'output' => trim(implode("\n", $out)),
            ]);
            return false;
        }

        $this->log('disable_remote_maintenance_via_ssh: success');
        return true;
    }

    private function force_disable_local_maintenance(): bool {
        $file = ABSPATH . '.maintenance';
        if (!is_file($file)) {
            return true;
        }
        if (Sync::set_maintenance(false)) {
            return true;
        }
        @chmod($file, 0644);
        if (@unlink($file)) {
            return true;
        }
        $this->log('force_disable_local_maintenance: failed', [
            'file' => $file,
            'is_writable' => is_writable($file),
            'owner' => function_exists('fileowner') ? @fileowner($file) : null,
        ]);
        return false;
    }

    private function upload_media_diff_to_remote(array $set): array {
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => __('exec() indisponible', 'gires-cicd-tools')];
        }
        if (!$this->has_ssh_config()) {
            return ['success' => false, 'message' => __('SSH non configuré pour sync médias différentielle.', 'gires-cicd-tools')];
        }

        $settings = $this->settings->get_all();
        $host = trim((string) ($settings['ssh_host'] ?? ''));
        $user = trim((string) ($settings['ssh_user'] ?? ''));
        $path = rtrim((string) ($settings['ssh_path'] ?? ''), '/');
        if ($host === '' || $user === '' || $path === '') {
            return ['success' => false, 'message' => __('SSH incomplet pour sync médias différentielle.', 'gires-cicd-tools')];
        }

        $suffix = sanitize_key($set['id'] ?? 'remote');
        if ($suffix === '') {
            $suffix = 'remote';
        }

        $local_uploads = Sync::uploads_dir();
        $remote_uploads = $path . '/wp-content/uploads';
        $remote_tmp = $path . '/wp-content/upload_tmp_' . $suffix;
        $remote_target = $user . '@' . $host;

        $prepare = implode(' && ', [
            'mkdir -p ' . escapeshellarg($path . '/wp-content'),
            'mkdir -p ' . escapeshellarg($remote_uploads),
            'rm -rf ' . escapeshellarg($remote_tmp),
            'cp -a ' . escapeshellarg($remote_uploads) . ' ' . escapeshellarg($remote_tmp),
        ]);
        $ssh_base = 'ssh -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        $prepare_cmd = $ssh_base . ' ' . escapeshellarg($remote_target) . ' ' . escapeshellarg($prepare) . ' 2>&1';

        $out = [];
        $code = 0;
        exec($prepare_cmd, $out, $code);
        if ($code !== 0) {
            return [
                'success' => false,
                'message' => trim(implode("\n", $out)) ?: __('Préparation upload_tmp distante impossible', 'gires-cicd-tools'),
            ];
        }

        $rsync_ssh = 'ssh -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        $rsync_cmd = implode(' ', [
            'rsync',
            '-az',
            '--delete',
            '--exclude=' . escapeshellarg('gires-cicd/'),
            '--exclude=' . escapeshellarg('tmp_upload*/'),
            '--exclude=' . escapeshellarg('bak_upload*/'),
            '--exclude=' . escapeshellarg('upload_tmp*/'),
            '--exclude=' . escapeshellarg('upload_bak*/'),
            '-e',
            escapeshellarg($rsync_ssh),
            escapeshellarg(rtrim($local_uploads, '/') . '/'),
            escapeshellarg($remote_target . ':' . rtrim($remote_tmp, '/') . '/'),
        ]) . ' 2>&1';

        $out = [];
        $code = 0;
        exec($rsync_cmd, $out, $code);
        if ($code !== 0) {
            return [
                'success' => false,
                'message' => trim(implode("\n", $out)) ?: __('Rsync médias différentiel échoué', 'gires-cicd-tools'),
            ];
        }

        return ['success' => true];
    }

    private function pull_media_diff_from_remote(array $set): array {
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => __('exec() indisponible', 'gires-cicd-tools')];
        }
        if (!$this->has_ssh_config()) {
            return ['success' => false, 'message' => __('SSH non configuré pour sync médias différentielle.', 'gires-cicd-tools')];
        }

        $settings = $this->settings->get_all();
        $host = trim((string) ($settings['ssh_host'] ?? ''));
        $user = trim((string) ($settings['ssh_user'] ?? ''));
        $path = rtrim((string) ($settings['ssh_path'] ?? ''), '/');
        if ($host === '' || $user === '' || $path === '') {
            return ['success' => false, 'message' => __('SSH incomplet pour sync médias différentielle.', 'gires-cicd-tools')];
        }

        $suffix = sanitize_key($set['id'] ?? 'local');
        if ($suffix === '') {
            $suffix = 'local';
        }

        $local_uploads = Sync::uploads_dir();
        $local_tmp = Sync::tmp_uploads_dir($suffix);
        $remote_uploads = $path . '/wp-content/uploads';
        $remote_target = $user . '@' . $host;

        // Seed local temp from live uploads to enable differential transfer
        $seed_cmd = implode(' && ', [
            'rm -rf ' . escapeshellarg($local_tmp),
            'mkdir -p ' . escapeshellarg(dirname($local_tmp)),
            'cp -a ' . escapeshellarg($local_uploads) . ' ' . escapeshellarg($local_tmp),
        ]) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($seed_cmd, $out, $code);
        if ($code !== 0) {
            return [
                'success' => false,
                'message' => trim(implode("\n", $out)) ?: __('Préparation upload_tmp locale impossible', 'gires-cicd-tools'),
            ];
        }

        $rsync_ssh = 'ssh -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
        $rsync_cmd = implode(' ', [
            'rsync',
            '-az',
            '--delete',
            '--exclude=' . escapeshellarg('gires-cicd/'),
            '--exclude=' . escapeshellarg('tmp_upload*/'),
            '--exclude=' . escapeshellarg('bak_upload*/'),
            '--exclude=' . escapeshellarg('upload_tmp*/'),
            '--exclude=' . escapeshellarg('upload_bak*/'),
            '-e',
            escapeshellarg($rsync_ssh),
            escapeshellarg($remote_target . ':' . rtrim($remote_uploads, '/') . '/'),
            escapeshellarg(rtrim($local_tmp, '/') . '/'),
        ]) . ' 2>&1';

        $out = [];
        $code = 0;
        exec($rsync_cmd, $out, $code);
        if ($code !== 0) {
            return [
                'success' => false,
                'message' => trim(implode("\n", $out)) ?: __('Rsync médias différentiel échoué', 'gires-cicd-tools'),
            ];
        }

        return ['success' => true];
    }

    private function run_sync_script(string $type, bool $skip_db, bool $skip_uploads): array {
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => __('exec() indisponible', 'gires-cicd-tools')];
        }

        $settings = $this->settings->get_all();
        if (empty($settings['ssh_host']) || empty($settings['ssh_user']) || empty($settings['ssh_path'])) {
            return ['success' => false, 'message' => __('SSH non configuré (host/user/path).', 'gires-cicd-tools')];
        }

        $scripts = new Scripts($this->settings);
        if (!$scripts->generate(true, true)) {
            return ['success' => false, 'message' => __('Impossible de générer les scripts.', 'gires-cicd-tools')];
        }

        $script_path = ABSPATH . 'scripts/sync_' . $type . '.sh';
        if (!is_file($script_path)) {
            return ['success' => false, 'message' => __('Script introuvable.', 'gires-cicd-tools')];
        }

        $env = [
            'SYNC_HOST' => $settings['ssh_host'],
            'SYNC_USER' => $settings['ssh_user'],
            'SYNC_PATH' => $settings['ssh_path'],
        ];
        if (!$skip_db) {
            $env['DB_NAME'] = $settings['db_name'] ?? '';
            $env['DB_USER'] = $settings['db_user'] ?? '';
            $env['DB_PASS'] = $settings['db_pass'] ?? '';
            $env['DB_HOST'] = $settings['db_host'] ?? 'localhost';
        }

        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= $key . '=' . escapeshellarg((string) $value) . ' ';
        }
        if ($skip_db) {
            $prefix .= 'SKIP_DB=1 ';
        }
        if ($skip_uploads) {
            $prefix .= 'SKIP_UPLOADS=1 ';
        }
        $prefix .= 'RUN_SMOKE_AFTER_SYNC=0 ';

        $cmd = $prefix . '/bin/bash ' . escapeshellarg($script_path) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $out = trim(implode("\n", $output));
        if ($code !== 0) {
            $this->log('run_sync_script: failed', [
                'type' => $type,
                'code' => $code,
                'output' => $out,
            ]);
            return ['success' => false, 'message' => $out ?: __('Erreur script sync', 'gires-cicd-tools')];
        }
        if ($out !== '') {
            $this->log('run_sync_script: output', [
                'type' => $type,
                'output' => $out,
            ]);
        }
        return ['success' => true, 'message' => __('Sync code OK', 'gires-cicd-tools')];
    }

    private function run_local_smoke_tests(bool $dry_run): array {
        $settings = $this->settings->get_all();
        if (empty($settings['smoke_after_sync'])) {
            return ['success' => true, 'message' => __('Smoke tests ignorés (désactivés)', 'gires-cicd-tools')];
        }

        $script = ABSPATH . 'scripts/smoke_wp.sh';
        if (!is_file($script) || !is_executable($script)) {
            return ['success' => false, 'message' => __('Script smoke introuvable ou non exécutable', 'gires-cicd-tools')];
        }

        $strict = !empty($settings['smoke_strict']);
        $base_url = home_url('/');
        $prefix = '';
        $prefix .= 'BASE_URL=' . escapeshellarg((string) $base_url) . ' ';
        $prefix .= 'WP_PATH=' . escapeshellarg((string) ABSPATH) . ' ';
        $prefix .= 'SKIP_LOGIN=1 ';
        $prefix .= 'REQUIRED_PLUGINS=' . escapeshellarg('gires-cicd-tools') . ' ';

        $cmd = $prefix . '/bin/bash ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $out = trim(implode("\n", $output));

        if ($code !== 0) {
            $summary = __('Smoke tests KO', 'gires-cicd-tools');
            if ($dry_run) {
                $summary = __('Smoke tests KO (dry-run)', 'gires-cicd-tools');
            }
            if ($strict) {
                return ['success' => false, 'message' => $summary . ($out !== '' ? ': ' . $out : '')];
            }
            $this->log('run_local_smoke_tests: non-strict failure', [
                'dry_run' => $dry_run,
                'output' => $out,
            ]);
            return ['success' => true, 'message' => $summary . ' - ' . __('non bloquant', 'gires-cicd-tools')];
        }

        return ['success' => true, 'message' => __('Smoke tests OK', 'gires-cicd-tools')];
    }

    private function tail_file(string $path, int $lines, string $filter = ''): string {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return '';
        }

        $buffer = '';
        $chunk = 4096;
        $pos = -1;
        $line_count = 0;
        $stat = fstat($fp);
        $size = $stat['size'] ?? 0;

        while ($size > 0 && $line_count <= $lines) {
            $read_size = min($chunk, $size);
            $size -= $read_size;
            fseek($fp, $size);
            $data = fread($fp, $read_size);
            if ($data === false) {
                break;
            }
            $buffer = $data . $buffer;
            $line_count = substr_count($buffer, "\n");
        }
        fclose($fp);

        $all = preg_split("/\r?\n/", trim($buffer));
        if (!is_array($all)) {
            return '';
        }
        $all = array_slice($all, -$lines);
        if ($filter !== '') {
            $all = array_values(array_filter($all, function ($line) use ($filter) {
                return stripos($line, $filter) !== false;
            }));
        }
        return implode("\n", $all);
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
        $remote_guard = $this->validate_remote_target($settings);
        if (empty($remote_guard['success'])) {
            return ['success' => false, 'message' => $remote_guard['message'] ?? __('URL distante invalide', 'gires-cicd-tools')];
        }
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            $this->log('remote_request: missing remote_url');
            return ['success' => false, 'message' => __('URL distante manquante', 'gires-cicd-tools')];
        }
        $url = $remote . $path;
        $body = wp_json_encode($payload);
        $response = $this->signed_request($method, $url, $body, $settings);
        if (is_wp_error($response)) {
            $this->log('remote_request: wp_error', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            if (($status === 401 || $status === 403) && ($data['code'] ?? '') === 'rest_forbidden') {
                $data['message'] = __("Autorisation REST refusée. Vérifie l'activation REST, Token/HMAC, et l'IP autorisée sur la prod.", 'gires-cicd-tools');
            }
            if (empty($data['success']) && isset($data['message'])) {
                $this->log('remote_request: remote error', [
                    'url' => $url,
                    'status' => $status,
                    'message' => $data['message'],
                    'code' => $data['code'] ?? '',
                ]);
            }
            return $data;
        }
        $this->log('remote_request: invalid response', [
            'url' => $url,
            'status' => $status,
            'body' => substr((string) $raw, 0, 200),
        ]);
        return ['success' => false, 'message' => __('Réponse distante invalide', 'gires-cicd-tools')];
    }

    private function remote_request_raw($method, $path, $body, $content_type = 'application/octet-stream') {
        $settings = $this->settings->get_all();
        $remote_guard = $this->validate_remote_target($settings);
        if (empty($remote_guard['success'])) {
            return ['success' => false, 'message' => $remote_guard['message'] ?? __('URL distante invalide', 'gires-cicd-tools')];
        }
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            $this->log('remote_request_raw: missing remote_url');
            return ['success' => false, 'message' => __('URL distante manquante', 'gires-cicd-tools')];
        }

        $url = $remote . $path;
        $token = $settings['rest_token'] ?? '';
        $hmac = $settings['rest_hmac_secret'] ?? '';
        if (empty($token) || empty($hmac)) {
            $this->log('remote_request_raw: missing token/hmac');
            return ['success' => false, 'message' => __('Token/HMAC manquant', 'gires-cicd-tools')];
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
            'timeout' => 180,
            'connect_timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            $this->log('remote_request_raw: wp_error', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (is_array($data) && empty($data['success']) && isset($data['message'])) {
            if (($status === 401 || $status === 403) && ($data['code'] ?? '') === 'rest_forbidden') {
                $data['message'] = __("Autorisation REST refusée. Vérifie l'activation REST, Token/HMAC, et l'IP autorisée sur la prod.", 'gires-cicd-tools');
            }
            $this->log('remote_request_raw: remote error', [
                'url' => $url,
                'status' => $status,
                'message' => $data['message'],
                'code' => $data['code'] ?? '',
            ]);
        }
        return is_array($data) ? $data : ['success' => true];
    }

    private function remote_download($path) {
        $settings = $this->settings->get_all();
        $remote_guard = $this->validate_remote_target($settings);
        if (empty($remote_guard['success'])) {
            $this->log('remote_download: invalid remote target', [
                'message' => $remote_guard['message'] ?? '',
                'remote_url' => $settings['remote_url'] ?? '',
                'home_url' => home_url('/'),
            ]);
            return false;
        }
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            $this->log('remote_download: missing remote_url');
            return false;
        }
        $url = $remote . $path;
        $response = $this->signed_request('GET', $url, '', $settings);
        if (is_wp_error($response)) {
            $this->log('remote_download: wp_error', [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        return wp_remote_retrieve_body($response);
    }

    private function test_remote_connection(array $settings) {
        $status = $this->fetch_remote_status($settings);
        if (empty($status['success'])) {
            $settings['rest_last_connection_ok'] = false;
            $this->settings->update($settings);
            return ['success' => false, 'message' => $status['message'] ?? __('Connexion impossible', 'gires-cicd-tools')];
        }

        $body = $status['body'] ?? [];
        $remote_ip = $body['remote_ip'] ?? '';
        $remote_site = $body['site_url'] ?? '';
        $remote_pending = (int) ($body['pending_count'] ?? 0);
        $remote_plugin_version = (string) ($body['plugin_version'] ?? '');

        $settings['rest_last_connection_ok'] = true;
        $settings['remote_ip'] = $remote_ip;
        $settings['remote_site_url'] = $remote_site;
        $settings['remote_pending_count'] = $remote_pending;
        $settings['remote_plugin_version'] = $remote_plugin_version;
        $this->settings->update($settings);

        return [
            'success' => true,
            'remote_ip' => $remote_ip,
            'remote_site_url' => $remote_site,
            'remote_pending_count' => $remote_pending,
            'remote_plugin_version' => $remote_plugin_version,
            'local_plugin_version' => defined('GIRES_CICD_VERSION') ? GIRES_CICD_VERSION : '',
        ];
    }

    private function ensure_remote_plugin_compatible(array $settings) {
        $status = $this->fetch_remote_status($settings);
        if (empty($status['success'])) {
            return ['success' => false, 'message' => $status['message'] ?? __('Connexion distante impossible', 'gires-cicd-tools')];
        }

        $body = $status['body'] ?? [];
        $remote_version = (string) ($body['plugin_version'] ?? '');
        $local_version = defined('GIRES_CICD_VERSION') ? (string) GIRES_CICD_VERSION : '';

        if ($remote_version === '' || $local_version === '') {
            return ['success' => false, 'message' => __('Version plugin introuvable (locale ou distante).', 'gires-cicd-tools')];
        }
        if ($remote_version !== $local_version) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Version plugin differente. Local: %1$s / Distant: %2$s. Mets la meme version des deux cotes.', 'gires-cicd-tools'),
                    $local_version,
                    $remote_version
                ),
            ];
        }
        return ['success' => true];
    }

    private function fetch_remote_status(array $settings) {
        $remote_guard = $this->validate_remote_target($settings);
        if (empty($remote_guard['success'])) {
            return ['success' => false, 'message' => $remote_guard['message'] ?? __('URL distante invalide', 'gires-cicd-tools')];
        }
        $remote = rtrim($settings['remote_url'] ?? '', '/');
        if (empty($remote)) {
            return ['success' => false, 'message' => __('URL distante manquante', 'gires-cicd-tools')];
        }
        if (empty($settings['rest_token']) || empty($settings['rest_hmac_secret'])) {
            return ['success' => false, 'message' => __('Token ou HMAC manquant', 'gires-cicd-tools')];
        }

        $endpoint = $remote . '/wp-json/gires-cicd/v1/status';
        $response = $this->signed_request('GET', $endpoint, '', $settings);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        if ($status_code >= 400) {
            $remote_message = '';
            if (is_array($body)) {
                $remote_message = (string) ($body['message'] ?? $body['code'] ?? '');
            }
            if ($remote_message === '') {
                $remote_message = trim(wp_strip_all_tags($raw_body));
                if ($remote_message !== '') {
                    $remote_message = substr($remote_message, 0, 220);
                }
            }
            $message = sprintf(__('Status distant HTTP %d', 'gires-cicd-tools'), $status_code);
            if ($remote_message !== '') {
                $message .= ' - ' . $remote_message;
            }
            return ['success' => false, 'message' => $message];
        }

        if (!is_array($body)) {
            return ['success' => false, 'message' => __('Reponse status distante invalide', 'gires-cicd-tools')];
        }
        if (!empty($body['code']) && empty($body['plugin_version'])) {
            $remote_message = (string) ($body['message'] ?? $body['code']);
            return ['success' => false, 'message' => __('Status distant invalide: ', 'gires-cicd-tools') . $remote_message];
        }

        return ['success' => true, 'body' => $body];
    }

    private function validate_remote_target(array $settings): array {
        $remote = trim((string) ($settings['remote_url'] ?? ''));
        if ($remote === '') {
            return ['success' => false, 'message' => __('URL distante manquante', 'gires-cicd-tools')];
        }

        $remote_norm = rtrim($remote, '/');
        $home_norm = rtrim((string) home_url('/'), '/');
        if ($remote_norm === $home_norm) {
            return [
                'success' => false,
                'message' => __('URL distante invalide: elle pointe vers ce site local. Configure l’URL de production.', 'gires-cicd-tools'),
            ];
        }

        $remote_host = strtolower((string) (wp_parse_url($remote, PHP_URL_HOST) ?? ''));
        $home_host = strtolower((string) (wp_parse_url(home_url('/'), PHP_URL_HOST) ?? ''));
        if ($remote_host !== '' && $remote_host === $home_host) {
            return [
                'success' => false,
                'message' => __('URL distante invalide: host identique au site local. Configure un host de production.', 'gires-cicd-tools'),
            ];
        }

        return ['success' => true];
    }
}
