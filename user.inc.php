<?php

// user.inc.php

// class user - this manages aspects of tracking a user securely, logged in or not,
// through sessions, "stay logged in" and CSRF protection tokens

// this class needs access to the context because it reads and writes get variables and cookies

define('USER_SESSIONLENGTH', 2400);
define('USER_PERSISTLENGTH', 86400*90);
define('USER_STRENGTHENSESSIONS', true);

class user
{
	function user()
	{
		$this->context = &context::getinstance();
		$this->debug = &debug::getinstance();
		
		$this->session_exists = false;
		$this->sessionhash = null;
		$this->logged_in = false;
		$this->userdetails = null;
		$this->status = null;
	}
	
	function getdata()
	{
		// check for existing session
		$sessionhash = $this->context->load_var('session', 'COOKIE', 'location');
		if ($sessionhash)
		{
			$expire = TIMENOW - USER_SESSIONLENGTH;
			$userdetails = $this->db->query_single("
				SELECT 
					u.*, session_IP AS ps_ip, session_uahash AS ps_ua,
					session_loginseqID AS ps_seqid
				FROM
					{$this->prefix}session AS s
					LEFT JOIN {$this->prefix}user AS u ON
						user_ID=session_userID
				WHERE
					session_hash='$sessionhash'
					AND session_lastvisited>=$expire
				");	
				
			if (!empty($userdetails)
				&& $this->sourcecheck($userdetails['ps_ip'], $userdetails['ps_ua']))
			{
				$this->session_exists = true;
				$this->sessionhash = $sessionhash;
				$this->userdetails = $userdetails;
				$this->logged_in = $this->userdetails['user_ID'] > 0;
				
				$timenow = TIMENOW;
				$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'location');
				$ua = $this->context->load_var('HTTP_USER_AGENT', 'SERVER', 'string');
				$uahash = crc32(preg_replace('/[^a-zA-Z();]++/', ' ', $ua));
				$this->db->query("
					UPDATE {$this->prefix}session SET session_IP='$ip',
					session_lastvisited=$timenow, session_uahash=$uahash
					WHERE session_hash='$sessionhash'");
			}
			else
				$this->context->setcookie('session', '', 946684800, '/', '', false, true);
		}	
		
		if (!$this->logged_in 
			&& ($loginuser = $this->context->load_var('stay_logged_in', 'REQUEST', 'location'))
			&& strpos($loginuser, '.'))
		{
			list($userid, $farhash) = explode('.', $loginuser);
			$userid = (int)$userid;
			$farhash = addslashes($farhash);
			$expire = TIMENOW - USER_PERSISTLENGTH;
			
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
					userlogin_userID=$userid
					AND userlogin_hash='$farhash'
					AND userlogin_time>$expire
				"); // hopefully this uses the index with the hash rather than the time
			
			if ($userdetails)
			{
				if ($userdetails['pl_valid'] ||
					// or let it slide if it was valid <30 seconds ago
					($userdetails['pl_newID'] && $userdetails['pl_time'] > (TIMENOW-30)))
				{
					$this->debug->notice('user', 'Valid persistent cookie found');
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
	
	function sourcecheck($oldip, $oldua)
	// checks if the ip address and user agent currently in use is sufficiently similar to
	// the recorded ones given, to help make session hijacking harder
	{
		$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'location');
		$ua = $this->context->load_var('HTTP_USER_AGENT', 'SERVER', 'string');
		$uahash = crc32(preg_replace('/[^a-zA-Z();]++/', ' ', $ua));
		list($oldip1, $oldip2) = explode('.', $oldip);
		list($ip1, $ip2) = explode('.', $ip);
		
		if (USER_STRENGTHENSESSIONS
			&& ($oldip1!=$ip1 || $oldip2!=$ip2 || $oldua!=$uahash))
			return false;
		
		return ($oldua==$uahash || ($oldip1==$ip1 && $oldip2==$ip2));
	}
	
	function handlesuspect($userid, $newloginid = null)
	// what to do when we see something suspicious like and invalid hash re-used
	// or session var crossing IP and user-agent boundaries
	{
		$this->debug->notice('user', 'Suspicious credentials; killing session');
		$newloginid = (int)$newloginid; $userid = (int)$userid;
		$this->db->query("DELETE FROM {$this->prefix}session
			WHERE session_userID=$userid");
		if ($newloginid)
		{
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_newID=0, userlogin_hashstillvalid=0
				WHERE userlogin_userID=$userid AND userlogin_newID=$newloginid");
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_newID=0, userlogin_hashstillvalid=0, userlogin_suspicious=1
				WHERE userlogin_userID=$userid AND userlogin_sequenceID=$newloginid");
			$this->status = 'loginfail_replayattack';
		}
		else $this->status = 'sessionfail_suspicious';
	}

	function startsession($userid = 0, $seqid = 0)
	// starts a session, with optional userid and login sequence ID.
	// if a session is already active & not logged in, modifies the existing session instead 
	{
		$userid = (int)$userid; $seqid = (int)$seqid;
		if ($this->session_exists && !$this->logged_in)
		{ // already we have a non-authenticated session
			if (!$userid) return;
			return $this->db->query("UPDATE {$this->prefix}session
				SET session_userID=$userid, session_loginseqID=$seqid
				WHERE session_hash='{$this->sessionhash}'");
		}
		
		$this->debug->notice('user', 'Creating new session');
		$this->sessionhash = user::randhash('sessionhash');
			
		$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'location');
		$ua = $this->context->load_var('HTTP_USER_AGENT', 'SERVER', 'string');
		$uahash = crc32(preg_replace('/[^a-zA-Z();]++/', ' ', $ua));
		$timenow = TIMENOW;
		
		$this->db->query("
			REPLACE INTO {$this->prefix}session
			SET session_hash='{$this->sessionhash}', session_userID=$userid,
			session_loginseqID=$seqid, session_IP='$ip', session_uahash=$uahash,
			session_lastvisited=$timenow
			");
		$this->context->setcookie('session', $this->sessionhash, 0, '/', '', false, true);
		$this->session_exists = true;
		
		// occasionally delete expired sessions
		if (mt_rand(0,100 == 50))
			$this->db->query("DELETE FROM {$this->prefix}session 
				WHERE session_lastvisited <" . ($timenow-USER_SESSIONLENGTH));
	}
	
	function processlogout()
	// logs the user out.  also loses session and "stay logged in" for this browser
	{
		if (!$this->session_exists) return;
		$this->db->query("DELETE FROM {$this->prefix}session
			WHERE session_hash='{$this->sessionhash}'");
			
		if ($this->logged_in && !empty($this->userdetails['ps_seqid']))
		{
			$userid = $this->userdetails['user_ID'];
			$seqid = $this->userdetails['ps_seqid'];
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hash=NULL, userlogin_hashstillvalid=0, userlogin_newID=0
				WHERE userlogin_userID=$userid AND userlogin_sequenceID=$seqid");
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hash=NULL, userlogin_newID=0
				WHERE userlogin_userID=$userid AND userlogin_newID=$seqid");
			$this->context->setcookie('stay_logged_in', '', 946684800, '/', '', false, true);
		}
		$this->context->setcookie('session', '', 946684800, '/', '', false, true);
		$this->logged_in = $this->session_exists = false;
	}
	
	function processlogin($userdetails, $persistent = false, $oldseqid = null)
	{ 
		if (empty($userdetails['user_ID'])) return;
		$hashstillvalid = $persistent ? 1 : 0;
		
		$ip = $this->context->load_var('REMOTE_ADDR', 'SERVER', 'location');
		$hostsl = !empty($_SERVER['REMOTE_HOST']) ? addslashes($_SERVER['REMOTE_HOST'])
			: addslashes(gethostbyaddr($ip));
		$useragentsl = !empty($_SERVER['HTTP_USER_AGENT']) ?
			addslashes($_SERVER['HTTP_USER_AGENT']) : '';
		$userid = (int)$userdetails['user_ID'];
		$timenow = TIMENOW;
		$userhash = $persistent ? user::randhash('userhash') : '';
		$userhasheq = ($userhash!='') ? "userlogin_hash='$userhash'," : '';
		list($seqid) = $this->db->query_single("
			SELECT MAX(userlogin_sequenceID) FROM {$this->prefix}userlogin
			WHERE userlogin_userid=$userid");
		$seqid++;
		
		$this->db->query("
			INSERT INTO {$this->prefix}userlogin
			SET
				userlogin_userID=$userid, userlogin_sequenceID=$seqid,
				$userhasheq userlogin_hashstillvalid=$hashstillvalid,
				userlogin_IP='$ip', userlogin_host='$hostsl',
				userlogin_useragent='$useragentsl', userlogin_time=$timenow
			");
			
		if ($oldseqid)
		{
			// invalidate and re-point old logins
			$oldseqid = (int)$oldseqid;
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hashstillvalid=0, userlogin_newID=$seqid
				WHERE userlogin_userid=$userid AND userlogin_sequenceID=$oldseqid
				");
			$this->db->query("UPDATE {$this->prefix}userlogin
				SET userlogin_newID=$seqid
				WHERE userlogin_userid=$userid AND userlogin_newID=$oldseqid
				");
		}
		if (mt_rand(0,200) == 50) // occasional clean up, also saves some space
		{
			$expire = TIMENOW - USER_PERSISTLENGTH;
			$this->query("UPDATE {$this->prefix}userlogin
				SET userlogin_hash=NULL, userlogin_newID=0, userlogin_hashstillvalid=0
				WHERE userlogin_userid=$userid AND userlogin_time<$expire
				");
		}
	
		$this->logged_in = true;
		$this->userdetails = $userdetails;
		$this->userdetails['ps_seqid'] = $seqid;
		$this->startsession($this->userdetails['user_ID'], $seqid);
		
		if ($persistent) $this->context->setcookie('stay_logged_in', 
			$this->userdetails['user_ID'] . ".$userhash",
			$timenow+USER_PERSISTLENGTH, '/', '', false, true);
	}
	
	// static
	function randhash($seed = '')
	// generates random hash, should be hard to predict but slowish
	{		
		return user::uhash(uniqid($seed,true).'c8PMLhAlevWdEbNf9BRjWhbxhbkTaThJo9wwCadYiys'
			. serialize($_SERVER).mt_rand().__FILE__.time().serialize($_ENV));		
	}
	
	// static
	function uhash($d)
	{
		if (function_exists('hash')) // "url-compatible base64" coding of the sha256 hash
			return trim(strtr(base64_encode(hash('sha256',$d,1)),'+/=','-_ '));
		return sha1($d); // php4 compatible - remove later
	}

	function setdb(&$db)
	{
		$this->db = &$db;
		$this->prefix = $this->db->get_prefix();
	}
	
	function &getinstance()
	{
		static $instance;
		if (!isset($instance)) $instance = new user(); 
		return $instance;
	}
}

?>