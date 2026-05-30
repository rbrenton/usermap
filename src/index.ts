/**
 * usermap — Cloudflare Worker.
 *
 *  - fetch():     serves the map page ("/") and the marker data ("/data.json").
 *                 Static assets (css/js/images) are served by the assets binding.
 *  - scheduled(): the ingest job — pulls r/flying flair, geocodes new stations
 *                 via gcmap.com, and upserts into D1 (replaces the old cron.php).
 */

import {
  esc,
  groupStations,
  maskSecrets,
  num,
  parseStation,
  type PilotRow,
} from "./lib.ts";

export interface Env {
  DB: D1Database;

  // Secrets (wrangler secret put / .dev.vars).
  REDDIT_USERNAME: string;
  REDDIT_PASSWORD: string;
  REDDIT_API_APP: string;
  REDDIT_API_SECRET: string;
  GMAP_API_KEY: string;

  // Vars (wrangler.jsonc).
  HTML_TITLE: string;
  HTML_HEADER: string;
  GMAP_DEFAULT_LAT: string | number;
  GMAP_DEFAULT_LON: string | number;
  GMAP_DEFAULT_ZOOM: string | number;
  REDDIT_FLAIR_URL: string;
  REDDIT_TOKEN_URL: string;
  REDDIT_USER_AGENT: string;
  GEO_USER_AGENT: string;
  MAX_GEOCODES_PER_RUN: string | number;
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);

    if (url.pathname === "/data.json") {
      return handleData(env);
    }
    if (url.pathname === "/" || url.pathname === "") {
      return handlePage(url, env);
    }

    // Static assets are handled by the assets binding before reaching here.
    return new Response("Not found", { status: 404 });
  },

  async scheduled(_event: ScheduledController, env: Env, ctx: ExecutionContext): Promise<void> {
    ctx.waitUntil(runIngest(env));
  },
} satisfies ExportedHandler<Env>;

// ---------------------------------------------------------------------------
// HTTP handlers
// ---------------------------------------------------------------------------

async function handleData(env: Env): Promise<Response> {
  const { results } = await env.DB.prepare(
    "SELECT name, station, lat, lon, flair FROM rflying_locations" +
      " WHERE station != '' AND lat IS NOT NULL" +
      " ORDER BY lat, lon, station = 'n/a', length(station) DESC, station",
  ).all<PilotRow>();

  const body = JSON.stringify(groupStations(results ?? []));
  return new Response(body, {
    headers: {
      "content-type": "application/json; charset=UTF-8",
      "cache-control": "public, max-age=300",
    },
  });
}

async function handlePage(url: URL, env: Env): Promise<Response> {
  let lat = num(env.GMAP_DEFAULT_LAT);
  let lon = num(env.GMAP_DEFAULT_LON);
  let zoom = num(env.GMAP_DEFAULT_ZOOM);

  const name = url.searchParams.get("name") ?? "";
  if (name !== "") {
    const row = await env.DB.prepare(
      "SELECT lat, lon FROM rflying_locations WHERE name = ? AND lat IS NOT NULL LIMIT 1",
    )
      .bind(name)
      .first<{ lat: number; lon: number }>();
    if (row) {
      lat = row.lat;
      lon = row.lon;
      zoom = 12;
    }
  }

  return new Response(renderPage(env, lat, lon, zoom), {
    headers: { "content-type": "text/html; charset=UTF-8" },
  });
}

