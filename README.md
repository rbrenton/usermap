# UserMap for r/flying

UserMap maps Reddit users by their home location based on subreddit flair. It was
built for [/r/flying](https://www.reddit.com/r/flying), where members set their
flair to an airport identifier (or coordinates) representing their home base. The
application renders an interactive, clustered Google Map of those locations.

## How it works

```
Reddit flair API  ──►  cron.php  ──►  PostgreSQL  ──►  index.php  ──►  Google Map
   (OAuth)            (ingest)        (locations)      (render)        (browser)
                         │
                         └─► gcmap.com (airport → lat/lon, cached in DB)
```

- **`cron.php`** — a CLI script run on a schedule. It authenticates to Reddit via
  OAuth, pages through the subreddit flair list, parses an airport code (or raw
  coordinates) out of each user's flair, geocodes airport codes via gcmap.com
  (caching results in the database), and upserts each user's location.
- **`index.php`** — the web front end. It serves the HTML/JS map and a
  `?a=data.json` endpoint that emits the location data consumed by the map.
- **`lib/`** — supporting classes (`RedditFlairClient`, `RateLimiter`).

## Requirements

- PHP 7.4+ (developed and linted against PHP 8.4) with the `pgsql` and `curl`
  extensions
- PostgreSQL 9.5+ (the schema and upsert path use `INSERT ... ON CONFLICT`)
- A Reddit "script" OAuth app (client id + secret)
- A Google Maps JavaScript API key

## Setup

1. **Database** — create the schema:

   ```sh
   psql "your-connection-string" -f sql/setup.sql
   ```

2. **Configuration** — copy the template and fill in your values:

   ```sh
   cp settings.php.dist settings.php
   $EDITOR settings.php
   ```

   `settings.php` is git-ignored so credentials never land in version control.
   See the configuration reference below.

3. **Google Maps API key** — set your key in `index.php` and **restrict it** in
   the Google Cloud Console to your domain(s) and to the Maps JavaScript API
   only. The key is necessarily public (it ships in client-side HTML), so
   restriction is the control that prevents quota/billing abuse.

4. **Cron** — schedule the ingest. `cron.php` takes an exclusive `flock` so
   overlapping runs exit immediately; pair it with failure alerting:

   ```cron
   */30 * * * * /usr/bin/php /path/to/usermap/cron.php >> /var/log/usermap.log 2>&1 || echo "usermap cron failed" | mail -s "usermap" you@example.com
   ```

## Configuration reference (`settings.php`)

| Constant | Purpose |
| --- | --- |
| `PG_CONNECTION_STRING` | PostgreSQL connection string |
| `PG_TABLE` | Locations table name (default `usermap_locations`) |
| `REDDIT_USERNAME` / `REDDIT_PASSWORD` | Reddit account used for OAuth password grant |
| `REDDIT_API_APP` / `REDDIT_API_SECRET` | Reddit OAuth app client id / secret |
| `HTML_TITLE` / `HTML_HEADER` | Page title and on-map header |
| `GMAP_DEFAULT_LAT` / `GMAP_DEFAULT_LON` / `GMAP_DEFAULT_ZOOM` | Initial map view |
| `CURL_REDDIT_USERAGENT` / `CURL_OTHER_USERAGENT` | User-Agent strings for outbound requests |
| `URL_REDDIT_FLAIR` / `URL_REDDIT_ACCESS_TOKEN` | Reddit API endpoints |

## Development

Lint all PHP sources:

```sh
find . -path ./vendor -prune -o -name '*.php' -print -exec php -l {} \;
# or, with Composer installed:
composer lint
```

## Security & operations notes

- All database access uses parameterized queries (`pg_query_params`).
- User-supplied content (flair, station text) is HTML-escaped before rendering.
- gcmap.com is fetched over HTTPS.
- The `?a=data.json` endpoint is unauthenticated and returns the full dataset;
  put rate limiting / caching in front of it at the web-server layer for any
  significant traffic. See [BACKLOG.md](BACKLOG.md).

## Roadmap

Known issues and planned improvements are tracked in [BACKLOG.md](BACKLOG.md).
Release history is in [CHANGELOG.md](CHANGELOG.md).

## License

Apache License 2.0 — see [LICENSE](LICENSE).
