<?php

/*
	user - manages aspects of tracking a user through sessions
	Copyright (c) 2004, 2011 Thomas Rutter
	
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

// class user - this manages aspects of tracking a user securely, logged in or not,
// through sessions, "stay logged in" and CSRF protection tokens

// this class needs access to the context because it reads and writes get variables and cookies

class user
{
	private
		$context,
		$debug,
		$db,
		$prefix,
		$session_exists = false,
		$sessionhash = null,
		$logged_in = false,
		$userdetails = null,
		$status = null,
		$safe_token,
		$sessionlength,
		$persistlength,
		$strongsessions;

	function __construct($sessionlength = 2400, $persistdays = 90, $strongsessions = true)
	{
		$this->context = &context::getinstance();
		$this->debug = &debug::getinstance();

		$this->sessionlength = $sessionlength;
		$this->persistlength = min($persistdays, 365) * 86400;
		$this->strongsessions = $strongsessions;
	}
	
	public function getdata()
	{
		// check for existing session
		$sessionhash = $this->context->load_var('session', 'COOKIE', 'location');
		if ($sessionhash)
		{
			$expire = TIMENOW - $this->sessionlength;
			$userdetails = $this->db->query_single("
				SELECT 
					u.*, session_IP AS ps_ip, session_uahash AS ps_ua,
					session_loginseqID AS ps_seqid
				FROM
					{$this->prefix}session AS s
					LEFT JOIN {$this->prefix}user AS u ON
						user_ID=session_userID
				WHERE
					session_hash=?
					AND session_lastvisited>=?
				", $sessionhash, $expire);	
				
			if (!empty($userdetails)
				&& $this->sourcecheck($userdetails['ps_ip'], $userdetails['ps_ua']))
			{
				$this->session_exists = true;
				$this->sessionhash = $sessionhash;
				$this->userdetails = $userdetails;
				$this->logged_in = $this->userdetails['user_ID'] > 0;
				
				$timenow = TIMENOW;
				$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'string');
				$uahash = $this->getuahash();
				$this->db->query("
					UPDATE {$this->prefix}session SET session_IP=?,
					session_lastvisited=?, session_uahash=?
					WHERE session_hash=?",
					$ip, $timenow, $uahash, $sessionhash);
			}
			else
				$this->context->setcookie('session', '', 946684800, '/', '', false, true);
		}	

		exit(print_r($_COOKIE));
		
		if (!$this->logged_in 
			&& ($loginuser = $this->context->load_var('stay_logged_in', 'COOKIE', 'location'))
			&& strpos($loginuser, '.'))
		{
			list($userid, $farhash) = explode('.', $loginuser);
			$userid = (int)$userid;
			$expire = TIMENOW - $this->persistlength;
			
			$userdetails = $this->db->query_single("
				SELECT
					u.*,
					userlogin_hashstillvalid AS pl_valid,
					userlogin_newID AS pl_newID,
					userlogin_time AS pl_time,
					userlogin_sequenceID AS pl_seqID
				FROM
					{$this->prefix}userlogin
					INNER JOIN {$this->prefix}user AS u ON
						user_ID=userlogin_userID
				WHERE
					userlogin_userID=?
					AND userlogin_hash=?
					AND userlogin_time>?
					", $userid, $farhash, $expire);
					// hopefully this uses the index with the hash rather than the time
			
			if ($userdetails)
			{
				if ($userdetails['pl_valid'] ||
					// or let it slide if it was valid <30 seconds ago
					($userdetails['pl_newID'] && $userdetails['pl_time'] > (TIMENOW-30)))
				{
					$this->debug->notice('user', 'Checking cookie', 'Valid persistent cookie found');
					$this->userdetails = $userdetails;
					if ($userdetails['pl_valid']) // unless we were let in under the '30 second rule'
						$this->processlogin($userdetails, true, $userdetails['pl_seqID']);
				}
				else
				{
					$this->handlesuspect($userdetails['user_ID'], $userdetails['pl_newID']);
					$this->context->setcookie('stay_logged_in', '', 946684800, '/', '', false, true);
				}
			}
			else
				$this->context->setcookie('stay_logged_in', '', 946684800, '/', '', false, true);
	  	}
		$this->safe_token = $this->session_exists ?
			user::uhash($this->sessionhash.'bF2b3J1cOYPmS0vCgCFsmzRNiKckn50LRj47zPOHtTU') : '';
		
		return array(
			'details' => $this->logged_in ? $this->userdetails : array(
				'user_ID' => 0, 'user_name' => 'Guest',),
			'logged_in' => $this->logged_in,
			'session_exists' => $this->session_exists,
			'token' => $this->safe_token,
			);
	}
	
	private function sourcecheck($oldip, $oldua)
	// checks if the ip address and user agent currently in use is sufficiently similar to
	// the recorded ones given, to help make session hijacking harder
	{
		$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'string');
		$uahash = $this->getuahash();
		$ipstart1 = preg_replace('/\d++[.:]\d++$|\d++$/', '', $oldip);
		$ipstart2 = preg_replace('/\d++[.:]\d++$|\d++$/', '', $ip);

		return $this->strongsessions
			? ($ipstart1 == $ipstart2 && $oldua == $uahash)
			: ($ipstart1 == $ipstart2 || $oldua == $uahash);
	}
	
	private function handlesuspect($userid, $newloginid = null)
	// what to do when we see something suspicious like and invalid hash re-used
	// or session var crossing IP and user-agent boundaries
	{
		$this->debug->notice('user', 'Suspicious credentials', 'Killing session');
		$newloginid = (int)$newloginid; $userid = (int)$userid;
		$this->db->query("DELETE FROM {$this->prefix}session
			WHERE session_userID=?", $userid);
		if ($newloginid)
		{
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_newID=0, userlogin_hashstillvalid=0
				WHERE userlogin_userID=? AND userlogin_newID=?",
				$userid, $newloginid);
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_newID=0, userlogin_hashstillvalid=0, userlogin_suspicious=1
				WHERE userlogin_userID=? AND userlogin_sequenceID=?",
				$userid, $newloginid);

			$this->status = 'loginfail_replayattack';
		}
		else $this->status = 'sessionfail_suspicious';
	}

	private function getuahash()
	{
		$ua = $this->context->load_var('HTTP_USER_AGENT', 'SERVER', 'string');
		$uahash = crc32(preg_replace('/[^a-za-z();]++/', ' ', $ua));
		return ($uahash >= 0x80000000) ? ($uahash - 0x100000000) : $uahash;
	}

	private function startsession($userid = 0, $seqid = 0)
	// starts a session, with optional userid and login sequence ID.
	// if a session is already active & not logged in, modifies the existing session instead 
	{
		$userid = (int)$userid; $seqid = (int)$seqid;
		if ($this->session_exists && !$this->logged_in)
		{ // already we have a non-authenticated session
			if (!$userid) return;
			return $this->db->query("UPDATE {$this->prefix}session
				SET session_userID=?, session_loginseqID=?
				WHERE session_hash=?",
				$userid, $seqid, $this->sessionhash);
		}
		
		$this->debug->notice('user', 'Creating session');
		$this->sessionhash = user::randhash('sessionhash');
			
		$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'string');
		$uahash = $this->getuahash();
		$timenow = TIMENOW;
		
		$this->db->query("
			REPLACE INTO {$this->prefix}session
			SET session_hash=?, session_userID=?,
			session_loginseqID=?, session_IP=?, session_uahash=?,
			session_lastvisited=?
			", $this->sessionhash, $userid, $seqid, $ip, $uahash, $timenow);
		$this->context->setcookie('session', $this->sessionhash, 0, '/', '', false, true);
		$this->session_exists = true;
		
		// occasionally delete expired sessions
		if (mt_rand(0,100 == 50))
			$this->db->query("DELETE FROM {$this->prefix}session 
				WHERE session_lastvisited < ?",
				$timenow-$this->sessionlength);
	}
	
	public function processlogout()
	// logs the user out.  also loses session and "stay logged in" for this browser
	{
		if (!$this->session_exists) return;
		$this->db->query("DELETE FROM {$this->prefix}session
			WHERE session_hash=?", $this->sessionhash);
			
		if ($this->logged_in && !empty($this->userdetails['ps_seqid']))
		{
			$userid = $this->userdetails['user_ID'];
			$seqid = $this->userdetails['ps_seqid'];
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hash=NULL, userlogin_hashstillvalid=0, userlogin_newID=0
				WHERE userlogin_userID=? AND userlogin_sequenceID=?",
				$userid, $seqid);
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hash=NULL, userlogin_newID=0
				WHERE userlogin_userID=? AND userlogin_newID=?",
				$userid, $seqid);
			$this->context->setcookie('stay_logged_in', '', 946684800, '/', '', false, true);
		}
		$this->context->setcookie('session', '', 946684800, '/', '', false, true);
		$this->logged_in = $this->session_exists = false;
	}
	
	public function processlogin($userdetails, $persistent = false, $oldseqid = null)
	{ 
		if (empty($userdetails['user_ID'])) return;
		$hashstillvalid = $persistent ? 1 : 0;
		
		$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'string');
		$host = !empty($_SERVER['REMOTE_HOST']) ? $_SERVER['REMOTE_HOST']
			: gethostbyaddr($ip);
		$useragent = !empty($_SERVER['HTTP_USER_AGENT']) ?
			$_SERVER['HTTP_USER_AGENT'] : '';
		$userid = (int)$userdetails['user_ID'];
		$timenow = TIMENOW;
		$userhash = $persistent ? user::randhash('userhash') : null;
		list($seqid) = $this->db->query_single("
			SELECT MAX(userlogin_sequenceID) FROM {$this->prefix}userlogin
			WHERE userlogin_userid=?", $userid);
		$seqid++;
		
		$this->db->query("
			INSERT INTO {$this->prefix}userlogin
			SET
				userlogin_userID=?, userlogin_sequenceID=?,
				userlogin_hash=?, userlogin_hashstillvalid=?,
				userlogin_IP=?, userlogin_host=?,
				userlogin_useragent=?, userlogin_time=?
			",
			$userid, $seqid,
			$userhash, $hashstillvalid,
			$ip, $host,
			$useragent, $timenow
			);
			
		if ($oldseqid)
		{
			// invalidate and re-point old logins
			$oldseqid = (int)$oldseqid;
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hashstillvalid=0, userlogin_newID=?
				WHERE userlogin_userid=? AND userlogin_sequenceID=?
				", $seqid, $userid, $oldseqid);
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_newID=?
				WHERE userlogin_userid=? AND userlogin_newID=?
				", $seqid, $userid, $oldseqid);
		}
		if (mt_rand(0,200) == 50) // occasional clean up, also saves some space
		{
			$expire = TIMENOW - $this->persistlength;
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hash=NULL, userlogin_newID=0, userlogin_hashstillvalid=0
				WHERE userlogin_userid=? AND userlogin_time<?
				", $userid, $expire);
		}
	
		$this->logged_in = true;
		$this->userdetails = $userdetails;
		$this->userdetails['ps_seqid'] = $seqid;
		$this->startsession($this->userdetails['user_ID'], $seqid);
		
		if ($persistent) $this->context->setcookie('stay_logged_in', 
			$this->userdetails['user_ID'] . ".$userhash",
			$timenow+$this->persistlength, '/', '', false, true);
	}
	
	public static function randhash($seed = '')
	// generates random hash, should be hard to predict but slowish
	{		
		return user::uhash(uniqid($seed,true).'c8PMLhAlevWdEbNf9BRjWhbxhbkTaThJo9wwCadYiys'
			. serialize($_SERVER).mt_rand().__FILE__.time().serialize($_ENV));		
	}
	
	public static function uhash($d)
	{
		return trim(strtr(base64_encode(hash('sha256',$d,1)),'+/=','-_ '));
	}

	public function setdb(&$db)
	{
		$this->db = &$db;
		$this->prefix = $this->db->get_prefix();
	}
	
	public static function &getinstance()
	{
		static $instance;
		if (!isset($instance)) $instance = new user(); 
		return $instance;
	}
}

?>
