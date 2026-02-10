<?php

use PHPUnit\Framework\TestCase;
use GiresCICD\Settings;
use GiresCICD\Migrations;

final class MigrationsTest extends TestCase {
    private $settings;
    private $migrations;
    private $wpdb;
    private $dir;

    protected function setUp(): void {
        $GLOBALS['gires_cicd_options'] = [];
        $this->wpdb = new FakeWpdbMigrations();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->dir = sys_get_temp_dir() . '/gires_cicd_migrations_' . uniqid();
        mkdir($this->dir, 0777, true);

        update_option('gires_cicd_settings', [
            'migrations_path' => $this->dir,
            'applied_option' => 'gires_project_migrations',
        ]);

        $this->settings = new Settings();
        $this->migrations = new Migrations($this->settings);
    }

    protected function tearDown(): void {
        $this->deleteDir($this->dir);
    }

    public function testApplyAllRunsSqlAndPhpMigrations(): void {
        $sqlFile = $this->dir . '/20260210_0001_test.sql';
        $phpFile = $this->dir . '/20260210_0002_test.php';

        file_put_contents($sqlFile, "CREATE TABLE wp_test (id INT);\n");
        file_put_contents($phpFile, "<?php\nreturn function () { update_option('gires_cicd_migration_test_unit', 'ok'); };\n");

        $result = $this->migrations->apply_all();

        $this->assertEmpty($result['errors']);
        $this->assertContains('20260210_0001_test.sql', $result['applied']);
        $this->assertContains('20260210_0002_test.php', $result['applied']);
        $this->assertSame('ok', get_option('gires_cicd_migration_test_unit'));
        $this->assertNotEmpty($this->wpdb->queries);

        $pending = $this->migrations->get_pending();
        $this->assertCount(0, $pending);
    }

    private function deleteDir(string $dir): void {
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
                $this->deleteDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

final class FakeWpdbMigrations {
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = [];

    public function query($statement) {
        $this->queries[] = $statement;
        return true;
    }
}
