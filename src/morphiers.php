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

/**
 * All classes in this file implements IMorphier interface
 * interface IMorphier {
 *   function getBaseForm($word);
 *   function getAllForms($word);
 *   function getAllFormsWithGramInfo($word);
 * };
 */
 
/**
 * Base class for all morphiers
 * @abstract 
 */
class phpMorphy_Morphier_Base {
	var $graminfo;
	var $fsa;
	var $root_trans;
	
	function phpMorphy_Morphier_Base(&$fsa, &$graminfo) {
		$this->fsa =& $fsa;
		$this->graminfo =& $graminfo;
		$this->root_trans = $fsa->getRootTrans();
	}
	
	function getBaseForm($word) {
		if(false === ($annot = $this->_findWord($word))) {
			return false;
		}
		
		return $this->_composeForms($word, $annot, true);
	}
	
	function getAllForms($word) {
		if(false === ($annot = $this->_findWord($word))) {
			return false;
		}
		
		return $this->_composeForms($word, $annot, false);
	}
	
	function getAllFormsWithGramInfo($word) {
		if(false === ($annots = $this->_findWord($word))) {
			return false;
		}
		
		$result = $this->_composeGramInfos($annots, 'all');
		$i = 0;
		foreach($annots as $annot) {
			$current =& $result[$i];
			
			$current['common'] = $annot['ancode'];
			$current['forms'] =  $this->_composeForms($word, array($annot), false);
			
			unset($current);
			$i++;
		}
		
		return $result;
	}
	
	function &getFsa() { return $this->fsa; }
	function &getGramInfo() { return $this->graminfo; }
	
	function _composeGramInfos($annots, $key) {
		$result = array();
		
		foreach($annots as $annot) {
			$result[] = array(
				$key => $this->graminfo->readAncodes($annot)
			);
		}
		
		return $result;
	}
	
	function _composeForms($word, $annots, $onlyBase) {
		$result = array();
		
		foreach($annots as $annot) {
			list($base, $prefix) = $this->_getBaseAndPrefix(
				$word,
				$annot['cplen'],
				$annot['plen'],
				$annot['flen']
			);
			
			// read flexia
			$flexias = $this->graminfo->readFlexiaData($annot, $onlyBase);
			
			for($i = 0, $c = count($flexias); $i < $c; $i += 2) {
				$result[$prefix . $flexias[$i] . $base . $flexias[$i + 1]] = 1;
			}
		}
		
		
		return array_keys($result);
	}
	
	function _getBaseAndPrefix($word, $cplen, $plen, $flen) {
		if($flen) {
			$base = substr($word, $cplen + $plen, -$flen);
		} else {
			if($cplen || $plen) {
				$base = substr($word, $cplen + $plen);
			} else {
				$base = $word;
			}
		}
		
		$prefix = $cplen ? substr($word, 0, $cplen) : '';
		
		return array($base, $prefix);
	}
	
	// abstract methods
	function _findWord($word) { }
	function _decodeAnnot($annotRaw) { }
	function _getAnnotSize() { }
};

// TODO: This can`t extends phpMorphy_Morphier_Base, refactor it!
class phpMorphy_Morphier_Dict extends phpMorphy_Morphier_Base {
	var $predict;
	var $single_morphier;
	var $bulk_morphier;
	
	function phpMorphy_Morphier_Dict(&$fsa, &$graminfo, &$predict) {
		parent::phpMorphy_Morphier_Base($fsa, $graminfo);
		$this->predict =& $predict;
		
		$this->single_morphier = $this->_createSingle($fsa, $graminfo);
	}
	
	function getBaseForm($word) { return $this->_invoke('getBaseForm', $word); }
	function getAllForms($word) { return $this->_invoke('getAllForms', $word); }
	
	function _invoke($method, $word) {
		if(!is_array($word)) {
			return $this->single_morphier->$method($word);
		} else {
			if(!isset($this->bulk_morphier)) {
				$this->bulk_morphier =& $this->_createBulk(
					$this->fsa,
					$this->graminfo,
					$this->predict
				);
			}
			
			return $this->bulk_morphier->$method($word);
		}
	}
	
	function &_createSingle(&$fsa, &$graminfo, &$predict) {
		$obj =& new phpMorphy_Morphier_DictSingle($fsa, $graminfo, $predict);
		return $obj;
	}
	
	function &_createBulk(&$fsa, &$graminfo, &$predict) {
		$obj =& new phpMorphy_Morphier_DictBulk($fsa, $graminfo, $predict);
		return $obj;
	}
}

