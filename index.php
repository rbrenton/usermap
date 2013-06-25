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

  if(preg_match(';^[A-Z0-9]{3,4}$;', $station)) {
    $html = "<a href=\"http://www.gcmap.com/airport/{$station}\">{$station}</a><br/><br/>";
  } else {
    $html = "{$station}<br/><br/>";
  }

  $count = 0;
  foreach($pilots as $row) {
    $count++;
    $name = htmlspecialchars($row['name']);
    $flair = htmlspecialchars($row['flair']);
    $html .= "<a href=\"http://www.reddit.com/user/{$name}\">{$name}</a> - {$flair}<br/>";
  }

  $stationVar = $tmp = preg_replace(';[^A-z0-9];', '', $station);
  static $stationVars = array();
  $i=0;
  while(isset($stationVars[$tmp])) {
    $tmp = $stationVar.'_'.($i++);
  }
  $stationVar = $tmp;
  $stationVars[$tmp] = true;

  echo json_encode(array('title'=>"{$stationVar} - {$count}",'count'=>$count,'lon'=>$lon,'lat'=>$lat, 'html'=>$html)).',';
}

switch($_GET['a']) {
case 'data.json':
  $conn = @pg_connect(PG_CONNECTION_STRING);
  $stations = array();

  echo <<<JAVASCRIPT
var data = [
JAVASCRIPT;

  $select = pg_query("SELECT name,station,lat,lon,flair FROM ".PG_TABLE." WHERE station!='' AND lat IS NOT NULL ORDER BY station='n/a',station,lat,lon;");
  $count = pg_num_rows($select);
  $lastStation = null;
  $pilots = array();
  for($i=0; $i<$count; $i++) {
    $row = pg_fetch_assoc($select);
    if($lastStation==null) {
      $pilots = array();
      $lastStation = $row['station'];
    }
    if($lastStation!=$row['station']) {
      echoStationJSON($lastStation, $pilots);
      $pilots = array();
    }
    $pilots[] = $row;
    if($i+1>=$count) {
      echoStationJSON($row['station'], $pilots);
    }
    $lastStation=$row['station'];
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

if($_GET['name']!='') {
  $conn = @pg_connect(PG_CONNECTION_STRING);
  $nameSQL = pg_escape_string($_GET['name']);
  $select = pg_query("SELECT name,station,lat,lon,flair FROM ".PG_TABLE." WHERE name='{$nameSQL}' AND lat IS NOT NULL LIMIT 1;");
  if(pg_num_rows($select)>0) {
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
<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=false"></script>
<script type="text/javascript" src="?a=data.json"></script>
<script type="text/javascript" src="js/markerclusterer.js"></script>
<link rel="stylesheet" href="css/default.css" type="text/css"/>
</head>
<body>
<div id="header"><?php echo $header; ?></div>
<div id="map_canvas" style="width:100%; height:100%"></div>

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

    for(var i=0; i<markers.length; i++) {
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


  var map = new google.maps.Map(document.getElementById('map_canvas'), {
    zoom: <?php echo $defaultZoom;?>,
    center: latlng,
    mapTypeId: google.maps.MapTypeId.ROADMAP
  });

  var infowindow = new google.maps.InfoWindow( { backgroundColor: '#333'} );
  var markers = [];
  for (var i = 0; i < data.length; i++) {
    var markerLatLng = new google.maps.LatLng(data[i].lat, data[i].lon);
    var marker = new google.maps.Marker({position: markerLatLng, title: data[i].title});
    marker.pilots = data[i].count;
    markers.push(marker);
    bindInfoWindow(marker, map, infowindow, data[i].html);
  }
  var markerCluster = new MarkerClusterer(map, markers, {gridSize: 50, maxZoom: 10, calculator: fnPilotCount});
}

google.maps.event.addDomListener(window, 'load', initialize);
</script>
</body>
</html>
