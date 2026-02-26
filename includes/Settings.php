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
            'remote_plugin_version' => '',
            'remote_ip' => '',
            'remote_url' => '',
            'ssh_host' => '',
            'ssh_user' => '',
            'ssh_path' => '',
            'db_name' => '',
            'db_user' => '',
            'db_pass' => '',
            'db_host' => 'localhost',
            'smoke_after_sync' => true,
            'smoke_strict' => false,
            'rsync_excludes' => "wp-config.php
.git/
.gitmodules
Documentation/
scripts/
backups/
vendor/
Telechargements/
Téléchargements/",
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
        $all = array_merge(self::defaults(), get_option($this->option_name, []));
        $must_persist = false;

        if (trim((string) ($all['rsync_excludes'] ?? '')) === '') {
            $all['rsync_excludes'] = self::defaults()['rsync_excludes'];
            $must_persist = true;
        }
        if (!isset($all['replication_sets']) || !is_array($all['replication_sets']) || count($all['replication_sets']) === 0) {
            $all['replication_sets'] = self::defaults()['replication_sets'];
            $must_persist = true;
        }

        $map = [
            'ssh_host' => 'GIRES_CICD_SSH_HOST',
            'ssh_user' => 'GIRES_CICD_SSH_USER',
            'ssh_path' => 'GIRES_CICD_SSH_PATH',
            'db_name' => 'GIRES_CICD_DB_NAME',
            'db_user' => 'GIRES_CICD_DB_USER',
            'db_pass' => 'GIRES_CICD_DB_PASS',
            'db_host' => 'GIRES_CICD_DB_HOST',
        ];
        foreach ($map as $key => $const) {
            if (defined($const) && constant($const) !== '') {
                $all[$key] = constant($const);
            }
        }

        if ($must_persist) {
            update_option($this->option_name, $all);
        }

        return $all;
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