class phpMorphy_Morphier_Common extends phpMorphy_Morphier_Base {
	function _getAnnotSize() { return 15; }
	
	function _decodeAnnot($annotRaw) {
		$result = array();
		
		$len = strlen($annotRaw);
		if($len % 15 != 0 || !$len) {
			return php_morphy_error("Invalid annot with $len length given");
		}
		
		for($i = 0, $c = strlen($annotRaw); $i < $c; $i += 15) {
			$result[] = unpack(
				'Voffset/vbase_size/vall_size/vancodes_size/a2ancode/Cflen/Cplen/Ccplen',
				substr($annotRaw, $i, 15)
			);
		}
		
		return $result;
	}
}

class phpMorphy_Morphier_DictBulk extends phpMorphy_Morphier_Common {
	var $predict;
	
	function phpMorphy_Morphier_DictBulk(&$fsa, &$graminfo, &$predict) {
		parent::phpMorphy_Morphier_Common($fsa, $graminfo);
		$this->predict = $predict;
	}
	
	function getBaseForm($words) {
		return $this->_invoke('getBaseForm', $words, true);
	}
	
	function getAllForms($words) {
		return $this->_invoke('getAllForms', $words, false);
	}
	
	function getAllFormsWithGramInfo($words) {
		$raw_annots = $this->_findWord($words);
		
		$result = array();
		if(isset($raw_annots[''])) {
			if($this->predict) {
				foreach($raw_annots[''] as $word) {
					$result[$word] = $this->predict->getAllFormsWithGramInfo($word);
				}
			} else {
				foreach($raw_annots[''] as $word) {
					$result[$word] = false;
				}
			}
		}
		
		foreach($raw_annots as $annot_raw => $words) {
			if(!strlen($annot_raw)) continue;
			
			$annot_chunks = str_split($annot_raw, $this->_getAnnotSize());
			$annot_decoded = $this->_decodeAnnot($annot_raw);
			
			foreach($words as $word) {
				$i = 0;
				foreach($annot_chunks as $chunk) {
					$result[$word][] = array(
						'forms' => $this->_composeForms(
							array($chunk => array($word)),
							false,
							false
						),
						'common' => $annot_decoded[$i]['ancode'],
						'all' => $this->graminfo->readAncodes($annot_decoded[$i])
					);
					
					$i++;
				}
			}
		}
		
		
		return $result;
	}
	
	function _invoke($method, $words, $onlyBase) {
		$annots = $this->_findWord($words);
		
		// TODO: Ugly hack!
		$result = $this->_composeForms($annots, $onlyBase);
		
		if(isset($annots[''])) {
			if($this->predict) {
				foreach($annots[''] as $word) {
					$result[$word] = $this->predict->$method($word);
				}
			} else {
				foreach($annots[''] as $word) {
					$result[$word] = false;
				}
			}
		}

		return $result;
	}
	
	function _findWord($words) {
		$tree = $this->_buildPrefixTree($words);
		$annots = array();
		$unknown_words_annot = '';
		
		for($keys = array_keys($tree), $i = 0, $c = count($keys); $i < $c; $i++) {
			$prefix = $keys[$i];
			$suffixes = $tree[$prefix];
			
			// find prefix
			$prefix_result = $this->fsa->walk($this->root_trans, $prefix, false);
			$prefix_trans = $prefix_result['last_trans'];
			$prefix_found = $prefix_result['result'];
			
			for($j = 0, $jc = count($suffixes); $j < $jc; $j++) {
				$suffix = $suffixes[$j];
				$word = $prefix . $suffix;
				
				if($prefix_found) {
					// find suffix
					$result = $this->fsa->walk($prefix_trans, $suffix, true);
					
					if(!$result['result'] || null === $result['annot']) {
						$annots[$unknown_words_annot][] = $word;
					} else {
						$annots[$result['annot']][] = $word;
					}
				} else {
					$annots[$unknown_words_annot][] = $word;
				}
			}
		}
		
		return $annots;
	}
	
