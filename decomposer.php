<?php
	// Decomposer.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/str_basics.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"userinput" => "="
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Decomposer command-line tool\n";
		echo "Purpose:  Generate no-conflict standalone builds of Composer/PSR-enabled software.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] cmd [cmdoptions]\n";
		echo "Options:\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " create name=myproject\n";
		echo "\tphp " . $args["file"] . " decompose myproject\n";

		exit();
	}

	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	@mkdir($rootpath . "/projects", 0770);

	// Get the command.
	$cmds = array("list" => "List projects", "create" => "Create a new project", "decompose" => "Decompose dependencies for a project", "delete" => "Deletes a project");

	$cmd = CLI::GetLimitedUserInputWithArgs($args, "cmd", "Command", false, "Available commands:", $cmds, true, $suppressoutput);

	function DisplayResult($result)
	{
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		exit();
	}

	function ProjectsList()
	{
		global $rootpath;

		$result = array("success" => true, "data" => array());
		$path = $rootpath . "/projects";
		$dir = opendir($path);
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== "..")
				{
					$result["data"][$file] = $file;
				}
			}

			closedir($dir);
		}

		ksort($result["data"], SORT_NATURAL | SORT_FLAG_CASE);

		return $result;
	}

	function GetProjectName()
	{
		global $suppressoutput, $args;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "name"))  $name = CLI::GetUserInputWithArgs($args, "name", "Project name", false, "", $suppressoutput);
		else
		{
			$result = ProjectsList();
			if (!$result["success"])  DisplayResult($result);

			$names = array();
			foreach ($result["data"] as $id => $name)  $names[$id] = $name;
			if (!count($names))  CLI::DisplayError("No projects have been created.  Try creating your first project with the command:  create");
			$name = CLI::GetLimitedUserInputWithArgs($args, "name", "Project name", false, "Available projects:", $names, true, $suppressoutput);
		}

		return $name;
	}

	if ($cmd === "list")  DisplayResult(ProjectsList());
	else if ($cmd === "create")
	{
		// Get the project name.
		do
		{
			$name = CLI::GetUserInputWithArgs($args, "name", "Project name", false, "", $suppressoutput);
			$name = Str::FilenameSafe($name);
			$path = $rootpath . "/projects/" . $name;
			$found = is_dir($path);
			if ($found)  CLI::DisplayError("A project with that name already exists.  The path '" . $path . "' already exists.", false, false);
		} while ($found);

		// Initialize directory structure.
		@mkdir($rootpath . "/projects/" . $name, 0770);
		@mkdir($rootpath . "/projects/" . $name . "/final", 0770);
		@copy($rootpath . "/support/composer.phar", $rootpath . "/projects/" . $name . "/composer.phar");

		// Prepare examples file.
		$data = file_get_contents($rootpath . "/support/base_examples.php");
		$data = str_replace("@DECOMPOSER_FUNCTIONS@", var_export($rootpath . "/support/decomposer_functions.php", true), $data);
		$data = str_replace("@ROOTPATH@", var_export($rootpath, true), $data);
		$data = str_replace("@PROJECT@", var_export($name, true), $data);
		@file_put_contents($rootpath . "/projects/" . $name . "/examples.php", $data);

		// Prepare convenience decompose file.
		$data = file_get_contents($rootpath . "/support/base_decompose.php");
		$data = str_replace("@ROOTPATH@", var_export($rootpath, true), $data);
		$data = str_replace("@PROJECT@", var_export($name, true), $data);
		@file_put_contents($rootpath . "/projects/" . $name . "/decompose.php", $data);

		$result = array(
			"success" => true,
			"project" => array(
				"name" => $name,
				"path" => $rootpath . "/projects/" . $name
			)
		);

		DisplayResult($result);
	}
	else
	{
		$name = GetProjectName();

		if ($cmd === "decompose")
		{
			$modes = array(
				"all" => "All classes in one file, no autoloader (maximum RAM usage)",
				"auto" => "Classes used by 'examples.php' with an autoloader for any missing classes (adaptive)",
				"none" => "Classes used by 'examples.php', no autoloader for missing classes (living dangerously)"
			);

			$mode = CLI::GetLimitedUserInputWithArgs($args, "mode", "Mode", false, "Available class modes:", $modes, true, $suppressoutput);

			$stagingpath = $rootpath . "/projects/" . $name;
			$finalpath = $rootpath . "/projects/" . $name . "/final";
			@mkdir($finalpath, 0770);

			// Determine the system diff patcher to use.
			$os = php_uname("s");
			if (strtoupper(substr($os, 0, 3)) == "WIN")  $patcher = $rootpath . "/support/winpatch/patch.exe";
			else  $patcher = "patch";

			function ApplyPatches($path)
			{
				global $patcher;

				$dir = @opendir($path);
				if ($dir)
				{
					while (($file = readdir($dir)) !== false)
					{
						if ($file !== "." && $file !== "..")
						{
							if (is_dir($path . "/" . $file))  ApplyPatches($path . "/" . $file);
							else if ($file === "decomposer.diff")
							{
								$cwd = getcwd();
								chdir($path);
								ob_start();
								passthru(escapeshellarg($patcher) . " --forward decomposer.diff 2>&1");
								ob_end_clean();
								chdir($cwd);
							}
						}
					}

					closedir($dir);
				}
			}

			// Apply any decomposer patches to the software.
			$path = $stagingpath . "/vendor";
			$dir = @opendir($path);
			if (!$dir)  CLI::DisplayError("Unable to find the directory '" . $path . "'.  Did you run Composer?");
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== ".." && $file !== "composer" && is_dir($path . "/" . $file))
				{
					ApplyPatches($path . "/" . $file);
				}
			}
			closedir($dir);

			function DumpTokens($tokens)
			{
				foreach ($tokens as $token)
				{
					if (is_array($token))  echo "Line " . $token[2] . ": " . token_name($token[0]) . " | " . $token[1] . "\n";
					else  echo "Str:  " . $token . "\n";
				}
			}

			$warnings = array();
			function ProcessNamespacedFile($file, $requirenamespace)
			{
				global $warnings;

				$tokens = token_get_all(file_get_contents($file));

				// Remove all comments and change spaces to tabs to considerably reduce PHP file size.
				foreach ($tokens as $num => $token)
				{
					if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT))  unset($tokens[$num]);
					else if (is_array($token) && $token[0] === T_WHITESPACE)  $tokens[$num][1] = str_replace("\r", "\n", str_replace("\r\n", "\n", str_replace("  ", "\t", str_replace("   ", "\t", str_replace("    ", "\t", $token[1])))));
				}
				$tokens = array_values($tokens);

				// Remove starting and trailing PHP tags and whitespace.
				while (count($tokens) && is_array($tokens[0]) && ($tokens[0][0] === T_OPEN_TAG || $tokens[0][0] === T_WHITESPACE))  array_shift($tokens);
				while (count($tokens) && is_array($tokens[count($tokens) - 1]) && ($tokens[count($tokens) - 1][0] === T_CLOSE_TAG || $tokens[count($tokens) - 1][0] === T_WHITESPACE))  array_pop($tokens);

				// Determine namespace used (if any).
				$namespaced = (count($tokens) && is_array($tokens[0]) && $tokens[0][0] === T_NAMESPACE);
				if ($namespaced)
				{
					// Change namespace ';' to '{' and append a '}'.
					foreach ($tokens as $num => $token)
					{
						if (!is_array($token))
						{
							if ($token === "{")  break;  // }
							else if ($token === ";")
							{
								$tokens[$num] = " {";
								$tokens[] = "\n}";

								break;
							}
						}
					}
				}
				else
				{
					if ($requirenamespace)  return false;
				}

				// Process warnings.
				$warnstrs = array(
					"opendir" => true,
					"readdir" => true,
					"closedir" => true,
					"scandir" => true,
					"glob" => true,
					"directoryiterator" => true,
					"recursivedirectoryiterator" => true,
				);

				foreach ($tokens as $num => $token)
				{
					if (is_array($token) && $token[0] === T_STRING && isset($warnstrs[strtolower($token[1])]))
					{
						$warnings[] = "Found '" . $token[1] . "' in '" . $file . "' on line " . $token[2] . ".";
					}

					if (is_array($token) && ($token[0] === T_REQUIRE || $token[0] === T_REQUIRE_ONCE || $token[0] === T_INCLUDE || $token[0] === T_INCLUDE_ONCE))
					{
						$warnings[] = "Found '" . $token[1] . "' in '" . $file . "' on line " . $token[2] . ".";

						// Determine what string follows and inject a file_exists() wrapper.
						$y = count($tokens);
						$filename = "";
						for ($x = $num + 1; $x < $y; $x++)
						{
							if (!is_array($tokens[$x]) && $tokens[$x] === ";")
							{
								$tokens[$x] .= " }";

								break;
							}
							else if (is_array($tokens[$x]) && $tokens[$x][0] === T_CLOSE_TAG)
							{
								$tokens[$x][1] = "} " . $tokens[$x][1];

								break;
							}

							$filename .= (is_array($tokens[$x]) ? $tokens[$x][1] : $tokens[$x]);
						}

						$tokens[$num][1] = "{ if (file_exists(" . trim($filename) . "))  " . $tokens[$num][1];
					}
				}

				// Condense the data.
				$result = "<" . "?php\n" . ($namespaced ? "" : "namespace {\n");
				foreach ($tokens as $token)
				{
					$result .= (is_array($token) ? $token[1] : $token);
				}
				if (!$namespaced)  $result .= "}";

				// Clean up and remove spurious empty lines and trailing whitespace.
				$tokens = token_get_all($result);

				// Remove starting PHP tags and whitespace (again).
				while (count($tokens) && is_array($tokens[0]) && ($tokens[0][0] === T_OPEN_TAG || $tokens[0][0] === T_WHITESPACE))  array_shift($tokens);

				foreach ($tokens as $num => $token)
				{
					if (is_array($token) && $token[0] === T_WHITESPACE)
					{
						$lines = explode("\n", $token[1]);

						if (count($lines) > 2)  $tokens[$num][1] = "\n\n" . $lines[count($lines) - 1];
						else if (count($lines) == 2)  $tokens[$num][1] = "\n" . $lines[count($lines) - 1];
					}
				}

				// Prepare the result.
				$result = "";
				foreach ($tokens as $token)
				{
					$result .= (is_array($token) ? $token[1] : $token);
				}

				return $result;
			}

			function FindPHPFiles($path)
			{
				global $extrafiles;

				if (is_file($path) && strtolower(substr($path, -4)) === ".php")
				{
					$extrafiles[$path] = ProcessNamespacedFile($path, false);

					return;
				}

				$dir = @opendir($path);
				if ($dir)
				{
					while (($file = readdir($dir)) !== false)
					{
						if ($file !== "." && $file !== "..")
						{
							if (is_dir($path . "/" . $file))  FindPHPFiles($path . "/" . $file);
							else if (strtolower(substr($file, -4)) === ".php")  $extrafiles[$path . "/" . $file] = ProcessNamespacedFile($path . "/" . $file, false);
						}
					}

					closedir($dir);
				}
			}

			function FindComposerFiles($path)
			{
				if (file_exists($path . "/composer.json"))
				{
					$data = json_decode(file_get_contents($path . "/composer.json"), true);
					if (is_array($data) && isset($data["autoload"]))
					{
						foreach ($data["autoload"] as $items)
						{
							foreach ($items as $item)
							{
								if (substr($item, -1) === "/")  $item = substr($item, 0, -1);

								FindPHPFiles($path . "/" . $item);
							}
						}
					}

					return;
				}

				$dir = @opendir($path);
				if ($dir)
				{
					while (($file = readdir($dir)) !== false)
					{
						if ($file !== "." && $file !== "..")
						{
							if (is_dir($path . "/" . $file))  FindComposerFiles($path . "/" . $file);
						}
					}

					closedir($dir);
				}
			}

			// Find all vendor PHP class files.
			$extrafiles = array();
			$path = $stagingpath . "/vendor";
			$dir = @opendir($path);
			if (!$dir)  CLI::DisplayError("Unable to find the directory '" . $path . "'.  Did you run Composer?");
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== ".." && $file !== "composer" && is_dir($path . "/" . $file))
				{
					FindComposerFiles($path . "/" . $file);
				}
			}
			closedir($dir);

			// Instrument the build normally.
			@unlink($stagingpath . "/instrument_all_test.txt");
			@unlink($stagingpath . "/instrumented.json");
			@unlink($stagingpath . "/instrumented_all.json");
			@unlink($stagingpath . "/instrumented_failed.json");
			@unlink($stagingpath . "/instrumented_depend.json");
			putenv("DECOMPOSER");
			$cwd = getcwd();
			chdir($stagingpath);
			system(escapeshellarg(PHP_BINARY) . " examples.php");
			chdir($cwd);

			if (!file_exists($stagingpath . "/instrumented.json"))  CLI::DisplayError("Unable to find the file '" . $stagingpath . "/instrumented.json" . "'.  It appears that '" . $stagingpath . "/examples.php' is not completing normally.  Unable to instrument the Composer build.");

			// Reorder the files list based on the instrumentation that took place.
			$extrafiles2 = array();
			$instrumented = json_decode(file_get_contents($stagingpath . "/instrumented.json"), true);
			foreach ($instrumented as $file)
			{
				$file = str_replace("\\", "/", $file);

				if (isset($extrafiles[$file]))
				{
					$extrafiles2[$file] = $extrafiles[$file];

					unset($extrafiles[$file]);
				}
			}

			foreach ($extrafiles as $file => $data)  $extrafiles2[$file] = $data;
			$extrafiles = $extrafiles2;

			$failedfiles = array();
			@unlink($stagingpath . "/instrumented_log.txt");
			do
			{
				// Generate an instrumentation PHP file.
				@unlink($stagingpath . "/instrument_all_test.txt");
				$data = "<" . "?php\n\t// Decomposer automatically generated instrumentation file.";
				$data .= "\n\tif (getenv(\"DECOMPOSER\") !== \"INSTRUMENT_ALL\")  exit();";
				foreach ($extrafiles as $filename => $data2)
				{
					$data .= "\n\tDecomposerHelper::LoadFile(" . var_export($filename, true) . ");";
				}

				file_put_contents($stagingpath . "/instrument_all.php", $data);

				// Instrument the build.
				@unlink($stagingpath . "/instrumented_all.json");
				@unlink($stagingpath . "/instrumented_depend.json");
				putenv("DECOMPOSER=INSTRUMENT_ALL");
				$cwd = getcwd();
				chdir($stagingpath);
				ob_start();
				passthru(escapeshellarg(PHP_BINARY) . " examples.php 2>&1 >> instrumented_log.txt");
				ob_end_clean();
				chdir($cwd);
				putenv("DECOMPOSER");

				$redo = false;

				// If a fatal error occurred, attempt to recover.
				if (file_exists($stagingpath . "/instrument_all_test.txt"))
				{
					$filenames = explode("\n", trim(file_get_contents($stagingpath . "/instrument_all_test.txt")));
					$filename = array_pop($filenames);
					if (!isset($extrafiles[$filename]))
					{
						if ($filename === "")  CLI::DisplayError(file_get_contents($stagingpath . "/instrumented_log.txt") . "\n\nA fatal, unrecoverable error occurred.");
						else  CLI::DisplayError("The file '" . $filename . "' failed to load but is not in the list of files to instrument.");
					}

					if (!$suppressoutput)  echo "Removing:  " . $filename . "\n";
					$failedfiles[] = $filename;
					unset($extrafiles[$filename]);

					$redo = true;
				}

				// Reorder the files based on dependencies.
				if (file_exists($stagingpath . "/instrumented_depend.json"))
				{
					$dependencies = json_decode(file_get_contents($stagingpath . "/instrumented_depend.json"), true);
					if (!$suppressoutput)  echo "Dependencies left to resolve:  " . count($dependencies) . "\n";

					$extrafiles2 = array();
					foreach ($extrafiles as $file => $data)
					{
						if (isset($dependencies[$file]))
						{
							foreach ($dependencies[$file] as $file2)
							{
								$file2 = str_replace("\\", "/", $file2);

								if (!isset($extrafiles[$file2]))  CLI::DisplayError("The file '" . $file2 . "' is a dependency of '" . $file . "' but '" . $file2 . "' is not in the list of files to instrument.");

								$extrafiles2[$file2] = $extrafiles[$file2];
								unset($extrafiles[$file2]);
							}

							$redo = true;
						}

						$extrafiles2[$file] = $data;
						unset($extrafiles[$file]);
					}

					$extrafiles = $extrafiles2;
				}
			} while ($redo);

			if (!file_exists($stagingpath . "/instrumented_all.json"))  CLI::DisplayError("Unable to find the file '" . $stagingpath . "/instrumented_all.json" . "'.  It appears that '" . $stagingpath . "/examples.php' is not completing normally.  Unable to instrument the Composer build.");
			if (!file_exists($stagingpath . "/instrumented_depend.json"))  CLI::DisplayError("Unable to find the file '" . $stagingpath . "/instrumented_depend.json" . "'.  It appears that '" . $stagingpath . "/examples.php' is not completing normally.  Unable to instrument the Composer build.");

			@unlink($stagingpath . "/instrument_all.php");

			// Process instrumented files first.
			$finalfiles = array();
			if ($mode === "all")
			{
				foreach ($extrafiles as $file => $val)  $instrumented[] = $file;
			}
			$data = "<" . "?php\n\t// Generated with Decomposer.";
			$instrumented2 = array();
			foreach ($instrumented as $file)  $instrumented2[str_replace("\\", "/", $file)] = true;
			foreach ($extrafiles as $file => $data2)
			{
				if (isset($instrumented2[$file]))
				{
					$data .= "\n\n" . $data2;

					unset($extrafiles[$file]);
				}
			}

			if ($mode === "auto" && count($extrafiles))
			{
				$str .= "\n\n";
				$str .= "namespace {\n";
				$str .= "\tspl_autoload_register(function (\$class) {\n";
				$str .= "\t\tif (file_exists(__DIR__ . \"/" . $name . "_decomposed_extras.php\"))  require_once __DIR__ . \"/" . $name . "_decomposed_extras.php\";\n";
				$str .= "\t});\n";
				$str .= "}";

				$data .= $str;
			}

			file_put_contents($finalpath . "/" . $name . "_decomposed.php", $data);
			$finalfiles[] = $finalpath . "/" . $name . "_decomposed.php";

			@unlink($finalpath . "/" . $name . "_decomposed_extras.php");
			if ($mode !== "all" && count($extrafiles))
			{
				$data = "<" . "?php\n\t// Generated with Decomposer.";

				foreach ($extrafiles as $file => $data2)
				{
					$data .= "\n\n" . $data2;
				}

				file_put_contents($finalpath . "/" . $name . "_decomposed_extras.php", $data);
				$finalfiles[] = $finalpath . "/" . $name . "_decomposed_extras.php";
			}

			// Run the finalized build.
			@unlink($finalpath . "/orig_composer.json");
			putenv("DECOMPOSER=FINAL");
			$cwd = getcwd();
			chdir($stagingpath);
			system(escapeshellarg(PHP_BINARY) . " examples.php");
			chdir($cwd);
			putenv("DECOMPOSER");

			if (!file_exists($finalpath . "/orig_composer.json"))  CLI::DisplayError("Unable to find the file '" . $finalpath . "/orig_composer.json" . "'.  It appears that '" . $stagingpath . "/examples.php' is not completing normally.  Unable to verify the final build.", array("success" => false, "error" => "Build verification failed.", "errorcode" => "build_verification_failed", "info" => array("warnings" => $warnings, "failed" => $extrafiles)));
			$finalfiles[] = $finalpath . "/orig_composer.json";

			$result = array(
				"success" => true,
				"warnings" => $warnings,
				"failed" => $failedfiles,
				"project" => array(
					"name" => $name,
					"path" => $finalpath,
					"files" => $finalfiles
				)
			);

			DisplayResult($result);
		}
		else if ($cmd === "delete")
		{
			function DeleteDirectory($path)
			{
				$dir = @opendir($path);
				if ($dir)
				{
					while (($file = readdir($dir)) !== false)
					{
						if ($file !== "." && $file !== "..")
						{
							@unlink($path . "/" . $file);

							if (is_dir($path . "/" . $file))  DeleteDirectory($path . "/" . $file);
						}
					}

					closedir($dir);
				}

				@rmdir($path);
			}

			DeleteDirectory($rootpath . "/projects/" . $name);

			$result = array(
				"success" => true
			);

			DisplayResult($result);
		}
	}
?>