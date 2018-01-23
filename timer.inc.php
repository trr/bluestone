<?php

/*
	timer - simple benchmarking variant of tester
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

if (!defined('BLUESTONE_DIR'))
	define('BLUESTONE_DIR', __DIR__);
require_once(BLUESTONE_DIR . '/debug.inc.php');
require_once(BLUESTONE_DIR . '/tester.inc.php');

class timer extends tester
{
	protected static
		$times = array();

	private
		$expected,
		$timer,
		$hash = '';

	public static function report() {

		$htmlformat = !empty($_SERVER['REQUEST_URI']);

		if ($htmlformat) {
			echo '<table cellspacing="0" border="0" cellpadding="6">';
			echo '<tr bgcolor="#f8f8f8"><th>Class</th><th>Function</th><th>Test</th><th>Time (ms)</th><th>Kreps/s</th><th>MB/s</th><th>Source</th></tr>';
		}

		foreach (self::$times as $class => $funcs) {
			$numclass = 0;
			foreach ($funcs as $func => $times) $numclass += count($times);

			$firstinclass = true;
			foreach ($funcs as $func => $times) {
				$numfunc = count($times);
				$firstinfunc = true;

				foreach ($times as $row) {

					$cols = array(
						$row['desc'],
						number_format($row['elapsed'] * 1000, 2),
						$row['rate'] ? number_format($row['rate'] / 1000, 2) : '',
						$row['datarate'] ? number_format($row['datarate'] / 1048576, 2) : '',
						basename($row['file']) . ', line ' . $row['line']
					);
					$widths = array(26, 9, 9, 8, 40);
					$rightalign = array(0, 1, 1, 1, 0);

					if ($htmlformat) {
						echo '<tr>';
						if ($firstinclass) echo "<td rowspan=\"$numclass\" valign=\"top\">" . htmlspecialchars($class) . '</td>';
						if ($firstinfunc) echo "<td rowspan=\"$numfunc\" valign=\"top\">" . htmlspecialchars($func) . '</td>';
						foreach ($cols as $id => $col) if ($col !== null) {
							$_align = $rightalign[$id] ? ' align="right"' : '';
							echo "<td$_align>" . htmlspecialchars($col) . '</td>';

						}
					}
					else {
						if ($firstinclass) echo "Class $class:\n";
						if ($firstinfunc) echo "  $func:\n";
						foreach ($cols as $id => $col)
							$cols[$id] = str_pad($col, $widths[$id], ' ', $rightalign[$id] ? STR_PAD_LEFT : STR_PAD_RIGHT);
						echo '    ' . implode('  ', $cols) . "\n";
					}

					$firstinclass = $firstinfunc = false;
				}
			}
		}

		if ($htmlformat) echo '</table>';
		if (ob_get_length()) ob_flush();



		$debug = debug::getinstance();
		$debug->halt(null, 0);
	}

	public function start($description, $reps = null, $inputlen = null) {

		if ($this->timer)
			$this->recordtime();

		$func = 'time';
		$file = __FILE__;
		$line = null;
		$traces = debug_backtrace();
		if (count($traces) >= 2)
		{
			$func = $traces[1]['function'];
			$file = $traces[0]['file'];
			$line = $traces[0]['line'];
		}

		$this->timer = array(
			'desc' => $description,
			'class' => get_class($this),
			'func' => $func,
			'file' => $file,
			'line' => $line,
			'reps' => $reps,
			'inputlen' => $inputlen,
		);
        $this->timer['starttime'] = microtime(true);

	}

	protected function recordtime() {

        $time = microtime(true);
		$timer = $this->timer;

		$elapsed = $time - $timer['starttime'];

		$timer += array(
			'elapsed' => $elapsed,
			'rate' => $timer['reps'] ? ($timer['reps'] / $elapsed) : null,
			'datarate' => ($timer['reps'] && $timer['inputlen']) ? ($timer['reps'] * $timer['inputlen'] / $elapsed) : null
		);

		self::$times[get_class($this)][$timer['func']][] = $timer;
	}

	public function runtest($method) {

		$this->expected = null;
		$this->timer = null;
		$this->hash = '';

		$this->$method();

		if ($this->timer)
			$this->recordtime();

		/*
		if ($this->expected && $notice['taskelapsed'] > $this->expected) {
			$this->passed = false;
			$inmsg = $func = '';
			$traces = debug_backtrace();
			if (count($traces) >= 2)
			{
				list($trace0, $trace1) = $traces;
				$inmsg = " in $trace0[file] line $trace0[line]";
				$func = "$trace1[function]: ";
			}
			$debug->notice('timer', 'Too slow', $func . number_format($notice['taskelapsed'] * 1000, 2) . 'ms (expected ' .
				number_format($this->expected * 1000, 2) . 'ms)');
		}
		 */
	}

	public function assert_time($milliseconds) {
		$this->expected = $milliseconds / 1000;
	}

	public function genbytes($len = 240) {

		$hash = $this->hash;
		$output = '';
		while ($len >= 40) {
			$output .= ($hash = hash('ripemd320', $hash, true));
			$len -= 40;
		}

		if ($len > 0) {
			$hash = hash('ripemd320', $hash, true);
			$output .= substr($hash, 0, $len);
		}

		$this->hash = $hash;
		return $output;
	}

	public function genascii($len = 240) {
		return preg_replace('/[^\x20-\x7e\x0a\x0d\x09]/', ' ', self::genbytes($len) & str_repeat("\x7f", $len));
	}

	public function genutf8($len = 240) {
		return preg_replace('/[^\x20-\x7e\x0a\x0d\x09\PC\p{Cf}\p{Co}]/u', ' ', utf8_encode(self::genbytes($len)));
	}
}

?>
