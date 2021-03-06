<?php
namespace Gis\Domain\Spatial\Service;

use Gis\Core\Config\ConfigReader;
use Gis\Core\Domain\IDomainService;
use Gis\Domain\Spatial\Model\Layer\LayerFactory;
use Gis\Domain\Spatial\Model\Layer\Layer;
use Gis\Domain\Spatial\Model\Set\SetFactory;
use Gis\Domain\Spatial\Model\Attribute\AttributeFactory;
use Gis\Domain\Spatial\Model\Polygon\PolygonFactory;
use Gis\Domain\Spatial\Model\Polygon\Polygon;
use Gis\Domain\Spatial\Model\Point\PointFactory;
use Gis\Domain\Spatial\Model\Point\Point;
use Gis\Domain\Spatial\Model\Linestring\LinestringFactory;
use Gis\Domain\Spatial\Model\Linestring\Linestring;
use Gis\Domain\Spatial\TransferObject\SpatialObject;
use Gis\Domain\Spatial\TransferObject\Coord;

/**
 * Map domain service used to draw the map tiles. Map tiles are made
 * up of 1 or more layers, each comprising of multiple spatial objects
 * (polygon, linestring, point)
 *
 * @author David Berliner <dsberliner@gmail.com>
 */
class MapTile implements IDomainService
{
	private $image;		//GD image handle
	private $width;		//Width of the image/tile
	private $height;	//Height of the image/tile
	private $zoom;		//current zoom level
	private $x;			//tiles x value
	private $y;			//tiles y value
	private $offsetX;	//based on the tile coord and the image width
	private $offsetY;	//based on the tile coord and the image height
	private $scale;		//2^zoom
	private $boundsMax;	//Max x,y coords of the tile
	private $boundsMin; //Min x,y coords of the tile
	private $configReader;

	/*
		There seems to be a bug in the imagefilledpolygon method of the
		GD library where it doesnt fill a polygon completely. Often there
		is a 1px row at the very bottom of the image that is not correct.
		This results in lines appearing on the map, if you notice this then
		you should turn this flag on. 

		With this flag set the image is created 1px longer and then the 1px
		cropped off at the end. Such a hack but it seems to work.
	 */
	private $doGDHack = 1;
	/**
	 * Create us a new map instance of the specified width x height
	 * @param int $width   width of the tile in px
	 * @param int $height  height of the tile px
	 */
	public function __construct($width, $height)
	{
		$this->width = $width;
		$this->height = $height;

		//refer to notes above
		if($this->doGDHack) {
			$this->height += 1;
		}
	}

	/**
	 * Used to read config from Gis\Config\*
	 * 
	 * @return ConfigReader
	 */
	private function getConfigReader()
	{
		if(!isset($this->configReader)) {
			$this->configReader = new ConfigReader();
		}
		
		return $this->configReader;
	}

	/**
	 * Create a tile of size width x height and draw all of the spatial points
	 * within the given layers onto it. Scaling is based on Google Maps API
	 *
	 * pixelCoordinate = worldCoordinate * 2^zoomLevel
	 *
	 * @link https://developers.google.com/maps/documentation/javascript/maptypes#PixelCoordinates
	 * @link https://developers.google.com/maps/documentation/javascript/examples/maptype-base
	 * 
	 * @param  Layer[] $layers A collection of layer objects
	 * @param  int $x The tiles x coordinate
	 * @param  int $y The tiles y coordinate
	 * @param  int $z The zoom level
	 * @return Content-type: image/png
	 */
	public function get($layers, $x, $y, $z)
	{
		// make sure that we actually have something to draw
		if(empty($layers)) {
			return false;
		}

		$this->x = $x;
		$this->y = $y;
		$this->zoom = $z;
		$this->scale = pow(2, $z);
		$this->offsetX = $this->x * $this->width;
		$this->offsetY = $this->y * $this->height;

		//coordinate bounds of the tile
		$this->setBounds();
		//create the GD image handle
		$this->createImage();

		//draw each one of the given layers
		foreach($layers as $layer) {
			$colors = $this->hexToRGB($layer->getColor());

		//@TODO here we make an assumption that this layer only contains data of 1 type
			switch($layer->getType()) {
				case SpatialObject::TYPE_POLYGON:
					$this->drawPolygons($layer->getId(), $colors);
					break;
				case SpatialObject::TYPE_LINESTRING:
					$this->drawLinestrings($layer->getId(), $colors);
					break;
				case SpatialObject::TYPE_POINT:
					$this->drawPoints($layer->getId(), $colors);
					break;
			}
		}

        //output the image
        $this->render();
	}

	/**
	 * define the max/min x/y coordinates for this tile. This is
	 * used to only fetch data that is relevant to this tile.
	 * 
	 * @return void
	 */
	private function setBounds()
	{
		$this->boundsMax = new Coord();
		$this->boundsMin = new Coord();
		$this->boundsMin->setType(Coord::TYPE_LAT_LNG);
		$this->boundsMax->setType(Coord::TYPE_LAT_LNG);

		$this->boundsMax->setX(($this->width + $this->offsetX)/$this->scale);
		$this->boundsMax->setY((-$this->height - $this->offsetY)/$this->scale);
		$this->boundsMin->setX($this->offsetX/$this->scale);
		$this->boundsMin->setY(-$this->offsetY/$this->scale);
	}

