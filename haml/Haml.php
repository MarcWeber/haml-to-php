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

class HamlParser {
  public $s; // the haml file contents as string
  public $o; // file offset

  // returns array($line, $col)
  public function pos(){
    $lines = explode("\n",substr($this->s, $o));
    $len = count($lines);
    return array($len, strlen($lines[$len-1]));
  }

  protected function error($msg){
    list($l,$c) = $this->pos();
    throw new HamlParseException($this->name.":$l:$c: $error parsing haml: $msg");
  }

  // increases offset on if str matches at offset
  protected function str($s, $msg = null){
    if (substr($this->s, $this->o, $l = strlen($s)) == $s){
      $this->o += $l;
      return true;
    }
    if ($msg == null)
      return false;
    else $this->error($msg);
  }

  // increases offset on if reg matches at offset
  protected function reg($reg, &$m, $msg = null){
    $reg = str_replace('\s',' \t', $reg);
    if (preg_match('/(?m)'.$reg.'/', $this->s, $m, 0, $this->o)){
      return $this->str($m[0]); // force matching at ^
    }
    if ($msg == null)
      return false;
    else $this->error($msg);
  }

  // combinators {{{2
  // a parser is a method name and a list of arguments
  // eg array('sequence'[, .. the args]);
  // all p* parser reset ->o pointer if they fail

