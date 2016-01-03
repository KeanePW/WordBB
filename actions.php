<?php

require_once('functions.php');

$wp_root='../../..';

$action=!empty($_POST['action'])?$_POST['action']:$_GET['action'];
if(!isset($action))
	die;

if(!empty($_POST['action']))
	$ajax=$_POST['ajax'];

function wordbb_get_arg($name)
{
	return !empty($_POST[$name])?$_POST[$name]:$_GET[$name];
}

switch($action)
{
case 'save_categories':
	{
		require_once('inc/include_wp.php');
		
		$nonce=$_REQUEST['_wpnonce'];
		if(!wp_verify_nonce($nonce, 'wordbb_save_categories'))
			die;

		$cat_forums=$_POST['wordbb_cat_forums'];
		if(!isset($cat_forums) || !is_array($cat_forums))
			die;

		foreach($cat_forums as $id=>$cat_forum)
		{
			wordbb_bridge(WORDBB_CAT,$id,$cat_forum,WORDBB_WP);
		}

		if(!$ajax)
		{
			wp_redirect(get_bloginfo('wpurl').'/wp-admin/edit.php?page=wordbb-categories');
		}
	}
	break;

case 'bridge_post':
	{
		require_once('inc/include_wp.php');

		$post=wordbb_get_arg('post');
		if(!isset($post))
			die;

		$nonce=$_REQUEST['_wpnonce'];
		if(!wp_verify_nonce($nonce, 'wordbb_bridge_post_'.$post))
			die; 

		wordbb_bridge_wp_post($post);

		if(!$ajax)
		{
			wp_redirect(get_bloginfo('wpurl').'/wp-admin/edit.php');
		}

	}
	break;

case 'bridge_posts':
	{
		require_once('inc/include_wp.php');

		$nonce=$_REQUEST['_wpnonce'];
		if(!wp_verify_nonce($nonce, 'wordbb_bridge_posts'))
			die; 

		$posts=$_POST['post'];
		if(!isset($posts) || !is_array($posts))
			die;

		foreach($posts as $post_id)
		{
			wordbb_bridge_wp_post($post_id);
		}

		if(!$ajax)
		{
			wp_redirect(get_bloginfo('wpurl').'/wp-admin/edit.php');
		}

	}
	break;

case 'sync_bridge_thread':
	{
		require_once('inc/include_wp.php');

		$post=wordbb_get_arg('post');
		if(!isset($post))
			die;

		$nonce=$_REQUEST['_wpnonce'];
		if(!wp_verify_nonce($nonce, 'wordbb_sync_bridge_thread_'.$post))
			die; 

		wordbb_bridge_wp_post($post);

		if(!$ajax)
		{
			wp_redirect(get_bloginfo('wpurl').'/wp-admin/edit.php');
		}

		exit("true");
	}
	break;

case 'delete_bridge_thread':
	{
		require_once('inc/include_wp.php');

		$post=wordbb_get_arg('post');
		if(!isset($post))
			die;

		$nonce=$_REQUEST['_wpnonce'];
		if(!wp_verify_nonce($nonce, 'wordbb_delete_bridge_thread_'.$post))
			die; 

		wordbb_delete_bridge_thread($post,true);

		if(!$ajax)
		{
			wp_redirect(get_bloginfo('wpurl').'/wp-admin/edit.php');
		}

		exit("true");
	}
	break;

case 'create_thread':
	{
		$nonce=$_POST['_wordbbnonce'];
		if(!wordbb_verify_nonce($nonce,'create_thread'))
			die;

		$mybb_root=$_POST['wordbb_mybb_abs'];
		if(!isset($mybb_root))
			die;

		require_once('inc/include_mybb.php');

		$subject=$_POST['subject'];
		$message=$_POST['message'];
		$fid=$_POST['fid'];
		$uid=$_POST['uid'];
		$ip=$_POST['ip'];

		$user=$MyBBI->getUser($uid);
		$username=$user['username'];

		$data = array(
			'fid' => $fid,
			'subject' => $subject,
			'uid' => $uid,
			'username' => $username,
			'ipaddress' => $ip,
			'message' => $message
		);
		$create = $MyBBI->createThread($data,false);
		exit(serialize($create));
	}
	break;

case 'update_thread':
	{
		$nonce=$_POST['_wordbbnonce'];
		if(!wordbb_verify_nonce($nonce,'update_thread'))
			die;

		$mybb_root=$_POST['wordbb_mybb_abs'];
		if(!isset($mybb_root))
			die;

		require_once('inc/include_mybb.php');

		$tid=$_POST['tid'];
		$subject=$_POST['subject'];
		$message=$_POST['message'];
		$fid=$_POST['fid'];
		$uid=$_POST['uid'];
		$ip=$_POST['ip'];

		$user=$MyBBI->getUser($uid);
		$username=$user['username'];

		// get id of the first post in the thread
		$query = $MyBBI->db->simple_select("posts","pid","tid=$tid AND replyto=0");
		$pid = $MyBBI->db->fetch_field($query, "pid");

		require_once MYBB_ROOT."inc/datahandlers/post.php";
		$posthandler = new PostDataHandler("update");
		$posthandler->action = "post";

		$post = array(
			"pid" => $pid,
			"subject" => $subject,
			"icon" => -1,
			"uid" => $uid,
			"username" => $username,
			"edit_uid" => '',
			"message" => $message
		);

		$post['options'] = array(
			"signature" => $sig ? "yes" : "no",
			"emailnotify" => "no",
			"disablesmilies" => "no"
		);

		$posthandler->set_data($post);
		if(!$posthandler->validate_post())
		{
			exit(serialize($posthandler->get_friendly_errors()));
		}

		$posthandler->update_post();
	}
	break;

case 'delete_thread':
	{
		$nonce=$_POST['_wordbbnonce'];
		if(!wordbb_verify_nonce($nonce,'delete_thread'))
			die;

		$mybb_root=$_POST['wordbb_mybb_abs'];
		if(!isset($mybb_root))
			die;

		require_once('inc/include_mybb.php');

		$tid=$_POST['tid'];

		$deleted = $MyBBI->removeThread($tid);
		exit("$deleted");
	}
	break;

case 'create_post':
	{
		$nonce=$_POST['_wordbbnonce'];
		if(!wordbb_verify_nonce($nonce,'create_post'))
			die;

		$mybb_root=$_POST['wordbb_mybb_abs'];
		if(!isset($mybb_root))
			die;

		require_once('inc/include_mybb.php');

		$subject=$_POST['subject'];
		$message=$_POST['message'];
		$tid=$_POST['tid'];
		$uid=$_POST['uid'];
		$ip=$_POST['ip'];

		$thread=$MyBBI->getThread($tid);
		$fid=$thread['fid'];

		$user=$MyBBI->getUser($uid);
		$username=$user['username'];

		$data = array(
			'tid' => $tid,
			'fid' => $fid,
			'uid' => $uid,
			'username' => $username,
			'ipaddress' => $ip,
			'subject' => $subject,
			'message' => $message,
			'savedraft' => false,
			'options' => array('signature'=>true,'disablesmilies'=>false,'subscriptionmethod'=>false)
		);
		$create = $MyBBI->createPost($data,false);
		$thread=$MyBBI->getThread($tid);

		update_forum_lastpost($fid);

		exit(serialize($create));
	}
	break;

/*
case 'create_mybb_user':
	{
		require_once('inc/include_mybb.php');

		$username=$_POST['username'];
		$password=$_POST['password'];
		$email=trim($_POST['email']);

		$usergroup = 2;

		$user = array(
			"username" => $username,
			"password" => $password,
			"password2" => $password, 
			"email" => $email,
			"email2" => $email,
			"usergroup" => $usergroup,
			"referrer" => "",
			"timezone" => "",
			"language" => "",
			"profile_fields" => "",
			"regip" => "",
			"longregip" => "",
			"coppa_user" => ""
		);

		$user['options'] = array(
			"allownotices" => 1,
			"hideemail" => 1,
			"subscriptionmethod" => 0,
			"receivepms" => 1,
			"pmnotice" => 1,
			"emailpmnotify" => 1,
			"invisible" => 0,
			"dstcorrection" => 0
		);
		
		require MYBB_ROOT.'inc/datahandlers/user.php';

		$userhandler = new UserDataHandler("insert");
		$userhandler->set_data($user);

		$errors = "";

		if(!$userhandler->validate_user())
		{
			$errors=$userhandler->get_friendly_errors();
			$out=serialize($errors);
		}
		else
		{
			$user_info = $userhandler->insert_user();
			$out=serialize($user_info['uid']);
		}

		exit("$out");
	}
	break;
*/
}

?>