<?php

/*
 * Project: Serpent - the PHP compiling template engine
 * Copyright (C) 2009 Christoph Erdmann
 * 
 * This library is free software; you can redistribute it and/or modify it under the terms of the GNU Lesser General Public License as published by the Free Software Foundation; either version 2.1 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public License along with this library; if not, write to the Free Software Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110, USA
 */

namespace McSodbrenner\Serpent;

class Serpent {
	// main config
	protected $_compile_dir;
	protected $_charset;
	protected $_force_compile;
	protected $_vars				= array();
	
	// all assigned mappings
	protected $_mappings			= array();
	protected $_mapping_parameters	= array();

	// for captures, repeats and cycles
	protected $_capture_stack		= array();
	protected $_repeat_stack		= array();
	protected $_loop_stack			= array();
	
	// for inheritance
	protected $_template_stack		= array();
	protected $_block_content		= array();
	protected $_block_name			= array();
	protected $_block_name_current	= '';	

	// holds the resources
	protected $_resources			= array();
	
	public function __construct($compile_dir = 'templates_compiled/', $charset = 'utf-8', $force_compile = true) {
		$this->_compile_dir		= $compile_dir;
		$this->_charset			= $charset;
		$this->_force_compile	= $force_compile;

		$this->_mappings['strings'] = array(
			'render'		=> 'echo $this->render',
			'include'		=> 'include $this->_render',
			'eval'			=> 'eval( "?>" . $this->compile',
			'extend'		=> '$this->_addParentTemplate',
			'block'			=> '$this->_block_name[$this->render_id][] = %1$s;'
								.'if (!isset($this->_block_content[$this->render_id][%1$s])) {'
								.'ob_start(); time',
			'endblock'		=> '};'
								.'end($this->_block_name[$this->render_id]);'
								.'$this->_block_name_current = current($this->_block_name[$this->render_id]);'
								.'if (!isset($this->_block_content[$this->render_id][$this->_block_name_current])) {'
								.'$this->_block_content[$this->render_id][array_pop($this->_block_name[$this->render_id])] = ob_get_clean();' 
								.'}'
								.'echo $this->_block_content[$this->render_id][$this->_block_name_current];'
								.'time',
	
			'capture'		=> 'ob_start(); $this->_capture_stack[] = ',
			'endcapture'	=> '${array_pop($this->_capture_stack)} = ob_get_clean',
			'repeat'		=> 'ob_start(); $this->_repeat_stack[] = ',
			'endrepeat'		=> 'echo str_repeat(ob_get_clean(), array_pop($this->_repeat_stack)); time',
			'loop'			=> '$this->_loop_stack[] = %1$s; end($this->_loop_stack); for($this->_loop_stack[key($this->_loop_stack)]=$this->_loop_stack[key($this->_loop_stack)]; $this->_loop_stack[key($this->_loop_stack)]>0; $this->_loop_stack[key($this->_loop_stack)]--){ time',
			'endloop'		=> '}; array_pop($this->_loop_stack); time',
			'raw'			=> '$this->_raw',
			'escape'		=> '$this->_escape',
			'unescape'		=> '$this->_unescape',
		);
		$this->_mappings['closures'] = array();
	}
		
	public function pass($vars) {
		$this->_vars = $this->_tagXSS($vars);
	}
	
	// the main method to render a template
	public function render($tpl, $vars = null, $resource_handler = null) {
		// pass these vars if set
		if (is_null($vars)) $vars = $this->_vars;
		else $vars = $this->_tagXSS($vars);
		
		// we need an unique id for each render-inheritance-branch.
		$this->render_id = md5(uniqid());
		$this->render_id_stack[] = $this->render_id;
		
		// add to template stack
		$this->_template_stack[$this->render_id][] = $this->_render($tpl, $resource_handler);

		// render data 
		extract($vars, EXTR_REFS);
		ob_start();
		
		// include the extended templates
		// foreach would not work here because an included template could fill the template stack
		// (and foreach just works with a copy of the array and would not recognize the new template)
		while (count($this->_template_stack[$this->render_id]) > 0) {
			include(array_shift($this->_template_stack[$this->render_id]));
			
			// throw away the output of templates that only extend
			if ( count($this->_template_stack[$this->render_id]) > 0) ob_clean();
		}

		// Now we have to set the old id as the current id if we leave this render branch
		array_pop($this->render_id_stack);
		$this->render_id = end($this->render_id_stack);
		
		// check for XSS strings
		$content = ob_get_clean();
		if (strpos($content, '<!XSS!>') !== false) {
			preg_match('|<!XSS!>(.+?)<!/XSS!>|s', $content, $match);
			throw new SerpentSecurityException(
				__CLASS__ . ": The use of following string in template '" . $tpl . "' was not specified: '" . $match[1] . "'.<br /><br />"
				. "Use :raw() if you know that the input cannot contain a XSS attack.<br />"
				. "Use :escape() to escape user input. Be careful: Also \$_SERVER variables can be dangerous.");
		}

		return $content;
	}

	public function addResource($name, \McSodbrenner\Serpent\Resource $obj) {
		$this->_resources[$name] = $obj;
	}

