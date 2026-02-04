<?php

use PHPUnit\Framework\TestCase;
use GiresCICD\Sync;

final class SyncTest extends TestCase {
    private $uploadsDir;

    protected function setUp(): void {
        $upload = wp_upload_dir();
        $this->uploadsDir = rtrim($upload['basedir'], '/');
        $this->cleanupDir($this->uploadsDir);
        mkdir($this->uploadsDir, 0777, true);
    }

    public function testCreateMediaArchivesSplits(): void {
        $source = $this->uploadsDir . '/test';
        mkdir($source, 0777, true);

        // create ~3 MB total in small files
        for ($i = 0; $i < 6; $i++) {
            file_put_contents($source . '/file_' . $i . '.bin', str_repeat('a', 512 * 1024));
        }

        $dest = $this->uploadsDir . '/gires-cicd';
        $result = Sync::create_media_archives($this->uploadsDir, $dest, 1);

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(1, count($result['archives']));
        foreach ($result['archives'] as $zip) {
            $this->assertFileExists($zip);
        }
    }

    public function testUnzipArchiveExtracts(): void {
        $source = $this->uploadsDir . '/src';
        mkdir($source, 0777, true);
        file_put_contents($source . '/hello.txt', 'world');

        $dest = $this->uploadsDir . '/gires-cicd';
        $result = Sync::create_media_archives($this->uploadsDir, $dest, 5);
        $this->assertTrue($result['success']);
        $zip = $result['archives'][0];

        $extract = $this->uploadsDir . '/tmp_upload_unit';
        $unz = Sync::unzip_archive($zip, $extract);
        $this->assertTrue($unz['success']);
        $this->assertFileExists($extract . '/src/hello.txt');
    }

    public function testSwapUploadsMovesFolders(): void {
        $uploads = $this->uploadsDir . '/uploads';
        $tmp = $this->uploadsDir . '/tmp_upload_test';
        $bak = $this->uploadsDir . '/bak_upload_test';
        mkdir($uploads, 0777, true);
        mkdir($tmp, 0777, true);
        file_put_contents($uploads . '/a.txt', 'live');
        file_put_contents($tmp . '/b.txt', 'tmp');

        $result = Sync::swap_uploads($tmp, $bak, $uploads);
        $this->assertTrue($result['success']);
        $this->assertFileExists($uploads . '/b.txt');
        $this->assertFileExists($bak . '/a.txt');
    }

    public function testCleanupUploadsRemovesFolders(): void {
        $tmp = $this->uploadsDir . '/tmp_upload_clean';
        $bak = $this->uploadsDir . '/bak_upload_clean';
        mkdir($tmp, 0777, true);
        mkdir($bak, 0777, true);
        file_put_contents($tmp . '/x.txt', 'x');
        file_put_contents($bak . '/y.txt', 'y');

        Sync::cleanup_uploads($tmp, $bak);
        $this->assertDirectoryDoesNotExist($tmp);
        $this->assertDirectoryDoesNotExist($bak);
    }

    private function cleanupDir(string $dir): void {
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
                $this->cleanupDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
