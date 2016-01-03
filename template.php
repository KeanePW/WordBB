<?php
/*
	WordBB template tags
	This file is part of WordBB plugin. Get it on http://valadilene.org/wordbb
	http://valadilene.org/
*/

function wordbb_thread_link()
{
	global $wordbb, $id;

	$bridge=wordbb_get_bridge(WORDBB_POST,$id);
	if($bridge)
	{
		$tid=$bridge->mybb_id;
		echo $wordbb->mybb_url.'/showthread.php?tid='.$tid;
	}
}

function wordbb_get_thread_id()
{
	global $wordbb, $id;

	$tid=false;
	$bridge=wordbb_get_bridge(WORDBB_POST,$id);
	if($bridge)
	{
		$tid=$bridge->mybb_id;
	}
	return $tid;
}

function wordbb_last_comment_by($line='')
{
	global $wordbb, $id;

	$bridge=wordbb_get_bridge(WORDBB_POST,$id);
	if($bridge)
	{
		$tid=$bridge->mybb_id;

		if(!empty($tid) && !empty($wordbb->lastposters[$tid]))
		{
			$lastposter=$wordbb->lastposters[$tid];
			
			if(!empty($line))
				_e( str_replace('$user', $lastposter, $line) );
			else
				_e( "Last comment by $lastposter" );
		}
	}
}

function wordbb_comments_popup_link($zero = false, $one = false, $more = false, $css_class = '', $none = false)
{
	global $wordbb, $id, $wpcommentspopupfile, $wpcommentsjavascript, $post;

	$bridge=wordbb_get_bridge(WORDBB_POST,$id);
	if($bridge)
	{
		$tid=$bridge->mybb_id;

		if ( false === $zero ) $zero = __( 'No Comments' );
		if ( false === $one ) $one = __( '1 Comment' );
		if ( false === $more ) $more = __( '% Comments' );
		if ( false === $none ) $none = __( 'Comments Off' );

		$number = get_comments_number( $id );

		if ( 0 == $number && !comments_open() && !pings_open() ) {
			echo '<span' . ((!empty($css_class)) ? ' class="' . esc_attr( $css_class ) . '"' : '') . '>' . $none . '</span>';
			return;
		}

		if ( post_password_required() ) {
			echo __('Enter your password to view comments');
			return;
		}

		echo '<a href="';
		
		if(get_option('wordbb_use_mybb_comments')=='on' && get_option('wordbb_show_mybb_comments')!='on' && !empty($tid))
		{
			wordbb_thread_link();
			echo '"';
		}
		else
		{
			if ($wpcommentsjavascript) {
				if ( empty($wpcommentspopupfile) )
					$home = get_option('home');
				else
					$home = get_option('siteurl');
				echo $home . '/' . $wpcommentspopupfile.'?comments_popup='.$id;
				echo '" onclick="wpopen(this.href); return false"';
			} else { // if comments_popup_script() is not in the template, display simple comment link
				if ( 0 == $number )
					echo get_permalink() . '#respond';
				else
					comments_link();
				echo '"';
			}
		}
		
		if (!empty($css_class)) {
			echo ' class="'.$css_class.'"';
		}
		$title = attribute_escape(apply_filters('the_title', get_the_title()));
		echo ' title="' . sprintf( __('Comment on %s'), $title ) .'">';
		comments_number($zero, $one, $more, $number);
		echo '</a>';
		
		echo '&nbsp;';
	}
	else
	{
		comments_popup_link($zero,$one,$more,$none);
	}
}

function wordbb_get_username()
{
	global $wordbb;

	return $wordbb->loggeduserinfo->username;
}

function wordbb_get_avatar()
{
	global $wordbb;

	$avatar=$wordbb->loggeduserinfo->avatar;
	$url=$avatar;

	if($wordbb->loggeduserinfo->avatartype!='remote')
		$url=$wordbb->mybb_url.'/'.$avatar;

	return $url;
}

function wordbb_get_pms()
{
	global $wordbb;

	$pms=array();
	$pms['totalpms']=$wordbb->loggeduserinfo->totalpms;
	$pms['unreadpms']=$wordbb->loggeduserinfo->unreadpms;

	return $pms;
}

function wordbb_get_lastvisit()
{
	global $wordbb;

	return $wordbb->loggeduserinfo->lastvisit;
}

function wordbb_get_friendly_lastvisit()
{
	global $wordbb;

	$lastvisit=wordbb_my_date($wordbb->mybbsettings['dateformat'],$wordbb->loggeduserinfo->lastvisit,'',1,get_option('wordbb_langtoday'),get_option('wordbb_langyesterday')).', '.wordbb_my_date($wordbb->mybbsettings['timeformat'],$wordbb->loggeduserinfo->lastvisit);

	return $lastvisit;
}

function wordbb_get_logged_user_info()
{
	global $wordbb;

	return $wordbb->loggeduserinfo;
}

function wordbb_get_logout_key()
{
	global $wordbb;
	
	if(empty($wordbb->loggeduserinfo))
		return false;
	
	return md5($wordbb->loggeduserinfo->loginkey);
}

function wordbb_get_logout_url()
{
	global $wordbb;
	
	if(empty($wordbb->loggeduserinfo))
		return false;
	
	return $wordbb->mybb_url.'/member.php?action=logout&logoutkey='.wordbb_get_logout_key();	
}

?>