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
 * 3) create PHP code out of items (func treeToPHP)
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

class HamlParser {
  public $s; // the haml file contents as string
  public $o; // file offset

  // returns array($line, $col)
  public function pos(){
    $lines = explode("\n",substr($this->s, $this->o));
    $len = count($lines);
    return array($len, strlen($lines[$len-1]));
  }

  protected function error($msg){
    list($l,$c) = $this->pos();
    throw new HamlParseException($this->options['filename'].":$l:$c: error parsing haml: $msg");
  }

  // increases offset on if str matches at offset
  protected function str($s){
    if (substr($this->s, $this->o, $l = strlen($s)) == $s){
      $this->o += $l;
      return true;
    }
    return false;
  }

  // increases offset on if reg matches at offset
  protected function reg($reg, &$m){
    $reg = str_replace('\s',' \t', $reg);
    if (preg_match('/(?m)'.$reg.'/', $this->s, $m, 0, $this->o)){
      return $this->str($m[0]); // force matching at ^
    }
    return false;
  }

  // combinators {{{2
  // a parser is a method name and a list of arguments
  // eg array('sequence'[, .. the args]);
  // all p* parser reset offset ->o pointer if they fail

  // create bad result
  protected function pFail($msg){
    return array('msg' => $msg, 'o' => $this->o);
  }

  // cerate ok result
  protected function pOk($r){
    return array('r' =>  $r);
  }

  // is result ok?
  protected function rOk($r){
    return isset($r['r']);
  }

  //
  protected function p2(&$r){
    $args = func_get_args();
    array_shift($args);
    $r = $this->p($args);
    return ($this->rOk($r));
  }
  protected function p($p){
    $name = array_shift($p);
    return call_user_func_array(array(&$this, $name), $p);
  }
  // if parser succeds apply function to result
  protected function pApply($eval_, $p){
    $r = $this->p($p);
    if ($this->rOk($r)){
      $R = $r['r'];
      eval($eval_);
      $r['r'] = $R;
    }
    return $r;
  }

  // if parser fails cause error
  protected function pError($msg, $p){
    $r = $this->p($p);
    if ($this->rOk($r))
      return $r['r'];
    else $this->pFail($msg);
  }

  // first arg map, following args parsers
  // if map is numeric that index is returned
  // if its a string its evaled
  protected function pSequence(){
    $o = $this->o;
    $args = func_get_args();
    $R = array();
    $map = array_shift($args);
    foreach ($args as $p) {
      $r = $this->p($p);
      if ($this->rOk($r))
        $R[] = $r['r'];
      else {
        $this->o = $o;
        return $r;
      }
    }
    if (is_string($map)){
      eval($map);
    }elseif (is_numeric($map))
      $R = $R[$map];
    return $this->pOk($R);
  }

  protected function pChoice(){
    $o = $this->o;
    $args = func_get_args();
    $bad = array();
    foreach ($args as $p) {
      $r = $this->p($p);
      if ($this->rOk($r))
        return $r;
      else {
        $this->o = $o;
        $bad[] = $r;
      }
    }
    return $this->pFail($bad);
  }

  protected function pStr($s){
    $o = $this->o;
    if ($this->str($s))
      return $this->pOk($s);
    else {
      $this->o = $o;
      return $this->pFail("expected : $s");
    }
  }

  protected function pReg($reg){
    $o = $this->o;
    if ($this->reg($reg, $m))
      return $this->pOk(isset($m[1]) ? $m[1] : true);
    else {
      $this->o = $o;
      return $this->pFail("expected regex : $reg");
    }
  }

  // 0 or more
  protected function pMany($extra, $p){
    $R = array();
    while (true){
      $r = $this->p($p);
      if ($this->rOk($r))
        $R[] = $r['r'];
      else {
        if (is_string($extra)){
          eval($extra);
        }
        return $this->pOk($R);
      }
    }
  }

  // 1 or more
  protected function pMany1($extra, $p){
    $R = array();
    $r = $this->p($p);
    if ($this->rOk($r))
      $R[] = $r['r'];
    else
      return $r;

    while (true){
      $r = $this->p($p);
      if ($this->rOk($r)){
        $R[] = $r['r'];
      } else {
        if (is_string($extra)){
          eval($extra);
        }
        return $this->pOk($R);
      }
    }
  }

