<?php

// DO NOT CALL THIS. use $wordbb->forums instead
function _wordbb_get_forums()
{
	global $wpdb, $wordbb;

	$results=$wordbb->mybbdb->get_results("SELECT fid, name, type FROM {$wordbb->table_forums} WHERE type='f'");
	$forums=array();
	foreach($results as $result)
	{
		$forums[$result->fid]=$result->name;
	}
	return $forums;
}

// DO NOT CALL THIS. use $wordbb->postcounts[tid] instead
function _wordbb_get_threads_postcounts($tids)
{
	global $wpdb, $wordbb;

	$in='';
	foreach($tids as $tid)
	{
		$in.=$tid.',';
	}
	$in=rtrim($in,',');

	$results=$wordbb->mybbdb->get_results("SELECT COUNT(*) AS count, tid FROM {$wordbb->table_posts} WHERE tid IN({$in}) GROUP BY(tid)");
	$counts=array();
	foreach($results as $result)
	{
		$counts[$result->tid]=$result->count-1;
	}
	return $counts;
}

// DO NOT CALL THIS. use $wordbb->posts[tid] instead
function _wordbb_get_threads_posts($tids)
{
	global $wpdb, $wordbb;

	$in='';
	foreach($tids as $tid)
	{
		$in.=$tid.',';
	}
	$in=rtrim($in,',');

	// get posts
	$results=$wordbb->mybbdb->get_results("SELECT pid,tid,replyto,fid,subject,uid,username,".
		"dateline,message,ipaddress FROM {$wordbb->table_posts} WHERE tid IN({$in}) AND visible=1 ORDER BY dateline ASC LIMIT 1,18446744073709551615");

	$threads=array();
	foreach($results as $result)
	{
		$thread=&$threads[$result->tid];
		$thread[]=$result;
	}
	return $threads;
}

// DO NOT CALL THIS. use $wordbb->mybbsettings instead
function _wordbb_get_mybb_settings()
{
	global $wpdb, $wordbb;

	$results=$wordbb->mybbdb->get_results("SELECT name,value FROM {$wordbb->table_settings}");

	$settings=array();
	foreach($results as $result)
	{
		$settings[$result->name]=$result->value;
	}

	return $settings;
}

// DO NOT CALL THIS. use $wordbb->lastposters instead
function _wordbb_get_threads_lastposters($tids)
{
	global $wpdb, $wordbb;

	$in='';
	foreach($tids as $tid)
	{
		$in.=$tid.',';
	}
	$in=rtrim($in,',');

	$results=$wordbb->mybbdb->get_results("SELECT lastposter, tid FROM {$wordbb->table_threads} WHERE tid IN({$in}) AND replies>0 GROUP BY(tid)");
	$lastposters=array();
	foreach($results as $result)
	{
		$lastposters[$result->tid]=$result->lastposter;
	}
	return $lastposters;
}

function wordbb_get_latest_threads($n=10,$exclude='')
{
	global $wpdb, $wordbb;

	$exclude=rtrim($exclude,',');
	if(!empty($wordbb->exclude_fids))
	{
		foreach($wordbb->exclude_fids as $fid)
		{
			$exclude.="$fid,";
		}
		$exclude=rtrim($exclude,',');
	}
	
	$where='';
	if(!empty($exclude))
	{
		$where="fid NOT IN ({$exclude}) AND";
	}
	
	// get latest threads
	$results=$wordbb->mybbdb->get_results($wpdb->prepare("SELECT tid,fid,subject,".
		"uid,username,dateline,lastpost,lastposter,".
		"lastposteruid FROM {$wordbb->table_threads} ".
		"WHERE $where visible=1 ORDER BY dateline DESC LIMIT %d",$n));

	return $results;
}

function wordbb_get_latest_posts($n=10,$exclude='')
{
	global $wpdb, $wordbb;

	$exclude=rtrim($exclude,',');	
	if(!empty($wordbb->exclude_fids))
	{
		foreach($wordbb->exclude_fids as $fid)
		{
			$exclude.="$fid,";
		}
		$exclude=rtrim($exclude,',');
	}
	
	$where='';
	if(!empty($exclude))
	{
		$where="fid NOT IN ({$exclude}) AND";
	}

	// get latest posts
	$results=$wordbb->mybbdb->get_results($wpdb->prepare("SELECT pid,tid,replyto,fid,subject,uid,username,".
		"dateline,message,ipaddress FROM {$wordbb->table_posts} WHERE $where visible=1 ".
		"ORDER BY dateline DESC LIMIT %d",$n));

	return $results;
}

function wordbb_get_user_info($uid)
{
	global $wpdb, $wordbb;

	if(empty($uid))
		return false;

	return $wordbb->mybbdb->get_row($wpdb->prepare("SELECT * FROM {$wordbb->table_users} WHERE uid=%d",$uid));
}

function wordbb_get_user_info_by_username($username)
{
	global $wpdb, $wordbb;

	if(empty($username))
		return false;

	return $wordbb->mybbdb->get_row($wpdb->prepare("SELECT * FROM {$wordbb->table_users} WHERE username=%s",$username));
}

?>