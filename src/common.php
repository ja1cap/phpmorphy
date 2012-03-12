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

if(!defined('PHPMORPHY_DIR')) {
	define('PHPMORPHY_DIR', dirname(__FILE__));
}

require_once(PHPMORPHY_DIR . '/fsa/fsa.php');
require_once(PHPMORPHY_DIR . '/graminfo/graminfo.php');
require_once(PHPMORPHY_DIR . '/morphiers.php');
require_once(PHPMORPHY_DIR . '/storage.php');

if(version_compare(PHP_VERSION, '5') < 0) {
	function php_morphy_error($errorMessage, $result = false) {
		trigger_error($errorMessage, E_USER_ERROR);
		return $result;
	}

	require_once(PHPMORPHY_DIR . '/php4_compat.php');
} else {
	require_once(PHPMORPHY_DIR . '/php5_compat.php');
}

class phpMorphy_FilesBundle {
	 var $dir;
	 var $lang;

	function phpMorphy_FilesBundle($dirName, $lang) {
		$this->dir = $dirName;
		$this->lang = $lang;
	}

	function getCommonAutomatFile() {
		return $this->_genFileName('%s/common_aut.%s.bin');
	}

	function getPredictAutomatFile() {
		return $this->_genFileName('%s/predict_aut.%s.bin');
	}

	function getGramInfoFile() {
		return $this->_genFileName('%s/morph_data.%s.bin');
	}
	
	function getGramTabFile() {
		return $this->_genFileName('%s/gramtab.%s.bin');
	}

	function _genFileName($fmt) {
		return sprintf($fmt, $this->dir, strtolower($this->lang));		
	}
	
};

class phpMorphy {
	var $options;
	var $common_fsa;
	var $graminfo;
	var $gramtab;
	var $single_morphier;
	var $bulk_morphier;
	var $predict_morphier;
	
	function phpMorphy(&$bundle, $options = null) {
		$options = $this->_repairOptions($options);
		$this->options = $options;
		$this->bundle =& $bundle;
		
		$common_fsa =& $this->_createFsa(
			$this->_createStorage(
				$options['storage'],
				$bundle->getCommonAutomatFile()
			)
		);
		
		$this->common_fsa =& $common_fsa;
		
		$graminfo =& $this->_createGramInfo(
			$this->_createStorage(
				$options['storage'],
				$bundle->getGramInfoFile()
			)
		);
		
		$this->graminfo =& $graminfo;
		
		$extra_morphiers = array();
		
		if($options['predict_by_suffix']) {
			$extra_morphiers[] =& $this->_createPredictBySuffixMorphier(
				$common_fsa,
				$graminfo
			);
		}
		
		if($options['predict_by_db']) {
			$extra_morphiers[] =& $this->_createPredictByDatabaseMorphier(
				$this->_createFsa(
					$this->_createStorage(
						$options['storage'],
						$bundle->getPredictAutomatFile()
					)
				),
				$graminfo
			);
		}
		
		$predict_morphier = null;
		$single_morphier = null;
		$standalone_morphier =& $this->_createSingleMorphier($common_fsa, $graminfo);
		
		if(($count = count($extra_morphiers))) {
			if($count > 1) {
				$predict_morphier =& $this->_createChainMorphier();
				$single_morphier =& $this->_createChainMorphier();
				
				$single_morphier->add($standalone_morphier);
				
				for($i = 0, $c = count($extra_morphiers); $i < $c; $i++) {
					$predict_morphier->add($extra_morphiers[$i]);
					$single_morphier->add($extra_morphiers[$i]);
				}
			} else {
				$predict_morphier =& $extra_morphiers[0];
				
				$single_morphier =& $this->_createChainMorphier();
				$single_morphier->add($standalone_morphier);
				$single_morphier->add($predict_morphier);
			}
		} else {
			$single_morphier =& $standalone_morphier;
		}
		
		if($options['with_gramtab']) {
			$this->gramtab =& $this->_createGramTab(
				$options['storage'],
				$bundle->getGramTabFile()
			);
			
			$this->single_morphier =& $this->_createGramTabMorphier(
				$single_morphier,
				$this->gramtab
			);			
		} else {
			$this->single_morphier =& $single_morphier;
		}
		
		$this->predict_morphier = $predict_morphier;
	}
	
	function &getSingleMorphier() { return $this->single_morphier; }
	
