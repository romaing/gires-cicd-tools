<?php

namespace GiresCICD;

class Sync {
    public static function set_maintenance($enabled) {
        $file = ABSPATH . '.maintenance';
        if ($enabled) {
            $content = "<?php \$upgrading = " . time() . "; ?>";
            @file_put_contents($file, $content);
            return true;
        }
        if (is_file($file)) {
            return @unlink($file);
        }
        return true;
    }

    public static function uploads_dir() {
        $upload = wp_upload_dir();
        return rtrim($upload['basedir'], '/');
    }

    public static function tmp_uploads_dir($suffix = '') {
        $base = dirname(self::uploads_dir());
        $dir = rtrim($base, '/') . '/upload_tmp' . ($suffix ? '_' . $suffix : '');
        return $dir;
    }

    public static function bak_uploads_dir($suffix = '') {
        $base = dirname(self::uploads_dir());
        $dir = rtrim($base, '/') . '/upload_bak' . ($suffix ? '_' . $suffix : '');
        return $dir;
    }

    public static function ensure_dir($dir) {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return is_dir($dir) && is_writable($dir);
    }

    public static function swap_tables(array $tables, $temp_prefix, $backup_prefix) {
        global $wpdb;

        foreach ($tables as $table) {
            $live = $table;
            $tmp = $temp_prefix . $table;
            $bak = $backup_prefix . $table;

            if ($live === $tmp) {
                continue;
            }

            $tmp_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tmp));
            if (!$tmp_exists) {
                continue;
            }

            $live_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $live));
            $wpdb->query("DROP TABLE IF EXISTS `{$bak}`");
            if ($live_exists) {
                $wpdb->query("RENAME TABLE `{$live}` TO `{$bak}`");
            }
            $wpdb->query("RENAME TABLE `{$tmp}` TO `{$live}`");
        }
        return true;
    }

    public static function cleanup_tables(array $tables, $temp_prefix, $backup_prefix) {
        global $wpdb;
        foreach ($tables as $table) {
            $tmp = $temp_prefix . $table;
            $bak = $backup_prefix . $table;
            $wpdb->query("DROP TABLE IF EXISTS `{$tmp}`");
            $wpdb->query("DROP TABLE IF EXISTS `{$bak}`");
        }
        return true;
    }

    public static function swap_uploads($tmp_dir, $bak_dir, $uploads_dir) {
        if (!is_dir($tmp_dir)) {
            return ['success' => false, 'message' => 'tmp_upload introuvable'];
        }

        if (is_dir($bak_dir)) {
            self::rrmdir($bak_dir);
        }

        if (is_dir($uploads_dir)) {
            @rename($uploads_dir, $bak_dir);
        }
        @rename($tmp_dir, $uploads_dir);
        return ['success' => true];
    }

    public static function cleanup_uploads($tmp_dir, $bak_dir) {
        if (is_dir($tmp_dir)) {
            self::rrmdir($tmp_dir);
        }
        if (is_dir($bak_dir)) {
            self::rrmdir($bak_dir);
        }
        return true;
    }

    public static function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!$items) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public static function create_media_archives($uploads_dir, $dest_dir, $max_mb = 512) {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'ZipArchive indisponible'];
        }

        if (!self::ensure_dir($dest_dir)) {
            return ['success' => false, 'message' => 'Impossible de créer le dossier zip'];
        }

        $max_bytes = max(1, (int) $max_mb) * 1024 * 1024;
        $token = bin2hex(random_bytes(8));
        $archives = [];
        $part = 1;
        $current_size = 0;
        $zip = null;
        $zip_path = '';

        $skip_dirs = [
            rtrim($uploads_dir, '/') . '/gires-cicd',
            rtrim($uploads_dir, '/') . '/tmp_upload',
            rtrim($uploads_dir, '/') . '/bak_upload',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($uploads_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $file_path = $file->getPathname();
            foreach ($skip_dirs as $skip) {
                if (strpos($file_path, $skip) === 0) {
                    continue 2;
                }
            }
            $rel_path = ltrim(str_replace($uploads_dir, '', $file_path), '/');
            $file_size = $file->getSize();

            if (!$zip || ($current_size + $file_size) > $max_bytes) {
                if ($zip) {
                    $zip->close();
                }
                $zip_path = $dest_dir . '/uploads_' . $token . '_part' . $part . '.zip';
                $zip = new \ZipArchive();
                if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    return ['success' => false, 'message' => 'Impossible de créer le zip'];
                }
                $archives[] = $zip_path;
                $part++;
                $current_size = 0;
            }

            $zip->addFile($file_path, $rel_path);
            $current_size += $file_size;
        }

        if ($zip) {
            $zip->close();
        }

        return ['success' => true, 'token' => $token, 'archives' => $archives];
    }

    public static function unzip_archive($zip_path, $dest_dir) {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'ZipArchive indisponible'];
        }
        if (!self::ensure_dir($dest_dir)) {
            return ['success' => false, 'message' => 'Impossible de créer le dossier tmp_upload'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return ['success' => false, 'message' => 'Impossible d’ouvrir le zip'];
        }
        $zip->extractTo($dest_dir);
        $zip->close();
        return ['success' => true];
    }
}
