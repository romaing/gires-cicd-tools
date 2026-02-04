<?php

namespace GiresCICD;

class Settings {
    private $option_name = 'gires_cicd_settings';

    public static function defaults() {
        return [
            'migrations_path' => ABSPATH . 'migrations',
            'applied_option' => 'gires_project_migrations',
            'rest_enabled' => false,
            'rest_token' => '',
            'rest_hmac_secret' => '',
            'rest_allowlist' => '',
            'rest_last_connection_ok' => false,
            'remote_site_url' => '',
            'remote_pending_count' => 0,
            'remote_ip' => '',
            'remote_url' => '',
            'ssh_host' => '',
            'ssh_user' => '',
            'ssh_path' => '',
            'db_name' => '',
            'db_user' => '',
            'db_pass' => '',
            'db_host' => 'localhost',
            'replication_sets' => [
                [
                    'id' => 'pull_prod',
                    'name' => 'Pull prod',
                    'type' => 'pull',
                    'tables' => [],
                    'search' => [
                        'https://gires.conseil.digital',
                    ],
                    'replace' => [
                        'http://localhost:8080',
                    ],
                    'include_media' => true,
                    'media_chunk_mb' => 512,
                    'temp_prefix' => 'tmp_',
                    'backup_prefix' => 'bak_',
                    'auto_cleanup' => true,
                ],
                [
                    'id' => 'push_prod',
                    'name' => 'Push prod',
                    'type' => 'push',
                    'tables' => [],
                    'search' => [
                        'http://localhost:8080',
                    ],
                    'replace' => [
                        'https://gires.conseil.digital',
                    ],
                    'include_media' => true,
                    'media_chunk_mb' => 512,
                    'temp_prefix' => 'tmp_',
                    'backup_prefix' => 'bak_',
                    'auto_cleanup' => true,
                ],
            ],
        ];
    }

    public static function ensure_defaults() {
        if (!get_option('gires_cicd_settings')) {
            update_option('gires_cicd_settings', self::defaults());
        }
    }

    public function get_all() {
        return array_merge(self::defaults(), get_option($this->option_name, []));
    }

    public function get($key, $default = null) {
        $all = $this->get_all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function update($data) {
        $current = $this->get_all();
        $merged = array_merge($current, $data);
        update_option($this->option_name, $merged);
        return $merged;
    }
}
