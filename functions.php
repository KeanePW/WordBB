<?php

define('WORDBB_CAT','cat');
define('WORDBB_POST','post');
define('WORDBB_USER','user');

define('WORDBB_WP','wp');
define('WORDBB_MYBB','mybb');

function wordbb_select_mybb_db()
{
	global $wpdb, $wordbb;

	if($wordbb->mybbdb)
		return;

	$dbuser=get_option('wordbb_dbuser');
	$dbpass=get_option('wordbb_dbpass');
	$dbname=get_option('wordbb_dbname');
	$dbhost=get_option('wordbb_dbhost');

	if(empty($dbuser) && empty($dbpass) && empty($dbname))
	{
		$wordbb->mybbdb=&$wpdb;
		return true;
	}

	if(empty($dbhost))
		$dbhost=DB_HOST;

	$wordbb->mybbdb=new wpdb($dbuser, $dbpass, $dbname, $dbhost);
	if(is_wp_error($wordbb->mybbdb->error))
	{
		$errors=array("Couldn't connect to the specified database");
		wordbb_set_errors('Database configuration error. Go to <a href="options-general.php?page=wordbb-options">WordBB Options</a>',$errors,false);

		return false;
	}

	return true;
}

function wordbb_get_bridge($type,$wp_id='',$mybb_id='')
{
	global $wpdb, $wordbb;

	// look in the cache
	foreach($wordbb->bridges as $bridge)
	{
		if($bridge->type==$type)
		{
			if(!empty($wp_id) && $bridge->wp_id!=$wp_id)
				continue;

			if(!empty($mybb_id) && $bridge->mybb_id!=$mybb_id)
				continue;

			return $bridge;
		}
	}

	$bridge=false;

	$q='SELECT * FROM wordbb_meta WHERE type="'.$wpdb->escape($type).'"';

	switch($type)
	{
	case WORDBB_CAT:
		{
			if(!empty($wp_id))
				$q.=' AND wp_id='.$wpdb->escape($wp_id);
			if(!empty($mybb_id))
				$q.=' AND mybb_id='.$wpdb->escape($mybb_id);

			$bridge=$wpdb->get_row($q);
		}
		break;

	case WORDBB_USER:
	case WORDBB_POST:
		{
			if(empty($wp_id) && empty($mybb_id))
			{
				return false;
			}

			if(!empty($wp_id))
				$q.=' AND wp_id='.$wpdb->escape($wp_id);
			if(!empty($mybb_id))
				$q.=' AND mybb_id='.$wpdb->escape($mybb_id);

			$bridge=$wpdb->get_row($q);
		}
		break;
	}

	// cache this bridge
	if(!empty($bridge))
	{
		$wordbb->bridges[]=$bridge;
	}

	return $bridge;
}

function wordbb_bridge($type,$wp_id,$mybb_id,$set)
{
	global $wpdb;

	if(empty($set))
		return false;

	switch($type)
	{
	case WORDBB_CAT:
	case WORDBB_USER:
	case WORDBB_POST:
		{
			switch($set)
			{
			case WORDBB_WP:
				{
					if(!empty($wp_id) && empty($mybb_id))
					{
						$wpdb->query($wpdb->prepare("DELETE FROM wordbb_meta WHERE type=%s AND wp_id=%d",$type,$wp_id));
						return false;
					}

					$exists=$wpdb->get_row($wpdb->prepare("SELECT * FROM wordbb_meta WHERE type=%s AND wp_id=%d",$type,$wp_id));
					if($exists)
					{
						$wpdb->query($wpdb->prepare("UPDATE wordbb_meta SET mybb_id=%d WHERE type=%s AND wp_id=%d",$mybb_id,$type,$wp_id));
						return true;
					}
				}
				break;

			case WORDBB_MYBB:
				{
					if(!empty($mybb_id) && empty($wp_id))
					{
						$wpdb->query($wpdb->prepare("DELETE FROM wordbb_meta WHERE type=%s AND mybb_id=%d",$type,$wp_id));
						return false;
					}

					$exists=$wpdb->get_row($wpdb->prepare("SELECT * FROM wordbb_meta WHERE type=%s AND mybb_id=%d",$type,$mybb_id));
					if($exists)
					{
						$wpdb->query($wpdb->prepare("UPDATE wordbb_meta SET wp_id=%d WHERE type=%s AND mybb_id=%d",$wp_id,$type,$mybb_id));
						return true;
					}
				}
				break;
			}
		}
		break;

	default:
		return false;
	}

	// insert
	$wpdb->query($wpdb->prepare("INSERT INTO wordbb_meta (type,wp_id,mybb_id) VALUES(%s,%d,%d)",$type,$wp_id,$mybb_id));

	return true;
}

