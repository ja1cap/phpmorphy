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

interface phpMorphy_GramTab_IBuilder {
	/**
	 * Build gramifo string from part of speech and grammems string
	 *
	 * @param string $pos Part of speech string
	 * @param string $grammems Grammems string
	 * @return string
	 */
	function build($pos, $grammems);
	
	/**
	 * Join several graminfo strings into one
	 *
	 * @param array $strings
	 * @return string
	 */
	function join($strings);
}
 
class phpMorphy_GramTab_StandartBuilder implements phpMorphy_GramTab_IBuilder {
	function build($pos, $grammems) {
		if($pos) {
			return "$pos $grammems";
		} else {
			return $grammems;
		}
	}
	
	function join($strings) {
		return implode(';', $strings);
	}
};

class phpMorphy_GramTab {
	var $index;
	var $poses;
	var $grammems;
	var $builder;
	
	function phpMorphy_GramTab($raw, phpMorphy_GramTab_IBuilder $builder) {
		$this->builder = $builder;
		
		$data = $this->prepare($raw);
		
		if(
			!is_array($data) ||
			!isset($data['index']) ||
			!isset($data['grammems']) ||
			!isset($data['poses'])
		) {
			throw new phpMorphy_Exception("Broken gramtab data");
		}
		
		$this->index = $data['index'];
		$this->grammems = $data['grammems'];
		$this->poses = $data['poses'];
	}
	
	function resolve($ancodes) {
		if($ancodes) {
			$result = array();
			foreach(str_split($ancodes, 2) as $ancode) {
				$index = $this->index[$ancode];
				
				$result[] = $this->builder->build(
					$this->poses[$index & 0xFF],
					$this->grammems[$index >> 8]
				);
			}
			
			return $this->builder->join($result);
		} else {
			return '';
		}
	}
	
	protected function prepare($data) {
		return unserialize($data);
	}
};
