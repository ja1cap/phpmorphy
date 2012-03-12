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

class ShmFileManager {
	protected
		$file_path,
		$salt,
		$cache;
	
	function ShmFileManager($filePath, $salt = '*') {
		$this->file_path = $filePath;
		$this->salt = $salt;
	}
	
	function get() {
		if(isset($this->cache)) {
			return $this->cache;
		}
		
		if(false === ($real_path = $this->getRealPath())) {
			throw new phpMorphy_Exception("Can`t determine realpath for $this->file_path file");
		}
		
		$key = $this->getShmKey();
		$path_len = strlen($real_path);
		$file_size = 0;
		
		if(false === ($shm_id = @shmop_open($key, 'a', 0, 0))) {
			$file_size = filesize($real_path);
			
			$segment_size =
				$file_size +
				4 + // path len
				4 + // file size
				strlen($real_path);
			
			// TODO: memory_limit restriction. May be read file chunk by chunk?
			$file_data = $this->readFile();
			
			// TODO: why in win version 'n' flag not works, may be bug in shmget func?
			if(false === ($shm_id = shmop_open($key, 'c', 0644, $segment_size))) {
				throw new phpMorphy_Exception('Can`t create shm segment');
			}
			
			// 1. write file data
			if(strlen($file_data) != shmop_write($shm_id, $file_data, 0)) {
				shmop_delete($shm_id);
				shmop_close($shm_id);
				throw new phpMorphy_Exception('Can`t shmop_write file contents');
			}
			
			$offset = $file_size;
			// 2. write file path
			if($path_len != shmop_write($shm_id, $real_path, $offset)) {
				shmop_delete($shm_id);
				shmop_close($shm_id);
				throw new phpMorphy_Exception('Can`t shmop_write file path');
			}
			$offset += $path_len;

			// 3. write path len
			if(4 != shmop_write($shm_id, pack('V', $path_len), $offset)) {
				shmop_delete($shm_id);
				shmop_close($shm_id);
				throw new phpMorphy_Exception('Can`t shmop_write path len');
			}
			$offset += 4;
			
			// 4. write file size
			// TODO: check filesize() on 64bit archs. is int size in php always 32bit?
			if(4 != shmop_write($shm_id, pack('V', $file_size), $offset)) {
				shmop_delete($shm_id);
				shmop_close($shm_id);
				throw new phpMorphy_Exception('Can`t shmop_write file size');
			}
		} else {
			$shm_size = shmop_size($shm_id);
			
			list($path_len, $file_size) = array_values(
				unpack('Va/Vb', shmop_read($shm_id, $shm_size - 8, 8))
			);
			
			$shm_path = '';
			if($path_len) {
				$shm_path = shmop_read($shm_id, $shm_size - 8 - $path_len, $path_len);
			}
			
			if($shm_path != $real_path) {
				throw new phpMorphy_Exception(
					'Segment collision for ' . $real_path . ' detected ' .
					'(shm path is ' . $shm_path . ')' .
					'try move it to other location'
				);
			}
		}
		
		$this->cache = array(
			'shm_id' => $shm_id,
			'file_size' => $file_size
		);
		
		return $this->cache;
	}
	
	protected function getRealPath() {
		return $this->file_path;
		//return realpath($this->file_path);
	}
	
	protected function readFile() {
		if(false === ($data = file_get_contents($this->file_path))) {
			throw new phpMorphy_Exception("Can`t read $this->file_path file contens");
		}
		
		return $data;
	}
	
	protected function getShmKey() {
		$path = $this->getRealPath();
		
		if(function_exists('ftok')) {
			return ftok($path, $this->salt);
		} else {
			return $this->getShmKeyWin($path);
		}
	}
	
	protected function getShmKeyWin($path) {
		// from PEAR::System::SharedMemory
		if(false === ($s = stat($path))) {
			throw new phpMorphy_Exception("Can`t get stat $path file");
		}
		
		$salt = $this->salt;
		
		return sprintf(
			"%u",
			(
				($s['ino'] & 0xffff) |
				(($s['dev'] & 0xff) << 16) |
				(($salt & 0xff) << 24)
			)
		);
		
		// Old version
		//return crc32($this->salt . $path);
	}
};