function renderPage(env: Env, lat: number, lon: number, zoom: number): string {
  const key = encodeURIComponent(env.GMAP_API_KEY ?? "");
  return `<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="initial-scale=1.0, user-scalable=no"/>
  <meta http-equiv="content-type" content="text/html; charset=UTF-8"/>
  <title>${esc(env.HTML_TITLE)}</title>
  <link rel="stylesheet" href="/css/default.css" type="text/css"/>
</head>
<body>
  <div id="header">${env.HTML_HEADER ?? ""}</div>
  <div id="map_canvas"></div>
  <script src="/js/markerclusterer.js"></script>
  <script>
  const DEFAULT = { lat: ${lat}, lon: ${lon}, zoom: ${zoom} };

  function initialize() {
    const clusterStyle = [
      { url: '/images/m1.png', height: 52, width: 53 },
      { url: '/images/m2.png', height: 55, width: 56 },
      { url: '/images/m3.png', height: 65, width: 66 },
      { url: '/images/m4.png', height: 77, width: 78 },
      { url: '/images/m5.png', height: 89, width: 90 },
    ];

    function fnPilotCount(markers, numStyles) {
      let count = 0;
      for (const m of markers) count += m.pilots;
      count = count.toString();
      let index = 0;
      let dv = count;
      while (dv !== 0) { dv = parseInt(dv / 10, 10); index++; }
      return { text: count, index: Math.min(index, numStyles) };
    }

    const map = new google.maps.Map(document.getElementById('map_canvas'), {
      zoom: DEFAULT.zoom,
      center: { lat: DEFAULT.lat, lng: DEFAULT.lon },
      mapTypeId: google.maps.MapTypeId.ROADMAP,
    });
    const infowindow = new google.maps.InfoWindow({ backgroundColor: '#333' });

    // Tile fix (modern replacement for the legacy DOMSubtreeModified hack):
    // cache "good" tile URLs and rewrite the styled variants Google sometimes
    // serves so the basemap renders consistently.
    const cache = {};
    const s2k = (s) => s.replace('!3m9', '').replace('!2m3!1e2!6m1!3e5!3m14', '').replace(/!12m4!1e26!2m2!1sstyles!2z[^!]+/, '').replace(/&key=.*/, '');
    const b2s = (b) => { const k = s2k(b); return (typeof cache[k] !== 'undefined') ? cache[k] : b.replace('!2m3!1e2!6m1!3e5!3m14', '!3m9'); };
    const mapCanvas = document.getElementById('map_canvas');
    const observeCfg = { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] };
    const processTiles = () => {
      mapCanvas.querySelectorAll('img[src*="3m9"]').forEach((img) => { cache[s2k(img.src)] = img.src; });
      mapCanvas.querySelectorAll('img[src*="2m3"]').forEach((img) => { const n = b2s(img.src); if (n !== img.src) img.src = n; });
    };
    const observer = new MutationObserver(() => { observer.disconnect(); processTiles(); observer.observe(mapCanvas, observeCfg); });
    observer.observe(mapCanvas, observeCfg);

    fetch('/data.json').then((r) => r.json()).then((data) => {
      const markers = data.map((d) => {
        const marker = new google.maps.Marker({ position: { lat: d.lat, lng: d.lon }, title: d.title });
        marker.pilots = d.count;
        marker.addListener('click', () => { infowindow.setContent(d.html); infowindow.open(map, marker); });
        return marker;
      });
      new MarkerClusterer(map, markers, { styles: clusterStyle, gridSize: 50, maxZoom: 10, calculator: fnPilotCount });
    });
  }
  window.initialize = initialize;
  </script>
  <script async src="https://maps.googleapis.com/maps/api/js?key=${key}&callback=initialize&loading=async"></script>
</body>
</html>`;
}

// ---------------------------------------------------------------------------
// Ingest job (scheduled)
// ---------------------------------------------------------------------------

async function runIngest(env: Env): Promise<void> {
  const token = await getRedditToken(env);
  if (!token) {
    console.log("ingest: aborting, no access token");
    return;
  }

  const maxGeocodes = num(env.MAX_GEOCODES_PER_RUN) || 50;
  const runCache = new Map<string, [number, number] | null>();
  let geocodes = 0;
  let after: string | null = null;
  let lastAfter: string | null = null;
  let pilots = 0;

  do {
    const page = await fetchFlairPage(env, token, after);
    if (!page) break;

    for (const [user, flair] of page.flairs) {
      const station = parseStation(flair);
      let coords: [number, number] | null = null;
      if (station !== "") {
        coords = await resolveLatLon(env, station, runCache, () => geocodes < maxGeocodes, () => geocodes++);
      }
      await upsertPilot(env, user, flair, station, coords);
      pilots++;
    }

    after = page.after && page.after !== lastAfter ? page.after : null;
    lastAfter = page.after;
  } while (after);

  console.log(`ingest: done; pilots=${pilots}, new geocodes this run=${geocodes}`);
}

async function getRedditToken(env: Env): Promise<string | null> {
  await rateLimit("redditoauth");
  const body = new URLSearchParams({
    grant_type: "password",
    username: env.REDDIT_USERNAME,
    password: env.REDDIT_PASSWORD,
  });
  const auth = btoa(`${env.REDDIT_API_APP}:${env.REDDIT_API_SECRET}`);

  const res = await fetch(env.REDDIT_TOKEN_URL, {
    method: "POST",
    headers: {
      authorization: `Basic ${auth}`,
      "user-agent": env.REDDIT_USER_AGENT,
      "content-type": "application/x-www-form-urlencoded",
    },
    body,
  });

  const text = await res.text();
  console.log("reddit token response:", maskSecrets(text));

  let obj: any;
  try {
    obj = JSON.parse(text);
  } catch {
    return null;
  }
  if (!obj || obj.error || !obj.access_token) {
    console.log("reddit token error:", obj?.error ?? "unparseable");
    return null;
  }
  return obj.access_token as string;
}

