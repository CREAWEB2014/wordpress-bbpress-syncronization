<?php
/*
Plugin Name: WordPress-bbPress syncronization
Plugin URI: http://bobrik.name/
Description: Sync your WordPress comments to bbPress forum and back.
Version: 0.5.0
Author: Ivan Babrou <ibobrik@gmail.com>
Author URI: http://bobrik.name/

Copyright 2008 Ivan Babroŭ (email : ibobrik@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the license, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; see the file COPYING.  If not, write to
the Free Software Foundation, Inc., 59 Temple Place - Suite 330,
Boston, MA 02111-1307, USA.
*/

// for version checking
$wpbb_version = 0.50;
$min_version = 0.50;

// for mode checking
$wpbb_plugin = 0;


function add_textdomain()
{
	// setting textdomain for translation
	load_plugin_textdomain('wordpress-bbpress-syncronization', false, 'wordpress-bbpress-syncronization');
}

function afterpost($id)
{
	//error_log("wordpress: afterpost");
	if (!wpbb_do_sync())
		return;
	$comment = get_comment($id);
	$post = get_post($comment->comment_post_ID);
	if (!is_enabled_for_post($post->ID))
		return; // sync disabled for that post
	// do not sync if not enabled for that status
	if (!sync_that_status($comment->comment_ID))
		return;
	$row = get_table_item('wp_post_id', $comment->comment_post_ID);
	// checking topic existance for post instead of comment counting
	if (!$row) {
		// do not create topic for unapproved comment if not enabled
		if (get_option('wpbb_create_topic_anyway') != 'enabled' && $comment->comment_approved != 1)
			return;
		create_bb_topic($post);
		continue_bb_topic($post, $comment);
	} elseif (sync_that_status($comment->comment_ID))
	{
		// continuing discussion on forum
		continue_bb_topic($post, $comment);
	}
}

function afteredit($id)
{
	//error_log("wordpress: afteredit");
	if (!wpbb_do_sync())
		return;
	$comment = get_comment($id);
	$post = get_post($comment->comment_post_ID);
	if (!is_enabled_for_post($post->ID))
		return; // sync disabled for that post
	$row = get_table_item('wp_comment_id', $comment->comment_ID);
	if ($row)
	{
		// have it in database, must sync
		edit_bb_post($post, $comment);
	} else
	{
		if (!sync_that_status($comment->comment_ID))
			return;
		$row = get_table_item('wp_post_id', $comment->comment_post_ID);
		if (!$row)
		{
			// no topic for that post
			if (get_option('wpbb_create_topic_anyway') != 'enabled' && $comment->comment_approved != 1)
				return;
			create_bb_topic($post);
			continue_bb_topic($post, $comment);			
		} else
		{
			continue_bb_topic($post, $comment);
		}
	}
}

function afterdelete($id)
{
	//error_log('wordpress: afterdelete');
	delete_table_item('wp_comment_id', $id);
	$request = array(
		'action' => 'delete_post',
		'comment_id' => $id
	);
	$answer = send_command($request);
	remove_action('wp_set_comment_status', 'afteredit');
}

function afterstatuschange($id)
{
	//error_log('wordpress: afterstatuschange');
	if (!wpbb_do_sync())
		return;
	if (!is_enabled_for_post($id))
		return; // sync disabled for that post
	$post = get_post($id);
	$row = get_table_item('wp_post_id', $post->ID);
	if (!$row)
	{
		return;
	}
	if ($post->comment_status == 'open')
	{
		open_bb_topic($row['bb_topic_id']);
	} elseif ($post->comment_status == 'closed')
	{
		close_bb_topic($row['bb_topic_id']);
	}
}

function afterpuplish($id)
{
	//error_log('wordpress: afterpuplish');
	if (!wpbb_do_sync())
		return;
	if (!is_enabled_for_post($id))
		return; // sync disabled for that post
	$post = get_post($id);
	// so maybe? ;)
	$row = get_table_item('wp_post_id', $post->ID);
	if (!$row && get_option('wpbb_topic_after_posting') == 'enabled')
	{
		create_bb_topic($post);
	}
}

