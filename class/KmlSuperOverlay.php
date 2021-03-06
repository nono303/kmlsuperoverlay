<?php
	class KmlSuperOverlay {

		/*
			PRIVATE
		*/
		private $kml;
		private $src;
		private $name;
		private $baseurl;
		private $isOverlay = false;
		private $regionPolygon;
		// debug
		private $isDebug = false;
		private $debugUrl = "";
		private $startTime;
		private $ruTime;

		/*
			STATIC
		*/
		private static $worldBbox = [
			"north" => 85,	/* maxLat */
			"south" => -85,	/* minLat */
			"east" => 180,	/* maxLon */
			"west" => -180,	/* minLon */
		];
		private static $tidyOptions = [
			"input-xml"=> true, 
			"output-xml" => true, 
			"indent" => true, 
			"indent-cdata" => true, 
			"wrap" => false,
			"clean" => true,
		];
		// !! <PolyStyle><fill>0</fill></PolyStyle> bug: don't follow longitude curve / <PolyStyle><color>00ffffff</color></PolyStyle> OK
		private static $kmlformat = [
			"header" => 
				"<?xml version='1.0' encoding='utf-8'?>
				<kml xmlns='http://www.opengis.net/kml/2.2' xmlns:atom='http://www.w3.org/2005/Atom' xmlns:gx='http://www.google.com/kml/ext/2.2'>
				<Document>
				<Style id='linered'><LineStyle><color>ff0000ff</color></LineStyle><PolyStyle><color>00ffffff</color></PolyStyle></Style>
				<Style id='linegreen'><LineStyle><color>ff00ff00</color></LineStyle><PolyStyle><color>00ffffff</color></PolyStyle></Style>
				<Style id='folderCheckOffOnly'><ListStyle><listItemType>checkOffOnly</listItemType><bgColor>bbfcf7de</bgColor>
					<ItemIcon><state>open</state><href>http://maps.google.com/mapfiles/kml/shapes/donut.png</href></ItemIcon>
					<ItemIcon><state>closed</state><href>http://maps.google.com/mapfiles/kml/shapes/forbidden.png</href></ItemIcon>
				</ListStyle></Style>",
			"footer" => 
				"</Document></kml>"
		];
		private static $lod = [
			"groundOverlay" => [
				"minLodPixels" => 128, 
				"maxLodPixels" => 512, 
				"minFadeExtent" => -1, 
				"maxFadeExtent" => -1
			],
			"networkLink" => [
				"minLodPixels" => 128, 
				"maxLodPixels" => -1, 
				"minFadeExtent" => -1, 
				"maxFadeExtent" => -1
			]
		];
		private static $altitude = [
			"minAltitude" => 0, 
			"maxAltitude" => 0
		];
		private static $minZoom = 3;
		private static $displayRegion = true;
		private static $debugHtml = false;
		// ".kml" || ".kmz"
		private static $outFormat = ".kml";
		// false || https://github.com/brick/geo#configuration
		private static $brickEngine = "GEOSEngine";

		/*
			CONST
		*/ 
		const EPSG = 4326;

		/*
			CONSTRUCTOR
		*/
		function __construct($debug, $baseurl,$src = null) {
			if(!in_array(self::$outFormat,[".kml",".kmz"]))
				throw new Exception("unknow outFormat '".self::$outFormat."'");
			if(!extension_loaded("zip") && self::$outFormat == ".kmz")
				throw new Exception("outFormat '".self::$outFormat."' not supported: zip extension not enabled");
			$this->kml = "";
			$this->src = $src;
			$this->debug = $debug;
			if($this->debug){
				$this->startTime = microtime(true);
				$this->ruTime = getrusage();
				$this->debugUrl = "/debug";
				self::$outFormat = ".kml";
			}
			$this->baseurl = $baseurl;
			if($this->src["overlay"])
				$this->isOverlay = true;
			if(self::$brickEngine && is_array($this->src["region"])){
				Brick\Geo\Engine\GeometryEngineRegistry::set(eval ('return new Brick\\Geo\\Engine\\'.self::$brickEngine.'();'));
				$this->regionPolygon = Brick\Geo\Polygon::fromText("POLYGON ((".self::bboxToWkt($this->src["region"])."))");
			}
		}
		/*
			PUBLIC
		*/
		public function display(){
			$description = "";
			if($this->debug){
				$ru = getrusage();
				$desc = 
					date("Y-m-d H:i:s").PHP_EOL.
					"ttim: ".round((microtime(true)-$this->startTime)*1000).PHP_EOL.
					"utim: ".Common::rutime($ru, $this->ruTime, "utime").PHP_EOL.
					"stim: ".Common::rutime($ru, $this->ruTime, "stime").PHP_EOL.
					"pmem: ".Common::afficheOctets(memory_get_peak_usage());
				$description = self::createElement("description",nl2br($desc));
			}
			$this->kml = 
				self::$kmlformat["header"].
				$description.
				$this->kml.
				self::$kmlformat["footer"];
			if(TIDY_KML && extension_loaded("tidy")){
				$this->kml = tidy_repair_string($this->kml, self::$tidyOptions);
			} elseif (INDENT_KML){
				$doc = new DOMDocument();
				$doc->preserveWhiteSpace = false;
				$doc->formatOutput = true;
				$doc->loadXML($this->kml);
				$this->kml = $doc->saveXML();
			}
			if(!$this->debug){
				ob_clean();
				header("Content-Disposition: inline; filename=".$this->name.self::$outFormat);
				if(self::$outFormat == ".kml"){
					header('Content-Type: application/vnd.google-earth.kml+xml kml');
					echo $this->kml;
				} elseif(self::$outFormat == ".kmz"){
					header('Content-Type: application/vnd.google-earth.kmz kmz');
					unlink($kmzfile = tempnam(sys_get_temp_dir(), 'FOO')); // https://stackoverflow.com/a/64698936
					($zip = new ZipArchive())->open($kmzfile, ZIPARCHIVE::CREATE);
					$zip->addFromString($this->name.".kml",$this->kml);
					$zip->close();
					readfile($kmzfile);
					unlink($kmzfile);
				}
			} else {
				if(self::$debugHtml){
					echo Common::htmlAsHtml($this->kml);
				} else {
					echo $this->kml;
				}
			}
			
			exit();
		}
		public function createFromZXY($z,$x,$y) {
			// Document Name
			$this->name = $z."-".$x."-".$y;
			$this->kml .= "<name>".$this->name."</name>";

			// Region
			$this->kml .= self::createElement("Region", [self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge(Gis::tileCoordZXY($z,$x,$y,self::EPSG), self::$altitude)))]);

			$nz = $z + 1;
			for($nx = ($x * 2); $nx <= ($x * 2) + 1; $nx++){
				for($ny = ($y * 2); $ny <= ($y * 2) + 1; $ny++){
					$display = true;
					$tilecoords = Gis::tileCoordZXY($nz,$nx,$ny,self::EPSG);
					if(!is_null($this->regionPolygon))
						$display = (Brick\Geo\Polygon::fromText("POLYGON ((".self::bboxToWkt($tilecoords)."))"))->intersects($this->regionPolygon);
					if($display){
						$groundOverlay .= $this->getGroundOverlay($nz,$nx,$ny,$tilecoords);
						if($nz < $this->src["maxZoom"])
							$networkLink .= $this->getNetworkLink($nz,$nx,$ny,$tilecoords);
					}
				}
			}
			$this->kml .= $groundOverlay;
			if($nz < $this->src["maxZoom"])
				$this->kml .= $networkLink;
		}
		public function createFromBbox() {
			if(is_array($this->src["region"])){
				$bbox = $this->src["region"];
				if(self::$displayRegion){
					$placemarkItems = [
						self::createElement("styleUrl","#linegreen"),
						self::createElement("Polygon",
							self::createElement("outerBoundaryIs",
								self::createElement("LinearRing",
									self::createElement("coordinates",self::bboxToLinearRing($bbox)))))
					];
					$pmlsbbox =self::createElement("Placemark",$placemarkItems,"region");
				}
			} else {
				$bbox = self::$worldBbox;
			}
			$zoom = $this->src["minZoom"]-1;
			if($this->src["minZoom"] < self::$minZoom)
				$zoom = self::$minZoom-1;

			// Document Name
			$this->name = $this->src["name"];
			$this->kml .= "<name>".$this->name."</name>";

			// Region
			$this->kml .= self::createElement("Region", [self::createElement("LatLonAltBox",Common::assocArrayToXml($bbox))]);
			$this->kml .= $pmlsbbox;

			// NetworkLink
			foreach(Gis::ZXYFromBbox($bbox,$zoom)["tiles"] as $nl)
				$this->kml .= $this->getNetworkLink($nl["z"]-1,$nl["x"],$nl["y"],Gis::tileCoordZXY($nl["z"]-1,$nl["x"],$nl["y"],self::EPSG));
		}
		public function createRoot($rootfolder,$name){
			$this->name = $name;
			$this->kml .= "<name>".$this->name."</name>";

			$nf = "<Folder><styleUrl>#folderCheckOffOnly</styleUrl>";
			$curfolder = null;

			// scandirRecursiveFilePattern ensure compatibility for unix & windows filesystems with only "/" as DIRECTORY_SEPARATOR
			foreach(Common::scandirRecursiveFilePattern($rootfolder,'/^.+\.xml$/i') as $name){
				$filear = explode("/",$uri = str_replace([$rootfolder,".xml"],["","/"],$name));
				$rootname = null;
				$xmlcontent = Common::getXmlFileAsAssocArray($name,$rootname);
				// filter 
				if($rootname == "customMapSource"){
					// to be complient with https://github.com/grst/geos, just prefixed filesystem folders with '-' to be in first on my layer list & remove it here for display
					$mapsource["folder"] = preg_replace("/^-/","",$filear[0]);
					if($xmlcontent["folder"])
						// see https://geos.readthedocs.io/en/latest/users.html#more-maps
						$mapsource["folder"] = $xmlcontent["folder"];
					// Construct KML
					if($mapsource["folder"] != $curfolder){
						$this->kml .= $nf."<name>".($curfolder = $mapsource["folder"])."</name>";
						$nf = "</Folder><Folder><styleUrl>#folderCheckOffOnly</styleUrl>";
					}
					$linkItems = [
						self::createElement("href",$this->baseurl.$uri.$this->debugUrl),
						self::createElement("viewRefreshMode","onRegion")
					];
					$networkItems = [
						self::createElement("visibility",0),
						self::createElement("Link",$linkItems),
					];
					$this->kml .= self::createElement("NetworkLink", $networkItems,$xmlcontent["name"]);
				}
			}
			$this->kml .= "</Folder>";
		}
		/*
			PRIVATE
		*/
		private function getGroundOverlay($z,$x,$y,$tilecoords){
			$lod = self::$lod["groundOverlay"];
			// if max zoom or opaque tiles : we keep it displayed
			if($z == $this->src["maxZoom"] || !$this->isOverlay)
				$lod["maxLodPixels"] = -1;
			if($z == $this->src["minZoom"] || $z == self::$minZoom)
				$lod["minLodPixels"] = -1;
			$regionItems = [
				self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge($tilecoords, self::$altitude))),
				self::createElement("Lod",Common::assocArrayToXml($lod))
				];
			$groundItems = [
				self::createElement("Region", $regionItems),
				self::createElement("drawOrder",$z),
				self::createElement("Icon",self::createElement("href",$this->getUrl($z,$x,$y))),
				self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge($tilecoords, self::$altitude))),
			];
			return self::createElement("GroundOverlay", $groundItems,"go-".$z."-".$x."-".$y);
		}
		private function getNetworkLink($z,$x,$y,$tilecoords){
			$lod = self::$lod["networkLink"];
			if($z == $this->src["minZoom"] || $z == self::$minZoom)
				$lod["minLodPixels"] = -1;
			$regionItems = [
				self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge($tilecoords, self::$altitude))),
				self::createElement("Lod",Common::assocArrayToXml($lod)),
			];
			$linkItems = [
				self::createElement("href",$this->baseurl.$z."-".$x."-".$y.self::$outFormat.$this->debugUrl),
				self::createElement("viewRefreshMode","onRegion")
			];
			$networkItems = [
				self::createElement("open",1),
				self::createElement("Link",$linkItems),
				self::createElement("Region", $regionItems)
			];
			return self::createElement("NetworkLink", $networkItems,"nl-".$z."-".$x."-".$y);
		}
		private function getUrl($z,$x,$y){
			// serverParts {$serverpart}
			if($this->src["serverParts"])
				$this->src["url"] = str_replace('{$serverpart}',($sp = explode(" ",$this->src["serverParts"]))[mt_rand(0, count($sp) - 1)],$this->src["url"]);
			// TMS {$ry}
			if(str_contains($this->src["url"],'{$ry}'))
				return str_replace(['{$z}','{$x}','{$ry}'],[$z,$x,Gis::revY($z,$y)],$this->src["url"]);
			// QUAD {$q}
			if(str_contains($this->src["url"],'{$q}'))
				return str_replace('{$q}',Gis::tileToQuadKey($x,$y,$z),$this->src["url"]);
			// WMS {$bbox}
			if(str_contains($this->src["url"],'{$q}'))
				return str_replace('{$q}',Gis::tileToQuadKey($x,$y,$z),$this->src["url"]);
			// ZXY {$y}
			if(str_contains($this->src["url"],'{$y}'))
				return str_replace(['{$z}','{$x}','{$y}'],[$z,$x,$y],$this->src["url"]);
			// WMS
			if(str_contains($this->src["url"],'{$bbox}')){
				preg_match("/rs=epsg:([0-9]*)/i",$this->src["url"],$matches);
				if($curepsg = $matches[1]){
					if ($curepsg == "4326"){
						return str_replace('{$bbox}',Gis::tileEdges($x, $y, $z,4326),$this->src["url"]);
					} elseif ($curepsg == "3857" || $curepsg == "102100" || $curepsg == "900913") {
						return str_replace('{$bbox}',Gis::tileEdges($x, $y, $z,3857),$this->src["url"]);
					} else {
						throw new Exception("unsupported Projection EPSG:".$curepsg." in '".$this->src["url"]."'");
						/*
							Not activated cause require cs2cs and lot of libs, deps...
						$bboxt = explode(",",$curbbox = Gis::tileEdges($x, $y, $z, self::EPSG));
						$bbox = Gis::transformEpsg(self::EPSG, $curepsg,$bboxin = [[$bboxt[0],$bboxt[1]],[$bboxt[2],$bboxt[3]]]);
						*/
					}
				} else {
					throw new Exception("unknow Projection - EPSG FOR CRS or SRS - in '".$this->src["url"]."' Is that a bug?");
				}
			}
		}
		/*
			PRIVATE STATIC
		*/
		private static function bboxToWkt($bbox){
			return $bbox["west"]." ".$bbox["north"].", ".$bbox["east"]." ".$bbox["north"].", ".$bbox["east"]." ".$bbox["south"].", ".$bbox["west"]." ".$bbox["south"].", ".$bbox["west"]." ".$bbox["north"];
		}
		private static function bboxToLinearRing($bbox){
			return $bbox["west"].",".$bbox["north"].",0 ".$bbox["east"].",".$bbox["north"].",0 ".$bbox["east"].",".$bbox["south"].",0 ".$bbox["west"].",".$bbox["south"].",0 ".$bbox["west"].",".$bbox["north"].",10";
		}
		private static function createElement($itemName, $items, $namevalue = null){
			if(!is_null($namevalue))
				$ret .= "<name><![CDATA[".$namevalue."]]></name>";
			if(is_null($items))
				throw new Exception("items is null");
			if(is_array($items)){
				foreach($items as $item)
					$ret .= $item;
			} elseif(is_string($items) || is_numeric($items)){
				$ret .= $items;
			} else {
				throw new Exception("unknonw items type '".gettype($items)."'");
			}
			if(in_array($itemName,["href","description"]))
				return "<".$itemName."><![CDATA[".$ret."]]></".$itemName.">";
			return "<".$itemName.">".$ret."</".$itemName.">";
		}
		/*
			STATIC CONTROLLER
		*/
		public static function controller($params,$urlbase){
			$debug = false;
			if(sizeof($params) > 0 && strcasecmp(end($params),"debug") == 0){
				$debug = true;
				array_pop($params);
			}
			// KML for all mapsource overlays
			if(sizeof($params) == 0){
				$so = new KmlSuperOverlay($debug, $_SERVER['REQUEST_SCHEME'] .'://'. $_SERVER['HTTP_HOST'].$urlbase);
				$so->createRoot(MAP_SOURCE_ROOT,end(array_filter(explode("/",$urlbase))));
				$so->display();
			} else{
				if(preg_match("/\.(km.)$/",end($params),$matches))
					$zxy = explode("-",str_replace($matches[1],"",array_pop($params)));
				$path = implode("/",$params);
				if(is_file($file = MAP_SOURCE_ROOT.$path.".xml")){
					$so = new KmlSuperOverlay($debug, $_SERVER['REQUEST_SCHEME'] .'://'. $_SERVER['HTTP_HOST'].$urlbase.$path."/",Common::getXmlFileAsAssocArray($file));
					if($zxy){
						// KML region for an overlay
						$so->createFromZXY($zxy[0],$zxy[1],$zxy[2]);
					} else {
						// KML root for an overlay
						$so->createFromBbox();
					}
					$so->display();
				} else {
					header("HTTP/1.0 404 Not Found");
					echo "Layer '".$path."' doesn't exist";
					exit();
				}
			}
		}
	}
?>