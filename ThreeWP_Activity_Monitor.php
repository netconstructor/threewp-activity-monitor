<?php
/*                                                                                                                                                                                                                                                             
Plugin Name: ThreeWP Activity Monitor
Plugin URI: http://mindreantre.se/threewp-activity-monitor/
Description: Plugin to track user activity. Network aware.
Version: 2.3
Author: edward mindreantre
Author URI: http://www.mindreantre.se
Author Email: edward@mindreantre.se
License: GPLv3
*/

if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('You are not allowed to call this page directly.'); }

require_once('SD_Activity_Monitor_Base.php');
class ThreeWP_Activity_Monitor extends SD_Activity_Monitor_Base
{
	private $cache = array('user' => array(), 'blog' => array(), 'post' => array());
	
	private $activities = array();		// The list of activities that this plugin handles.
	
	protected $site_options = array(
		'activities_limit' => 100000,
		'activities_limit_view' => 100,
		'role_logins_view'	=>			'administrator',			// Role required to view own logins
		'role_logins_view_other' =>		'administrator',			// Role required to view other users' logins
		'role_logins_delete' =>			'administrator',			// Role required to delete own logins 
		'role_logins_delete_other' =>	'administrator',			// Role required to delete other users' logins
		'database_version' => 200,									// Version of database
		'logged_activities' => false,								// Which activities are logged
	);
	
