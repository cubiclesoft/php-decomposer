<?php
	// Decomposer.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

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
		@mkdir($rootpath . "/projects/" . $name . "/staging", 0770);
		@mkdir($rootpath . "/projects/" . $name . "/final", 0770);
		@copy($rootpath . "/support/composer.phar", $rootpath . "/projects/" . $name . "/staging/composer.phar");

		// Prepare examples file.
		$data = file_get_contents($rootpath . "/support/base_examples.php");
		$data = str_replace("@DECOMPOSER_FUNCTIONS@", var_export($rootpath . "/support/decomposer_functions.php", true), $data);
		$data = str_replace("@ROOTPATH@", var_export($rootpath, true), $data);
		$data = str_replace("@PROJECT@", var_export($name, true), $data);
		$data = str_replace("@PROJECTNAME@", $name, $data);
		@file_put_contents($rootpath . "/projects/" . $name . "/staging/examples.php", $data);

		$result = array(
			"success" => true,
			"project" => array(
				"name" => $name,
				"path" => $rootpath . "/projects/" . $name . "/staging"
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

			$stagingpath = $rootpath . "/projects/" . $name . "/staging";
			$finalpath = $rootpath . "/projects/" . $name . "/final";

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

			// Instrument the build.
			@unlink($stagingpath . "/instrumented.json");
			putenv("DECOMPOSER");
			$cwd = getcwd();
			chdir($stagingpath);
			system(escapeshellarg(PHP_BINARY) . " examples.php");
			chdir($cwd);

			if (!file_exists($stagingpath . "/instrumented.json"))  CLI::DisplayError("Unable to find the file '" . $stagingpath . "/instrumented.json" . "'.  It appears that '" . $stagingpath . "/examples.php' is not completing normally.  Unable to instrument the Composer build.");

			function FindPHPFiles($path)
			{
				global $extrafiles;

				$dir = @opendir($path);
				if ($dir)
				{
					while (($file = readdir($dir)) !== false)
					{
						if ($file !== "." && $file !== "..")
						{
							if (is_dir($path . "/" . $file))  FindPHPFiles($path . "/" . $file);
							else if (strtolower(substr($file, -4)) === ".php")  $extrafiles[$path . "/" . $file] = true;
						}
					}

					closedir($dir);
				}
			}

			// Find all vendor PHP files.
			$extrafiles = array();
			$path = $stagingpath . "/vendor";
			$dir = @opendir($path);
			if (!$dir)  CLI::DisplayError("Unable to find the directory '" . $path . "'.  Did you run Composer?");
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== ".." && $file !== "composer" && is_dir($path . "/" . $file))
				{
					FindPHPFiles($path . "/" . $file);
				}
			}
			closedir($dir);

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
					"DirectoryIterator" => true,
					"RecursiveDirectoryIterator" => true,
				);

				foreach ($tokens as $num => $token)
				{
					if (is_array($token) && $token[0] === T_STRING && isset($warnstrs[$token[1]]))  $warnings[] = "Found '" . $token[1] . "' in '" . $file . "' on line " . $token[2] . ".";
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

			// Process instrumented files first.
			$finalfiles = array();
			$instrumented = json_decode(file_get_contents($stagingpath . "/instrumented.json"), true);
			if ($mode === "all")
			{
				foreach ($extrafiles as $file => $val)  $instrumented[] = $file;
			}
			$workfile = "<" . "?php\n\t// Working file.\n\trequire_once " . var_export($finalpath . "/decomposed.php", true) . ";\n";
			file_put_contents($finalpath . "/workfile.php", $workfile);
			$data = array("<" . "?php\n\t// Generated with Decomposer.");
			do
			{
				// Attempt to resolve all dependencies in dependency order.
				$repeatable = false;
				$processed = false;

				foreach ($instrumented as $num => $file)
				{
					$file = str_replace("\\", "/", $file);

					if (!isset($extrafiles[$file]))  unset($instrumented[$num]);
					else
					{
						$result = ProcessNamespacedFile($file, true);
						if ($result === false)  unset($instrumented[$num]);
						else
						{
							// Verify correct functionality.
							$data2 = $data;
							$data2[] = $result;

							file_put_contents($finalpath . "/decomposed.php", implode("\n\n", $data2));

							ob_start();
							passthru(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($finalpath . "/workfile.php") . " 2>&1");
							$result = trim(ob_get_contents());
							ob_end_clean();

							if ($result !== "")  $repeatable = true;
							else
							{
								$data = $data2;

								unset($instrumented[$num]);
								unset($extrafiles[$file]);

								$processed = true;
							}
						}
					}
				}
			} while ($repeatable && $processed);

			// Dump final work file before appending autoloader logic.
			file_put_contents($finalpath . "/decomposed.php", implode("\n\n", $data));

			if ($mode === "auto" && count($extrafiles))
			{
				$str = "namespace {\n";
				$str .= "\tspl_autoload_register(function (\$class) {\n";
				$str .= "\t\tif (file_exists(__DIR__ . \"/" . $name . "_decomposed_extras.php\"))  require_once __DIR__ . \"/" . $name . "_decomposed_extras.php\";\n";
				$str .= "\t});\n";
				$str .= "}";

				$data[] = $str;
			}

			file_put_contents($finalpath . "/" . $name . "_decomposed.php", implode("\n\n", $data));
			$finalfiles[] = $finalpath . "/" . $name . "_decomposed.php";

			@unlink($finalpath . "/" . $name . "_decomposed_extras.php");
			if ($mode !== "all" && count($extrafiles))
			{
				$workfile .= "\trequire_once " . var_export($finalpath . "/decomposed_extras.php", true) . ";\n";
				file_put_contents($finalpath . "/workfile.php", $workfile);

				$data = array("<" . "?php\n\t// Generated with Decomposer.");

				do
				{
					// Attempt to resolve all dependencies in dependency order.
					$repeatable = false;
					$processed = false;

					foreach ($extrafiles as $file => $val)
					{
						$result = ProcessNamespacedFile($file, true);
						if ($result === false)  unset($extrafiles[$file]);
						else
						{
							// Verify correct functionality.
							$data2 = $data;
							$data2[] = $result;

							file_put_contents($finalpath . "/decomposed_extras.php", implode("\n\n", $data2));

							ob_start();
							passthru(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($finalpath . "/workfile.php") . " 2>&1");
							$result = trim(ob_get_contents());
							ob_end_clean();

							if ($result !== "")  $repeatable = true;
							else
							{
								$data = $data2;

								unset($extrafiles[$file]);

								$processed = true;
							}
						}
					}
				} while ($repeatable && $processed);

				file_put_contents($finalpath . "/" . $name . "_decomposed_extras.php", implode("\n\n", $data));
				$finalfiles[] = $finalpath . "/" . $name . "_decomposed_extras.php";
			}

			// Cleanup work environment.
			@unlink($finalpath . "/workfile.php");
			@unlink($finalpath . "/decomposed.php");
			@unlink($finalpath . "/decomposed_extras.php");

			// Run the finalized build.
			@unlink($finalpath . "/orig_composer.json");
			putenv("DECOMPOSER=FINAL");
			$cwd = getcwd();
			chdir($stagingpath);
			system(escapeshellarg(PHP_BINARY) . " examples.php");
			chdir($cwd);
			putenv("DECOMPOSER");

			if (!file_exists($finalpath . "/orig_composer.json"))  CLI::DisplayError("Unable to find the file '" . $finalpath . "/orig_composer.json" . "'.  It appears that '" . $stagingpath . "/examples.php' is not completing normally.  Unable to verify the final build.", array("success" => false, "warnings" => $warnings, "failed" => $extrafiles));
			$finalfiles[] = $finalpath . "/orig_composer.json";

			$result = array(
				"success" => true,
				"warnings" => $warnings,
				"failed" => $extrafiles,
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
							if (is_dir($path . "/" . $file))  DeleteDirectory($path . "/" . $file);
							else  @unlink($path . "/" . $file);
						}
					}
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