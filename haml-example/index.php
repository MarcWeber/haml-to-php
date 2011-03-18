<?php

require_once(dirname(__FILE__).'/../haml/Haml.php');

define('BASE',dirname(__FILE__));

define('HAML_DIR',BASE.'/haml');
define('HAML_TEMLPATE_CACHE',BASE.'/template-cache');

function haml($haml_file /*, ... */){
  $args = func_get_args();
  $c = HAML_TEMLPATE_CACHE.'/'.$haml_file;
  $h = HAML_DIR.'/'.$haml_file;
  if (true || !file_exists($c)
    || filemtime($c) < filemtime($h)){
      file_put_contents($c, Haml::hamlToPHPStr(file_get_contents($h)));
  }
  $args[0] = $c; // first arg is file
  return call_user_func_array('Haml::runTemplate', $args);
}

$_POST = array_merge(
  array('run' => 0,
        'yourtext' => 'initial text'
      ),
  $_POST
);

@ini_set('xdebug.max_nesting_level','600');

$g = array('title' => "You're welcome to modify this HAML sample and play with it");

echo haml('main.haml', $g);
