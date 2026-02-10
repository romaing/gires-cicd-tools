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

    public function testSplitSqlHandlesSemicolonsInStrings(): void {
        $method = new ReflectionMethod(Replication::class, 'split_sql');
        $method->setAccessible(true);
        $sql = "INSERT INTO `wp_posts` VALUES ('a; b');\nSELECT 1;";
        $statements = $method->invoke($this->replication, $sql);
        $this->assertCount(2, $statements);
    }

    public function testApplySearchReplaceWithSerializedData(): void {
        $method = new ReflectionMethod(Replication::class, 'apply_search_replace');
        $method->setAccessible(true);
        $data = serialize(['url' => 'http://old.local']);
        $result = $method->invoke($this->replication, $data, ['http://old.local'], ['https://prod.example']);
        $this->assertStringContainsString('https://prod.example', $result);
    }

    public function testExportSqlChunksInserts(): void {
        $wpdb = new FakeWpdbChunk();
        $GLOBALS['wpdb'] = $wpdb;
        $replication = new Replication($this->settings);

        $set = ['tables' => ['wp_posts']];
        $sql = $replication->export_sql($set, ['insert_chunk_size' => 2]);

        $this->assertSame(3, substr_count($sql, 'INSERT INTO `wp_posts`'));
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

final class FakeWpdbChunk {
    public $prefix = 'wp_';
    public $last_error = '';

    public function get_col($query) {
        return ['wp_posts'];
    }

    public function get_row($query, $output = null) {
        return ['Create Table' => 'CREATE TABLE `wp_posts` (id INT, post_content TEXT)'];
    }

    public function get_results($query, $output = null) {
        return [
            ['id' => 1, 'post_content' => 'a'],
            ['id' => 2, 'post_content' => 'b'],
            ['id' => 3, 'post_content' => 'c'],
            ['id' => 4, 'post_content' => 'd'],
            ['id' => 5, 'post_content' => 'e'],
        ];
    }

    public function query($statement) {
        return true;
    }
}
