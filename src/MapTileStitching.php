<?php

namespace netas\lib\maptilestitching;

/*
 * Copyright © 2024 Netas Ltd., Switzerland.
 * MIT Licensed
 * @author  Lukas Buchs, lukas.buchs@netas.ch
 * @date    2024-02-08
 */
class MapTileStitching {
    protected $_tileProviderUrl;
    protected $_tileSize;               // Tile Size in Pixel
    protected $_googleTileFormat;
    protected $_copyrightNotice;
    protected $_earthRadius = 6378137;  // Earth's equatorial radius in meters
    protected $_initialResolution;
    protected $_originShift;
    protected ?\CurlHandle $_curl = null;

    /**
     * @param string $tileProviderUrl  URL for tiles with parameters {z}/{x}/{y}
     * @param string $copyrightNotice  URL for tiles with parameters {z}/{x}/{y}
     * @param int $tileSize            Tile size, default to 256
     * @param bool $googleTileFormat   false for TMS tile coordinates instead of Google Tile coordinates (default)
     */
    public function __construct(string $tileProviderUrl, ?string $copyrightNotice=null, int $tileSize=256, bool $googleTileFormat=true) {
        $this->_tileProviderUrl = $tileProviderUrl;
        $this->_googleTileFormat = $googleTileFormat;
        $this->_copyrightNotice = $copyrightNotice;
        $this->_tileSize = $tileSize;
        $this->_initialResolution = 2 * \M_PI * $this->_earthRadius / $this->_tileSize;
        $this->_originShift = 2 * \M_PI * $this->_earthRadius / 2.0;
    }


    public function __destruct() {
        if ($this->_curl) {
            curl_close($this->_curl);
        }
    }

    /**
     * creates a image for a given lat-lon
     * @param float $lat
     * @param float $lon
     * @param int $width
     * @param int $height
     * @param int $zoomLevel
     * @return GdImage
     * @throws \Exception
     */
    public function createImage(float $lat, float $lon, int $width, int $height, int $zoomLevel=20): \GdImage {
        list ($tx, $ty) = $this->_getTileCoordinates($lat, $lon, $zoomLevel);

        list($minLat, $minLon, $maxLat, $maxLon) = $this->_tileLatLonBounds($tx, $ty, $zoomLevel);

        $yPxl = $this->_tileSize / \abs($maxLat - $minLat) * \abs($lat - $minLat);
        $xPxl = $this->_tileSize / \abs($maxLon - $minLon) * \abs($lon - $minLon);

        $yPxl = $this->_tileSize - (int)round($yPxl);
        $xPxl = (int)round($xPxl);

        $tilesTop = \ceil((\round($height/2) - $yPxl) / $this->_tileSize); // number of Tiles above target
        $tilesLeft = \ceil((\round($width/2) - $xPxl) / $this->_tileSize); // number of Tiles left of arget

        $yTileOffset = \round($height/2) - (($tilesTop * $this->_tileSize) + $yPxl); // offset in Pixel
        $xTileOffset = \round($width/2) - (($tilesLeft * $this->_tileSize) + $xPxl); // offset in Pixel

        if ($xTileOffset > 0 || $yTileOffset > 0) {
            throw new \Exception('invalid tile offset...');
        }

        $image = \imagecreatetruecolor($width, $height);

        $tileY = $ty - $tilesTop;
        for ($py = $yTileOffset; $py < $height; $py += $this->_tileSize) {
            $tileX = $tx - $tilesLeft;
            for ($px = $xTileOffset; $px < $width; $px += $this->_tileSize) {

                $tile = $this->_downloadTile($tileX, $tileY, $zoomLevel);
                \imagecopy($image, $tile, $px, $py, 0, 0, $this->_tileSize, $this->_tileSize);
                \imagedestroy($tile);

                $tileX++;
            }
            $tileY++;
        }

        if ($this->_copyrightNotice) {
            $txt = \mb_convert_encoding($this->_copyrightNotice, 'ISO-8859-2');
            $len = \strlen($txt);
            $font = 3;
            $color = \imagecolorallocate($image, 0, 0, 0);
            \imagestring($image, $font, $width - ($len * \imagefontwidth($font)) - 3, $height - \imagefontheight($font) - 3, $txt, $color);
        }

        return $image;
    }


