<?php

/**
 * @package WordBB
 * @author Hangman
 * @version 0.3
 */
/*
Plugin Name: WordBB - WP side
Plugin URI: http://valadilene.org/wordbb
Description: WordPress/MyBB bridge.
Author: Hangman
Version: 0.3.1
Author URI: http://valadilene.org
*/

require_once('functions.php');
require_once('functions_mybb.php');
require_once('template.php');

if(is_admin())
{
	add_action('admin_menu', 'wordbb_plugin_options_menu');
	add_action('admin_init', 'wordbb_admin_init');
	add_action('admin_head', 'wordbb_admin_head');
}

if(wordbb_init())
{
	// plugin hooks

	if(is_admin())
	{
		// init
		add_action('admin_menu', 'wordbb_plugin_menu');

		// users
		add_action('admin_head', 'wordbb_admin_users_update');
		add_action('manage_users_custom_column', 'wordbb_users_custom_column', 8, 3);
		add_filter('manage_users_columns', 'wordbb_users_columns');

		// posts
		add_action('publish_post', 'wordbb_publish_post');
		add_action('future_to_publish', 'wordbb_publish_post');
		add_action('delete_post', 'wordbb_delete_bridge_thread');		

		add_action('manage_posts_custom_column', 'wordbb_posts_custom_column', 8, 2);
		add_filter('manage_posts_columns', 'wordbb_posts_columns');
		
		if(get_option('wordbb_use_mybb_comments')=='on')
		{
			add_filter('get_comments_number', 'wordbb_get_comments_number');
		}
	}
	else
	{
		// non-admin enqueues, actions, and filters

		add_action('loop_start', 'wordbb_loop_start');
		add_action('loop_end', 'wordbb_loop_end');

		if(get_option('wordbb_use_mybb_comments')=='on')
		{
			add_action('pre_comment_on_post','wordbb_comment_on_post');

			add_filter('get_comments_number', 'wordbb_get_comments_number');

			add_filter('comments_array', 'wordbb_get_comments_array');
			if(!isset($_POST['comment_post_ID']))
			{
				// override comment links only if we're not using
				// the wp comment form (which uses get_comment_link()
				// to redirect the user to the comments page)
				add_filter('get_comment_link', 'wordbb_get_comment_link', 0, 3);
			}
			add_filter('comment_reply_link', 'wordbb_comment_reply_link', 8, 4);
		}
	}

	add_action('widgets_init', 'wordbb_register_widget');
//	add_action('user_register', 'wordbb_user_register');
}

function wordbb_check_config() {
	global $wordbb;

	$errors=array();

	$wordbb_mybb_url=trim(get_option('wordbb_mybb_url'));
	if(false===$wordbb_mybb_url || empty($wordbb_mybb_url))
		$errors[]='MyBB URL field empty';

	$wordbb_mybb_abs=trim(get_option('wordbb_mybb_abs'));
	if(false===$wordbb_mybb_abs || empty($wordbb_mybb_abs))
		$errors[]='MyBB root folder field empty';

	if(!empty($wordbb_mybb_abs))
	{
		if(!file_exists($wordbb_mybb_abs))
			$errors[]='MyBB root folder does not exist';
		else if(!file_exists($wordbb_mybb_abs.'/global.php'))
			$errors[]='MyBB global.php file not found. Check your MyBB absolute path on server!';
	}

/*	$wordbb_post_forum=get_option('wordbb_post_forum');
	if(false===$wordbb_post_forum || empty($wordbb_post_forum))
		$errors[]='Default post forum field empty';*/

	if($wordbb->mybb_db_found) {
		$wordbb_post_author=get_option('wordbb_post_author');
		if(false===$wordbb_post_author || empty($wordbb_post_author))
			$errors[]='Default post author field empty';
		else
		{
			if(!wordbb_get_user_info_by_username($wordbb_post_author))
				$errors[]='Default post author username does not exist on MyBB';
		}
	}
	
	if(!empty($errors))
		wordbb_set_errors('Configuration is not complete. Go to <a href="options-general.php?page=wordbb-options">WordBB Options</a>',$errors,false);

	return (empty($errors));
}

function wordbb_init() {
	global $wordbb, $wpdb;

	$wordbb->errors=array();
	$wordbb->bridges=array();

	$wordbb->loop_started=false;

	// check if meta table exists, if not create it
	$wpdb->query("CREATE TABLE IF NOT EXISTS `wordbb_meta` (`type` enum('cat','post','user'),`wp_id` int(11),`mybb_id` int(11))");

	$prefix=get_option('wordbb_dbprefix');
	if(empty($prefix))
		$prefix='mybb_';

	// store mybb db table names
	$wordbb->table_forums=$prefix.'forums';
	$wordbb->table_forumpermissions=$prefix.'forumpermissions';
	$wordbb->table_users=$prefix.'users';
	$wordbb->table_posts=$prefix.'posts';
	$wordbb->table_threads=$prefix.'threads';
	$wordbb->table_settings=$prefix.'settings';

	$wordbb->mybb_db_found=wordbb_select_mybb_db();
	if($wordbb->mybb_db_found)
	{
		$configured=wordbb_check_config();
	}
	
	wordbb_display_errors();

	if($configured)
	{
		// store mybb url
		$wordbb->mybb_url=get_option('wordbb_mybb_url');

		// store mybb inc folder path
		$wordbb->mybb_inc=get_option('wordbb_mybb_abs');

		// wordbb plugin url
		$wordbb->plugin_url=get_bloginfo('wpurl'). "/wp-content/plugins/" . plugin_basename(dirname(__FILE__));

		// wordbb js url
		$wordbb->js_url=$wordbb->plugin_url.'/wordbb.js';

		// plugin action file url
		$wordbb->action_url=$wordbb->plugin_url.'/actions.php';

		// mybb settings
		$wordbb->mybbsettings=_wordbb_get_mybb_settings();

		// forums array
		$wordbb->forums=_wordbb_get_forums();
		/*$users=_wordbb_get_users();
		$wordbb->users=$users['usernames'];
		$wordbb->usersinfo=$users['usersinfo'];*/
		
		// store default author's id
		$post_author=wordbb_get_user_info_by_username($wordbb_post_author);
		if(!empty($post_author))
			$wordbb_post_author=$post_author->uid;

		// get currently logged in info (if any)
		$mybbuser=$_COOKIE['mybbuser'];
		if(isset($mybbuser) && !empty($mybbuser))
		{
			$mybbuser=explode('_',$mybbuser);
			$uid=$mybbuser[0];
			$key=$mybbuser[1];
			$userinfo=wordbb_get_user_info($uid);
			if($userinfo->loginkey==$key)
				$wordbb->loggeduserinfo=$userinfo;
		}

		// store IDs of forums non visible by the currently logged in user
		$usergroup=1;
		if(!empty($wordbb->loggeduserinfo))
		{
			$usergroup=(int)$wordbb->loggeduserinfo->usergroup;
		}
		
		$exclude_fids=array();
		$forums=$wordbb->mybbdb->get_results($wordbb->mybbdb->prepare("SELECT fid FROM {$wordbb->table_forumpermissions} WHERE gid=%d AND (canview=0 OR canviewthreads=0)",$usergroup));
		foreach($forums as $forum)
		{
			$exclude_fids[]=$forum->fid;
		}
		$wordbb->exclude_fids=$exclude_fids;
	
		// define WP pluggables functions
		wordbb_pluggables();
	}
	
	return $configured;
}

