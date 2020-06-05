<?php

final class SimpleTemplate_SyntaxError extends \Exception {
	function __construct($message) {
		$this->message = 'SimpleTemplate - Syntax Error: ' . $message;
	}
}

final class SimpleTemplate_FN_Invalid extends \Exception {
	function __construct($message) {
		$this->message = 'SimpleTemplate_FN - Invalid function: ' . $message;
	}
}

// contains all functions needed
final class SimpleTemplate_FN {
	private static function fn_array_flat(){
		$return = array();
		$array = func_get_args();
		array_walk_recursive($array, function($value)use(&$return){
			$return[] = $value;
		});
		return $return;
	}
	
	private static function fn_inc($_, $by = 1){
		// if there's no increment value
		if(!$by)
		{
			return $_;
		}
		
		// if there's no value
		if(!$_)
		{
			return $by;
		}
		
		$fn = function($_, $by){
			switch(gettype($_))
			{
				case 'NULL':
				case 'null':
					return $by;
				case 'integer':
				case 'double':
				case 'float':
					return $_ + $by;
				case 'string':
					if($_ === '')
					{
						return '';
					}
					
					$_by = abs($by);
					
					for($i = 0; $i < $_by; $i++)
					{
						if($by > 0)
						{
							++$_;
						}
						else
						{
							$last = strlen($_) - 1;
							
							if($_[$last] === 'a' || $_[$last] === 'A')
							{
								// handles aaaa -> zzz
								$_ = preg_replace_callback('@[aA]+$@', function($str){
									return str_repeat($str[0][0] === 'a' ? 'z': 'Z', strlen($str[0]) - 1);
								}, $_);
							}
							else
							{
								$_[$last] = chr(ord($_[$last]) - 1);
							}
						}
					}
					
					return $_;
				default:
					return $by;
			}
		};

		
		if(gettype($_) === 'array')
		{
			array_walk_recursive($_, function(&$value)use(&$fn, &$by){
				$value = $fn($value, $by);
			});
		}
		else
		{
			$_ = $fn($_, $by);
		}
		
		return $_;
	}
	
	private static function fn_len($args){
		$result = array();
		
		if(func_num_args() > 1)
		{
			$args = func_get_args();
		}
		else
		{
			$args = array($args);
		}
		
		foreach($args as $arg)
		{
			switch(gettype($arg))
			{
				case 'array':
					$result[] = count($arg);
					break;
				case 'string':
					$result[] = strlen($arg);
					break;
				case 'integer':
				case 'double':
				case 'float':
					$result[] = 0;
					break;
				default:
					$result[] = null;
			}
		}
		
		return $result;
	}
	
	private static function fn_repeat($_, $times = 1){
		if($times < 1)
		{
			return '';
		}
		
		array_walk_recursive($_, function(&$value)use(&$times){
			$value = str_repeat($value, $times);
		});
		
		return $_;
	}
	
	// *******************************************************************************
	
	static function call(){
		$args = func_get_args();
		$fn = array_shift($args);
		
		if(!self::method_exists($fn))
		{
			throw new SimpleTemplate_FN_Invalid($fn);
		}
		
		return call_user_func_array(array(__CLASS__, 'fn_' . $fn), $args);
	}
	
	static function name_list(){
		static $list = array();
		
		if(!$list)
		{
			foreach(get_class_methods(__CLASS__) as $method)
			{
				if(strpos($method, 'fn_') === 0)
				{
					$list[] = preg_replace('@^fn_@', '', $method);
				}
			}
		}
		
		return $list;
	}
	
	static function method_exists($method){
		static $exist = array();
		
		return isset($exist[$method])
			? $exist[$method]
			: (
				is_null($exist[$method] = @method_exists(__CLASS__, 'fn_' . $method))
					? in_array($method, self::name_list())
					: $exist[$method]
			);
	}
}

// compiler class
class SimpleTemplate_Compiler {
	private $uuid = null;
	
	private static $var_name = 'DATA';
	private static $default_var_name = '_';
	
