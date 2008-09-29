<?php
/**
*
* @package install
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
*/
if (!defined('IN_INSTALL'))
{
	// Someone has tried to access the file direct. This is not a good idea, so exit
	exit;
}

if (!empty($setmodules))
{
	$module[] = array(
		'module_type'		=> 'update',
		'module_title'		=> 'UPDATE',
		'module_filename'	=> substr(basename(__FILE__), 0, -strlen($phpEx)-1),
		'module_order'		=> 20,
		'module_subs'		=> '',
		'module_stages'		=> array('INTRO', 'REQUIREMENTS', 'UPDATE_DB', 'ADVANCED', 'FINAL'),
		'module_reqs'		=> ''
	);
}

/**
* Installation
* @package install
*/
class install_update extends module
{
	function install_update(&$p_master)
	{
		$this->p_master = &$p_master;
	}

	function main($mode, $sub)
	{
		global $user, $template, $phpbb_root_path, $cache, $phpEx;

		$gallery_config = load_gallery_config();

		switch ($sub)
		{
			case 'intro':
				$this->page_title = $user->lang['SUB_INTRO'];

				$template->assign_vars(array(
					'TITLE'			=> $user->lang['UPDATE_INSTALLATION'],
					'BODY'			=> $user->lang['UPDATE_INSTALLATION_EXPLAIN'],
					'L_SUBMIT'		=> $user->lang['NEXT_STEP'],
					'U_ACTION'		=> $this->p_master->module_url . "?mode=$mode&amp;sub=requirements",
				));

			break;

			case 'requirements':
				$this->check_server_requirements($mode, $sub);

			break;

			case 'update_db':
				$database_step = request_var('step', 0);
				switch ($database_step)
				{
					case 0:
						$this->update_db_schema($mode, $sub);
					break;
					case 1:
					case 2:
						$this->update_db_data($mode, $sub);
					break;
					case 3:
						$this->thinout_db_schema($mode, $sub);
					break;
				}
			break;

			case 'advanced':
				$this->obtain_advanced_settings($mode, $sub);

			break;

			case 'final':
				set_gallery_config('phpbb_gallery_version', NEWEST_PG_VERSION);
				$cache->purge();

				$template->assign_vars(array(
					'TITLE'		=> $user->lang['INSTALL_CONGRATS'],
					'BODY'		=> sprintf($user->lang['INSTALL_CONGRATS_EXPLAIN'], NEWEST_PG_VERSION),
					'L_SUBMIT'	=> $user->lang['GOTO_GALLERY'],
					'U_ACTION'	=> append_sid($phpbb_root_path . 'gallery/index.' . $phpEx),
				));


			break;
		}

		$this->tpl_name = 'install_install';
	}