function wordbb_admin_head() {
	global $wordbb;

	echo '
	<style type="text/css">
		ul.wordbb-errors {
			list-style-type: disc;
			padding-left: 24px;
		}
	</style>
	';
}

function wordbb_admin_init() {
	global $wordbb;

	// register settings
	register_setting( 'wordbb', 'wordbb_post_forum', 'intval' );
	register_setting( 'wordbb', 'wordbb_post_author', '' );
	register_setting( 'wordbb', 'wordbb_mybb_url' );
	register_setting( 'wordbb', 'wordbb_mybb_abs', 'wordbb_mybb_abs_sanitize' );
	register_setting( 'wordbb', 'wordbb_dbname' );
	register_setting( 'wordbb', 'wordbb_dbuser' );
	register_setting( 'wordbb', 'wordbb_dbpass' );
	register_setting( 'wordbb', 'wordbb_dbhost' );
	register_setting( 'wordbb', 'wordbb_dbprefix' );
	register_setting( 'wordbb', 'wordbb_create_thread' );
	register_setting( 'wordbb', 'wordbb_create_thread_excerpt' );
	register_setting( 'wordbb', 'wordbb_create_thread_post_link' );
	register_setting( 'wordbb', 'wordbb_create_thread_post_link_place' );
	register_setting( 'wordbb', 'wordbb_create_thread_post_link_text' );
	register_setting( 'wordbb', 'wordbb_delete_thread' );
	register_setting( 'wordbb', 'wordbb_use_mybb_comments' );
	register_setting( 'wordbb', 'wordbb_show_mybb_comments' );
	register_setting( 'wordbb', 'wordbb_redirect_mybb' );
	register_setting( 'wordbb', 'wordbb_langtoday', 'wordbb_langtoday_sanitize' );
	register_setting( 'wordbb', 'wordbb_langyesterday', 'wordbb_langyesterday_sanitize' );

	function wordbb_mybb_abs_sanitize($v) { return str_replace('\\', '/', $v); }
	function wordbb_langtoday_sanitize($v) { if(empty($v)) $v='Today'; return $v; }
	function wordbb_langyesterday_sanitize($v) { if(empty($v)) $v='Yesterday'; return $v; }

	// enqueue wordbb js
	wp_enqueue_script('wordbb_js',$wordbb->js_url,array('jquery'),'0.1');
}

function wordbb_register_widget()
{
//	register_sidebar_widget('WordBB Latest Threads', 'wordbb_widget');
//	register_widget_control('WordBB Latest Threads', 'wordbb_widget_control');

	$widget_options = array('classname' => 'wordbb_widget', 'description' => "Shows latest MyBB threads/posts on your sidebar." );
	wp_register_sidebar_widget('wordbb_widget','WordBB Latest Threads','wordbb_widget',$widget_options);
	wp_register_widget_control('wordbb_widget','WordBB Latest Threads','wordbb_widget_control',$widget_options);
}

function wordbb_widget_control()
{
	if($_POST['wordbb_widget_submit'])
	{
		$mode=$_POST['wordbb_widget_mode'];
		$title=$_POST['wordbb_widget_title'];
		if(!isset($title) || empty($title))
			$title='Forums Latest '.($mode=='threads'?'Threads':'Posts');
		$exclude=$_POST['wordbb_widget_exclude'];
		$usernames=$_POST['wordbb_widget_usernames'];
		if(!empty($exclude))
		{
			$exclude=explode(',',trim($exclude));
			$fids=array();
			for($i=0; $i<count($exclude); $i++)
			{
				$fid=intval(trim($exclude[$i]));
				if($fid>0)
					$fids[]=$fid;
			}
			$exclude=implode(',',$fids);
		}
		$count=intval($_POST['wordbb_widget_count']);
		if($count<=0)
			$count=10;

		update_option('wordbb_widget_title',$title);
		update_option('wordbb_widget_mode',$mode);
		update_option('wordbb_widget_exclude',$exclude);
		update_option('wordbb_widget_usernames',$usernames);
		update_option('wordbb_widget_count',$count);
	}

	$title=get_option('wordbb_widget_title');
	$mode=get_option('wordbb_widget_mode');
	$exclude=get_option('wordbb_widget_exclude');
	$usernames=get_option('wordbb_widget_usernames');
	$count=get_option('wordbb_widget_count');

?>	
	<ul>
	<li><?php _e('Title', 'wordbb'); ?>
		<input style="width: 100%" id="wordbb_widget_title" name="wordbb_widget_title" type="text" value="<?php echo $title; ?>" />
	</li>

	<li><?php _e('Mode', 'wordbb'); ?>
		<select style="width: 100%" id="wordbb_widget_mode" name="wordbb_widget_mode">
		<option value="threads" <?php if($mode=='threads') echo 'selected' ?>>Latest threads</option>
		<option value="posts" <?php if($mode=='posts') echo 'selected' ?>>Latest posts</option>
		</select>
	</li>

	<li><?php _e('Max entry count', 'wordbb'); ?>
		<input style="width: 100%" id="wordbb_widget_count" name="wordbb_widget_count" type="text" value="<?php echo $count ?>" />
	</li>

	<li><?php _e('Exclude forums', 'wordbb'); ?>
		<span style="font-size: 6pt">(comma separated list of forum IDs)</span>
		<input style="width: 100%" id="wordbb_widget_exclude" name="wordbb_widget_exclude" type="text" value="<?php echo $exclude ?>" />
	</li>

	<li><?php _e('Show usernames', 'wordbb'); ?>
		<input id="wordbb_widget_usernames" name="wordbb_widget_usernames" type="checkbox" <?php if($usernames) : ?>checked="checked"<?php endif ?> />
	</li>
	</ul>

	<input style="width: 100%" type="hidden" id="wordbb_widget_submit" name="wordbb_widget_submit" value="1" />
<?php
}

