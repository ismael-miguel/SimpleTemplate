# SimpleTemplate
SimpleTemplate - A very simple template engine to use, with support for custom PHP

This was made entirelly for the fun of it, and **may** not work for what you are trying to do.

The performance of it isn't great, but works fine **as far as I know**.

<hr>

##How to use it

1- You need to load a template code:

	$template = new SimpleTemplate('<code>');
	
	//or
	$template = new SimpleTemplate::fromFile($path);

2- Pass some data:

	$template->setData('key', 'value');
	$template->loadData(array('key' => 'value'));

3- It is ready to run:

	$template->render($arguments);

If you are curious about the generated PHP:

	echo $template->getPHP($arguments);

I recommend you to do not look at it. In there lies madness and **bad** code. Stay away from it.

##How it works

I've tried to keep the syntax as easy as possible, but still allow some flexibility. Since this is a simple engine, it isn't that powerfull.

The way the engine works is by converting the input into syntactically valid PHP.

###The syntax:

Every command has the following structure: `{@<command> arguments}`.

Each command has a different structure for the `arguments` bit.

Anything outside those is considered output.

###The commands

 - `/`
     This command doesn't receive any data.
	 
	 It is used to specify the end of `if`, `for` and `each`.
 - `if`
     It is used for a condition.
	 
	 Structure: `if <val>[ has [not] <val>|is[n't| not][equal[ to]|lower[ than]|greater[ than]] <val>]`. Anything between `[square brackest]` is optional.
	 
	 `if <val> has` -> Sees if a particular key exists on `<val>`
	 
	 `if <val> is ...` -> Performs a single boolean operation on `<val>`.
 - `else`
     Just a simple `else` statement.
 - `each`
     Loops over `<val>`. The structure is equal to PHP.
 - `for`
     Strucutre: `for <val> [<start>..]<end>[ step <steps>]`
	 
	 These values will be fed to the PHP `range` function, which then runs a single `foreach`.
 - `set`
     Strucutre: `set <var> <value>[, <values>]`
	 
     Defined a value to a variable. To create an array, separate the values with commas.
	 
	 The values from the array are accessed with `<array>.<key>`.
 - `global`
    Strucutre: `global <save_var> <global_var_name>`
	 
 	Fetches the value from the var `$GLOBALS` and stores on `<save_var>`.
