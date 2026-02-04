<?php

use PHPUnit\Framework\TestCase;
use GiresCICD\Settings;

final class SettingsTest extends TestCase {
    public function testDefaultsContainReplicationSets(): void {
        $defaults = Settings::defaults();
        $this->assertArrayHasKey('replication_sets', $defaults);
        $this->assertNotEmpty($defaults['replication_sets']);
    }
}