  protected function pSepBy($sep, $item){
    $o = $this->o;
    $items = array();
    $i = $this->p($item);
    if ($this->rOk($i)){
      $items[] = $i['r'];
      while (true){
        $i = $this->p($sep);
        if (!$this->rOk($i))
          return $this->pOk($items);
        else {
          $i = $this->p($item);
          if ($this->rOk($i))
            $items[] = $i['r'];
          else
            return $i;
        }
      }
    }
  }

  public function selfTest(){
    $this->s = "12  \t\tABC\n";
    $this->len = strlen($this->s);
    $this->o = 0;

    // test text matches first char
    assert($this->str('1'));
    // test that offset works:
    assert($this->str('2'));

    // test spaces
    assert($this->reg('[\s]',$m));
    // test capturing
    assert($this->reg('([\s])',$m));
    assert($m[1]== ' ');
    // test spaces tab
    assert($this->reg('([\s]*)',$m));
    assert($m[1]== "\t\t");

    // test match at beginning only
    // ABC would match, BC should not
    assert(!$this->reg("BC",$m));
    assert($this->reg("([^\n]*)\n",$m));
    assert($m[1]=="ABC");

    // p* tools:
    $this->s = "12[1,2]";
    $this->o = 0;
    $this->len = strlen($this->s);
    $pChoice = array('pChoice', array('pStr','1'),array('pStr','2'));
    assert(array('r' => '1') === $this->p($pChoice)); 

    $this->o = 0;
    $r = $this->pSequence(null, array('pStr','1'),array('pStr','2'));
    assert(array('r' => array('1','2')) === $r);
    $r2 = $this->pSequence(null, array('pStr','['),array('pSepBy', array('pStr',','), $pChoice), array('pStr', ']'));
    assert(array('r' => array('[', array('1','2'), ']')) === $r2);
  }

}

// HamlTree parses a string to a tree. Using "typed" arrays because they are /*{{{*/
// faster than classes (and objects?).
// tags have the keys: type = 'tag', ind, id, classes, attributes, childs
// attributes arre a list of array(key => value) where value is a list of 
// arrays denoting PHP code or text
// text have the keys: text = the content
// minimal testing code for text and regex matching in hamlInternalTest
//
// Because we match only against non utf-8 chars this should be almost utf-8 compatible
// (except that it does only match latin spaces..)
//
// Parser was not written for speed. Eg the indentation may be evaluated 
// multiple times. All the function calls take time as well. In the end you'll 
// be using file or sqlite caches or such anyway ..
// Minimizing code to maintain is more important to me.
//
// \s has been introduced to reduce duplication. Its replaced by ' \t' before 
// running a regex/*}}}*/
class HamlTree extends HamlParser {
  public $ind = null; // indentation string used in haml file
  public $last_ind = 0; // indentation last line
  public $name; // name of source

  public $doctype;
  public $tree = null;
  public $list = array(); // filled by step 3)
  public $options;

  public $childs; // the parsed representation.