function afterpostedit($id)
{
	//error_log('wordpress: afterpostedit');
	if (!wpbb_do_sync())
		return;
	if (!is_enabled_for_post($id))
		return; // sync disabled for that post
	$row = get_table_item('wp_post_id', $id);
	if ($row)
	{
		edit_bb_tags($id, $row['bb_topic_id']);
		edit_bb_first_post($id);
	}
}

function get_real_comment_status($id)
{
	// FIXME: remove that shit! it must work original way fine. Really must? ;)
	global $wpdb;
	$comment = get_comment($id);
	return $wpdb->get_var('SELECT comment_approved FROM '.$wpdb->prefix.'comments WHERE comment_id = '.$id);
}

function wpbb_do_sync()
{
	if (get_option('wpbb_plugin_status') != 'enabled')
		return false;
	global $wpbb_plugin;
	if (!$wpbb_plugin)
		// we don't need endless loop ;)
		return false;
	return true; // everything is ok ;)
}

function sync_that_status($id)
{
	if (get_real_comment_status($id) == 1 || get_option('wpbb_sync_all_comments') == 'enabled')
		return true;
	else
		return false;
}

function is_enabled_for_post($post_id)
{
	if (get_post_meta($post_id, 'wpbb_sync_comments', true) == 'yes')
		return true; // sync enabled for that post
	elseif (get_option('wpbb_sync_by_default') == 'enabled' && get_post_meta($post_id, 'wpbb_sync_comments', true) != 'no')
		return true; // sync enabled for that post
	return false; // sync disabled for that post
}

// ===== start of bb functions =====

function create_bb_topic($post)
{
	$tags = array();
	foreach (wp_get_post_tags($post->ID) as $tag)
	{
		$tags[] = $tag->name;
	}
	$morepos = strpos($post->post_content, '<!--more-->');
	$post_content = $morepos === false ? $post->post_content : substr($post->post_content, 0, $morepos);
	if (get_option('wpbb_quote_first_post') == 'enabled')
	{
		$post_content = '<blockquote>'.$post_content.'</blockquote>';
	}
	$post_content .= '<br/><a href="'.get_permalink($post->ID).'">'.$post->post_title.'</a>';
	$request = array(
		'action' => 'create_topic',
		'topic' => apply_filters('the_title', $post->post_title),
		'post_content' => apply_filters('the_content', $post_content),
		'user' => $post->post_author,
		'tags' => implode(', ', $tags),
		'post_id' => $post->ID,
		'comment_id' => 0,
		'comment_approved' => 1
	);
	$answer = send_command($request);
	$data = unserialize($answer);
	return add_table_item($post->ID, 0, $data['topic_id'], $data['post_id']);
}

function continue_bb_topic($post, $comment)
{
	$request = array(
		'action' => 'continue_topic',
		'post_content' => apply_filters('comment_text', $comment->comment_content),
		'post_id' => $post->ID,
		'comment_id' => $comment->comment_ID,
		'user' => $comment->user_id,
		'comment_author' => $comment->comment_author,
		'comment_author_email' => $comment->comment_author_email,
		'comment_author_url' => $comment->comment_author_url,
		'comment_approved' => get_real_comment_status($comment->comment_ID)
	);
	$answer = send_command($request);
	$data = unserialize($answer);
	add_table_item($post->ID, $comment->comment_ID, $data['topic_id'], $data['post_id']);
}

function edit_bb_post($post, $comment)
{
	$request = array(
		'action' => 'edit_post',
		'post_content' => apply_filters('comment_text', $comment->comment_content),
		'post_id' => $post->ID,
		'comment_id' => $comment->comment_ID,
		'user' => $comment->user_id,
		'comment_author' => $comment->comment_author,
		'comment_author_email' => $comment->comment_author_email,
		'comment_author_url' => $comment->comment_author_url,
		'comment_approved' => get_real_comment_status($comment->comment_ID)
	);
	send_command($request);
}