interface FlairPage {
  flairs: Array<[string, string]>;
  after: string | null;
}

async function fetchFlairPage(env: Env, token: string, after: string | null): Promise<FlairPage | null> {
  await rateLimit("redditflair");
  let url = env.REDDIT_FLAIR_URL;
  if (after) {
    url += (url.includes("?") ? "&" : "?") + "after=" + encodeURIComponent(after);
  }

  const res = await fetch(url, {
    headers: {
      authorization: `bearer ${token}`,
      "user-agent": env.REDDIT_USER_AGENT,
      "content-type": "application/json",
    },
  });
  if (!res.ok) {
    console.log("flair fetch http", res.status);
    return null;
  }

  const obj: any = await res.json().catch(() => null);
  if (!obj || obj.error) {
    console.log("flair fetch error:", obj?.error ?? "unparseable");
    return null;
  }

  const flairs: Array<[string, string]> = (obj.users ?? []).map(
    (u: any) => [u.user as string, (u.flair_text ?? "") as string],
  );
  return { flairs, after: obj.next ?? null };
}

async function resolveLatLon(
  env: Env,
  station: string,
  runCache: Map<string, [number, number] | null>,
  canGeocode: () => boolean,
  onGeocode: () => void,
): Promise<[number, number] | null> {
  if (station === "") return null;
  if (runCache.has(station)) return runCache.get(station)!;

  // Cached in D1 from a previous run?
  const row = await env.DB.prepare(
    "SELECT lat, lon FROM rflying_locations WHERE station = ? AND lat IS NOT NULL AND lon IS NOT NULL LIMIT 1",
  )
    .bind(station)
    .first<{ lat: number; lon: number }>();
  if (row) {
    const coords: [number, number] = [row.lat, row.lon];
    runCache.set(station, coords);
    return coords;
  }

  // New station: geocode it, but cap lookups per run to stay within limits.
  if (!canGeocode()) return null;
  onGeocode();
  const coords = await geocodeStation(env, station);
  runCache.set(station, coords);
  return coords;
}

async function geocodeStation(env: Env, station: string): Promise<[number, number] | null> {
  await rateLimit("gcmap");
  const res = await fetch(`https://www.gcmap.com/airport/${encodeURIComponent(station)}`, {
    headers: { "user-agent": env.GEO_USER_AGENT },
    redirect: "follow",
  });
  if (!res.ok) {
    console.log(`gcmap ${station} http`, res.status);
    return null;
  }

  const html = await res.text();
  const m = html.match(
    /abbr class="latitude" title="([0-9.-]+)"[\s\S]*?abbr class="longitude" title="([0-9.-]+)"/i,
  );
  if (!m) {
    console.log(`gcmap ${station}: no lat/lon`);
    return null;
  }
  return [parseFloat(m[1]), parseFloat(m[2])];
}

async function upsertPilot(
  env: Env,
  name: string,
  flair: string,
  station: string,
  coords: [number, number] | null,
): Promise<void> {
  const lat = coords ? coords[0] : null;
  const lon = coords ? coords[1] : null;

  // PostgreSQL 9.x is gone; this is the D1/SQLite upsert. Honors the `locked`
  // flag and won't overwrite a real station with an empty one.
  await env.DB.prepare(
    `INSERT INTO rflying_locations (name, station, lat, lon, flair, time_updated)
     VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
     ON CONFLICT(name) DO UPDATE SET
       time_updated = CURRENT_TIMESTAMP,
       station = excluded.station,
       flair = excluded.flair,
       lat = excluded.lat,
       lon = excluded.lon
     WHERE rflying_locations.locked = 0
       AND (rflying_locations.station <> 'n/a' OR excluded.station <> '')`,
  )
    .bind(name, station, lat, lon, flair)
    .run();
}

// Best-effort per-key rate limiter shared across one isolate's invocation.
const rateLimitNext = new Map<string, number>();
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
async function rateLimit(key: string, delayMs = 1000): Promise<void> {
  const now = Date.now();
  const next = rateLimitNext.get(key) ?? 0;
  if (next > now) await sleep(next - now);
  rateLimitNext.set(key, Date.now() + delayMs);
}