  function __construct($s, $options, $parse = true){
    // {{{2
    $options_defaults = array(
      /*
      Determines the output format. Normally the default is :xhtml, although 
      under Rails 3 it’s :html5, since that’s the Rails 3’s default format. 
      Other options are :html4 and :html5, which are identical to :xhtml 
      except there are no self-closing tags, the XML prolog is ignored and 
      correct DOCTYPEs are generated.  :escape_html
       */
      'format' => 'xhtml', // not implemented yet
      /*
      Sets whether or not to escape HTML-sensitive characters in script. If 
      this is true, = behaves like &=; otherwise, it behaves like !=. Note 
      that if this is set, != should be used for yielding to subtemplates and 
      rendering partials. See also Escaping HTML and Unescaping HTML Defaults 
      to false.
       */
      'escape_html' => false, // not implemented yet

      /*
      If set to true, Haml makes no attempt to properly indent or format the HTML 
      output. This significantly improves rendering performance but makes viewing the 
      source unpleasant. Defaults to true in Rails production mode, and false 
      everywhere else.
       */
      'ugly' => true, // not implemented yet

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

      /* The name of the Haml file being parsed. This is only used as information 
      * when exceptions are raised. This is automatically assigned when working 
      * through ActionView, so it’s really only useful for the user to assign when 
      * dealing with Haml programatically.
       */
      'filename' => '<no file>',

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
    ); // }}}2

    $options_defaults['filters'] = array(
      'plain' => 'Filters::plain',
      'javascript' => 'Filters::javascript',
      'css' => 'Filters::css',
      'cdata' => 'Filters::cdata',
      'escaped' => 'Filters::escaped',
      'php' => 'Filters::php',
      'preserve' => 'Filters::preserve',
      // ...
    );



    $this->idItem = serialize(self::toNameItem('id'));
    $this->classItem = serialize(self::toNameItem('class'));

    $this->options = array_merge($options_defaults, $options);
    $this->s = $s."\n"; // \n so that it gets parsed
    $this->len = strlen($this->s);
    $this->o = 0;
    if (preg_match('/(?m)\n([ \t]+)/',$s,$m))
      $this->ind = $m[1];
    else
      $this->ind = '  '; // prevent childs from being parsed

    // any number of spaces, returns \n
    $this->pEmptyLine = array('pApply', '$R = array(array("text" => "\n"));', array('pReg','[\s]*\n'));
    // parses rest of line (after indentation) and \n
    $this->pTextContentLine = array('pSequence', '$R = $R[0]; $R[] = array("text" => "\n");', $this->interpolatedString("\n"), array('pStr', "\n"));

    if ($parse)
      $this->parseHAML();

  }

  static public function array_merge($list){
    if (count($list) == 0)
      return array();
    elseif (count($list) == 1)
      return $list[0];
    $r = call_user_func_array('array_merge', $list);
    return $r;
  }

  static public function toNameItem($s){
    return array(array("text" => $s));
  }

  // render items {{{2
  protected function rText($s, $quoted){
    if ($quoted)
      $s = htmlentities($s, ENT_QUOTES, $this->options['encoding']);
    $this->list[] = array('text' => $s);
  }

  protected function rEchoPHP($php, $quoted){
    if ($quoted)
      $php = "htmlentities(".$php.", ENT_QUOTES, ".var_export($this->options['encoding'],true).")";
    $this->list[] = array('phpecho' => $php);
  }

  protected function rPHP($php, $hasChilds = false){
    if ($hasChilds)
      $php .= '{';
    $this->list[] = array('php' => $php);
  }

  // preparing rendering {{{2
  // Also see treeToPHP

  function doctype(){/*{{{*/
    // TODO
    return '';
    /* TODO convert this ruby code somehow and find out how it is calledin Ruby HAML
      $text = strtolower(substr($doctype,3,strlen($doctype)-1));
      if text.index("xml") == 0
        return '';
        return nil if html?
        wrapper = @options[:attr_wrapper]
        return "<?xml version=#{wrapper}1.0#{wrapper} encoding=#{wrapper}#{text.split(' ')[1] || "utf-8"}#{wrapper} ?>"
      end

      if html5?
        '<!DOCTYPE html>'
      else
        version, type = text.scan(DOCTYPE_REGEX)[0]

        if xhtml?
          if version == "1.1"
            '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">'
          elsif version == "5"
            '<!DOCTYPE html>'
          else
            case type
            when "strict";   '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">'
            when "frameset"; '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Frameset//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-frameset.dtd">'
            when "mobile";   '<!DOCTYPE html PUBLIC "-//WAPFORUM//DTD XHTML Mobile 1.2//EN" "http://www.openmobilealliance.org/tech/DTD/xhtml-mobile12.dtd">'
            when "rdfa";     '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML+RDFa 1.0//EN" "http://www.w3.org/MarkUp/DTD/xhtml-rdfa-1.dtd">'
            when "basic";    '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML Basic 1.1//EN" "http://www.w3.org/TR/xhtml-basic/xhtml-basic11.dtd">'
            else             '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            end
          end

        elsif html4?
          case type
          when "strict";   '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">'
          when "frameset"; '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">'
          else             '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">'
          end
        end
      end
     */
  }/*}}}*/

  public function flatten(){
    $this->flattenChilds($this->childs);
  }

  protected function flattenChilds($childs){
    foreach ($childs as $c) {
      $this->flattenThing($c);
    }
  }

