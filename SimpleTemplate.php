<?php

	class SimpleTemplate {
		
		private static $version = '0.2';
		
		private static $var_name = 'DATA';
		
		private static $regex = array(
			'var' => '(?:(?:(?:U|unsafe)\s+)?[_a-zA-Z]\w*(?:\.\w*)?)',
			'value' => '(?:(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)',
			'var_value' => '(?:(?:(?:U|unsafe)\s+)?[_a-zA-Z]\w*(?:\.\w*)?|(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)'
		);
		
		private $data = array();
		
		private $optimize = true;
		
		// array_flat -> http://stackoverflow.com/a/1320156
		private $php = <<<'PHP'
// - FUNCTION BOILERPLATE
$FN = array(
	'array_flat' => function(array $array) {
		$return = array();
		array_walk_recursive($array, function($value)use(&$return){
			$return[] = $value;
		});
		return $return;
	},
	'inc' => function(&$_, $by = 1){
		if(!$by)
		{
			return $_;
		}
		
		switch(gettype($_))
		{
			case 'NULL':
			case 'null':
				$_ = $by;
				break;
			case 'integer':
			case 'double':
			case 'float':
				$_ += $by;
				break;
			case 'string':
				if($_ === '')
				{
					break;
				}
				
				for($i = 0; $i < $by; $i++)
				{
					++$_;
				}
				break;
		}
	}
);
// - END FUNCTION BOILERPLATE -

// - CODE
PHP;

		private static function render_var($name = null, $safe = true){
			preg_match('@^\s*(?:(?<unsafe>U|unsafe)\s+)?(?<var>.*)$@', $name, $bits);
			
			$var = '$' . self::$var_name . ($bits['var'] ? '[\'' . join('\'][\'', explode('.', $bits['var'])) . '\']' : '');
			
			return $safe && !$bits['unsafe'] ? '(isset(' . $var . ')?' . $var . ':null)' : $var;
		}
		
		private static function split_values($values, $delimiter = '\s*,\s*'){
			return preg_split('@(' . $delimiter . ')(?=(?:[^"]|"[^"]*")*$)@', $values);
		}
		
		private static function parse_values($values, $delimiter = '\s*,\s*', $safe = true){
			$value_bits = self::split_values($values, $delimiter);
			
			foreach($value_bits as $k => $value)
			{
				$value_bits[$k] = self::parse_value($value, $safe);
			}
			
			return $value_bits;
		}
		
		private static function parse_boolean($data){
			if(
				!preg_match(
					'@(' . self::$regex['var_value'] . ')\s*(?:(is(?:(?:\s*not|n\'?t)?\s*(?:(?:greater|lower)(?:\s*than)?|equal(?:\s*to)?|equal|a|(?:(?:instance|multiple|mod)(?:\s*of)?)|matches))?|has(?:\s*not)?)\s*(' . self::$regex['var_value'] . '))?@',
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
						'matches' => 'preg_match(%s, %s)'
					);
					
					preg_match('@(?<not>not)?\s*(?<operation>equal|lower|greater|a|instance|multiple|mod|matches)?\s*(?:of|to|than)?@', $data, $bits);
					
					return (isset($bits['not']) && $bits['not'] !== '' ? '!': '') . '(' . sprintf($symbols[isset($bits['operation']) ? $bits['operation']: ''], $var1, $var2) . ')';
				},
				'has' => function($data, $var1, $var2){
					return ($data === 'not' ? '!': '') . 'array_key_exists((array)' . $var1 . ', ' . $var2 . ')';
				}
			);
			
			if(isset($bits[3]))
			{
				$ops = explode(' ', $bits[2], 2);
				
				return $fn[$ops[0]](isset($ops[1]) ? $ops[1]: '', self::parse_value($bits[1]), self::parse_value($bits[3]));
			}
			else
			{
				return self::parse_value($bits[1]);
			}
		}
		
		private static function parse_value($value, $safe = true){
			return preg_match('@^' . self::$regex['value'] . '$@', $value) ? $value : self::render_var($value, $safe);
		}
		
		private static function is_value($value){
			return strlen($value) && $value[0] !== '$' && $value[0] !== '(';
		}
		
		function __construct($str, $optimize = true){
			
			$brackets = 0;
			$tabs = '';
			
			$this->optimize = !!$optimize;
			
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
					preg_match('@^(?:separator\s+(?<separator>' . self::$regex['var_value'] . ')\s+)?(?<data>.*)$@', $data, $bits);
					
					$separator = $bits['separator'] ? self::parse_value($bits['separator']): '\'\'';
					
					return $tabs . 'echo implode(' . $separator . ', $FN[\'array_flat\']((array)' . implode(',(array)', self::parse_values($bits['data'])) . '));';
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
					return $replacement['call']((strpos('into', $data)===0? 's' : '') . 'printf ' . $data);
				},
				'if' => function($data)use(&$replacement, &$brackets, &$tabs){
					++$brackets;
					
					return $tabs . 'if(' . self::parse_boolean($data) . '){';
				},
				'else' => function($data)use(&$replacement, &$brackets, &$tabs){
					$data = explode(' ', $data, 2);
					
					if($data[0] === 'if')
					{
						--$brackets;
					
						return $tabs . '}else ' . $replacement['if']($data[1]);
					}
					else
					{
						return $tabs . '}else{';
					}
				},
				'each' => function($data)use(&$replacement, &$brackets, &$tabs){
					++$brackets;
					
					preg_match('@^(?<var>' . self::$regex['var'] . ')\s*as\s*(?<as>' . self::$regex['var'] . ')(?:\s*key\s*(?<key>' . self::$regex['var'] . ')\s*)?$@', $data, $bits);
					
					return $tabs . 'foreach((array)' . self::render_var($bits['var']) . ' as ' . (isset($bits['key']) ? self::render_var($bits['key'], false) . ' => ': '') . self::render_var($bits['as'], false) . '){';
				},
				'while' => function($data)use(&$replacement, &$brackets, &$tabs){
					++$brackets;
					
					return $tabs . 'while(' . self::parse_boolean($data) . '){';
				},
				'for' => function($data)use(&$replacement, &$brackets, &$tabs){
					++$brackets;
					
					return preg_replace_callback(
						'@(?<var>' . self::$regex['var'] . ')(?:\s*from\s*(?<start>' . self::$regex['var_value'] . '))?(?:\s*to\s*(?<end>' . self::$regex['var_value'] . '))(?:\s*step\s*(?<step>' . self::$regex['var_value'] . '))?@',
						function($matches)use(&$replacement, &$brackets, &$tabs){
						
							$values = array(
								'start' => isset($matches['start']) && $matches['start'] !== '' ? self::parse_value($matches['start']) : '0',
								'end' => isset($matches['end']) ? self::parse_value($matches['end']) : self::parse_value($matches['start']),
								'step' => isset($matches['step']) ? self::parse_value($matches['step']) : '1'
							);
							
							$return = $tabs . 'foreach(';
							
							if(self::is_value($values['start']) && self::is_value($values['end']) && self::is_value($values['step']))
							{
								if($this->optimize)
								{
									$return = "{$tabs}// ~ optimization enabled ~ inlining the results\r\n{$return}" . var_export(
										range(
											preg_replace('@^"|"$@', '', $values['start']),
											preg_replace('@^"|"$@', '', $values['end']),
											abs($values['step'])
										),
										true
									);
								}
								else
								{
								$return = "{$tabs}// ~ optimization DISABLE ~ results could be inlined\r\n{$return}range({$values['start']}, {$values['end']}, abs({$values['step']}))";
								}
							}
							else
							{
								$return .= 'range(' . $values['start'] . ', ' . $values['end'] . ', abs(' . $values['step'] . '))';
							}
							
							return $return . ' as ' . self::render_var($matches['var'], false) . '){';
						},
						$data
					);
				},
				'set' => function($data)use(&$replacement, &$brackets, &$tabs){
					preg_match('@^(?<var>' . self::$regex['var'] . ')\s*(?<values>.*)$@', $data, $bits);
					
					$values = self::parse_values($bits['values']);
					$count = count($values);
					
					if($count > 1)
					{
						return $tabs . self::render_var($bits['var'], false) . ' = array(' . implode(',', $values) . ');';
					}
					else
					{
						return $tabs . self::render_var($bits['var'], false) . ' = ' . ($count && strlen($values[0]) ? $values[0] : 'null') . ';';
					}
				},
				'global' => function($data)use(&$replacement, &$brackets, &$tabs){
					$data = self::split_values($data, ' ');
					
					return $tabs . self::render_var(array_shift($data)) . ' = $GLOBALS[\'' . join('\'][\'', $data) . '\'];';
				},
				'call' => function($data)use(&$replacement, &$brackets, &$tabs){
					preg_match('@^\s*(?<fn>' . self::$regex['var'] . ')\s*(?:into\s*(?<into>' . self::$regex['var'] . ')\s*)?(?<args>.*?)$@', $data, $bits);
					
					$var = self::render_var($bits['fn'], false);
					
					return $tabs . ($bits['into'] ? self::render_var($bits['into'], false) . ' = ' : '')
						. 'call_user_func_array('
							. 'isset(' . $var . ') && is_callable(' . $var . ')'
								. '? ' . $var . ' : "' . str_replace('.', '_', $bits['fn']) . '", '
							. 'array(' . implode(',', self::parse_values($bits['args'])) . '));';
				},
				'php' => function($data)use(&$replacement, &$brackets, &$tabs){
					return $tabs . $data . ';';
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
						if($this->optimize)
						{
							return "{$tabs}// ~ optimization enabled ~ increment by {$inc} removed";
						}
						else
						{
							$return .= "{$tabs}// ~ optimization DISABLED ~ increment by {$inc} could be removed\r\n";
						}
					}
					
					$var_name = self::$var_name;
					
					foreach($values as $value)
					{
						if(!isset($value[0]) || $value[0] !== '$')
						{
							continue;
						}
						
						$return .= <<<PHP
{$tabs}if(gettype({$value})==='array')
{$tabs}{
{$tabs}	array_walk_recursive({$value}, function(&\$value)use(&\$FN, &\${$var_name}){
{$tabs}		\$FN['inc'](\$value, {$inc});
{$tabs}	});
{$tabs}}
{$tabs}else
{$tabs}{
{$tabs}	\$FN['inc']({$value}, {$inc});
{$tabs}}

PHP;
					}
					
					return $return;
					
				},
				'fn' => function($data)use(&$replacement, &$brackets, &$tabs){
					++$brackets;
					
				    $version = self::$version;
				    $var_name = self::$var_name;
					
					return $tabs . self::render_var($data, false) . <<<PHP
 = function()use(&\$FN, &\$_){
{$tabs}	\${$var_name} = array(
{$tabs}		'argv' => func_get_args(),
{$tabs}		'argc' => func_num_args(),
{$tabs}		'VERSION' => '{$version}',
{$tabs}		'EOL' => PHP_EOL,
{$tabs}		'PARENT' => &\$_
{$tabs}	);
{$tabs}	\$_ = &\${$var_name};

PHP;
				}
			);
				
			$this->php .= "\r\necho trim(<<<'" . self::$var_name . "'\r\n"
				. preg_replace_callback(
					// http://stackoverflow.com/a/6464500
					'~{@(echoj?l?|print|if|else|for|while|each|set|call|global|php|return|inc|fn|//?)(?:\\s*(.*?))?}(?=(?:[^"\\\\]*(?:\\\\.|"(?:[^"\\\\]*\\\\.)*[^"\\\\]*"))*[^"]*$)~i',
					function($matches)use(&$replacement, &$brackets, &$tabs){
						
						$tabs = $brackets
							? str_repeat("\t", $brackets - ($matches[1] === '/'))
							: '';
						
						$var_name = self::$var_name;
						
						$php = $replacement[$matches[1]](isset($matches[2]) ? $matches[2] : null);
						
						
						return "\r\n{$var_name}\r\n);\r\n{$tabs}// {$matches[0]}\r\n{$php}\r\n\r\n{$tabs}echo trim(<<<'{$var_name}'\r\n";
					},
					$str . ''
				)
				. "\r\n" . self::$var_name . "\r\n);\r\n";
			
			$this->php = preg_replace(
				array(
					'@\r\n\t*echo\s*trim\(<<<\'' . self::$var_name . '\'(?:\s*\r\n)?' . self::$var_name . '\r\n\);@',
					'@\r\n' . self::$var_name . '\r\n\);(\r\n)*\t*echo\s*trim\(<<<\'' . self::$var_name . '\'@'
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
			
			$this->php .= PHP_EOL . '// - END CODE -';
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
			$this->data['argv'] = func_get_args();
			$this->data['argc'] = func_num_args();
			
			$this->data['VERSION'] = self::$version;
			$this->data['EOL'] = PHP_EOL;
			
			return '// - DATA BOILERPLATE'
				. PHP_EOL
				. '$DATA = ' . var_export($this->data, true) . ';'
				. PHP_EOL
				. '// - END DATA BOILERPLATE -'
				. PHP_EOL
				. PHP_EOL
				. $this->php;
		}
		
		function render(){
			$fn = eval('return function(){' . call_user_func_array(array($this, 'getPHP'), func_get_args()) . PHP_EOL . '};');
			return $fn();
		}
		
		static function fromFile($path){
			return new self(file_get_contents($path));
		}
		
		static function fromString($string){
			return new self($string);
		}
	}
