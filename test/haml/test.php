<?php

/* run tests in test/haml/ruby-haml-3.0.24-tests.json
 */

require_once(dirname(__FILE__).'/../../haml/Haml.php');


$nr = 0;

function d($ar, $key, $default){ return isset($ar[$key]) ? $ar[$key] : $default; }

function strip($s)
{
  if (is_null($s))
    return $s;
  // dropping / to ignore HTML vs XHTML
  // haml is quoting attrs by, phamlp by " ?
  return str_replace("\n","",preg_replace('/[ \t]*/','',$s));
  return $s;
}

$ok = 0;
$max_failures = 1;

Haml::hamlInternalTest();

# $only = 4;
$skip = array(
  "content in a 'preserve' filter",
  "boolean attribute with XHTML",
  "boolean attribute with HTML"
);

foreach (array(
      dirname(__FILE__).'/ruby-haml-3.0.24-tests.json',
      dirname(__FILE__).'/extra-tests.json',
  ) as $file) {

  $tests = json_decode(file_get_contents($file), true);

  foreach ($tests as $groupheader => $group) {
    echo "===> $groupheader\n";
    foreach ($group as $name => $test) {
      $nr ++;
      if (in_array($name, $skip))
        continue;
      if (isset($only) && $nr != $only)
        continue;
      
      $haml = $test['haml'];
      $expected = $test['html'];

      echo "$nr: $name\n";
      // try {

        $f = "test$nr";
        if (isset($only));
        $opts = array('filename' => $name);
        $locals = d($test,'locals',array());

        $way = 1;
        switch($way) {
          case 1:
            // each step manually
            $hamlTree = new HamlTree($haml, array_merge($opts, d($test,'config',array())));
            # var_export($hamlTree->childs);
            $php_function = Haml::treeToPHP($hamlTree, $f);
            # echo "$php_function\n";
            eval($php_function); // create function
            $rendered = $f($locals);
            break;
          case 2:
            // generate php function:
            $php_function = Haml::hamlToPHPStr($haml, d($test,'config',array()), $f); 
            eval($php_function); // create function
            $rendered = $f($locals);
            break;
          case 3:
            // generate code to be required or evaled:
            $php = Haml::hamlToPHPStr($haml, d($test,'config',array())); 
            $php_file = dirname(__FILE__).'/tmp/tmp.php';
            file_put_contents($php_file, $php);
            $rendered = Haml::runTemplate($php_file, $locals);
            break;
          default:
        }


/*
      } catch (Exception $e){
        $rendered =
          (d($test,'expect_parse_failure', false))
          ? null
          : "Exception: ".$e->getMessage();
      }
*/

      list($e_s, $got_s) = array_map('strip', array($expected, $rendered));

      echo "haml: $haml\n";
      if ($e_s === $got_s){
        $ok ++;
        echo "ok $nr\n";
      }else{
        echo "failed:\n";
        echo "expected: $e_s\n";
        echo "got: $rendered\n";
        var_export($test);
        $max_failures --;
        if ($max_failures == 0)
          exit(0);
      }
      echo "\n";
    }
  }
}

echo "$ok of $nr \n";
