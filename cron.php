#!/usr/bin/php
<?php
/**
 * @name UserMap for r/flying
 * @version 1.3.0
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
require_once(__DIR__ . '/lib/RateLimiter.php');
require_once(__DIR__ . '/lib/RedditFlairClient.php');

// Prevent overlapping cron executions.
$lockFile = sys_get_temp_dir() . '/usermap-cron.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fprintf(STDERR, "%s: another instance is already running\n", date('c'));
    exit(1);
}

$conn = pg_connect(PG_CONNECTION_STRING);
if (!$conn) {
    fprintf(STDERR, "%s: database connection failed\n", date('c'));
    exit(1);
}

$rateLimiter = new RateLimiter();


function fetchLatLon($conn, $station, RateLimiter $rateLimiter)
{
    static $ch = null;
    static $stations = array();

    if ($station === '')
        return null;

    if (isset($stations[$station]))
        return $stations[$station];

    // Check database cache before hitting the network.
    $select = pg_query_params($conn,
        "SELECT lat, lon FROM " . PG_TABLE . " WHERE station=$1 AND lat IS NOT NULL AND lon IS NOT NULL LIMIT 1",
        array($station)
    );

    if ($row = pg_fetch_assoc($select)) {
        $stations[$station] = array($row['lat'], $row['lon']);
        printf("%s:%d station=%s, latLon=(%s, %s)\n", __FUNCTION__, __LINE__, $station, $row['lat'], $row['lon']);
        return $stations[$station];
    }

    if ($ch === false) {
        printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
        return null;
    }

    if ($ch === null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, CURL_OTHER_USERAGENT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if (!$ch) {
            $ch = false;
            printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
            return null;
        }
    }

    $url = "https://www.gcmap.com/airport/{$station}";
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    $rateLimiter->wait('gcmap');
    if (!($result = curl_exec($ch))) {
        printf("%s:%d station=%s, latLon=null\n", __FUNCTION__, __LINE__, $station);
        $stations[$station] = null;
        return null;
    }

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

    return $stations[$station];
}


function updatePilot($conn, $name, $flair, RateLimiter $rateLimiter)
{
    return updatePilot93($conn, $name, $flair, $rateLimiter);
}


function updatePilot93($conn, $name, $flair, RateLimiter $rateLimiter)
{
    $station = parseStation($flair);

    $select = pg_query_params($conn,
        "SELECT name, station, lat, lon, locked FROM " . PG_TABLE . " WHERE name = $1",
        array($name)
    );

    $existingRow    = pg_num_rows($select) > 0 ? pg_fetch_assoc($select) : null;
    $stationChanged = ($existingRow === null || $existingRow['station'] !== $station);
    $latLon         = $stationChanged ? fetchLatLon($conn, $station, $rateLimiter) : null;
    $latParam       = is_array($latLon) ? (double)$latLon[0] : null;
    $lonParam       = is_array($latLon) ? (double)$latLon[1] : null;

    printf("%s:%d station=%s, name=%s, lat=%s, lon=%s, flair=%s, rows=%d\n",
        __FUNCTION__, __LINE__, $station, $name,
        $latParam !== null ? $latParam : 'null',
        $lonParam !== null ? $lonParam : 'null',
        $flair, $existingRow !== null ? 1 : 0
    );

    if ($existingRow === null) {
        pg_query_params($conn,
            "INSERT INTO " . PG_TABLE . " (name, station, lat, lon, flair, time_updated) VALUES ($1, $2, $3, $4, $5, NOW())",
            array($name, $station, $latParam, $lonParam, $flair)
        );
    } elseif ($stationChanged
        && $existingRow['locked'] !== 't'
        && ($existingRow['station'] !== 'n/a' || $station !== ''))
    {
        pg_query_params($conn,
            "UPDATE " . PG_TABLE . " SET time_updated = NOW(), station = $1, flair = $2, lat = $3, lon = $4 WHERE name = $5",
            array($station, $flair, $latParam, $lonParam, $name)
        );
    }
}


function updatePilot95($conn, $name, $flair, RateLimiter $rateLimiter)
{
    $station  = parseStation($flair);
    $latLon   = fetchLatLon($conn, $station, $rateLimiter);
    $latParam = is_array($latLon) ? (double)$latLon[0] : null;
    $lonParam = is_array($latLon) ? (double)$latLon[1] : null;

    printf("%s:%d station=%s, name=%s, lat=%s, lon=%s, flair=%s\n",
        __FUNCTION__, __LINE__, $station, $name,
        $latParam !== null ? $latParam : 'null',
        $lonParam !== null ? $lonParam : 'null',
        $flair
    );
    pg_query_params($conn,
        "INSERT INTO " . PG_TABLE . " (name, station, lat, lon, flair, time_updated) VALUES ($1, $2, $3, $4, $5, NOW())
         ON CONFLICT(name) DO UPDATE SET
           time_updated = NOW(),
           station      = EXCLUDED.station,
           flair        = EXCLUDED.flair,
           lat          = EXCLUDED.lat,
           lon          = EXCLUDED.lon
         WHERE " . PG_TABLE . ".locked = false",
        array($name, $station, $latParam, $lonParam, $flair)
    );
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


$client = new RedditFlairClient(
    REDDIT_USERNAME,
    REDDIT_PASSWORD,
    REDDIT_API_APP,
    REDDIT_API_SECRET,
    URL_REDDIT_FLAIR,
    URL_REDDIT_ACCESS_TOKEN,
    CURL_REDDIT_USERAGENT,
    $rateLimiter
);

while (($flairs = $client->fetchNextPage()) !== null) {
    foreach ($flairs as $name => $flair) {
        updatePilot($conn, $name, $flair, $rateLimiter);
    }
}
