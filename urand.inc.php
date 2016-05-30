<?php 

/*
	urand - random number generation based on CSPRNG
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

class urand {

	public static function fraction() {
	// returns random float value 0 <= x < 1.0
	// based on 53 bits of randomness
	// PHP only prints 14 digits of floats by default which is below 47 significant bits
	// but stores them with 53 bits - just below 17 digits precision

		// reading ahead doesn't buy anything here
		do {
			$v = unpack('V2', (openssl_random_pseudo_bytes(7) & "\xff\xff\xff\x1f\xff\xff\xff") . "\0");

			$f = (($v['1'] / 0x20000000) + $v['2']) / 0x01000000;
		}
		while ($f >= 1.0); // shouldn't happen if using 64-bit IEEE754 doubles

		/*
		echo number_format($f, 53) . '  ';
		for ($i = 64; $i--;) {
			$f = $f * 2;
			echo $f >= 1 ? "X" : '.';
			if ($f >= 1) $f = $f - 1;
		}
		echo "\n";
		 */
		
		return $f;
	}

	public static function discrete($range = null) {
		// returns value in the range of [0 ... $range - 1] where all numbers in that range
		// are equally likely
		// If range is not specified, returns value in [0 ... 0x7fffffff] (2^31 - 1) - full 31 bits

		static $vals = false;
		
		do {
			// we read ahead 10 values at a time for performance
			if (!$vals)
				$vals = unpack("V10", openssl_random_pseudo_bytes(40) & 
				"\xff\xff\xff\x7f\xff\xff\xff\x7f\xff\xff\xff\x7f\xff\xff\xff\x7f\xff\xff\xff\x7f" .
				"\xff\xff\xff\x7f\xff\xff\xff\x7f\xff\xff\xff\x7f\xff\xff\xff\x7f\xff\xff\xff\x7f");

			$v = array_pop($vals);

			// even though we're stripping bits and wasting entropy, there were no speed gains
			// from requesting fewer random bytes
			if (!($range & ($range - 1))) {
				return $v & ($range - 1);
			}
		}
		while ($v >= $range * floor(0x7fffffff / $range));

		return $v % $range;
	}

}