function wordbb_widget($args)
{
	global $wordbb;

	extract($args);

	$mode=get_option('wordbb_widget_mode');
	$title=get_option('wordbb_widget_title');
	$exclude=get_option('wordbb_widget_exclude');
	$usernames=get_option('wordbb_widget_usernames');
	$count=get_option('wordbb_widget_count');

	echo $before_widget;
	echo $before_title;
	echo $title;
	echo $after_title;

	$entries=($mode=='threads')?wordbb_get_latest_threads($count,$exclude):wordbb_get_latest_posts($count,$exclude);

	if(is_array($entries))
?>
		<ul>
<?php
		foreach($entries as $entry)
		{
?>
		<li>
		<?php if($mode=='threads') : ?>
		<a href="<?php echo $wordbb->mybb_url.'/showthread.php?tid='.$entry->tid ?>"><?php echo $entry->subject ?></a>
		<?php else : ?>
		<a href="<?php echo $wordbb->mybb_url.'/showthread.php?tid='.$entry->tid.'&pid='.$entry->pid.'#pid'.$entry->pid ?>"><?php echo $entry->subject ?></a>
		<?php endif ?>
		<?php if($usernames) : ?>
		by <a href="<?php echo $wordbb->mybb_url ?>/member.php?action=profile&uid=<?php echo $entry->uid ?>"><?php echo $entry->username ?></a>
		<?php endif ?>
		</li>
<?php
		}
?>
		</ul>
<?php

	echo $after_widget;
}

function wordbb_pluggables() {

	if(!function_exists('wp_sanitize_redirect')) :
		function wp_sanitize_redirect($location) {
			$location = preg_replace('|^a-z0-9-~+_.?#=&;,/:%!|i', '', $location);
			$location = wp_kses_no_null($location);

			// remove %0d and %0a from location
			$strip = array('%0d', '%0a');
			$found = true;
			while($found) {
				$found = false;
				foreach( (array) $strip as $val ) {
					while(strpos($location, $val) !== false) {
						$found = true;
						$location = str_replace($val, '', $location);
					}
				}
			}
			return $location;
		}
	endif;
}

function wordbb_error_handling($location)
{
	global $wordbb;

	$errors_title=$wordbb->errors['title'];
	$errors_array=$wordbb->errors['errors'];

	$errors='';

	if(is_array($errors_array))
	{
		$errors.='errors_title='.urlencode($errors_title).'&';

		foreach($errors_array as $wordbb_error)
		{
			$errors.='errors[]='.urlencode($wordbb_error).'&';
		}
		$errors=rtrim($errors,'&');
	}

	if(!empty($errors))
		$location.='&'.$errors;

	return $location;
}

function wordbb_set_errors($title,$errors,$getparams=true) {
	global $wordbb;

	$wordbb->errors=array('title'=>$title,'errors'=>$errors);
	if($getparams)
		add_filter('wp_redirect',wordbb_error_handling);
}

function wordbb_display_errors() {
	global $wordbb;

	if(empty($wordbb->errors))
	{
		$wordbb->errors['title']=$_GET['errors_title'];
		$wordbb->errors['errors']=$_GET['errors'];
	}
	
	// display error messages
	if(isset($wordbb->errors['errors']))
	{
		function wordbb_error() {
			global $wordbb;
			
			$errors_message='<ul class="wordbb-errors">';
			foreach($wordbb->errors['errors'] as $error)
			{
				$errors_message.='<li>'.$error.'</li>';
			}
			$errors_message.='</ul>';
			
			echo "
			<div id='wordbb-error' class='updated fade'><p><strong>".__('WordBB errors &mdash; &#8220;<em>'.$wordbb->errors['title'].'</em>&#8221; ')."</strong><br /><br />".$errors_message."</p></div>
			";
		}
		add_action('admin_notices', 'wordbb_error');
	}
}

function wordbb_do_action($action,$params)
{
	global $wordbb;

	$params['wordbb_mybb_abs']=get_option('wordbb_mybb_abs');
	$params['_wordbbnonce']=wordbb_create_nonce($action);

	// uses parse_url to validate data
	// tnx to Tikitiki
	$url = parse_url($wordbb->action_url);
	if(!$url['host'])
	{
		return false;
	}
	if(!$url['port'])
	{
		$url['port'] = 80;
	}
	if(!$url['path'])
	{
		$url['path'] = "/";
	}
	if($url['query'])
	{
		$url['path'] .= "?{$url['path']}";
	}

	$reqbody = "";
	$reqbody.= "action=".urlencode($action);
	foreach($params as $key=>$val)
	{
		if (!empty($reqbody)) $reqbody.= "&";
		$reqbody.= $key."=".urlencode($val);
	}

	$contentlength = strlen("$reqbody\r\n"); // tnx to Rog
	// tnx to mike for letting me discover the Host \r\n bug
	$reqheader =  "POST {$url['path']} HTTP/1.0\r\n".
	"Host: {$url['host']}\r\n". "User-Agent: PostIt\r\n". "Connection: Close\r\n".
	"Content-Type: application/x-www-form-urlencoded\r\n".
	"Content-Length: $contentlength\r\n\r\n".
	"$reqbody\r\n";
	
	$socket = @fsockopen($url['host'], $url['port'], $errno, $errstr);
	@stream_set_timeout($socket, 10);
	if(!$socket)
	{
		return false;
	}

	fputs($socket, $reqheader);

	while(!feof($socket))
	{
		$result .= fgets($socket, 12800);
	}

	fclose($socket);
	
	//separate header and content
	$data = explode("\r\n\r\n", $result, 2);
	//$result=array('header'=>$data[0],'body'=>$data[1]);
	$result=unserialize($data[1]);
	if($result===false)
		$result=$data[1];
	
	return $result;
}

