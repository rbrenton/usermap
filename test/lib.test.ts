import assert from "node:assert/strict";
import { test } from "node:test";

import {
  buildStation,
  esc,
  groupStations,
  maskSecrets,
  num,
  parseStation,
  type PilotRow,
} from "../src/lib.ts";

test("parseStation extracts a trailing airport code", () => {
  assert.equal(parseStation("PPL - KJFK"), "KJFK");
  assert.equal(parseStation("Student pilot (KORD)"), "KORD");
  assert.equal(parseStation("flying out of KLAX"), "KLAX");
});

test("parseStation is case-sensitive (matches legacy behavior)", () => {
  // The legacy regex matched uppercase codes only; lowercase is not a station.
  assert.equal(parseStation("based at lax"), "");
});

test("parseStation filters ratings and aircraft types", () => {
  assert.equal(parseStation("PPL ASEL"), ""); // rating
  assert.equal(parseStation("I fly a C172"), ""); // aircraft
  assert.equal(parseStation("airline guy B737"), ""); // aircraft
});

test("parseStation ignores explicit coordinates and empty flair", () => {
  assert.equal(parseStation("home (40.7,-74.0)"), "");
  assert.equal(parseStation(""), "");
  assert.equal(parseStation(null), "");
});

test("groupStations groups rows by lat/lon and counts pilots", () => {
  const rows: PilotRow[] = [
    { name: "alice", station: "KJFK", lat: 40.64, lon: -73.78, flair: "PPL KJFK" },
    { name: "bob", station: "KJFK", lat: 40.64, lon: -73.78, flair: "IR KJFK" },
    { name: "carol", station: "KSFO", lat: 37.62, lon: -122.38, flair: "ATP KSFO" },
  ];
  const groups = groupStations(rows);

  assert.equal(groups.length, 2);
  const jfk = groups.find((g) => g.title.startsWith("KJFK"))!;
  assert.equal(jfk.count, 2);
  assert.equal(jfk.lat, 40.64);
  assert.equal(jfk.lon, -73.78);
  assert.match(jfk.html, /alice/);
  assert.match(jfk.html, /bob/);
  assert.match(jfk.html, /gcmap\.com\/airport\/KJFK/);
});

test("buildStation de-duplicates identical station labels", () => {
  const used = new Set<string>();
  const a = buildStation("KJFK", [{ name: "a", station: "KJFK", lat: 1, lon: 1, flair: "x" }], used);
  const b = buildStation("KJFK", [{ name: "b", station: "KJFK", lat: 2, lon: 2, flair: "y" }], used);
  assert.equal(a.title, "KJFK - 1");
  assert.equal(b.title, "KJFK_0 - 1");
});

test("esc escapes HTML-significant characters", () => {
  assert.equal(esc(`<a href="x">&'`), "&lt;a href=&quot;x&quot;&gt;&amp;&#39;");
});

test("maskSecrets redacts tokens", () => {
  const masked = maskSecrets('{"access_token": "abc123", "x": 1}');
  assert.match(masked, /\*\*REDACTED\*\*/);
  assert.doesNotMatch(masked, /abc123/);
});

test("num coerces strings and numbers", () => {
  assert.equal(num("37.0625"), 37.0625);
  assert.equal(num(2), 2);
});