function edit_bb_first_post($post_id)
{
	$post = get_post($post_id, ARRAY_A);
	$morepos = strpos($post['post_content'], '<!--more-->');
	$post_content = $morepos === false ? $post['post_content'] : substr($post['post_content'], 0, $morepos);
	if (get_option('wpbb_quote_first_post') == 'enabled')
	{
		$post_content = '<blockquote>'.$post_content.'</blockquote>';
	}
	$post_content .= '<br/><a href="'.get_permalink($post['ID']).'">'.$post['post_title'].'</a>';
	$request = array(
		'action' => 'edit_post',
		'get_row_by' => 'wp_post',
		'topic_title' => apply_filters('the_title', $post['post_title']), // editing topic title
		'post_content' => apply_filters('the_content', $post_content),
		'post_id' => $post['ID'],
		'comment_id' => 0, // post, not a comment
		'user' => $comment->user_id,
		'comment_approved' => 1 // approved
	);
	send_command($request);
}

function close_bb_topic($topic)
{
	$request = array(
		'action' => 'close_bb_topic',
		'topic_id' => $topic,
	);
	send_command($request);
}

function open_bb_topic($topic)
{
	$request = array(
		'action' => 'open_bb_topic',
		'topic_id' => $topic,
	);
	send_command($request);
}

function check_bb_settings()
{
	$answer = send_command(array('action' => 'check_bb_settings'));
	$data = unserialize($answer);
	return $data;
}

function set_bb_plugin_status($status)
{
	// when enabling in WordPress
	$request = array(
		'action' => 'set_bb_plugin_status',
		'status' => $status,
	);
	$answer = send_command($request);
	$data = unserialize($answer);
	return $data;
}

function edit_bb_tags($wp_post, $bb_topic)
{
	$tags = array();
	foreach (wp_get_post_tags($wp_post) as $tag)
	{
		$tags[] = $tag->name;
	}
	$request = array(
		'action' => 'edit_bb_tags',
		'topic' => $bb_topic,
		'tags' => implode(', ', $tags)
	);
	send_command($request);
}

function get_bb_topic_first_post($post_id)
{
	global $wpdb;
	return $wpdb->get_var("SELECT bb_post_id FROM ".$wpdb->prefix."wpbb_ids WHERE wp_post_id = $post_id ORDER BY bb_post_id ASC LIMIT 1");
}

// ===== end of bb functions =====

// ===== start of wp functions =====

function edit_wp_comment()
{
	$comment = get_comment($id);
	if (!is_enabled_for_post($comment->comment_post_ID))
		return; // sync disabled for that post
	$new_info = array(
		'comment_ID' => $_POST['comment_id'],
		'comment_content' => $_POST['post_text'],
		'comment_approved' => status_bb2wp($_POST['post_status'])
	);
	remove_all_filters('comment_save_pre');
	wp_update_comment($new_info);
}

function add_wp_comment()
{
	// NOTE: wordpress have something very strange with users
	// everyone cant have an registered id and different display_name
	// and other info for posts. strange? i think so ;)
	if (!is_enabled_for_post($_POST['wp_post_id']))
		return; // sync disabled for that post
	global $current_user;
	get_currentuserinfo();
	$info = array(
		'comment_content' => $_POST['post_text'],
		'comment_post_ID' => $_POST['wp_post_id'],
		'user_id' => $_POST['user'],
		'comment_author_email' => $current_user->user_email,
		'comment_author_url' => $current_user->user_url,
		'comment_author' => $current_user->display_name,
		'comment_agent' => 'wordpress-bbpress-syncronization plugin by bobrik (http://bobrik.name)'
	);
	$comment_id = wp_insert_comment($info);
	wp_set_comment_status($comment_id, status_bb2wp($_POST['post_status']));
	add_table_item($_POST['wp_post_id'], $comment_id, $_POST['topic_id'], $_POST['post_id']);
	$data = serialize(array('comment_id' => $comment_id));
	echo $data;
}

