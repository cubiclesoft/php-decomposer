<?php
	// Decomposer runtime functions.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	class DecomposerHelper
	{
		private static $rootpath, $project, $testfp, $depend;

		public static function Init($rootpath, $project)
		{
			self::$rootpath = $rootpath;
			self::$project = $project;

			if (getenv("DECOMPOSER") === "FINAL")  require_once self::$rootpath . "/projects/" . self::$project . "/final/" . self::$project . "_decomposed.php";
			else if (getenv("DECOMPOSER") === "INSTRUMENT_ALL")
			{
				self::$testfp = fopen(self::$rootpath . "/projects/" . self::$project . "/staging/instrument_all_test.txt", "wb");
				self::$depend = array();

				require_once self::$rootpath . "/projects/" . self::$project . "/staging/vendor/autoload.php";

				require_once self::$rootpath . "/projects/" . self::$project . "/staging/instrument_all.php";

				file_put_contents(self::$rootpath . "/projects/" . self::$project . "/staging/instrumented_all.json", json_encode(get_included_files(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				file_put_contents(self::$rootpath . "/projects/" . self::$project . "/staging/instrumented_depend.json", json_encode(self::$depend, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
				fclose(self::$testfp);
				@unlink(self::$rootpath . "/projects/" . self::$project . "/staging/instrument_all_test.txt");

				exit();
			}
			else
			{
				require_once self::$rootpath . "/projects/" . self::$project . "/staging/vendor/autoload.php";
			}
		}

		public static function Finalize()
		{
			if (getenv("DECOMPOSER") === "FINAL")  @copy(self::$rootpath . "/projects/" . self::$project . "/staging/composer.json", self::$rootpath . "/projects/" . self::$project . "/final/orig_composer.json");
			else  file_put_contents(self::$rootpath . "/projects/" . self::$project . "/staging/instrumented.json", json_encode(get_included_files(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		private static function TryLoadFile($file)
		{
			fwrite(self::$testfp, $file . "\n");

			require_once $file;
		}

		public static function LoadFile($file)
		{
			$files = get_included_files();
			foreach ($files as $file2)
			{
				$file2 = str_replace("\\", "/", $file2);

				if ($file === $file2)  return;
			}

			self::TryLoadFile($file);

			$files2 = get_included_files();
			if (count($files2) > count($files) + 1)  self::$depend[$file] = array_reverse(array_slice($files2, count($files) + 1));
		}
	}
?>