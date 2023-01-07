<?php
	error_reporting(E_ERROR);

	include_once(__DIR__ ."/class/Gis.php");
	include_once(__DIR__ ."/class/Common.php");
	include_once(__DIR__ ."/class/KmlSuperOverlay.php");
	if(extension_loaded("geos")){
		if(!class_exists("Brick\Geo\Engine\GEOSEngine")){
			if(is_file(__DIR__ ."/vendor/autoload.php")) {
				include_once(__DIR__ ."/vendor/autoload.php");
			} else {
				throw new Exception("'geos' extension loaded but 'Brick\Geo' missing. Please run composer install");
			}
		}
	}
	// !! with ending '/'
	define("MAP_SOURCE_ROOT","./mapsources/");
	// !! with ending '/'
	define("URL_BASE","/kmlsuperoverlay/");
	// enable (slow & higher memory usage) or disable kml clean & format with tidy extension
	define("TIDY_KML",false);
	// enable kml indent if TIDY_KML == false
	define("INDENT_KML",true);

	KmlSuperOverlay::controller(array_filter(explode("/",explode(URL_BASE,$_REQUEST['qs'])[1])),URL_BASE);
?>