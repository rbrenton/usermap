# usermap

Maps r/flying users by home airport, parsed from their subreddit flair, on a
Google Map with marker clustering.

This is a Cloudflare-native app: a single [Worker](https://developers.cloudflare.com/workers/)
serves the map and the marker data, backed by [D1](https://developers.cloudflare.com/d1/)
(SQLite). A [Cron Trigger](https://developers.cloudflare.com/workers/configuration/cron-triggers/)
runs the ingest job that refreshes the data.

## Architecture

| Piece | Where |
|-------|-------|
| Map page (`/`) and marker data (`/data.json`) | `src/index.ts` (Worker `fetch`) |
| Static assets (css, js, images) | `public/` (Workers Static Assets) |
| Ingest: Reddit flair → geocode → upsert | `src/index.ts` (Worker `scheduled`) |
| Database | D1, schema in `schema.sql` |
| Pure, unit-tested helpers | `src/lib.ts` (`parseStation`, grouping, escaping) |

## Setup

```sh
npm install

# 1. Create the D1 database, then paste the returned database_id into
#    wrangler.jsonc (d1_databases[0].database_id).
npx wrangler d1 create usermap

# 2. Apply the schema (remote and/or local).
npm run db:init          # remote
npm run db:init:local    # local dev

# 3. Set secrets (production).
npx wrangler secret put REDDIT_USERNAME
npx wrangler secret put REDDIT_PASSWORD
npx wrangler secret put REDDIT_API_APP
npx wrangler secret put REDDIT_API_SECRET
npx wrangler secret put GMAP_API_KEY

# For local dev, copy .dev.vars.example -> .dev.vars and fill it in.
```

Non-secret config (titles, default map center/zoom, URLs, rate-limit cap) lives
in `wrangler.jsonc` under `vars`.

## Develop / deploy / test

```sh
npm run dev        # local Worker + map
npm run typecheck  # tsc --noEmit
npm test           # unit tests (node --experimental-strip-types)
npm run deploy     # wrangler deploy
```

## Notes

- **Maps API key** is a browser key, so it is exposed to clients by design —
  restrict it by HTTP referrer in the Google Cloud console rather than relying
  on secrecy.
- **Initial backfill:** the ingest job geocodes at most `MAX_GEOCODES_PER_RUN`
  new stations per run (default 50) to stay within Worker subrequest/time
  limits. Already-known stations are served from the D1 cache, so once the
  backfill completes, each run only geocodes newly seen airports. Lower the
  cron interval or raise the cap to backfill faster.
- Geocoding scrapes gcmap.com; explicit `(lat,lon)` flair is not currently
  mapped (preserved from the original behavior).
