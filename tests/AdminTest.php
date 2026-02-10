<?php

use PHPUnit\Framework\TestCase;
use GiresCICD\Settings;
use GiresCICD\Migrations;
use GiresCICD\Replication;
use GiresCICD\Admin;

final class AdminTest extends TestCase {
    private $admin;
    private $settings;

    protected function setUp(): void {
        $this->settings = new Settings();
        $migrations = new Migrations($this->settings);
        $replication = new Replication($this->settings);
        $this->admin = new Admin($this->settings, $migrations, $replication);

        $base = Settings::defaults();
        $base['remote_url'] = 'https://prod.example';
        $base['rest_token'] = 'token';
        $base['rest_hmac_secret'] = 'hmac';
        update_option('gires_cicd_settings', $base);
    }

    public function testBuildSettingsPreservesRemoteUrlWhenMissing(): void {
        $method = new ReflectionMethod(Admin::class, 'build_settings_from_request');
        $method->setAccessible(true);

        $request = [
            'rest_enabled' => '1',
        ];
        $result = $method->invoke($this->admin, $request);
        $this->assertSame('https://prod.example', $result['remote_url']);
        $this->assertSame('token', $result['rest_token']);
        $this->assertSame('hmac', $result['rest_hmac_secret']);
    }

    public function testNormalizeReplicationSetsGeneratesId(): void {
        $method = new ReflectionMethod(Admin::class, 'normalize_replication_sets');
        $method->setAccessible(true);

        $sets = [
            ['name' => 'Ma replication 3 PULL du jeudi', 'type' => 'pull', 'tables' => []],
        ];
        $normalized = $method->invoke($this->admin, $sets);
        $this->assertNotEmpty($normalized[0]['id']);
    }

    public function testNormalizeSqlPayloadDecodesJsonString(): void {
        $method = new ReflectionMethod(Admin::class, 'normalize_sql_payload');
        $method->setAccessible(true);

        $raw = "SELECT 1;\n";
        $json = json_encode($raw);

        $decoded = $method->invoke($this->admin, $json);
        $this->assertSame($raw, $decoded);

        $plain = $method->invoke($this->admin, $raw);
        $this->assertSame($raw, $plain);
    }
}
