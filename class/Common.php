<?php
	if (intval(PHP_VERSION[0]) < 8) {
		function str_contains($haystack, $needle): bool {
			return '' === $needle || false !== strpos($haystack, $needle);
		}

		function str_starts_with($haystack ,$needle) {
			return $needle === "" || strpos($haystack, $needle) === 0;
		}

		function str_ends_with($haystack ,$needle) {
			return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
		}
	}

	class Common {
		public static function getXmlFileAsAssocArray($xmlfile, &$rootname = null){
			if(!is_file($xmlfile))
				throw new Exception("xmlfile is null");
			$xml = simplexml_load_file($xmlfile,'SimpleXMLElement', LIBXML_NOCDATA);
			$rootname = $xml->getName();
			return json_decode(json_encode($xml), TRUE);
		}

		public static function assocArrayToXml($array){
			if(is_null($array))
				throw new Exception("array is null");
			foreach($array as $k => $v)
				$ret .= "<".$k.">".$v."</".$k.">";
			return $ret;
		}

		public static function scandirRecursiveFilePattern($path,$pattern) {
			return 
				array_map(
					function($input) {
						return str_replace(DIRECTORY_SEPARATOR,"/",$input);
					},
					array_keys(
						iterator_to_array(
							new RegexIterator(
								new RecursiveIteratorIterator(
									new RecursiveDirectoryIterator($path)
								), 
								$pattern, 
								RecursiveRegexIterator::GET_MATCH
							)
					)
				)
			);
		}

		public static function rutime($ru, $rus, $index) {
			return ($ru["ru_$index.tv_sec"]*1000 + intval($ru["ru_$index.tv_usec"]/1000))
				 -  ($rus["ru_$index.tv_sec"]*1000 + intval($rus["ru_$index.tv_usec"]/1000));
		}

		public static function afficheOctets($bytes, $decimals = 2){
			$sz = 'BKMGTP';
			$factor = floor((strlen($bytes) - 1) / 3);
			return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
		}

		public static function htmlAsHtml($html,$code = true){
			$code = ["",""];
			if($code)
				$code = ["<code>","</code>"];
			return $code[0].nl2br(str_replace(" ","&nbsp;",htmlentities($html))).$code[1];
		}
	}
?>