function close_wp_comments()
{
	if (!is_enabled_for_post($_POST['post_id']))
		return; // sync disabled for that post
	global $wpdb;
	$wpdb->query('UPDATE '.$wpdb->prefix.'posts SET comment_status = \'closed\' WHERE ID = '.$_POST['post_id']);
}

function open_wp_comments()
{
	if (!is_enabled_for_post($_POST['post_id']))
		return; // sync disabled for that post
	global $wpdb;
	$wpdb->query('UPDATE '.$wpdb->prefix.'posts SET comment_status = \'open\' WHERE ID = '.$_POST['post_id']);
}

function set_wp_plugin_status()
{
	// to be call through http request
	$status = $_POST['status'];
	if ((check_wp_settings() == 0 && $status == 'enabled') || $status == 'disabled')
	{
		update_option('wpbb_plugin_status', $status);
	} else
	{
		$status = 'disabled';
		update_option('wpbb_plugin_status', $status);
	}
	$data = serialize(array('status' => $status));
	echo $data;
}

function check_wp_settings()
{
	if (!test_pair())
		return 1; // cannot establish connection to bb
	if (!secret_key_equal())
		return 2; // secret keys are not equal
	if (!correct_bbwp_version())
		return 3; // too old bbPress part version
	return 0; // everything is ok
}

function wp_status_error($code)
{
	if ($code == 0)
		return __('Everything is ok!');
	if ($code == 1)
		return __('Cannot establish connection to bbPress part');
	elseif ($code == 2)
		return __('Invalid secret key');
	elseif ($code == 3)
		return __('Too old bbPress part plugin version');
}

function edit_wp_tags()
{
	wp_set_post_tags($_POST['post'], unserialize((str_replace('\"', '"', $_POST['tags']))));
}

// ===== end of wp functions =====

function send_command($pairs)
{
	$url = get_option('wpbb_bbpress_url').'my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php';
	preg_match('@https?://([\-_\w\.]+)+(:(\d+))?/(.*)@', $url, $matches);
	if (!$matches)
		return;
	// setting user
	if (!isset($pairs['user']))
	{
		global $user_ID;
		global $user_login;
		get_currentuserinfo();
		if ($user_ID)
		{
			$pairs['user'] = $user_ID;
			$pairs['username'] = $user_login;
		} else
		{
			// anonymous user
			$pairs['user'] = 0;
		}
	}
	if (substr($url, 0, 5) == 'https')
	{
		// must use php-curl to work with https
		// FIXME: really works? :)
		$ch = curl_init($url);
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $pairs);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
		$answer = curl_exec($ch);
		curl_close($ch);
		return $answer;
	} else
	{
		$port = $matches[3] ? $matches[3] : 80;
		global $wp_version;
		
		$request = '';
		foreach ($pairs as $key => $data)
			$request .= $key.'='.urlencode(stripslashes($data)).'&';

		$http_request  = "POST /$matches[4] HTTP/1.0\r\n";
		$http_request .= "Host: $matches[1]\r\n";
		$http_request .= "Content-Type: application/x-www-form-urlencoded; charset=" . get_option('blog_charset') . "\r\n";
		$http_request .= "Content-Length: " . strlen($request) . "\r\n";
		$http_request .= "User-Agent: WordPress/$wp_version | WordPress-bbPress	syncronization\r\n";
		$http_request .= "\r\n";
		$http_request .= $request;

		$response = '';
		if( false != ( $fs = @fsockopen($matches[1], $port, $errno, $errstr, 10) ) ) {
			fwrite($fs, $http_request);

			while ( !feof($fs) )
				$response .= fgets($fs, 1160); // One TCP-IP packet
			fclose($fs);
			$response = explode("\r\n\r\n", $response, 2);
		}
		return $response[1];
	}
}

