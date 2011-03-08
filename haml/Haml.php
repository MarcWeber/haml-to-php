<?php

class HamlParseException extends Exception {}

/* author & copyright: Marc Weber
 * copyright 2011
 * license: GPL (contact me if you have other requirements )
 */


// because we match only against non utf-8 chars this should be almost utf-8 compatible
// (except that it does only match latin spaces..
//
// Parser was not written for speed. Eg the indentation may be evaluated 
// multiple times. All the function calls take time as well. In the end you'll 
// be using file or sqlite caches or such anyway ..
//
// \s has been introduced to reduce duplication. Its replaced by ' \t' before 
// running a regex
//
// HamlTree parses a string to a tree. Using "typed" arrays because they are 
// faster than classes (and objects?).
// tags have the keys: type = 'tag', ind, id, classes, attributes, childs
// attributes arre a list of array(key => value) where value is a list of 
// arrays denoting PHP code or text
// text have the keys: text = the content
class HamlTree {
  public $s; // the haml file contents as string
  public $o; // file offset
  public $ind = null; // indentation string used in haml file
  public $last_ind = 0; // indentation last line
  public $name;

  public $doctype;
  public $tree = null;

  // returns array($line, $col)
  public function pos(){
    $lines = explode("\n",substr($this->s, $o));
    $len = count($lines);
    return array($len, strlen($lines[$len-1]));
  }

  protected function error($msg){
    list($l,$c) = $this->pos();
    throw new HamlParseException("$name:$l:$c: $error parsing haml: $msg");
  }

  // increases offset on if str matches at offset
  protected function str($s, $msg){
    if (substr($this->s, $o, $l = strlen($s)) == $s){
      $this->o += $l;
      return true;
    }
    if ($msg == null)
      return false;
    else $this->error($msg);
  }

  // increases offset on if reg matches at offset
  protected function reg($reg, &$m, $msg){
    $reg = str_replace('\s',' \t', $reg);
    if (preg_match('/(?m)'.$reg.'/', $this->s, $m, 0, $this->o)){
      return $this->str($m[0]); // force matching at ^
    }
    if ($msg == null)
      return false;
    else $this->error($msg);
  }

  function __construct($s, $options, $name){
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
    $this->parseHAML();
  }

  // parsing:

  protected function parseHAML(){
    // parse optional !!! doctype
    if ($this->str('!!!')){
      $this->reg('[\s]*');
      $this->doctype = $this->reg('[^\s]', 'doctype expected');
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

  protected function parseChilds($ind, $ind_str){
    $childs = array();
    $last = null;

    $o = $this->o;
    $new_ind = $this->previewIndent();

    $this->o = o;
    if ($new_ind == $ind){
      // same indentation: no childs
      return array();
    }

    if ($new_ind > $ind +1){
      $this->error('the line is indented '.($new_ind - $ind).' levels too deep');
    }

    if ($new_ind = $ind+1){
      $ind_N = $ind_str.$this->ind;
      // parse children
      while (!$this->eof()){
        if ($new_ind > $ind){
          $o2 = $this->o;
          $child = $this->parseTag($new_ind, $ind_N);
          if ($child !== null){
          $childs[] = $child;
          } else {
            $list = $this->parseText($new_ind, $ind_N);
            if (is_null($list))
              $this->error('child (tag or text) expected');
            $childs = array_merge($childs, $list);
          }
        }
      }
    }
    // else: indentation decreases. return empty array
    return $childs;
  }

  // returns tag array('type' => tag, 'name' => tag, 'attributes' => array, childs => array) or null
  protected function parseTag($expectedIndent, $ind_str){
    if (!$this->str($ind_str))
      return null;
    // HAML has wired properties "haml" : "%p#id(id='1')" -> "html" : "<p id='id_1'></p>"
    // thus store css values separately and merge them the HAML way when PHP is generated 
    
    $o = $this->o;
    # optional tag name defaulting to div (eg #table)
    $tag = array('id' => '', 'classes' => array(), 'ind' => $ind_str);
    if ($this->reg('%([^\s.#]+)',$m)){
      $tag['name'] = $m[1];
    } else $tag['name'] = 'div';

    # parse .foo and #bar CSS properties
    while ($this->reg('#([^\s.#]+)|.([^\s.#]+)', $m)){
      $in_loop = true;
      if ($m[2] === '')
        // last id wins
        $tag['id'] = $m[1];
      elseif ($m[1] === '')
        // classes are all stored and will be separated by spaces
        $tag['classes'][] = $m[2];
      else throw new Exception('unexpected');
    }
    if (!isset($in_loop) && $tag=='div'){
      // neither CSS style and default div. This is not a tag line
      $this->o = $o;
      return null;
    }
    unset($in_loop);

    $attrs = array();
    # parse properties
    if ($this->text('(')){
      $this->parseAttrs($attrs, null,null,')',false);
    } elseif ($this->text('{')){
      $this->parseAttrs($attrs, ':',',','}', true);
    }
    return $tag;
  }

  protected function parseAttrs(&$attrs, $k_prefix, $list_sep, $close, $spaces_in_code){
    $first = true;
    while (true){
      $this->reg('[\s]*');
      if ($this->text($close))
        return;
      if (!$first && !is_null($list_sep)){
        // line breaks are allowed here
        $this->str($list_sep);
        $this->reg('[\s\n]*');
      }
      if (!is_null($k_prefix))
        $this->text($k_prefix,"$k_prefix expected");
      $value = $this->str('"') ? $this->parseRestOfHTMLAttributeValueMaybeContainingCode()
                                 : $this->arbitraryPHPCode($spaces_in_code);
      if (strpos($value,"\n")!== false)
        $this->error("\\n not allowed in attributes ?!");
      $attributes[] = array(
        $this->reg('([^\s=\n]+)=',$m, $k_prefix.'key=')
        => array(array('php' =>  $value)));
      $first = false;
    }
  }

  protected function arbitraryPHPCode($spaces_in_code = true){
    $s = $spaces_in_code ? '' : '\s';
    $o = $this->o;
    static $regex;
    if (is_null($regex)){
      // (?R) matches to most outer regexp recursively
      // That's something Ruby can't do (yet?)
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
    return substr($this->s, $o, $this->o - $o);
  }

  protected function parseRestOfHTMLAttributeValueMaybeContainingCode(){
    $items = array();
    $s = '';
    while (true){
      if ($this->eof) $this->error('ending " expected');
      if ($this->str('#{')){
        $items[] = array('text' => $s);
        $s = '';
        $code = $this->arbitraryPHPCode(true);
        $this->str('}', '} expected');
        $items[] = array('php' => $code);
      } else {
        $s .= $this->s[$this->o++];
      }
    }
    if ($s !== '')
      $items[] = array('text' => $s);
    return $items;
  }
 
  // returns text array('type' => 'text') or null. 
  protected function parseText($expectedIndent, $ind_str){
    // TODO: HAML supports #{} in text as well
    $text = '';
    while ($this->str($ind_str)){
      $text .= $this->reg('\([^\n]*\n)');
    }
    return array('type' => 'text', 'text' => $text, 'ind' => $ind_str);
  }

}
/**
 * HamlParser class.
 * Parses {@link http://haml-lang.com/ Haml} view files.
 * @package			PHamlP
 * @subpackage	Haml
 */
class Haml {



  function text_for_doctype($doctype){
      /*
      $text = text[3..-1].lstrip.downcase
      if text.index("xml") == 0
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
  }

  static public function parse($str, $options, $name){
    return new self.treeToPHP(HamlTree($str, $options, $name));
  }
}
// vim: fdm:marker
