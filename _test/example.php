<?php

/*
 * Copyright © 2024 Netas Ltd., Switzerland.
 * MIT Licensed
 * @author  Lukas Buchs, lukas.buchs@netas.ch
 * @date    2024-02-08
 */

require_once '../src/MapTileStitching.php';

// Example with Google Earth
// Check License before use
// check https://developers.google.com/maps/documentation/tile/session_tokens how to fetch a session token
$sessionToken = 'YOUR_SESSION_TOKEN';
$apiKey = 'YOUR_API_KEY';
$url = 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session=' . urlencode($sessionToken) . '&key=' . urlencode($apiKey);
$copyright = '(c) ' . date('Y') . ' Google Maps';
$zoom = 18;

// Example with ESRI
// Check License before use
$url = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
$copyright = '(c) ' . date('Y') . ' ESRI';
$zoom = 18;

// Example with Swisstopo
// Check License before use
$url = 'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg';
$copyright = '(c) ' . date('Y') . ' swisstopo.ch';
$zoom = 20;

// create a instance
$mapTileStitching = new netas\lib\maptilestitching\MapTileStitching($url, $copyright);

// create a image of 1920*1080 Pixels centered at 46.81874, 7.58231
$image = $mapTileStitching->createImage(46.81874, 7.58231, 1920, 1080, $zoom);
unset ($mapTileStitching);

// Output of \GdImage to the browser
header("Content-Type: image/jpeg");
imagejpeg($image); // use imagejpeg($image, $path); to save it to a file
imagedestroy($image);