function wordbb_plugin_menu() {
	add_posts_page('WordBB Categories', 'WordBB Categories', 8, 'wordbb-categories', 'wordbb_categories_page');
}

function wordbb_plugin_options_menu() {
	add_options_page('WordBB Options', 'WordBB Options', 8, 'wordbb-options', 'wordbb_options_page');
}

function wordbb_options_page() {
	global $wordbb;

?>

<style type="text/css">
.form-table th {
	width: 30%;
}
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
	function updatePostLink() {
		$('.wordbb_create_thread_post_link_place').attr('disabled',!$('#wordbb_create_thread_post_link').attr('checked'));
	}
	updatePostLink();
	$('#wordbb_create_thread_post_link').click(updatePostLink);
});
</script>

<div class="wrap">
<h2>WordBB Options</h2>

	<p style="margin-left: 8px"><em><?php echo __('Welcome in WordBB\'s configuration panel. Here you can tweak all the plugin settings to take full advantage of the bridge.<br />'); ?></em></p>

<form method="post" action="options.php">

	<h3>MyBB data</h3>

	<table class="form-table">

		<tr valign="top">
			<th scope="row"><label for="wordbb_mybb_url">MyBB URL</label></th>
			<td><input type="text" id="wordbb_mybb_url" name="wordbb_mybb_url" size="40" value="<?php echo get_option('wordbb_mybb_url'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_mybb_abs">MyBB absolute path on server</label></th>
			<td><input type="text" id="wordbb_mybb_abs" name="wordbb_mybb_abs" size="40" value="<?php echo get_option('wordbb_mybb_abs'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_dbname">MyBB DB name (optional) (*)</label></th>
			<td><input type="text" id="wordbb_dbname" name="wordbb_dbname" size="40" value="<?php echo get_option('wordbb_dbname'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_dbuser">MyBB DB username (optional) (*)</label></th>
			<td><input type="text" id="wordbb_dbuser" name="wordbb_dbuser" size="40" value="<?php echo get_option('wordbb_dbuser'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_dbpass">MyBB DB password (optional) (*)</label></th>
			<td><input type="password" id="wordbb_dbpass" name="wordbb_dbpass" size="40" value="<?php echo get_option('wordbb_dbpass'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_dbhost">MyBB DB host (optional) (*)</label></th>
			<td><input type="text" id="wordbb_dbhost" name="wordbb_dbhost" size="40" value="<?php echo get_option('wordbb_dbhost'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_dbprefix">MyBB DB prefix (optional) (*)</label></th>
			<td><input type="text" id="wordbb_dbprefix" name="wordbb_dbprefix" size="40" value="<?php echo get_option('wordbb_dbprefix'); ?>" /></td>
		</tr>

	</table>

	<h3>Bridge settings and comment system</h3>

	<table class="form-table" border="1">

		<tr valign="top">
			<th scope="row"><label for="wordbb_create_thread">Create MyBB thread on WP post publish</label></th>
			<td><input type="checkbox" id="wordbb_create_thread" name="wordbb_create_thread" <?php if(get_option('wordbb_create_thread')=='on') echo 'checked'; ?> /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_create_thread_excerpt">Use post excerpt instead of full post as thread's message</label></th>
			<td><input type="checkbox" id="wordbb_create_thread_excerpt" name="wordbb_create_thread_excerpt" <?php if(get_option('wordbb_create_thread_excerpt')=='on') echo 'checked'; ?> /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_create_thread_post_link">Add a link to the original WordPress post in the thread's post</label></th>
			<td>
				<input type="checkbox" id="wordbb_create_thread_post_link" name="wordbb_create_thread_post_link" <?php if(get_option('wordbb_create_thread_post_link')=='on') echo 'checked'; ?> />&nbsp;
				<input type="radio" id="wordbb_create_thread_post_link_place_before" class="wordbb_create_thread_post_link_place" name="wordbb_create_thread_post_link_place" value="before" <?php if(get_option('wordbb_create_thread_post_link_place')=='before') echo 'checked'; ?> /> <label for="wordbb_create_thread_post_link_place_before">before content</label>
				<input type="radio" id="wordbb_create_thread_post_link_place_after" class="wordbb_create_thread_post_link_place" name="wordbb_create_thread_post_link_place" value="after" <?php if(get_option('wordbb_create_thread_post_link_place')=='after') echo 'checked'; ?> /> <label for="wordbb_create_thread_post_link_place_after">after content</label>
				<br />
				<label for="wordbb_create_thread_post_link_text">Link text (<code>%title%</code> will be replaced with the post's title)</label><br />
				<input type="text" id="wordbb_create_thread_post_link_text" name="wordbb_create_thread_post_link_text" size="30" value="<?php echo get_option('wordbb_create_thread_post_link_text'); ?>" />
			</td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_delete_thread">Delete MyBB thread on WP post deletion</label></th>
			<td><input type="checkbox" id="wordbb_delete_thread" name="wordbb_delete_thread" <?php if(get_option('wordbb_delete_thread')=='on') echo 'checked'; ?> /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_use_mybb_comments">Use MyBB as comment system (**)</label></th>
			<td><input type="checkbox" id="wordbb_use_mybb_comments" name="wordbb_use_mybb_comments" <?php if(get_option('wordbb_use_mybb_comments')=='on') echo 'checked'; ?> /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_show_mybb_comments">Show MyBB posts as comments on WordPress (***)</label></th>
			<td><input type="checkbox" id="wordbb_show_mybb_comments" name="wordbb_show_mybb_comments" <?php if(get_option('wordbb_show_mybb_comments')=='on') echo 'checked'; ?> /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_redirect_mybb">Redirect to MyBB thread when using WP's comment form (will redirect to WP comments if unchecked)</label></th>
			<td><input type="checkbox" id="wordbb_redirect_mybb" name="wordbb_redirect_mybb" <?php if(get_option('wordbb_redirect_mybb')=='on') echo 'checked'; ?> /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_post_forum">Default post forum</label></th>
			<td>
			<?php
			$html=wordbb_get_array_html($wordbb->forums,'wordbb_post_forum',get_option('wordbb_post_forum'),'',array(),'fid');
			if(empty($html))
				echo '(mybb forums table not available)';
			else
				echo $html;
			?>
			</td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_post_author">Default post author</label></th>
			<td>
			<input type="text" id="wordbb_post_author" name="wordbb_post_author" value="<?php echo get_option('wordbb_post_author') ?>" />
			</td>
		</tr>

	</table>

	<h3>Friendly last visit</h3>

	<table class="form-table">

		<tr valign="top">
			<th scope="row"><label for="wordbb_langtoday">Language "Today" string</label></th>
			<td><input type="text" id="wordbb_langtoday" name="wordbb_langtoday" size="40" value="<?php echo get_option('wordbb_langtoday'); ?>" /></td>
		</tr>

		<tr valign="top">
			<th scope="row"><label for="wordbb_langyesterday">Language "Yesterday" string</label></th>
			<td><input type="text" id="wordbb_langyesterday" name="wordbb_langyesterday" size="40" value="<?php echo get_option('wordbb_langyesterday'); ?>" /></td>
		</tr>

	</table>

<?php
	settings_fields( 'wordbb' );
?>

<p class="submit">
<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
</p>
</form>

<p>
* Leaving the database fields empty, the plugin will assume that MyBB is installed on the same database as WordPress.
</p>

<p>
** When this option is active, comments links will redirect to the corresponding MyBB threads (if any).
</p>

<p>*** When this option is active, MyBB posts will be displayed as WordPress comments in WP blog posts.<br />

<?php if(0) : ?>
<strong>WARNING</strong>: Note that your comment form will still use WordPress' comment system, so if MyBB posts are displayed as comments on a specific post, any comment sent on that post will not be displayed when this option is active. You may want to encapsulate the comment form in your WordPress theme in an if statement using the <code>wordbb_get_thread_id()</code> function, e.g. <code>if(!wordbb_get_thread_id()) { // show WP comment form } else { // show link to MyBB thread using wordbb_thread_link() }</code>. For more details, please <a href="http://valadilene.org/wordbb/wordbb-template-changes">refer to this page</a>.
<?php endif ?>
</p>

<br />
<p style="margin-left: 8px"><em><?php echo __('WordBB - <a href="http://valadilene.org/wordbb" target="_blank">http://valadilene.org/wordbb</a> (<a href="http://valadilene.org/wordbb/wordbb-documentation"  target="_blank">documentation</a>) (<a href="http://valadilene.org/wordbb/wordbb-changelog" target="_blank">changelog</a>) - <a href="http://twitter.com/monochromenight" target="_blank">Follow me</a> on Twitter!<br />If you find this plugin useful or want to motivate me in my projects, please send me some pizza money! You can use the form in the sidebar over at <a href="http://valadilene.org">http://valadilene.org/wordbb</a> or the button below. Thanks!'); ?></em></p>

<p>
<form action="https://www.paypal.com/cgi-bin/webscr" method="post">
<input type="hidden" name="cmd" value="_s-xclick">
<input type="hidden" name="hosted_button_id" value="7033720">
<input type="image" src="https://www.paypal.com/en_US/i/btn/btn_donate_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
<img alt="" border="0" src="https://www.paypal.com/en_US/i/scr/pixel.gif" width="1" height="1">
</form>
</p>


</div>

<?php
}

