<?php

declare(strict_types=1);

namespace Screenshots;

/**
 * GET /?url=https://example.com/&size=1280x800&thumb=400&format=webp&fresh=1
 */
final class App
{
    /** Same-key requests queue on one of these, so a URL is never rendered twice at once. */
    private const KEY_LOCKS = 32;

    private UrlGuard $guard;
    private Cache $cache;
    private Locks $locks;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
        $this->guard = new UrlGuard($config['domains']);
        $this->cache = new Cache($config['var_dir'], $config['ttl'], $config['min_free_mb'] * 1024 * 1024);
        $this->locks = new Locks($config['var_dir'] . '/locks');
    }

    public static function run(string $root): void
    {
        try {
            (new self(Config::load($root)))->handle();
        } catch (HttpError $e) {
            self::fail($e->status, $e->getMessage(), $e->headers);
        } catch (\Throwable $e) {
            error_log('screenshots: ' . $e);
            self::fail(500, 'Internal error');
        }
    }

    private function handle(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            throw new HttpError(405, 'Method not allowed', ['Allow' => 'GET, HEAD']);
        }

        $url = $this->guard->normalize($this->param('url') ?? throw new HttpError(400, 'Missing "url" parameter'));
        $size = $this->oneOf('size', $this->config['sizes']);
        $thumb = $this->param('thumb') === null ? null : (int) $this->oneOf('thumb', $this->config['thumbs']);
        $format = $this->oneOf('format', array_keys(Image::TYPES), $this->config['format']);
        $refresh = $this->param('fresh') === '1';

        $key = hash('sha256', $url . '|' . $size);
        $source = $this->cache->path($key, '.png');
        $wanted = $thumb === null && $format === 'png'
            ? $source
            : $this->cache->path($key, '-' . ($thumb ?? 'full') . '.' . $format);

        $cached = !$refresh && $this->isValid($source, $wanted);
        $rendered = false;
        if (!$cached) {
            // The client may give up while we render; finish anyway so the work isn't wasted.
            ignore_user_abort(true);
            $rendered = $this->build($url, $size, $key, $source, $wanted, $thumb, $format, $refresh);
        }

        $this->serve($wanted, $format, !$rendered, $refresh);

        if (!$cached) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->cache->pruneIfNeeded();
        }
    }

    /** Renders and/or converts whatever is missing. Returns whether Chrome had to run. */
    private function build(
        string $url,
        string $size,
        string $key,
        string $source,
        string $wanted,
        ?int $thumb,
        string $format,
        bool $refresh,
    ): bool {
        $keyLock = $this->lock(['key-' . (hexdec(substr($key, 0, 2)) % self::KEY_LOCKS)]);
        try {
            // Someone else may have done the work while we waited for the lock.
            $render = !$this->cache->isFresh($source)
                || ($refresh && filemtime($source) <= time() - $this->config['refresh_min_age']);
            if ($render) {
                $this->render($url, $size, $source);
            }
            if (!$this->isValid($source, $wanted)) {
                $tmp = $this->cache->tmpFile($format);
                try {
                    Image::convert($source, $tmp, $format, $thumb);
                    $this->cache->store($tmp, $wanted);
                } finally {
                    @unlink($tmp);
                }
            }

            return $render;
        } finally {
            $this->locks->release($keyLock);
        }
    }

    private function render(string $url, string $size, string $source): void
    {
        if ($this->config['preflight']) {
            $this->guard->preflight($url);
        }

        [$width, $height] = array_map('intval', explode('x', $size));
        $chrome = new Chrome(
            $this->config['chrome'],
            $this->config['chrome_args'],
            $this->config['render_timeout'],
            $this->config['wait_ms'],
            $this->cache->tmpDir(),
        );

        $slots = array_map(fn (int $i) => 'slot-' . $i, range(1, max(1, $this->config['max_concurrent'])));
        $slot = $this->lock($slots);
        $tmp = $this->cache->tmpFile('png');
        try {
            $chrome->capture($url, $width, $height, $tmp);
            $this->cache->store($tmp, $source);
        } finally {
            @unlink($tmp);
            $this->locks->release($slot);
        }
    }

    /** A converted file is only good as long as it is newer than the render it came from. */
    private function isValid(string $source, string $wanted): bool
    {
        if (!$this->cache->isFresh($source)) {
            return false;
        }
        if ($wanted === $source) {
            return true;
        }
        clearstatcache(true, $wanted);

        return is_file($wanted) && filemtime($wanted) >= filemtime($source);
    }

    private function serve(string $path, string $format, bool $hit, bool $refresh): void
    {
        // Once the file is open, a concurrent prune can't take it away from us anymore.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new HttpError(503, 'Screenshot vanished, try again', ['Retry-After' => '1']);
        }
        $stat = fstat($handle);
        $etag = '"' . md5(basename($path) . $stat['mtime'] . $stat['size']) . '"';
        $maxAge = max(0, min($this->config['browser_ttl'], $stat['mtime'] + $this->config['ttl'] - time()));

        header('Content-Type: ' . Image::TYPES[$format]);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT');
        header('Cache-Control: ' . ($refresh ? 'no-store' : 'public, max-age=' . $maxAge));
        header('X-Cache: ' . ($hit ? 'HIT' : 'MISS'));

        $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        $notModified = isset($_SERVER['HTTP_IF_NONE_MATCH'])
            ? $_SERVER['HTTP_IF_NONE_MATCH'] === $etag
            : $since !== false && $since >= $stat['mtime'];
        if ($notModified) {
            http_response_code(304);
        } else {
            header('Content-Length: ' . $stat['size']);
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                fpassthru($handle);
            }
        }
        fclose($handle);
    }

    /**
     * @param list<string> $names
     * @return resource
     */
    private function lock(array $names)
    {
        return $this->locks->acquire($names, $this->config['queue_timeout'])
            ?? throw new HttpError(503, 'Busy, try again later', ['Retry-After' => '10']);
    }

    private function param(string $name): ?string
    {
        $value = $_GET[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param list<string|int> $allowed first one is the default, unless given */
    private function oneOf(string $name, array $allowed, ?string $default = null): string
    {
        $value = $this->param($name) ?? $default ?? (string) $allowed[0];
        $value = $name === 'format' && $value === 'jpeg' ? 'jpg' : $value;
        if (!in_array($value, array_map('strval', $allowed), true)) {
            throw new HttpError(400, "Invalid \"$name\", allowed: " . implode(', ', $allowed));
        }

        return $value;
    }

    /** @param array<string, string> $headers */
    private static function fail(int $status, string $message, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo $message, "\n";
    }
}