	private static $regex = array(
		'var' => '(?:(?:(?:U|unsafe|R|ref|reference)\:)?[_a-zA-Z]\w*(?:\.(?:\[[_a-zA-Z]\w*(?:\.\w*)*\]|\w*))*)',
		'var_name' => '(?:[_a-zA-Z]\w*)',
		'var_simple' => '(?:(?:(?:U|unsafe|R|ref|reference)\:)?[_a-zA-Z]\w*(?:\.\w*)*)',
		'value' => '(?:(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*")|[\-+]?\d*(?:\.\d*)?|true|false|null)',
		'var_value' => '(?:(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*")|[\-+]?[\d\W]\d*(?:\.\d*)?|true|false|null|(?:(?:(?:U|unsafe|R|ref|reference)\:)?[_a-zA-Z]\w*(?:\.(?:\[[_a-zA-Z]\w*(?:\.\w*)*\]|\w*))*))'
	);
	
	private $options = array();
	private $template = null;
	
	private $fn = null;
	private $php = '$_ = &$DATA;' . PHP_EOL;
	
	private static function render_var($name = null, $safe = true, $allow_ref = false){
		// preg_match('@^\s*(?:(?<unsafe>U|unsafe)\s+)?(?<var>.*)$@', $name ?: self::$default_var_name, $bits);
		preg_match('@^\s*(?:(?:(?<unsafe>U|unsafe)|(?<ref>R|ref|reference))\:)?(?<var>.*)$@', $name ?: self::$default_var_name, $bits);
		
		if(strpos($bits['var'], '['))
		{
			$var = preg_replace_callback('@\.\[([_a-zA-Z]\w*(?:\.\w*)?)\]@', function($matches){
				return '[' . self::render_var($matches[1], false) . ']';
			}, $bits['var']);
			
			$var = '$' . self::$var_name . ($bits['var'] ? '[\'' . implode('\'][\'', explode('.', $var)) . '\']' : '');
			
			$var = preg_replace(
				array('@\'(\w+)\[@', '@\'\]\]\'\]@'),
				array('\'$1\'][', '\']]'),
				$var
			);
		}
		else
		{
			$var = '$' . self::$var_name . ($bits['var'] ? '[\'' . implode('\'][\'', explode('.', $bits['var'])) . '\']' : '');
		}
		
		return $allow_ref && isset($bits['ref']) && $bits['ref']
			? '&' . $var
			: (
				$safe && !$bits['unsafe']
					? '(isset(' . $var . ')?' . $var . ':null)'
					: $var
			);
	}
	
	private static function split_values($values, $delimiter = '\s*,\s*'){
		// http://stackoverflow.com/a/5696141/ --> regex quoted string
		// http://stackoverflow.com/a/632552/ --> regex to match $delimiter outside quotes
		return preg_split('@(' . ($delimiter ?: '\s*,\s*') . ')(?=(?:[^"]|"[^"\\\\]*(?:\\\\.[^"\\\\]*)*")*$)@', $values);
	}
	
	private static function parse_values($values, $delimiter = '\s*,\s*', $safe = true, $allow_ref = true){
		$value_bits = self::split_values($values, $delimiter);
		
		foreach($value_bits as $k => $value)
		{
			$value_bits[$k] = self::parse_value($value, $safe, $allow_ref);
		}
		
		return $value_bits;
	}
	
	private static function parse_boolean($data){
		if(
			!preg_match(
				'@(' . self::$regex['var_value'] . ')\s*(?:(isset|is(?:(?:\s*not|n\'?t)?\s*(?:(?:greater|lower)(?:\s*than)?|equal(?:\s*to)?|equal|a|(?:(?:instance|multiple|mod)(?:\s*of)?)|matches))?|has(?:\s*not)?|(?:not\s*)?matches)\s*(' . self::$regex['var_value'] . ')?)?(?:\s*(.+))?@',
				$data, $bits
			)
		)
		{
			return '';
		}
		
		$fn = array(
			'is' => function($data, $var1, $var2){
				$symbols = array(
					'' => '%s === %s',
					'a' => 'gettype(%s) === %s',
					'instance' => 'is_a(%s, %s)',
					'equal' => '%s == %s',
					'lower' => '%s < %s',
					'greater' => '%s > %s',
					'multiple' => '!(%s %% %s)',
					'mod' => '%s %% %s',
					'matches' => 'preg_match(%2$s, %1$s)'
				);
				
				preg_match('@(?<not>not)?\s*(?<operation>equal|lower|greater|a|instance|multiple|mod|matches)?\s*(?:of|to|than)?\s*@', $data, $bits);
				
				return (isset($bits['not']) && $bits['not'] !== '' ? '!': '') . '(' . sprintf($symbols[isset($bits['operation']) ? $bits['operation']: ''], self::parse_value($var1), self::parse_value($var2)) . ')';
			},
			'has' => function($data, $var1, $var2){
				return ($data === 'not' ? '!': '') . 'array_key_exists(' . self::parse_value($var2) . ', (array)' . self::parse_value($var1) . ')';
			},
			'isset' => function($data, $var1){
				return ($data === 'not' ? '!': '') . 'isset(' . self::render_var($var1, false) . ')';
			},
			'matches' => function($data, $var1, $var2, $extra){
				if($extra && self::is_var($extra = self::parse_value($extra, false)))
				{
					return ($data === 'not' ? '!': '') . 'preg_match(' . self::parse_value($var2) . ', ' . self::parse_value($var1) . ', ' . $extra . ')';
				}
				else
				{
					return ($data === 'not' ? '!': '') . 'preg_match(' . self::parse_value($var2) . ', ' . self::parse_value($var1) . ')';
				}
			}
		);
		
		if(isset($bits[2]))
		{
			$ops = explode(' ', $bits[2], 2);
			
			return $fn[$ops[0]](
				isset($ops[1]) ? $ops[1]: '',
				$bits[1],
				isset($bits[3]) ? $bits[3] : self::$default_var_name,
				isset($bits[4]) ? $bits[4] : ''
			);
		}
		else
		{
			return self::parse_value($bits[1]);
		}
	}
	
