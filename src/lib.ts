/**
 * Pure helpers for usermap — no Worker/runtime dependencies, so they can be
 * unit-tested directly with `node --experimental-strip-types`.
 */

export interface PilotRow {
  name: string;
  station: string;
  lat: number;
  lon: number;
  flair: string;
}

export interface StationGroup {
  title: string;
  count: number;
  lon: number;
  lat: number;
  html: string;
}

/** Escape a value for safe interpolation into HTML. */
export function esc(value: unknown): string {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/** Coerce a config value (wrangler vars may be number or string) to a number. */
export function num(value: unknown): number {
  return typeof value === "number" ? value : parseFloat(String(value));
}

/** Redact OAuth tokens before logging upstream responses. */
export function maskSecrets(str: string): string {
  return str
    .replace(/"access_token"\s*:\s*"[^"]+"/g, '"access_token": "**REDACTED**"')
    .replace(/eyJhbGciOi[A-Za-z0-9._-]+/g, "**REDACTED**");
}

// A 3-4 char alnum code at the end of the flair, preceded by a boundary char.
const RE_STATION = /[^A-Z0-9]([A-Z0-9]{3,4})\)?$/;
// Explicit "(lat,lon)" flair — currently ignored (matches legacy behavior).
const RE_COORDS = /\(([0-9.-]+),([0-9.-]+)\)/;
// Ratings/certificates that look like station codes but are not airports.
const RE_EXCLUDE_RATING =
  /^(SIM|ST|SPT|RPL|PPL|CPL|ATP|MIL|ATC|CFI|MEI|ABI|AB|CMP|HP|IR|TW|GLI|MEL|MES|ROT|SEL|SES|ASEL|ASES|SELS|INST|CFII)$/;
// Aircraft types / known bad lookups that also match the station pattern.
const RE_EXCLUDE_AIRCRAFT =
  /^(100|1000|107|111|1159|120|1200|121|125|12W|130|1300|130H|130J|135|135R|140|141|145|146|150|150M|152|15C|15E|160|161|170|172|172P|172S|175|177B|180|181|182|182F|182N|182P|18D|18E|18F|18G|190|1900|195|200|2000|201|2018|208B|20C1|210|210N|212|220|225|227|22B|234|235|250|260|260B|28R|300|310|31A|320|3200|321|327|32R|330|338|340|350|382|390|390S|3IP|400|407|408|411|420|420S|45C|45J|47F|4LYF|500|505|510|525|525B|525C|525S|53E|550|55J|560|56XL|5929|59S|600|604|60S|60T|615|64D|64E|650|680|6II|6P9|6TT|700|707|726|727|72A|737|738|747|750|7500|757|767|7700|777|777F|77W|787|7AC|7ECA|7IN|800|80C2|8200|850|900|A100|A211|A220|A225|A300|A306|A318|A319|A320|A321|A32F|A32X|A330|A350|A351|A36|A380|AA1B|AA5|AA5A|AA5B|AC50|AC95|ACO|ACSO|ADAN|AEST|AGII|AH1Z|AIGI|AL3C|ALPA|AMEL|AMES|AO2|AREO|ARFF|ARMA|ASAP|AT42|AT72|AT76|ATCM|ATPL|B190|B200|B206|B300|B350|B38M|B407|B55|B58P|B703|B707|B717|B727|B73|B737|B738|B73C|B744|B747|B752|B756|B757|B767|B772|B777|B77W|B787|BAMF|BCS1|BCS3|BE02|BE10|BE19|BE20|BE23|BE30|BE35|BE36|BE40|BE55|BE58|BE90|BE99|BE9L|BVD|C120|C130|C140|C150|C152|C162|C172|C177|C182|C208|C212|C25B|C340|C402|C408|C40A|C414|C42|C425|C441|C525|C550|C55B|C560|C56X|C5M|C680|C68A|CAPT|CASA|CFIG|CJ3|CJ4|CJ6|CL30|CLUB|CMEI|CMEL|COMP|CP10|CPLX|CREW|CRJ2|CSEL|CSES|CSIP|CV22|CVR|CWK|CYN3|CZBN|D228|D328|D35K|DA20|DA40|DA42|DA50|DC10|DC3|DC3T|DC8|DC9|DCS|DESK|DH8|DH8C|DH8D|DHC2|DHC6|DHC7|DHC8|DTH|DV20|DZSO|E120|E145|E170|E175|E179|E190|E195|E295|E300|E50P|E550|E55P|EA32|EA50|EASA|EC35|EGTT|EHAA|ENGR|ENSV|ERAU|F100|F15C|F16|F18|F33A|FA8X|FAIP|FDX|FFR|FII|FIR|FISO|FSX|G100|G150|G200|G280|G2CA|G550|G58|G600|GALX|GIA|GIV|GLAS|GLEX|GLID|GROL|GVKT|GYRO|H125|H130|H145|H46|H60|HC3|HELI|HELO|HEMS|ICAO|IFR|IGI|III|IRH|IRST|ISR|JA30|JET|JHZ|K12N|K1C5|K1H0|K1T8|K23N|K39N|K56D|K6R3|K6S8|K7S3|K92F|KAAC|KDCW|KDFK|KI68|KM54|KPLY|KPVR|KT67|KU42|KU77|KW13|KX51|KXYZ|KYSN|L138|L382|L410|LAPL|LIFE|LINE|LJ35|LJ45|LJ75|LKAB|LR35|LR45|LR60|LSAS|LSRM|M20C|M20E|M20F|M20J|M20P|M500|M600|MCCP|MECH|MEII|MEIR|MEOW|MH60|MIFR|MNBT|MODE|MSFS|MU2|MV22|N265|NASA|NAVY|NPPL|NQ9P|NVFR|ONLY|P180|P46T|P80|PC12|PC24|PIMP|PNW|POET|POOR|PPLA|PPLG|PRM1|Q400|R182|R22|R44|RAAF|RAFL|RAMP|RCAF|REAL|RH44|RPC|RPIC|RV10|RV3B|RV7|RVSM|S269|S300|SB20|SC7|SD3|SEAL|SF34|SF50|SHIT|SMEL|SPL|SR22|SR71|STOL|SUAS|SUAV|SW4|SW5|T182|T206|T210|TB20|TBM7|TBM9|TECH|TWI|UAV|UH60|UPRT|UPT|USAF|USMC|UWU|V22|V35B|VET|VFR|WCHN|WEEK|WOCL|WORK|WSC|WTF|WTI|WW24|YBBB|YHEC|YOAS|YPK|YUH|ZAU|ZKC|ZLC|ZMA)$/;

