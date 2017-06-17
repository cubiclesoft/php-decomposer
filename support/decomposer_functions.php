<?php
	// Decomposer runtime functions.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	class DecomposerHelper
	{
		private static $rootpath, $project;

		public static function Init($rootpath, $project)
		{
			self::$rootpath = $rootpath;
			self::$project = $project;

			if (getenv("DECOMPOSER") === "FINAL")  require_once self::$rootpath . "/projects/" . self::$project . "/final/" . self::$project . "_decomposed.php";
			else  require_once self::$rootpath . "/projects/" . self::$project . "/staging/vendor/autoload.php";
		}

		public static function Finalize()
		{
			if (getenv("DECOMPOSER")  === "FINAL")  @copy(self::$rootpath . "/projects/" . self::$project . "/staging/composer.json", self::$rootpath . "/projects/" . self::$project . "/final/orig_composer.json");
			else  file_put_contents(self::$rootpath . "/projects/" . self::$project . "/staging/instrumented.json", json_encode(get_included_files(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}
	}
?>