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

class ResourceFile implements Resource {
	protected $_template_dir;
	protected $_suffix;
	protected $_language;

	public function __construct($template_dir = 'templates/', $suffix = '.tpl', $language = 'en') {
		$this->_template_dir	= $template_dir;
		$this->_suffix			= $suffix;
		$this->_language		= $language;
	}

	public function getTemplateId($tpl) {
		return $tpl.'.'.$this->_language;
	}
	
	public function getTemplate($tpl) {
		// check trailing slash in _template_dir
		if (substr($this->_template_dir, -1) != '/') $this->_template_dir .= '/';

		$raw_tpl		= $this->_template_dir . $tpl;

		// check for language dependent template file
		$tpl_lang = $raw_tpl . '.' . $this->_language . $this->_suffix;		
		if (file_exists( $tpl_lang )) $raw_tpl = $tpl_lang;
		else $raw_tpl = $raw_tpl . $this->_suffix; // or fall back to standard template
		
		return file_get_contents( $raw_tpl );
	}

	public function getTimestamp($tpl) {
		// check trailing slash in _template_dir
		if (substr($this->_template_dir, -1) != '/') $this->_template_dir .= '/';

		$raw_tpl		= $this->_template_dir . $tpl;

		// check for language dependent template file
		$tpl_lang = $raw_tpl . '.' . $this->_language . $this->_suffix;		
		if (file_exists( $tpl_lang )) $raw_tpl = $tpl_lang;
		else $raw_tpl = $raw_tpl . $this->_suffix; // or fall back to standard template

		// does the template exist
		if (!file_exists( $raw_tpl )) throw new \Exception('template "'.$tpl.'" ('.$raw_tpl.') does not exist.');
		
		return filemtime( $raw_tpl );
	}
}
