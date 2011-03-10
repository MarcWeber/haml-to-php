What is this?:
=============
See www.haml-lang.com

Status:
=======

  This is work in progress. current state:
  parser: maybe 80%
  parsed tree: nothing
  test cases: none are pased yet

  skipping doctype tests (TODO: implement this)

  Anyway me adding a real test suite and running it (even extending it)
  should give you some confidence that this attemp is taken seriously.
  The result will be HAML compliant libarary
  (at least HTML and attributes should be rendered correctly)

BUGS:
=====
  Make test 58 work
  expected: hello&#x000A;<p></p>
  got: hello<p></p>

  
  The filter functions are checked when creating the template PHP code only.
  Thus you have to delete old templates using filters you want to forbid
  manually.

  suppress_eval is not implemented yet. I don't know how to handle

    - foreach( .. ){

  or

    - if 
        ..
    - else
        ..

  without causing syntax errors.
  

  Probably parsing is slow. I agree. But at it works at least :)
  See related work about alternatives

  #{} only works it attr values or attr names.

  Multiline strings not yet supported. Can we do better?

  There is no :ruby filter. a :php filter has been added instead using eval()
  Of course globals etc are accessible - so use with caution.
  For this reason suppress_eval defaults to true.

  There are no :sass :textile :markdown :maruku :erb filters.
  Don't think they should be part of this library. I'm a friend of modular
  reusable code.

  If you find bugs use the gituhub's bugs create a test case and send a patch,
  please.

  #{} is not honored in text blocks (See TODO)

  #id()() and #id{}{} is allowed (won't fix)

  #A{:id => "B"}(id="C") yields <div id="A_B_C"> instead of <div id="A_C_B"> (fix only if some is hurt by it)

  There are some complicated cases such as using both () and {} and lists of id
  and class items. In some cases the result differs from Ruby HAML.

  complicated cases like this.. What should be the result?
  #A{:id => "B", :class => "in#{2}ner2"}(id="C" class="inner1" class="iN#{3+1}ner2")
  ruby haml: <div class='in2ner2' id='A_C_B'></div>
  So the second class is dropped?

  filters are still missing

  If Ruby native arrays are support this library should support php native
  arrays. Maybe you're right. Parsing is more complicated then. This is no
  priority for me.

  no caching provided yet. a simple file caching (using locking) should be
  implemented

  This is valid ruby-haml but not documented:
  #A{:id = ["a" "b"]}
  it yields id="ab" !? Not implemented here

  Duplicate keys are dropped based on the code generating them - not by the
  result. Example:
  #d{ '#{uniqid}' => "x", '#{uniqid}' => 'y'}
  will only yield <div unique='y'></div>
  Fixing this would require more logic to be executed when the template is run.
  Possible but more work. Do you need it?
  Also if the attr name evaluates to class / id it may be duplicated

Usage:
======
  $haml = "%div = $key"
  eval(Haml::hamlToPHPStr($haml, "func_name"));
  echo func_name(array('key'=> "value"));

  The func runs extract on each passed argument to put vars in scope

  a HamlParseException is thrown if parsing fails
  Is there any way to push the parse location to the stack trace !?
  I haven't found one

Note:
=====
  This library of course is limited by PHP. The language still sucks.
  I still tried getting the best out of it.

  I thought I'd manage to keep LOC below 1000. I failed.

TODO test quoting in all cases:
===============================
  %div = 
  %div != 
  %div &= 
  with both options escape_html
  reformat this

  Add this library to the list of PHP implementations on Wikipedia

  implement doctypes
  #{} in text blocks

  use namespace!
  
  #{} in filters

  test cases for - foreach(..) blocks and if .. else

Why do I use HAML:
==================
  Tags are always closed. Its faster to write and read.
  Why use Zen Coding if you can use HAML?


Why I wrote it:
===============

  - Cause I want to improve the world - tell people that they should learn from
    other communities and other languages - because PHP is too limiting in
    various cases. Important tools like QuickCheck do not exist in PHP.
    This often results in bad code which means:
    * missing test suites
    * you have to find out what works and what doesn't

  - I need a working implementation I can maintain.

  - I want to translate this code into HaXe later in which case I only
    have to rewrite the recursive regex by some additional code and replace
    arrays by lists or such..

related (php) work:
===================
Most of the related work has been found using wikipedias haml page

  phphaml:
  ========
    http://phphaml.sourceforge.net/
    (flaws rev 90):
    - Code does not pay attention to != vs &= vs =
    - Code dose contain only one htmlentities which is used for quoting attribute
     values. So for HTML content no quoting is done at all!  So no quoting is
     done at all !

    Is the author using filters or such?

    - This does not work:
      %div{ :foo => substr("abc",1,2) }
      at least this works here:
      %div{ :foo => 2 }

    That the author does not pay attention to HTML quoting enough makes me stop
    thinking about using it.

  phamlp:
  =======
    http://code.google.com/p/phamlp/
    https://github.com/MarcWeber/phamlp
    
    See my statement in the README.
    The parser will never be compliant without a rewrite
    The author seems to be gone away ? No replies or updates for a long time.

  Fammel: https://github.com/dxw/Fammel
  =====================================
    Its using a real Parser and grammar. Current status:
    Horribly broken. The author admits it.

    dxw: " However, there were two big problems. The first was a (perhaps inherent)
      struggle over implementing the syntax of Ruby haml exactly, or explicitly
      designing some PHP variant. I think the latter is what we eventually decided,
      mostly because... 
      [ .. next mail .. ]
        
        But the whole point of starting a new parser was in order to have one
      that was based on a formal, specified grammar. I think that any other approach
      would eventually become impossible to maintain. "

    me: I agree. Its a challenge :)
        Actually teh code is using some very simple parsing combinators ..
        So I hope the code is somewhat understandable and maintainable.

    me: Which sass implementation do you use?
    dxw: " original sass that comes with HAML
    me: (Yeah. Go Ruby if you can!)
        Nobody is going to fix PHP because Ruby exists ..
        (Mabye someone proofs me wrong

    Haml's testcases run on Fammel (ignoring spaces etc.)
    (based on git rev 89f10893)
    http://mawercer.de/~marc/results.txt

    Examples of cases which fail:

        Boolean attributes
        81:
          boolean attribute with XHTML
        haml: %input(checked=true)
        expected: : <input checked='checked' />
             got: : <input>  (checked=true)</input>

        82:
          boolean attribute with HTML
        haml: %input(checked=true)
        expected: : <input checked>
             got: : <input>  (checked=true)</input>

strange cases:
==============
  What happens to the ! ?
  .class!a= 7
  <div class='class'>a= 7</div>
