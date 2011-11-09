=== ThreeWP Activity Monitor ===
Tags: wp, wpms, network, threewp, activity, monitor, activity monitor, blog activity, user, comments, logins,
Requires at least: 3.2
Tested up to: 3.2
Stable tag: trunk
Contributors: edward mindreantre
Track and display site or network-wide user activity.

== Description ==

Displays a multitude of user actions to keep the site administrator informed that all is well and that the blog or network is not being abused. Displays:

* Logins (successful and failed)
* Retrieved and reset passwords
* Posts/pages created, updated, trashed, untrashed and deleted
* Comments approved, trashed, spammed, unspammed, trashed, untrashed and deleted
* Changed passwords
* Changed user info
* User registrations
* User deletions
* Custom activities from other plugins

Keeps track of latest login times and displays a column in the user overview(s).

Since this plugin allows you to monitor all activity sitewide, it will be very easy to quickly locate spam blogs and their activities.

Unlike the wpmu.org "premium" plugins, Blog Activity and User Activity, this plugin displays information about _what_ is happening, not just that there is _something_ happening.

Has an uninstall option to completely remove itself from the database.

Available in English and Swedish.

Since v1.2 other plugins can add new activities.

== Custom activities ==

Your plugin can both log activities it creates and [optionally] display them.

1. First, use the filter threewp_activity_monitor_list_activities.
2. Then log the activity using the threewp_activity_monitor_new_activity action.
3. Then, optionally, you can handle the displaying of the activity yourself: filter threewp_activity_monitor_display_activity

= action: threewp_activity_monitor_new_activity =

`do_action('threewp_activity_monitor_new_activity', array(
	'activity_id' => '25char_description',
	'activity_strings' => array(
		"" => "%user_display_name_with_link% removed a category on %blog_name_with_panel_link%",
		"Action key 1" => "The key is displayed as small, grey text in front of the other text. Something interesting happened and user # %user_id% caused it!",
		"  " => "%user_login% didnt want any header here so %user_login_with_link% left it blank with two spaces",
		"Another key!" => "This time I wanted a key on %blog_name% (%blog_id%).",
	),
	['user_data'] => $user_object,
	['post_data'] => $post_object,
	['comment_data'] => $comment_object,
	['other_data'] => $any,
));`

*activity_id* is the string to use as an index. See the _index table for the existing activity_id strings which you should avoid (comment_approve, login_success, etc). 25 chars max.
*activity_strings* is a description of the activity. It is an array of key/header => value/text.

The *key* is the small, gray text. It can be left empty. If you want several empty keys, use a different amount of spaces for each one. The spaces are trimmed off.
The *value* is the black text to be displayed to the right of the key or by itself on a line.

Both the *key* and *value* can be normal HTML and are both capable of *keywords*.

*user_data* is an optional user object, if you want the user-related keywords to work with other user data other than the logged-in user's.
*post_data* is an optional post object that enables the post-related keywords.
*comment_data* is an optional comment object that enables the comment-related keywords.

The following keywords are automatically replaced by Activity Monitor. Note that post and comment keywords only work when post_data or comment_data are specified.

* %user_id% ID of user.
* %user_login% User's login name.
* %user_login_with_link% User's login name in link format (link goes to the user edit page).
* %user_display_name% User's display name.
* %user_display_name_with_link% User's display name in link format (link goes to the user edit page).
* %user_display_name% User's login name.
* %user_display_name_with_link% User's login name in link format (link goes to the user edit page).
* %blog_id% ID of blog.
* %blog_name% Name of blog.
* %blog_link% Link to front page of blog.
* %blog_panel_link% Link to blog's admin panel.
* %blog_name_with_link% Blog's name with link to front page.
* %blog_name_with_panel_link% Blog's name with link to admin panel.
* %server_http_user_agent% User's web browser
* %server_http_remote_host% User's hostname
* %server_http_remote_addr% User's IP address
* %post_title% The post's title
* %post_link% The post's title
* %post_title_with_link% The post's title
* %comment_id% The comment's ID
* %comment_link% The link to the comment
* %comment_id_with_link% The comment's linked ID.

Any other data stored in the array is saved automatically. This array can later be retrieved and used for custom display using the filter: threewp_activity_monitor_display_activity

The activity is stored only if you've used threewp_activity_monitor_list_activities to allow the admin to log the activity at all.