	private static function parse_value($value, $safe = true, $allow_ref = false){
		if($value === '' || $value === '""')
		{
			return $value;
		}
		else if(preg_match('@^' . self::$regex['value'] . '$@', $value))
		{
			return $value[0] === '"'
				? self::parse_value_string($value)
				: $value;
		}
		else if(preg_match('@^' . self::$regex['var'] . '$@', $value))
		{
			return self::render_var($value, $safe, $allow_ref);
		}
		else
		{
			throw new SimpleTemplate_SyntaxError('Invalid value syntax: ' . $value);
		}
	}
	
	private static function parse_value_string($value){
		return preg_replace_callback(
			'@#{([_a-zA-Z]\w*(?:\.(?:\[[_a-zA-Z]\w*(?:\.\w*)*\]|\w*))*)}@',
			function($matches){
				return '{' . self::render_var($matches[1], false) . '}';
			},
			str_replace('$', '\\$', $value)
		);
	}
	
	private static function is_value($value){
		return strlen($value)
			&& $value[0] !== '$'
			&& $value[0] !== '('
			&& $value[0] !== '&'
			/*&& (
				$value[0] !== '"'
				|| strpos($value, '{$' . self::$var_name) === false
			)*/;
	}
	
	private static function is_var($value){
		return strlen($value)
			&& (
				$value[0] === '$'
				|| $value[0] === '('
				|| $value[0] === '&'
			);
	}
	
	private function format_code($code, $tabs, $skip_first = false, $skip_last = false){
		$lines = preg_split("@(?:\r?\n|\r)+@", $code);
		$heredoc_closing = self::$var_name . $this->uuid;
		
		$return = $skip_first ? array_shift($lines) : '';
		$last = $skip_last ? PHP_EOL . array_pop($lines): '';
		
		foreach($lines as $line)
		{
			if($return)
			{
				$return .= PHP_EOL;
			}
			
			if($line === $heredoc_closing)
			{
				$return .= $heredoc_closing;
			}
			else
			{
				$return .= (
					preg_match('@^\s*\)+;?\s*$@', $line)
						? substr($tabs, 1)
						: $tabs
				). ltrim($line);
			}
		}
		
		return $return . $last;
	}
	