  protected function d($ar, $key, $default){
    return isset($ar[$key]) ? $ar[$key] : $default;
  }

  protected function rItems($l, $quote){
    foreach ($l as $v) {
      if (isset($v['text']))
        $this->rText($v['text'], $quote);
      elseif (isset($v['phpvalue']))
        $this->rEchoPHP($v['phpvalue'], $quote);
      else throw new Exception('bad item '.var_export($v,true));
    }
  }

  protected function flattenThing(array $thing){
    $html = substr($this->options['format'], 0, 4) == 'html';
    $q = $this->options['attr_wrapper'];
    if (isset($thing['type'])){
      switch ($thing['type']) {
        case 'text':
          $this->rItems($thing['items'], true);
          break;
        case 'conditional-comment':
          $this->rText('<!--['.$thing['condition'].']>', false);
          if (isset($thing['childs']))
            foreach ($thing['childs'] as $v) {
              $this->flattenThing($v);
            }
          $this->rText('<![endif]-->',false);
          break;
        case 'filter':
          if (!isset($this->options['filters'][$thing['filter']]))
            $this->error('bad filter: '.$thing['filter']); // TODO location?
          $text = array();
          foreach ($thing['items'] as $i) {
            if (isset($i['phpvalue']))
              $text[] = $i['phpvalue'];
            elseif (isset($i['text']))
              $text[] = var_export($i['text'],true);
            else assert(false);
          }
            /// var_export($thing['text'], true)
          $this->rEchoPHP($this->options['filters'][$thing['filter']].'('.var_export($this->options['encoding'],true).','.implode('.',$text).')', false);
          break;
        case 'inline-comment':
          $this->rText('<!--', false);
          $this->rItems($thing['items'], false);
          $this->rText('-->', false);
          break;
        case 'silent-comment':
          break;
        case 'tag':
          // TODO optimize
          $tag_name = $thing['name'];
          $autoclose = in_array($tag_name, $this->options['autoclose']);
          $childs = $this->d($thing,'childs',array());
          if ($autoclose && count($childs) > 0 && ($childs !== array ( array ( 'type' => 'text', 'items' => array ( array ( 'text' => "\n"))))) )
            $this->error('tag "'.$tag_name.'" found which should autoclose but has children !'.var_export($childs,true));

          // tag open and name
          // TODO: add indentation here for pretty rendering?
          $this->rText("<$tag_name", false);
          // attributes
          # classes are sorted. dups are removed.
          $classes = $this->d($thing, 'classes', array());
          $class = array();
          foreach ($classes as $c){
            $class[] =self::toNameItem($c);
          }
          $id = isset($thing['id'])
            ? array(self::toNameItem($thing['id']))
            : array();

          $collect = array(
            $this->idItem => 'id',
            $this->classItem => 'class'
          );
          $attrs = array();
          foreach ($this->d($thing, 'attrs', array()) as $a) {
            foreach ($a as $attr => $value) {
              if (isset($collect[$attr])) {
                ${$collect[$attr]} = array_merge(${$collect[$attr]}, $value);
              } else {
                $attrs[$attr] = $value;
              }
            }
          }
          // render classes (dynamic because haml removes duplicates. The duplicates are known at runtime)
          array_unique($class);
          if (count($class) > 0){
            $this->rText(" class=$q", false);
            $class_items = array();
            $items = array();
            foreach ($class as $class_item) {
              $item_builder = array();
              foreach ($class_item as $cii) {
                if (isset($cii['text']))
                  $item_builder[] = var_export($cii['text'],true);
                elseif (isset($cii['phpvalue'])){
                  $item_builder[] = $cii['phpvalue'];
                }
              }
              $items[] = implode('.',$item_builder);
            }
            $this->rEchoPHP('Haml::renderClassItems(array('.implode(',',$items).'))', true);
            $this->rText("$q", false);
          }
          // render id
          if (count($id) > 0){
            $this->rText(" id=$q", false);
            $sep = '';
            foreach ($id as $id_item) {
              if ($sep != '') $this->rText($sep, true);
              $this->rItems($id_item, true);
              $sep = "_";
            }
            $this->rText("$q", false);
          }

          // render remaining attrs
          foreach ($attrs as $key => $v) {
            $this->rText(" ",false);
            $this->rItems(unserialize($key), false);
            $this->rText("=$q", false);
            $this->rItems($v, true);
            $this->rText("$q", false);
          }

          if ($autoclose){
            $this->rText("".($html ? '' : ' /').">", false);
          } else {
            $this->rText('>', false);
            foreach ($childs as $v) {
              $this->flattenThing($v);
            }
            $this->rText("</$tag_name>", false);
          }
          break;
        case 'block':
          $hasC = count($thing['childs']) > 0;
          $this->rPHP($thing['php'], $hasC);
          foreach ($thing['childs'] as $c) {
            $this->flattenThing($c);
          }
          if ($hasC)
            $this->rPHP('}');
          break;
        default:
          throw new Exception('missing implementation');
      }
    } elseif (isset($thing['phpecho'])){
      $this->rEchoPHP($thing['phpecho'], $thing['escape_html']);
    } elseif (isset($thing['text'])){
      $this->rText($thing['text'], true);
    } else
      throw new Exception('implement: '.var_export($thing,true));
  }

