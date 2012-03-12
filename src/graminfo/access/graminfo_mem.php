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
 * This file is autogenerated at Fri, 30 Mar 2007 04:07:59 +0400, don`t change it!
 */
class phpMorphy_Graminfo_Mem extends phpMorphy_Graminfo {
	function readGramInfoHeader($offset) {
		$mem = $this->resource;
		 
		
		$result = unpack(
			'vid/vfreq/vancodes_offset/vfull_size/vbase_size',
			substr($mem, $offset + 4, 4) 
		);
		
		$result['offset'] = $offset;
		$result['all_size'] = $result['ancodes_offset'];
		$result['ancodes_size'] = $result['full_size'] - $result['all_size'];
		
		return $result;
	}

	function readAncodes($info) {
		$mem = $this->resource;
		 
		return explode("\x0", substr($mem, $info['offset'] + 10 + $info['all_size'], $info['ancodes_size'] - 1));
	}
	
	function readFlexiaData($info, $onlyBase) {
		$mem = $this->resource;
		 
		return explode("\x0", substr($mem, $info['offset'] + 10, ($onlyBase ? $info['base_size'] : $info['all_size']) - 1));
	}
	
	function readAllGramInfoOffsets() {
		$mem = $this->resource;
		
		$result = array();
		for($offset = 0x100, $i = 0, $c = $this->header['flex_count']; $i < $c; $i++) {
			$result[] = $offset;
			
			$header = $this->readGramInfoHeader($offset);
			
			// skip padding
			$flexia_size = 10 + $header['full_size'];
			
			 
			$pad_len = ord(substr($mem, $offset + $flexia_size, 1));
			
			$offset += $flexia_size + $pad_len + 1;
		}
		
		return $result;
	}
}
