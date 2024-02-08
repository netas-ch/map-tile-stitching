# Map Tile Stitching
This small PHP library takes coordinates in WGS 84 (lat/lon) format and generates a image with the provided coordinates centered in the middle of the image.
It loads the tiles from a provided tile server like ESRI, Google Earth, Swisstopo, etc...

## usage

1. define your map tile provider and create a instance:

```php
// Swisstopo Map Tile Provider
$url = 'https://wmts.geo.admin.ch/1.0.0/ch.swisstopo.swissimage/default/current/3857/{z}/{x}/{y}.jpeg';
$copyright = '(c) ' . date('Y') . ' swisstopo.ch';
$zoom = 20;

// create a instance
$mapTileStitching = new netas\lib\maptilestitching\MapTileStitching($url, $copyright);
```

2. create a image centered at lat/lon with the size 1920x1080:

```php
$image = $mapTileStitching->createImage(46.81874, 7.58231, 1920, 1080, $zoom);
```

3. output the image to the browser
```php
header("Content-Type: image/jpeg");
imagejpeg($image);
imagedestroy($image);
```

4. or save it as a file
```php
imagejpeg($image, 'map.jpg');
imagedestroy($image);
```