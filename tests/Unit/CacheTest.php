<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private string $tmpDir;
    private Cache  $cache;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cache_test_' . uniqid();
        $this->cache  = new Cache($this->tmpDir, ttl: 60);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.json') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ── remember ──────────────────────────────────────────────────────────────

    public function testRememberCallsFetchOnMiss(): void
    {
        $calls  = 0;
        $result = $this->cache->remember('pfx', 'key', function () use (&$calls) {
            $calls++;
            return ['v' => 1];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(['v' => 1], $result);
    }

    public function testRememberReturnsCachedValueWithoutCallingFetch(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['v' => 42]; };

        $this->cache->remember('pfx', 'key', $fetch);
        $result = $this->cache->remember('pfx', 'key', $fetch);

        $this->assertSame(1, $calls);
        $this->assertSame(['v' => 42], $result);
    }

    public function testRememberStoresDataToDisk(): void
    {
        $this->cache->remember('pfx', 'key', fn() => ['stored' => true]);

        $files = glob($this->tmpDir . '/pfx_*.json');
        $this->assertCount(1, $files);
    }

    public function testRememberWithZeroTtlAlwaysCallsFetch(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $calls   = 0;
        $fetch   = function () use (&$calls) { $calls++; return []; };

        $noCache->remember('pfx', 'key', $fetch);
        $noCache->remember('pfx', 'key', $fetch);

        $this->assertSame(2, $calls);
    }

    // ── wasHit ────────────────────────────────────────────────────────────────

    public function testWasHitFalseBeforeAnyCall(): void
    {
        $this->assertFalse($this->cache->wasHit('pfx'));
    }

    public function testWasHitFalseOnFirstCall(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $this->assertFalse($this->cache->wasHit('pfx'));
    }

    public function testWasHitTrueAfterCacheHit(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $this->cache->remember('pfx', 'key', fn() => []);

        $this->assertTrue($this->cache->wasHit('pfx'));
    }

    // ── isFresh ───────────────────────────────────────────────────────────────

    public function testIsFreshFalseWhenNotCached(): void
    {
        $this->assertFalse($this->cache->isFresh('pfx', 'missing'));
    }

    public function testIsFreshTrueAfterRemember(): void
    {
        $this->cache->remember('pfx', 'key', fn() => ['x' => 1]);
        $this->assertTrue($this->cache->isFresh('pfx', 'key'));
    }

    public function testIsFreshFalseForZeroTtlCache(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $this->assertFalse($noCache->isFresh('pfx', 'key'));
    }

    // ── put ───────────────────────────────────────────────────────────────────

    public function testPutStoresValueReadableViaRemember(): void
    {
        $this->cache->put('pfx', 'key', ['direct' => true]);

        $calls  = 0;
        $result = $this->cache->remember('pfx', 'key', function () use (&$calls) {
            $calls++;
            return [];
        });

        $this->assertSame(0, $calls);
        $this->assertSame(['direct' => true], $result);
    }

    public function testPutIsNoOpForZeroTtlCache(): void
    {
        $noCache = new Cache($this->tmpDir, ttl: 0);
        $noCache->put('pfx', 'key', ['x' => 1]);

        $this->assertFalse($noCache->isFresh('pfx', 'key'));
    }

    // ── flush ─────────────────────────────────────────────────────────────────

    public function testFlushRemovesAllFiles(): void
    {
        $this->cache->remember('a', 'k1', fn() => [1]);
        $this->cache->remember('b', 'k2', fn() => [2]);

        $deleted = $this->cache->flush();

        $this->assertSame(2, $deleted);
        $this->assertEmpty(glob($this->tmpDir . '/*.json'));
    }

    public function testFlushWithPrefixRemovesOnlyMatchingFiles(): void
    {
        $this->cache->remember('aa', 'k1', fn() => []);
        $this->cache->remember('bb', 'k2', fn() => []);

        $deleted = $this->cache->flush('aa');

        $this->assertSame(1, $deleted);
        $this->assertCount(1, glob($this->tmpDir . '/*.json'));
    }

    public function testFlushOnEmptyDirReturnsZero(): void
    {
        $this->assertSame(0, $this->cache->flush());
    }

    // ── entries ───────────────────────────────────────────────────────────────

    public function testEntriesReturnsMetadataForCachedFiles(): void
    {
        $this->cache->remember('shop', 'k1', fn() => ['a']);
        $this->cache->remember('ss',   'k2', fn() => ['b']);

        $entries = $this->cache->entries();

        $this->assertCount(2, $entries);
        $prefixes = array_column($entries, 'prefix');
        $this->assertContains('shop', $prefixes);
        $this->assertContains('ss',   $prefixes);
    }

    public function testEntriesIncludesRequiredKeys(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $entry = $this->cache->entries()[0];

        $this->assertArrayHasKey('file',       $entry);
        $this->assertArrayHasKey('prefix',     $entry);
        $this->assertArrayHasKey('expires_at', $entry);
        $this->assertArrayHasKey('expired',    $entry);
        $this->assertArrayHasKey('size_kb',    $entry);
    }

    public function testEntriesFreshEntryIsNotMarkedExpired(): void
    {
        $this->cache->remember('pfx', 'key', fn() => []);
        $this->assertFalse($this->cache->entries()[0]['expired']);
    }

    public function testEntriesReturnsEmptyArrayWhenNoCachedFiles(): void
    {
        $this->assertSame([], $this->cache->entries());
    }

    // ── getTtl ────────────────────────────────────────────────────────────────

    public function testGetTtlReturnsConfiguredValue(): void
    {
        $this->assertSame(60, $this->cache->getTtl());
    }

    // ── expiry behaviour ──────────────────────────────────────────────────────

    public function testRememberBypassesExpiredEntryAndCallsFetchAgain(): void
    {
        // Pre-populate an already-expired cache file for the key
        $file = $this->tmpDir . '/pfx_' . hash('sha256', 'key') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 10, 'data' => ['old' => true]]));

        $calls  = 0;
        $result = $this->cache->remember('pfx', 'key', function () use (&$calls) {
            $calls++;
            return ['new' => true];
        });

        $this->assertSame(1, $calls);
        $this->assertSame(['new' => true], $result);
    }

    public function testExpiredFileRemainsOnDiskUntilExplicitlyPruned(): void
    {
        // Write an expired file for key A
        $fileA = $this->tmpDir . '/pfx_' . hash('sha256', 'keyA') . '.json';
        file_put_contents($fileA, json_encode(['expires_at' => time() - 10, 'data' => ['stale']]));

        // Call remember() for a completely different key — should not touch key A's file
        $this->cache->remember('pfx', 'keyB', fn() => ['fresh']);

        $this->assertTrue(file_exists($fileA), 'Expired file must stay on disk until pruneExpired() is called');
    }

    // ── pruneExpired ──────────────────────────────────────────────────────────

    public function testPruneExpiredDeletesFilesPastRetentionPeriod(): void
    {
        // Use a short retention window so we can trigger deletion without sleeping
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 60);

        $file = $this->tmpDir . '/old_' . hash('sha256', 'k') . '.json';
        // Expired 2 minutes ago — past the 60 s retention
        file_put_contents($file, json_encode(['expires_at' => time() - 120, 'data' => []]));

        $deleted = $pruneCache->pruneExpired();

        $this->assertSame(1, $deleted);
        $this->assertFalse(file_exists($file));
    }

    public function testPruneExpiredKeepsRecentlyExpiredFilesWithinRetentionWindow(): void
    {
        // retention = 1 hour; file expired only 30 s ago — should be kept
        $pruneCache = new Cache($this->tmpDir, ttl: 60, retention: 3600);

        $file = $this->tmpDir . '/recent_' . hash('sha256', 'k') . '.json';
        file_put_contents($file, json_encode(['expires_at' => time() - 30, 'data' => []]));

        $deleted = $pruneCache->pruneExpired();

        $this->assertSame(0, $deleted);
        $this->assertTrue(file_exists($file));
    }

    public function testPruneExpiredKeepsFreshFiles(): void
    {
        $this->cache->remember('pfx', 'fresh', fn() => ['ok' => true]);

        $deleted = $this->cache->pruneExpired();

        $this->assertSame(0, $deleted);
        $this->assertCount(1, glob($this->tmpDir . '/*.json'));
    }
}
