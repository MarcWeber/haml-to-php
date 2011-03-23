<?php

require_once(dirname(__FILE__).'/../haml/Haml.php');

define('BASE',dirname(__FILE__));

define('HAML_DIR',BASE.'/haml');
define('HAML_TEMLPATE_CACHE',BASE.'/template-cache');

$haml = new HamlFileCache(HAML_DIR, HAML_TEMLPATE_CACHE);
$haml->forceUpdate = true; // update cache on each request - usually you want to switch it off
$haml->options['ugly'] = false;

$_POST = array_merge(
  array('run' => 0,
        'yourtext' => 'initial text'
      ),
  $_POST
);

@ini_set('xdebug.max_nesting_level','600');

$g = array('title' => "You're welcome to modify this HAML sample and play with it");

echo $haml->haml('test.haml', $g);
