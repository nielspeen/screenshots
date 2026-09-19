<?php

// Defaults for every setting. Don't edit this file: create config.php next to
// it and return only the keys you want to override, e.g.
//
//     <?php return ['domains' => ['example.com', '*.example.com']];

return [
    // Hosts that may be screenshotted. 'example.com' matches exactly that host,
    // '*.example.com' matches any subdomain (but not example.com itself).
    'domains' => [],

    // Allowed viewports as WIDTHxHEIGHT (?size=). The first one is the default.
    'sizes' => ['1280x800', '1920x1080', '768x1024', '390x844'],

    // Allowed thumbnail widths in pixels (?thumb=).
    'thumbs' => [200, 400, 800],

    // Default output format (?format=): png, jpg or webp.
    'format' => 'png',

    // Seconds a screenshot stays valid before it is rendered again.
    'ttl' => 30 * 86400,

    // Cache-Control max-age sent to browsers and CDNs.
    'browser_ttl' => 86400,

    // ?fresh=1 only re-renders when the cached screenshot is older than this.
    'refresh_min_age' => 3600,

    // Oldest screenshots are deleted when the disk has less free space than this.
    'min_free_mb' => 2048,

    // Writable directory for cache, locks and temp files.
    'var_dir' => __DIR__ . '/var',

    // Chrome/Chromium binary. null = look in the usual places.
    'chrome' => null,

    // Extra Chrome flags, e.g. ['--no-sandbox'] when running as root or in a container.
    'chrome_args' => [],

    // Virtual time (ms) the page gets to finish loading and run its scripts.
    'wait_ms' => 5000,

    // Hard limit in seconds for one Chrome run.
    'render_timeout' => 25,

    // Chrome instances allowed to run at the same time.
    'max_concurrent' => 2,

    // Seconds a request waits for a free render slot before giving up with a 503.
    'queue_timeout' => 15,

    // Fetch the URL's headers before rendering: only 2xx pages are captured and
    // every redirect has to stay on a whitelisted host.
    'preflight' => true,
];
