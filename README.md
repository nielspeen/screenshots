# Screenshots

A small HTTP API that returns screenshots of web pages, for whitelisted domains only.

- Plain PHP (8.2+, ext-gd). No Composer dependencies, no database, no queue, no daemon.
- Renders with headless Chrome, one short-lived process per screenshot.
- Results are cached on disk for 30 days. When the disk runs low on space, the oldest screenshots are deleted.
- No authentication: the API only renders pages on domains you list, so there is nothing to abuse it for.
- Optionally, a secret key lets your own back ends screenshot any public site.

## API

    GET /?url=https://example.com/pricing&size=1280x800&thumb=400&format=webp

| Parameter | Default    | Description                                                                                   |
|-----------|------------|-----------------------------------------------------------------------------------------------|
| `url`     | (required) | http(s) URL on a whitelisted domain. URL-encode it if it has a query string of its own.         |
| `size`    | `1280x800` | Viewport. One of the configured `sizes`.                                                        |
| `thumb`   | none       | Scale the result down to this width. One of the configured `thumbs`.                            |
| `format`  | `png`      | `png`, `jpg` or `webp`.                                                                         |
| `fresh`   | none       | `fresh=1` re-renders instead of serving from cache, at most once per `refresh_min_age` per URL. |

The response is the image itself, so the URL can go straight into an `<img src>`. It carries
`Cache-Control`, `ETag` and `Last-Modified` (conditional requests get a `304`), plus
`X-Cache: HIT|MISS` to tell whether Chrome had to run. A miss takes a few seconds.

A request with `Authorization: Bearer <key>`, where the key is one of the configured `keys`, is
not held to the whitelist: it may screenshot any host on the public internet. That is meant for
your own servers (a job that captures third-party sites, say), never for a web page, where the
key would be there for everyone to read. Responses to such requests are `Cache-Control: private`.

Errors are plain text:

| Status | Meaning                                                                                  |
|--------|------------------------------------------------------------------------------------------|
| `400`  | Missing or invalid parameter. The message lists the allowed values.                      |
| `401`  | The request carries a key, but not a valid one.                                          |
| `403`  | The URL, or something it redirects to, is not on a whitelisted domain. With a key: it points into a private network. |
| `424`  | The page could not be reached, did not answer with a 2xx, did not finish loading within `render_timeout` seconds, or kept Chrome behind its bot check. |
| `500`  | Chrome failed; see PHP's error log.                                                      |
| `503`  | All render slots stayed busy for `queue_timeout` seconds. Comes with `Retry-After`.      |

`424` rather than `502`/`504`, because proxies such as Cloudflare replace those with an error page
of their own and the reason would never reach the caller.

## Setup

Requirements: PHP 8.2+ with ext-gd (including WebP support), Composer, coreutils' `timeout`, and
Chrome or Chromium.

    composer install --no-dev
    mkdir var && chown www-data: var

Create `config.php` and override whatever you need from [config.defaults.php](config.defaults.php).
Usually that is just the whitelist:

```php
<?php

return [
    'domains' => ['example.com', '*.example.com'],
];
```

`example.com` matches exactly that host. `*.example.com` matches every subdomain, but not
`example.com` itself. Redirects are checked as well, so a site that redirects from `example.com`
to `www.example.com` needs both.

nginx:

```nginx
server {
    server_name screenshots.example.com;
    root /var/www/screenshots/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_read_timeout 60s;
    }
}
```

Things to know about the server:

- **Chrome.** On Debian `apt install chromium` works. On Ubuntu that package is a snap, which
  can't run from PHP-FPM; install Google's `google-chrome-stable` .deb instead. The binary is
  found automatically, or set `chrome` in the config.
- **Fonts.** A server has almost none. Install at least `fonts-liberation`, `fonts-noto-core` and
  `fonts-noto-color-emoji`, or text renders as boxes.
- **Sandbox.** Chrome runs with its sandbox enabled. If it refuses to start (running as root, in a
  container, or with user namespaces disabled), add `'chrome_args' => ['--no-sandbox']`. Chrome's
  stderr ends up in PHP's error log when a render fails.
- **Timeouts.** A request that has to queue can take 2 × `queue_timeout` + `render_timeout`
  seconds (55 by default), plus the preflight. Keep `fastcgi_read_timeout` and FPM's
  `request_terminate_timeout` above that.
- **Workers.** Requests waiting for a render each hold a PHP-FPM worker. Give the pool more
  children than `max_concurrent`, plus room for the waiters.
- **Rate limiting.** Anyone can request any URL on a whitelisted domain, and every new URL costs a
  Chrome run. `max_concurrent` caps the load on the server, but a flood makes everybody else
  wait or get a `503`. Add nginx's `limit_req` if that worries you.
- **OPcache.** Changes to `config.php` take effect after `opcache.revalidate_freq` seconds, or
  after an FPM reload if `opcache.validate_timestamps` is off.

## How it works

**Cache.** A screenshot lives at `var/cache/<ab>/<sha256 of url and size>.png`, and the file's
mtime is its render time; there is no other bookkeeping. Thumbnails and other formats are made
from that PNG with GD and stored next to it (`<hash>-400.webp`), so they never need a second
Chrome run. A converted file only counts while it is newer than the PNG it came from, which is
how `fresh=1` invalidates all of them at once.