	/**
	 * Draw all of the polygons in this layer onto the map.
	 * 
	 * @param  int $layerId
	 * @return void  All changes done to the $this->image handle
	 */
	private function drawPolygons($layerId, $colors)
	{	
		$color = imagecolorallocate($this->image,$colors[0],$colors[1],$colors[2]);

		$polygonFactory = new PolygonFactory();
		$polygons = $polygonFactory->getByLayerInBounds($layerId, $this->boundsMin, $this->boundsMax);
	
		foreach($polygons as $polygon) {
			
			$spatialObject = $polygon->getSpatialObject();

			foreach ($spatialObject as $partId => $part) {
				$cArray = array();
				$i = 0;
				foreach($part as $coord) {
					$cArray[$i] = $coord->getX() * $this->scale - $this->offsetX;
					$cArray[$i+1] = -$coord->getY() * $this->scale - $this->offsetY;
					$i += 2;
				}

				if(count($cArray) >= 6){
					imagefilledpolygon($this->image, $cArray, ($i/2), $color);
					imagepolygon($this->image, $cArray, ($i/2), $this->linecolor);
				}
			}
		}
		
	}

	/**
	 * Draw all of the linestrings in this layer to the map tile.
	 * 
	 * @param  int $layerId
	 * @return void All changes done to the $this->image handle
	 */
	private function drawLinestrings($layerId, $colors)
	{
		$color = imagecolorallocate($this->image,$colors[0],$colors[1],$colors[2]);

		$linestringFactory = new LinestringFactory();
		$linestrings = $linestringFactory->getByLayerInBounds($layerId, $this->boundsMin, $this->boundsMax);
		
		foreach($linestrings as $linestring) {
			$spatialObject = $linestring->getSpatialObject();

			foreach ($spatialObject as $partId => $part) {
				$prevX = 0;
				$prevY = 0;

				foreach($part as $coord) {
					$nextX = $coord->getX() * $this->scale - $this->offsetX;
					$nextY = -$coord->getY() * $this->scale - $this->offsetY;

					if($prevX == $nextX && $prevY == $nextY) {}
					elseif(!empty($prevX)) {
						imagesetthickness($this->image, $thick = 2);
		    			imageline($this->image, $prevX, $prevY, $nextX, $nextY, $color);
		    		}
		    		
		    		$prevX = $nextX;
					$prevY = $nextY;
				}
			}
		}
	}

	/**
	 * Draw all of the points in this layer onto the map tile.
	 * @param  int $layerId
	 * @return void All changes done to the $this->image handle
	 */
	private function drawPoints($layerId, $colors)
	{
		$color = imagecolorallocate($this->image,$colors[0],$colors[1],$colors[2]);

		$setFactory = new SetFactory();
		$pointFactory = new PointFactory();
		$points = $pointFactory->getByLayerInBounds($layerId, $this->boundsMin, $this->boundsMax);

		foreach($points as $point) {
			$set = $setFactory->getById($point->getSetId());
			$spatialObject = $point->getSpatialObject();
			$coord = current($spatialObject->current());

			$x = $coord->getX() * $this->scale - $this->offsetX;
			$y = -$coord->getY() * $this->scale - $this->offsetY;
			
			imagefilledellipse($this->image, $x, $y, 4, 4, $color);
			imagestring($this->image, $font=1,$x+1,$y,$set->getName(), $color);
		}
	}

	/**
	 * Create a new palette based image. GD Library.
	 * @return void
	 */
	private function createImage()
	{
		$this->image = imagecreate($this->width, $this->height);

		$bgcolor = $this->getConfigReader()->get('color.background');
		$bordercolor = $this->getConfigReader()->get('color.border');

		if(!is_array($bgcolor)) {
			$bgcolor = array(76, 95, 115);
		}

		if(!is_array($bordercolor)) {
			$bordercolor = array(150,150,150);
		}

        $bg = imagecolorallocate($this->image, $bgcolor[0], $bgcolor[1], $bgcolor[2]);
        $this->tilecolor = imagecolorallocate($this->image,200,200,172);
		$this->linecolor = imagecolorallocate($this->image,$bordercolor[0],$bordercolor[1],$bordercolor[2]);
	}

	/**
	 * Set the headers and output the PNG image.
	 * @return Content-type: image/png
	 */
	private function render()
	{
		header ("Content-type: image/png");

		//see notes above. Going to crop off the last px from the bottom
		//because of a bug in imagefilledpolygon
		if($this->doGDHack) {
			$img = imagecreate($this->width, $this->height-1);
			imagecopyresampled($img, $this->image, 0,0,0,0, $this->width, $this->height-1, $this->width, $this->height-1);
			imagepng($img);
		} else {
			imagepng($this->image);
		}
	}

	/**
	 * Convert a hex color e.g #FF2321 to RGB
	 * 
	 * @param  string $hex
	 * @return array(r,g,b)
	 */
	private function hexToRGB($hex)
	{
		list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
		return array($r, $g, $b);
	}
}