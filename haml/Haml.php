<?php

/* author & copyright: Marc Weber
 * copyright 2011
 * license: GPL (contact me if you have other requirements )
 *
 * parsing is done by class HamlTree
 * user interface class Haml
 *
 * high level overview:
 * 1) HAML is parsed to a tree (represented as arrays). instantiating HamlTree does this
 * 2) tree is converted to items: (funcs: flatten*)
 *   - text (always quoted)
 *   - verbatim (never quoted)
 *   - php (add php. used by blocks)
 *   - phpecho (echo php result)
 * 3) create PHP code out of items (func toPHP)
 *   two options:
 *    - code which can be required
 *    - code which defines a function you can call
 * 1),2),3) are all called for you by hamlToPHPStr
 */

class HamlParseException extends Exception {}

// see filter option in option_defaults
class Filters {
  static public function plain($encoding, $s){ return $s; }
  static public function javascript($encoding, $s){
      return 
"<script type='text/javascript'>
  //<![CDATA[
    $s
  //]]>
</script>";
  }
  static public function css($encoding, $s){
    return 
"<style type='text/css'>
  /*<![CDATA[*/
    $s
  /*]]>*/
</style>";
  }
  static public function cdata($encoding, $s){
    return 
"<![CDATA[
    $s
  ]]>
";
  }
  static public function escaped($encoding, $s){
    return htmlentities($s, ENT_QUOTES, $encoding);
  }
  static public function php($encoding, $s){
    ob_start();
    ob_implicit_flush(false);
    return ob_get_clean();
  }
  static public function preserve($encoding, $s){
    // how to do this ?
    return htmlentities($s, ENT_QUOTES, $encoding);
  }
  // TODO sass, textile, markdown, maruku, ...
}

// the user interface you want to use:
class Haml {

  static public function hamlToPHPStr($str, $options = array(), $func_name = null){
    require_once dirname(__FILE__).'/HamlParser.php';
    $hamlTree = new HamlTree($str, $options);
    return $hamlTree->toPHP($func_name);
  }

  static public function runTemplate($file /*, .. */){
    // using these function to render the template allows
    // putting array keys in local scope using extract
    // $file should contain the str contents of hamlToPHPStr($haml); with unset 
    // func_name
    $args = func_get_args();
    foreach (array_slice($args,1) as $ar) {
      extract($ar);
    }
    ob_start();
    ob_implicit_flush(false);
    try {
      require "$file";
      // return echoed result:
      return ob_get_clean();
    } catch (Exception $e) {
      // on failure don't output partial result. It could destroy your HTML
      ob_get_clean();
      throw $e;
    }
  }

  // used by the generated code removes class duplicates
  static public function renderClassItems($items){
    // split "foo bar" into "foo", "bar" items
    $no_dups = array();
    foreach ($items as $i) {
      foreach (preg_split("/[ \t]+/",$a) as $value) {
        $no_dups[$value]=1;
      }
    }
    $no_dups = array_flip($no_dups);
    sort($no_dups);
    return implode(' ',$no_dups);
  }

  // helper function
  static public function array_merge($list){
    if (count($list) == 0)
      return array();
    elseif (count($list) == 1)
      return $list[0];
    $r = call_user_func_array('array_merge', $list);
    return $r;
  }
}
// vim: fdm=marker