  // }}}

  // parsing: {{{2

  protected function parseHAML(){
    // parse optional !!! doctype
    if ($this->p2($r, 'pReg', '!!![\s]*([^\n]*)\n')){
      $this->doctype = $r['r'];
    }else
      $this->doctype = null;
   
    $this->childs = $this->pError('failed parsing haml', array('pChilds',0,''));
    if ($this->o < $this->len-1)
        $this->error('parsing stopped. 10 of the first remaining chars:'.var_export(substr($this->s,$this->o,10),true));
  }

  protected function eof(){
    return $this->o >= $this->len;
  }

  // always returns array of children which may be empty
  // if no children are found. No children are found if indentation decreases
  protected function pChilds($expected_ind, $ind_str){
    return $this->pMany(
        null
      , array('pChoice'
              , array('pConditionalComment', $expected_ind, $ind_str) # /[IE] ..
              , array('pInlineComment', $expected_ind, $ind_str)      # /
              , array('pSilentComment', $expected_ind, $ind_str)      # -#
              , array('pFilter', $expected_ind, $ind_str)             # :
              , array('pPHP', $expected_ind, $ind_str)
              , array('pTag', $expected_ind, $ind_str)
              , array('pBlock', $expected_ind, $ind_str)
              , array('pText', $expected_ind, $ind_str)
          )
    );
  }

  // rest of line starting by = != &=
  protected function pPHPAssignment(){
    return $this->p(array('pSequence'
        , '
        $R = array( "phpecho" => $R[1],
                    "escape_html" => ($this->options["escape_html"] && $R[0] != "!=")
                                    || ($R[0] == "&=")
                  );
        '
        , array('pReg','(=|!=|[&]=)')
        , array('pArbitraryPHPCode',true)
        , array('pStr',"\n")
      ));
  }

  protected function pPHP($expected_ind, $ind_str){
    return $this->p(array('pSequence',1
      , array('pStr',$ind_str)
      , array('pPHPAssignment')));
  }

  protected function pStringNoInterpolation($stopat){
    $o = $this->o;
    $s = '';
    while ($this->o < $this->len){
      $c = $this->s[$this->o];
      if (strpos($stopat, $c)!== false){
        break;
      } elseif ($c == '\\'){
        $c2 = $this->s[$this->o+1];
        if ($c2 == '#' || $c2 == '\\'){
          $s .= $c2;
          $this->o++;
        }
      } elseif ($c == '#'){
        break;
      } else {
        $s .= $c;
      }
      $this->o++;
    }
    if ($s !== '')
      return $this->pOk(array('text' => $s));
    else {
      $this->o = $o;
      return $this->pFail('str expected');
    }
  }

  // parser #{...}
  protected function pInterpolation(){
    $o = $this->o;
    if ($this->str('#{')){
      if (!$this->p2($code, 'pArbitraryPHPCode',true)){
        $this->o = $o; return $code;
      }
      if (!$this->p2($r, 'pStr', '}')){
        $this->o = $o; return $r;
      }
      return $this->pOk(array('phpvalue' => $code['r']));
    } else return $this->pFail('#{ expected');
  }

  # parse text maybe containing #{} till $stopA 
  protected function interpolatedString($stopAt){
    return array('pMany1', null
                , array('pChoice'
                    , array('pInterpolation')
                    , array('pStringNoInterpolation', $stopAt)));
  }

