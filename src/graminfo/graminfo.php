<?php
 /**
 * This file is part of phpMorphy library
 *
 * Copyright c 2007 Kamaev Vladimir <heromantor@users.sourceforge.net>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the
 * Free Software Foundation, Inc., 59 Temple Place - Suite 330,
 * Boston, MA 02111-1307, USA.
 */

define('PHPMORPHY_GRAMINFO_HEADER_SIZE', 128);
 
class phpMorphy_GramInfo {
	var $resource;
	var $header;
	
	// private ctor
	function phpMorphy_GramInfo($resource, $header) {
		$this->resource = $resource;
		$this->header = $header;
	}
	
	// static
	function &create(&$storage) {
		$header = phpMorphy_GramInfo::_readHeader(
			$storage->read(0, PHPMORPHY_GRAMINFO_HEADER_SIZE)
		);
		
		if(!phpMorphy_GramInfo::_validateHeader($header)) {
			return php_morphy_error("Invalid graminfo format");
		}
		
		$storage_type = phpMorphy_GramInfo::_getStorageString($storage->getType());
		$file_path = dirname(__FILE__) . "/access/graminfo_{$storage_type}.php";
		$clazz = 'phpMorphy_GramInfo_' . ucfirst($storage_type);
		
		require_once($file_path);
		$obj =& new $clazz($storage->getResource(), $header);
		
		return $obj;
	}
	
	function getLanguage() {
		return $this->header['lang'];
	}
	
	function getCodepage() {
		return $this->header['codepage'];
	}
	
	// abstract
	function readGramInfoHeader($offset) { }
	function readAncodes($info) { }
	function readFlexiaData($info, $onlyBase) { }
	function readAllGramInfoOffsets() { }
	
	function _readHeader($headerRaw) {
		$header = unpack(
			'Vver/Vis_be/Vflex_count/Vflex_offset/Vflex_size',
			$headerRaw
		);
		
		$offset = 20;
		$len = ord(substr($headerRaw, $offset++, 1));
		$header['lang'] = rtrim(substr($headerRaw, $offset, $len));
		
		$len = ord(substr($headerRaw, $offset++, 1));
		$header['codepage'] = rtrim(substr($headerRaw, $offset, $len));
		
		return $header;
	}
	
	function _validateHeader($header) {
		if(
			2 != $header['ver'] &&
			0 == $header['is_be']
		) {
			return false;
		}
		
		return true;
	}
	
	function _getStorageString($type) {
		$types_map = array(
			PHPMORPHY_STORAGE_FILE => 'file',
			PHPMORPHY_STORAGE_MEM => 'mem',
			PHPMORPHY_STORAGE_SHM => 'shm'
		);
		
		if(!isset($types_map[$type])) {
			return php_morphy_error('Unsupported storage type ' . $storage->getType());
		}
		
		return $types_map[$type];
	}
};

class phpMorphy_GramInfo_Decorator {
	var $info;
	
	function phpMorphy_GramInfo_Decorator(&$info) {
		$this->info =& $info;
	}
	
	function readGramInfoHeader($offset) { return $this->info->readGramInfoHeader($offset); }
	function readAncodes($info) { return $this->info->readAncodes($info); }
	function readFlexiaData($info, $onlyBase) { return $this->info->readFlexiaData($info, $onlyBase); }
	function readAllGramInfoOffsets() { return $this->info->readAllGramInfoOffsets(); }
	
	function getLanguage()  { return $this->info->getLanguage(); }
	function getCodepage()  { return $this->info->getCodepage(); }
}

class phpMorphy_GramInfo_RuntimeCaching extends phpMorphy_GramInfo_Decorator {
	var $flexia_all = array();
	var $flexia_base = array();
	
	function readFlexiaData($info, $onlyBase) {
		$offset = $info['offset'];
		
		if($onlyBase) {
			if(!isset($this->flexia_base[$offset])) {
				$this->flexia_base[$offset] = $this->info->readFlexiaData($info, $onlyBase);
			}
			
			return $this->flexia_base[$offset];
		} else {
			if(!isset($this->flexia_all[$offset])) {
				$this->flexia_all[$offset] = $this->info->readFlexiaData($info, $onlyBase);
			}
			
			return $this->flexia_all[$offset];
		}
	}
}
