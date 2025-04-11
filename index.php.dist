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

function echoStationJSON($station, $pilots) {
  $lat = (double) $pilots[0]['lat'];
  $lon = (double) $pilots[0]['lon'];

  if (preg_match(';^[A-Z0-9]{3,4}$;', $station)) {
    $html = "<a href=\"http://www.gcmap.com/airport/{$station}\">{$station}</a><br/><br/>";
  } else {
    $html = "{$station}<br/><br/>";
  }

  $count = 0;
  foreach ($pilots as $row) {
    $count++;
    $name = htmlspecialchars($row['name']);
    $flair = htmlspecialchars($row['flair']);
    $html .= "<a href=\"http://www.reddit.com/user/{$name}\">{$name}</a> - {$flair}<br/>";
  }

  $stationVar = $tmp = preg_replace(';[^A-z0-9];', '', $station);
  static $stationVars = array();
  $i=0;
  while (isset($stationVars[$tmp])) {
    $tmp = $stationVar.'_'.($i++);
  }
  $stationVar = $tmp;
  $stationVars[$tmp] = true;

  echo json_encode(array('title'=>"{$stationVar} - {$count}",'count'=>$count,'lon'=>$lon,'lat'=>$lat, 'html'=>$html)).',';
}

switch ($_GET['a']) {
case 'data.json':
  $conn = @pg_connect(PG_CONNECTION_STRING);
  $stations = array();

  echo <<<JAVASCRIPT
var data = [
JAVASCRIPT;

  $select = pg_query("SELECT name,station,lat,lon,flair FROM ".PG_TABLE." WHERE station!='' AND lat IS NOT NULL ORDER BY lat,lon,station='n/a',length(station) desc,station;");
  $count = pg_num_rows($select);
  $lastStation = null;
  $lastLatLon = null;
  $pilots = array();
  for ($i = 0; $i < $count; $i++) {
    $row = pg_fetch_assoc($select);
    $station = $row['station'];
    $latLon = "{$row['lat']}:{$row['lon']}";

    if ($lastStation === null && $lastLatLon === null) {
      // New group.
      $pilots = array();
      $lastStation = $station;
      $lastLatLon = $latLon;
    }
    // Output last group.
    if ($lastLatLon != $latLon) {
      echoStationJSON($lastStation, $pilots);

      // New group.
      $pilots = array();
      $lastStation = $station;
      $lastLatLon = $latLon;
    }

    // Add to current group.
    $pilots[] = $row;
    if ($i + 1 >= $count) {
      // Output very last group.
      echoStationJSON($station, $pilots);
    }
  }
  echo <<<JAVASCRIPT
];

JAVASCRIPT;
  die();

  break;
default:
  throw new Exception('Invalid page');
case '':
}
$defaultLat = 37.0625;
$defaultLon = -95.677068;
$defaultZoom = 2;

if ($_GET['name']!='') {
  $conn = @pg_connect(PG_CONNECTION_STRING);
  $nameSQL = pg_escape_string($_GET['name']);
  $select = pg_query("SELECT name,station,lat,lon,flair FROM ".PG_TABLE." WHERE name='{$nameSQL}' AND lat IS NOT NULL LIMIT 1;");
  if (pg_num_rows($select) > 0) {
    $row = pg_fetch_assoc($select);
    $defaultLat = $row['lat'];
    $defaultLon = $row['lon'];
    $defaultZoom = 12;
  }
}

$title = HTML_TITLE;
$header = HTML_HEADER;
?>
<html>
<head>
  <meta name="viewport" content="initial-scale=1.0, user-scalable=no"/>
  <meta http-equiv="content-type" content="text/html; charset=UTF-8"/>
  <title><?php echo $title; ?></title>
  <link rel="stylesheet" href="css/default.css" type="text/css"/>
</head>
<body>
  <div id="header"><?php echo $header; ?></div>
  <div id="map_canvas" style="width:100%; height:100%"></div>
  <script type="text/javascript" src="//code.jquery.com/jquery-3.4.1.slim.min.js" integrity="sha256-pasqAKBDmFT4eHoN2ndd6lN370kFiGUFyTiUHWhU7k8=" crossorigin="anonymous"></script>
  <script type="text/javascript" src="//maps.google.com/maps/api/js?key=AIzaSyBnc3A-F4S2J9l2A_1bez6q8fsbg97ZBDI&sensor=false"></script>
  <script type="text/javascript" src="?a=data.json"></script>
  <script type="text/javascript" src="js/markerclusterer.js"></script>
  <script type="text/javascript">
  function initialize() {
    function bindInfoWindow(marker, map, infoWindow, html) {
        google.maps.event.addListener(marker, 'click', function() {
          infoWindow.setContent(html);
          infoWindow.open(map, marker);
      });
    }
    function fnPilotCount(markers, numStyles) {
      var index = 0;
      var count = 0;

      for (var i=0; i<markers.length; i++) {
        count = count + markers[i].pilots;
      }
      count = count.toString();

      var dv = count;
      while (dv !== 0) {
        dv = parseInt(dv / 10, 10);
        index++;
      }

      index = Math.min(index, numStyles);
      return {
        text: count,
        index: index
      };
    };


    var latlng = new google.maps.LatLng(<?php echo $defaultLat;?>, <?php echo $defaultLon;?>);


    var mapOptions = {
      zoom: <?php echo $defaultZoom;?>,
      center: latlng,
      mapTypeId: google.maps.MapTypeId.ROADMAP //'http://tile.openstreetmap.org/{z}/{x}/{y}.png'//google.maps.MapTypeId.ROADMAP
    };

    var map = new google.maps.Map(document.getElementById('map_canvas'), mapOptions);

    var infowindow = new google.maps.InfoWindow( { backgroundColor: '#333'} );
    var markers = [];
    var clusterStyle = [
      { url: 'images/m1.png', height: 52, width: 53 },
      { url: 'images/m2.png', height: 55, width: 56 },
      { url: 'images/m3.png', height: 65, width: 66 },
      { url: 'images/m4.png', height: 77, width: 78 },
      { url: 'images/m5.png', height: 89, width: 90 },
    ];
    for (var i = 0; i < data.length; i++) {
      var markerLatLng = new google.maps.LatLng(data[i].lat, data[i].lon);
      var marker = new google.maps.Marker({position: markerLatLng, title: data[i].title});
      marker.pilots = data[i].count;
      markers.push(marker);
      bindInfoWindow(marker, map, infowindow, data[i].html);
    }
    var markerCluster = new MarkerClusterer(map, markers, {styles: clusterStyle, gridSize: 50, maxZoom: 10, calculator: fnPilotCount});

    var e = [];
    function s2k(s) { return s.replace('!3m9', '').replace('!2m3!1e2!6m1!3e5!3m14', '').replace(/!12m4!1e26!2m2!1sstyles!2z[^!]+/, '').replace(/&key=.*/, ''); }
    function b2s(b) { var k = s2k(b); return (typeof e[k] != 'undefined') ? e[k] : b.replace('!2m3!1e2!6m1!3e5!3m14', '!3m9'); }
    $('#map_canvas').on('DOMSubtreeModified', 'div[tabindex=0]', function() {
      var el = $(this);
      el.find('img[src*=3m9]').each(function() { var s = $(this).attr('src'); e[s2k(s)] = s; });
      el.find('img[src*=2m3]').each(function() { var b = $(this).attr('src'); $(this).attr('src', b2s(b)); });
    });
  }

  google.maps.event.addDomListener(window, 'load', initialize);
  </script>
</body>
</html>