function wordbb_categories_page() {
	global $wordbb, $wpdb;

?>
<div class="wrap">

<h2>WordBB Categories</h2>

	<p style="margin-left: 8px"><em><?php echo __('Here you can link WordPress categories to MyBB forums. Every blog post published in a particular category will be duplicated in the corresponding MyBB section.<br />Do not forget to activate HTML code on the MyBB forums you link to WordPress.<br />'); ?></em></p>

<form method="post" action="<?php echo $wordbb->action_url ?>">

<div class="tablenav">

<?php
$taxonomy='category';

$pagenum = isset( $_GET['pagenum'] ) ? absint( $_GET['pagenum'] ) : 0;
if ( empty($pagenum) )
	$pagenum = 1;
	

$tags_per_page = (int) get_user_option( 'edit_' .  $taxonomy . '_per_page' );
if ( empty($tags_per_page) || $tags_per_page < 1 )
	$tags_per_page = 20;
$tags_per_page = apply_filters( 'edit_categories_per_page', $tags_per_page ); // Old filter

$page_links = paginate_links( array(
	'base' => add_query_arg( 'pagenum', '%#%' ),
	'format' => '',
	'prev_text' => __('&laquo;'),
	'next_text' => __('&raquo;'),
	'total' => ceil(wp_count_terms($taxonomy) / $tags_per_page),
	'current' => $pagenum
));

if ( $page_links )
	echo "<div class='tablenav-pages'>$page_links</div>";
?>

<br class="clear" />
</div>

<div class="clear"></div>

<?php

	$args = array('type' => 'post', 'child_of' => 0, 'orderby' => 'name', 'order' => 'ASC', 'exclude' => $exclude, 
					'hide_empty' => false, 'include_last_update_time' => false, 'hierarchical' => true, 'pad_counts' => true);

	$cats = get_categories($args);

?>

<table class="widefat fixed" cellspacing="0">
	<thead>
	<tr>
	<th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th> 
	<th scope="col" id="name" class="manage-column column-name" style="">Name</th> 
	<th scope="col" id="description" class="manage-column column-description" style="">Description</th> 
	<th scope="col" id="slug" class="manage-column column-slug" style="">Slug</th> 
	<th scope="col" id="mybb-forum" class="manage-column column-mybb-forum num" style="">MyBB Forum</th>
	</tr>
	</thead>

	<tfoot>
	<tr>
	<th scope="col"  class="manage-column column-cb check-column" style=""><input type="checkbox" /></th> 
	<th scope="col"  class="manage-column column-name" style="">Name</th> 
	<th scope="col"  class="manage-column column-description" style="">Description</th> 
	<th scope="col"  class="manage-column column-slug" style="">Slug</th> 
	<th scope="col"  class="manage-column column-mybb-forum num" style="">MyBB Forum</th> 
	</tr>
	</tfoot>

	<tbody id="the-list" class="list:cat">
<?php
	foreach($cats as $cat) :
		$class=$class==''?' class="alternate"':'';
		
		$bridge=wordbb_get_bridge(WORDBB_CAT,$cat->cat_ID);
		if($bridge)
		{
			$cat_forum=$bridge->mybb_id;
		}

		$html=wordbb_get_array_html($wordbb->forums,"wordbb_cat_forums[$cat->cat_ID]",$cat_forum,'(default)',array(get_option('wordbb_post_forum')),'fid');
?>
	<tr id="tag-<?php echo $cat->cat_ID ?>"<?php echo $class ?>>
	<th scope="row" class="check-column"> <input type="checkbox" name="delete_tags[]" value="<?php echo $cat->cat_ID ?>" /></th>
	<td class="name column-name"><strong><a class="row-title" href="edit-tags.php?action=edit&amp;taxonomy=category&amp;post_type=&amp;tag_ID=<?php echo $cat->cat_ID ?>" title="Edit &#8220;<?php echo $cat->name ?>&#8221;"><?php echo $cat->name ?></a></strong><br /><div class="row-actions"><span class='edit'><a href="edit-tags.php?action=edit&amp;taxonomy=category&amp;post_type=&amp;tag_ID=<?php echo $cat->cat_ID ?>">Edit</a> | </span><span class='inline hide-if-no-js'><a href="#" class="editinline">Quick&nbsp;Edit</a> | </span><span class='delete'><a class='delete-tag' href='edit-tags.php?action=delete&amp;taxonomy=category&amp;tag_ID=<?php echo $cat->cat_ID ?>&amp;_wpnonce=a97b9a530a'>Delete</a></span></div><div class="hidden" id="inline_<?php echo $cat->cat_ID ?>"><div class="name"><?php echo $cat->name ?></div><div class="slug"><?php echo $cat->slug ?></div><div class="parent">0</div></div></td>
	<td class="description column-description"></td>
	<td class="slug column-slug"><?php echo $cat->slug ?></td>
	<td class="mybb-forum column-mybb-forum"><?php echo $html ?></td>
	</tr> 
<?php
	endforeach;
?>
	</tbody>
</table>

<input type="hidden" name="action" value="save_categories" />
<?php wp_nonce_field( 'wordbb_save_categories' ) ?>

<p class="submit">
<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
</p>

</form>
</div>

</div>

<?php
}