	private function compile($str){
		$UUID = $this->uuid;
		
		$brackets = 0;
		$tabs = '';
		
		$replacement = array(
			'/' => function($data)use(&$replacement, &$brackets, &$tabs){
				if($brackets > 0)
				{
					--$brackets;
					
					return $tabs . '};';
				}
				else
				{
					return $tabs . ';';
				}
			},
			'//' => function(){
				return '';
			},
			'echo' => function($data)use(&$replacement, &$brackets, &$tabs){
				preg_match('@^(?:separator\s+(?<separator>' . self::$regex['var_value'] . ')\s*)?(?<data>.*)$@', $data, $bits);
				
				$separator = $bits['separator'] ? self::parse_value($bits['separator']): '\'\'';
				
				return $tabs . 'echo implode(' . $separator . ', SimpleTemplate_FN::call(\'array_flat\', ' . implode(', ', self::parse_values($bits['data'])) . '));';
			},
			'echol' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $replacement['echo']($data) . 'echo PHP_EOL;';
			},
			'echoj' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $replacement['echo']('separator ' . $data);
			},
			'echojl' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $replacement['echol']('separator ' . $data);
			},
			'echof' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $replacement['echol']('separator ' . $data);
			},
			'print' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $replacement['call']((strpos('into', $data) === 0 ? 's' : '') . 'printf ' . $data);
			},
			'if' => function($data)use(&$replacement, &$brackets, &$tabs){
				++$brackets;
				
				return $tabs . 'if(' . self::parse_boolean($data) . ') {';
			},
			'else' => function($data)use(&$replacement, &$brackets, &$tabs){
				preg_match('@(?<if>if)(?<data>.*)@', $data, $bits);
				
				$return = substr($tabs, 1) . '} else';
				
				if(isset($bits['if']) && isset($bits['data']))
				{
					--$brackets;
				
					$return .= ltrim($replacement['if'](trim($bits['data'])));
				}
				else
				{
					$return .= ' {';
				}
				
				return $return;
			},
			'each' => function($data)use(&$replacement, &$brackets, &$tabs){
				++$brackets;
				
				preg_match('@^(?<var>' . self::$regex['var_value'] . ')\s*(?:as\s*(?<as>' . self::$regex['var'] . ')(?:\s*key\s*(?<key>' . self::$regex['var'] . ')\s*)?)?$@', $data, $bits);
				
				static $count = 0;
				
				$var_name = self::$var_name;
				$tmp_name = 'tmp_' . (++$count) . '_';
				
				$vars_var = self::parse_value(isset($bits['var']) ? $bits['var'] : '_', false);
				$vars_as = self::render_var(isset($bits['as']) ? $bits['as'] : '', false);
				$vars_key = self::render_var(isset($bits['key']) ? $bits['key'] : '__', false);
				
				if(self::is_var($vars_var))
				{
					return <<<PHP
{$tabs}// ~ optimization unfeasable ~ variable used in the first argument
{$tabs}// loop variables - variable
{$tabs}\${$tmp_name}val = isset({$vars_var}) ? \${$tmp_name}val = &{$vars_var} : null;
{$tabs}\${$tmp_name}keys = gettype({$vars_var}) == 'array'
{$tabs}	? array_keys({$vars_var})
{$tabs}	: ((\${$tmp_name}val = \${$tmp_name}val . '')
{$tabs}		? array_keys(range(0, strlen(\${$tmp_name}val = \${$tmp_name}val . '') - 1))
{$tabs}		: array()
{$tabs}	);
{$tabs}\${$tmp_name}key_last = end(\${$tmp_name}keys);
{$tabs}
{$tabs}// loop
{$tabs}foreach(\${$tmp_name}keys as \${$tmp_name}index => \${$tmp_name}key){
{$tabs}	\${$var_name}['loop'] = array(
{$tabs}		'index' => \${$tmp_name}index,
{$tabs}		'i' => \${$tmp_name}index,
{$tabs}		'key' => \${$tmp_name}key,
{$tabs}		'k' => \${$tmp_name}key,
{$tabs}		'value' => \${$tmp_name}val[\${$tmp_name}key],
{$tabs}		'v' => \${$tmp_name}val[\${$tmp_name}key],
{$tabs}		'first' => \${$tmp_name}key === \${$tmp_name}keys[0],
{$tabs}		'last' => \${$tmp_name}key === \${$tmp_name}key_last,
{$tabs}	);
{$tabs}	{$vars_key} = \${$tmp_name}key;
{$tabs}	{$vars_as} = \${$tmp_name}val[\${$tmp_name}key];
PHP;
				}
				else if($this->options['optimize'])
				{
					$keys = '';
					
					if($vars_var !== '""')
					{
						$keys = implode(
							',',
							range(
								0,
								strlen($vars_var) - ($vars_var[0] === '"' ? 3 : 1)
							)
						);
					}
					
					return <<<PHP
{$tabs}// ~ optimization ENABLED ~ \${$tmp_name}keys was inlined
{$tabs}// loop variables - inline value
{$tabs}\${$tmp_name}val = ({$vars_var}) . ''; // convert to string
{$tabs}\${$tmp_name}keys = array({$keys});
{$tabs}\${$tmp_name}key_last = end(\${$tmp_name}keys);
{$tabs}
{$tabs}// loop
{$tabs}foreach(\${$tmp_name}keys as \${$tmp_name}index => \${$tmp_name}key){
{$tabs}	\${$var_name}['loop'] = array(
{$tabs}		'index' => \${$tmp_name}index,
{$tabs}		'i' => \${$tmp_name}index,
{$tabs}		'key' => \${$tmp_name}key,
{$tabs}		'k' => \${$tmp_name}key,
{$tabs}		'value' => \${$tmp_name}val[\${$tmp_name}key],
{$tabs}		'v' => \${$tmp_name}val[\${$tmp_name}key],
{$tabs}		'first' => \${$tmp_name}key === \${$tmp_name}keys[0],
{$tabs}		'last' => \${$tmp_name}key === \${$tmp_name}key_last,
{$tabs}	);
{$tabs}	{$vars_key} = \${$tmp_name}key;
{$tabs}	{$vars_as} = \${$tmp_name}val[\${$tmp_name}key];
PHP;
				}
				else
				{
					return <<<PHP
{$tabs}// ~ optimization DISABLED ~ \${$tmp_name}keys could be inlined
{$tabs}// loop variables - inline value
{$tabs}\${$tmp_name}val = ({$vars_var}) . ''; // convert to string
{$tabs}\${$tmp_name}keys = \${$tmp_name}val
{$tabs}	? array_keys(range(0, strlen(\${$tmp_name}val) - 1))
{$tabs}	: array();
{$tabs}\${$tmp_name}key_last = end(\${$tmp_name}keys);
{$tabs}
{$tabs}// loop
{$tabs}foreach(\${$tmp_name}keys as \${$tmp_name}index => \${$tmp_name}key){
{$tabs}	\${$var_name}['loop'] = array(
{$tabs}		'index' => \${$tmp_name}index,
{$tabs}		'i' => \${$tmp_name}index,
{$tabs}		'key' => \${$tmp_name}key,
{$tabs}		'k' => \${$tmp_name}key,
{$tabs}		'value' => \${$tmp_name}val[\${$tmp_name}key],
{$tabs}		'v' => \${$tmp_name}val[\${$tmp_name}key],
{$tabs}		'first' => \${$tmp_name}key === \${$tmp_name}keys[0],
{$tabs}		'last' => \${$tmp_name}key === \${$tmp_name}key_last,
{$tabs}	);
{$tabs}	{$vars_key} = \${$tmp_name}key;
{$tabs}	{$vars_as} = \${$tmp_name}val[\${$tmp_name}key];
PHP;
				}
			},
			'while' => function($data)use(&$replacement, &$brackets, &$tabs){
				++$brackets;
				
				return $tabs . 'while(' . self::parse_boolean($data) . '){';
			},
			'for' => function($data)use(&$replacement, &$brackets, &$tabs){
				++$brackets;
				
				return preg_replace_callback(
					'@(?<var>' . self::$regex['var'] . ')?\s*(?:from\s*(?<start>' . self::$regex['var_value'] . '))?(?:\s*to\s*(?<end>' . self::$regex['var_value'] . '))(?:\s*step\s*(?<step>' . self::$regex['var_value'] . '))?@',
					function($matches)use(&$replacement, &$brackets, &$tabs){
					
						$values = array(
							'start' => isset($matches['start']) && $matches['start'] !== '' ? self::parse_value($matches['start']) : '0',
							'end' => isset($matches['end']) ? self::parse_value($matches['end']) : self::parse_value($matches['start']),
							'step' => isset($matches['step']) ? self::parse_value($matches['step']) : '1'
						);
						
						$return = $tabs . 'foreach(';
						
						if(self::is_value($values['start']) && self::is_value($values['end']) && self::is_value($values['step']))
						{
							if($this->options['optimize'])
							{
								$return = "{$tabs}// ~ optimization enabled ~ inlining the results\r\n{$return}" . self::format_code(
									var_export(
										range(
											preg_replace('@^"|"$@', '', $values['start']),
											preg_replace('@^"|"$@', '', $values['end']),
											abs($values['step'])
										),
										true
									),
									$tabs . "\t",
									true
								);
							}
							else
							{
								$return = "{$tabs}// ~ optimization DISABLED ~ results could be inlined\r\n{$return}range({$values['start']}, {$values['end']}, abs({$values['step']}))";
							}
						}
						else
						{
							$return .= 'range(' . $values['start'] . ', ' . $values['end'] . ', abs(' . $values['step'] . '))';
						}
						
						return $return . ' as ' . self::render_var(isset($matches['var']) ? $matches['var'] : '', false) . '){';
					},
					$data
				);
			},
			'do' => function($data)use(&$replacement, &$brackets, &$tabs){
				++$brackets;
				
				return $tabs . 'do{';
			},
			'until' => function($data)use(&$replacement, &$brackets, &$tabs){
				--$brackets;
				
				return substr($tabs, 1) . '}while(!(' . self::parse_boolean($data) . '));';
			},
			'set' => function($data)use(&$replacement, &$brackets, &$tabs){
				preg_match('@^\s*(?<op>[\+\-\*\\\/\%])?\s*(?<var>' . self::$regex['var'] . ')\s*(?:(?<op_val>' . self::$regex['var_value'] . ')\s)?\s*(?<values>.*)$@', $data, $bits);
				
				$values = self::parse_values($bits['values']);
				$count = count($values);
				
				$return = $tabs . self::render_var($bits['var'], false) . ' = ';
				
				$close = 0;
				
				if(isset($bits['op']))
				{
					switch($bits['op'])
					{
						case '-':
							$return .= <<<PHP
call_user_func_array(function(){
{$tabs}	\$args = func_get_args();
{$tabs}	\$initial = array_shift(\$args);
{$tabs}	return array_reduce(\$args, function(\$carry, \$value){
{$tabs}		return \$carry - \$value;
{$tabs}	}, \$initial);
{$tabs}}, SimpleTemplate_FN::call('array_flat', 
PHP;
							$close = 2;
							break;
						case '+':
							$return .= 'array_sum(SimpleTemplate_FN::call(\'array_flat\', ';
							$close = 2;
							break;
						case '*':
							$return .= 'array_product(SimpleTemplate_FN::call(\'array_flat\', ';
							$close = 2;
							break;
						case '\\':
						case '/':
						case '%':
							$ops = array(
								'\\' => 'round(%s / $value)',
								'/' => '(%s / $value)',
								'%' => '(%s %% $value)'
							);
							
							$return .= 'array_map(function($value)use(&$' . self::$var_name . '){'
								.'return ' . sprintf(
									$ops[$bits['op']],
									isset($bits['op_val'])
										? self::parse_value($bits['op_val'])
										: self::render_var($bits['var'], false)
								)
								. ';}, SimpleTemplate_FN::call(\'array_flat\', ';
							$close = 2;
							break;
					}
				}
				
				if($count > 1)
				{
					$return .= 'array(' . implode(',', $values) . ')';
				}
				else
				{
					$return .= ($count && strlen($values[0]) ? $values[0] : 'null');
				}
				
				return $return . str_repeat(')', $close) . ';';
			},
			'unset' => function($data)use(&$replacement, &$brackets, &$tabs){
				$values = self::parse_values($data, null, false);
				$vars = array_filter($values, function($var){
					return !self::is_value($var);
				});
				
				$return = $tabs . (
					count($values) !== count($vars)
						? '// Warning: invalid values were passed' . PHP_EOL . $tabs
						: ''
				);
				
				return $return . (
					$vars
						? 'unset(' . implode(',', $vars) . ');'
						: '// Warning: no values were passed or all were filtered out'
				);
			},
			'global' => function($data)use(&$replacement, &$brackets, &$tabs){
				$data = self::split_values($data, ' ');
				
				return $tabs . self::render_var(array_shift($data), false) . ' = $GLOBALS[\'' . join('\'][\'', $data) . '\'];';
			},
			'call' => function($data)use(&$replacement, &$brackets, &$tabs){
				preg_match('@^\s*(?<fn>' . self::$regex['var'] . ')\s*(?:into\s*(?<into>' . self::$regex['var'] . ')\s*)?(?<args>.*?)$@', $data, $bits);
				
				$var = self::render_var($bits['fn'], false);
				
				$into = $bits['into'] ? self::render_var($bits['into'], false) . ' = ' : '';
				$args = implode(', ', self::parse_values($bits['args']));
				$alt_name = str_replace('.', '_', $bits['fn']);
				
				return <<<PHP
{$tabs}{$into}call_user_func_array(
{$tabs}	(isset({$var}) && is_callable({$var})
{$tabs}		? {$var}
{$tabs}		: (SimpleTemplate_FN::method_exists('{$bits['fn']}')
{$tabs}			? SimpleTemplate_FN::call('{$bits['fn']}')
{$tabs}			: '{$alt_name}'
{$tabs}		)
{$tabs}	),
{$tabs}	array({$args})
{$tabs});
PHP;
			},
			'php' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $tabs . 'call_user_func_array(function(&$' . self::$var_name . '){' . PHP_EOL
					   . "{$tabs}\t{$data};" . PHP_EOL
					   . $tabs . '}, array(&$' . self::$var_name . '));';
			},
			'return' => function($data)use(&$replacement, &$brackets, &$tabs){
				return $tabs . 'return ' . ($data ? self::parse_value($data): '').';';
			},
			'inc' => function($data)use(&$replacement, &$brackets, &$tabs){
				preg_match('@^(?:\s*by\s*(?<by>' . self::$regex['var_value'] . ')\s*)?(?<values>.*?)$@', $data, $bits);
				$values = self::parse_values($bits['values'], '\s*,\s*', false);
				$inc = isset($bits['by']) && $bits['by'] !== '' ? self::parse_value($bits['by']): '1';
				
				$return = '';
				
				if(!$inc || $inc === '"0"' || $inc === 'null' || $inc === 'false')
				{
					if($this->options['optimize'])
					{
						return "{$tabs}// ~ optimization enabled ~ increment by {$inc} removed";
					}
					else
					{
						$return .= "{$tabs}// ~ optimization DISABLED ~ increment by {$inc} could be removed" . PHP_EOL;
					}
				}
				
				$var_name = self::$var_name;
				
				foreach($values as $value)
				{
					if(!isset($value[0]) && !self::is_value($value[0]))
					{
						continue;
					}
					
					$return .= "{$tabs}{$value} = SimpleTemplate_FN::call('inc', isset({$value})?{$value}:0, {$inc});" . PHP_EOL;
				}
				
				return $return;
				
			},
			'fn' => function($data)use(&$replacement, &$brackets, &$tabs){
				if(
					preg_match(
						'@^\s*(' . self::$regex['var_simple'] . ')(?:\s+(' . self::$regex['var_name'] . '(?:,\s*' . self::$regex['var_name'] . ')*))?$@',
						$data, $bits
					) === false
				)
				{
					return '';
				}
				
				++$brackets;
				
				$version = SimpleTemplate::version();
				$var_name = self::$var_name;
				
				$return = $tabs . self::render_var($bits ? $bits[1] : null, false) . <<<PHP
= function()use(&\$_){
{$tabs}	\${$var_name} = array(
{$tabs}		'argv' => func_get_args(),
{$tabs}		'argc' => func_num_args(),
{$tabs}		'VERSION' => '{$version}',
{$tabs}		'EOL' => PHP_EOL,
{$tabs}		'PARENT' => &\$_
{$tabs}	);
{$tabs}	\$_ = &\${$var_name};

PHP;
				if(isset($bits[2]) && $bits[2])
				{
					$args = array();
					foreach(self::split_values($bits[2]) as $value)
					{
						if(!self::is_value(self::parse_value($value)))
						{
							$args[] = $value;
						}
					}
					
					foreach($args as $k => $arg)
					{
						$return .= "{$tabs}	\${$var_name}[\"{$arg}\"] = &\${$var_name}[\"argv\"][{$k}];" . PHP_EOL;
					}
				}
				
				return $return;
			},
			'eval' => function($data)use(&$replacement, &$brackets, &$tabs){
				$return = '';
				$value = self::parse_value($data);
				
				if($this->options['optimize'] && self::is_value($value))
				{
					$return = $tabs . '// ~ optimization enabled ~ trying to avoid compiling in runtime' . PHP_EOL;
					
					static $cached = array();
					
					$sha1 = sha1($value);
					
					if(isset($cached[$sha1]))
					{
						$return .= $tabs . '// {@eval} cached code found: cache entry ';
					}
					else
					{
						$return .= $tabs . '// {@eval} no cached code found: creating entry ';
						
						$compiler = new SimpleTemplate_Compiler($this->template, stripslashes(trim($value, '"')), $this->options);
						
						$cached[$sha1] = self::format_code($compiler->getPHP() . '// {@eval} ended', $tabs);
						
						unset($compiler);
					}
					
					$return .= $sha1 . PHP_EOL . $cached[$sha1];
				}
				else
				{
					$options = self::format_code(var_export($this->options, true), $tabs . "\t\t", true);
					
					$return = <<<PHP
{$tabs}// ~ optimization DISABLED or unfeasable ~ compilation in runtime is required
{$tabs}call_user_func_array(function()use(&\$DATA){
{$tabs}	\$compiler = new SimpleTemplate_Compiler(\$this, {$value}, {$options});
{$tabs}	\$fn = \$compiler->getFN();
{$tabs}	return \$fn(\$DATA);
{$tabs}}, array());
PHP;
				}
				
				return $return;
			}
		);
		
		$trim_fn = $this->options['trim'] ? 'trim' : '';
		
		$this->php .= "\r\necho {$trim_fn}(<<<'" . self::$var_name . "{$UUID}'\r\n"
			. preg_replace_callback(
				// http://stackoverflow.com/a/6464500
				'~{@(eval|echoj?l?|print|if|else|for|while|each|do|until|(?:un)?set|call|global|php|return|inc|fn|//?)(?:\\s*(.*?))?}(?=(?:[^"\\\\]*(?:\\\\.|"(?:[^"\\\\]*\\\\.)*[^"\\\\]*"))*[^"]*$)~',
				function($matches)use(&$replacement, &$brackets, &$tabs, &$UUID, &$trim_fn){
					
					$tabs = $brackets
						? str_repeat("\t", $brackets - ($matches[1] === '/'))
						: '';
					
					$var_name = self::$var_name;
					
					$php = $replacement[$matches[1]](isset($matches[2]) ? $matches[2] : null);
					
					return "\r\n{$var_name}{$UUID}\r\n);\r\n{$tabs}// {$matches[0]}\r\n{$php}\r\n\r\n{$tabs}echo {$trim_fn}(<<<'{$var_name}{$UUID}'\r\n";
				},
				$str . ''
			)
			. "\r\n" . self::$var_name . "{$UUID}\r\n);\r\n";
		
		$this->php = preg_replace(
			array(
				'@\r\n\t*echo\s*' . $trim_fn . '\(<<<\'' . self::$var_name . $UUID . '\'(?:\s*\r\n)?' . self::$var_name . $UUID . '\r\n\);@',
				'@\r\n' . self::$var_name . $UUID . '\r\n\);(\r\n)*\t*echo\s*' . $trim_fn . '\(<<<\'' . self::$var_name . $UUID . '\'@'
			),
			array(
				'', ''
			),
			$this->php
		);
		
		if($brackets)
		{
			$this->php .=  "\r\n// AUTO-CLOSE\r\n" . str_repeat('};', $brackets);
		}
	}
	
	function getPHP(){
		return $this->php;
	}
	
	function getFN(){
		if(!$this->fn)
		{
			$this->fn = eval('return function(&$' . self::$var_name . '){'
					. PHP_EOL . $this->php . PHP_EOL
				. '};'
			);
			
			$this->fn = $this->fn->bindTo($this->template);
		}
		
		return $this->fn;
	}
	
	function __construct(SimpleTemplate $template, $code, array $options = array()){
		$this->options = $options;
		$this->template = $template;
		
		// ALMOST unguessable name, to avoid syntax errors
		$this->uuid = str_shuffle(mt_rand() . time() . sha1($code));
		
		$this->compile($code);
	}
}

// base class
class SimpleTemplate {
	private static $version = '0.82';
	
	private $data = array();
	private $settings = array(
		'optimize' => true,
		'trim' => false
	);
	
	private $compiler = null;
	
	function __construct($code, array $options = array()){
		if(!$code)
		{
			throw new Exception('No code was provided');
		}
		
		if($options)
		{
			$this->settings = array_merge($this->settings, $options);
		}
		
		$this->compiler = new SimpleTemplate_Compiler($this, $code, $this->settings);
	}
	
	function setData($key, $value){
		$this->data[$key] = $value;
	}
	
	function getData($key, $value){
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}
	
	function unsetData($key){
		unset($this->data[$key]);
	}
	
	function loadData($data){
		foreach($data as $k => $value)
		{
			$this->data[$k] = $value;
		}
	}
	
	function clearData(){
		$this->data = array();
	}
	
	function getPHP(){
		return $this->compiler->getPHP();
	}
	
	function render(){
		$this->data['argv'] = func_get_args();
		$this->data['argc'] = func_num_args();
		
		$this->data['VERSION'] = self::$version;
		$this->data['EOL'] = PHP_EOL;
		
		$fn = $this->compiler->getFN();
		
		return $fn($this->data);
	}
	
	static function fromFile($path, array $options = array()){
		return new self(file_get_contents($path), $options);
	}
	
	static function fromString($string, array $options = array()){
		return new self($string, $options);
	}
	
	static function version(){
		return self::$version;
	}
}
