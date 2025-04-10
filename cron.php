#!/usr/bin/php
<?php
/**
 * @name UserMap for Google Maps
 * @version 1.2.0 [April 10, 2025]
 * @author R. Brenton Strickler
 *
 * @description This script maps users by their home location based on reddit flair.
 *
 * @changelog
 * [June 22, 2020] 1.1.0 Update to use reddit api.
 * [June 25, 2013] 1.0.0 Initial version. 
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
        if (!$ch || !($result = curl_exec($ch))) {
            $ch = false;
            return null;
        }

        printf("%s:%d result=%s\n", __FUNCTION__, __LINE__, $result);
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

function rateLimit($key)
{
    static $delayUntil = array();
    $delay = 1.0;

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
    $forceLatLonUpdate = true;

    $station = getStation($flair);

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
        //$insert = pg_query($sql);
    } else {
        $row = pg_fetch_assoc($select);
        if ($forceLatLonUpdate || $row['station'] != $station) {
            if ($row['locked'] == 'f' && ($row['station'] != 'n/a' || $station != '')) {
                $sql = sprintf("UPDATE %s SET time_updated = NOW(), station = '%s', flair = '%s', lat = %s, lon = %s WHERE name = '%s';", PG_TABLE, $pgStation, $pgFlair, $lat, $lon, $pgName);
                printf("%s:%d sql=%s\n", __FUNCTION__, __LINE__, $sql);
                //$update = pg_query($sql);
            }
        }
    }
}

function getStation($flair)
{
    $station = '';

    if (preg_match(';[^A-Z0-9]([A-Z0-9]{3,4})[)]?$;', trim($flair), $regs)) {
        $station = $regs[1];
        if (preg_match(';^(SIM|ST|SPT|RPL|PPL|CPL|ATP|MIL|ATC|CFI|MEI|ABI|AB|CMP|HP|IR|TW|GLI|MEL|MES|ROT|SEL|SES|ASEL|ASES|SELS|INST|CFII)$;', $station))
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

$dupes = array();

while ($flairs = fetchFlairPage()) {
    foreach ($flairs as $name => $flair) {
        if (isset($dupes[$name])) continue;

        updatePilot($name, $flair);

        $dupes[$name] = true;
    }
}
