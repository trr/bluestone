<?php

/*
	bluestonedata - information required for setting up bluestone
	Copyright (c) 2004, 2009 Thomas Rutter
	
	This file is part of Bluestone.
	
	Bluestone is free software: you can redistribute it and/or modify
	it under the terms of the GNU Lesser General Public License as 
	published by the Free Software Foundation, either version 3 of
	the License, or (at your option) any later version.
	
	Bluestone is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Lesser General Public License for more details.
	
	You should have received a copy of the GNU Lesser General Public
	License along with Bluestone.  If not, see	
	<http://www.gnu.org/licenses/>.
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
					'session_IP'          => array('char(47)', ''),
					'session_uahash'		=> array('int(11)', '0'),
					'session_loginseqID'	=> array('int(11)', '0'),
					'session_lastvisited' => array('int(11)', '0'),
					);
				$tabledefs['session']['indexes'] = array(
					'PRIMARY'              => array('PRIMARY', 'session_hash'),
					'session_index_userID' => array('KEY', 'session_userID'),
					);
				$tabledefs['session']['isheap'] = true;
				
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
					'userlogin_host'			=> array('varchar(128)', '0'),
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