function test_pair()
{
	$answer = send_command(array('action' => 'test'));
	// return 1 if test passed, 0 otherwise
	// TODO: check configuration!
	$data = unserialize($answer);
	return $data['test'] == 1 ? 1 : 0;
}

function secret_key_equal()
{
	$answer = send_command(array('action' => 'keytest', 'secret_key' => get_option('wpbb_secret_key')));
	$data = unserialize($answer);
	return $data['keytest'];
}

function compare_keys_local()
{
	return $_POST['secret_key'] == get_option('wpbb_secret_key') ? 1 : 0;
}

function set_global_plugin_status($status)
{
	// FIXME: fix something here.
	$bb_settings = check_bb_settings();
	if (($bb_settings['code'] == 0 && check_wp_settings() == 0 && $status == 'enabled') || $status == 'disabled')
	{
		$bb_status = set_bb_plugin_status($status);
		if ($bb_status['status'] == $status)
		{
			update_option('wpbb_plugin_status', $status);
			return;
		}
	}
	// disable everything, something wrong
	$status = 'disabled';
	$wp_status = set_bb_plugin_status($status);
	update_option('wpbb_plugin_status', $status);		
}

function check_wpbb_settings()
{
	$bb_settings = check_bb_settings();
	$wp_code = check_wp_settings();
	$wp_message = wp_status_error($wp_code);
	if ($wp_code != 0)
	{
		$data['code'] = $wp_code;
		$data['message'] = '[WordPress part] '.$wp_message;
	} elseif ($bb_settings['code'] != 0)
	{
		$data['code'] = $bb_settings['code'];
		$data['message'] = '[bbPress part] '.$bb_settings['message'];
	} else
	{
		$data['code'] = 0;
		$data['message'] = __('Everything is ok!');
	}
	return $data;
}

if (isset($_REQUEST['wpbb-listener']))
{
	// define redirection if request have wpbb-listener key
	add_action('template_redirect', 'wpbb_listener');
} else
{
	// work as truly plugin
	global $wpbb_plugin;
	$wpbb_plugin = 1;
}

function wpbb_listener()
{
	// TODO: catching commands
	set_current_user($_POST['user']);
	//error_log("GOT COMMAND for WordPress part: ".$_POST['action']);
	if ($_POST['action'] == 'test')
	{
		echo serialize(array('test' => 1));
		return;
	} elseif ($_POST['action'] == 'keytest')
	{
		echo serialize(array('keytest' => compare_keys_local()));
		return;
	}
	// here we need secret key, only if not checking settings
	if (!secret_key_equal() && $_POST['action'] != 'check_wp_settings')
	{
		// go away, damn cheater!
		return;
	}
	if ($_POST['action'] == 'set_wp_plugin_status')
	{
		set_wp_plugin_status();
	} elseif ($_POST['action'] == 'check_wp_settings')
	{
		$code = check_wp_settings();
		echo serialize(array('code' => $code, 'message' => wp_status_error($code)));
	} elseif ($_POST['action'] == 'get_wpbb_version')
	{
		global $wpbb_version;
		echo serialize(array('version' => $wpbb_version));
	}
	// we need enabled plugins for next actions
	if (get_option('wpbb_plugin_status') != 'enabled')
	{
		// stop sync
		return;
	}
	if ($_POST['action'] == 'edit_comment')
	{
		edit_wp_comment();
	} elseif ($_POST['action'] == 'add_comment')
	{
		add_wp_comment();
	} elseif ($_POST['action'] == 'close_wp_comments')
	{
		close_wp_comments();
	} elseif ($_POST['action'] == 'open_wp_comments')
	{
		open_wp_comments();
	} elseif ($_POST['action'] == 'edit_wp_tags')
	{
		edit_wp_tags();
	}
	exit;
}

