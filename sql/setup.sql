-- Requires PostgreSQL 9.5+ (cron.php uses INSERT ... ON CONFLICT). PG 17 recommended.
CREATE TABLE rflying_locations (
    name character varying(64) NOT NULL,
    station character varying(4) NOT NULL,
    lat double precision,
    lon double precision,
    flair character varying(128),
    locked boolean DEFAULT false
);

ALTER TABLE ONLY rflying_locations
    ADD CONSTRAINT rflying_locations_pkey PRIMARY KEY (name);