/*function wordbb_cat_columns($defaults) {
    $defaults['wordbb_cat_forum'] = __('MyBB forum');
    return $defaults;
}

function wordbb_cat_custom_column($value, $column_name, $id) {
	global $wordbb;
	
    switch( $column_name ) {
		case 'wordbb_cat_forum':
		{
			$bridge=wordbb_get_bridge(WORDBB_CAT,$id);
			if($bridge)
			{
				$cat_forum=$bridge->mybb_id;
			}

			return wordbb_get_array_html($wordbb->forums,"wordbb_cat_forums[$id]",$cat_forum,'(default)',array(get_option('wordbb_post_forum')),'fid');
		}
		break;
    }
}*/

function wordbb_get_array_html($array,$name,$default='',$blank='',$exclude=array(),$id_text='id')
{
	global $wordbb;

	if(!$array)
		return false;

	$html='';
	$html.='<select id="'.$name.'" name="'.$name.'">';
	$html.='<option value="">'.$blank.'</option>';
	foreach($array as $k=>$v) {
		if(!empty($exclude) && is_array($exclude) && array_search($k,$exclude)!==false)
			continue;

		$html.='<option value="'.$k.'"';
		if(!empty($default) && $k==$default) $html.=' selected="selected"';
		$id=(!empty($k))?" ({$id_text} {$k})":'';
		$html.='>'.$v.$id.'</option>';
	}
	$html.='</select>';

	return $html;
}

function wordbb_filter_post_content($content)
{
	// remove more tag
	$content=str_replace('<!--more-->','',$content);
	return $content;
}

function wordbb_get_post_teaser($post)
{
	$content=$post->post_content;
	$excerpt=$post->post_excerpt;
	
	if(!empty($excerpt))
		return $excerpt;

	// remove more tag
	$content=explode('<!--more-->',$content);
	return $content[0];
}

function wordbb_publish_post($id)
{
	global $wordbb;

	if(get_option('wordbb_create_thread')!="on")
		return;

	wordbb_bridge_wp_post($id);
}