	function _composeForms($annotsRaw, $onlyBase) {
		$size_index = $onlyBase ? 'base_size' : 'all_size';
		
		$result = array();
		// process found annotations
		foreach($annotsRaw as $annot_raw => $words) {
			if(strlen($annot_raw) == 0) continue;
			
			foreach($this->_decodeAnnot($annot_raw) as $annot) {
				$flexias = $this->graminfo->readFlexiaData($annot, $onlyBase);
				
				$cplen = $annot['cplen'];
				$plen = $annot['plen'];
				$flen = $annot['flen'];
				
				foreach($words as $word) {
					if($flen) {
						$base = substr($word, $cplen + $plen, -$flen);
					} else {
						if($cplen || $plen) {
							$base = substr($word, $cplen + $plen);
						} else {
							$base = $word;
						}
					}
					
					$prefix = $cplen ? substr($word, 0, $cplen) : '';
					
					for($i = 0, $c = count($flexias); $i < $c; $i += 2) {
						$form = $prefix . $flexias[$i] . $base . $flexias[$i + 1];
						
						if(!isset($result[$word]) || !in_array($form, $result[$word])) {
							$result[$word][] = $form;
						}
					}
				}
			}
		}
		
		return $result;
	}
	
	function _buildPrefixTree($words) {
		sort($words);
		
		$prefixes = array();
		$prev_word = '';
		
		foreach($words as $word) {
			if($prev_word != $word) {
				for($idx = 0, $c = min(strlen($prev_word), strlen($word)); $idx < $c && $word[$idx] == $prev_word[$idx]; $idx++);
				
				$prefix = substr($word, 0, $idx);
				$rest = substr($word, $idx);
				
				$prefixes[$prefix][] = $rest;
				
				$prev_word = $word;
			}
		}
		
		return $prefixes;
	}
}

class phpMorphy_Morphier_DictSingle extends phpMorphy_Morphier_Common {
	function _findWord($word) {
		$result = $this->fsa->walk($this->root_trans, $word);
		
		if(!$result['result'] || null === $result['annot']) {
			return false;
		}
		
		return $this->_decodeAnnot($result['annot']);
	}
};

class phpMorphy_Morphier_PredictBySuffix extends phpMorphy_Morphier_Common {
	var $min_suf_len;
	var $unknown_len;
	
	function phpMorphy_Morphier_PredictBySuffix(&$fsa, &$graminfo, $minimalSuffixLength = 4) {
		parent::phpMorphy_Morphier_Base($fsa, $graminfo);
		
		$this->min_suf_len = $minimalSuffixLength;
	}
	
	function _findWord($word) {
		$word_len = strlen($word);
		
		for($i = 1, $c = $word_len - $this->min_suf_len; $i < $c; $i++) {
			$result = $this->fsa->walk($this->root_trans, substr($word, $i));
			
			if($result['result'] && null !== $result['annot']) {
				break;
			}
		}

		if($i < $c) {
			//$known_len = $word_len - $i;
			$unknown_len = $i;
			
			
			return $this->_fixAnnots(
				$this->_decodeAnnot($result['annot']),
				$unknown_len
			);
		} else {
			return false;
		}
	}
	
	function _fixAnnots($annots, $len) {
		for($i = 0, $c = count($annots); $i < $c; $i++) {
			$annots[$i]['cplen'] = $len;
		}
		
		return $annots;
	}
};

class phpMorphy_PredictMorphier_Collector extends phpMorphy_Fsa_WordsCollector {
	var $used_poses = array();
	var $collected = 0;
	
	function collect($path, $annotRaw) {
		if($this->collected > $this->limit) {
			return false;
		}
		
		$used_poses =& $this->used_poses;
		$annots = $this->decodeAnnot($annotRaw);
		
		for($i = 0, $c = count($annots); $i < $c; $i++) {
			$annot = $annots[$i];
			$annot['cplen'] = $annot['plen'] = 0;
			
			$pos_id = $annot['pos_id'];
			
			if(isset($used_poses[$pos_id])) {
				$result_idx = $used_poses[$pos_id];
				
				if($annot['freq'] > $this->items[$result_idx]['freq']) {
					$this->items[$result_idx] = $annot;
				}
			} else {
				$used_poses[$pos_id] = count($this->items);
				$this->items[] = $annot;
			}
		}
		
		$this->collected++;
		return true;
	}
	
	function clear() {
		parent::clear();
		$this->collected = 0;
		$this->used_poses = array();
	}
	
	function decodeAnnot($annotRaw) {
		$result = array();
		
		$len = strlen($annotRaw);
		if($len % 16 != 0 || !$len) {
			return php_morphy_error("Invalid annot with $len length given");
		}
		
		for($i = 0, $c = strlen($annotRaw); $i < $c; $i += 16) {
			$result[] = unpack(
				'Voffset/vbase_size/vall_size/vancodes_size/a2ancode/vfreq/Cflen/Cpos_id',
				substr($annotRaw, $i, 16)
			);
		}
		
		return $result;
	}
};

class phpMorphy_Morphier_PredictByDatabse extends phpMorphy_Morphier_Base {
	var $collector;
	var $min_postfix_match;
	
