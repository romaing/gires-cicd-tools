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

    public function delete_file($name) {
        $name = basename((string) $name);
        if ($name === '' || !preg_match('/\.(sql|php)$/i', $name)) {
            return [
                'success' => false,
                'message' => 'Nom de migration invalide',
            ];
        }

        $dir = $this->get_migrations_dir();
        if (!is_dir($dir)) {
            return [
                'success' => false,
                'message' => 'Dossier migrations introuvable',
            ];
        }

        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            return [
                'success' => false,
                'message' => 'Fichier migration introuvable',
            ];
        }

        $real_dir = realpath($dir);
        $real_file = realpath($path);
        if ($real_dir === false || $real_file === false) {
            return [
                'success' => false,
                'message' => 'Chemin migration invalide',
            ];
        }

        $real_dir = rtrim($real_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($real_file, $real_dir) !== 0) {
            return [
                'success' => false,
                'message' => 'Accès refusé hors dossier migrations',
            ];
        }

        if (!@unlink($real_file)) {
            return [
                'success' => false,
                'message' => 'Suppression du fichier impossible',
            ];
        }

        $applied = $this->get_applied();
        if (in_array($name, $applied, true)) {
            $applied = array_values(array_filter($applied, function ($item) use ($name) {
                return $item !== $name;
            }));
            $this->store_applied($applied);
        }

        return [
            'success' => true,
        ];
    }

    private function store_applied(array $applied) {
        $option_name = $this->settings->get('applied_option', 'gires_project_migrations');
        update_option($option_name, array_values($applied));
    }
}
