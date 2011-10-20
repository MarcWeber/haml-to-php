<?php

/* author & copyright: Marc Weber
 * copyright 2011
 * license: GPL (contact me if you have other requirements )
 *
 * parsing is done by class HamlTree
 * user interface class Haml
 */

class HamlParseException extends Exception {}

// see filter option in option_defaults
// This class contains functions which are called by templates
class HamlUtilities {
  /* functions called by templates */
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


  // used by the generated code removes class duplicates
  static public function renderClassItems($items){
    // split "foo bar" into "foo", "bar" items
    $no_dups = array();
    $nr=0;
    foreach ($items as $a) {
      foreach (preg_split("/[ \t]+/",$a) as $value) {
        $no_dups[$value]=$nr++;
      }
    }
    $no_dups = array_flip($no_dups);
    sort($no_dups);
    return implode(' ',$no_dups);
  }

  // if $value evaluates to false and $xhtml is set then attr=value is not 
  // rendered at all. This function is only called for complex attribute values
  static public function renderAttribute($attr, $value, $q, $enc, $html){
    if ($value === false) return '';
    if ($value === true){
      if ($html)
        return "$attr";
      else
        return "$attr=$q$attr$q ";
    }
    return "$attr=$q".htmlentities($value, ENT_QUOTES, $enc).$q;
  }

  /* functions which could be useful to you */
  static public function hamlToPHPStr($str, $options, $filename = '', $func_name = null){
    require_once dirname(__FILE__).'/HamlParser.php';
    $hamlTree = new HamlTree($str, $options, $filename);
    // var_export($hamlTree->childs);
    return $hamlTree->toPHP($func_name);
  }

  static public function runTemplate($file /*, .. */){
    // using these function to render the template allows
    // putting array keys in local scope using extract
    // $file should contain the str contents of hamlToPHPStr($haml); with unset 
    // func_name
    $args = func_get_args();
    for ($i = 1; $i < count($args); $i++) {
      extract($args[$i]);
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

// the user interface you want to use:
// This objects keeps an array of options which is passed to the HAML to PHP renderer.
class Haml {

	public $options = array(
      /*
      Determines the output format. Normally the default is :xhtml, although 
      under Rails 3 it’s :html5, since that’s the Rails 3’s default format. 
      Other options are :html4 and :html5, which are identical to :xhtml 
      except there are no self-closing tags, the XML prolog is ignored and 
      correct DOCTYPEs are generated.  :escape_html
       */
      'format' => 'xhtml',
      /*
      Sets whether or not to escape HTML-sensitive characters in script. If 
      this is true, = behaves like &=; otherwise, it behaves like !=. Note 
      that if this is set, != should be used for yielding to subtemplates and 
      rendering partials. See also Escaping HTML and Unescaping HTML Defaults 
      to true.
       */
      'escape_html' => true,

      /*
      If set to true, Haml makes no attempt to properly indent or format the HTML 
      output. This significantly improves rendering performance but makes viewing the 
      source unpleasant. Defaults to true in Rails production mode, and false 
      everywhere else.
       */
      'ugly' => true,

      /* Whether or not attribute hashes and Ruby scripts designated by = or ~ should 
       * be evaluated. If this is true, said scripts are rendered as empty strings. 
       * Defaults to false.
       */
      'suppress_eval' => false,

      /* The character that should wrap element attributes. This defaults to ' (an
      * apostrophe). Characters of this type within the attributes will be escaped 
      * (e.g. by replacing them with &apos;) if the character is an apostrophe or a 
      * quotation mark.
       */
      'attr_wrapper' => "'",

       /* The line offset of the Haml template being parsed. This is useful for inline 
       * templates, similar to the last argument to Kernel#eval.
       */
      'line' => '<no line>', // not implemented yet

      /* A list of tag names that should be automatically self-closed if they have no 
      * content. This can also contain regular expressions that match tag names (or 
      * any object which responds to #===). Defaults to ['meta', 'img', 'link', 'br',
      * 'hr', 'input', 'area', 'param', 'col', 'base']. // not implemented yet
       */
      'autoclose' => array('meta','img','link','br','hr','input','area','param','col','base'),

      /* A list of tag names that should automatically have their newlines preserved 
      * using the Haml::Helpers#preserve helper. This means that any content given on 
      * the same line as the tag will be preserved. For example, %textarea= 
      * "Foo\nBar" compiles to <textarea>Foo&#x000A;Bar</textarea>. Defaults to 
      * ['textarea', 'pre']. See also Whitespace Preservation. // not implemented yet
       */
      'preserve' => array('textarea', 'pre'), // not implemented yet

      /* The encoding to use for the HTML output. Only available in Ruby 1.9 or 
      * higher. This can be a string or an Encoding Object. Note that Haml does not 
      * automatically re-encode Ruby values; any strings coming from outside the 
      * application should be converted before being passed into the Haml template. 
      * Defaults to Encoding.default_internal; if that’s not set, defaults to the 
      * encoding of the Haml template; if that’s us-ascii, defaults to "utf-8". 
      * Many Ruby database drivers are not yet Ruby 1.9 compatible; in 
      *
      * particular, they return strings marked as ASCII-encoded even when 
      * those strings contain non-ASCII characters (such as UTF-8). This 
      * will cause encoding errors if the Haml encoding isn’t set to 
      * "ascii-8bit". To solve this, either call #force_encoding on all the 
      * strings returned from the database, set :encoding to "ascii-8bit", 
      * or try to get the authors of the database drivers to make them Ruby 
      * 1.9 compatible.
       */
      'encoding' => "utf-8", // not implemented yet

	  'filters' => array(
		'plain' => 'HamlUtilities::plain',
		'javascript' => 'HamlUtilities::javascript',
		'css' => 'HamlUtilities::css',
		'cdata' => 'HamlUtilities::cdata',
		'escaped' => 'HamlUtilities::escaped',
		'php' => 'HamlUtilities::php',
		'preserve' => 'HamlUtilities::preserve',
		// ...
	  ),

      /* HAML-TO-PHP specific: When enabled and when using XHTML tag order will be checked
       * causing <tr><table> to fail */
      'check_tag_order' => true
    ); // }}}2


    public function hamlToPHP($str, $filename, $func_name = null){
      return HamlUtilities::hamlToPHPStr($str, $this->options, $filename, $func_name);
    }

}

/* simple implementation caching the php code in files
 * usage:
 * $haml = new HamlFileCache('haml','haml-cache');
 * $haml->options['ugly'] = false;
 * $haml->options['filters']['code'] = 'haml_filter_code';
 * echo $haml->haml('main.haml',array('title' => 'my site'));
 */
class HamlFileCache extends Haml {
  protected $cacheDir = null;
  protected $hamlSourceDir = null;
  public $forceUpdate = false;

  public function haml($relativeHamlFile /*, .. */){
	  $args = func_get_args();
	  $h = $this->hamlSourceDir.'/'.$relativeHamlFile;
	  $c = $this->cacheDir.'/'.preg_replace('/[\/\\\\]/',' ',$relativeHamlFile).'.php';
	  if ($this->forceUpdate 
		  || (
			!file_exists($c)
			|| filemtime($c) < filemtime($h))){
			file_put_contents($c, $this->hamlToPHP(file_get_contents($h), $h));
	  }
	  $args[0] = $c; // first arg is file
	  return call_user_func_array('HamlUtilities::runTemplate', $args);
  }

  function __construct($hamlSourceDir, $cacheDir){
	$this->hamlSourceDir = $hamlSourceDir;
	$this->cacheDir = $cacheDir;
  }
}
// vim: fdm=marker
