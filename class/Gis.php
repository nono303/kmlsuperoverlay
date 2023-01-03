<?php
	// https://www.orekit.org/site-orekit-9.1/apidocs/org/orekit/utils/Constants.html#EIGEN5C_EARTH_EQUATORIAL_RADIUS
	define("EARTH_EQUATORIAL_RADIUS",6378136.46);

	class Gis {
		public static function bboxToWkt($bbox){
			return $bbox["west"]." ".$bbox["north"].", ".$bbox["east"]." ".$bbox["north"].", ".$bbox["east"]." ".$bbox["south"].", ".$bbox["west"]." ".$bbox["south"].", ".$bbox["west"]." ".$bbox["north"];
		}
		
		public static function bboxToLinearRing($bbox){
			return $bbox["west"].",".$bbox["north"].",0 ".$bbox["east"].",".$bbox["north"].",0 ".$bbox["east"].",".$bbox["south"].",0 ".$bbox["west"].",".$bbox["south"].",0 ".$bbox["west"].",".$bbox["north"].",10";
		}
		
		public static function revY($z,$y){
			return (pow(2, $z)-1-$y);
		}

		public static function ZXYFromBbox($bbox,$z){
			$modulo = 2 ** $z;
			$bbox["west"] > $bbox["east"] ? $addeast = $modulo : $addeast = 0;
			for ($x = Gis::lonToTileX($bbox["west"],$z) ; $x <= Gis::lonToTileX($bbox["east"],$z)+$addeast; $x++)
				for ($y = Gis::latToTileY($bbox["north"],$z) ; $y <= Gis::latToTileY($bbox["south"],$z); $y++)
					$res["tiles"][] = ["z" =>($z+1),"x" => (($x+$modulo) % $modulo),"y" => $y];
			return $res;
		}

		public static function tileCoordZXY($z,$x,$y,$epsg=4326){
			$array = explode(",",Gis::tileEdges($x,$y,$z,$epsg));
			return ["west" => $array[0],"south" => $array[1],"east" => $array[2],"north" => $array[3]];
		}

		// https://gis.stackexchange.com/a/207747
		public static function lonToTileX($lon, $zoom){
			return floor((($lon + 180) / 360) * pow(2, $zoom));
		}

		// https://gis.stackexchange.com/a/207747
		public static function latToTileY($lat, $zoom){
			return floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * pow(2, $zoom));
		}

		// Mercator - http://randochartreuse.free.fr/mobac2.x/documentation/#bsh
		public static function tileEdges($x,$y,$z,$epsg=4326){
			if($epsg == 4326)
			return (
					Gis::lonEdges1($x,$z)
				.",".
					Gis::latEdges2($y,$z)
				.",".
					Gis::lonEdges2($x,$z)
				.",".
					Gis::latEdges1($y,$z)
				);
			if($epsg == 3857) 
			return (
					Gis::lon2mercator(Gis::lonEdges1($x,$z))
				.",".
					Gis::lat2mercator(Gis::latEdges2($y,$z))
				.",".
					Gis::lon2mercator(Gis::lonEdges2($x,$z))
				.",".
					Gis::lat2mercator(Gis::latEdges1($y,$z))
				);
			throw new Exception("Unknown ESP '".$epsg."'");
		}

		private static function latEdges1($y,$z){
			$n = Gis::numTiles($z);
			$unit = 1 / $n;
			$relyA = $y * $unit;
			$lat1 = Gis::mercatorToLat(M_PI * (1 - 2 * $relyA));
			return $lat1;
		}

		private static function latEdges2($y,$z){
			$n = Gis::numTiles($z);
			$unit = 1 / $n;
			$relyA = $y * $unit;
			$relyB = $relyA + $unit;
			$lat2 = Gis::mercatorToLat(M_PI * (1 - 2 * $relyB));
			return $lat2;
		}

		private static function lonEdges1($x,$z){
			$n = Gis::numTiles($z);
			$unit = 360 / $n;
			$lon1 = -180 + $x * $unit;
			return $lon1;
		}

		private static function lonEdges2($x,$z){
			$n = Gis::numTiles($z);
			$unit = 360 / $n;
			$lon1 = -180 + $x * $unit;
			$lon2 = $lon1 + $unit;
			return $lon2;
		}

		private static function numTiles($z){
			return abs(pow(2,$z));
		}

		private static function mercatorToLat($mercatory){
			return rad2deg(atan(sinh($mercatory)));
		}

		// http://randochartreuse.free.fr/mobac2.x/documentation/#bsh
		public static function tileToQuadKey($x, $y, $zoom){ 
			$res="";
			$prx = $osy = $osx = pow(2,$zoom-1);
			for ($i=0;$i<($zoom);$i++) {
				$prx = $prx/2;
				if ($x < $osx){
					$osx=$osx-$prx;
					if ($y<$osy){
						$osy=$osy-$prx;
						$res=$res."0";
					} else {
						$osy=$osy+$prx;
						$res=$res."2";
					}
				} else {
					$osx=$osx+$prx;
					if ($y<$osy) {
						$osy=$osy-$prx;
						$res=$res."1";
					} else {
						$osy=$osy+$prx;
						$res=$res."3";
					}
				}
			}
			return $res;
		}

		private static function lon2mercator($l){
			return ($l * M_PI * EARTH_EQUATORIAL_RADIUS / 180);
		}

		private static function lat2mercator($l){
			$r = deg2rad($l);
			$lat = log((1+sin($r)) / (1-sin($r)));
			return ($lat * EARTH_EQUATORIAL_RADIUS / M_PI);
		}
	}
?>