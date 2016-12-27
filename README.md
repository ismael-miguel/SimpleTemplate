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

 - `/`
 
    This command doesn't receive any data.
	 
    It is used to specify the end of `if`, `for` and `each`.
    
    It isn't required, but it is recommended since it allows you to define a scope for those commands.

 - `echo`
     
     Structure: `echo [separator <value> ]<value>[, <values>]`
 
     Outputs the values and the contents of the variables. Multiple arguments are separated with commas.
     
     Arrays are automatically flatened, being separated by the value of `separator`, if present.
    
 - `if`
 
     It is used for a condition.
	 
     Structure: `if <val>[ has [not] <val>|is[n't| not][equal[ to]|lower[ than]|greater[ than]] <val>]`. Anything between `[square brackest]` is optional.
	 
     `if <val> has` -> Sees if a particular key exists on `<val>`
	 
     `if <val> is ...` -> Performs a single boolean operation on `<val>`.
     
 - `else`
 
     Just a simple `else` statement.
     
     If you add an `if`, all the rules before apply
     
 - `each`
 
     Loops over `<val>`. The structure is equal to PHP.
     
 - `for`
 
    Strucutre: `for <val>[ from <start>] to <end>[ step <steps>]`
	 
    These values will be fed to the PHP `range` function, which then runs a single `foreach`.
    
    The `range` is "compiled" on run-time, to allow to use variables as the values.
    
 - `set`
 
    Strucutre: `set <var> <value>[, <values>]`
	 
    Defined a value to a variable. To create an array, separate the values with commas.
	 
    The values from the array are accessed with `<array>.<key>`.
    
 - `global`
 
    Strucutre: `global <save_var> <global_var_name>`
    
    Fetches the value from the var `$GLOBALS` and stores on `<save_var>`.
    
 - `call`
 
    Strucutre: `call <function> [into <var>][value[, values]]`
    
    Calls a function with the provided values, storing the result into the defined variable.
    
 - `php`
 
     Strucutre: `php <snippet>`
     
     Simply runs the `<snippet>` directly.
     
     You can use the variable `$DATA` to access everything you need.
    

More changes may come (a `while` loop would be a good idea) in the future.