  protected function textLine($ind_str){
    return array('pChoice'
        , $this->pEmptyLine
        , array('pSequence', 1, array('pStr', $ind_str), $this->pTextContentLine));
  }

  protected function pFilter($expectedIndent, $ind_str){
      return $this->pSequence(
                  '$R = array("type" => "filter", "filter" => $R[0], "items" => $R[1]);'
                 // name
                 , array('pReg',$ind_str.':([^\n\s]+)\n') /* name */
                 // text
                 , array('pMany', '$R = HamlTree::array_merge($R);', $this->textLine($ind_str.$this->ind))
      );
  }


  protected function pConditionalComment($expectedIndent, $ind_str){
    return $this->pSequence(
      '$R = array("type" => "conditional-comment", "condition" => $R[0], "childs" => $R[1]);'
      , array('pReg', $ind_str.'\/\[([^\]]+)\][\s]*\n')
      , array('pChilds', $expectedIndent + 1, $ind_str.$this->ind));
  }

  protected function pInlineComment($expectedIndent, $ind_str){
    return $this->pApply(
        '$R = array("type" => "inline-comment", "items" => $R);'
        , array('pChoice'
          // one line
          , array('pSequence', 1, array('pStr', $ind_str.'/'), $this->pTextContentLine)
          // multiple lines
          , array('pSequence'
              , 1
              , array('pReg', $ind_str.'\/[\s]*'."\n")
              , array('pMany', '$R = HamlTree::array_merge($R);'
                      , $this->textLine($ind_str.$this->ind)))
       ));
  }

  protected function pSilentComment($expectedIndent, $ind_str){
    return $this->pApply(
      '$R = array("type" => "silent-comment");'
      , array('pSequence'
        , null
        , array('pReg', $ind_str.'-#[^\n]*\n')
        , array('pMany', null, array('pReg',$ind_str.$this->ind.'[^\n]*\n'))
      ));
  }

  protected function pBlock($expectedIndent, $ind_str){
    return $this->pSequence(
      '$R = array("type" => "block", "php" => $R[0], "childs" => $R[1]);'
      , array('pReg', $ind_str.'-([^\n]*)\n')
      , array('pChilds', $expectedIndent + 1, $ind_str.$this->ind)
      );
  }

