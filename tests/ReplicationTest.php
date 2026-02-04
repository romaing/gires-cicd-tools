<?php

use PHPUnit\Framework\TestCase;
use GiresCICD\Settings;
use GiresCICD\Replication;

final class ReplicationTest extends TestCase {
    private $settings;
    private $replication;
    private $wpdb;

    protected function setUp(): void {
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->settings = new Settings();
        $this->replication = new Replication($this->settings);
    }

    public function testExportSearchReplaceOnlySelectedTables(): void {
        $set = [
            'tables' => ['wp_posts', 'wp_options'],
        ];

        $override = [
            'search' => ['http://old.local'],
            'replace' => ['https://prod.example'],
            'search_only_tables' => ['wp_posts'],
        ];

        $sql = $this->replication->export_sql($set, $override);

        $this->assertStringContainsString('https://prod.example', $sql);
        $this->assertStringContainsString('http://old.local', $sql, 'options table should keep old value');
    }

    public function testImportSqlSkipsRenameWhenOptionSet(): void {
        $set = [
            'tables' => ['wp_posts'],
            'temp_prefix' => 'tmp_',
        ];
        $sql = "CREATE TABLE `wp_posts` (id INT);\nINSERT INTO `wp_posts` (id) VALUES (1);";
        $result = $this->replication->import_sql($sql, $set, ['skip_rename' => true, 'temp_prefix' => 'tmp_']);

        $this->assertTrue($result['success']);
        $this->assertFalse($this->wpdb->sawRename, 'rename should not be executed when skip_rename is true');
    }
}

final class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $sawRename = false;

    public function get_col($query) {
        if (stripos($query, 'SHOW TABLES') !== false) {
            return ['wp_posts', 'wp_options'];
        }
        return [];
    }

    public function get_row($query, $output = null) {
        if (stripos($query, 'SHOW CREATE TABLE') !== false) {
            if (strpos($query, 'wp_posts') !== false) {
                return ['Create Table' => 'CREATE TABLE `wp_posts` (id INT)'];
            }
            if (strpos($query, 'wp_options') !== false) {
                return ['Create Table' => 'CREATE TABLE `wp_options` (option_name VARCHAR(191), option_value LONGTEXT)'];
            }
        }
        return null;
    }

    public function get_results($query, $output = null) {
        if (strpos($query, 'FROM `wp_posts`') !== false) {
            return [
                ['id' => 1, 'post_content' => 'http://old.local/page'],
            ];
        }
        if (strpos($query, 'FROM `wp_options`') !== false) {
            return [
                ['option_name' => 'siteurl', 'option_value' => 'http://old.local'],
            ];
        }
        return [];
    }

    public function query($statement) {
        if (stripos($statement, 'RENAME TABLE') !== false) {
            $this->sawRename = true;
        }
        return true;
    }
}