	public function addMappings($mappings) {
		$closures = array_filter($mappings, function($mapping){
			return is_object($mapping);
		});

		$strings = array_filter($mappings, function($mapping){
			return is_string($mapping);
		});

		// the array_merge have the default mappings at the second place. So it is not possible to overwrite tha default mappings
		$this->_mappings['strings']		= array_merge($strings, $this->_mappings['strings']);
		$this->_mappings['closures']	= array_merge($closures, $this->_mappings['closures']);
	}

	// input is getting tagged with a XSS string to check for unspecified output (raw or escape)
	protected function _tagXSS($value) {
		if (is_array($value)) {
			$value = array_map(array(&$this, '_tagXSS'), $value);
		} else {
			if (is_string($value)) $value = '<!XSS!>' . $value . '<!/XSS!>';
		}
		return $value;
	}

	// makes sure that a valid filename will be used for the compilation file
	protected function _cleanFilename($filename) {
		return preg_replace('=[^a-z0-9_-]=i', '%', $filename);
	}
	
	// checks if a template has to be compiled and returns the path to the compiled template
	protected function _render($tpl, $resource_handler = null) {
		// get resource
		if (is_null($resource_handler) || !isset($this->_resources[$resource_handler])) {
			reset($this->_resources);
			$resource_handler = key($this->_resources);
			if (!isset($this->_resources[$resource_handler])) {
				throw new \Exception('There is no resource handler with the name "'.$resource_handler.'"');
			}
		}
		$resource = $this->_resources[$resource_handler];

		// add trailing slash to compile_dir if not exists
		$this->_compile_dir = rtrim($this->_compile_dir, '/') . '/';
		
		// the name of the compiled template
		$compiled_tpl = $this->_compile_dir . $this->_cleanFilename( $resource_handler ) . '/' . $this->_cleanFilename( $resource->getTemplateId($tpl) );
		
		// force compile and check if a compiled template exists
		if ($this->_force_compile || !file_exists( $compiled_tpl )) {
			$this->_compileTemplate( $tpl, $resource, $compiled_tpl);
			return $compiled_tpl;
		}
		
		// compare timestamp of tpl and compiled file
		$compiled_tpl_time	= filemtime( $compiled_tpl );
		$raw_tpl_mtime		= $resource->getTimestamp( $tpl );
		if ($compiled_tpl_time != $raw_tpl_mtime) {
			$this->_compileTemplate( $tpl, $resource, $compiled_tpl);
			return $compiled_tpl;
		}
		
		return $compiled_tpl;
	}
		
	// creates the compiled template
	protected function _compileTemplate($tpl, $resource, $compiled_tpl) {
		$source		= $resource->getTemplate( $tpl );
		$timestamp	= $resource->getTimestamp( $tpl );
		
		// compile source
		$compiled = $this->_compile($source);
		
		// create folder for resource handler if not exist
		if (!is_dir(dirname($compiled_tpl))) mkdir(dirname($compiled_tpl));

		// write compiled template
		file_put_contents($compiled_tpl, $compiled);
		
		// touch it to synch the mtime of the original and the compiled template
		touch($compiled_tpl, $timestamp);
	}

	// use this function for inheritance in the template
	protected function _addParentTemplate($tpl) {
		$this->_template_stack[$this->render_id][] = $this->_render($tpl);
	}
	

	protected function _raw($var) {
		if (is_array($var)) {
			foreach ($var as $key=>$value) {
				$var[$key] = $this->_raw($value);
			}
		} else {
			if (is_string($var)) {
				$var = substr($var, 7, -8);
			}
		}
		return $var;
	}

	// used for the mapped function "escape"
	protected function _escape($var, $charset = null) {
		if (is_null($charset)) $charset = $this->_charset;

		if (is_array($var)) {
			foreach ($var as $key=>$value) {
				$var[$key] = $this->_escape($value, $charset);
			}
		} else {
			if (is_string($var)) {
				$var = substr($var, 7, -8);
				$var = htmlspecialchars($var, ENT_QUOTES, $charset);
			}
		}
		return $var;
	}

	// used for the mapped function "unescape"
	protected function _unescape($var) {
		if (is_array($var)) {
			foreach ($var as $key=>$value) {
				$var[$key] = $this->_unescape($value);
			}
		} else {
			if (is_string($var)) {
				$var = htmlspecialchars_decode($var, ENT_QUOTES);
			}
		}
	return $var;
	}

