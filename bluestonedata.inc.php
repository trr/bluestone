<?php

/*
	bluestonedata - information required for setting up bluestone
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

// to be used in installer/upgrade scripts

// provides setup data for installing a bluestone app

class bluestonedata
{
	public static function gettabledefs($modules = array())
	// returns table definitions for specified modules.  at present
	// only 'user' module needs database tables
	{
		if (!is_array($modules)) $modules = array($modules);

		$tabledefs = array();

		foreach ($modules as $module)
		{
			switch ($module)
			{
			case 'user':

				/* session */
				
				$tabledefs['session']['fields'] = array(
					'session_hash'        => array('char(43)', ''),
					'session_userID'      => array('int(11)', '0'),
					'session_IP'          => array('varchar(47)', ''),
					'session_uahash'		=> array('int(11)', '0'),
					'session_loginseqID'	=> array('int(11)', '0'),
					'session_lastvisited' => array('int(11)', '0'),
					);
				$tabledefs['session']['indexes'] = array(
					'PRIMARY'              => array('PRIMARY', 'session_hash'),
					'session_index_userID' => array('KEY', 'session_userID'),
					);
				
				/* user */
				
				$tabledefs['user']['fields'] = array(
					'user_ID'       => array('int(11)', NULL, false, 'AUTO_INCREMENT'),
					);
				$tabledefs['user']['indexes'] = array(
					'PRIMARY'             => array('PRIMARY', 'user_ID'),
					);
					
				/* userlogin */
				
				$tabledefs['userlogin']['fields'] = array(
					'userlogin_userID'			=> array('int(11)', '0'),
					'userlogin_sequenceID'	=> array('int(11)', '0'),
					'userlogin_hash'			=> array('varchar(43)', null, true),
					'userlogin_hashstillvalid'	=> array('tinyint(1)', '0'),
					'userlogin_suspicious'	=> array('tinyint(1)', '0'),
					'userlogin_newID'			=> array('bigint(20)', '0'),
					'userlogin_IP'				=> array('varchar(47)', '0'),
					'userlogin_useragent'	=> array('varchar(255)', '0'),
					'userlogin_time'			=> array('int(11)', '0'),
					);
				$tabledefs['userlogin']['indexes'] = array(
					'PRIMARY'              => array('PRIMARY', 'userlogin_userID,userlogin_sequenceID'),
					'userlogin_index_userID_hash' => array('UNIQUE', 'userlogin_userID,userlogin_hash'),
					'userlogin_index_userID_time' => array('KEY', 'userlogin_userID,userlogin_time'),
					'userlogin_index_userID_hashstillvalid' => array(
						'KEY', 'userlogin_userID,userlogin_hashstillvalid'),
					'userlogin_index_userID_newID' => array('KEY', 'userlogin_userID,userlogin_newID'),
					'userlogin_index_suspicious' => array('KEY', 'userlogin_suspicious,userlogin_time'),
					);

				break;
			}
		}

		return $tabledefs;
	}
}

