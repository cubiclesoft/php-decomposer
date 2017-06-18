Decomposer
==========

Generate no-conflict standalone builds of PHP Composer/PSR-enabled software.

Features
--------

* Quickly reduce Composer/PSR software down to one or two files containing only the parts that are actually used.
* Three decomposition modes depending on application needs.
* Easily create and manage projects.
* A complete, question/answer enabled command-line interface.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

The command-line interface is question/answer enabled, which means all you have to do is run:

```
php decomposer.php
```

Which will enter interactive mode and guide you through the entire process.

Once you grow tired of manually entering information, you can pass in some or all of the answers to the questions on the command-line:

```
php decomposer.php list

php decomposer.php create myproject

php decomposer.php -s decompose myproject all
```

The -s option suppresses normal output (except for fatal error conditions), which allows for the processed JSON result to be the only thing that is output.  Useful for automation.

Creating Projects
-----------------

To create a project, run:

```
php decomposer.php create [yourprojectname]

cd projects/[yourprojectname]/staging

php composer.phar require [vendor/someproject:~1.2]
php composer.phar require [vendor2/someotherproject:~2.0]
...

cd ../../..
php decomposer.php decompose [yourprojectname] all
```

This will create a set of directories, run Composer to pull down the various bits of software, and then generate the final decomposed build bundling every file into one fully-validated PHP file in the `projects/[yourprojectname]/final` directory.

Instrumenting Builds
--------------------

The 'decompose' command does all of the heavy lifting.  Using the 'all' mode will always work but might be a bit more resource intensive (e.g. RAM) than might be desired on the tail end of things (i.e. your application).  To minimize resource usage, it is possible to teach the command about the classes that are actually going to be used in the most common scenarios.  This is accomplished by instrumenting the build with one or more working examples.

Edit the `/projects/[yourprojectname]/staging/examples.php` file that was generated during project creation.  After that, grab some example code and put it where `examples.php` says to put example code.  Test the functionality by manually running `examples.php` from the command-line.  Note that the usual `require "vendor/autoload.php";` should NOT be called as that is automatically handled by the boilerplate code.

```
cd projects/[yourprojectname]/staging
php examples.php
```

Running the code updates 'instrumented.json', which contains a list of files that were loaded by `examples.php`.  This information is used to instrument the build when using 'auto' and 'none'.

Once the code is working, preferably with no output, the 'auto' and 'none' modes become more useful.

```
php decomposer.php decompose [yourprojectname] auto
```

The 'auto' mode appends an autoloader to the first generated file that loads the second file if the autoloader is ever called by PHP.  The 'none' mode is for anyone who likes to live dangerously and is okay with their application breaking in spectacular ways at inopportune times.

Patching Broken Software
------------------------

Sometimes a Composer project makes assumptions about where it sits in its ecosystem and the developers are stubborn to change the software.  If a file called `decomposer.diff` exists in the project's directory (i.e. a text diff file), Decomposer will automatically attempt to apply the patch to the software before instrumenting it.

If you design a working patch for using a specific project with Decomposer, be a good netizen and commit it back to the original repository.  The authors will (possibly) appreciate it.

At the end of a Decomposer run, it outputs any 'warnings' it encountered and 'failed' files.  Warnings are emitted for very specific functions that are known to cause issues and will likely require a patch.  Failed files are emitted for a variety of reasons and may or may not result in functional output.

Any patches should be extremely laser-focused such that they are immutable if they are applied multiple times as Decomposer will always attempt to apply all patches before instrumenting.

How It Works
------------

Decomposer takes in a number of pieces of information, runs any patches that need to be applied, instruments the build, generates a few PHP files to guarantee that dependency order is maintained and that no errors occur, and verifies the build.

The most interesting parts of the process are extracting just the useful content bits (i.e. code) and determining if adding the contents of any given file will result in PHP parser errors.  The former is accomplished with the built-in PHP tokenizer `token_get_all()`, which does most of the work of identifying things such as comments, whitespace, and namespace keywords.  Once the code has been cleaned up, it is bolted onto the end of the current working set and tested.  If the new working set fails with an error, then the file is deferred until later and the next one is tried.  This process repeats ad nauseum until no more files can be processed.

Decomposer is rather brute force-ish in its approach, which is why it can take anywhere from a few seconds to a few minutes to decompose a project:  Add class, run PHP, add class, run PHP, add class, run PHP, etc.
