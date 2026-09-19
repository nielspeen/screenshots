<?php

// Dependency-free tests: `composer test`. Exits non-zero when something fails.

declare(strict_types=1);

use Screenshots\Cache;
use Screenshots\Chrome;
use Screenshots\HttpError;
use Screenshots\Image;
use Screenshots\Locks;
use Screenshots\UrlGuard;

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    echo $ok ? '  ok  ' : 'FAIL  ', $name, "\n";
}

/** HTTP status normalize() answers with, or the normalized URL. */
function normalize(UrlGuard $guard, string $url): int|string
{
    try {
        return $guard->normalize($url);
    } catch (HttpError $e) {
        return $e->status;
    }
}

$tmp = sys_get_temp_dir() . '/screenshots-test-' . bin2hex(random_bytes(4));
mkdir($tmp);

// --- UrlGuard ---------------------------------------------------------------

$guard = new UrlGuard(['example.com', '*.example.org', 'Shop.Example.NET']);

$accepted = [
    'https://example.com' => 'https://example.com/',
    'https://example.com/a/b?c=d&e=f#frag' => 'https://example.com/a/b?c=d&e=f',
    'HTTP://EXAMPLE.COM/Path' => 'http://example.com/Path',
    'https://example.com:443/x' => 'https://example.com/x',
    'http://example.com:80/' => 'http://example.com/',
    'https://example.com./' => 'https://example.com/',
    '  https://example.com/  ' => 'https://example.com/',
    'https://www.example.org/' => 'https://www.example.org/',
    'https://a.b.example.org/' => 'https://a.b.example.org/',
    'https://shop.example.net/' => 'https://shop.example.net/',
    'https://example.com/?next=https://evil.com/' => 'https://example.com/?next=https://evil.com/',
];
foreach ($accepted as $url => $expected) {
    check("accepts $url", normalize($guard, $url) === $expected);
}

$rejected = [
    '' => 400,
    'example.com' => 400,
    '//example.com/' => 400,
    'ftp://example.com/' => 400,
    'file:///etc/passwd' => 400,
    'javascript:alert(1)' => 400,
    'https://example.com:8443/' => 400,
    'https://example.com:80/' => 400,
    'https://user@example.com/' => 400,
    'https://user:pass@example.com/' => 400,
    'https://example.com@evil.com/' => 400,
    'https://example.com:443@evil.com/' => 400,
    'https://example.com\@evil.com/' => 400,
    'https://evil.com\.example.com/' => 400,
    "https://example.com/\nX-Injected: 1" => 400,
    'https://example.com/a b' => 400,
    'https://exam%70le.com/' => 400,
    'https://[::1]/' => 400,
    'https://' . str_repeat('a', 2050) . '.example.org/' => 400,
    'https://www.example.com/' => 403,
    'https://example.org/' => 403,
    'https://evilexample.com/' => 403,
    'https://example.com.evil.com/' => 403,
    'https://evil.com/example.com' => 403,
    'https://evil.com/?x=.example.org' => 403,
    'https://evil.com#.example.org' => 403,
    'https://xexample.org/' => 403,
    'http://127.0.0.1/' => 403,
    'http://localhost/' => 403,
    'http://2130706433/' => 403,
];
foreach ($rejected as $url => $status) {
    check("rejects ($status) " . json_encode(substr($url, 0, 50)), normalize($guard, $url) === $status);
}

$resolved = [
    ['https://example.com/a/b', 'https://other.com/x', 'https://other.com/x'],
    ['https://example.com/a/b', '//other.com/x', 'https://other.com/x'],
    ['https://example.com/a/b', '/x?y=1', 'https://example.com/x?y=1'],
    ['https://example.com/a/b', 'c', 'https://example.com/a/c'],
    ['https://example.com/', 'c', 'https://example.com/c'],
];
foreach ($resolved as [$base, $location, $expected]) {
    check("resolves $location against $base", UrlGuard::resolve($base, $location) === $expected);
}

// A caller with a key may go off the whitelist, but not into our own network.
$dns = [
    'vendor.test' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
    'loopback.test' => ['127.0.0.1'],
    'metadata.test' => ['169.254.169.254'],
    'lan.test' => ['192.168.1.10'],
    'tailnet.test' => ['100.101.102.103'],
    'tailnet6.test' => ['fd7a:115c:a1e0::1'],
    'mixed.test' => ['93.184.215.14', '10.0.0.5'],
    'mapped.test' => ['::ffff:127.0.0.1'],
    'example.com' => ['10.0.0.5'],
];
$trusted = new UrlGuard(['example.com'], true, fn (string $host) => $dns[$host] ?? []);

check('key: accepts a public host off the whitelist', normalize($trusted, 'https://vendor.test/pricing') === 'https://vendor.test/pricing');
check('key: whitelisted hosts stay allowed wherever they point', normalize($trusted, 'https://example.com/') === 'https://example.com/');
foreach (['loopback', 'metadata', 'lan', 'tailnet', 'tailnet6', 'mixed', 'mapped'] as $name) {
    check("key: rejects a host resolving to a $name address", normalize($trusted, "https://$name.test/") === 403);
}
check('key: rejects a host that does not resolve', normalize($trusted, 'https://nowhere.test/') === 400);
check('key: url rules still apply', normalize($trusted, 'https://vendor.test:8443/') === 400 && normalize($trusted, 'ftp://vendor.test/') === 400);
check('no key: the same public host is refused', normalize($guard, 'https://vendor.test/') === 403);

