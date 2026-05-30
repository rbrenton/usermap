-- D1 (SQLite) schema for usermap.
-- Apply with: npm run db:init   (remote)  /  npm run db:init:local
CREATE TABLE IF NOT EXISTS rflying_locations (
    name          TEXT PRIMARY KEY NOT NULL,
    station       TEXT NOT NULL,
    lat           REAL,
    lon           REAL,
    flair         TEXT,
    locked        INTEGER NOT NULL DEFAULT 0,
    time_updated  TEXT
);

-- Speeds up the geocode cache lookup in the ingest job (resolveLatLon).
CREATE INDEX IF NOT EXISTS idx_rflying_station ON rflying_locations (station);
