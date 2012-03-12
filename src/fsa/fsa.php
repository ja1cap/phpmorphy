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

define('PHPMORPHY_FSA_HEADER_SIZE', 60);

class phpMorphy_Fsa {
	var $resource;
	var $header;
	var $fsa_start;
	var $root_trans;
	var $alphabet;	
	
	// private ctor
	function phpMorphy_Fsa($resource, $header) {
		$this->resource = $resource;
		$this->header = $header;
		$this->fsa_start = $header['fsa_offset'];
		$this->root_trans = $this->_readRootTrans();
	}

	// static
	function &create(&$storage) {
		$header = phpMorphy_Fsa::_readHeader(
			$storage->read(0, PHPMORPHY_FSA_HEADER_SIZE)
		);
		
		if(!phpMorphy_Fsa::_validateHeader($header)) {
			return php_morphy_error("Invalid fsa format");
		}
		
		if($header['flags']['is_sparse']) {
			$type = 'sparse';
		} else if($header['flags']['is_tree']) {
			$type = 'tree';
		} else {
			return php_morphy_error("Only sparse or tree fsa`s supported");
		}
		
		$storage_type = phpMorphy_Fsa::_getStorageString($storage->getType());
		$file_path = dirname(__FILE__) . "/access/fsa_{$type}_{$storage_type}.php";
		$clazz = 'phpMorphy_Fsa_' . ucfirst($type) . '_' . ucfirst($storage_type);
		
		require_once($file_path);
		$obj =& new $clazz(
			$storage->getResource(),
			$header
		);
		
		return $obj;
	}
	
	function getRootTrans() { return $this->root_trans; }
	
	function &getRootState() {
		$obj =& $this->_createState($this->_getRootStateIndex());
		return $obj;
	}
	
	function getAlphabet() {
		if(!isset($this->alphabet)) {
			$this->alphabet = str_split($this->_readAlphabet());
		}
		
		return $this->alphabet;
	}
	
	function &_createState($index) {
		require_once(PHPMORPHY_DIR . '/fsa/fsa_state.php');
		
		$obj =& new phpMorphy_State($this, $index);
		return $obj;
	}
	
	// static
	function _readHeader($headerRaw) {
		if(strlen($headerRaw) != PHPMORPHY_FSA_HEADER_SIZE) {
			return php_morphy_error("Invalid header string given");
		}
		
		$fields = array(
			'fourcc' => 'a4',
			'ver' => 'V',
			'flags' => 'V',
			
			'alphabet_offset' => 'V',
			'fsa_offset' => 'V',
			'annot_offset' => 'V',
			
			'alphabet_size' => 'V',
			'transes_count' => 'V',
			
			'annot_size_len' => 'V',
			'annot_chunk_size' => 'V',
			'annot_chunks_count' => 'V',
			
			'char_size' => 'V',
			'padding_size' => 'V',
			'dest_size' => 'V',
			'hash_size' => 'V',
		);
		
		// build line
		$unpack_str = '';
		foreach($fields as $k => $v) {
			$unpack_str .= "$v$k/";
		}
		
		$header = unpack(substr($unpack_str, 0, -1), $headerRaw);

		if(false === $header) {
			return php_morphy_error("Can`t unpack header");
		}

		$flags = array();
		$raw_flags = $header['flags'];
		$flags['is_tree'] =  $raw_flags & 0x01 ? true : false;
		$flags['is_hash'] =  $raw_flags & 0x02 ? true : false;
		$flags['is_sparse'] = $raw_flags & 0x04 ? true : false;
		$flags['is_be'] =  $raw_flags & 0x08 ? true : false;

		$header['flags'] = $flags;
		
		return $header;
	}
	
	// static
	function _validateHeader($header) {
		if(
			'meal' != $header['fourcc'] ||
			2 != $header['ver'] ||
			$header['char_size'] != 1 ||
			$header['padding_size'] > 0 ||
			$header['dest_size'] != 3 ||
			$header['hash_size'] != 0 ||
			$header['annot_size_len'] != 1 ||
			$header['annot_chunk_size'] != 1 ||
			$header['flags']['is_be'] ||
			$header['flags']['is_hash'] ||
			1 == 0
		) {
			return false;
		}
		
		return true;
	}
	
	// static
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
	
	function _getRootStateIndex() { return 0; }
	
	// pure virtual
	function getAnnot($trans) { }
	function walk($trans, $word, $readAnnot = true) { }
	function collect($startNode, $callback, $readAnnot = true, $path = '') { }
	function readState($index) { }
	function unpackTranses($rawTranses) { }
	function _readRootTrans() { }
	function _readAlphabet() { }
};

class phpMorphy_Fsa_Decorator {
	var $fsa;
	
	function phpMorphy_Fsa_Decorator(&$fsa) {
		$this->fsa =& $fsa;
	}
	
	function getRootTrans() { return $this->fsa->getRootTrans(); }
	function &getRootState() { return $this->fsa->getRootState(); }
	function getAlphabet() { return $this->fsa->getAlphabet(); }
	function getAnnot($trans) { return $this->fsa->getAnnot($trans); }
	function walk($start, $word, $readAnnot = true) { return $this->fsa->walk($start, $word, $readAnnot); }
	function collect($start, $callback, $readAnnot = true, $path = '') { return $this->fsa->collect($start, $callback, $readAnnot, $path); }
	function readState($index) { return $this->fsa->readState($index); }
	function unpackTranses($transes) { return $this->fsa->unpackTranses($transes); }
};

class phpMorphy_Fsa_WordsCollector {
	var $items = array();
	var $limit;
	
	function phpMorphy_Fsa_WordsCollector($collectLimit) {
		$this->limit = $collectLimit;
	}
	
	function collect($word, $annot) {
		if(count($this->items) < $this->limit) {
			$this->items[$word] = $annot;
			return true;
		} else {
			return false;
		}
	}
	
	function getItems() { return $this->items; }
	function clear() { $this->items = array(); }
	function getCallback() { return array(&$this, 'collect'); }
};
