<?php

declare(strict_types=1);

namespace Screenshots;

/** flock() based locks; they vanish with the process, so nothing can get stuck. */
final class Locks
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Takes the first lock that is free among $names, waiting up to $wait seconds.
     *
     * @param list<string> $names
     * @return resource|null
     */
    public function acquire(array $names, float $wait)
    {
        $deadline = microtime(true) + $wait;
        while (true) {
            foreach ($names as $name) {
                $handle = fopen($this->dir . '/' . $name . '.lock', 'c');
                if ($handle === false) {
                    throw new \RuntimeException("Cannot open lock file in {$this->dir}");
                }
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return $handle;
                }
                fclose($handle);
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(200_000);
        }
    }

    /** @param resource $handle */
    public function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