	function phpMorphy_Morphier_PredictByDatabse(&$fsa, &$graminfo, $minPostfixMatch = 2, $collectLimit = 32) {
		parent::phpMorphy_Morphier_Base($fsa, $graminfo);
		
		$this->min_postfix_match = $minPostfixMatch;
		$this->collector =& $this->_createCollector($collectLimit);
	}
	
	function _findWord($word) {
		$result = $this->fsa->walk($this->root_trans, strrev($word));
		
		if($result['result'] && null !== $result['annot']) {
			$annots = $result['annot'];
		} else {
			if(null === ($annots = $this->_determineAnnots($result['last_trans'], $result['walked']))) {
				return false;
			}
		}
		
		if(!is_array($annots)) {
			$annots = $this->collector->decodeAnnot($annots);
		}
		
		return $this->_fixAnnots($annots);
	}
	
	// TODO: Refactor this!!!
	function _getAnnotSize() { return 16; }
	function _decodeAnnot($annots) { return $this->collector->decodeAnnot($annots); }
	
	function _determineAnnots($trans, $matchLen) {
		$annots = $this->fsa->getAnnot($trans);
		
		if(null == $annots && $matchLen >= $this->min_postfix_match) {
			$this->collector->clear();
			
			$this->fsa->collect(
				$trans,
				$this->collector->getCallback()
			);
			
			$annots = $this->collector->getItems();
		}

		return $annots;
	}
	
	function _fixAnnots($annots) {
		for($i = 0, $c = count($annots); $i < $c; $i++) {
			$annots[$i]['cplen'] = $annots[$i]['plen'] = 0;
		}
		
		return $annots;
	}
	
	function &_createCollector($limit) {
		$obj =& new phpMorphy_PredictMorphier_Collector($limit);
		return $obj;
	}
};

class phpMorphy_Morphier_Decorator {
	var $morphier;
	
	function phpMorphy_Morphier_Decorator(&$morphier) {
		$this->morphier = $morphier;
	}
	
	function getBaseForm($word) { return $this->morphier->getBaseForm($word); }
	function getAllForms($word) { return $this->morphier->getAllForms($word); }
	function getAllFormsWithGramInfo($word) { return $this->morphier->getAllFormsWithGramInfo($word); }
	
	function &getFsa() { return $this->morphier->getFsa(); }
	function &getGramInfo() { return $this->morphier->getGramInfo(); }
	
	function &getInner() { return $this->morphier; }
}

class phpMorphy_Morphier_WithGramTab extends phpMorphy_Morphier_Decorator {
	var $gramtab;
	var $file_name;
	
	function phpMorphy_Morphier_WithGramTab(&$morphier, &$gramtab) {
		parent::phpMorphy_Morphier_Decorator($morphier);
		$this->gramtab =& $gramtab;
	}
	
	function getAllFormsWithGramInfo($word) {
		if(false !== ($result = $this->morphier->getAllFormsWithGramInfo($word))) {
			$this->_postprocessItems($result);
		}
		
		return $result;
	}
	
	function _postprocessItems(&$result) {
		for($i = 0, $c = count($result); $i < $c; $i++) {
			$res =& $result[$i];
			$res['common'] = $this->gramtab->resolve($res['common']);
			
			$res_all =& $res['all'];
			for($j = 0, $jc = count($res_all); $j < $jc; $j++) {
				$res_all[$j] = $this->gramtab->resolve($res_all[$j]);
			}
		}
	}
};

class phpMorphy_Morphier_WithGramTabBulk extends phpMorphy_Morphier_WithGramTab {
	function _postprocessItems(&$result) {
		foreach(array_keys($result) as $key) {
			parent::_postprocessItems($result[$key]);
		}
	}
}

class phpMorphy_Morphier_Chain {
	var $morphiers = array();
	
	function getMorphiers() { return $this->morphiers; }
	function add(&$morphier) { $this->morphiers[] =& $morphier; }
	
	function getBaseForm($word) {
		return $this->_invoke('getBaseForm', $word);
	}
	
	function getAllForms($word) {
		return $this->_invoke('getAllForms', $word);
	}
	
	function getAllFormsWithGramInfo($word) {
		return $this->_invoke('getAllFormsWithGramInfo', $word);
	}
	
	function _invoke($method, $word) {
		for($i = 0, $c = count($this->morphiers); $i < $c; $i++) {
			if(false !== ($result = $this->morphiers[$i]->$method($word))) {
				return $result;
			}
		}
		
		return false;
	}
};