  // cerate bad result
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
    $r =& $this->p($args);
    return ($this->pOk($r));
  }
  protected function p($p){
    if ($p === 'nop')
      return $this->pOk(true);
    $name = array_shift($p);
    return call_user_func_array(array(&$this, $name), $p);
  }
  // if parser succeds apply function to result
  protected function pApply($f, $p){
    $r = $this->p($p);
    if ($this->rOk($r)){
      $r['ok'] = $f[$p['ok']];
    }
    return $r;
  }

  // if parser fails cause error
  protected function pError($msg, $p){
    $r = $this->p($p);
    if ($this->rOk($r))
      return $r['r'];
    else $this->pError($msg);
  }

  protected function pSequence(){
    $o = $this->o;
    $args = func_get_args();
    $results = array();
    foreach ($args as $p) {
      $r = $this->p($p);
      if ($this->rOk($r))
        $results[] = $r['r'];
      else {
        $this->o = $o;
        return $r;
      }
    }
    return $this->pOk($results);
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
        $bad[] = var_export($r, true);
      }
    }
    return $this->pFail('one of '.implode(',', $bad));
  }

  protected function pStr($s){
    $o = $this->o;
    if ($this->str($s))
      return array('r' => $s);
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
      return $this->pFail("expected regex : $s");
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
          return $items;
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
    $r = $this->pSequence(array('pStr','1'),array('pStr','2'));
    assert(array('r' => array('1','2')) === $r);
    $r2 = $this->pSequence(array('pStr','['),array('pSepBy', array('pStr',','), $pChoice), array('pStr', ']'));
    assert(array('r' => array(true, array(true,true), array(true))) === $r2);
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

  function __construct($s, $name, $options, $parse = true){
    // {{{2
    $options_defaults = array(
      /*
      Determines the output format. Normally the default is :xhtml, although 
      under Rails 3 it’s :html5, since that’s the Rails 3’s default format. 
      Other options are :html4 and :html5, which are identical to :xhtml 
      except there are no self-closing tags, the XML prolog is ignored and 
      correct DOCTYPEs are generated.  :escape_html
       */
      'format' => 'html', // not implemented yet
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
      'suppress_eval' => false, // not implemented yet

      /* The character that should wrap element attributes. This defaults to ' (an
      * apostrophe). Characters of this type within the attributes will be escaped 
      * (e.g. by replacing them with &apos;) if the character is an apostrophe or a 
      * quotation mark.
       */
      'attr_wrapper' => "'", // not implemented yet

      /* The name of the Haml file being parsed. This is only used as information 
      * when exceptions are raised. This is automatically assigned when working 
      * through ActionView, so it’s really only useful for the user to assign when 
      * dealing with Haml programatically.
       */
      'filename' => '<no file>', // not implemented yet

       /* The line offset of the Haml template being parsed. This is useful for inline 
       * templates, similar to the last argument to Kernel#eval.
       */
      'line' => '<no line>', // not implemented yet

      /* A list of tag names that should be automatically self-closed if they have no 
      * content. This can also contain regular expressions that match tag names (or 
      * any object which responds to #===). Defaults to ['meta', 'img', 'link', 'br',
      * 'hr', 'input', 'area', 'param', 'col', 'base']. // not implemented yet
       */
      'autoclose' => array('meta','img','link','br','hr','input','area','param','col','base'), // not implemented yet

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


    $this->opts = array_merge($options_defaults, $options);
    $this->s = $s;
    $this->len = strlen($s);
    $this->o = 0;
    $this->name = $name;
    if ($parse)
      $this->parseHAML();
  }

  // render items {{{2
  protected function rText($s, $quoted){
    if ($quoted)
      $s = htmlentities($s, $this->options['encoding']);
    $this->items[] = array('text' => $s);
  }

  protected function rEchoPHP($php, $quoted){
    if ($quoted)
    $php = "htmlentities(".$php.", ".var_export($this->options['encoding']).")";
    $this->items[] = array('phpecho' => $php);
  }

  protected function rPHP($php, $hasChilds){
    if ($hasChilds)
      $php .= '{';
    $this->items[] = array('php' => $php);
  }

  // preparing rendering {{{2
  // Also see treeToPHP

  function doctype(&$list){/*{{{*/
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
    $this->flattenChilds($list, $this->childs);
  }

  protected function flattenChilds( $childs){
    array_map(array($this,'flattenThing'), $childs);
  }

  protected function d($ar, $key, $default){
    return isset($ar[$key]) ? $ar[$key] : $default;
  }

  protected function flattenThing(array $thing){
    if (isset($thing['type'])){
      switch ($thing['type']) {
        case 'tag':
          // TODO optimize
          $n = $thing['name'];
          $autoclose = in_array($n, $this->options['autoclose']);
          $childs = $this->d($thing,'childs',array());
          if ($autoclose && count($childs) > 0)
            $this->error('tag found '.$n.' which should autoclose but has children!');

          // tag open and name
          $this->rTextUnquoted("<$n ");
          // attributes
          # classes are sorted. dups are removed.
          $classes = array_flip($this->d($thing, 'class', array()));
          $id = $this->d($thing,'id',array());
          if (count($id) > 1) # last id wins in #A#B
            $id = array_slice($id, 0, count($id) -1);
          $attrs = array();
          foreach ($this->d($thing, 'attrs', array()) as $v) {
          }

          if ($autoclose){
          
          } else {
          }
          break;
        case 'block':
          
        default:
          throw new Exception('missing implementation');
      }
    } elseif (isset($thing['phpecho'])){
      $list[] = $thing;
    } elseif (isset($thing['php'])){
      // if childs add { .. }
      $has_childs = isset($thing['childs']);
      $list[] = array('php' => $thing['php'].($has_childs ? '{' : ''));
      if ($has_childs){
        foreach ($list['childs'] as $c) $this->flattenThing($list, $c);
        $list[] = array('php' => '}');
      }
    } elseif (isset($thing['text'])){
      $list[] = $thing;
    }
  }

  // }}}

  // parsing: {{{2

  protected function parseHAML(){
    // parse optional !!! doctype
    if ($this->str('!!!')){
      $this->reg('[\s]*');
      $this->doctype = $this->reg('[^\s]', 'doctype expected', $m);
      $this->reg('\n', 'new line expeced');
    }
    $this->childs = $this->parseChilds(0,'');
  }

  protected function eof(){
    return $this->o >= $this->len;
  }

  protected function getIndent(){
    if (is_null($this->ind)){
      // first indent or still no indent
      if ($this->reg('([\s]+)',$m)){
        $this->ind = $m[1];
      }
      return 1;
    } else {
      $new_ind = 0;
      while ($this->str($this->ind)){
        $new_ind ++;
      }
      $this->new_ind = $new_ind;
      return $new_ind;
    }
  }

  protected function previewIndent(){
    $o = $this->o;
    $i = $this->getIndent();
    $o = $this->o;
    return $i;
  }

  // always returns array of children which may be empty
  // if no children was found. No children is found if indentation decreases
  protected function parseChilds($expected_ind, $ind_str){
    $childs = array();
    $last = null;

    $o = $this->o;
    $new_ind = $this->previewIndent();

    $this->o = $o;
    if ($new_ind == $expected_ind){
      // same indentation: no childs
      return array();
    }

    if ($new_ind > $expected_ind +1){
      $this->error('the line is indented '.($new_ind - $expected_ind).' levels too deep');
    }

    if ($new_ind = $expected_ind+1){
      $ind_N = $ind_str.$this->ind;
      // parse children
      while (!$this->eof()){
        if ($new_ind > $expected_ind){
          $o2 = $this->o;
          if (null !== $child = $this->parseTag($new_ind, $ind_N)){
            // tag parsed
          } elseif (null !== $block = $this->parseText($new_ind, $ind_N) ){
            // block parsed
            $childs[] = $block;
          } elseif (null !== $list = $this->parseText($new_ind, $ind_N) ){
            // text parsed
            $childs = array_merge($childs, $list);
          } else {
            $this->error('child (tag or text) expected');
          }
        }
      }
    }
    // else: indentation decreases. return empty array
    return $childs;
  }

  protected function parseBlock($expectedIndent, $ind_str){
    if (!$this->str($ind_str.'-')) return null;
    $this->reg('([^\n]*)\n',$m);
    $ind = $this->previewIndent();
    // too big indenting will be catched by parent
    if ($ind == $expectedIndent +1){
      // nested loop or if or else
      $childs = $this->parseChilds($expectedIndent+1, $ind_str.$this-ind);
    }
  }

  // returns tag array('type' => tag, 'name' => tag, 'attributes' => array, childs => array) or null
  protected function parseTag($expectedIndent, $ind_str){
    if (!$this->str($ind_str))
      return null;
    // HAML has wired properties "haml" : "%p#id(id='1')" -> "html" : "<p id='id_1'></p>"
    // thus store css values separately and merge them the HAML way when PHP is generated 
    
    $o = $this->o;
    # optional tag name defaulting to div (eg #table)
    $tag = array('id' => array(), 'classes' => array(), 'ind' => $ind_str);
    if ($this->reg('%([^\s.#]+)',$m)){
      $tag['name'] = $m[1];
    } else $tag['name'] = 'div';

    # parse .foo and #bar CSS properties
    while ($this->reg('#([^\s.#]+)|.([^\s.#]+)', $m)){
      $in_loop = true;
      if ($m[2] === '')
        $tag['id'][] = $m[1];
      elseif ($m[1] === '')
        // classes are all stored and will be separated by spaces
        $tag['classes'][] = $m[2];
      else throw new Exception('unexpected');
    }
    if (!isset($in_loop) && $tag=='div'){
      // neither CSS style and default div. This is not a tag line
      $this->o = $o;
      return $this->pError('tag expected');
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
      $tag['attrs'][] = $this->pError("parsing HTML like attrs", array($attrParsers[$m[1]]));
    }

    if ($this->reg("=|!=|&=",$m)){
      // after optional attributes the content assignment may take place:
      // = != or &= assinment
      $op = $m[1];
      $code = $this->arbitraryPHPCode();
      if (strpos($code,"\n")!== false)
        $this->error("\\n not supported in != &= = assignments !?");
      $tag['childs'] = array(array(
        'phpecho' => $code,
        'escape_html' => ($this->options['escape_html'] && $op != '!=')
                      || ($op == '&=')
      ));
    } else {
      // try parsing nested children
      $tag['childs'] = $this->parseChilds($expectedIndent, $ind_str.$this->ind);
    }
    return $tag;
  }

  protected function pAttrs($type){
    $sepsByType = array(
      'ruby' => array('pReg','[\s]*,[\s\n]*'),
      'html' =>  array('pReg','[\s\n]+')
    );
    $endByType = array(
      'ruby' => '}',
      'html' =>  ']'
    );
    $attributes = $this->pError('attribute list expecetd',  array('pSepBy', $sepsByType[$type], array('pAttr', $rubyLike)));
    $t = $endByType[$type];
    $this->pError($t, array('pStr',$t));

    $attrs = array();
    // merge key = value pairs keeping the last occurrence only
    return array_reduce($attributes, 'array_merge');
  }

  protected function pAttr($type){
    $nameByType = array(
      'html' => array('pReg','([\w]+)'),
      'ruby' => array('pReg',':([\w]+)')
    );
    $sepByType = array(
      'html' => array('pStr','='),
      'ruby' => array('pStr','[\s]*=>[\s]*')
    );
    if (!$this->reg('[\w]+', $m))
      $this->error('attr name expected');
    $name = $m[1];
    $this->pError('= or => expected depending on attr type', $sepByType[$type]);
    return array($name => $this->pError('value maybe list', array('pAttrValue', $type, $name)));
  }

  protected function pAttrValue($type, $name){
    $spaceOkByType = array(
      'html' => false,
      'ruby' => true,
    );

    # parse php code or "..#{}.."
    $pAttrValue = array('pChoice'
     , array('pApply', create_function('$s','return array(array("phpvalue" => $s));'),'pArbitraryPHPCode')
     , array('pAttrValueQuot')
     );

    if (in_array($name, array('id','class'))){
      # may be a list.

      $r = $this->pChoice(
          array('pApply', create_function('$s','return array($s);'), $pAttrValue)
        , array('pSequencea'
                , array('pStr','[')
                , array('pSepBy'
                  , array('pReg',',[\s\n]*')
                  ,       $pAttrValue)
                , array('pStr',']'),
          )
      );
    
    } else {
      $r = $this->p($pAttrValue);
    }
    return $r;
  }

  //  "...#{} .. #{}.."
  //  returns list of array('text' => ..) array('phpecho' =>  .. )
  protected function pAttrValueQuot($spacesOk){
    $o = $this->o;
    $items = array();
    $s = '';
    if (!$this->p2($r,'pStr','"')){
      $this->o = $o; return $r;
    }
     
    while (true){
      if ($this->eof){
        $this->o = $o; return $this->pFail('no eof expected');
      }
      if ($this->str('#{')){
        $items[] = array('text' => $s);
        $s = '';
        if (!$this->p2($r, 'pArbitraryPHPCode',$spacesOk)){
          $this->o = $o; return $r;
        }
        if (!$this->p2($r, 'str', '}')){
          $this->o = $o; return $r;
        }
        $items[] = array('phpvalue' => $code['r']);
      } else {
        $s .= $this->s[$this->o++];
      }
    }
    if ($s !== '')
      $items[] = array('text' => $s);

    $x = $this->pStr('"');
    if (!$this->p2($r, 'pStr', '"')){
      $this->o = $o;
      return $r;
    }
    return $items;
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
        "' str" => '("[^"\\\\]+|\\.)*"',
        '" str' => '([^\'\\\\]+|\\.)*\'',
        ', separated func args' =>  '\(((?R)(,(?R))*)\)',
        'recursion in ()' => '\((?R)\)', // this catches nested ( 2 + (4 + ) ) ..
        // '{(?R)}
        ' anything else but terminal' => "[^(){},$s]+"
      );
      $regex ='('. implode('|',$items).')+';
    }
    $this->reg($regex, $m);
    $s = substr($this->s, $o, $this->o - $o);
    if (strlen($s) > 0)
      return $s;
    else {
      $this->o = $o; return null;
    }
  }

  protected function parseText($expectedIndent, $ind_str){
    // TODO: HAML supports #{} in text as well
    $text = '';
    while ($this->str($ind_str)){
      $text .= $this->reg('\([^\n]*\n)');
    }
    return array('text' => $text, 'ind' => $ind_str);
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
        $code .= "\$html .= ".$l['php'].";\n";
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
        foreach ($args as $arr) { extract($arr); }
        \$html = '';
        $code
        return $html;
      }
    ";
  }

  static public function phpRenderer($list){
    // its your task to put stuff in scope before evaluating this code..
    $code = '';
    // this can be optimized probably
    foreach ($list as $l) {
      if (isset($l['phpecho'])){
        $code .= '<?php echo '.$l['php'].")?>";
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
      return $this->phpRenderer($hamlTree->list, $hamlTree->options['encoding']);
    else
      return $this->funcRenderer($hamlTree->list, $hamlTree->options['encoding'], $func_name);
  }

  static public function hamlToPHPStr($str, $name = 'no location', $options = array(), $func_name = null){
    return self::treeToPHP(new HamlTree($str, $name, $options), $func_name);
  }

  // minimal test of the parser
  static public function hamlInternalTest(){
    $p = new HamlTree("",'test location', array(), false);
    $p->selfTest();
  }
}
// vim: fdm:marker