function wordbb_bridge_wp_post($id)
{
	$post_bridge=wordbb_get_bridge(WORDBB_POST,$id);

	$post=get_post($id);

	if(get_option('wordbb_create_thread_excerpt')=="on")
	{
		$post_content=wordbb_get_post_teaser($post);
	}
	else
	{
		$post_content=wordbb_filter_post_content($post->post_content);
	}
	
	if(get_option('wordbb_create_thread_post_link')=="on")
	{
		$link_text=get_option('wordbb_create_thread_post_link_text');
		if(empty($link_text))
		{
			$link_text=__('Read the full article on the blog.');
		}
		$link_text=str_replace('%title%',$post->post_title,$link_text);
		$link='<a class="wordbb-full-post" href="'.get_permalink($id).'" title="'.$post->post_title.'">'.$link_text.'</a>';
		if(get_option('wordbb_create_thread_post_link_place')=='before')
		{
			$post_content=$link.'<br />'.$post_content;
		}
		else
		{
			$post_content=$post_content.'<br />'.$link;
		}
	}

	$categories=get_the_category($post->ID);

	foreach($categories as $category)
	{
		// get mybb forum corresponding to wp post category
		$fid=false;
		$bridge=wordbb_get_bridge(WORDBB_CAT,$category->cat_ID);
		if($bridge)
			$fid=$bridge->mybb_id;

		if(empty($fid))
		{
			// if a bridge was not found, use default forum
			$fid=get_option('wordbb_post_forum');

			if(empty($fid))
			{
				// still nothing, give up
				continue;
			}
		}

		// get mybb user corresponding to wp post author
		$uid=false;
		$bridge=wordbb_get_bridge(WORDBB_USER,$post->post_author);
		if($bridge)
			$uid=$bridge->mybb_id;

		if(empty($uid))
		{
			// if a bridge was not found, use default author
			$post_author=wordbb_get_user_info_by_username(get_option('wordbb_post_author'));
			if(!empty($post_author))
				$uid=$post_author->uid;

			if(!$uid)
				return;
		}

		if($post_bridge)
		{
			$params=array();
			$params['tid']=$post_bridge->mybb_id;
			$params['subject']=$post->post_title;
			$params['message']=$post_content;
			$params['fid']=$fid;
			$params['uid']=$uid;
			$params['ip']=wordbb_get_ip();
			$ret=wordbb_do_action('update_thread',$params);

			if(is_array($ret))
			{
				wordbb_set_errors($post->post_title,$ret);
				return;
			}
		}
		else
		{
			$params=array();
			$params['subject']=$post->post_title;
			$params['message']=$post_content;
			$params['fid']=$fid;
			$params['uid']=$uid;
			$params['ip']=wordbb_get_ip();
			$ret=wordbb_do_action('create_thread',$params);

			if(is_array($ret))
			{
				if(!isset($ret['tid']))
				{
					wordbb_set_errors($post->post_title,$ret);
					return;
				}

				// create bridge
				wordbb_bridge(WORDBB_POST,$id,$ret['tid'],WORDBB_WP);
			}
		}
	}
}

function wordbb_delete_bridge_thread($post,$force=false)
{
	if(!$force && get_option('wordbb_delete_thread')!="on")
		return;

	$bridge=wordbb_get_bridge(WORDBB_POST,$post);

	if($bridge)
	{
		$params=array();
		$params['tid']=$bridge->mybb_id;
		$ret=wordbb_do_action('delete_thread',$params);

		if(intval($ret)===1)
		{
			wordbb_bridge(WORDBB_POST,$post,'',WORDBB_WP);
		}
	}
}

function wordbb_users_columns($defaults) {
    $defaults['wordbb_mybb_user'] = __('MyBB user');
    return $defaults;
}

function wordbb_users_custom_column($value, $column_name, $id) {
	global $wordbb;

	if($column_name!='wordbb_mybb_user')
		return;

	$bridge=wordbb_get_bridge(WORDBB_USER,$id);
	$mybb_uid=$bridge->mybb_id;

/*	$users=array();
	foreach($wordbb->users as $uid=>$user)
	{
		$user_bridge=wordbb_get_bridge(WORDBB_USER,'',$uid);
		if(empty($user_bridge) || (!empty($user_bridge) && $user_bridge->wp_id==$id))
			$users[$uid]=$user;
	}
	return wordbb_get_array_html($users,"wordbb_users[$id]",$mybb_uid,'',array(),'uid');*/

	$mybb_user=wordbb_get_user_info($mybb_uid);
	if(!empty($mybb_user))
		$username=$mybb_user->username;

	return '<input type="text" name="wordbb_users['.$id.']" value="'.$username.'" />';
}

function wordbb_posts_columns($defaults) {
	$defaults['wordbb_mybb_thread'] = "MyBB thread";

//	unset($defaults['tags']);
	return $defaults;
}

function wordbb_posts_custom_column($column_name, $id) {
	global $wordbb;

	if(!$wordbb->postcounts)
	{
		// act like this is a WP loop, so we can have postcounts and stuff
		wordbb_loop_start();
	}
	
	$post=get_post($id);
	$bridge=wordbb_get_bridge(WORDBB_POST,$id);
	if($bridge)
	{
		$tid=$bridge->mybb_id;
	}

	switch($column_name)
	{
	case 'wordbb_mybb_thread':
		{
			echo "<div id='wordbb_column_{$id}'>";
			if(empty($tid))
			{
?>
	<a class="edit" href="<?php echo wp_nonce_url($wordbb->action_url.'?action=bridge_post&post='.$id,'wordbb_bridge_post_'.$id) ?>">Create thread</a>
<?php
			}
			else
			{
				$onclick="return confirm('You are about to delete the MyBB thread linked to \'".$post->post_title."\'\\n\'Cancel\' to stop, \'OK\' to delete.')";

?>
	<a class="edit" href="<?php echo wp_nonce_url($wordbb->action_url.'?action=sync_bridge_thread&post='.$id,'wordbb_sync_bridge_thread_'.$id) ?>">Sync</a> -

	<a class="delete" href="<?php echo wp_nonce_url($wordbb->action_url.'?action=delete_bridge_thread&post='.$id,'wordbb_delete_bridge_thread_'.$id) ?>" onclick="<?php echo $onclick ?>">Delete</a>
<?php
			}

			if(!empty($tid)) :
?>
		- <a href="<?php echo $wordbb->mybb_url ?>/showthread.php?tid=<?php echo $tid ?>" title="<?php echo $wordbb->postcounts[$tid]; ?> posts" target="_blank">View</a> (<?php echo $wordbb->postcounts[$tid]; ?>)

<?php endif ?>

		</div>
<?php

	}
	break;
	}

}