	public function __construct()
	{
		parent::__construct(__FILE__);
		register_activation_hook(__FILE__, array(&$this, 'activate') );
		register_deactivation_hook(__FILE__, array(&$this, 'deactivate') );
		
		add_action( 'admin_print_styles', array(&$this, 'admin_print_styles') );
		add_action( 'admin_menu', array(&$this, 'admin_menu') );
		add_action( 'network_admin_menu', array(&$this, 'network_admin_menu') );
		
		add_action( 'threewp_activity_monitor_cron', array(&$this, 'cron') );

		add_filter( 'wp_login', array(&$this, 'wp_login'), 10, 3 );							// Successful logins
		add_filter( 'wp_login_failed', array(&$this, 'wp_login_failed'), 10, 3 );			// Login failures
		add_filter( 'wp_logout', array(&$this, 'wp_logout'), 10, 3 );						// Logouts
		
		add_filter( 'user_register', array(&$this, 'user_register'), 10, 3 );				// User creation
		add_filter( 'profile_update', array(&$this, 'profile_update'), 10, 3 );				// Profile updates
		add_filter( 'wpmu_delete_user', array(&$this, 'delete_user'), 10, 3 ); 				// User deletion
		add_filter( 'delete_user', array(&$this, 'delete_user'), 10, 3 );					// User deletion
		
		add_filter( 'retrieve_password', array(&$this, 'retrieve_password'), 10, 3 );		// Send password
		add_filter( 'password_reset', array(&$this, 'password_reset'), 10, 3 );				// Change password
		
		// Posts (and pages)
		add_action( 'transition_post_status', array(&$this, 'publish_post'), 10, 3 );
		add_action( 'post_updated', array(&$this, 'post_updated'), 10, 3 );
		add_action( 'trashed_post', array(&$this, 'trashed_post'));
		add_action( 'untrash_post', array(&$this, 'untrashed_post'));
		add_action( 'deleted_post', array(&$this, 'deleted_post'));
		
		// Comments
		add_action( 'wp_set_comment_status', array(&$this, 'wp_set_comment_status'), 10, 3 );
		
		add_action( 'threewp_activity_monitor_new_activity', array(&$this, 'log_new_activity'), 100000, 1 );
		add_filter( 'threewp_activity_monitor_delete_activity', array(&$this, 'delete_activity'), 100000, 2 );
		add_filter( 'threewp_activity_monitor_display_activity', array(&$this, 'display_activity'), 100000, 2 );
		add_filter( 'threewp_activity_monitor_find_activities', array(&$this, 'find_activities'), 10, 2 );
		add_filter( 'threewp_activity_monitor_list_activities', array(&$this, 'list_activities'), 10, 2 );
		add_filter( 'threewp_activity_monitor_list_activities', array(&$this, 'clean_activities_list'), 100000, 2 );		
		add_filter( 'threewp_activity_monitor_convert_activity_to_post', array(&$this, 'convert_activity_to_post'), 100000, 2 );		
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Callbacks
	// --------------------------------------------------------------------------------------------
	public function activate()
	{
		// If we are on a network site, make the site-admin the default role to access the functions.
		if ($this->is_network)
		{
			foreach(array('role_logins_view', 'role_logins_view_other', 'role_logins_delete', 'role_logins_delete_other') as $key)
				$this->options[$key] = 'super_admin';
		}
		
		parent::activate();
		
		// v0.3 has an activity_monitor table. Not necessary anymore.
		if ($this->sql_table_exists( $this->wpdb->base_prefix."activity_monitor" ) )		
			$this->query("DROP TABLE `".$this->wpdb->base_prefix."activity_monitor`");

		wp_schedule_event(time() + 600, 'daily', 'threewp_activity_monitor_cron');
		
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_activity_monitor_index` (
				  `i_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Index ID',
				  `activity_id` varchar(25) NOT NULL COMMENT 'What action was executed?',
				  `user_id` INT NULL COMMENT 'User''s ID',
				  `blog_id` INT NULL COMMENT 'Blog''s ID',
				  `i_datetime` datetime NOT NULL,
				  `data` text COMMENT 'Misc data associated with the query at hand',
			  PRIMARY KEY (`i_id`),
			  KEY (`activity_id`),
			  INDEX (`i_datetime`, `user_id`, `blog_id`)
			) ENGINE = MYISAM ;
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics` (
			  `user_id` int(11) NOT NULL COMMENT 'User ID',
			  `key` varchar(100) NOT NULL,
			  `value` text NOT NULL,
			  KEY `key` (`key`),
			  KEY `user_id` (`user_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=latin1;
		");
		
		if ($this->get_option('database_version') < 120)
		{
			// v1.2 serializes AND base64_encodes the data. So go through all the rows and encode what is necessary.
			$rows = $this->sql_index_list(array('limit' => 100000000));
			foreach($rows as $row)
			{
				$data = @unserialize($row['data']);
				if ( $data !== false)
					$this->query("UPDATE `".$this->wpdb->base_prefix."_3wp_activity_monitor_index` SET data = '". base64_encode(serialize($data))."' WHERE i_id = '".$row['i_id']."'");
			}
			$this->update_option('database_version', 120);
		}
		
		if ($this->get_option('database_version') < 200)
		{
			// v1.5 doesn't need the post and login tables.
			$this->query( "DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_index`" );
			$this->query( "DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins`" );
			$this->query( "DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts`" );
			
			// The tables don't start with an underscore anymore...
			// But a new table was created, therefore trash the new one and rename the old one.
			$this->query( "DROP TABLE `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics`" );
			$this->query("RENAME TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics` TO `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics` ");
			
			// We have an extra column in index.
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_activity_monitor_index` ADD `user_id` INT NULL COMMENT 'User''s ID' AFTER `activity_id`" );
			$this->query("ALTER TABLE `".$this->wpdb->base_prefix."3wp_activity_monitor_index` ADD INDEX ( `user_id`)");
			$this->update_option('database_version', 200);
		}
		
		// Select all activities per default
		$logged_activities = $this->get_site_option( 'logged_activities' );
		if ( $logged_activities === false )
		{
			$activities = apply_filters( 'threewp_activity_monitor_list_activities', array() );
			foreach( $activities as $activity )
				$logged_activities[ $activity['id'] ] = $activity['id'];
			$this->update_site_option( 'logged_activities', $logged_activities );
		}
	}
	
	public function deactivate()
	{
		parent::deactivate();
		wp_clear_scheduled_hook('threewp_activity_monitor_cron');
	}
	
	public function cron()
	{
		$this->sql_activities_crop(array(
			'limit' => $this->get_option('activities_limit'),
		));
	}

	public function uninstall()
	{
		parent::uninstall();
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_activity_monitor_index`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics`");
	}
	
	public function admin_menu()
	{
		$this->common_admin_menu();
	}
	
	public function network_admin_menu()
	{
		$this->common_admin_menu();
		add_submenu_page('settings.php', $this->_('Activity Monitor'), $this->_('Activity Monitor'), 'read', 'ThreeWP_Activity_Monitor', array (&$this, 'admin'));
	}
	
	public function common_admin_menu()
	{
		if ($this->role_at_least( $this->get_option('role_logins_view') ))
			add_filter( 'show_user_profile', array(&$this, 'show_user_profile'));
		if ($this->role_at_least( $this->get_option('role_logins_delete') ))
			add_filter( 'personal_options_update', array(&$this, 'personal_options_update'));

		if ($this->role_at_least( $this->get_option('role_logins_view_other') ))
			add_filter( 'edit_user_profile', array(&$this, 'show_user_profile'));
		if ($this->role_at_least( $this->get_option('role_logins_delete_other') ))
			add_filter( 'edit_user_profile_update', array(&$this, 'personal_options_update'));

		if ($this->role_at_least( $this->get_option('role_logins_view_other') ))
		{
			add_filter( 'manage_users_columns', array(&$this, 'manage_users_columns')); 
			add_filter( 'wpmu_users_columns', array(&$this, 'manage_users_columns')); 

			add_filter( 'manage_users_custom_column', array(&$this, 'manage_users_custom_column'), 10, 3 );
			
			add_submenu_page('index.php', $this->_('Activity Monitor'), $this->_('Activity Monitor'), 'read', 'ThreeWP_Activity_Monitor', array (&$this, 'admin'));
		}
	}

	public function admin()
	{
		$this->load_language();
		
		$tab_data = array(
			'tabs'		=>	array(),
			'functions' =>	array(),
		);
		
		$tab_data['tabs'][] = $this->_('Overview');
		$tab_data['functions'][] = 'adminOverview';

		if ($this->role_at_least( $this->get_option('role_logins_delete_other') ))
		{
			$tab_data['tabs'][] = $this->_('Settings');
			$tab_data['functions'][] = 'admin_settings';
	
			$tab_data['tabs'][] = $this->_('Activities');
			$tab_data['functions'][] = 'admin_activities';
	
			$tab_data['tabs'][] = $this->_('Uninstall');
			$tab_data['functions'][] = 'admin_uninstall';
		}
		
		$this->tabs($tab_data);
	}
	
	public function adminOverview()
	{
		$count = $this->sql_index_list(array(
			'count' => true,
		));
		
		$per_page = $this->get_option('activities_limit_view');
		$max_pages = floor($count / $per_page);
		$page = (isset($_GET['paged']) ? $_GET['paged'] : 1);
		$page = $this->minmax($page, 1, $max_pages);
		$activities = $this->sql_index_list( array(
			'limit' => $per_page,
			'page' => ($page-1),
		));
		
		$page_links = paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
			'current' => $page,
			'total' => $max_pages,
		));
		
		if ($page_links)
			$page_links = '<div style="width: 50%; float: right;" class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
		
		echo $page_links;
		echo $this->show_activities(array(
			'activities' => $activities,
		));		
		echo $page_links;
	}
	
	public function admin_settings()
	{
		// Collect all the roles.
		$roles = array();
		if ($this->is_network)
			$roles['super_admin'] = 'Site admin';
		foreach($this->roles as $role)
			$roles[ $role['name'] ] = ucfirst($role['name']);
			
		if (isset($_POST['3am_submit']))
		{
			$this->update_option( 'activities_limit', intval($_POST['activities_limit']) );
			$this->update_option( 'activities_limit_view', intval($_POST['activities_limit_view']) );
			
			$this->sql_activities_crop(array(
				'limit' => $this->get_option( 'activities_limit' )
			));

			foreach(array('role_logins_view', 'role_logins_view_other', 'role_logins_delete', 'role_logins_delete_other') as $key)
				$this->update_option($key, (isset($roles[$_POST[$key]]) ? $_POST[$key] : 'administrator'));

			$this->message('Options saved!');
		}
		
		$form = $this->form();
		$count = $this->sql_index_list(array(
			'count' => true,
		));
			
		$inputs = array(
			'activities_limit' => array(
				'type' => 'text',
				'name' => 'activities_limit',
				'label' => $this->_('Keep at most this amount of activities in the database'),
				'maxlength' => 10,
				'size' => 5,
				'value' => $this->get_option('activities_limit'),
				'validation' => array(
					'empty' => true,
				),
			),
			'activities_limit_view' => array(
				'type' => 'text',
				'name' => 'activities_limit_view',
				'label' => $this->_('Display this amount of activities per page'),
				'maxlength' => 10,
				'size' => 5,
				'value' => $this->get_option('activities_limit_view'),
				'validation' => array(
					'empty' => true,
				),
			),
			'role_logins_view' => array(
				'name' => 'role_logins_view',
				'type' => 'select',
				'label' => 'View own login statistics',
				'value' => $this->get_option('role_logins_view'),
				'options' => $this->roles_as_options(),
			),
			'role_logins_view_other' => array(
				'name' => 'role_logins_view_other',
				'type' => 'select',
				'label' => 'View other users\' login statistics',
				'value' => $this->get_option('role_logins_view_other'),
				'options' => $this->roles_as_options(),
			),
			'role_logins_delete' => array(
				'name' => 'role_logins_delete',
				'type' => 'select',
				'label' => 'Delete own login statistics',
				'value' => $this->get_option('role_logins_delete'),
				'options' => $this->roles_as_options(),
			),
			'role_logins_delete_other' => array(
				'name' => 'role_logins_delete_other',
				'type' => 'select',
				'label' => 'Delete other users\' login statistics and administer the plugin settings',
				'value' => $this->get_option('role_logins_delete_other'),
				'options' => $this->roles_as_options(),
			),
		);
		
		$inputSubmit = array(
			'type' => 'submit',
			'name' => '3am_submit',
			'value' => $this->_('Apply'),
			'css_class' => 'button-primary',
		);
			
		$returnValue = '
			'.$form->start().'
			
			<h3>Database cleanup</h3>
			
			<p>
				There are currently '.$count.' activities in the database.
			</p>
			
			' . $this->display_form_table( array( 'inputs' => array(
				$inputs['activities_limit'],
				$inputs['activities_limit_view'],
			) ) ) . '
			
			<h3>Roles</h3>
			
			<p>
				Actions can be restricted to specific user roles.
			</p>
			
			' . $this->display_form_table( array( 'inputs' => array(
				$inputs['role_logins_view'],
				$inputs['role_logins_view_other'],
				$inputs['role_logins_delete'],
				$inputs['role_logins_delete_other'],
				$inputSubmit,
			) ) ) . '
			
			'.$form->stop().'
		';

		echo $returnValue;
	}
	
	public function admin_activities()
	{
		$activities = apply_filters( 'threewp_activity_monitor_list_activities', array() );
		$logged_activities = $this->get_site_option( 'logged_activities' );

		if ( isset( $_POST['update'] ) )
		{
			if ( $_POST['mass_edit'] == '' )
			{
				$logged_activities = array();
				if ( !isset( $_POST['activities'] ) )
					$_POST['activities'] = array();
				foreach( $_POST['activities'] as $activity => $ignore )
					$logged_activities[ $activity ] = $activity;
				$this->update_site_option( 'logged_activities', $logged_activities );
				$this->message('Options saved!');
			}
			else
			{
				foreach( $activities as $activity => $data )
				{
					switch( $_POST['mass_edit'] )
					{
						case 'select_all':
							$logged_activities[ $activity ] = true;
							break;
						case 'select_none':
							unset( $logged_activities[ $activity ] );
							break;
						case 'sensitive_select_all':
							if ( $data['sensitive'] )
								$logged_activities[ $activity ] = true;
							break;
						case 'sensitive_select_none':
							if ( $data['sensitive'] )
								unset( $logged_activities[ $activity ] );
							break;
					}
				}
				$this->message('Activities have been marked/unmarked but not saved!');
			}
		}
		
		$form = $this->form();
		$tBody = '';
		
		foreach( $activities as $activity )
		{
			$activity_id = $activity['id']; 
			$input = array(
				'name' => $activity_id ,
				'type' => 'checkbox',
				'nameprefix' => '[activities]',
				'label' => $activity['id'],
				'checked' => isset( $logged_activities[ $activity_id  ] ),
			);
			
			// Assemble the info array.
			$info = array();
			
			if ( $activity['description'] != '' )
				$info[] = $activity['description'];

			if ( $activity['sensitive'] != '' )
				$info[] = '<span class="sensitive">' . $this->_('Logs sensitive information.') . '</span>';

			if ( $activity['can_be_converted_to_a_post'] != '' )
				$info[] = '<span class="converted_to_post">' . $this->_('Can be converted to a post.') . '</span>';

			$info = implode( '</div><div>', $info );
			
			$tBody .= '
				<tr>
					<td>'. $form->make_input( $input ) .' '. $form->make_label( $input ) .'</td>
					<td>'. $activity['name'] .'</td>
					<td>' . $activity['plugin'] . '</td>
					<td><div>' . $info . '</div></td>
				</tr>
			';
		}
		
		$input_mass_edit = array(
			'name' => 'mass_edit',
			'type' => 'select',
			'label' => 'Action',
			'options' => array(
				'' => $this->_('Save marked activities'),
				'select_all' => $this->_('Select all'),
				'select_none' => $this->_('Deselect all'),
				'sensitive_select_all' => $this->_('Also select all activities that log sensitive information'),
				'sensitive_select_none' => $this->_('Deselect all activities that log sensitive information'),
			),
		);
		
		$input_update = array(
			'name' => 'update',
			'type' => 'submit',
			'value' => $this->_('Apply'),
			'css_class' => 'button-primary',
		);
		
		$returnValue = '
			<p>The following marked activities are saved. All unmarked activities are discarded.</p>
			'.$form->start().'
			<table class="widefat">
				<thead>
					<th>ID</th>
					<th>Name</th>
					<th>Plugin</th>
					<th>Info</th>
				</thead>
				<tbody>
					' . $tBody . '
				</tbody>
			</table>
			<p>
				'. $form->make_label( $input_mass_edit ) .' '. $form->make_input( $input_mass_edit ) .'
			</p>
			<p>
				'. $form->make_input( $input_update ) .'
			</p>
			'.$form->stop().'
		';
		
		echo $returnValue;
	}

	public function admin_print_styles()
	{
		$load = false;
		if ( isset($_GET['page']) )
			$load |= strpos($_GET['page'],get_class()) !== false;

		foreach(array('profile.php', 'user-edit.php') as $string)
			$load |= strpos($_SERVER['SCRIPT_FILENAME'], $string) !== false;
		
		if (!$load)
			return;
		
		wp_enqueue_style('3wp_activity_monitor', '/' . $this->paths['path_from_base_directory'] . '/css/ThreeWP_Activity_Monitor.css', false, '1.0', 'screen' );
	}
	
	/**
		Logs the successful login of a user.
	*/
	public function wp_login( $username )
	{
		$user_data = get_userdatabylogin( $username );
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'wp_login',
			'activity_strings' => array(
				"" => $this->_( "%user_login_with_link% logged in to %blog_name_with_link%" ),
				$this->_( 'IP' ) => $this->make_ip('html2'),
				$this->_( 'User agent' ) => '%server_http_user_agent%',
			),
			'user_data' => $user_data,
		));
		
		$this->sql_stats_increment( $user_data->ID, 'login_success' );
		// Updated the latest login time.
		$this->sql_stats_set($user_data->ID, 'latest_login', $this->now());
	}
	
	/**
		Logs the unsuccessful login of a user.
	*/
	public function wp_login_failed( $username )
	{
		$user_data = get_userdatabylogin( $username );
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'wp_login_failed',
			'activity_strings' => array(
				'' => $this->_( "%user_login_with_link% tried to log in to %blog_name_with_link%" ),
				$this->_( 'Password tried' ) => esc_html( $_POST['pwd'] ),
				$this->_( 'IP' ) => $this->make_ip('html2'),
				$this->_( 'User agent' ) => '%server_http_user_agent%',
			),
			'user_data' => $user_data,
		));
		$this->sql_stats_increment( $user_data->ID, 'login_failure' );
	}
	
	/**
		Logs the logout of a user.
	*/
	public function wp_logout( $username )
	{
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'wp_logout',
			'activity_strings' => array(
				'' => $this->_( "%user_login_with_link% logged out from %blog_name_with_link%" ),
			),
		));
	}
	
	public function retrieve_password($username)
	{
		$userdata = get_userdatabylogin($username);
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'password_retrieve',
			'activity_strings' => array(
				'' => $this->_( "%user_login_with_link% wanted a new password from %blog_name_with_link%" ),
				$this->_( 'IP' ) => $this->make_ip('html2'),
				$this->_( 'User agent' ) => '%server_http_user_agent%',
			),
			'user_data' => $user_data,
		));
	}
	
	public function password_reset($user_data)
	{
		// Yes... this is the only action here that passes the whole user data, not just the name. *sigh*
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'password_reset',
			'activity_strings' => array(
				'' => $this->_( "%user_login_with_link% has reset the password on %blog_name_with_link%" ),
				$this->_( 'IP' ) => $this->make_ip('html2'),
				$this->_( 'User agent' ) => '%server_http_user_agent%',
			),
			'user_data' => $user_data,
		));
	}
	
	public function profile_update($user_id, $old_userdata)
	{
		$new_userdata = get_userdata($user_id);		
		
		$changes = array();
		
		if ($old_userdata->user_pass != $new_userdata->user_pass)
			$changes[ $this->_( 'Password' ) ] = $this->_( 'Password changed' );
		
		if ($old_userdata->first_name != $new_userdata->first_name)
			$changes[ $this->_( 'First name' ) ] =
				sprintf(
					$this->_( "From <em>%s</em> to <em>%s</em>" ),
					$old_userdata->first_name,
					$new_userdata->first_name
				);
		
		if ($old_userdata->last_name != $new_userdata->last_name)
			$changes[ $this->_( 'Last name' )] =
				sprintf(
					$this->_( "From <em>%s</em> to <em>%s</em>" ),
					$old_userdata->last_name,
					$new_userdata->last_name
				);
		
		if ( count($changes) < 1 )
			return;
		
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'profile_update',
			'activity_strings' => $changes,
		) );
	}
	
	public function delete_user($user_id)
	{
		$user_data = get_userdata($user_id);
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'delete_user',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% deleted' ) . sprintf( ' %s (%s)',
					$user_data->user_login,
					$user_data->user_email
				),
			),
		));
	}
	
	public function user_register($user_id)
	{
		$user_data = get_userdata($user_id);
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'user_register',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% created' ) . sprintf( ' %s (%s)',
					$user_data->user_login,
					$user_data->user_email
				),
			),
		));
	}
	
	public function publish_post($new_status, $old_status, $post)
	{
		if ($old_status == 'trash')
			return;
		if ($old_status == 'publish')
			return;
		
		if ( !$this->post_is_for_real($post) )
			return;
			
		global $threewp_broadcast;
		if ( $threewp_broadcast !== null )
			if ( $threewp_broadcast->is_broadcasting() )
				return;

		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'post_publish',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% wrote %post_title_with_link% on %blog_name_with_link%' ),
			),
			'post_data' => $post,
		));
	}

	public function post_updated($post_id, $new_post, $old_post)
	{
		if ( !$this->post_is_for_real($old_post) )
			return;
		if ( !$this->post_is_for_real($new_post) )
			return;
			
		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'post_updated',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% updated %post_title_with_link% on %blog_name_with_link%' ),
			),
			'post_data' => $new_post,
		));
	}
	
	public function trashed_post($post_id)
	{
		$post_data = get_post($post_id);

		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'trashed_post',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% trashed %post_title_with_link% on %blog_name_with_link%' ),
			),
			'post_data' => $post_data,
		));
	}
	
	public function untrashed_post($post_id)
	{
		$post_data = get_post($post_id);

		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'untrashed_post',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% untrashed %post_title_with_link% on %blog_name_with_link%' ),
			),
			'post_data' => $post_data,
		));
	}
	
	public function deleted_post($post_id)
	{
		$post_data = get_post($post_id);
		
		if ( !$this->post_is_for_real($post_data) && $post_data->post_status != 'trash')
			return;

		do_action('threewp_activity_monitor_new_activity', array(
			'activity_id' => 'untrashed_post',
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% deleted %post_title_with_link% on %blog_name_with_link%' ),
			),
			'post_data' => $post_data,
		));
	}
	
	public function wp_set_comment_status($comment_id, $status)
	{
		$comment_data = get_comment($comment_id);
		$post_id = $comment_data->comment_post_ID;
		$post_data = get_post($post_id);
		
		switch($status)
		{
			case '0':
				$verb = 'reset';
				break;
			case '1':
				$verb = 'reapproved';
				break;
			case 'hold':
				$verb = 'held back';
				break;
			case 'spam':
				$verb = 'spammed';
				break;
			case 'trash':
				$verb = 'trashed';
				break;
			case 'delete':
				$verb = 'deleted';
				break;
			default:
				$verb = 'approve';
				break;
		}
		
		do_action( 'threewp_activity_monitor_new_activity', array(
			'activity_id' => 'comment_' . $status,
			'activity_strings' => array(
				'' => $this->_( '%user_login_with_link% ' . $verb. ' comment %comment_id_with_link% on %post_title_with_link%' ),
			),
			'post_data' => $post_data,
			'comment_data' => $comment_data,
		) );
	}
	
	public function show_user_profile($userdata)
	{
		$default_login_stats = array(
			'latest_login' => array( 'value' => '' ),
			'login_success' => array( 'value' => '' ),
			'login_failure' => array( 'value' => '' ),
			'password_retrieve' => array( 'value' => '' ),
			'password_reset' => array( 'value' => '' ),
		);
		$returnValue = '<h3>'.$this->_('User activity').'</h3>';
		
		$login_stats = $this->sql_stats_list($userdata->ID);
		$login_stats = $this->array_moveKey($login_stats, 'key');
		$login_stats = array_merge($default_login_stats, $login_stats);
		$returnValue .= '
			<table class="widefat">
				<thead>
					<tr>
						<th>'.$this->_('Latest login').'</th>
						<th>'.$this->_('Successful logins').'</th>
						<th>'.$this->_('Failed logins').'</th>
						<th>'.$this->_('Retrieved passwords').'</th>
						<th>'.$this->_('Reset passwords').'</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><span title="'.$login_stats['latest_login']['value'].'">'.$this->ago($login_stats['latest_login']['value']).'</span></td>
						<td>'.intval($login_stats['login_success']['value']).'</td>
						<td>'.intval($login_stats['login_failure']['value']).'</td>
						<td>'.intval($login_stats['password_retrieve']['value']).'</td>
						<td>'.intval($login_stats['password_reset']['value']).'</td>
					</tr>
				</tbody>
			</table>
			<br />
		';
		
		$logins = $this->sql_index_list(array(
			'user_id' => $userdata->ID
		));
		$returnValue .= $this->show_activities(array(
			'activities' => $logins,
			'show_mass_edit' => false,
		));

		if ($this->role_at_least( $this->get_option('role_logins_delete') ))
		{
			$form = $this->form();
			
			// Make crop option
			$inputCrop = array(
				'type' => 'text',
				'name' => 'activity_monitor_activities_crop',
				'label' => $this->_('Crop the activity list down to this amount of rows'),
				'value' => count($logins),
				'validation' => array(
					'empty' => true,
				),
			);
			$returnValue .= '<p>'.$form->make_label($inputCrop).' '.$form->make_input($inputCrop).'</p>';

			// Make clear option
			$inputClear = array(
				'type' => 'checkbox',
				'name' => 'activity_monitor_activities_delete',
				'label' => $this->_('Clear the user\'s activity list completely'),
				'checked' => false,
			);
			$returnValue .= '<p>'.$form->make_input($inputClear).' '.$form->make_label($inputClear).'</p>';
		}
		echo $returnValue;
	}
	
	public function personal_options_update($user_id)
	{
		if ( intval($_POST['activity_monitor_activities_crop']) > 0)
		{
			$crop_to = $_POST['activity_monitor_activities_crop'];
			$this->sql_activities_crop(array(
				'limit' => $crop_to,
				'user_id' => $user_id,
			));
		}
		if ( isset($_POST['activity_monitor_activities_delete']) )
		{
			$crop_to = 0;
			$this->sql_activities_crop(array(
				'limit' => $crop_to,
				'user_id' => $user_id,
			));
		}
	}

	public function manage_users_columns($defaults)
	{
		$defaults['3wp_activity_monitor'] = '<span title="'.$this->_('Various login statistics about the user').'">'.$this->_('Login statistics').'</span>';
		return $defaults;
	}
	
	public function manage_users_custom_column($p1, $p2, $p3 = '')
	{
		// echo is the variable that tells us whether we need to echo our returnValue. That's because wpmu... needs stuff to be echoed while normal wp wants stuff returned.
		// *sigh*
		
		if ( $p2 != '3wp_activity_monitor' )
			return $p1;
		
		if ($p3 == '')
		{
			$column_name = $p1;
			$user_id = $p2;
			$echo = true;
		}
		else
		{
			$column_name = $p2;
			$user_id = $p3;
			$echo = false;
		}
		
		$returnValue = '';
		
		$login_stats = $this->sql_stats_list($user_id);
		$login_stats = $this->array_moveKey($login_stats, 'key');

		if (count($login_stats) < 1)
		{
			$message = $this->_('No login data available');
			if ($echo)
				echo $message;
			return $message;
		}
			
		$stats = array();
		
		// Translate the latest login date/time to the user's locale.
		if ($login_stats['latest_login'] != '')
			$stats[] = sprintf('<span title="%s: '.$login_stats['latest_login']['value'].'">' . $this->ago($login_stats['latest_login']['value']) . '</span>',
			$this->_('Latest login')
			);
		
		$returnValue .= implode(' | ', $stats);
		
		if ($echo)
			echo $returnValue;
		return $returnValue;
	}
	
	/**
		Log a new activity.
		
		The whole options array is saved.
		
		$options = array(
			activity_id => String that acts as an index/name for the activity type. 'publish_post' or 'user_register'
			activity_strings => array of Heading=>Text that is displayed.
			[user_data] => optional user object to use.
			[post_data] => optional post object to use.
			[comment_data] => optional comment object to use.
		)
		
		Setting post_data or comment_data will save the post ID or comment ID respectively.
		
		The activity_strings are parsed with specific keywords being replaced automatically, for your convenience.
		
		After the whole $options is saved you can use the threewp_activity_monitor_display_activity to display your custom activity. 
		
		@param		array		$options		Flexible options array. :)
	**/
	public function log_new_activity( $options )
	{
		$options = array_merge(array(
			'activity_id' => 'log_new_activity',
			'activity_strings' => array(),
			'user_data' => null,
			'post_data' => null,
			'comment_data' => null,
		), $options );
		
		// Do we log this activity at all?
		$logged_activities = $this->get_site_option( 'logged_activities' );
		if ( !isset( $logged_activities[ $options['activity_id'] ] ) )
			return;
		
		// Have we been supplied with user data to use?
		if ( $options['user_data'] !== null )
			$current_user = $options['user_data'];
		else
		{
			global $current_user;
			get_currentuserinfo();
		}
		
		$user_id = $current_user->ID;				// Convenience

		global $blog_id;
		$bloginfo_name = get_bloginfo('name');		// Convenience
		$bloginfo_url = get_bloginfo('url');		// Convenience
		$options['blog_id'] = $blog_id;

		$replacements = array(
			'%user_id%' => $user_id,
			'%blog_id%' => $blog_id,
			'%blog_name%' => $bloginfo_name,
			'%blog_link%' => $bloginfo_url,
			'%blog_panel_link%' => $bloginfo_url . '/wp-admin',
			'%blog_name_with_link%' => sprintf('<a href="%s">%s</a>', $bloginfo_url, $bloginfo_name),
			'%blog_name_with_panel_link%' => sprintf('<a href="%s">%s</a>', $bloginfo_url . '/wp-admin', $bloginfo_name),
			'%server_http_user_agent%' => esc_html( $_SERVER['HTTP_USER_AGENT'] ),
			'%server_http_remote_host%' => $_SERVER['REMOTE_HOST'],
			'%server_http_remote_addr%' => $_SERVER['REMOTE_ADDR'],
		);
		
		$replacements[ '%user_login%' ] = $current_user->user_login;
		$replacements[ '%user_login_with_link%' ] = $this->make_profile_link($user_id);
		$replacements[ '%user_display_name%' ] = $current_user->display_name;
		$replacements[ '%user_display_name_with_link%' ] = $this->make_profile_link($user_id, $current_user->display_name);
		
		if ( $options['post_data'] !== null )
		{
			$post_data = $options['post_data'];
			$post_link = $bloginfo_url . '?p=' . $post_data->ID;
			$replacements['%post_title%'] = $post_data->post_title;
			$replacements['%post_link%'] = $post_data->post_title;
			$replacements['%post_title_with_link%'] = sprintf( '<a href="%s">%s</a>',
				$post_link,
				$post_data->post_title
			);
			$options['post_id'] = $post_data->ID;
		}

		if ( $options['comment_data'] !== null )
		{
			$comment_data = $options['comment_data'];
			$comment_link = $post_link . '#comment-' . $comment_data->comment_ID;
			$replacements['%comment_id%'] = $comment_data->comment_ID;
			$replacements['%comment_link%'] = $comment_link;
			$replacements['%comment_id_with_link%'] = sprintf( '<a href="%s">%s</a>',
				$comment_link,
				$comment_data->comment_ID
			);
			$options['comment_id'] = $comment_data->comment_ID;
		}

		// Replace the keywords in the activity.
		foreach($options['activity_strings'] as $index => $text)
		{
			foreach($replacements as $replace_this => $with_this)
			{
				$index = str_replace($replace_this, $with_this, $index);
				$text = str_replace($replace_this, $with_this, $text);
			}
			$options['activity_strings'][$index] = $text;
		}
		
		// Clear the objects that can't / shouldn't be saved
		unset( $options['user_data'] );
		unset( $options['post_data'] );
		unset( $options['comment_data'] );
		
		// And now save the whole options object.
		$this->sql_log_index( $options['activity_id'], array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'data' => $options,
		) );
	}
	
	/**
		Fill in the $activities parameter with a list of all of ours.
		
		$activities = array(
			activity_key => array(
				name => Human-readable name of activity.
				plugin => Name of the plugin that creates this activity.
				[description] => Description [optional]
				[sensitive] => True if the activity logs sensitive data (passwords tried).
				[can_be_converted_to_post] => True if the activity can be converted to a post object (for RSSing).
			)
		)		
		
		@param		array		$activities		All activities that we append to.
		@return		array						All + out activities.
	**/
	public function list_activities($activities)
	{
		$this->load_language();
		
		// First, fill in our own activities.
		$this->activities = array(
			'comment_0' => array(
				'name' => $this->_('Comment was reset.'),
			),
			'comment_1' => array(
				'name' => $this->_('Comment was reapproved.'),
			),
			'comment_hold' => array(
				'name' => $this->_('Comment was held back.'),
			),
			'comment_spam' => array(
				'name' => $this->_('Comment was spammed.'),
			),
			'comment_trash' => array(
				'name' => $this->_('Comment was trashed.'),
			),
			'comment_delete' => array(
				'name' => $this->_('Comment was deleted.'),
			),
			'comment_approve' => array(
				'name' => $this->_('Comment was approved.'),
			),
			'wp_login' => array(
				'name' => $this->_('User login'),
			),
			'wp_login_failed' => array(
				'name' => $this->_('User login failed'),
				'description' => $this->_('Logs the password the user tried to login with.'),
				'sensitive' => true,
			),
			'wp_logout' => array(
				'name' => $this->_('User logout'),
			),
			'user_regisiter' => array(
				'name' => $this->_('User registration'),
			),
			'profile_update' => array(
				'name' => $this->_('User profile updated'),
			),
			'wpmu_delete_user' => array(
				'name' => $this->_('User deleted (network)'),
			),
			'delete_user' => array(
				'name' => $this->_('User deleted (single)'),
			),
			'retrieve_password' => array(
				'name' => $this->_('New password was requested'),
			),
			'password_reset' => array(
				'name' => $this->_('Password was reset'),
			),
			'post_publish' => array(
				'name' => $this->_('Post published'),
				'description' => $this->_('A new page or post has been published.'),
				'plugin' => 'ThreeWP Activity Monitor',
				'can_be_converted_to_a_post' => true,
			),
			'post_updated' => array(
				'name' => $this->_('Post was updated'),
			),
			'trashed_post' => array(
				'name' => $this->_('Post was trashed'),
			),
			'untrashed_post' => array(
				'name' => $this->_('Post was untrashed'),
			),
			'deleted_post' => array(
				'name' => $this->_('Post was deleted'),
			),
		);
		
		// Insert our module name in all the values.
		foreach( $this->activities as $index => $activity )
		{
			$activity['plugin'] = 'ThreeWP Activity Monitor';
			$activities[ $index ] = $activity;
		} 
		
		return $activities;
	}
	
	/**
		Inserts default values and sorts the listed activities.
		
		Is called after all the other modules have inserted their activities.
		
		@param		array		$activities		List of activities from other plugins.
		@return		array						Nicely sorted list of activities.
	**/
	public function clean_activities_list($activities)
	{
		// And now clean up everyone else's activities by inserting default values if necessary.
		$default_activity_settings = array(
			'name' => 'Human readable name of activity',	// Activity will be pruned from the list if this is not filled in.
			'plugin' => 'Unknown',							// Name of plugin producing this activity. Optional, but ... really.
			'sensitive' => false,							// Is there a risk that this activity logs sensitive information (passwords tried)? Optional.
			'description' => '',							// Exact html / text string that describes the activity. Optional.
			'can_be_converted_to_a_post' => false,			// This activity can be converted to a post, for use in activity monitor RSS. Optional.
		);
		$returnValue = array();
		foreach($activities as $index => $activity)
		{
			if ( is_int( $index ) )
				continue;
			if ( !isset( $activity['name'] ) || trim($activity['name']) == '' )
				continue;
			$new_index = ( !is_int($index) ? $index : $activity['id'] );
			$activity['id'] = $index;
			$returnValue[ $index ] = array_merge($default_activity_settings, $activity);
		}
		
		// Sort them
		ksort( $returnValue );
		
		return $returnValue;
	}
	
	/**
		Converts an activity to a post, suitable for RSSing.
		
		@param		array		$activity		An activity, fetched from the database.
		@param		object		$returnValue	An incomplete post.
		@return		object						A complete post with changed values and what not.	
	**/
	public function convert_activity_to_post($activity)
	{
		$data = unserialize( base64_decode( $activity['data'] ) );
		switch($activity['activity_id'])
		{
			case 'post_publish':
				switch_to_blog( $activity['blog_id'] );
				$post_id = $data['post_id'];
				$returnValue = get_post( $post_id  );
				
				// Pages generate incorrect permalinks if they're generated on other blogs. Pretend it's a post. That'll work.
				if ($returnValue->post_type == 'page')
				{
					$returnValue->post_type = 'post';
					$returnValue->guid .= '?p=' . $returnValue->ID;
				}
				
				restore_current_blog();
			break;
		}
		return $returnValue;
	}
	
	/**
		Inserts display info into the activity.
		
		The following options are available for display:
		
		Optional: a raw string.
		$activity['display_string'] => 'Is a completely unfiltered string.';

		Optional: strings with headings.
		$activity['display_strings'] = array(
			'' => 'No heading, text string.',
			' ' => 'Another non-heading, displaying just this string.',
			'User ID' => 'The heading user id, and then this string',
			'   ' => 'More (trimmed) spaces means another non-heading',
		);
		
		If you want special classes appended to the activity's <tr>, append values to the display_tr_classes array.
		$activity['display_tr_classes'] = array('class1', 'class2');
		
		@param		array		$activity		Activity to be filled in.
		@return		array						Activity with a combination of display_string, display_strings and display_tr_classes keys set.
	**/
	public function display_activity( $activity )
	{
		switch( $activity['activity_id'] )
		{
			case 'custom_act_22':
				$activity['display_string'] = 'This string is outputted without much ado. No filtering or anything.';
				break;
			case 'user_exploded':
				$activity['display_strings'] = array_merge( array(
					'' => 'This is the first line, without a heading',
					'Good heading' => 'Is a the second line, with a very good heading',
				), $activity['data']['activity_strings'] );
				$activity['display_string'] = 'This string is outputted without much ado. No filtering or anything.';
				
				$activity['display_strings']['Cows'] = 'The user has ' . $activity['data']['cow_count'] . ' cows available for purchase.';
				break;
				
		}
		return $activity;
	}
	
	/**
		Deletes one or more activities.
	**/
	public function delete_activity( $activity )
	{
		if ( ! is_array( $activity ) )
			$activity = array( $activity );
		
		$this->sql_activities_delete( array(
			'i_id' => $activity,
		) );
	}
	
	/**
		Find some activities.
		
		$options is an array of
			bool	count		Return a count of the activities? Default no.
			int		limit		How many activities to return. Default 1000.
			int		page		Which page of activities to return? Default 0.
			string	select		Columns to return. Default *
			int		blog_id		Return activities on this blog_id.
			int		user_id		Return activities with this user_id
			array	where		Where conditions.
		
		There where-conditions are plain SQL strings. For example:
		
		find_activities( array(
			where => array( "activity_id = 'wp_login'" ),
		);
		
		@param		array		$options		Find options.
	**/
	
	public function find_activities( $options )
	{
		return $this->sql_index_list( $options );
	}

	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Misc functions
	// --------------------------------------------------------------------------------------------
	private function cache_blog($target_blog_id)
	{
		global $blog_id;

		if ( isset($this->cache['blog'][$target_blog_id]) )
			return;
		
		if ($target_blog_id != $blog_id)
			switch_to_blog($target_blog_id);
		$this->cache['blog'][$target_blog_id] = array(
			'title' => get_bloginfo('title'),
			'url' => get_bloginfo('url'),
		);
		if ($target_blog_id != $blog_id)
			restore_current_blog(); 
	}
	
	private function cache_user($user_id)
	{
		if ( isset($this->cache['user'][$user_id]) )
			return;
		
		$this->cache['user'][$user_id] = get_userdata($user_id);

		if ( ! $this->cache['user'][$user_id] instanceof stdClass )
		{
			$this->cache['user'][$user_id] = new stdClass();
			$this->cache['user'][$user_id]->user_login = 'Wordpress';
		}
	}
	
	private function make_ip($type = 'text1')
	{
		switch($type)
		{
			case 'text1':
				if ($_SERVER['REMOTE_HOST'] != '')
					return $_SERVER['REMOTE_HOST'] . ' ('.$_SERVER['REMOTE_ADDR'].')';
				else
					return $_SERVER['REMOTE_ADDR'];
			break;
			case 'text2':
				if ($_SERVER['REMOTE_HOST'] != '')
					return $_SERVER['REMOTE_HOST'] . ' / '.$_SERVER['REMOTE_ADDR'];
				else
					return $_SERVER['REMOTE_ADDR'];
			break;
			case 'html1':
				if ($_SERVER['REMOTE_HOST'] != '')
					return '<span title="'.$_SERVER['REMOTE_ADDR'].'">' . $_SERVER['REMOTE_HOST'] . '</span>';
				else
					return $_SERVER['REMOTE_ADDR'];
			break;
			case 'html2':
				if ($_SERVER['REMOTE_HOST'] != '')
					return $_SERVER['REMOTE_HOST'] . ' <span class="threewp_activity_monitor_sep">|</span> '.$_SERVER['REMOTE_ADDR'];
				else
					return $_SERVER['REMOTE_ADDR'];
			break;
		}
	}
	
	private function show_activities($options)
	{
		$form = $this->form();
		$options = array_merge( array(
			'show_mass_edit' => $this->role_at_least( $this->get_option('role_logins_delete_other') ),
		), $options );
		
		$ago = true;			// Display the login time as "ago" or datetime?
		$tBody = '';
		
		$mass = '';
		$th_cb = '';
		
		$may_delete = $this->role_at_least( $this->get_option('role_logins_delete_other') ) && ( $options['show_mass_edit'] === true );
		
		$form_start = $may_delete ? $form->start() : '';
		$form_stop = $may_delete ? $form->stop() : '';
		
		if ( $may_delete )
		{
			if ( isset($_POST['mass_submit']) )
			{
				$selected = array();
	
				if ( isset( $_POST['cb'] ) )
				{
					$selected = array_keys( $_POST['cb'] );
				}
	
				if ( count( $selected ) > 0 )
				{
					switch( $_POST['mass'] )
					{
						case 'delete':
							apply_filters( 'threewp_activity_monitor_delete_activity', $selected );
							$this->message( sprintf(
								$this->_( 'The selected activities have been deleted! %sReload this page%s.' ),
								'<a href="' . remove_query_arg('test') . '">',
								'</a>' ) );
							break;
					}
				}
			}

			$mass_input = array(
				'type' => 'select',
				'name' => 'mass',
				'label' => $this->_( 'With the selected actions do:' ),
				'options' => array(
					'' => $this->_('Nothing'),
					'delete' => $this->_('Delete'),
				),
			);
			
			$mass_input_submit = array(
				'type' => 'submit',
				'name' => 'mass_submit',
				'value' => $this->_( 'Apply' ),
				'css_class' => 'button-primary',
			);
			
			$mass = '
				<div style="float: left; width: 50%;">
					' . $form->make_label( $mass_input) . '
					' . $form->make_input( $mass_input) . '
					' . $form->make_input( $mass_input_submit) . '
				</div>
			';
			
			$selected = array(
				'type' => 'checkbox',
				'name' => 'check',
			);

			$th_cb = '
						<th class="check-column">
							' . $form->make_input( $selected ) . '<span class="screen-reader-text">' . $this->_('Selected') . '</span>
						</th>
			';
		}
		
		foreach( $options['activities'] as $activity )
		{
			$activity_strings = array();		// What is displayed.
			$tr_class = array('activity_monitor_action action_' . $activity['activity_id']);
			
			$cb = '';
			
			if ( $may_delete )
			{
				$activity_id = $activity['i_id'];
				$cb_input = array(
					'name' => $activity_id ,
					'type' => 'checkbox',
					'nameprefix' => '[cb]',
					'label' => $activity_id,
					'checked' => isset( $_POST['cb'][ $activity_id ]  ),
				);
				
				$cb = '
					<th scope="row" class="check-column">
						' . $form->make_input( $cb_input ) . '
						<span class="screen-reader-text">' . $form->make_label( $cb_input ) . '</span>
					</th>
				';
			}

			// Unserialize the data.
			$data = unserialize( base64_decode($activity['data']) );
			$activity['serialized_data'] = $activity['data'];
			$activity['data'] = $data;
			$activity['display_strings'] = array();
			$activity['display_tr_classes'] = array();
			$activity['display_string'] = '';
			
			$activity = apply_filters( 'threewp_activity_monitor_display_activity', $activity );
			
			// Was this activity handled? If no, then assume that data can go straight to the activity strings.
			if ( (count( $activity['display_strings'] ) < 1)
				&&  $activity['display_string'] == '' )
				$activity['display_strings'] = $activity['data']['activity_strings'];
			
			// Add new tr classes?
			if ( count($activity['display_tr_classes']) > 0 )
				$tr_class = array_merge( $tr_class, $activity['display_tr_classes'] );
			
			// Display the display_strings array.
			if ( count( $activity['display_strings'] ) > 0 )
				foreach ($activity['display_strings'] as $data_key => $data_value)
					$activity_strings[] = sprintf('<span class="threewp_activity_monitor_activity_info_key">%s</span> <span class="activity_info_data">%s</span>', trim($data_key), $data_value);
			
			// And display the display_string string.
			if ( $activity['display_string'] != '' )
				// This activity has a hardcoded string that is displayed straight off the bat.
				$activity_strings[] = $activity['display_string'];
			
			if ($ago)
			{
				$loginTime = strtotime( $activity['i_datetime'] );
				if (time() - $loginTime > 60*60*24)		// Older than 24hrs and we can display the datetime normally.
					$ago = false;
				$time = '<span title="'.$activity['i_datetime'].'">'. $this->ago($activity['i_datetime']) .'</span>';
			}
			else
				$time = $activity['i_datetime'];

			$tBody .= '
				<tr class="'.implode(' ', $tr_class).'">
					'.$cb.'
					<td class="activity_monitor_action_time">'.$time.'</td>
					<td class="activity_monitor_action"><div>'.implode('</div><div>', $activity_strings).'</div></td>
				</tr>
			';
		}
				
		return '
			' . $form_start . '

			' . $mass . '

			<table class="widefat threewp_activity_monitor">
				<thead>
					<tr>
						' . $th_cb . '
						<th>'.$this->_('Time').'</th>
						<th>'.$this->_('Activity').'</th>
					</tr>
				</thead>
				<tbody>
					'.$tBody.'
				</tbody>
			</table>

			' . $form_stop . '
		';
	}
	
	private function post_is_for_real($post)
	{
		// Posts must be published and the parent must be 0 (meaning no autosaves)
		// Also: posts must actually be posts, not pages or menus or anything.
		if ( !is_object($post) )
			return false;
		return $post->post_status == 'publish' && $post->post_parent == 0 && $post->post_type == 'post';
	}
	
	private function make_profile_link($user_id, $text = "")
	{
		$this->cache_user($user_id);
		if ($text == "")
			$text = $this->cache['user'][$user_id]->user_login;
		
		return '<a href="user-edit.php?user_id='.$user_id.'">'. $text .'</a>';
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- SQL
	// --------------------------------------------------------------------------------------------
	private function sql_stats_increment($user_id, $action)
	{
		$this->sql_stats_set($user_id, $action, intval($this->sql_stats_get($user_id, $action)) + 1);
	}
	
	private function sql_stats_get($user_id, $key)
	{
		$result = $this->query("SELECT value FROM `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics` WHERE `user_id` = '".$user_id."' AND `key` = '".$key."'");
		if (count($result) < 1)
			return null;
		else
			return $result[0]['value'];
	}
	
	private function sql_stats_set($user_id, $key, $value)
	{
		if ($this->sql_stats_get($user_id, $key) === null)
		{
			$this->query("INSERT INTO `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics` (`user_id`, `key`, `value`) VALUES
				('".$user_id."', '".$key."', '".$value."')");
		}
		else
		{
			$this->query("UPDATE `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics`
				SET `value` = '".$value."'
				WHERE `user_id` = '".$user_id."'
				AND `key` = '".$key."'
			");
		}
	}
	
	private function sql_stats_list($user_id)
	{
		return $this->query("SELECT `key`, `value` FROM `".$this->wpdb->base_prefix."3wp_activity_monitor_user_statistics` WHERE `user_id` = '".$user_id."'");
	}
	
	private function sql_log_index($action, $options)
	{
		$options = array_merge(array(
			'user_id' => null,
			'blog_id' => null,
			'data' => array(),
		), $options);
		
		$data = $options['data'];
		// Remove unused keys from the data array
		foreach($data as $key => $value)
			if ($value === null)
				unset( $data[$key] );
		if ( count($data) < 1 )
			$data = null;
		else
			$data = base64_encode( serialize( $data) );
		$options['data'] = $data;
		
		foreach(array('user_id', 'data', 'blog_id') as $key)
			$options[$key] = ($options[$key] === null ? 'null' : "'" . $options[$key] . "'");
		
		$query = "INSERT INTO `".$this->wpdb->base_prefix."3wp_activity_monitor_index` (activity_id, i_datetime, user_id, blog_id, data) VALUES
		 	('".$action."', '".$this->now()."', ".$options['user_id'].", ".$options['blog_id'].",  ".$options['data'].")
		 ";
		
		$this->query( $query );
	}
	
	public function sql_index_list($options)
	{
		$options = array_merge(array(
			'limit' => 1000,
			'count' => false,
			'page' => 0,
			'select' => '*',
			'user_id' => null,
			'blog_id' => null,
			'where' => array('1=1'),
		), $options);

		$select = ($options['count'] ? 'count(*) as ROWS' : $options['select']);
		
		if ($options['page'] > 0)
			$options['page'] = $options['page'] * $options['limit'];
		
		if ( $options['user_id'] !== null )
			$options['where']['user_id'] = "user_id = '".$options['user_id']."'";

		if ( $options['blog_id'] !== null )
			$options['where']['blog_id'] = "blog_id = '".$options['blog_id']."'";

		$query = ("SELECT ".$select." FROM `".$this->wpdb->base_prefix."3wp_activity_monitor_index`
			WHERE " . implode(' AND ', $options['where']) . "
			ORDER BY i_datetime DESC
			".(isset($options['limit']) ? "LIMIT ".$options['page'].",".$options['limit']."" : '')."
		 ");

		 $result = $this->query($query);
		 
		 if ($options['count'])
		 	$result = $result[0]['ROWS'];
		 
		 return $result;
	}
	
	private function sql_activities_crop($options)
	{
		$options = array_merge(array(
			'user_id' => null,
		), $options);
		
		$rows = $this->sql_index_list(array(
			'user_id' => $options['user_id'],
			'limit' => ($options['user_id'] !== null ? null : $options['limit']),
			'select' => 'i_id',
		));
		
		if ($options['user_id'] !== null)
		{
			for($counter=0; $counter < $options['limit']; $counter++)
				array_shift($rows);

			$rows_to_delete = array(
				'i_id' => array(),
			);
			foreach($rows as $row)
				foreach($rows_to_delete as $key => $ignore)
					if ($row[$key] != '')
						$rows_to_delete[$key][] = $row[$key];
						
			$query = "DELETE FROM `".$this->wpdb->base_prefix."3wp_activity_monitor_index` WHERE i_id IN ('".(implode("', '", $rows_to_delete['i_id']))."')";
			$this->query($query);
			return;
		}
		
		$rows = $this->array_moveKey($rows, 'i_id');
		
		$rows_to_keep = array(
			'i_id' => array(),
		);
		foreach($rows as $row)
			foreach($rows_to_keep as $key => $ignore)
				if ($row[$key] != '')
					$rows_to_keep[$key][] = $row[$key];
		
		$query = "DELETE FROM `".$this->wpdb->base_prefix."3wp_activity_monitor_index` WHERE i_id NOT IN ('".(implode("', '", $rows_to_keep['i_id']))."')";
		$this->query($query);
	}
	
	/**
		Deletes one or more activities.
		
		$options is an array of
			i_id => array of i_id to delete
		
		@param		array		$options		Deletion options.
	**/
	private function sql_activities_delete($options)
	{
		$options = array_merge( array(
		), $options );
		
		$query = "DELETE FROM `".$this->wpdb->base_prefix."3wp_activity_monitor_index` WHERE i_id IN ('".(implode("', '", $options['i_id']))."')";
		$this->query($query);
	}

}
$threewp_activity_monitor = new ThreeWP_Activity_Monitor();
?>