	function &getBulkMorphier() {
		if(!isset($this->bulk_morphier)) {
			$bulk_morphier =& $this->_createBulkMorphier(
				$this->common_fsa,
				$this->graminfo,
				$this->predict_morphier
			);
			
			if($this->options['with_gramtab']) {
				$this->bulk_morphier =& $this->_createGramTabMorphierBulk(
					$bulk_morphier,
					$this->gramtab
				);
			} else {
				$this->bulk_morphier =& $bulk_morphier;
			}
		}
		
		return $this->bulk_morphier;
	}
	
	function getBaseForm($word) {
		if(!is_array($word)) {
			return $this->single_morphier->getBaseForm($word);
		} else {
			$bulker =& $this->getBulkMorphier();
			return $bulker->getBaseForm($word);
		}
	}
	
	function getAllForms($word) {
		if(!is_array($word)) {
			return $this->single_morphier->getAllForms($word);
		} else {
			$bulker =& $this->getBulkMorphier();
			return $bulker->getAllForms($word);
		}
	}
	
	function getPseudoRoot($word) {
		if(!is_array($word)) {
			return $this->single_morphier->getPseudoRoot($word);
		} else {
			$bulker =& $this->getBulkMorphier();
			return $bulker->getPseudoRoot($word);
		}
	}
	
	function getAllFormsWithGramInfo($word) {
		if(!is_array($word)) {
			return $this->single_morphier->getAllFormsWithGramInfo($word);
		} else {
			$bulker =& $this->getBulkMorphier();
			return $bulker->getAllFormsWithGramInfo($word);
		}
	}
	
	function getCodepage() {
		return $this->graminfo->getCodepage();
	}
	
	function _repairOptions($options) {
		$default = array(
		 	'storage' => PHPMORPHY_STORAGE_FILE,
			'with_gramtab' => false,
			'predict_by_suffix' => false,
			'predict_by_db' => false,
		);
		
		$result = array();
		settype($options, 'array');
		
		foreach($default as $k => $v) {
			if(array_key_exists($k, $options)) {
				$result[$k] = $options[$k];
			} else {
				$result[$k] = $v;
			}
		}
		
		return $result;
	}
	
	function &_createGramTabMorphierBulk(&$morphier, &$gramtab) {
		$obj =& new phpMorphy_Morphier_WithGramTabBulk($morphier, $gramtab);
		return $obj;
	}
	
	function &_createGramTabMorphier(&$morphier, &$gramtab) {
		$obj =& new phpMorphy_Morphier_WithGramTab($morphier, $gramtab);
		return $obj;
	}
	
	function _readGramTab($storageType, $fileName) {
		$storage =& $this->_createStorage($storageType, $fileName);
		return $storage->read(0, $storage->getFileSize());
	}
	
	function &_createGramTab($storageType, $fileName) {
		require_once(PHPMORPHY_DIR . '/gramtab.php');
		
		$obj =& new phpMorphy_GramTab(
			$this->_readGramTab($storageType, $fileName),
			new phpMorphy_GramTab_StandartBuilder()
		);
		return $obj;
	}
	
	function &_createGramInfo(&$storage) {
		$obj =& new phpMorphy_GramInfo_RuntimeCaching(
			phpMorphy_GramInfo::create($storage)
		);
		
		return $obj;
	}
	
	function &_createFsa(&$storage) {
		$obj =& phpMorphy_Fsa::create($storage);
		return $obj;
	}
	
	function &_createStorage($type, $fileName) {
		$obj =& phpMorphy_Storage::create($type, $fileName);
		return $obj;
	}
	
	function &_createChainMorphier() {
		$obj =& new phpMorphy_Morphier_Chain();
		return $obj;
	}
	
	function &_createSingleMorphier(&$fsa, &$graminfo) {
		$obj =& new phpMorphy_Morphier_DictSingle($fsa, $graminfo);
		return $obj;
	}
	
	function &_createBulkMorphier(&$fsa, &$graminfo, &$predict) {
		$obj =& new phpMorphy_Morphier_DictBulk($fsa, $graminfo, $predict);
		return $obj;
	}
	
	function &_createPredictBySuffixMorphier(&$fsa, &$graminfo) {
		$obj =& new phpMorphy_Morphier_PredictBySuffix($fsa, $graminfo);
		return $obj;
	}
	
	function &_createPredictByDatabaseMorphier(&$fsa, &$graminfo) {
		$obj =& new phpMorphy_Morphier_PredictByDatabse($fsa, $graminfo);
		return $obj;
	}
};
