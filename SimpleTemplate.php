<?php

	class SimpleTemplate {
		
		private static $version = 0.1;
		
		private static $var_name = '';
		private static $regex = array(
			'var' => '(?:\w*(?:\.\w*)?)',
			'value' => '(?:(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)',
			'var_value' => '(?:\w*(?:\.\w*)?|(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)'
		);
		
		private $data = array();
		
		// http://stackoverflow.com/a/1320156
		private $php = '$array_flat = function(array $array) {
	$return = array();
	array_walk_recursive($array, function($a)use(&$return){$return[]=$a;});
	return $return;
};
';
		
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
			
			$brackets = 0;
			
			$replacement = array(
				'/' => function()use(&$brackets){
					if($brackets > 0)
					{
						--$brackets;
						
						return '}';
					}
					else
					{
						return '';
					}
				},
				'echo' => function($data){
					preg_match('@^(?:separator\s+(?<separator>' . self::$regex['var_value'] . ')\s+)?(?<data>.*)$@', $data, $bits);
					
					$separator = $bits['separator'] ? self::parse_value($bits['separator']): '\'\'';
					
					return 'echo implode(' . $separator . ', $array_flat((array)' . implode(')), implode(' . $separator . ', $array_flat((array)', self::parse_values($bits['data'])) . '));';
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
				'if' => function($data)use(&$brackets){
					++$brackets;
					
					return 'if(' . self::parse_boolean($data) . '){';
				},
				'else' => function($data)use(&$brackets, &$replacement){
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
					$bits = explode(' ', $data, 2);
					$values = self::parse_values($bits[1]);
					
					if(count($values) > 1)
					{
						return self::render_var($bits[0], false) . ' = array(' . implode(',', $values) . ');';
					}
					else
					{
						return self::render_var($bits[0], false) . ' = ' . self::parse_value($bits[1]) . ';';
					}
				},
				'global' => function($data){
					$data = self::split_values($data, ' ');
					
					return self::render_var(array_shift($data)) . ' = $GLOBALS[\'' . join('\'][\'', $data) . '\'];';
				},
				'call' => function($data){
					preg_match('@^\s*(?<fn>\w+)\s*(?:into\s*(?<into>' . self::$regex['var'] . ')\s*)?(?<args>.*?)$@', $data, $data);
					
					return ($data['into'] ? self::render_var($data['into'], false) . ' = ' : '') . $data['fn'] . '(' . implode(',', self::parse_values($data['args'])) . ');';
				},
				'php' => function($data){
					return '?><?php ' . $data;
				},
				'return' => function($data){
					return 'return ' . ($data ? self::parse_value($data): '').';';
				},
				'inc' => function($data){
					preg_match('@^(?:\s*by\s*(?<by>' . self::$regex['var_value'] . ')\s*)?(?<values>.*?)$@', $data, $bits);
					$values = self::parse_values($bits['values'], '\s*,\s*', false);
					$inc = isset($bits['by']) && $bits['by'] !== '' ? self::parse_value($bits['by']): '1';
					$return = 'call_user_func_array(function()use(&$' . self::$var_name . '){
$fn=function(&$_){
	switch(gettype($_))
	{
		case \'integer\':
		case \'double\':
			$_ += ' . $inc . ';
			break;
		case \'string\':
			for($i = 0; $i < ' . $inc . '; $i++)
			{
				++$_;
			}
			break;
	}
};';
					
					foreach($values as $value)
					{
						if(!isset($value[0]) || $value[0] !== '$')
						{
							continue;
						}
						
						$return .= 'if(gettype(' . $value . ')===\'array\'){array_walk_recursive(' . $value . ', $fn);}else{$fn(' . $value . ');}';
					}
					
					return $return . '}, array());';
				}
			);
			
			$this->php .= str_replace(
				array(
					"echo <<<'" . self::$var_name . "'\r\n\r\n" . self::$var_name . ";",
					"\r\n" . self::$var_name . ";\r\necho <<<'" . self::$var_name . "'"
				),
				array('', ''),
				"echo <<<'" . self::$var_name . "'\r\n"
					. preg_replace_callback(
						// http://stackoverflow.com/a/6464500
						'~{@(echoj?l?|if|else|for|while|each|set|call|global|php|return|inc|/)(?:\\s*(.*?))?}(?=(?:[^"\\\\]*(?:\\\\.|"(?:[^"\\\\]*\\\\.)*[^"\\\\]*"))*[^"]*$)~i',
						function($matches)use(&$replacement){
							return "\r\n" . self::$var_name . ";\r\n"
								. $replacement[$matches[1]](isset($matches[2]) ? $matches[2] : null)
								. "echo <<<'" . self::$var_name . "'\r\n";
						},
						$str . ''
					)
				. "\r\n" . self::$var_name . ";\r\n"
				) . str_repeat('}', $brackets);
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
			
			return '$DATA = ' . var_export($this->data, true) . ';$' . self::$var_name . ' = &$DATA;' . $this->php;
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
