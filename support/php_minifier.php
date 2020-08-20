<?php
	// PHP minifier.  Minifies PHP files.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	class PHPMinifier
	{
		public static function DumpTokens($tokens)
		{
			foreach ($tokens as $token)
			{
				if (is_array($token))  echo "Line " . $token[2] . ": " . token_name($token[0]) . " | " . $token[1] . "\n";
				else  echo "Str:  " . $token . "\n";
			}
		}

		public static function Minify($filename, $data, $options = array())
		{
			if (!isset($options["require_namespace"]))  $options["require_namespace"] = false;
			if (!isset($options["remove_comments"]))  $options["remove_comments"] = true;
			if (!isset($options["convert_whitespace"]))  $options["convert_whitespace"] = true;
			if (!isset($options["check_dir_functions"]))  $options["check_dir_functions"] = false;
			if (!isset($options["wrap_includes"]))  $options["wrap_includes"] = false;
			if (!isset($options["return_tokens"]))  $options["return_tokens"] = false;

			// Very large PHP files will run the tokenizer out of RAM.  Disable memory limits.
			ini_set("memory_limit", "-1");

			// Be overly aggressive on RAM cleanup.
			if (function_exists("gc_mem_caches"))  gc_mem_caches();

			$tokens = token_get_all($data);

			// Remove all comments and change spaces to tabs to considerably reduce PHP file size.
			if ($options["remove_comments"] || $options["convert_whitespace"])
			{
				foreach ($tokens as $num => $token)
				{
					if ($options["remove_comments"] && is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT))  unset($tokens[$num]);
					else if ($options["convert_whitespace"] && is_array($token) && $token[0] === T_WHITESPACE)  $tokens[$num][1] = str_replace("\r", "\n", str_replace("\r\n", "\n", str_replace("  ", "\t", str_replace("   ", "\t", str_replace("    ", "\t", $token[1])))));
				}
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
				$open = true;
				$changed = false;
				foreach ($tokens as $num => $token)
				{
					if (is_array($token))
					{
						if ($token[0] === T_CLOSE_TAG)  $open = false;
						else if ($token[0] === T_OPEN_TAG)  $open = true;
					}
					else if (!$changed)
					{
						if ($token === "{")  break;  // }
						else if ($token === ";")
						{
							$tokens[$num] = " {";
							$changed = true;
						}
					}
				}

				// {{
				if ($changed)  $tokens[] = ($open ? "\n}" : "<" . "?php\n}");
			}
			else
			{
				if ($options["require_namespace"])  return array("success" => false, "error" => self::PMTranslate("Missing namespace in '%s'.", $filename), "errorcode" => "missing_required_namespace");
			}

			// Process warnings.
			$warnings = array();
			if ($options["check_dir_functions"] || $options["wrap_includes"])
			{
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
					if ($options["check_dir_functions"] && is_array($token) && $token[0] === T_STRING && isset($warnstrs[strtolower($token[1])]))
					{
						$warnings[] = self::PMTranslate("Found '%s' in '%s' on line '%d'.", $token[1], $filename, $token[2]);
					}

					if ($options["wrap_includes"] && is_array($token) && ($token[0] === T_REQUIRE || $token[0] === T_REQUIRE_ONCE || $token[0] === T_INCLUDE || $token[0] === T_INCLUDE_ONCE))
					{
						$warnings[] = self::PMTranslate("Found '%s' in '%s' on line '%d'.", $token[1], $filename, $token[2]);

						// Determine what string follows and inject a file_exists() wrapper.
						$y = count($tokens);
						$filename2 = "";
						for ($x = $num + 1; $x < $y; $x++)
						{
							if (!is_array($tokens[$x]) && $tokens[$x] === ";")
							{
								$tokens[$x] = " : \"\")" . $tokens[$x];

								break;
							}
							else if (is_array($tokens[$x]) && $tokens[$x][0] === T_CLOSE_TAG)
							{
								$tokens[$x][1] = " : \"\")" . $tokens[$x][1];

								break;
							}

							$filename2 .= (is_array($tokens[$x]) ? $tokens[$x][1] : $tokens[$x]);
						}

						$tokens[$num][1] = "(file_exists(" . trim($filename2) . ") ? " . $tokens[$num][1];
					}
				}
			}

			// Condense the data.
			$result = "<" . "?php\n" . ($namespaced ? "" : "namespace {\n");
			$open = true;
			foreach ($tokens as $token)
			{
				if (is_array($token))
				{
					if ($token[0] === T_CLOSE_TAG)  $open = false;
					else if ($token[0] === T_OPEN_TAG)  $open = true;
				}

				$result .= (is_array($token) ? $token[1] : $token);
			}
			// {{
			if (!$namespaced)  $result .= ($open ? "}" : "<" . "?php\n}");

			// Be overly aggressive on RAM cleanup.
			if (function_exists("gc_mem_caches"))  gc_mem_caches();

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
			if ($options["return_tokens"])  $data = array_values($tokens);
			else
			{
				$data = "";
				foreach ($tokens as $token)
				{
					$data .= (is_array($token) ? $token[1] : $token);
				}
			}

			return array("success" => true, "filename" => $filename, "data" => $data, "warnings" => $warnings);
		}

		public static function MinifyFiles($srcdir, $destdir, $recurse = true, $options = array())
		{
			if (!isset($options["file_exts"]))  $options["file_exts"] = array("php" => true);
			if (is_string($options["file_exts"]))  $options["file_exts"] = array($options["file_exts"] => true);
			unset($options["return_tokens"]);

			$srcdir = rtrim(str_replace("\\", "/", $srcdir), "/");
			$destdir = rtrim(str_replace("\\", "/", $destdir), "/");

			self::MinifyFiles_Internal($srcdir, $destdir, $recurse, $options);
		}

		private static function MinifyFiles_Internal($srcdir, $destdir, $recurse = true, $options = array())
		{
			@mkdir($destdir, 0777, true);

			$dir = @opendir($srcdir);
			if ($dir)
			{
				while (($file = readdir($dir)) !== false)
				{
					if ($file != "." && $file != "..")
					{
						if (is_dir($srcdir . "/" . $file))
						{
							if ($recurse)  self::MinifyFiles_Internal($srcdir . "/" . $file, $destdir . "/" . $file, true, $options);
						}
						else
						{
							$pos = strrpos($file, ".");
							$ext = ($pos !== false ? (string)substr($file, $pos + 1) : "");
							if (isset($options["file_exts"][$ext]))
							{
								$data = file_get_contents($srcdir . "/" . $file);
								$result = self::Minify($srcdir . "/" . $file, $data, $options);

								file_put_contents($destdir . "/" . $file, ($result["success"] ? "<" . "?php\n" . $result["data"] : $data));
							}
							else
							{
								$fp = @fopen($srcdir . "/" . $file, "rb");
								$fp2 = @fopen($destdir . "/" . $file, "wb");

								if ($fp !== false && $fp2 !== false)
								{
									while (($data = fread($fp, 1048576)) !== false  && $data !== "")  fwrite($fp2, $data);
								}

								if ($fp2 !== false)  fclose($fp2);
								if ($fp !== false)  fclose($fp);
							}
						}
					}
				}

				closedir($dir);
			}
		}

		protected static function PMTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>