function wordbb_loop_start()
{
	global $wordbb, $wp_query;

	if($wordbb->loop_started)
	{
		return;
	}

	$tids=array();

	$posts=&$wp_query->posts;
	if($posts)
	{
		foreach($posts as $post)
		{
			$bridge=wordbb_get_bridge(WORDBB_POST,$post->ID);
			if(!empty($bridge))
				$tids[]=$bridge->mybb_id;
		}

		if(!empty($tids))
		{
			$wordbb->postcounts=_wordbb_get_threads_postcounts($tids);
			$wordbb->posts=_wordbb_get_threads_posts($tids);
			$wordbb->lastposters=_wordbb_get_threads_lastposters($tids);
			$wordbb->comment=0;
		}
	}
	
	$wordbb->loop_started=true;
}

function wordbb_loop_end()
{
	global $wordbb;

	$wordbb->loop_started=false;
}

function wordbb_comment_loop_start()
{
	$wordbb->comment=0;
}

function wordbb_admin_users_update()
{
	$wordbb_users=$_GET['wordbb_users'];

	if(!isset($wordbb_users))
		return;

	foreach($wordbb_users as $id=>$wordbb_user)
	{
		$mybb_user=wordbb_get_user_info_by_username($wordbb_user);
		if(!empty($mybb_user))
		{
			wordbb_bridge(WORDBB_USER,$id,$mybb_user->uid,WORDBB_WP);
		}
		else
		{
			wordbb_bridge(WORDBB_USER,$id,'',WORDBB_WP);
		}
	}
}

function wordbb_comment_on_post($comment_post_ID)
{
	global $wordbb;

	$bridge=wordbb_get_bridge(WORDBB_POST,$comment_post_ID);
	if(!empty($bridge))
	{
		// post this reply to the corresponding thread

		$comment_content = ( isset($_POST['comment']) ) ? trim($_POST['comment']) : null;

		$params=array();
		$params['message']=stripslashes($comment_content);
		$params['uid']=$wordbb->loggeduserinfo->uid;
		$params['tid']=$bridge->mybb_id;
		$params['ip']=wordbb_get_ip();
		$ret=wordbb_do_action('create_post',$params);

		$pid=$ret['pid'];

		if(get_option('wordbb_redirect_mybb')=='on')
			$location=$wordbb->mybb_url.'/showthread.php?tid='.$bridge->mybb_id.'&pid='.$pid.'#pid'.$pid;
		else
			$location=get_permalink($comment_post_ID).'#comment-'.$pid;

		header('Location: '.$location);

		exit;
	}
}

function wordbb_get_comments_number($count)
{
	global $wordbb, $post;

	$bridge=wordbb_get_bridge(WORDBB_POST,$post->ID);
	if(!empty($bridge))
		return $wordbb->postcounts[$bridge->mybb_id];

	return $count;
}

function wordbb_get_comments_array($comments)
{
	global $wordbb, $post;

	$bridge=wordbb_get_bridge(WORDBB_POST,$post->ID);
	if(!empty($bridge))
	{
		$comments=null;
		$replies=&$wordbb->posts[$bridge->mybb_id];

		if(!empty($replies))
		{
			$comments=array();

			foreach($replies as $reply)
			{
				$comment = null;
				$comment->comment_ID = $reply->pid;
				$comment->comment_post_ID = $post->ID;
				$comment->comment_author = $reply->username;
				$comment->comment_author_email = "";
				$comment->comment_author_url = $wordbb->mybb_url.'/member.php?action=profile&uid='.$reply->uid;
				$comment->comment_author_IP = $reply->ipaddress;
				$comment->comment_date = date('Y-m-d H:i:s',$reply->dateline);
				$comment->comment_date_gmt = date('Y-m-d H:i:s',$reply->dateline);
				$comment->comment_content = $reply->message;
				$comment->comment_karma = "0";
				$comment->comment_approved = "1";
				$comment->comment_agent = "";
				$comment->comment_type = "";
				$comment->comment_parent = $reply->replyto;
				$comment->user_id = "0";
				
				$comments[]=$comment;
			}
		}
	}

	return $comments;
}

function wordbb_get_comment_link($link,$comment,$args)
{
	global $wordbb, $post;

	if(empty($post_ID))
		$post_ID=$post->ID;

	$wordbb->last_comment=$wordbb->comment;

	$bridge=wordbb_get_bridge(WORDBB_POST,$post_ID);
	$tid=$bridge->mybb_id;
	$pid=$wordbb->posts[$tid][$wordbb->last_comment]->pid;

	// increment current comment counter
	$wordbb->comment++;

	return $wordbb->mybb_url.'/showthread.php?tid='.$tid.'&pid='.$pid.'#pid'.$pid;
}

function wordbb_comment_reply_link($link,$args,$comment,$post)
{
	global $wordbb;

	extract($args, EXTR_SKIP);

	$bridge=wordbb_get_bridge(WORDBB_POST,$post->ID);
	if(!empty($bridge))
	{
		$tid=$bridge->mybb_id;
		$pid=$wordbb->posts[$tid][$wordbb->last_comment]->pid;

		$url=$wordbb->mybb_url.'/newreply.php?tid='.$tid.'&pid='.$pid.'#pid'.$pid;
		$link="<a rel='nofollow' class='comment-reply-link' href='{$url}'>{$reply_text}</a>";
	}
	return $before.$link.$after;
}

/*
function wordbb_user_register($id)
{
	$user=get_userdata($id);

	$params=array();
	$params['username']=$user->user_login;
	$params['password']=$_POST['pass1'];
	$params['email']=$user->user_email;
	$ret=wordbb_do_action('create_mybb_user',$params);

	if(is_array($ret))
	{
		wordbb_set_errors('MyBB user registration',$ret);
		return;
	}

	// create bridge
	wordbb_bridge(WORDBB_USER,$id,$ret['tid'],WORDBB_WP);
}

function wordbb_user_delete($id)
{
	// remove user bridge
	wordbb_bridge(WORDBB_USER,$id);
}*/

?>