/**
 * Extract an airport station code from a flair string, filtering out ratings
 * and aircraft types that happen to match the code shape. Returns '' when no
 * usable station is found. Ported from the legacy parseStation() in cron.php.
 */
export function parseStation(flair: string | null | undefined): string {
  let station = "";
  const f = (flair ?? "").trim();

  const m = f.match(RE_STATION);
  if (m) {
    station = m[1];
    if (RE_EXCLUDE_RATING.test(station) || RE_EXCLUDE_AIRCRAFT.test(station)) {
      station = "";
    }
  } else if (RE_COORDS.test(f)) {
    station = ""; // explicit coordinates are not currently mapped
  }

  return station.toUpperCase();
}

/** Build the front-end payload for a single map marker (a lat/lon group). */
export function buildStation(
  station: string,
  pilots: PilotRow[],
  usedTitles: Set<string>,
): StationGroup {
  const lat = Number(pilots[0].lat);
  const lon = Number(pilots[0].lon);

  let html = /^[A-Z0-9]{3,4}$/.test(station)
    ? `<a href="https://www.gcmap.com/airport/${esc(station)}">${esc(station)}</a><br/><br/>`
    : `${esc(station)}<br/><br/>`;

  let count = 0;
  for (const row of pilots) {
    count++;
    html += `<a href="https://www.reddit.com/user/${esc(row.name)}">${esc(row.name)}</a> - ${esc(row.flair)}<br/>`;
  }

  // De-duplicate the (alnum-only) station label so map titles stay unique.
  const base = station.replace(/[^A-Za-z0-9]/g, "");
  let label = base;
  let i = 0;
  while (usedTitles.has(label)) {
    label = `${base}_${i++}`;
  }
  usedTitles.add(label);

  return { title: `${label} - ${count}`, count, lon, lat, html };
}

/**
 * Collapse rows (pre-sorted by lat,lon) into one marker per distinct lat/lon.
 * The representative station is the first row of each group (the ORDER BY in
 * the query puts the most specific, non-"n/a" station first).
 */
export function groupStations(rows: PilotRow[]): StationGroup[] {
  const out: StationGroup[] = [];
  const usedTitles = new Set<string>();

  let i = 0;
  while (i < rows.length) {
    const key = `${rows[i].lat}:${rows[i].lon}`;
    const station = rows[i].station;
    const pilots: PilotRow[] = [];
    while (i < rows.length && `${rows[i].lat}:${rows[i].lon}` === key) {
      pilots.push(rows[i]);
      i++;
    }
    out.push(buildStation(station, pilots, usedTitles));
  }

  return out;
}
