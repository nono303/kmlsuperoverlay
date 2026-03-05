<?php
	class Gis {

		// for distances: getRadiusAtLat() - https://www.orekit.org/site-orekit-9.1/apidocs/org/orekit/utils/Constants.html#EIGEN5C_EARTH_EQUATORIAL_RADIUS
		const WGS_ELLIPSOID = [ "a" => 6378137.0, "b" => 6356752.314 ]; // meter
		// for coordinates: geod -le | grep -i wgs84
		const EARTH_EQUATORIAL_RADIUS = self::WGS_ELLIPSOID["a"];

		// for tileEdgesXxx functions
		const DEFAULT_EPSG = 4326;

		// https://developers.google.com/kml/schema/kml22gx.xsd
		const KMLHEAD = '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:gx="http://www.google.com/kml/ext/2.2" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:xal="urn:oasis:names:tc:ciq:xsdschema:xAL:2.0">';

		// https://wiki.openstreetmap.org/wiki/Bounding_box
		private static function bboxArrayFromString($in){
			is_string($in) ?
				list($bbox["west"], $bbox["south"], $bbox["east"], $bbox["north"]) = explode(',', $in) :
				$bbox = $in;
			return $bbox;
		}

		public static function bboxToWkt($bbox){
			$bbox = self::bboxArrayFromString($bbox);
			return $bbox["south"]." ".$bbox["west"].",".$bbox["south"]." ".$bbox["east"].",".$bbox["north"]." ".$bbox["east"].",".$bbox["north"]." ".$bbox["west"].",".$bbox["south"]." ".$bbox["west"];
		}

		public static function bboxToLinearRing($bbox){
			$bbox = self::bboxArrayFromString($bbox);
			return $bbox["west"].",".$bbox["south"].",0 ".$bbox["east"].",".$bbox["south"].",0 ".$bbox["east"].",".$bbox["north"].",0 ".$bbox["west"].",".$bbox["north"].",0 ".$bbox["west"].",".$bbox["south"].",0";
		}

		public static function revY($z,$y){
			return (pow(2, $z)-1-$y);
		}

		public static function ZXYFromBbox($bbox,$z){
			$bbox = self::bboxArrayFromString($bbox);
			$modulo = 2 ** $z;
			$bbox["west"] > $bbox["east"] ? $addeast = $modulo : $addeast = 0;
			for ($x = self::lonToTileX($bbox["west"],$z) ; $x <= self::lonToTileX($bbox["east"],$z)+$addeast; $x++)
				for ($y = self::latToTileY($bbox["north"],$z) ; $y <= self::latToTileY($bbox["south"],$z); $y++)
					$res["tiles"][] = ["z" =>($z+1),"x" => (($x+$modulo) % $modulo),"y" => $y];
			return $res;
		}

		public static function tileCoordZXY($z,$x,$y,$epsg=4326){
			$array = explode(",",self::tileEdges($x,$y,$z,$epsg));
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

		public static function tileEdgesArray($x,$y,$z,$epsg = self::DEFAULT_EPSG){
			$array = explode(",",self::tileEdgesBbox($x,$y,$z,$epsg));
			return ["west" => $array[0],"south" => $array[1],"east" => $array[2],"north" => $array[3]];
		}

		public static function tileEdgesBbox($x,$y,$z,$epsg = self::DEFAULT_EPSG){
			if($epsg == self::DEFAULT_EPSG){
				return (
					self::lonEdges1($x,$z)
				.",".
					self::latEdges2($y,$z)
				.",".
					self::lonEdges2($x,$z)
				.",".
					self::latEdges1($y,$z)
					);
			// https://epsg.io/3857
			} elseif(in_array($curepsg,[3857,900913,3587,54004,41001,102113,102100,3785])) {
				return (
					self::lon2mercator(self::lonEdges1($x,$z))
				.",".
					self::lat2mercator(self::latEdges2($y,$z))
				.",".
					self::lon2mercator(self::lonEdges2($x,$z))
				.",".
					self::lat2mercator(self::latEdges1($y,$z))
				);
			} else {
				return Coordinates::toBboxString(
					Coordinates::transformEpsg(
						self::DEFAULT_EPSG,
						$epsg,
						[
							[self::lonEdges1($x,$z),self::latEdges2($y,$z)],
							[self::lonEdges2($x,$z),self::latEdges1($y,$z)]
						]
					)
				);
			}
		}

		private static function latEdges1($y,$z){
			$n = self::numTiles($z);
			$unit = 1 / $n;
			$relyA = $y * $unit;
			$lat1 = self::mercatorToLat(M_PI * (1 - 2 * $relyA));
			return $lat1;
		}

		private static function latEdges2($y,$z){
			$n = self::numTiles($z);
			$unit = 1 / $n;
			$relyA = $y * $unit;
			$relyB = $relyA + $unit;
			$lat2 = self::mercatorToLat(M_PI * (1 - 2 * $relyB));
			return $lat2;
		}

		private static function lonEdges1($x,$z){
			$n = self::numTiles($z);
			$unit = 360 / $n;
			$lon1 = -180 + $x * $unit;
			return $lon1;
		}

		private static function lonEdges2($x,$z){
			$n = self::numTiles($z);
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
			return ($l * M_PI * self::EARTH_EQUATORIAL_RADIUS / 180);
		}

		private static function lat2mercator($l){
			$r = deg2rad($l);
			$lat = log((1+sin($r)) / (1-sin($r)));
			return ($lat * self::EARTH_EQUATORIAL_RADIUS / 2);
		}
	}

	class Coordinates{
		private static $projCache = [];

		// return: "lon0,lat0,lon1,lat1..."
		public static function toBboxString($tabxy){
			return implode(",",array_map(function($item){ return $item[0].",".$item[1]; },$tabxy));
		}

		public static function transformEpsg($epsgin,$epsgout,$tabxy,&$debug = null, $backend="PHPPROJ"){
			$start= microtime(true);
			$debug = [];
			if($backend == "PHPPROJ"){
				if(!self::$projCache[$epsgin."-".$epsgout])
					self::$projCache[$epsgin."-".$epsgout] = proj_create_crs_to_crs("EPSG:".$epsgin,"EPSG:".$epsgout);
				$return = array_map(
					function($item){
						return [$item["x"],$item["y"]];
					},$coords = proj_transform_array(self::$projCache[$epsgin."-".$epsgout],$tabxy)
				);
				if(($retcode = proj_get_errno(self::$projCache[$epsgin."-".$epsgout])) != 0)
					throw new Exception("proj_transform_array() errno '".$retcode."': ".proj_get_errno_string($retcode));
				return $return;
			} elseif($backend == "CS2CS"){
				// https://gis.stackexchange.com/a/162589
				$cmd = "cs2cs.exe +init=epsg:".$epsgin." +to +init=epsg:".$epsgout." -d 10 2>&1";
				$dst = self::subTransformGdalCs2cs($tabxy,$cmd,$debug, $backend);
			} elseif($backend == "GDALTRANSFORM"){
				// https://proj.org/apps/cs2cs.html#description
				$cmd = "gdaltransform.exe -s_srs epsg:".$epsgin." -t_srs epsg:".$epsgout." -output_xy 2>&1";
				$dst = self::subTransformGdalCs2cs($tabxy,$cmd,$debug, $backend);
			} else {
				throw new Exception("backend '".$backend."' not in [PHPPROJ,GDALTRANSFORM,CS2CS]");
			}

			$debug = array_merge($debug,[
				"backend" =>	$backend,
				"epsgin" =>		$epsgin,
				"epsgout" =>	$epsgout,
				"dst" =>		$dst,
				"time" =>		number_format(microtime(true)-$start,8)
			]);
			return $dst;
		}
	}
?>