function wordbb_get_ip()
{
	if(isset($_SERVER['REMOTE_ADDR']))
	{
		$ip = $_SERVER['REMOTE_ADDR'];
	}
	elseif(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
	{
		if(preg_match_all("#[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}#s", $_SERVER['HTTP_X_FORWARDED_FOR'], $addresses))
		{
			foreach($addresses[0] as $key => $val)
			{
				if(!preg_match("#^(10|172\.16|192\.168)\.#", $val))
				{
					$ip = $val;
					break;
				}
			}
		}
	}

	if(!isset($ip))
	{
		if(isset($_SERVER['HTTP_CLIENT_IP']))
		{
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		}
		else
		{
			$ip = '';
		}
	}

	$ip = preg_replace("#([^.0-9 ]*)#", "", $ip);
	return $ip;
}

//
// Taken from MyBB source code (inc/functions.php)
// I edited some minor things (some mybb specific variables, access to settings etc.)
// http://www.mybboard.net
//
function wordbb_my_date($format, $stamp="", $offset="", $ty=1, $today='Today', $yesterday='Yesterday', $adodb=false)
{
	global $mybb, $lang, $mybbadmin, $plugins;

	global $wordbb;

	// If the stamp isn't set, use TIME_NOW
	if(empty($stamp))
	{
		$stamp = time();
	}

	if(!$offset && $offset != '0')
	{
		if(!empty($wordbb->loggeduserinfo) && isset($wordbb->loggeduserinfo->timezone))
		{
			$offset = $wordbb->loggeduserinfo->timezone;
			$dstcorrection = $wordbb->loggeduserinfo->dst;
		}
		else
		{
			$offset = $wordbb->mybbsettings['timezoneoffset'];
			$dstcorrection = $wordbb->mybbsettings['dstcorrection'];
		}

		// If DST correction is enabled, add an additional hour to the timezone.
		if($dstcorrection == 1)
		{
			++$offset;
			if(my_substr($offset, 0, 1) != "-")
			{
				$offset = "+".$offset;
			}
		}
	}

	if($offset == "-")
	{
		$offset = 0;
	}
	
	if($adodb == true && function_exists('adodb_date'))
	{
		$date = adodb_date($format, $stamp + ($offset * 3600));
	}
	else
	{
		$date = gmdate($format, $stamp + ($offset * 3600));
	}

	if($wordbb->mybbsettings['dateformat'] == $format && $ty)
	{
		$stamp = time();
		
		if($adodb == true && function_exists('adodb_date'))
		{
			$todaysdate = adodb_date($format, $stamp + ($offset * 3600));
			$yesterdaysdate = adodb_date($format, ($stamp - 86400) + ($offset * 3600));
		}
		else
		{
			$todaysdate = gmdate($format, $stamp + ($offset * 3600));
			$yesterdaysdate = gmdate($format, ($stamp - 86400) + ($offset * 3600));
		}

		if($todaysdate == $date)
		{
			$date = $today;
		}
		else if($yesterdaysdate == $date)
		{
			$date = $yesterday;
		}
	}

	return $date;
}

//
// Taken from WordPress core (pluggable.php
// I edited some minor things 
// http://wordpress.org
//

function wordbb_nonce_tick() {
	$nonce_life = 86400;

	return ceil(time() / ( $nonce_life / 2 ));
}

function wordbb_verify_nonce($nonce, $action = -1) {
	$i = wordbb_nonce_tick();

	$nonce=trim($nonce);

	// Nonce generated 0-12 hours ago
	if ( md5($i . $action) == $nonce )
		return 1;
	// Nonce generated 12-24 hours ago
	if ( md5(($i - 1) . $action) == $nonce )
		return 2;
	// Invalid nonce
	return false;
}

function wordbb_create_nonce($action = -1) {
	$i = wordbb_nonce_tick();

	return md5($i . $action);
}

?>