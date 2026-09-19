<?php

declare(strict_types=1);

namespace Screenshots;

/**
 * Plain files under var/cache, sharded by the first two characters of the key.
 * The file's mtime is its render time; there is no other metadata.
 */
final class Cache
{
    /** Files younger than this are never pruned: a request may be about to serve them. */
    private const PRUNE_GRACE = 60;

    /** Leftovers in tmp/ older than this belong to crashed requests. */
    private const TMP_MAX_AGE = 3600;

    private string $cacheDir;
    private string $tmpDir;

    /** @param (\Closure(): float)|null $freeSpace bytes free on the cache disk; for tests */
    public function __construct(
        string $varDir,
        private int $ttl,
        private int $minFreeBytes,
        private ?\Closure $freeSpace = null,
    ) {
        $this->cacheDir = $varDir . '/cache';
        $this->tmpDir = $varDir . '/tmp';
        foreach ([$this->cacheDir, $this->tmpDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create $dir");
            }
        }
    }

    public function path(string $key, string $suffix): string
    {
        return $this->cacheDir . '/' . substr($key, 0, 2) . '/' . $key . $suffix;
    }

    public function tmpDir(): string
    {
        return $this->tmpDir;
    }

    /** A temp path on the same filesystem as the cache, so store() is an atomic rename. */
    public function tmpFile(string $extension): string
    {
        return $this->tmpDir . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    public function isFresh(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path) && filemtime($path) > time() - $this->ttl;
    }

    public function store(string $tmp, string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!rename($tmp, $path)) {
            throw new \RuntimeException("Cannot write $path");
        }
    }

    public function pruneIfNeeded(): void
    {
        if ($this->freeSpace() < $this->minFreeBytes) {
            $this->prune();
        }
    }

    /**
     * Deletes everything that is expired and, when free space is below the
     * minimum, oldest first whatever it takes to get 25% above it. The margin
     * keeps us from pruning again on the very next write.
     *
     * @return array{int, int} number of files deleted, bytes freed
     */
    public function prune(): array
    {
        $lock = fopen($this->cacheDir . '/.prune.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return [0, 0];
        }

        $now = time();
        foreach (new \FilesystemIterator($this->tmpDir) as $entry) {
            if ($entry->getMTime() < $now - self::TMP_MAX_AGE) {
                self::remove($entry->getPathname());
            }
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename()[0] !== '.') {
                $files[] = [$file->getMTime(), $file->getSize(), $file->getPathname()];
            }
        }
        sort($files);

        $free = $this->freeSpace();
        $target = $free < $this->minFreeBytes ? $this->minFreeBytes * 1.25 : 0;
        $deleted = $freed = 0;
        foreach ($files as [$mtime, $size, $path]) {
            $expired = $mtime <= $now - $this->ttl;
            if ($mtime > $now - self::PRUNE_GRACE || (!$expired && $free >= $target)) {
                break;
            }
            if (@unlink($path)) {
                // Counted by hand; some filesystems are slow to report freed space.
                $free += $size;
                $freed += $size;
                $deleted++;
            }
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return [$deleted, $freed];
    }

    /** Best effort rm -rf. */
    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                self::remove($entry->getPathname());
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    private function freeSpace(): float
    {
        return $this->freeSpace ? ($this->freeSpace)() : (float) disk_free_space($this->cacheDir);
    }
}