	/**
	* Checks that the server we are installing on meets the requirements for running phpBB
	*/
	function check_server_requirements($mode, $sub)
	{
		global $user, $template, $phpbb_root_path, $phpEx;

		$this->page_title = $user->lang['STAGE_REQUIREMENTS'];

		$template->assign_vars(array(
			'TITLE'		=> $user->lang['REQUIREMENTS_TITLE'],
			'BODY'		=> $user->lang['REQUIREMENTS_EXPLAIN'],
		));

		$passed = array('php' => false, 'files' => false,);

		// Test for basic PHP settings
		$template->assign_block_vars('checks', array(
			'S_LEGEND'			=> true,
			'LEGEND'			=> $user->lang['PHP_SETTINGS'],
		));

		// Check for GD-Library
		if (@extension_loaded('gd') || can_load_dll('gd'))
		{
			$passed['php'] = true;
			$result = '<strong style="color:green">' . $user->lang['YES'] . '</strong>';
		}
		else
		{
			$result = '<strong style="color:red">' . $user->lang['NO'] . '</strong>';
		}

		$template->assign_block_vars('checks', array(
			'TITLE'			=> $user->lang['REQ_GD_LIBRARY'],
			'RESULT'		=> $result,

			'S_EXPLAIN'		=> false,
			'S_LEGEND'		=> false,
		));

		// Check permissions on files/directories we need access to
		$template->assign_block_vars('checks', array(
			'S_LEGEND'			=> true,
			'LEGEND'			=> $user->lang['FILES_REQUIRED'],
			'LEGEND_EXPLAIN'	=> $user->lang['FILES_REQUIRED_EXPLAIN'],
		));

		$directories = array('gallery/import/', 'gallery/upload/', 'gallery/upload/cache/');

		umask(0);

		$passed['files'] = true;
		foreach ($directories as $dir)
		{
			$write = false;

			// Now really check
			if (file_exists($phpbb_root_path . $dir) && is_dir($phpbb_root_path . $dir))
			{
				if (!@is_writable($phpbb_root_path . $dir))
				{
					@chmod($phpbb_root_path . $dir, 0777);
				}
			}

			// Now check if it is writable by storing a simple file
			$fp = @fopen($phpbb_root_path . $dir . 'test_lock', 'wb');
			if ($fp !== false)
			{
				$write = true;
			}
			@fclose($fp);

			@unlink($phpbb_root_path . $dir . 'test_lock');

			$passed['files'] = ($write && $passed['files']) ? true : false;

			$write = ($write) ? '<strong style="color:green">' . $user->lang['WRITABLE'] . '</strong>' : (($exists) ? '<strong style="color:red">' . $user->lang['UNWRITABLE'] . '</strong>' : '');

			$template->assign_block_vars('checks', array(
				'TITLE'		=> $dir,
				'RESULT'	=> $write,

				'S_EXPLAIN'	=> false,
				'S_LEGEND'	=> false,
			));
		}

		$url = (!in_array(false, $passed)) ? $this->p_master->module_url . "?mode=$mode&amp;sub=update_db" : $this->p_master->module_url . "?mode=$mode&amp;sub=requirements";
		$submit = (!in_array(false, $passed)) ? $user->lang['INSTALL_START'] : $user->lang['INSTALL_TEST'];

		$template->assign_vars(array(
			'L_SUBMIT'	=> $submit,
			'S_HIDDEN'	=> '',
			'U_ACTION'	=> $url,
		));
	}

