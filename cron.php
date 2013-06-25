<?php
/**
 * @name UserMap for Google Maps
 * @version 1.0.0 [June 25, 2013]
 * @author R. Brenton Strickler
 * @fileoverview
 * This script maps users by their home location based on reddit flair.
 */

/**
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
require_once('settings.php');
$conn = @pg_connect(PG_CONNECTION_STRING);


function fetchFlairPage() {
  static $ch = null;
  static $url = 'http://www.reddit.com/r/'.REDDIT_SUB.'/about/flair/';
  static $cookieFile = null;
  $cookieFile = tempnam('/tmp', 'CURLJAR');


  if($ch===false || $url===false) {
    return null;
  }

  if(0) {
    $flair = array();
    $select = pg_query("SELECT name, flair FROM ".PG_TABLE);
    while($row = pg_fetch_assoc($select)) {
      $flair[$row['name']] = $row['flair'];
    }
    $ch=false;
    return $flair;
  }

  if($ch===null) {
    // Initialize curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, 'UserMap 1.0');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    // Authenticate
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_URL, 'https://ssl.reddit.com/api/login/'.REDDIT_USERNAME);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('api_type'=>'json','op'=>'login','passwd'=>REDDIT_PASSWORD,'rem'=>'off','user'=>REDDIT_USERNAME));

    // Send login request
    if(!$ch || !($result=curl_exec($ch))) {
      $ch = false;
      @unlink($cookieFile);
      return null;
    }

    // Reset curl to GET defaults
    curl_setopt($ch, CURLOPT_POST, 0);
  }

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
  if(!($result = curl_exec($ch))) {
    $url = null;
    @unlink($cookieFile);
    return null;
  }

  // Store link to next page
  $regex = ';<a [^>]*href="([^"]*flair\?after=[^"]+)"[^>]*>next</a>;mi';
  if(preg_match($regex, $result, $regs)) {
    $url = $regs[1];
  } else {
    $url = null;
    @unlink($cookieFile);
  }

  // Parse html from page for user flair.
  $regex = ';<a [^>]*class="[^"]*author[^"]*flairselectable[^"]*"[^>]*>([^<]+)</a><span class="[^"]*flair[^"]*"[^>]*>([^<]+)</span>;mi';
  preg_match_all($regex, $result, $regs);

  $flair = array();
  foreach($regs[1] as $i=>$name) {
    $flair[$name]=$regs[2][$i];
  }

  return $flair;
}

function fetchLatLon($station) {
  static $ch = null;
  static $stations = array();

  if($station=='')
    return null;

  // First look if the station lat/lon has already been looked up.
  if(isset($stations[$station]))
    return $stations[$station];

  // Next, see if we already have it in the database.
  $pgStation = pg_escape_string($station);
  $select = pg_query("SELECT lat,lon FROM ".PG_TABLE." WHERE station='{$pgStation}' AND lat IS NOT NULL AND lon IS NOT NULL LIMIT 1;");
  if($row = pg_fetch_assoc($select)) {
    $stations[$station] = array($row['lat'], $row['lon']);
    return $stations[$station];
  }

  if($ch===false) {
    return null;
  }

  if($ch===null) {
    // Initialize curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:10.0.2) Gecko/20100101 Firefox/10.0.2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    if(!$ch) {
      $ch = false;
      return null;
    }
  }

  // As a last resort, let's check gcmap.com for the lat/lon.
  $url = "http://www.gcmap.com/airport/{$station}";

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

  if(!($result = curl_exec($ch))) {
    $url = null;
    return null;
  }

  // Parse the html from gcmap.com and send them a thank you card.
  $regex = ";abbr class=\"latitude\" title=\"([0-9.-]+)\".*?abbr class=\"longitude\" title=\"([0-9.-]+)\";smi";
  if(preg_match($regex, $result, $regs)) {
    $lat = $regs[1];
    $lon = $regs[2];

    $stations[$station] = array($lat, $lon);
  } else {
    $stations[$station] = null;
  }

  //todo: send virtual thank you card to gcmap.com

  return $stations[$station];
}


$pilots = array();
while($flairs = fetchFlairPage()) {
  foreach($flairs as $user=>$flair) {
    $base = '';
    if(preg_match(';[^A-Z0-9]([A-Z0-9]{3,4})[)]?$;', trim($flair), $regs)) {
      $base = $regs[1];
      if(preg_match(';^(SIM|ST|SPT|RPL|PPL|CPL|ATP|MIL|ATC|CFI|MEI|ABI|AB|CMP|HP|IR|TW|GLI|MEL|MES|ROT|SEL|SES|ASEL|ASES|SELS|INST|CFII)$;', $base))
        $base = '';
    } else if(preg_match(';[(]([0-9.-]+),([0-9.-]+)[)];', trim($flair), $regs)) {
      $base = '';//$base = 'n/a';
      $lat = (double) $regs[1];
      $lon = (double) $regs[2];
      if($lat==0 || $lon==0) $lat = $lon = 0;
    }
    if(!isset($pilots[$user])) {
      $pilots[$user] = array($flair,$base);
    }
  }
}

$forceLatLonUpdate = true;
foreach($pilots as $user=>$data) {
  list($flair,$base) = $data;
  $pgName = pg_escape_string($user);
  $pgStation = pg_escape_string(strtoupper($base));
  $pgFlair = pg_escape_string($flair);
  $select = pg_query("SELECT name,station,lat,lon,locked FROM ".PG_TABLE." WHERE name='{$pgName}';");

  $latLon = ($forceLatLonUpdate||pg_num_rows($select)==0) ? fetchLatLon($pgStation) : null;
  if(is_array($latLon)) {
    $lat = (double) $latLon[0];
    $lon = (double) $latLon[1];
  } else {
    $lat = 'null';
    $lon = 'null';
  }

  if(pg_num_rows($select)==0) {
    pg_query("INSERT INTO ".PG_TABLE." (name, station, lat, lon, flair) VALUES ('{$pgName}', '{$pgStation}', {$lat}, {$lon}, '{$pgFlair}');");
  } else {
    $row = pg_fetch_assoc($select);
    if($forceLatLonUpdate||$row['station']!=$base) {
      if(!$row['locked'] && ($row['station']!='n/a' || $base!=''))
        pg_query("UPDATE ".PG_TABLE." SET station='{$pgStation}',flair='{$pgFlair}',lat={$lat},lon={$lon} WHERE name='{$pgName}';");
    }
  }
}
