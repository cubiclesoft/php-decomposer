<?php
	// Instrumentation examples for your application.
	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Sets up Composer vs. Decomposed (i.e. calls 'vendor/autoload.php' for you).
	require_once @DECOMPOSER_FUNCTIONS@;
	DecomposerHelper::Init(@ROOTPATH@, @PROJECT@);

	// Put logic here that tests as many scenario(s) as you need in your application.


	// Once finished testing, run 'php decompose.php' to generate the files you will need.
	DecomposerHelper::Finalize();
?>