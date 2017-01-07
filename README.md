# SimpleTemplate
SimpleTemplate - A very simple template engine to use, with support for custom PHP

This was made entirelly for the fun of it, and **may** not work for what you are trying to do.

The performance of it isn't great, but works fine **as far as I know**.

<hr>

##How to use it

1- You need to load a template code:

	$template = new SimpleTemplate('{@if argv.0}Hello world!{@else}Boo!!!');
	
	//or
	$template = new SimpleTemplate::fromFile($path);

2- Pass some data:

	$template->setData('key', 'value');
	$template->loadData(array('key' => 'value'));

3- It is ready to run:

	$template->render($arguments);

If you are curious about the generated PHP:

	echo $template->getPHP($arguments);

I recommend you to do not look at it. In there lies madness and **bad** code. Stay away from it! BEWARE!!! **BEWARE!!!1**

I've put some effort into making the generated some readable (or, at least, followable), but there's still madness there!

##How it works

I've tried to keep the syntax as easy as possible, but still allow some flexibility. Since this is a simple engine, it isn't that powerfull.

The way the engine works is by converting the input into syntactically valid PHP.

###The syntax:

Every command has the following structure: `{@<command> arguments}`.

Each command has a different structure for the `arguments` bit.

Anything outside those is considered output.

####Values and variables:

A variable is any alphanumeric string that doesn't have a special meaning. Arrays' items can be accessed by writting the key after a period `like.this.example.here`.

A value is everything else, like numbers, `"strings"`, `true` and `false`. Strings **must** be with double-quotes.

The variable `argv` will contain the arguments passed while `argc` will have the number of arguments.

###The commands:

 - `//`
    
    This is a comment. Just type whatever you wish in front of it.
    
    **Warning**: This is **NOT** passed to the PHP code, which will be rendered **without comments**.
    
 - `/`
 
    This command doesn't receive any data.
	 
    It is used to specify the end of `if`, `for` and `each`.
    
    It isn't required, but it is recommended since it allows you to define a scope for those commands.

 - `echo`
     
     Structure: `echo [separator <value> ]<value>[, <values>]`
 
     Outputs the values and the contents of the variables. Multiple arguments are separated with commas.
     
     Arrays are automatically flatened, being separated by the value of `separator`, if present.
     
     Instead of `{@echo separator " " argv}`, one can use `{@echoj " " argv}`.  
     You can also use `{@echol argv}`, to output a line at the end (like `{@echo argv, EOL}`).  
     Combining both into `{@echojl " " argv}` also works.
     
 - `print`
 
     Strcture: `print[ into <var>] <format>, <value>[, <values>]`
     
     This is just a syntax sugar for `{@call printf <format>, <values>}` and for `{@call printf into <var> <formar>,<values>}`.
     
 - `return`
 
     Structure: `return[ <value>]`
     
     Returns the value and quits. The value returned will also be returned by `SimpleTemplate::render()`, making it available outside the template code.

 - `inc`
 
     Structure: `inc[ by <value>] <var>[, <vars>]`
     
     Increments all values in the array by `<value>`. (Default value of `<by>` is `1`).
     
     This also works for strings! Example:
     
          {@set i "a test", 1,2,3}{@inc by 3 i}{@echoj " " i}
     
     Should output: `a tesw 4 5 6`
    
 - `if`
 
     It is used for a condition.
	 
     Structure: `if <val>[ has [not] <val>|is[ not][equal[ to]|lower[ than]|greater[ than]] <val>]`. Anything between `[square brackets]` is optional.
	 
     `if <val> has` -> Sees if a particular key exists on `<val>`
	 
     `if <val> is ...` -> Performs a single boolean operation on `<val>`.
     
 - `else`
 
     Just a simple `else` statement.
     
     If you add an `if`, all the rules before apply
     
 - `each`
 
     Structure: `each <array> as <var>[ key <key>]`
     
     Loops over each element in the array `<array>`.
     
     Each value will be available on the variable `<var>` (you can use array indexes like `argv.0` too).
     
 - `for`
 
    Structure: `for <val>[ from <start>] to <end>[ step <steps>]`
	 
    These values will be fed to the PHP `range` function, which then runs a single `foreach`.
    
    The `range` is "compiled" on run-time, to allow to use variables as the values.
    
 - `while`
 
    Structure: Same as `if`.
    
    Repeats the code inside the block while the value of the condition is true.
    
 - `set`
 
    Structure: `set <var> <value>[, <values>]`
	 
    Defined a value to a variable. To create an array, separate the values with commas.
	 
    The values from the array are accessed with `<array>.<key>`.
    
 - `global`
 
    Structure: `global <save_var> <global_var_name>`
    
    Fetches the value from the var `$GLOBALS` and stores on `<save_var>`.
    
 - `call`
 
    Structure: `call <function> [into <var>][value[, values]]`
    
    Calls a function with the provided values, storing the result into the defined variable.
    
 - `php`
 
     Structure: `php <snippet>`
     
     Simply runs the `<snippet>` directly.
     
     You can use the variable `$DATA` to access everything you need.
     
     Some functions are available inside `$FN`. They don't do anything too great, but they are **VERY** important and shouldn't be changed.

 - `fn`
 
    Structure: `fn <var>`
    
    Creates a function with the name `<var>`. Using `{@/}` to limit the scope is **VERY HIGLY** recommended.
    
    All the passed arguments are available inside `argv`.
    
    For example: `{@fn show}{@echo argv}{@/}{@call show 1, 2, 4, 8}`
    
    You can create functions inside array elements too like: `{@fn test.0}[...]{@/}`!  
    If the function `test.0` doesn't exist, the system will try `text_0`.  
    This allows you to write `{@call var.dump}` and it will run `var_dump()`.

More changes may come in the future, like expression (like `a + b / c % d . e`) parsing.

<hr>

##Some useless stuff:

It is possible to do **some** code golfing. I do not advise, but it works.

Here's an example, that increments all the values in `argv` by 3, and outputs them separated by a space.

    {@inc by 3 argv}{@echoj " " argv}

Which can be written as:

    {@incby3 argv}{@echoj" " argv}

Some whitespace are optional, as long as it isn't ambiguous.