function wpbb_install()
{
	// create table at first install
	global $wpdb;
	$wpbb_sync_db_version = 0.2;
	$table = $wpdb->prefix.'wpbb_ids';
	$sql = 'CREATE TABLE '.$table.' (
		`wp_comment_id` INT UNSIGNED NOT NULL,
		`wp_post_id` INT UNSIGNED NOT NULL,
		`bb_topic_id` INT UNSIGNED NOT NULL,
		`bb_post_id` INT UNSIGNED NOT NULL
	);';
	if ($wpdb->get_var('SHOW TABLES LIKE \'$table_name\'') != $table_name) 
	{
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		add_option('wpbb_sync_db_version', $wpbb_sync_db_version);
	}
	$installed_version = get_option('wpbb_sync_db_version');
	// upgrade table if necessary
	if ($installed_version != $wpbb_sync_db_version)
	{
		require_once(ABSPATH.'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		update_option('wpbb_sync_db_version', $wpbb_sync_db_version);
	}
}

function add_table_item($wp_post, $wp_comment, $bb_topic, $bb_post)
{
	global $wpdb;
	return $wpdb->query('INSERT INTO '.$wpdb->prefix."wpbb_ids (wp_post_id, wp_comment_id, bb_topic_id, bb_post_id)
		VALUES ($wp_post, $wp_comment, $bb_topic, $bb_post)");
}

function get_table_item($field, $value)
{
	global $wpdb;
	return $wpdb->get_row('SELECT * FROM '.$wpdb->prefix."wpbb_ids WHERE $field = $value LIMIT 1", ARRAY_A);
}

function delete_table_item($field, $value)
{
	global $wpdb;
	$wpdb->query('DELETE FROM '.$wpdb->prefix."wpbb_ids WHERE $field = $value");
}

function status_bb2wp($status)
{
	// return WordPres comment status equal to bbPress post status
	if ($status == 0)
		return 1; // hold
	if ($status == 1)
		return 0; // approved
	if ($status == 2)
		return 'spam'; // spam
}

function options_page()
{
	if (function_exists('add_submenu_page'))
	{
		add_submenu_page('plugins.php', __('bbPress syncronization'), __('bbPress syncronization'), 'manage_options', 'wpbb-config', 'wpbb_config');
	}
}

function wpbb_config() {
	if (isset($_POST['stage']) && $_POST['stage'] == 'process')
	{
		if (function_exists('current_user_can') && !current_user_can('manage_options'))
			die(__('Cheatin&#8217; uh?'));
		update_option('wpbb_bbpress_url', $_POST['bbpress_url']);
		update_option('wpbb_secret_key', $_POST['secret_key']);
		update_option('wpbb_comments_to_show', (int) $_POST['comments_to_show'] >= -1 ? (int) $_POST['comments_to_show'] : -1);
		update_option('wpbb_max_comments_with_form', (int) $_POST['max_comments_with_form'] >= -1 ? (int) $_POST['max_comments_with_form'] : -1);
		$_POST['plugin_status'] == 'on' ? set_global_plugin_status('enabled') : set_global_plugin_status('disabled');
		$_POST['enable_quoting'] == 'on' ? update_option('wpbb_quote_first_post', 'enabled') : update_option('wpbb_quote_first_post', 'disabled');
		$_POST['sync_by_default'] == 'on' ? update_option('wpbb_sync_by_default', 'enabled') : update_option('wpbb_sync_by_default', 'disabled');
		$_POST['sync_all_comments'] == 'on' ? update_option('wpbb_sync_all_comments', 'enabled') : update_option('wpbb_sync_all_comments', 'disabled');
		$_POST['point_to_forum'] == 'on' ? update_option('wpbb_point_to_forum', 'enabled') : update_option('wpbb_point_to_forum', 'disabled');
		$_POST['create_topic_anyway'] == 'on' ? update_option('wpbb_create_topic_anyway', 'enabled') : update_option('wpbb_create_topic_anyway', 'disabled');
		$_POST['topic_after_posting'] == 'on' ? update_option('wpbb_topic_after_posting', 'enabled') : update_option('wpbb_topic_after_posting', 'disabled');
	}

?>
<div class="wrap">
	<h2>bbPress syncronization</h2>
	<form name="form1" method="post" action="">
	<input type="hidden" name="stage" value="process" />
	<table width="100%" cellspacing="2" cellpadding="5" class="form-table">
		<tr valign="baseline">
			<th scope="row"><?php _e("bbPress plugin url", $textdomain); ?></th>
			<td>
				<input type="text" name="bbpress_url" value="<?php echo get_option('wpbb_bbpress_url'); ?>" />
				<?php
				if (!get_option('wpbb_bbpress_url'))
				{
					_e('bbPress url (we\'ll add <em>my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php</em> to your url)', $textdomain);
				} else
				{
					if (test_pair())
					{
						_e('Everything is ok!', $textdomain);
					} else
					{
						_e('URL is incorrect or connection error, please verify it (full variant): '.get_option('wpbb_bbpress_url').'my-plugins/wordpress-bbpress-syncronization/bbwp-sync.php');
					}
				}
				?>
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Secret key', $textdomain);  ?></th>
			<td>
				<input type="text" name="secret_key" value="<?php echo get_option('wpbb_secret_key', $textdomain); ?>" />
				<?php
				if (!get_option('wpbb_secret_key'))
				{
					_e('We need it for secure communication between your systems', $textdomain);
				} else
				{
					if (secret_key_equal())
					{
						_e('Everything is ok!', $textdomain);
					} else
					{
						_e('Error! Not equal secret keys in WordPress and bbPress', $textdomain);
					}
				}
				?>
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Sync comments by default', $textdomain); ?></th>
			<td>
				<input type="checkbox" name="sync_by_default"<?php echo (get_option('wpbb_sync_by_default') == 'enabled') ? ' checked="checked"' : '';?> /> (Also will be used for posts without any sync option value)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Create topic on posting', $textdomain); ?></th>
			<td>
				<input type="checkbox" name="topic_after_posting"<?php echo (get_option('wpbb_topic_after_posting') == 'enabled') ? ' checked="checked"' : '';?> /> (Will create topic in bbPress after WordPress post publishing)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Sync all comments', $textdomain); ?></th>
			<td>
				<input type="checkbox" name="sync_all_comments"<?php echo (get_option('wpbb_sync_all_comments') == 'enabled') ? ' checked="checked"' : '';?> /> (Sync comment even if not approved. Comment will have the same status at forum)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Create topic anyway', $textdomain); ?></th>
			<td>
				<input type="checkbox" name="create_topic_anyway"<?php echo (get_option('wpbb_create_topic_anyway') == 'enabled') ? ' checked="checked"' : '';?> /> (Create topic even if comment not approved. Will create topic <strong>without</strong> unapproved comment, only first post)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Enable quoting', $textdomain); ?></th>
			<td>
				<input type="checkbox" name="enable_quoting"<?php echo (get_option('wpbb_quote_first_post') == 'enabled') ? ' checked="checked"' : '';?> /> (If enabled, first post summary in bbPress will be blockquoted)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Amount of comments to show', $textdomain); ?></th>
			<td>
				<input type="text" name="comments_to_show" value="<?php echo get_option('wpbb_comments_to_show'); ?>" /> (Set to <em>-1</em> to show all comments)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Point to forum in latest comment', $textdomain); ?></th>
			<td>
				<input type="checkbox" name="point_to_forum"<?php echo (get_option('wpbb_point_to_forum') == 'enabled') ? ' checked="checked"' : ''; ?> /> (If enabled, lats comment will have link to forum discussion. Don't set previvous option to 0 to use that)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Max comments with form', $textdomain); ?></th>
			<td>
				<input type="text" name="max_comments_with_form" value="<?php echo get_option('wpbb_max_comments_with_form'); ?>" /> (Set to <em>-1</em> to show new comment form with any comments count)
			</td>
		</tr>
		<tr valign="baseline">
			<th scope="row"><?php _e('Enable plugin', $textdomain); ?></th>
			<td><?php $check = check_wpbb_settings(); if ($check['code'] != 0) set_global_plugin_status('disabled'); ?>
				<input type="checkbox" name="plugin_status"<?php echo (get_option('wpbb_plugin_status') == 'enabled') ? ' checked="checked"' : ''; echo ($check['code'] == 0) ? '' : ' disabled="disabled"'; ?> /> (<?php echo ($check['code'] == 0) ? 'Allowed by both parts' : 'Not allowed: '.$check['message'] ?>)
			</td>
		</tr>
	</table>
	<p class="submit">
		<input type="submit" name="Submit" value="<?php _e('Save Changes', $textdomain); ?>" />
	</p>
	</form>
</div>
<?php
}

function deactivate_wpbb()
{
	// deactivate on disabling
	set_global_plugin_status('disabled');
}

function wpbb_post_options()
{
	global $post;
	echo '<div class="postbox"><h3>bbPress syncronization</h3><div class="inside"><p>Syncronize post comments with bbPress?  ';
	if (get_post_meta($post->ID, 'wpbb_sync_comments', true) == 'no') 
	{
		$checked = '';
	} else 
	{
		$checked = 'checked="checked"';
	}
	echo '<input type="checkbox" name="wpbb_sync_comments" '.$checked.' />'; 
	echo '</p></div></div>';
}

function wpbb_store_post_options($post_id)
{
	$post = get_post($post_id);
	$value = $_POST['wpbb_sync_comments'] == 'on' ? 'yes' : 'no';
	update_post_meta($post_id, 'wpbb_sync_comments', $value);
}


function wpbb_comments_array_count($comments)
{
	$maxform = get_option('wpbb_max_comments_with_form');
	global $post;
	if (count($comments) > $maxform)
		$post->comment_status = 'closed';
	$max = get_option('wpbb_comments_to_show');
	if (get_option('wpbb_point_to_forum') == 'enabled' && $max != 0)
	{
		$row = get_table_item('wp_post_id', $post->ID);
		if ($row)
		{
			$topic_id = $row['bb_topic_id'];
			$answer = unserialize(send_command(array('action' => 'get_topic_link', 'topic_id' => $topic_id)));
			$link = $answer['link'];
		}
		// FIXME: dirty hack to get last array element
		$comments[count($comments)-1]->comment_content .= '<br/><p class="wpbb_continue_discussion">'.
			__("Please continue disussion on the forum: ")."<a href='$link'> link</a></p>";
	}
	if ($max == -1)
		return $comments;
	$i = count($comments);
	while ($i > $max)
	{
		array_shift($comments);
		--$i;
	}
	return $comments;
}


function correct_bbwp_version()
{
	$answer = unserialize(send_command(array('action' => 'get_bbwp_version')));
	global $min_version;
	return ($answer['version'] < $min_version) ? 0 : 1;
}


add_action('init', 'add_textdomain');
add_action('deactivate_wordpress-bbpress-syncronization/wpbb-sync.php', 'deactivate_wpbb');
add_action('comment_post', 'afterpost');
add_action('edit_comment', 'afteredit');
add_action('delete_comment', 'afterdelete');
add_action('wp_set_comment_status', 'afteredit');
add_action('edit_post', 'afterpostedit');
add_action('edit_post', 'afterstatuschange');
add_action('wp_set_comment_status', 'afteredit');
add_action('admin_menu', 'options_page');
register_activation_hook('wordpress-bbpress-syncronization/wpbb-sync.php', 'wpbb_install');
add_action('edit_form_advanced', 'wpbb_post_options');
add_action('draft_post', 'wpbb_store_post_options');
add_action('publish_post', 'wpbb_store_post_options');
add_action('save_post', 'wpbb_store_post_options');
add_action('publish_post', 'afterpuplish');
add_filter('comments_array', 'wpbb_comments_array_count');

?>