  // returns tag array('type' => tag, 'name' => tag, 'attributes' => array, childs => array) or null
  protected function pTag($expectedIndent, $ind_str){
    if (!$this->str($ind_str) || $this->eof())
      return $this->pFail('other indentation expected');
    // HAML has wired properties "haml" : "%p#id(id='1')" -> "html" : "<p id='id_1'></p>"
    // thus store css values separately and merge them the HAML way when PHP is generated 
    
    $o = $this->o;
    # optional tag name defaulting to div (eg #table)
    $tag = array('type' => 'tag', 'classes' => array(), 'ind' => $ind_str);
    if ($this->reg('%([^!\s.=#\n({]+)',$m)){
      $tag['name'] = $m[1];
    } else $tag['name'] = 'div';

    # parse .foo and #bar CSS properties
    while ($this->reg('([#.])([^\s.#({\n]+)', $m)){
      if ($m[1] === '#'){
        # last overrides previous:
        $tag['id'] = $m[2];
      }elseif ($m[1] === '.'){
        // classes are all stored and will be separated by spaces
        $tag['classes'][] = $m[2];
      }else {
        throw new Exception('unexpected');
      }
    }
    if ($this->o == $o){ // nothing consumed
      // neither CSS style and default div. This is not a tag line
      $this->o = $o;
      return $this->pFail('tag expected');
    }
    unset($in_loop);

    $tag['attrs'] = array();
    # parse properties
    $max = 2;
    while ($max-- > 0 && $this->reg('([({])', $m)){
      $attrParsers = array(
            '(' => array('pAttrs',"html"),
            '{' => array('pAttrs',"ruby")
      );
      $tag['attrs'][] = $this->pError('error parsing attrs',$attrParsers[$m[1]]);
    }

    $endl = array('pReg',"[\\s]*\n");
    if ($this->p2($r, 'pChoice'
      // &= != =
      , array('pApply','$R = array($R);', array('pPHPAssignment'))
      // childs
      , array('pSequence',1,$endl, array('pChilds', $expectedIndent +1, $ind_str.$this->ind))
      // html text
      , array('pApply','
      $R=array(array("type" => "text", "items" => $R));
    ', $this->pTextContentLine)
    )){
    $tag['childs'] = $r['r'];
    return $this->pOk($tag);
  } else {
    $this->o = $o;
    return $r;
  }
  }

  protected function pAttrs($type){
    $o = $this->o;
    $sepsByType = array(
      'ruby' => array('pReg','[\s]*,[\s\n]*'),
      'html' =>  array('pReg','[\s\n]+')
    );
    $endByType = array(
      'ruby' => '[\s]*}',
      'html' =>  '[\s]*\)'
    );
    if (!$this->p2($r, 'pSepBy', $sepsByType[$type], array('pAttr', $type))){
      return $r;
    }
    $attributes = $r['r'];
    if (!$this->p2($r, 'pReg', $endByType[$type])){
      $this->o = $o; return $r;
    }
    // merge key = value pairs keeping the last occurrence only
    $r = array();
    foreach ($attributes as $key_value_pair) {
      foreach ($key_value_pair as $key => $value) {
        $r[$key] = $value;
      }
    }
    return $this->pOk($r);
  }

  protected function pAttr($type){
    $nameByType = array(
      'html' => array('pApply', '$R = HamlTree::toNameItem($R);', array('pReg','[\s]*([^=\n]+)')),
      'ruby' => array('pChoice'
                      ,array('pApply', '$R = HamlTree::toNameItem($R);', array('pReg','[\s]*\:([\w]+)'))
                      ,array('pAttrValueQuot')
                    )
    );
    $sepByType = array(
      'html' => array('pStr','='),
      'ruby' => array('pReg','[\s]*=>[\s]*')
    );
    $r = $this->p($nameByType[$type]);
    if (!$this->rOk($r))
      $this->error($r['msg']);
    $name = serialize($r['r']); // serialize info as string
    $this->pError('= or => expected depending on attr type', $sepByType[$type]);
    return $this->pOk(array($name => $this->pError('value maybe list', array('pAttrValue', $type, $name))));
  }

  protected function pAttrValue($type, $name){
    $spaceOkByType = array(
      'html' => false,
      'ruby' => true,
    );

    # parse php code or "..#{}.."
    $pAttrValue = array('pChoice'
     , array('pAttrValueQuot')
     , array('pApply','$R =  array(array("phpvalue" => $R));',array('pArbitraryPHPCode'))
     );

    if (in_array($name, array($this->idItem,$this->classItem))){
      # may be a list.

      $r = $this->pChoice(
          array('pApply', '$R = array($R);', $pAttrValue)
        , array('pSequence'
                , 1
                , array('pReg','\[[\s]*')
                , array('pSepBy'
                  , array('pReg',',[\s\n]*')
                  , $pAttrValue)
                , array('pReg','[\s]*\]'),
          )
      );
    
    } else {
      $r = $this->p($pAttrValue);
    }
    return $r;
  }

  //  "...#{} .. #{}.."
  //  returns list of array('text' => ..) array('phpecho' =>  .. )
  protected function pAttrValueQuot(){
    $o = $this->o;
    $items = array();
    $s = '';
    if (!$this->p2($r,'pReg','(["\'])')){
      $this->o = $o; return $r;
    }
    $quotStyle = $r['r'];
     
    while (true){
      if ($this->eof()){
        $this->o = $o; return $this->pFail('no eof expected');
      }
      if ($this->str('#{')){
        if ($s !== '')
          $items[] = array('text' => $s);
        $s = '';
        if (!$this->p2($code, 'pArbitraryPHPCode',true)){
          $this->o = $o; return $r;
        }
        if (!$this->p2($r, 'pStr', '}')){
          $this->o = $o; return $r;
        }
        $items[] = array('phpvalue' => $code['r']);
      } elseif ($this->s[$this->o] == '\\'){
        $this->o++;
        $s .= $this->s[$this->o++];
      } elseif ($this->s[$this->o] == $quotStyle){
        $this->o++;
        break;
      } else {
        $s .= $this->s[$this->o++];
      }
    }
    if ($s !== '')
      $items[] = array('text' => $s);

    return $this->pOk($items);
  }

  protected function pArbitraryPHPCode($spacesOk = true){
    $s = $spacesOk ? '' : '\s';
    $o = $this->o;
    static $regex;
    if (is_null($regex)){
      // (?R) matches to most outer regexp recursively
      // That's something Ruby can't do (yet?)
      // You can't just parse until you hit a ","
      // because contents can be  %tag = substr(2,4,8)
      // so the substr will be matched by "anything else", the (...) will be matched by the ", separated func args"
      
      // keys are documentation only
      $items = array(
        '" str' => '("[^"\\\\]+|\\\\.)*"',
        "' str" => '(\'[^\'\\\\]+|\\\\.)*\'',
        ', separated func args' =>  '\(((?R)(,(?R))*)\)',
        'recursion in ()' => '\((?R)\)', // this catches nested ( 2 + (4 + ) ) ..
        // '{(?R)}
        ' anything else but terminal' => "[^(){},\n$s]+"
      );
      $regex ='('. implode('|',$items).')+';
    }
    $this->reg($regex, $m);
    $s = substr($this->s, $o, $this->o - $o);
    if (strlen($s) > 0)
      return $this->pOk($s);
    else {
      $this->o = $o; return $this->pFail("no arbitrary code");
    }
  }

  protected function pText($expectedIndent, $ind_str){
    return $this->pMany1(
      '$R = array("type" => "text", "items" => HamlTree::array_merge($R));'
      , $this->textLine($ind_str));
  }

  // }}}
}


// the user interface to the parser:
class Haml {

