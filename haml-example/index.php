<?php

require_once(dirname(__FILE__).'/../haml/Haml.php');

define('BASE',dirname(__FILE__));

define('HAML_DIR',BASE.'/haml');
define('HAML_TEMLPATE_CACHE',BASE.'/template-cache');

// create caching haml object
$haml = new HamlFileCache(HAML_DIR, HAML_TEMLPATE_CACHE);

// update cache on each request - usually you want to switch it off
$haml->forceUpdate = true; 
// somewhat more pretty HTML - usually you want to switch it off
$haml->options['ugly'] = false;

// merge some defaults into POST.
$_POST = array_merge(
  array('run' => 0,
        'yourtext' => 'initial text'
      ),
  $_POST
);

// xdebug prevents segfaults (caused by stack overflows)
// by introducing a function call depths.
// default is 100 which is not enough for HAML-TO-PHP
@ini_set('xdebug.max_nesting_level','600');

// the data passed to the .haml file. extract() is being used to put keys in scope
$g = array('title' => "You're welcome to modify this HAML sample and play with it");

// run the template. Parsing, translating to PHP and writing cache file is done 
// automatically
echo $haml->haml('test.haml', $g);
