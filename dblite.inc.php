<?php 

/*
	dblite - extension to sqlite3 interface to add some functionality
	Copyright (c) 2016 Thomas Rutter
	
	This file is part of Bluestone.
	
	Redistribution and use in source and binary forms, with or without
	modification, are permitted provided that the following conditions are met:
		* Redistributions of source code must retain the above copyright
			notice, this list of conditions and the following disclaimer.
		* Redistributions in binary form must reproduce the above copyright
			notice, this list of conditions and the following disclaimer in the
			documentation and/or other materials provided with the distribution.
		* Neither the name of the author nor the names of contributors may be used
			to endorse or promote products derived from this software without
			specific prior written permission.

	THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
	ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
	WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
	DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
	FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
	DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
	SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
	CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
	OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
	OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

class dblite extends SQLite3 {

	private $statements = array();

	static function valueString($arr) {
	// creates a value string of question marks from a one- or more-dimensional array
	// one-dimensional arrays will result in string like '?,?,?,?'
	// two-dimensional arrays will result in string like '(?,?),(?,?),(?,?)'
	// and each child array in a parent must have the same number of values
	// and no arrays must be empty
		$first = reset($arr);
		$val = is_array($first) ? ('(' . self::valueString($first) . ')') : '?';
		$count = count($arr);

		if ($count > 1) $val .= str_repeat(",$val", $count - 1);
		
		return $val;
	}

	function query($str) {
		// if any additional params appear after $str, this prepares a statement
		// and uses the additional params as the bound values.
		// eg $dblite->query("SELECT * FROM a WHERE b = ?", $myvalue);
		// extra params can include arrays to bind all their members
		// named parameters are not supported, only numbered (eg question marks)
		if (func_num_args() <= 1) 
			return parent::query($str);

		$extra = array_slice(func_get_args(), 1);
		$flat = array(0 => null);
		array_walk_recursive($extra, function($val, $key) use (&$flat) {
			$flat[] = $val;
		});

		$hash = hash('ripemd160', $str);
		if (isset($this->statements[$hash])) {
			$statement = $this->statements[$hash];
			$statement->reset();
			//$statement->clear();
		}
		else {
			$statement = parent::prepare($str);
			$this->statements[$hash] = $statement;

			if (count($this->statements) == 6)
				array_shift($this->statements);
		}
		
		foreach ($flat as $id => $val) if ($id != 0) {

			if (is_int($val) || is_bool($val))
				$statement->bindValue($id, $val, SQLITE3_INTEGER);
			elseif (is_string($val))
			// strings that are valid UTF-8 are saved as text, otherwise blobs, there is a small chance of problem
			// if you intend something as a blob and by coincidence it happens to be valid UTF-8
				$statement->bindValue($id, $val, $val == '' || preg_match('/^[\x20-\x7e\x0a\x0d\x09\PC\p{Cf}\p{Co}]*$/u', $val) ?
					SQLITE3_TEXT : SQLITE3_BLOB);
			elseif (is_null($val))
				$statement->bindValue($id, $val, SQLITE3_NULL);
			elseif (is_float($val))
				$statement->bindValue($id, $val, SQLITE3_FLOAT);
			else
				throw new Exception('Unknown variable type');

		}
		return $statement->execute();
	}

	function querySingle($str, $wholerow = false) {
		// as with extended query() above, but bound values begin at the third argument due to the
		// $entire_row argument
		if (func_num_arts() <= 2)
			return parent::querySingle($str, $wholerow);

		$extra = array_slice(func_get_args(), 2);
		$result = $this->query($str, $extra);

		if (!$wholerow)
			return $result->fetchArray(SQLITE3_NUM)[0];

		return $result->fetchArray(SQLITE3_ASSOC);
	}

}
