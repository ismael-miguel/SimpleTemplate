<?php

	class SimpleTemplate {
		
		private static $version = '0.1';
		
		private static $var_name = '';
		private static $regex = array(
			'var' => '(?:\w*(?:\.\w*)?)',
			'value' => '(?:(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)',
			'var_value' => '(?:\w*(?:\.\w*)?|(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)'
		);
		
		private $data = array();
		
		private $php = '';
		
		private static function render_var($name = null, $safe = true){
			$var = '$' . self::$var_name . ($name ? '[\'' . join('\'][\'', explode('.', $name)) . '\']' : '');
			
			return $safe ? '(isset(' . $var . ')?' . $var . ':null)' : $var;
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
					'@(' . self::$regex['var_value'] . ')\s*(?:(is(?:(?:\s*not|n\'?t)?\s*(?:(?:greater|lower)(?:\s*than)?|equal(?:\s*to)?))?|has(?:\s*not)?)\s*(' . self::$regex['var_value'] . '))?@',
					$data, $bits
				)
			)
			{
				return '';
			}
			
			$fn = array(
				'is' => function($data, $var1, $var2){
					$symbols = array(
						'' => '===',
						'equal' => '==',
						'lower' => '<',
						'greater' => '>'
					);
					
					preg_match('@(?<not>not)?\s*(?<operation>equal|lower|greater)?\s*(?:to|than)?@', $data, $bits);
					
					return (isset($bits['not']) && $bits['not'] !== '' ? '!': '') . '(' . $var1 . ' ' . $symbols[isset($bits['operation']) ? $bits['operation']: ''] . ' ' . $var2 . ')';
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
		
		function __construct($str){
		
			if(!self::$var_name)
			{
				self::$var_name = 'DATA' . mt_rand() . time();
			}
			
			// http://stackoverflow.com/a/1320156
			$this->php = <<<'PHP'

// - FUNCTION BOILERPLATE
$FN = array(
	'array_flat' => function(array $array) {
		$r = array();
		array_walk_recursive($array, function($a)use(&$r){$r[]=$a;});
		return $r;
	},
	'inc' => function(&$_, $by = 1){
		switch(gettype($_))
		{
			case 'integer':
			case 'double':
				$_ += $by;
				break;
			case 'string':
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
			
			$brackets = 0;
			
			$replacement = array(
				'/' => function()use(&$brackets){
					if($brackets > 0)
					{
						--$brackets;
						
						return '};';
					}
					else
					{
						return ';';
					}
				},
				'//' => function(){
					return '';
				},
				'echo' => function($data){
					preg_match('@^(?:separator\s+(?<separator>' . self::$regex['var_value'] . ')\s+)?(?<data>.*)$@', $data, $bits);
					
					$separator = $bits['separator'] ? self::parse_value($bits['separator']): '\'\'';
					
					return 'echo implode(' . $separator . ', $FN[\'array_flat\']((array)' . implode(')), implode(' . $separator . ', $FN[\'array_flat\']((array)', self::parse_values($bits['data'])) . '));';
				},
				'echol' => function($data)use(&$replacement){
					return $replacement['echo']($data) . 'echo PHP_EOL;';
				},
				'echoj' => function($data)use(&$replacement){
					return $replacement['echo']('separator ' . $data);
				},
				'echojl' => function($data)use(&$replacement){
					return $replacement['echol']('separator ' . $data);
				},
				'echof' => function($data)use(&$replacement){
					return $replacement['echol']('separator ' . $data);
				},
				'print' => function($data)use(&$replacement){
					return $replacement['call']((strpos('into', $data)===0? 's' : '') . 'printf ' . $data);
				},
				'if' => function($data)use(&$brackets){
					++$brackets;
					
					return 'if(' . self::parse_boolean($data) . '){';
				},
				'else' => function($data)use(&$brackets){
					$data = explode(' ', $data, 2);
					
					if($data[0] === 'if')
					{
						--$brackets;
					
						return '}else ' . $replacement['if']($data[1]);
					}
					else
					{
						return '}else{';
					}
				},
				'each' => function($data)use(&$brackets){
					++$brackets;
					
					preg_match('@^(?<var>' . self::$regex['var'] . ')\s*as\s*(?<as>' . self::$regex['var'] . ')(?:\s*key\s*(?<key>' . self::$regex['var'] . ')\s*)?$@', $data, $bits);
					
					return 'foreach((array)' . self::render_var($bits['var']) . ' as ' . (isset($bits['key']) ? self::render_var($bits['key'], false) . ' => ': '') . self::render_var($bits['as'], false) . '){';
				},
				'while' => function($data)use(&$brackets){
					++$brackets;
					
					return 'while(' . self::parse_boolean($data) . '){';
				},
				'for' => function($data)use(&$brackets){
					++$brackets;
					
					return preg_replace_callback(
						'@(?<var>' . self::$regex['var'] . ')(?:\s*from\s*(?<start>' . self::$regex['var_value'] . '))?(?:\s*to\s*(?<end>' . self::$regex['var_value'] . '))(?:\s*step\s*(?<step>' . self::$regex['var_value'] . '))?@',
						function($matches){
						
							$values = array(
								'start' => isset($matches['start']) && $matches['start'] !== '' ? self::parse_value($matches['start']) : '0',
								'end' => isset($matches['end']) ? self::parse_value($matches['end']) : self::parse_value($matches['start']),
								'step' => isset($matches['step']) ? self::parse_value($matches['step']) : '1'
							);
							
							return 'foreach(range(' . $values['start'] . ', ' . $values['end'] . ', abs(' . $values['step'] . ')) as ' . self::render_var($matches['var'], false) . '){';
						},
						$data
					);
				},
				'set' => function($data){
					preg_match('@^(?<var>' . self::$regex['var'] . ')\s*(?<values>.*?)$@', $data, $bits);
					
					$values = self::parse_values($bits['values']);
					
					if(count($values) > 1)
					{
						return self::render_var($bits['var'], false) . ' = array(' . implode(',', $values) . ');';
					}
					else
					{
						return self::render_var($bits['var'], false) . ' = ' . self::parse_value($bits['values']) . ';';
					}
				},
				'global' => function($data){
					$data = self::split_values($data, ' ');
					
					return self::render_var(array_shift($data)) . ' = $GLOBALS[\'' . join('\'][\'', $data) . '\'];';
				},
				'call' => function($data){
					preg_match('@^\s*(?<fn>' . self::$regex['var'] . ')\s*(?:into\s*(?<into>' . self::$regex['var'] . ')\s*)?(?<args>.*?)$@', $data, $bits);
					
					//return ($data['into'] ? self::render_var($bits['into'], false) . ' = ' : '') . $bits['fn'] . '(' . implode(',', self::parse_values($bits['args'])) . ');';
					
					$var = self::render_var($bits['fn'], false);
					
					return ($bits['into'] ? self::render_var($bits['into'], false) . ' = ' : '')
						. 'call_user_func_array('
							. 'isset(' . $var . ') && gettype(' . $var . ') === \'object\' && ' . $var . ' instanceof \Closure'
								. '? ' . $var . ' : "' . addslashes($bits['fn']) . '", '
							. 'array(' . implode(',', self::parse_values($bits['args'])) . '));';
				},
				'php' => function($data){
					return '?><?php ' . $data . ';';
				},
				'return' => function($data){
					return 'return ' . ($data ? self::parse_value($data): '').';';
				},
				'inc' => function($data){
					preg_match('@^(?:\s*by\s*(?<by>' . self::$regex['var_value'] . ')\s*)?(?<values>.*?)$@', $data, $bits);
					$values = self::parse_values($bits['values'], '\s*,\s*', false);
					$inc = isset($bits['by']) && $bits['by'] !== '' ? self::parse_value($bits['by']): '1';
					$return = '';
					
					foreach($values as $value)
					{
						if(!isset($value[0]) || $value[0] !== '$')
						{
							continue;
						}
						
						$return .= <<<PHP
if(gettype({$value})==='array')
{
	array_walk_recursive({$value}, function(&\$_)use(&\$FN){
		return \$FN['inc'](\$_, {$inc});
	});
}
else
{
	\$FN['inc']({$value}, {$inc});
}

PHP;
					}
					
					return $return;
					
				},
				'fn' => function($data)use(&$brackets){
					++$brackets;
					
				    $version = self::$version;
				    $var_name = self::$var_name;
					
					return self::render_var($data, false) . <<<PHP
 = function(){
	\$DATA = array(
		'argv' => func_get_args(),
		'argc' => func_num_args(),
		'VERSION' => '{$version}',
		'EOL' => PHP_EOL
	);
	\${$var_name} = &\$DATA;

PHP;
				}
			);
			
			$this->php .= str_replace(
				array(
					"echo <<<'" . self::$var_name . "'\r\n\r\n" . self::$var_name . ";",
					"\r\n" . self::$var_name . ";\r\necho <<<'" . self::$var_name . "'"
				),
				array('', ''),
				"\r\necho <<<'" . self::$var_name . "'\r\n"
					. preg_replace_callback(
						// http://stackoverflow.com/a/6464500
						'~{@(echoj?l?|print|if|else|for|while|each|set|call|global|php|return|inc|fn|//?)(?:\\s*(.*?))?}(?=(?:[^"\\\\]*(?:\\\\.|"(?:[^"\\\\]*\\\\.)*[^"\\\\]*"))*[^"]*$)~i',
						function($matches)use(&$replacement, &$brackets){
							$php = $replacement[$matches[1]](isset($matches[2]) ? $matches[2] : null);
							$tabs = $brackets > ($matches[1] == 'fn')
								? str_repeat("\t", $brackets)
								: '';
							
							return "\r\n" . self::$var_name . ";\r\n"
								. "// {$matches[0]}\r\n"
								. (
									$brackets > 1
										? $tabs . preg_replace('@\r?\n(?!\t|\r?\n) *@', '$1' . $tabs, $php)
										: $php
								)
								. "\r\n\r\necho <<<'" . self::$var_name . "'\r\n";
						},
						$str . ''
					)
					. "\r\n" . self::$var_name . ";\r\n"
				)
				. ($brackets
					? "\r\n// AUTO-CLOSE\r\n" . str_repeat('};', $brackets)
					: ''
				)
				. "// - END CODE -";
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
				. '$' . self::$var_name . ' = &$DATA;'
				. PHP_EOL
				. '// - END DATA BOILERPLATE -'
				. PHP_EOL
				. $this->php;
		}
		
		function render(){
			$fn = eval('return function(){' . call_user_func_array(array($this, 'getPHP'), func_get_args()) . '};');
			return $fn();
		}
		
		static function fromFile($path){
			return new self(file_get_contents($path));
		}
		
		static function fromString($string){
			return new self($string);
		}
	}