  static public function funcRenderer($list, $func_name){

    $code = '';
    // this can be optimized probably
    foreach ($list as $l) {
      if (isset($l['phpecho'])){
        $code .= "\$html .= ".$l['phpecho'].";\n";
      } elseif (isset($l['php'])){
        $code .= $l['php'].";\n";
      } elseif (isset($l['text'])) {
        $code .= '$html .= '.var_export($l['text'],true).";\n";
      } elseif (isset($l['verbatim'])){
        $code .= '$html .= '.var_export($l['text'],true).";\n";
      } else assert(false);
    }

    return "
      function $func_name(){
        \$args = func_get_args();
        // put vars in scope:
        foreach (\$args as \$arr) { extract(\$arr); }
        \$html = '';
        $code
        return \$html;
      }
    ";
  }

  static public function phpRenderer($list){
    // its your task to put stuff in scope before evaluating this code..
    $code = '';
    // this can be optimized probably
    foreach ($list as $l) {
      if (isset($l['phpecho'])){
        $code .= '<?php='.$l['phpecho'].")?>";
      } elseif (isset($l['php'])){
        $code .= '<?php '.$l['php']."?>";
      } elseif (isset($l['text'])) {
        $code .= var_export($l['text'],true)."\n";
      } elseif (isset($l['verbatim'])){
        $code .= var_export($l['text'],true)."\n";
      } else assert(false);
    }
    return $code;
  }

  // $hamlTree: parsed haml representation
  // $as_func: pass a function name to return
  // function $func_name(...){
  //   return 'the htmlp code';
  // }
  // 
  // Why a function? if there is an exceptoin for any reason your nice
  // ob_start(); ... nesting will get out of sync?
  // functions prevent this issue.
  static public function treeToPHP(HamlTree $hamlTree, $func_name = null){
    // list of items which will make up the code. An item is one of
    // array('text' => 'HTML code')
    // array('php' => 'php code'[, 'escape_html' => true /false] )
    $hamlTree->doctype();
    $hamlTree->flatten();
    if (is_null($func_name))
      return self::phpRenderer($hamlTree->list);
    else
      return self::funcRenderer($hamlTree->list, $func_name);
  }

  static public function hamlToPHPStr($str, $options = array(), $func_name = null){
    return self::treeToPHP(new HamlTree($str, $options), $func_name);
  }

  // minimal test of the parser
  static public function hamlInternalTest(){
    $p = new HamlTree("", array(), false);
    $p->selfTest();
  }

  static public function renderClassItems($items){
    $no_dups = array_unique($items);
    sort($no_dups);
    return implode(' ',$no_dups);
  }
}
// vim: fdm=marker