    protected function _downloadTile(int $x, int $y, int $z) : \GdImage {
        if (!$this->_curl) {
            $this->_curl = \curl_init();
        }
        $url = \str_replace(['{z}', '{x}', '{y}'], [(string)$z, (string)$x, (string)$y], $this->_tileProviderUrl);

        \curl_setopt($this->_curl, \CURLOPT_URL, $url);
        \curl_setopt($this->_curl, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($this->_curl, \CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($this->_curl, \CURLOPT_USERAGENT, 'SatelliteImageFetch/1.0.0');
        \curl_setopt($this->_curl, \CURLOPT_TIMEOUT, 5);
        $rep = \curl_exec($this->_curl);

        if ($rep === false) {
            throw new \Exception('fetch error while fetching ' . $url . ': ' . curl_error($this->_curl));
        }

        // GD Image erstellen
        $img = \imagecreatefromstring($rep);
        if ($img === false) {
            throw new \Exception('image create error while fetching ' . $url . '.');
        }

        if (\imagesx($img) !== $this->_tileSize || \imagesy($img) !== $this->_tileSize) {
            throw new \Exception('image create error while fetching ' . $url . ': wrong tile size (Expected: ' . $this->_tileSize . 'px)');
        }

        return $img;
    }

    protected function _getTileCoordinates(float $lat, float $lon, int $zoom): array {
        list ($x, $y) = $this->_latLonToMeters($lat, $lon);
        list ($px, $py) = $this->_metersToPixel($x, $y, $zoom);
        list ($tx, $ty) = $this->_pixelsToTile($px, $py);
        if ($this->_googleTileFormat) {
            return $this->_tileToGoogleTile($tx, $ty, $zoom);
        }
        return [$tx, $ty];
    }

    // Converts XY point from lat/lon in WGS84 to Spherical Mercator EPSG:900913
    protected function _latLonToMeters(float $lat, float $lon): array {

        $mx = $lon * $this->_originShift / 180.0;
        $my = \log(\tan((90 + $lat) * \M_PI / 360.0 )) / (\M_PI / 180.0);
        $my = $my * $this->_originShift / 180.0;

        return [$mx, $my];
    }

    // Converts XY point from Spherical Mercator EPSG:900913 to lat/lon in WGS84 Datum
    protected function _metersToLatLon(float $mx, float $my): array {
        $lon = ($mx / $this->_originShift) * 180.0;
        $lat = ($my / $this->_originShift) * 180.0;

        $lat = 180 / \M_PI * (2 * \atan(\exp($lat * \M_PI / 180.0)) - \M_PI / 2.0);
        return [$lat, $lon];
    }

    protected function _metersToPixel(float $x, float $y, int $zoom): array {
        $res = $this->_resolution($zoom);
        return [
            ($x + $this->_originShift) / $res,
            ($y + $this->_originShift) / $res
        ];
    }

    protected function _resolution(int $zoom): float {
        return $this->_initialResolution / \pow(2, $zoom);
    }

    protected function _pixelsToTile(float $px, float $py): array {
        return [
            (int)\ceil($px / $this->_tileSize) - 1,
            (int)\ceil($py / $this->_tileSize) - 1
        ];
    }

    protected function _pixelsToMeter(float $px, float $py, int $zoom): array {
        $res = $this->_resolution($zoom);
        $mx = $px * $res - $this->_originShift;
        $my = $py * $res - $this->_originShift;
        return [$mx, $my];
    }

    // Returns bounds of the given tile in EPSG:900913 coordinates
    protected function _tileBounds(float $tx, float $ty, int $zoom): array {
        list($minx, $miny) = $this->_pixelsToMeter($tx*$this->_tileSize, $ty*$this->_tileSize, $zoom);
        list($maxx, $maxy) = $this->_pixelsToMeter(($tx+1)*$this->_tileSize, ($ty+1)*$this->_tileSize, $zoom);
        return [$minx, $miny, $maxx, $maxy];
    }

    protected function _tileLatLonBounds(float $tx, float $ty, int $zoom): array {
        if ($this->_googleTileFormat) {
            list ($tx, $ty) = $this->_tileToTmsTile($tx, $ty, $zoom);
        }
        $bounds = $this->_tileBounds($tx, $ty, $zoom);

        list($minLat, $minLon) = $this->_metersToLatLon($bounds[0], $bounds[1]);
        list($maxLat, $maxLon) = $this->_metersToLatLon($bounds[2], $bounds[3]);

        return [$minLat, $minLon, $maxLat, $maxLon];
    }

    // Converts TMS tile coordinates to Google Tile coordinates
    protected function _tileToGoogleTile(float $tx, float $ty, int $zoom): array {
        return [
            $tx,
            (\pow(2, $zoom) - 1) - $ty
        ];
    }

    // Converts Google Tile coordinates to TMS tile coordinates
    protected function _tileToTmsTile(float $tx, float $ty, int $zoom): array {
        return [
            $tx,
            \pow(2, $zoom) - $ty - 1
        ];
    }
}
