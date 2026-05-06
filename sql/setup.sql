CREATE TABLE usermap_locations (
    name         character varying(64) NOT NULL,
    station      character varying(4)  NOT NULL DEFAULT '',
    lat          double precision,
    lon          double precision,
    flair        text,
    locked       boolean               NOT NULL DEFAULT false,
    created_at   timestamptz           NOT NULL DEFAULT NOW(),
    time_updated timestamptz           NOT NULL DEFAULT NOW()
);

ALTER TABLE ONLY usermap_locations
    ADD CONSTRAINT usermap_locations_pkey PRIMARY KEY (name);

ALTER TABLE usermap_locations
    ADD CONSTRAINT chk_lat CHECK (lat IS NULL OR lat BETWEEN -90 AND 90),
    ADD CONSTRAINT chk_lon CHECK (lon IS NULL OR lon BETWEEN -180 AND 180);

-- Fast lookups of coordinates by airport code (used in geocoding cache)
CREATE INDEX idx_usermap_locations_station
    ON usermap_locations (station)
    WHERE station != '' AND lat IS NOT NULL AND lon IS NOT NULL;

-- Pre-sorted index for the full-map data.json query
CREATE INDEX idx_usermap_locations_lat_lon
    ON usermap_locations (lat, lon)
    WHERE station != '' AND lat IS NOT NULL;

-- Enables efficient data retention cleanup queries
CREATE INDEX idx_usermap_locations_time_updated
    ON usermap_locations (time_updated)
    WHERE locked = false;
