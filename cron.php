#!/usr/bin/php
<?php
/**
 * @name UserMap for r/flying
 * @version 1.2.1
 * @author R. Brenton Strickler
 *
 * @description This script maps users by their home location based on reddit flair.
 *
 * @license Apache 2.0
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *         http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once('settings.php');

$conn = @pg_connect(PG_CONNECTION_STRING);


function maskSecrets($str)
{
    $str = preg_replace('/"access_token": "[^"]+"/', '"access_token": "**REDACTED**"', $str);
    $str = preg_replace('/eyJhbGciOi[A-Za-z0-9._-]+/', '**REDACTED**', $str);
    return $str;
}

function fetchFlairPage($next = null)
{
    static $ch = null;
    static $url = null;
    static $accessExpiresAt = 0;
    static $accessToken = null;
    static $lastAfter = null;

    // Initialize static variables.
    if ($url === null) {
        $url = URL_REDDIT_FLAIR;
    }

    if ($ch === false || $url === false) {
        return null;
    }

    if (0) {
        $flair = array();
        $select = pg_query("SELECT name, flair FROM ".PG_TABLE);
        while ($row = pg_fetch_assoc($select)) {
            $flair[$row['name']] = $row['flair'];
        }
        $ch = false;
        return $flair;
    }

    if ($ch === null || $accessExpiresAt <= time() || $accessToken === null) {
        // Initialize curl
        if ($ch === null) {
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_USERAGENT, CURL_REDDIT_USERAGENT);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        }

        // Authenticate
        $fields = array(
          'grant_type' => 'password',
          'username' => REDDIT_USERNAME,
          'password' => REDDIT_PASSWORD
        );
        $user = sprintf('%s:%s', REDDIT_API_APP, REDDIT_API_SECRET);

        $_url = URL_REDDIT_ACCESS_TOKEN;
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $_url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_USERPWD, $user);//!
        curl_setopt($ch, CURLOPT_HTTPHEADER, array());

        // Send token request
        printf("%s:%d fetching url=%s\n", __FUNCTION__, __LINE__, $_url);
        rateLimit("redditoauth");
        if (!$ch || !($result = curl_exec($ch))) {
            $ch = false;
            return null;
        }

        printf("%s:%d result=%s\n", __FUNCTION__, __LINE__, maskSecrets($result));
        $obj = json_decode($result);

        if (!is_object($obj)) {
          throw new Exception("Failed to parse json object.");
        }

        if (property_exists($obj, "error")) {
            printf("%s:%d error=%s error_description=%s\n", __FUNCTION__, __LINE__, $obj->error, property_exists($obj, "error_description") ? $obj->error_description: "");
            return null;
        }

        if (!property_exists($obj, "access_token")) {
            throw new Exception("Failed to find access_token.");
        }

        $accessToken = $obj->access_token;

        if (!property_exists($obj, "expires_in")) {
            throw new Exception("Failed to find expires_in.");
        }

        $accessExpiresAt = time() + floor($obj->expires_in * 0.9);


        // Reset curl to GET defaults
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
        curl_setopt($ch, CURLOPT_USERPWD, "");
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', "Authorization: bearer {$accessToken}"));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    printf("%s:%d fetching url=%s\n", __FUNCTION__, __LINE__, $url);
    rateLimit("redditflair");
    if (!($result = curl_exec($ch))) {
        $url = false;
        return null;
    }

    $obj = json_decode($result);

    printf("%s:%d result=%s\n", __FUNCTION__, __LINE__, $result);

    if (!is_object($obj)) {
      throw new Exception("Failed to parse json object.");
    }

    if (property_exists($obj, "error")) {
        printf("%s:%d error=%s message=%s\n", __FUNCTION__, __LINE__, $obj->error, property_exists($obj, "message") ? $obj->message: "");
        return null;
    }

    $after = property_exists($obj, "next") ? $obj->next : null;

    if ($after != null && $lastAfter != $after) {
      $url = URL_REDDIT_FLAIR."&after={$after}";
      $lastAfter = $after;
    } else {
      $url = false;
    }

    $flair = array();

    foreach ($obj->users as $i => $user) {
        $flair[$user->user] = $user->flair_text;
    }

    return $flair;
}

function rateLimit($key, $delay = 1.0)
{
    static $delayUntil = array();

    if (isset($delayUntil[$key])) {
        $delta = $delayUntil[$key] - microtime(true);

        if ($delta > 0) {
            printf("%s:%d sleeping=%s ratelimit=%s\n", __FUNCTION__, __LINE__, $delta, $key);
            usleep($delta);
        }
    }

    $delayUntil[$key] = microtime(true) + $delay;
}

function fetchLatLon($station)
{
    static $ch = null;
    static $stations = array();

    if ($station == '')
        return null;

    // First look if the station lat/lon has already been looked up.
    if (isset($stations[$station]))
        return $stations[$station];

    // Next, see if we already have it in the database.
    $pgStation = pg_escape_string($station);

    $select = pg_query("SELECT lat,lon FROM ".PG_TABLE." WHERE station='{$pgStation}' AND lat IS NOT NULL AND lon IS NOT NULL LIMIT 1;");

    if ($row = pg_fetch_assoc($select)) {
        $stations[$station] = array($row['lat'], $row['lon']);
        printf("%s:%d station=%s, latLon=(%s, %s)\n", __FUNCTION__, __LINE__, $station, $row['lat'], $row['lon']);
        return $stations[$station];;
    }

    if ($ch === false) {
        printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
        return null;
    }

    if ($ch === null) {
        // Initialize curl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, CURL_OTHER_USERAGENT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if(!$ch) {
            $ch = false;
            printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
            return null;
        }
    }

    // As a last resort, let's check gcmap.com for the lat/lon.
    $url = "http://www.gcmap.com/airport/{$station}";

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    rateLimit("gcmap");
    if(!($result = curl_exec($ch))) {
        $url = null;
        printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
        $stations[$station] = null;
        return null;
    }

    // Parse the html from gcmap.com and send them a thank you card.
    $regex = ";abbr class=\"latitude\" title=\"([0-9.-]+)\".*?abbr class=\"longitude\" title=\"([0-9.-]+)\";smi";
    if (preg_match($regex, $result, $regs)) {
        $lat = $regs[1];
        $lon = $regs[2];

        printf("%s:%d station=%s, latLon=(%s, %s)\n", __FUNCTION__, __LINE__, $station, $lat, $lon);
        $stations[$station] = array($lat, $lon);
    } else {
        printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
        $stations[$station] = null;
    }

    //todo: send virtual thank you card to gcmap.com

    return $stations[$station];
}

function updatePilot($name, $flair)
{
  return updatePilot93($name, $flair); // PostgreSQL 9.3
}

function updatePilot93($name, $flair)
{
    $forceLatLonUpdate = true;

    $station = parseStation($flair);

    $pgName = pg_escape_string($name);
    $pgStation = pg_escape_string($station);
    $pgFlair = pg_escape_string($flair);

    $sql = sprintf("SELECT name, station, lat, lon, locked FROM %s WHERE name = '%s';", PG_TABLE, $pgName);
    $select = pg_query($sql);

    $latLon = ($forceLatLonUpdate || pg_num_rows($select) == 0) ? fetchLatLon($station) : null;

    if (is_array($latLon)) {
        $lat = (double) $latLon[0];
        $lon = (double) $latLon[1];
    } else {
        $lat = 'null';
        $lon = 'null';
    }

    $count = pg_num_rows($select);
    printf("%s:%d station=%s, name=%s, lat=%s, lon=%s, flair=%s, rows=%s\n", __FUNCTION__, __LINE__, $station, $name, $lat, $lon, $flair, $count);
    if ($count == 0) {
        $sql = sprintf("INSERT INTO %s (name, station, lat, lon, flair, time_updated) VALUES ('%s', '%s', %s, %s, '%s', NOW());", PG_TABLE, $pgName, $pgStation, $lat, $lon, $pgFlair);
        printf("%s:%d sql=%s\n", __FUNCTION__, __LINE__, $sql);
        $insert = pg_query($sql);
    } else {
        $row = pg_fetch_assoc($select);
        if ($forceLatLonUpdate || $row['station'] != $station) {
            if ($row['locked'] == 'f' && ($row['station'] != 'n/a' || $station != '')) {
                $sql = sprintf("UPDATE %s SET time_updated = NOW(), station = '%s', flair = '%s', lat = %s, lon = %s WHERE name = '%s';", PG_TABLE, $pgStation, $pgFlair, $lat, $lon, $pgName);
                printf("%s:%d sql=%s\n", __FUNCTION__, __LINE__, $sql);
                $update = pg_query($sql);
            }
        }
    }
}

function updatePilot95($name, $flair)
{
    $station = parseStation($flair);

    $pgName = pg_escape_string($name);
    $pgStation = pg_escape_string($station);
    $pgFlair = pg_escape_string($flair);

    $latLon = fetchLatLon($station);

    if (is_array($latLon)) {
        $lat = (double) $latLon[0];
        $lon = (double) $latLon[1];
    } else {
        $lat = 'null';
        $lon = 'null';
    }

    printf("%s:%d station=%s, name=%s, lat=%s, lon=%s, flair=%s, rows=%s\n", __FUNCTION__, __LINE__, $station, $name, $lat, $lon, $flair, $count);
    $sql = sprintf("INSERT INTO %s (name, station, lat, lon, flair, time_updated) VALUES ('%s', '%s', %s, %s, '%s', NOW()) ON CONFLICT(name) DO UPDATE SET time_updated = NOW(), station = '%s', flair = '%s', lat = %s, lon = %s;", PG_TABLE, $pgName, $pgStation, $lat, $lon, $pgFlair, $pgStation, $pgFlair, $lat, $lon);
    printf("%s:%d sql=%s\n", __FUNCTION__, __LINE__, $sql);
    $insert = pg_query($sql);
}

function parseStation($flair)
{
    $station = '';

    if (preg_match(';[^A-Z0-9]([A-Z0-9]{3,4})[)]?$;', trim($flair), $regs)) {
        $station = $regs[1];
        if (preg_match(';^(SIM|ST|SPT|RPL|PPL|CPL|ATP|MIL|ATC|CFI|MEI|ABI|AB|CMP|HP|IR|TW|GLI|MEL|MES|ROT|SEL|SES|ASEL|ASES|SELS|INST|CFII)$;', $station))
            $station = '';
        elseif (preg_match(';^(100|1000|107|111|1159|120|1200|121|125|12W|130|1300|130H|130J|135|135R|140|141|145|146|150|150M|152|15C|15E|160|161|170|172|172P|172S|175|177B|180|181|182|182F|182N|182P|18D|18E|18F|18G|190|1900|195|200|2000|201|2018|208B|20C1|210|210N|212|220|225|227|22B|234|235|250|260|260B|28R|300|310|31A|320|3200|321|327|32R|330|338|340|350|382|390|390S|3IP|400|407|408|411|420|420S|45C|45J|47F|4LYF|500|505|510|525|525B|525C|525S|53E|550|55J|560|56XL|5929|59S|600|604|60S|60T|615|64D|64E|650|680|6II|6P9|6TT|700|707|726|727|72A|737|738|747|750|7500|757|767|7700|777|777F|77W|787|7AC|7ECA|7IN|800|80C2|8200|850|900|A100|A211|A220|A225|A300|A306|A318|A319|A320|A321|A32F|A32X|A330|A350|A351|A36|A380|AA1B|AA5|AA5A|AA5B|AC50|AC95|ACO|ACSO|ADAN|AEST|AGII|AH1Z|AIGI|AL3C|ALPA|AMEL|AMES|AO2|AREO|ARFF|ARMA|ASAP|AT42|AT72|AT76|ATCM|ATPL|B190|B200|B206|B300|B350|B38M|B407|B55|B58P|B703|B707|B717|B727|B73|B737|B738|B73C|B744|B747|B752|B756|B757|B767|B772|B777|B77W|B787|BAMF|BCS1|BCS3|BE02|BE10|BE19|BE20|BE23|BE30|BE35|BE36|BE40|BE55|BE58|BE90|BE99|BE9L|BVD|C120|C130|C140|C150|C152|C162|C172|C177|C182|C208|C212|C25B|C340|C402|C408|C40A|C414|C42|C425|C441|C525|C550|C55B|C560|C56X|C5M|C680|C68A|CAPT|CASA|CFIG|CJ3|CJ4|CJ6|CL30|CLUB|CMEI|CMEL|COMP|CP10|CPLX|CREW|CRJ2|CSEL|CSES|CSIP|CV22|CVR|CWK|CYN3|CZBN|D228|D328|D35K|DA20|DA40|DA42|DA50|DC10|DC3|DC3T|DC8|DC9|DCS|DESK|DH8|DH8C|DH8D|DHC2|DHC6|DHC7|DHC8|DTH|DV20|DZSO|E120|E145|E170|E175|E179|E190|E195|E295|E300|E50P|E550|E55P|EA32|EA50|EASA|EC35|EGTT|EHAA|ENGR|ENSV|ERAU|F100|F15C|F16|F18|F33A|FA8X|FAIP|FDX|FFR|FII|FIR|FISO|FSX|G100|G150|G200|G280|G2CA|G550|G58|G600|GALX|GIA|GIV|GLAS|GLEX|GLID|GROL|GVKT|GYRO|H125|H130|H145|H46|H60|HC3|HELI|HELO|HEMS|ICAO|IFR|IGI|III|IRH|IRST|ISR|JA30|JET|JHZ|K12N|K1C5|K1H0|K1T8|K23N|K39N|K56D|K6R3|K6S8|K7S3|K92F|KAAC|KDCW|KDFK|KI68|KM54|KPLY|KPVR|KT67|KU42|KU77|KW13|KX51|KXYZ|KYSN|L138|L382|L410|LAPL|LIFE|LINE|LJ35|LJ45|LJ75|LKAB|LR35|LR45|LR60|LSAS|LSRM|M20C|M20E|M20F|M20J|M20P|M500|M600|MCCP|MECH|MEII|MEIR|MEOW|MH60|MIFR|MNBT|MODE|MSFS|MU2|MV22|N265|NASA|NAVY|NPPL|NQ9P|NVFR|ONLY|P180|P46T|P80|PC12|PC24|PIMP|PNW|POET|POOR|PPLA|PPLG|PRM1|Q400|R182|R22|R44|RAAF|RAFL|RAMP|RCAF|REAL|RH44|RPC|RPIC|RV10|RV3B|RV7|RVSM|S269|S300|SB20|SC7|SD3|SEAL|SF34|SF50|SHIT|SMEL|SPL|SR22|SR71|STOL|SUAS|SUAV|SW4|SW5|T182|T206|T210|TB20|TBM7|TBM9|TECH|TWI|UAV|UH60|UPRT|UPT|USAF|USMC|UWU|V22|V35B|VET|VFR|WCHN|WEEK|WOCL|WORK|WSC|WTF|WTI|WW24|YBBB|YHEC|YOAS|YPK|YUH|ZAU|ZKC|ZLC|ZMA)$;', $station))
            $station = '';
    } else if (preg_match(';[(]([0-9.-]+),([0-9.-]+)[)];', trim($flair), $regs)) {
        $station = ''; //$station = 'n/a';
        $lat = (double) $regs[1];
        $lon = (double) $regs[2];

        if ($lat == 0 || $lon == 0) {
            $lat = $lon = 0;
        }
    }

    return strtoupper($station);
}

while ($flairs = fetchFlairPage()) {
    foreach ($flairs as $name => $flair) {
        updatePilot($name, $flair);
    }
}
