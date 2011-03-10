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

# $only = 22;
$skip = array(58);

foreach (array(
      dirname(__FILE__).'/ruby-haml-3.0.24-tests.json',
      // dirname(__FILE__).'/extra-tests.json',
  ) as $file) {

  $tests = json_decode(file_get_contents($file), true);

  foreach ($tests as $groupheader => $group) {
    if (in_array($groupheader,array('headers'))){
      // TODO
      echo "skipping $groupheader\n";
      continue;
    }

    echo "===> $groupheader\n";
    foreach ($group as $name => $test) {
      $nr ++;
      if (in_array($nr, $skip))
        continue;
      if (isset($only) && $nr != $only)
        continue;
      
      $haml = $test['haml'];
      $expected = $test['html'];

      echo "$nr: $name\n";
      // try {

        $f = "test$nr";
        if (isset($only));
        echo "haml:\n$haml\n";
        $opts = array('filename' => $name);
        $hamlTree = new HamlTree($haml, array_merge($opts, d($test,'config',array())));
        var_export($hamlTree->childs);
        $php = Haml::treeToPHP($hamlTree, $f);

        // $php = Haml::hamlToPHPStr($haml, d($test,'config',array()), $f); 
        echo "start\n";
        echo $php;
        echo "end\n";
        eval($php);
        $rendered = $f(d($test,'locals',array()));

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
        echo "got: $got_s\n";
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