	// creates the compiled template
	protected function _compile($content) {
		// strip comments
		$content = preg_replace("=/\*.*?\*/\s+=is", '', $content);
		
		// replace escaped tilde
		$content = str_replace('\~', '<?php echo chr(126) ?>', $content);
		
		// replace tildes with php tags
		$content  = preg_replace_callback('=(~~?)(.*?)~=s', array($this, '_callbackProcessTildes'), $content);
		
		// we have to prereplace the short open tag because since php 5.3
		// the token "T_OPEN_TAG_WITH_ECHO" is only recognized on "short_open_tag = on"
		// so we cannot use the tokenizer
		$content = preg_replace('#<\? #', '<?php ', $content);
		$content = str_replace('<?=', '<?php echo ', $content);

		// tokenize code
		$php_block = false; // shows if we are in a php block
		$tokens = token_get_all($content);
		$content = '';
		foreach ($tokens as $token) {
			// get token name and token content
			$token_name = 'UNDEFINED';
			if (is_array($token)) {
				$token_name = token_name($token[0]);
				$token = $token[1];
			}
			// process php blocks
			if ($token_name == 'T_OPEN_TAG') {
				$content .= '<?php ';
				$php_content = '';
				$php_block = true;
			} elseif ($token_name == 'T_CLOSE_TAG') {
				$content .= $this->_callbackProcessPhpBlocks($php_content) . ' ' . $token;
				$php_block = false;
			} elseif (!$php_block) {
				// process everything else
				$content .= $token;
			} elseif ($php_block) {
				$php_content .= $token;
			}
		}
		
		return $content;
	   }
	
	// callback to process the php blocks
	protected function _callbackProcessPhpBlocks($content) {
		// expand array syntax
		$content = preg_replace_callback('=(\$[a-z0-9_]+)((\.[a-z0-9_]+)+)=i', array($this, '_callbackExpandArraySyntax'), $content);
		
		// extract mapped functions
		while ($this->_expandFunctionSyntax($content)) continue;

		return $content;
	}
	
	// callback to process tildes
	protected function _callbackProcessTildes($content) {
		$returner = '<?php ';
		if ($content[1] == '~~') $returner .= 'echo ';
		$returner .= $content[2] . '?>';
		return $returner;
	}

	// replace all mapped function calls
	protected function _expandFunctionSyntax(&$content) {
		// find all mapped function calls
		if (!preg_match('=(?<!:):([a-z0-9_]+)\s*(\()=i', $content, $match, PREG_OFFSET_CAPTURE)) return false;
		
		// check mappings
		$mapping_name = $match[1][0];

		if (isset($this->_mappings['strings'][$mapping_name])) {
			$mapping = $this->_mappings['strings'][$mapping_name];
		} elseif (isset($this->_mappings['closures'][$mapping_name])) {
			$mapping = $this->_mappings['closures'][$mapping_name];
		} else {
			throw new SerpentMappingNotFoundException('mapping for function call "'.$mapping_name.'" does not exist.');
		}
		
		// find end of mapped function (search for the last closing parenthesis)
		$i		= $match[2][1]; // starting position of opening parenthesis
		$count	= null;
		while ($count !== 0 and isset($content{$i})) {
			if ($content{$i} == '(') $count++;
			if ($content{$i} == ')') $count--;
			$i++;
		}

		// get parts
		$start		= substr($content, 0, $match[0][1]);
		$parameters	= substr($content, $match[2][1], $i-$match[2][1]);
		$end		= substr($content, $i);

		// explode parameters for the use of placeholders in the mapping
		$parameters_array	= $this->_expandParameters($parameters);

		if (is_string($mapping)) {
			$paranthesis_add = str_repeat(')', substr_count($mapping, '(') - substr_count($mapping, ')') );
			// string
			$content = $start . vsprintf($mapping, $parameters_array) . $parameters . $paranthesis_add . $end;
		} else {
			// closure
			$content = $start . '$this->_mappings["closures"]["' . $mapping_name . '"]' . $parameters . $end;
		}
		return true;
	}
	
	// callback to expand the dot syntax for arrays
	protected function _callbackExpandArraySyntax($matches) {
		$parts = explode('.', $matches[2]);
		array_shift($parts);
		
		$returner = $matches[1];
		$returner .= "['".implode("']['", $parts)."']";
		return $returner;
	}

	// return an array of fucntion arguments from a string of function arguments
	protected function _expandParameters($parameters) {
		$parameters = substr($parameters, 1, strlen($parameters)-2);

		// first we filter out strings to have no problems with non balanced brackets in strings
		$regex = '/("|\')(?:[^\1\\\\]|\\\\.)*\1/';
		preg_match_all($regex, $parameters, $matches);
		$strings = $matches[0];
		$parameters = preg_replace($regex, '~~STRING~~', $parameters);

		// now we are able to parse the matching brackets
		$pos			= 0;
		$start			= 0;
		$count			= null;
		$new_parameters	= array();
		while (isset($parameters{$pos})) {
			if (in_array($parameters{$pos}, array('(', '['))) $count++;
			if (in_array($parameters{$pos}, array(')', ']'))) $count--;
			
			if ($count == 0 && $parameters{$pos} == ',') {
				$new_parameters[] = trim(substr($parameters, $start, $pos-$start));
				$start = $pos+1;
			}
			$pos++;
		}
		// to get the last match
		$new_parameters[] = substr($parameters, $start, $pos-$start);
		
		// rereplace the ~~STRING~~ replacements
		foreach ($new_parameters as $i => $p)  {
			while (strpos($new_parameters[$i], '~~STRING~~') !== false) {
				$new_parameters[$i] = str_replace('~~STRING~~', array_shift($strings), $new_parameters[$i]);
			}
		}
		
		return $new_parameters;
	}
}