= filter: threewp_activity_monitor_list_activities =

`
add_filter( 'threewp_activity_monitor_list_activities', array(&$this, 'list_activities'), 10, 2 );

public function list_activities($activities)
{
	// Create an array which we fill with out own activities.
	$this->activities = array(
		// The bare minimum needed: the key and an array with the name of the activity.
		'wp_login' => array(
			'name' => _('User login'),
		),
		// And now one with all options set.
		'wp_login_failed' => array(
			'name' => _('User login failed'),
			'description' => _('Logs the password the user tried to login with.'),
			'sensitive' => true,
			'can_be_converted_to_post' => true,		// In this example, it can.
		),
	);
	
	// Insert our module name in all the array values.
	// You can do this once, here, or several times manually in the above array.
	// After inserting the plugin name, insert the activity into the main $activities array.
	foreach( $this->activities as $index => $activity )
	{
		$activity['plugin'] = 'ThreeWP Activity Monitor';
		$activities[ $index ] = $activity;
	}
	
	// Return the complete array.
	return $activities;
}`

= filter: threewp_activity_monitor_display_custom_activity =

`
add_filter( 'threewp_activity_monitor_display_activity', array(&$this, 'display_custom_activity'), 10, 1);

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
`

= filter: threewp_activity_monitor_find_activities =

Get a list of semi-specific activities from the database.

Selection of exactly which activities you want is done using the where option in the array.

See the following example from ThreeWP Activity Monitor RSS Creator.

`
$activities = apply_filters( 'threewp_activity_monitor_find_activities', array (
	'where' => array(
		"activity_id" => "activity_id IN ('". implode("','", $feed['activities']) . "')",
		"blog_id" => "blog_id IN ('". implode("','", $feed['blogs']) . "')",
	),
	'limit' => $feed['limit'],
));

`

This uses the filter to requests $feed[limit] amount of activities, that have the $feed[activities] activity_ids and from the $feed[blogs] blogs.

This returns an array of activities, which the plugin later uses and calls another filter: convert_activity_to_post, in order to display the activities as an RSS feed.

= filter: threewp_activity_monitor_convert_activity_to_post =

If your plugin creates activities that can be displayed to the user in the form of posts (eg: new posts, updated posts, new comments) then add a filter.

Return a complete post. The guid should be used as a link to the post or comment in question.

== Installation ==

1. Unzip and copy the zip contents (including directory) into the `/wp-content/plugins/` directory
1. Activate the plugin sitewide through the 'Plugins' menu in WordPress.

== Screenshots ==

1. Main activity monitor tab
1. User list with "last login" column
1. Password tried info
1. Settings tab
1. Uninstall settings

== Upgrade Notice ==

= 2.0 =
* Data storage is in new format. Old data is discarded, unfortunately.
* New filters: threewp_activity_monitor_find_activities, threewp_activity_monitor_delete_activity, threewp_activity_monitor_list_activities
* Selection of activities to log
* Pagination and selection of activities to delete
= 1.2 =
Converts the data column to a base64encoded serialized string.
= 1.0 =
The old activity table is removed.

== Changelog ==
= 2.3 =
* Comment tracking is back.
= 2.2 =
* Documentation fixes.
* Password should actually be displayed in all cases now.
* More language strings.
= 2.1 =
* Fixed make_input problem.
* Uses Wordpress' check-column column for selecting activites.
* Site-admin role visible in settings.
* Form UI updated here and there.
= 2.0 =
* _login and _posts tables aren't used anymore.
* Most activity collected until now is obsolete. The activity log will have to be cleared.
* User's activity is now clearable again.
* Stored actions are self-contained and static, no longer dynamic (that's a pretty big change right there).
* Fixed column overwriting (thanks groucho75).
= 1.4 =
* Only posts and pages are counted as activity. Not menus or attachments.
* Updated the framework
= 1.3 =
* WP 3.1 support
* User's activities shown in profile (fixed)
= 1.2 =
* threewp_activity_monitor_new_activity action added.
= 1.1 =
* Wordpress deleting posts and comments isn't logged anymore.
* Pagination added
* Password tried info added for login failures
= 1.0 =
* Major overhaul.
* Settings are kept when activating the plugin.
= 0.3 =
* WP3.0 compatability
= 0.0.2 =
* Backend link to each blog
* Code cleanup (new base class, etc)
= 0.0.1 =
* Initial public release
