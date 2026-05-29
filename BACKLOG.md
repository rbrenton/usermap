# Backlog

Prioritized, actionable work remaining after the 1.3.0 review. Items completed in
1.3.0 are recorded in [CHANGELOG.md](CHANGELOG.md). Priorities: **P0** must do,
**P1** high value, **P2** worthwhile, **P3** nice to have.

## P0 — Operational (manual, cannot be done in code)

- [ ] **Rotate the Google Maps API key** currently committed in `index.php`, then
  restrict the new key in Google Cloud Console to the production domain(s) and to
  the Maps JavaScript API scope only. The old key should be considered
  compromised because it has lived in a public repository's history.

## P1 — Reliability & hardening

- [ ] **Rate-limit / cache the `?a=data.json` endpoint.** It is unauthenticated
  and dumps the full dataset on every request. Add `Cache-Control` headers and a
  web-server-layer rate limit (e.g. nginx `limit_req`), or cache the rendered
  payload to disk on the cron cadence.
- [ ] **Replace `printf` logging with PHP error logging** (`error_log`) so output
  has severity levels, can be routed to files, and is rotatable. Audit secret
  masking against wherever logs ultimately land.
- [ ] **Add a data-retention job.** Reddit accounts get deleted/renamed; rows
  accumulate forever. Add a scheduled `DELETE … WHERE time_updated < NOW() -
  INTERVAL '180 days' AND locked = false` (the `idx_..._time_updated` index
  already supports this).
- [ ] **Add cron failure alerting** to the deployment (the README documents a
  `flock` + mail one-liner; wire it up in the actual crontab).

## P2 — Engineering quality

- [ ] **Automated tests.** Add PHPUnit coverage for `parseStation()` (rich logic,
  pure function — easy, high-value), `RateLimiter`, and `RedditFlairClient` with a
  mocked HTTP layer.
- [ ] **CI pipeline.** GitHub Actions: `php -l` lint + PHPUnit on push/PR.
- [ ] **Adopt Composer autoloading.** `composer.json` is in place with a classmap;
  switch `cron.php` to the autoloader and drop the manual `require_once` lines.
- [ ] **Containerize** (`Dockerfile` + `docker-compose.yml`: php-fpm + nginx +
  postgres + a cron container) for reproducible local dev and deploy.

## P3 — Defense-in-depth & polish

- [ ] **Serve the Maps API key from a config constant** (`define('GMAP_API_KEY', …)`
  in `settings.php`) instead of hard-coding it in `index.php`, so it is managed
  alongside other secrets and never committed.
- [ ] **Security headers.** Add `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, and a `Content-Security-Policy` to the HTML response.
- [ ] **Paginate / trim `data.json`.** Group server-side and consider dropping
  per-user PII from the bulk payload, fetching details on demand.
- [ ] **Move configuration to `.env`** (e.g. `vlucas/phpdotenv`) with a typed
  config object, retiring the `define()`-based settings.
- [ ] **Re-validate the Google Maps tile-URL workaround** in `index.php`; confirm
  it is still needed against the current Maps API and remove it if not.