	/**
	* Add some Tables, Columns and Index to the database-schema
	*/
	function update_db_schema($mode, $sub)
	{
		global $db, $user, $template, $gallery_config, $table_prefix;

		$gallery_config = load_gallery_config();
		$this->page_title = $user->lang['STAGE_UPDATE_DB'];

		if (!isset($gallery_config['phpbb_gallery_version']))
		{
			$gallery_config['phpbb_gallery_version'] = (isset($gallery_config['album_version'])) ? $gallery_config['album_version'] : '0.0.0';
			if (in_array($gallery_config['phpbb_gallery_version'], array('0.1.2', '0.1.3', '0.2.0', '0.2.1', '0.2.2', '0.2.3', '0.3.0', '0.3.1')))
			{
				$sql = 'SELECT * FROM ' . GALLERY_ALBUMS_TABLE;
				if (@$db->sql_query_limit($sql, 1) == false)
				{
					// DB-Table missing
					$gallery_config['phpbb_gallery_version'] = '0.1.2';
					$check_succeed = true;
				}
				else
				{
					// No Schema Changes between 0.1.3 and 0.2.2
					$gallery_config['phpbb_gallery_version'] = '0.2.2';
					if (nv_check_column(GALLERY_ALBUMS_TABLE, 'album_user_id'))
					{
						$gallery_config['phpbb_gallery_version'] = '0.2.3';
						$sql = 'SELECT * FROM ' . GALLERY_FAVORITES_TABLE;
						if (@$db->sql_query_limit($sql, 1) == true)
						{
							$gallery_config['phpbb_gallery_version'] = '0.3.1';
						}
					}
				}
			}
			else
			{
				// No version-number problems since 0.4.0-RC1
				$gallery_config['phpbb_gallery_version'] = $gallery_config['album_version'];
			}
		}

		$dbms_data = get_dbms_infos();
		$db_schema = $dbms_data['db_schema'];
		$delimiter = $dbms_data['delimiter'];

		switch ($gallery_config['phpbb_gallery_version'])
		{
			case '0.1.2':
			case '0.1.3':
				trigger_error('VERSION_NOT_SUPPORTED', E_USER_ERROR);
/*				nv_create_table('phpbb_gallery_albums',		$dbms_data);
				nv_create_table('phpbb_gallery_comments',	$dbms_data);
				nv_create_table('phpbb_gallery_config',		$dbms_data);
				nv_create_table('phpbb_gallery_images',		$dbms_data);
				nv_create_table('phpbb_gallery_rates',		$dbms_data);*/
			break;

			case '0.2.0':
			case '0.2.1':
			case '0.2.2':
			case '0.2.3':
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_user_id',		array('UINT', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_user_colour',	array('VCHAR:6', ''));
				nv_add_column(USERS_TABLE,			'album_id',				array('UINT', 0));

				nv_change_column(GALLERY_IMAGES_TABLE,	'image_username',	array('VCHAR_UNI', ''));

			case '0.3.0':
			case '0.3.1':
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_images',				array('UINT', 0));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_images_real',		array('UINT', 0));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_last_image_id',		array('UINT', 0));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_image',				array('VCHAR', ''));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_last_image_time',	array('INT:11', 0));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_last_image_name',	array('VCHAR', ''));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_last_username',		array('VCHAR', ''));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_last_user_colour',	array('VCHAR:6', ''));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'album_last_user_id',		array('UINT', 0));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'display_on_index',			array('UINT:3', 1));
				nv_add_column(GALLERY_ALBUMS_TABLE,	'display_subalbum_list',	array('UINT:3', 1));

				nv_add_column(GALLERY_COMMENTS_TABLE,	'comment_user_colour',	array('VCHAR:6', ''));

				nv_add_column(GALLERY_IMAGES_TABLE,	'image_comments',			array('UINT', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_last_comment',		array('UINT', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_filemissing',		array('UINT:3', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_rates',				array('UINT', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_rate_points',		array('UINT', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_rate_avg',			array('UINT', 0));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_status',				array('UINT:3', 1));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_has_exif',			array('UINT:3', 2));
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_favorited',			array('UINT', 0));

				nv_add_column(SESSIONS_TABLE,		'session_album_id',			array('UINT', 0));

				nv_change_column(GALLERY_COMMENTS_TABLE,	'comment_username',	array('VCHAR', ''));

				nv_create_table('phpbb_gallery_favorites',	$dbms_data);
				nv_create_table('phpbb_gallery_modscache',	$dbms_data);
				nv_create_table('phpbb_gallery_permissions',$dbms_data);
				nv_create_table('phpbb_gallery_reports',	$dbms_data);
				nv_create_table('phpbb_gallery_roles',		$dbms_data);
				nv_create_table('phpbb_gallery_users',		$dbms_data);
				nv_create_table('phpbb_gallery_watch',		$dbms_data);

			case '0.3.2-RC1':
			case '0.3.2-RC2':
			case '0.4.0-RC1':
			case '0.4.0-RC2':
				nv_add_column(GALLERY_IMAGES_TABLE,	'image_reported',			array('UINT', 0));

				nv_add_index(GALLERY_USERS_TABLE,	'pg_palbum_id',				array('personal_album_id'));
				nv_add_index(SESSIONS_TABLE,		'session_aid',				array('session_album_id'));
			break;
		}

		set_gallery_config('phpbb_gallery_version', $gallery_config['phpbb_gallery_version']);


		$template->assign_vars(array(
			'BODY'		=> $user->lang['STAGE_CREATE_TABLE_EXPLAIN'],
			'L_SUBMIT'	=> $user->lang['NEXT_STEP'],
			'S_HIDDEN'	=> '',
			'U_ACTION'	=> $this->p_master->module_url . "?mode=$mode&amp;sub=update_db&amp;step=1",
		));
	}

	/**
	* Edit the data in the tables
	*/
	function update_db_data($mode, $sub)
	{
		global $user, $template, $table_prefix, $db;

		$gallery_config = load_gallery_config();
		$database_step = request_var('step', 0);

		$this->page_title = $user->lang['STAGE_UPDATE_DB'];
		$next_update_url = '';
		if ($database_step == 2)
		{
			$gallery_config['phpbb_gallery_version'] = '0.3.2-RC1';
		}

		switch ($gallery_config['phpbb_gallery_version'])
		{
			case '0.1.2':
			case '0.1.3':
			// Cheating?
				trigger_error('VERSION_NOT_SUPPORTED', E_USER_ERROR);
			break;

			case '0.2.0':
			case '0.2.1':
			case '0.2.2':
			case '0.2.3':
				$sql = 'SELECT i.image_user_id, i.image_id, u.username, u.user_colour
					FROM ' . GALLERY_IMAGES_TABLE . ' AS i
					LEFT JOIN ' . USERS_TABLE . " AS u
						ON i.image_user_id = u.user_id
					ORDER BY i.image_id DESC";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$image_id = $row['image_id'];

					if ($row['image_user_id'] == 1 || empty($row['username']))
					{
						continue;
					}

					$sql_ary = array(
						'image_username'		=> $row['username'],
						'image_user_colour'		=> $row['user_colour'],
					);

					$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
						WHERE ' . $db->sql_in_set('image_id', $image_id);
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);

				$sql = 'SELECT i.image_id, i.image_username, image_user_id
					FROM ' . GALLERY_IMAGES_TABLE . " AS i
					WHERE image_album_id = 0
					GROUP BY i.image_user_id DESC";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$album_data = array(
						'album_name'					=> $row['image_username'],
						'parent_id'						=> 0,
						//left_id and right_id are created some lines later
						'album_desc_options'			=> 7,
						'album_desc'					=> '',
						'album_parents'					=> '',
						'album_type'					=> 2,
						'album_user_id'					=> $row['image_user_id'],
					);
					$db->sql_query('INSERT INTO ' . GALLERY_ALBUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $album_data));

					$sql2 = 'SELECT album_id FROM ' . GALLERY_ALBUMS_TABLE . ' WHERE parent_id = 0 AND album_user_id = ' . $row['image_user_id'] . ' LIMIT 1';
					$result2 = $db->sql_query($sql2);
					$row2 = $db->sql_fetchrow($result2);
					$db->sql_freeresult($result2);

					$sql3 = 'UPDATE ' . USERS_TABLE . ' 
							SET album_id = ' . (int) $row2['album_id'] . '
							WHERE user_id  = ' . (int) $row['image_user_id'];
					$db->sql_query($sql3);

					$sql3 = 'UPDATE ' . GALLERY_IMAGES_TABLE . ' 
							SET image_album_id = ' . (int) $row2['album_id'] . '
							WHERE image_album_id = 0
								AND image_user_id  = ' . (int) $row['image_user_id'];
					$db->sql_query($sql3);
				}
				$db->sql_freeresult($result);

			case '0.3.0':
			case '0.3.1':
				// Set some configs
				$num_images = $total_galleries = 0;
				$sql = 'SELECT u.album_id, u.user_id, count(i.image_id) as images
					FROM ' . USERS_TABLE . ' u
					LEFT JOIN ' . GALLERY_IMAGES_TABLE . ' i
						ON i.image_user_id = u.user_id
						AND i.image_status = 1
					GROUP BY i.image_user_id';
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql_ary = array(
						'user_id'				=> $row['user_id'],
						'personal_album_id'		=> $row['album_id'],
						'user_images'			=> $row['images'],
					);
					$num_images += $row['images'];
					$db->sql_query('INSERT INTO ' . GALLERY_USERS_TABLE . $db->sql_build_array('INSERT', $sql_ary));
				}
				$db->sql_freeresult($result);
				$sql = 'SELECT COUNT(album_id) AS albums
					FROM ' . GALLERY_ALBUMS_TABLE . "
					WHERE parent_id = 0
						AND album_user_id <> 0";
				$result = $db->sql_query($sql);
				if ($row = $db->sql_fetchrow($result))
				{
					$total_galleries = $row['albums'];
				}
				$db->sql_freeresult($result);

				set_config('num_images', $num_images, true);
				set_config('gallery_total_images', 1);
				set_config('gallery_user_images_profil', 1);
				set_config('gallery_personal_album_profil', 1);

				set_gallery_config('thumbnail_info_line', 1);
				set_gallery_config('fake_thumb_size', 70);
				set_gallery_config('disp_fake_thumb', 1);
				set_gallery_config('exif_data', 1);
				set_gallery_config('watermark_height', 50);
				set_gallery_config('watermark_width', 200);
				set_gallery_config('personal_counter', $total_galleries);

				//change the sort_method if it is sepcial
				if ($gallery_config['sort_method'] == 'rating')
				{
					set_gallery_config('sort_method', 'image_rate_avg');
				}
				else if ($gallery_config['sort_method'] == 'comments')
				{
					set_gallery_config('sort_method', 'image_comments');
				}
				else if ($gallery_config['sort_method'] == 'new_comment')
				{
					set_gallery_config('sort_method', 'image_last_comment');
				}

				// Update the album_data
				$sql = 'UPDATE ' . GALLERY_ALBUMS_TABLE . ' SET album_type = 1';
				$db->sql_query($sql);

				$sql = 'UPDATE ' . GALLERY_ALBUMS_TABLE . ' SET album_type = 0 WHERE album_user_id = 0';
				$db->sql_query($sql);

				// Add the information for the last_image to the albums part 1: last_image_id, image_count
				$sql = 'SELECT COUNT(i.image_id) images, MAX(i.image_id) last_image_id, i.image_album_id
					FROM ' . GALLERY_IMAGES_TABLE . " i
					WHERE i.image_approval = 1
					GROUP BY i.image_album_id";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql_ary = array(
						'album_images'			=> $row['images'],
						'album_last_image_id'	=> $row['last_image_id'],
					);
					$sql = 'UPDATE ' . GALLERY_ALBUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
						WHERE ' . $db->sql_in_set('album_id', $row['image_album_id']);
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);


				// Add the information for the last_image to the albums part 2: correct album_type, images_real are all images, even unapproved
				$sql = 'SELECT COUNT(i.image_id) images, i.image_album_id
					FROM ' . GALLERY_IMAGES_TABLE . " i
					GROUP BY i.image_album_id";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql_ary = array(
						'album_images_real'	=> $row['images'],
						'album_type'		=> 1,
					);
					$sql = 'UPDATE ' . GALLERY_ALBUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
						WHERE ' . $db->sql_in_set('album_id', $row['image_album_id']);
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);

				// Add the information for the last_image to the albums part 3: user_id, username, user_colour, time, image_name
				$sql = 'SELECT a.album_id, a.album_last_image_id, i.image_time, i.image_name, i.image_user_id, i.image_username, i.image_user_colour, u.user_colour
					FROM ' . GALLERY_ALBUMS_TABLE . " a
					LEFT JOIN " . GALLERY_IMAGES_TABLE . " i
						ON a.album_last_image_id = i.image_id
					LEFT JOIN " . USERS_TABLE . " u
						ON a.album_user_id = u.user_colour
					WHERE a.album_last_image_id > 0";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql_ary = array(
						'album_last_image_time'		=> $row['image_time'],
						'album_last_image_name'		=> $row['image_name'],
						'album_last_username'		=> $row['image_username'],
						'album_last_user_colour'	=> isset($row['user_colour']) ? $row['user_colour'] : '',
						'album_last_user_id'		=> $row['image_user_id'],
					);
					$sql = 'UPDATE ' . GALLERY_ALBUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
						WHERE ' . $db->sql_in_set('album_id', $row['album_id']);
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);

				// Update the image_data
				$sql = 'SELECT rate_image_id, COUNT(rate_user_ip) image_rates, AVG(rate_point) image_rate_avg, SUM(rate_point) image_rate_points
					FROM ' . GALLERY_RATES_TABLE . '
					GROUP BY rate_image_id';
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . '
						SET image_rates = ' . $row['image_rates'] . ',
							image_rate_points = ' . $row['image_rate_points'] . ',
							image_rate_avg = ' . round($row['image_rate_avg'], 2) * 100 . '
						WHERE image_id = ' . $row['rate_image_id'];
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);

				$sql = 'SELECT COUNT(comment_id) comments, MAX(comment_id) image_last_comment, comment_image_id
					FROM ' . GALLERY_COMMENTS_TABLE . "
					GROUP BY comment_image_id";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . ' SET image_comments = ' . $row['comments'] . ',
						image_last_comment = ' . $row['image_last_comment'] . '
						WHERE ' . $db->sql_in_set('image_id', $row['comment_image_id']);
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);

				$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . '
					SET image_status = 2
					WHERE image_lock = 1';
				$db->sql_query($sql);

				$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . '
					SET image_status = 0
					WHERE image_lock = 0
						AND image_approval = 0';
				$db->sql_query($sql);

				// Update the comment_data
				$sql = 'SELECT u.user_colour, c.comment_id
					FROM ' . GALLERY_COMMENTS_TABLE . ' c
					LEFT JOIN ' . USERS_TABLE . ' u
						ON c.comment_user_id = u.user_id';
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					if (isset($row['user_colour']))
					{
						$sql = 'UPDATE ' . GALLERY_COMMENTS_TABLE . "
							SET comment_user_colour = '" . $row['user_colour'] . "'
							WHERE comment_id = " . $row['comment_id'];
						$db->sql_query($sql);
					}
				}
				$db->sql_freeresult($result);

				$next_update_url = $this->p_master->module_url . "?mode=$mode&amp;sub=update_db&amp;step=2";
			break;

			case '0.3.2-RC1':
			case '0.3.2-RC2':
			case '0.4.0-RC1':
				$total_images = 0;
				$sql = 'SELECT COUNT(gi.image_id) AS num_images, u.user_id
					FROM ' . USERS_TABLE . ' u
					LEFT JOIN  ' . GALLERY_IMAGES_TABLE . ' gi ON (u.user_id = gi.image_user_id AND gi.image_status = 1)
					GROUP BY u.user_id';
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$total_images += $row['num_images'];
					$db->sql_query('UPDATE ' . GALLERY_USERS_TABLE . " SET user_images = {$row['num_images']} WHERE user_id = {$row['user_id']}");
				}
				$db->sql_freeresult($result);
				set_config('num_images', $total_images, true);

			case '0.4.0-RC2':
				set_gallery_config('shorted_imagenames', 25);

				$sql = 'SELECT report_image_id, report_id
					FROM ' . GALLERY_REPORTS_TABLE . "
					WHERE report_status = 1";
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . ' SET image_reported = ' . $row['report_id'] . '
						WHERE ' . $db->sql_in_set('image_id', $row['report_image_id']);
					$db->sql_query($sql);
				}
				$db->sql_freeresult($result);


			case '0.4.0-RC3':

				$next_update_url = $this->p_master->module_url . "?mode=$mode&amp;sub=update_db&amp;step=3";
			break;
		}


		$template->assign_vars(array(
			'BODY'		=> $user->lang['UPDATING_DATA'],
			'L_SUBMIT'	=> $user->lang['NEXT_STEP'],
			'S_HIDDEN'	=> '',
			'U_ACTION'	=> $next_update_url,
		));
	}

	/**
	* Remove some old Columns
	*/
	function thinout_db_schema($mode, $sub)
	{
		global $user, $template, $db;

		$gallery_config = load_gallery_config();

		$this->page_title = $user->lang['STAGE_UPDATE_DB'];
		$reparse_modules_bbcode = false;

		switch ($gallery_config['phpbb_gallery_version'])
		{
/*			case '0.1.2':
			case '0.1.3':*/
			case '0.2.0':
			case '0.2.1':
			case '0.2.2':
			case '0.2.3':
			case '0.3.0':
			case '0.3.1':
			case '0.3.2-RC1':
			case '0.3.2-RC2':
			case '0.4.0-RC1':
				/* @todo move on bbcode-change or creating all modules */
				$reparse_modules_bbcode = true;

			case '0.4.0-RC2':
				nv_remove_column(GROUPS_TABLE,			'personal_subalbums');
				nv_remove_column(GROUPS_TABLE,			'allow_personal_albums');
				nv_remove_column(GROUPS_TABLE,			'view_personal_albums');
				nv_remove_column(USERS_TABLE,			'album_id');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_approval');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_order');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_view_level');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_upload_level');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_rate_level');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_comment_level');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_edit_level');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_delete_level');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_view_groups');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_upload_groups');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_rate_groups');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_comment_groups');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_edit_groups');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_delete_groups');
				nv_remove_column(GALLERY_ALBUMS_TABLE,	'album_moderator_groups');
			break;
		}

		$old_configs = array('user_pics_limit', 'mod_pics_limit', 'fullpic_popup', 'personal_gallery', 'personal_gallery_private', 'personal_gallery_limit', 'personal_gallery_view', 'album_version');
		$sql = 'DELETE FROM ' .GALLERY_CONFIG_TABLE . '
			WHERE ' . $db->sql_in_set('config_name', $old_configs);
		$db->sql_query($sql);

		if ($reparse_modules_bbcode)
		{
			$next_update_url = $this->p_master->module_url . "?mode=$mode&amp;sub=advanced";
		}
		else
		{
			$next_update_url = $this->p_master->module_url . "?mode=$mode&amp;sub=final";
		}

		$template->assign_vars(array(
			'BODY'		=> $user->lang['UPDATE_DATABASE_SCHEMA'],
			'L_SUBMIT'	=> $user->lang['NEXT_STEP'],
			'S_HIDDEN'	=> '',
			'U_ACTION'	=> $next_update_url,
		));
	}

	/**
	* Provide an opportunity to customise some advanced settings during the install
	* in case it is necessary for them to be set to access later
	*/
	function obtain_advanced_settings($mode, $sub)
	{
		global $user, $template, $phpEx, $db;

		$create = request_var('create', '');
		if ($create)
		{
			// Add modules
			$choosen_acp_module = request_var('acp_module', 0);
			$choosen_ucp_module = request_var('ucp_module', 0);
			if ($choosen_acp_module < 0)
			{
				$acp_mods_tab = array('module_basename' => '',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => 0,	'module_class' => 'acp',	'module_langname'=> 'ACP_CAT_DOT_MODS',	'module_mode' => '',	'module_auth' => '');
				add_module($acp_mods_tab);
				$choosen_acp_module = $db->sql_nextid();
			}
			// ACP
			$acp_gallery = array('module_basename' => '',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $choosen_acp_module,	'module_class' => 'acp',	'module_langname'=> 'PHPBB_GALLERY',	'module_mode' => '',	'module_auth' => '');
			add_module($acp_gallery);
			$acp_module_id = $db->sql_nextid();
			set_gallery_config('acp_parent_module', $acp_module_id);

			$acp_gallery_overview = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $acp_module_id,	'module_class' => 'acp',	'module_langname'=> 'ACP_GALLERY_OVERVIEW',	'module_mode' => 'overview',	'module_auth' => '');
			add_module($acp_gallery_overview);
			$acp_configure_gallery = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $acp_module_id,	'module_class' => 'acp',	'module_langname'=> 'ACP_GALLERY_CONFIGURE_GALLERY',	'module_mode' => 'configure_gallery',	'module_auth' => '');
			add_module($acp_configure_gallery);
			$acp_gallery_manage_albums = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $acp_module_id,	'module_class' => 'acp',	'module_langname'=> 'ACP_GALLERY_MANAGE_ALBUMS',	'module_mode' => 'manage_albums',	'module_auth' => '');
			add_module($acp_gallery_manage_albums);
			$album_permissions = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $acp_module_id,	'module_class' => 'acp',	'module_langname'=> 'ACP_GALLERY_ALBUM_PERMISSIONS',	'module_mode' => 'album_permissions',	'module_auth' => '');
			add_module($album_permissions);
			$import_images = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $acp_module_id,	'module_class' => 'acp',	'module_langname'=> 'ACP_IMPORT_ALBUMS',	'module_mode' => 'import_images',	'module_auth' => '');
			add_module($import_images);
			$cleanup = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $acp_module_id,	'module_class' => 'acp',	'module_langname' => 'ACP_GALLERY_CLEANUP',	'module_mode' => 'cleanup',	'module_auth' => '');
			add_module($cleanup);

			// UCP
			$ucp_gallery_overview = array('module_basename' => '',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $choosen_ucp_module,	'module_class' => 'ucp',	'module_langname'=> 'UCP_GALLERY',	'module_mode' => 'overview',	'module_auth' => '');
			add_module($ucp_gallery_overview);
			$ucp_module_id = $db->sql_nextid();
			set_gallery_config('ucp_parent_module', $ucp_module_id);

			$ucp_gallery = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $ucp_module_id,	'module_class' => 'ucp',	'module_langname' => 'UCP_GALLERY_SETTINGS',	'module_mode' => 'manage_settings',	'module_auth' => '');
			add_module($ucp_gallery);
			$ucp_gallery = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $ucp_module_id,	'module_class' => 'ucp',	'module_langname' => 'UCP_GALLERY_PERSONAL_ALBUMS',	'module_mode' => 'manage_albums',	'module_auth' => '');
			add_module($ucp_gallery);
			$ucp_gallery = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $ucp_module_id,	'module_class' => 'ucp',	'module_langname' => 'UCP_GALLERY_WATCH',	'module_mode' => 'manage_subscriptions',	'module_auth' => '');
			add_module($ucp_gallery);
			$ucp_gallery = array('module_basename' => 'gallery',	'module_enabled' => 1,	'module_display' => 1,	'parent_id' => $ucp_module_id,	'module_class' => 'ucp',	'module_langname' => 'UCP_GALLERY_FAVORITES',	'module_mode' => 'manage_favorites',	'module_auth' => '');
			add_module($ucp_gallery);

			// Add album-BBCode
			add_bbcode('album');
			$s_hidden_fields = '';
			$url = $this->p_master->module_url . "?mode=$mode&amp;sub=final";
		}
		else
		{
			$data = array(
				'acp_module'		=> 31,
				'ucp_module'		=> 0,
			);

			foreach ($this->gallery_config_options as $config_key => $vars)
			{
				if (!is_array($vars) && strpos($config_key, 'legend') === false)
				{
					continue;
				}

				if (strpos($config_key, 'legend') !== false)
				{
					$template->assign_block_vars('options', array(
						'S_LEGEND'		=> true,
						'LEGEND'		=> $user->lang[$vars])
					);

					continue;
				}

				$options = isset($vars['options']) ? $vars['options'] : '';
				$template->assign_block_vars('options', array(
					'KEY'			=> $config_key,
					'TITLE'			=> $user->lang[$vars['lang']],
					'S_EXPLAIN'		=> $vars['explain'],
					'S_LEGEND'		=> false,
					'TITLE_EXPLAIN'	=> ($vars['explain']) ? $user->lang[$vars['lang'] . '_EXPLAIN'] : '',
					'CONTENT'		=> $this->p_master->input_field($config_key, $vars['type'], $data[$config_key], $options),
					)
				);
			}
			$s_hidden_fields = '<input type="hidden" name="create" value="true" />';
			$url = $this->p_master->module_url . "?mode=$mode&amp;sub=advanced";
		}

		$submit = $user->lang['NEXT_STEP'];

		$template->assign_vars(array(
			'TITLE'		=> $user->lang['STAGE_ADVANCED'],
			'BODY'		=> $user->lang['STAGE_ADVANCED_EXPLAIN'],
			'L_SUBMIT'	=> $submit,
			'S_HIDDEN'	=> $s_hidden_fields,
			'U_ACTION'	=> $url,
		));
	}

	/**
	* The information below will be used to build the input fields presented to the user
	*/
	var $gallery_config_options = array(
		'legend1'				=> 'MODULES_PARENT_SELECT',
		'acp_module'			=> array('lang' => 'MODULES_SELECT_4ACP', 'type' => 'select', 'options' => 'module_select(\'acp\', 31, \'ACP_CAT_DOT_MODS\')', 'explain' => false),
		'ucp_module'			=> array('lang' => 'MODULES_SELECT_4UCP', 'type' => 'select', 'options' => 'module_select(\'ucp\', 0, \'\')', 'explain' => false),
	);
}

?>