$real = new UrlGuard([], true);
check('key: real resolver rejects localhost and literal internal addresses', normalize($real, 'http://localhost/') === 403
    && normalize($real, 'http://127.0.0.1/') === 403
    && normalize($real, 'http://2130706433/') === 403
    && normalize($real, 'http://169.254.169.254/latest/meta-data') === 403
    && normalize($real, 'http://100.100.100.100/') === 403);

// --- Cache ------------------------------------------------------------------

const MB = 1024 * 1024;

/** Creates a 1 MB cache file that was "rendered" $age seconds ago. */
function cached(Cache $cache, string $key, int $age): string
{
    $path = $cache->path($key, '.png');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, str_repeat('x', MB));
    touch($path, time() - $age);

    return $path;
}

$day = 86400;
$free = 4 * MB;
$cache = new Cache($tmp . '/prune', 30 * $day, 4 * MB, function () use (&$free) {
    return (float) $free;
});

$expired = cached($cache, 'aa-expired', 31 * $day);
$oldest = cached($cache, 'bb-oldest', 20 * $day);
$old = cached($cache, 'ff-old', 10 * $day);
$new = cached($cache, 'cc-new', $day);

check('fresh file is fresh', $cache->isFresh($new));
check('expired file is not fresh', !$cache->isFresh($expired));
check('missing file is not fresh', !$cache->isFresh($cache->path('nope', '.png')));

$cache->pruneIfNeeded();
check('enough space: pruneIfNeeded() leaves everything alone', is_file($expired) && is_file($oldest) && is_file($new));

[$deleted] = $cache->prune();
check('enough space: prune() deletes only what is expired', $deleted === 1 && !is_file($expired) && is_file($oldest) && is_file($new));

// Below the 4 MB minimum, so up to the 5 MB target: that takes two files, the oldest two.
$free = 3.5 * MB;
[$deleted, $freed] = $cache->prune();
check('low space: deletes oldest first, up to the margin', $deleted === 2 && $freed === 2 * MB && !is_file($oldest) && !is_file($old) && is_file($new));

$free = 0;
$justNow = cached($cache, 'dd-just-now', 5);
$cache->pruneIfNeeded();
check('no space: deletes everything', !is_file($new));
check('no space: except what was rendered seconds ago', is_file($justNow));

$stale = $cache->tmpDir() . '/chrome-crashed';
mkdir($stale . '/profile', 0775, true);
touch($stale . '/profile/file');
touch($stale, time() - 2 * 3600);
$inUse = $cache->tmpFile('png');
touch($inUse);
$cache->prune();
check('prune() sweeps stale temp dirs', !file_exists($stale));
check('prune() keeps temp files that are in use', is_file($inUse));

$path = $cache->path('ee-stored', '.png');
$cache->store($inUse, $path);
check('store() moves the file into a new shard dir', is_file($path) && !is_file($inUse) && basename(dirname($path)) === 'ee');

// --- Locks ------------------------------------------------------------------

$locks = new Locks($tmp . '/locks');
$first = $locks->acquire(['slot-1', 'slot-2'], 0);
$second = $locks->acquire(['slot-1', 'slot-2'], 0);
check('two slots can be taken', $first !== null && $second !== null);
check('a third one is refused', $locks->acquire(['slot-1', 'slot-2'], 0) === null);
$locks->release($first);
$third = $locks->acquire(['slot-1', 'slot-2'], 0);
check('until one is released', $third !== null);

// --- Chrome -----------------------------------------------------------------

check('recognises a Cloudflare challenge page', Chrome::isBotCheck('<html><head><title>Just a moment...</title><script>window._cf_chl_opt = {cvId: "3"};</script>'));
check('not fooled by the bot detection script on a normal Cloudflare site', !Chrome::isBotCheck('<html><head><title>Shop</title><script src="/cdn-cgi/challenge-platform/scripts/jsd/main.js"></script>'));
check('an empty page is not a challenge', !Chrome::isBotCheck(''));

// --- Image ------------------------------------------------------------------

$source = $tmp . '/source.png';
imagepng(imagecreatetruecolor(800, 600), $source);

foreach (Image::TYPES as $format => $mime) {
    $target = "$tmp/thumb.$format";
    Image::convert($source, $target, $format, 400);
    $info = getimagesize($target);
    check("converts to a 400px $format thumbnail", $info[0] === 400 && $info[1] === 300 && $info['mime'] === $mime);
}

Image::convert($source, "$tmp/full.webp", 'webp', null);
check('keeps the size without thumb', getimagesize("$tmp/full.webp")[0] === 800);

Image::convert($source, "$tmp/big.jpg", 'jpg', 1600);
check('never scales up', getimagesize("$tmp/big.jpg")[0] === 800);

// ----------------------------------------------------------------------------

Cache::remove($tmp);
echo $failures === 0 ? "\nAll good.\n" : "\n$failures failed.\n";
exit($failures === 0 ? 0 : 1);
