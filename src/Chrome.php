<?php

declare(strict_types=1);

namespace Screenshots;

/** Runs headless Chrome once per screenshot; no daemon, no protocol, no state. */
final class Chrome
{
    private const BINARIES = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
    ];

    private string $binary;

    /** @param list<string> $extraArgs */
    public function __construct(
        ?string $binary,
        private array $extraArgs,
        private int $timeout,
        private int $waitMs,
        private string $tmpDir,
    ) {
        $this->binary = $binary ?? self::find();
    }

    /** Writes a PNG of $url to $target. */
    public function capture(string $url, int $width, int $height, string $target): void
    {
        // A throwaway HOME and profile per run: nothing leaks between pages,
        // parallel runs can't fight over a profile and www-data needs no real home.
        $home = $this->tmpDir . '/chrome-' . bin2hex(random_bytes(8));
        if (!mkdir($home, 0700, true)) {
            throw new \RuntimeException("Cannot create $home");
        }

        $command = [
            // timeout(1) kills the whole process group, so no Chrome helpers stay behind.
            'timeout', '-k', '5', (string) $this->timeout,
            $this->binary,
            '--headless',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-crash-reporter',
            '--disable-extensions',
            '--disable-background-networking',
            '--disable-sync',
            '--mute-audio',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            '--host-resolver-rules=' . self::blockedHosts(),
            // Prints the final page on stdout, which tells us where Chrome really ended up.
            '--dump-dom',
            '--user-data-dir=' . $home . '/profile',
            '--window-size=' . $width . ',' . $height,
            '--screenshot=' . $target,
        ];
        if ($this->waitMs > 0) {
            $command[] = '--virtual-time-budget=' . $this->waitMs;
        }
        if (($userAgent = $this->userAgent()) !== null) {
            $command[] = '--user-agent=' . $userAgent;
        }
        $command = [...$command, ...$this->extraArgs, $url];

        try {
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $home . '/dom.html', 'w'],
                2 => ['file', $home . '/stderr.log', 'w'],
            ], $pipes, $home, ['HOME' => $home] + getenv());
            if (!is_resource($process)) {
                throw new HttpError(500, 'Could not start Chrome');
            }
            $exit = proc_close($process);

            if ($exit === 124 || $exit === 137) {
                throw new HttpError(UrlGuard::TARGET_FAILED, 'Rendering timed out');
            }
            clearstatcache(true, $target);
            if ($exit !== 0 || !is_file($target) || !@getimagesize($target)) {
                $stderr = (string) @file_get_contents($home . '/stderr.log');
                error_log("screenshots: Chrome exited with $exit for $url: " . substr(trim($stderr), -1000));
                throw new HttpError(500, 'Rendering failed');
            }
            if (self::isBotCheck((string) @file_get_contents($home . '/dom.html', false, null, 0, 1024 * 1024))) {
                @unlink($target);
                throw new HttpError(UrlGuard::TARGET_FAILED, "Blocked by the site's bot protection");
            }
        } finally {
            Cache::remove($home);
        }
    }

    /** Is this page Cloudflare's "Just a moment..." challenge rather than the site behind it? */
    public static function isBotCheck(string $dom): bool
    {
        return str_contains($dom, '_cf_chl_opt');
    }

    /**
     * What this Chrome sends when it is not headless. "HeadlessChrome" gets challenged as a bot
     * by many sites on sight; built from the installed version, so there is nothing to keep up
     * to date. Null (Chrome's default) if the version can't be read.
     */
    private function userAgent(): ?string
    {
        exec(escapeshellarg($this->binary) . ' --version 2>/dev/null', $output);
        if (!preg_match('/(\d+)\.\d+\.\d+\.\d+/', implode(' ', $output), $m)) {
            return null;
        }

        return "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$m[1].0.0.0 Safari/537.36";
    }

    /**
     * Second line of defence behind the whitelist: a whitelisted page that
     * redirects to (or embeds) a loopback or private address gets nothing.
     * Matches literal addresses only, not names that resolve to them.
     */
    private static function blockedHosts(): string
    {
        $patterns = ['localhost', '*.localhost', '[::1]', '0.*', '127.*', '10.*', '169.254.*', '192.168.*'];
        foreach (range(16, 31) as $second) {
            $patterns[] = "172.$second.*";
        }
        foreach (range(64, 127) as $second) {
            $patterns[] = "100.$second.*"; // CGNAT, which is where Tailscale lives
        }

        return implode(', ', array_map(fn (string $p) => "MAP $p ~NOTFOUND", $patterns));
    }

    private static function find(): string
    {
        foreach (self::BINARIES as $binary) {
            if (is_executable($binary)) {
                return $binary;
            }
        }

        throw new \RuntimeException('No Chrome/Chromium found, set "chrome" in config.php');
    }
}
