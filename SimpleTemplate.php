<?php

	class SimpleTemplate {
		
		private static $var_name = '';
		private static $regex = array(
			'var' => '(?:\w*(?:\.\w*)?)',
			'value' => '(?:(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false)',
			'var_value' => '(?:(?:"[^"]*")|[\-+]?\d*(?:\.\d*)?|true|false|\w*(?:\.\w*)?)'
		);
		
		private $data = array();
		
		private $php = '';
		
		private static function render_var($name = null){
			$var = '$' . self::$var_name . ($name ? '[\'' . join('\'][\'', explode('.', $name)) . '\']' : '');
			
			return '(isset(' . $var . ')?' . $var . ':null)';
		}
		
		private static function split_values($values, $delimiter = '\s*,\s*'){
			return preg_split('@(' . $delimiter . ')(?=(?:[^"]|"[^"]*")*$)@', $values);
		}
		
		private static function parse_values($values, $delimiter = '\s*,\s*'){
			$value_bits = self::split_values($values, $delimiter);
			
			foreach($value_bits as $k => $value)
			{
				$value_bits[$k] = self::parse_value($value);
			}
			
			return $value_bits;
		}
		
		private static function parse_value($value){
			return preg_match('@^' . self::$regex['value'] . '$@', $value) ? $value : self::render_var($value);
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
					return 'echo implode(\'\', (array)' . implode('), implode(\'\', (array)', self::parse_values($data)) . ');';
				},
				'if' => function($data)use(&$brackets){
					if(
						!preg_match(
							'@(' . self::$regex['var'] . ')\s*(?:(is(?:(?:\s*not|n\'?t)?\s*(?:(?:greater|lower)\s*than|equal(?:\s*to)?))?|has)\s*(' . self::$regex['var_value'] . '))?@',
							$data, $bits
						)
					)
					{
						return '';
					}
					
					++$brackets;
					
					if(isset($bits[3]))
					{
						$var1 = self::parse_value($bits[1]);
						$var2 = self::parse_value($bits[3]);
						
						$compare = array(
							'is' => $var1 . ' === ' . $var2,
							'isn\'t' => $var1 . ' !== ' . $var2,
							'is not' => $var1 . ' !== ' . $var2,
							
							'is equal' => $var1 . ' == ' . $var2,
							'isn\'t equal' => $var1 . ' != ' . $var2,
							'is not equal' => $var1 . ' != ' . $var2,
							
							'is greater' => $var1 . ' > ' . $var2,
							'isn\'t greater' => '!(' . $var1 . ' > ' . $var2 . ')',
							'is not greater' => '!(' . $var1 . ' > ' . $var2 . ')',
							'is greater than' => $var1 . ' > ' . $var2,
							'isn\'t greater than' => '!(' . $var1 . ' > ' . $var2 . ')',
							'is not greater than' => '!(' . $var1 . ' > ' . $var2 . ')',
							
							'is lower' => $var1 . ' < ' . $var2,
							'isn\'t lower' => '!(' . $var1 . ' < ' . $var2 . ')',
							'is not lower' => '!(' . $var1 . ' < ' . $var2 . ')',
							'is lower than' => $var1 . ' < ' . $var2,
							'isn\'t lower than' => '!(' . $var1 . ' < ' . $var2 . ')',
							'is not lower than' => '!(' . $var1 . ' < ' . $var2 . ')',
							
							'has' => 'array_key_exists((array)' . $var2 . ', ' . $var1 . ')',
							'has not' => '!array_key_exists((array)' . $var2 . ', ' . $var1 . ')'
						);
						
						return 'if(' . $compare[$bits[2]] . '){';
					}
					else
					{
						return 'if(' . self::parse_value($bits[1]) . '){';
					}
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
					
					return 'foreach(' . preg_replace('@\b(?!as)(' . self::$regex['var'] . ')\b@', self::render_var('$0'), $data) . '){';
				},
				'for' => function($data)use(&$brackets){
					++$brackets;
					
					return preg_replace_callback(
						'@(?<var>' . self::$regex['var'] . ')\s*(?<start>' . self::$regex['var_value'] . ')\s*(?:..(?<end>' . self::$regex['var_value'] . '))?(?:\s*step\s*(?<step>' . self::$regex['var_value'] . '))?@',
						function($matches){
						
							$values = array(
								'start' => isset($matches['start']) && isset($matches['end']) ? self::parse_value($matches['start']): 1,
								'end' => isset($matches['end']) ? self::parse_value($matches['end']): self::parse_value($matches['start']),
								'step' => isset($matches['step']) ? self::parse_value($matches['step']): 1
							);
							
							return 'foreach(range(' . $values['start'] . ',' . $values['end'] . ', abs(' . $values['step'] . ')) as ' .self::render_var($matches['var']) . '){';
						},
						$data
					);
				},
				'set' => function($data){
					$values = self::split_values($data, ' ');
					
					if(count($values) > 2)
					{
						$output = self::render_var(array_shift($values)) . ' = array(';
						
						foreach($values as $value)
						{
							$output .= self::parse_value(trim($value, ', ')) . ',';
						}
						
						return $output . ');';
					}
					else
					{
						return self::render_var($values[0]) . ' = ' . self::parse_value($values[1]) . ';';
					}
				},
				'global' => function($data){
					$data = self::split_values($data, ' ');
					
					return self::render_var(array_shift($data)) . ' = $GLOBALS[\'' . join('\'][\'', $data) . '\'];';
				},
				'call' => function($data){
					preg_match('@^\s*(?<fn>\w+)\s*(?:into\s*(?<into>' . self::$regex['var'] . ')\s*)?(?<args>.*?)$@', $data, $data);
					
					return ($data['into'] ? self::render_var($data['into']) . ' = ' : '') . $data['fn'] . '(' . implode(',', self::parse_values($data['args'])) . ');';
				},
				'php' => function($data){
					return '?><?php ' . $data;
				}
			);
			
			$this->php = str_replace(
				array(
					"echo <<<'" . self::$var_name . "'\r\n\r\n" . self::$var_name . ";",
					"\r\n" . self::$var_name . ";\r\necho <<<'" . self::$var_name . "'"
				),
				array('', ''),
				"echo <<<'" . self::$var_name . "'\r\n"
					. preg_replace_callback(
						'~{@(echo|if|else|for|each|set|call|global|php|/)(?:\s*([^}]+))?}~i',
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
			
			return '$DATA = ' . var_export($this->data, true) . ';$' . self::$var_name . ' = &$DATA;' . $this->php;
		}
		
		function render(){
			return eval(call_user_func_array(array($this, 'getPHP'), func_get_args()));
		}
		
		static function fromFile($path){
			return new self(file_get_contents($path));
		}
	}