**Pruning.** After every cache write the free disk space is checked. When it is below
`min_free_mb`, expired files go first and then the oldest ones, until there is 25% more free
space than the minimum. Files from the last minute are never touched. That is all there is
to it: expired screenshots nobody asks for anymore simply stay until the disk needs the room.
If you would rather not have them lying around, run `bin/prune` from cron as the web server's
user; it deletes what is expired regardless of disk space.

**Locking.** All locks are `flock()`s on files in `var/locks`, so they disappear with the process
holding them and can't get stuck. A per-URL lock makes simultaneous requests for the same
uncached screenshot wait for one render instead of all starting their own. A pool of
`max_concurrent` slot locks limits how many Chrome instances run at the same time.

**Bot checks.** Chrome introduces itself as ordinary Chrome (built from the installed version)
instead of `HeadlessChrome`, which many sites challenge on sight. When the preflight runs into a
Cloudflare challenge (`cf-mitigated: challenge`) it steps aside and lets Chrome try, since a real
browser usually gets through. Chrome also hands over the HTML of the page it ended up on, and if
that is still the challenge, the answer is a `424` rather than a month of "Just a moment..." in
the cache. Sites that block the server's address range outright can't be captured from it.

**Security.** The whitelist is what stands between the internet and a browser running on your
server, so:

- The URL is validated strictly (http/https, no credentials, no ports, plain ASCII host) and
  rebuilt from its parsed parts. Chrome gets the rebuilt URL and can't read a different host into
  it than the whitelist check did.
- Before rendering, the URL's headers are fetched. Every redirect has to stay on the whitelist,
  and the final response has to be a 2xx, so error pages are not cached for a month.
  `'preflight' => false` turns this off.
- Chrome is started with resolver rules that block `localhost` and literal loopback, link-local
  and private addresses, in case a whitelisted page redirects there with JavaScript or embeds
  something from there. This does not catch public hostnames that resolve to a private
  address. If the server can reach sensitive internal services, firewall the web server's user.
- With a key the whitelist is out of the picture, so the host, and every redirect, must resolve
  to public addresses only: no loopback, private, link-local (cloud metadata) or CGNAT
  (Tailscale) ranges.
- Every render gets a throwaway profile and `HOME`, deleted afterwards. No cookies or cache
  carry over from one page to the next.
- Chrome is started without a shell, under `timeout`, which kills the whole process group.

### Before you hand out a key

A key turns the server into a browser that visits pages you don't control, and such a page can
try to reach your internal network *through* that browser. The checks above look at a name once;
Chrome resolves it again by itself (DNS rebinding), and a page can redirect with JavaScript or
embed whatever it likes. Treat them as a first line and put the real fence in the firewall:
forbid the web server's user (PHP and Chrome run as it) to open connections to anything internal.
With ufw, add this to `/etc/ufw/before.rules`, above its `-A ufw-before-output -o lo -j ACCEPT`
line, and the same for `::1`, `fc00::/7` and `fe80::/10` (chain `ufw6-before-output`) to
`/etc/ufw/before6.rules`. Check with `iptables-restore -n --test < /etc/ufw/before.rules` before
you `ufw reload`:

```
-A ufw-before-output -m owner --uid-owner www-data -d 127.0.0.53 -p udp --dport 53 -j ACCEPT
-A ufw-before-output -m owner --uid-owner www-data -d 127.0.0.53 -p tcp --dport 53 -j ACCEPT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 0.0.0.0/8 -j REJECT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 127.0.0.0/8 -j REJECT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 10.0.0.0/8 -j REJECT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 100.64.0.0/10 -j REJECT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 169.254.0.0/16 -j REJECT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 172.16.0.0/12 -j REJECT
-A ufw-before-output -m owner --uid-owner www-data -m conntrack --ctstate NEW -d 192.168.0.0/16 -j REJECT
```

The first two lines keep DNS through systemd-resolved working. `--ctstate NEW` only stops
connections the user *opens*; nginx, which runs as the same user, can still answer visitors that
come in over a private network or a VPN. Keep Chrome updated (add its apt origin to
unattended-upgrades), since it now meets the open web.

## Limitations

These come with driving Chrome through its command line, which is what keeps this dependency-free:

- Viewport-sized screenshots only, no full-page capture.
- No waiting for a selector or for network idle. The page gets `wait_ms` of virtual time to
  settle, which is enough for most pages.
- No clicking away cookie banners, no custom cookies or headers, no login.

If that ever becomes a problem, [src/Chrome.php](src/Chrome.php) is the only file that knows how
a screenshot is made. Swap its `capture()` for a DevTools-protocol client such as
`chrome-php/chrome` and the rest stays as it is.

## Development

    composer serve    # http://127.0.0.1:8080/?url=https://example.com/
    composer test     # dependency-free tests for URL validation, pruning, locks and images

PHP's built-in server handles one request at a time; start it with `PHP_CLI_SERVER_WORKERS=4`
to try concurrent requests.
