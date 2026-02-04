<?php

namespace GiresCICD;

class Migrations {
    private $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function get_migrations_dir() {
        $path = $this->settings->get('migrations_path');
        return rtrim($path, '/');
    }

    public function list_files() {
        $dir = $this->get_migrations_dir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.{sql,php}', GLOB_BRACE);
        if (!$files) {
            return [];
        }
        usort($files, function ($a, $b) {
            return strcmp(basename($a), basename($b));
        });
        return $files;
    }

    public function get_applied() {
        $option_name = $this->settings->get('applied_option', 'gires_project_migrations');
        $value = get_option($option_name, []);
        if (is_array($value)) {
            return $value;
        }
        $unserialized = @unserialize($value);
        if (is_array($unserialized)) {
            return $unserialized;
        }
        $json = json_decode((string) $value, true);
        if (is_array($json)) {
            return $json;
        }
        return [];
    }

    public function get_pending() {
        $applied = $this->get_applied();
        $pending = [];
        foreach ($this->list_files() as $file) {
            $name = basename($file);
            if (!in_array($name, $applied, true)) {
                $pending[] = $file;
            }
        }
        return $pending;
    }

    public function apply_all() {
        global $wpdb;

        $pending = $this->get_pending();
        if (empty($pending)) {
            return [
                'applied' => [],
                'errors' => [],
            ];
        }

        $applied = $this->get_applied();
        $errors = [];

        foreach ($pending as $file) {
            $name = basename($file);
            $ext = pathinfo($file, PATHINFO_EXTENSION);

            if ($ext === 'sql') {
                $sql = file_get_contents($file);
                if ($sql === false) {
                    $errors[] = "Erreur lecture: {$name}";
                    continue;
                }
                $result = $wpdb->query($sql);
                if ($result === false) {
                    $errors[] = "Erreur SQL ({$name}): " . $wpdb->last_error;
                    continue;
                }
            } elseif ($ext === 'php') {
                $callable = include $file;
                if (is_callable($callable)) {
                    try {
                        $callable($wpdb, $wpdb->prefix);
                    } catch (\Throwable $e) {
                        $errors[] = "Erreur PHP ({$name}): " . $e->getMessage();
                        continue;
                    }
                }
            }

            $applied[] = $name;
            $this->store_applied($applied);
        }

        return [
            'applied' => $applied,
            'errors' => $errors,
        ];
    }

    private function store_applied(array $applied) {
        $option_name = $this->settings->get('applied_option', 'gires_project_migrations');
        update_option($option_name, array_values($applied));
    }
}
