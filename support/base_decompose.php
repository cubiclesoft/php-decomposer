<?php
	// Skips several steps to decompose the project.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	array_splice($_SERVER["argv"], 1, 0, array("decompose", @PROJECT@));
	$_SERVER["argc"] = count($_SERVER["argv"]);

	require_once @ROOTPATH@ . "/decomposer.php";
?>