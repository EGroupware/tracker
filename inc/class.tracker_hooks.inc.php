<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Link;
use EGroupware\Api\Framework;
use EGroupware\Api\Egw;

/**
 * diverse tracker hooks, all static
 */
class tracker_hooks
{
	/**
	 * Hook called by link-class to include tracker in the appregistry of the linkage
	 *
	 * @param array/string $location location and other parameters (not used)
	 * @return array with method-names
	 */
	static function search_link($location)
	{
		unset($location);	// not used, but required by function signature

		$link = array(
			'query' => 'tracker.tracker_bo.link_query',
			'title' => 'tracker.tracker_bo.link_title',
			'titles' => 'tracker.tracker_bo.link_titles',
			'view'  => array(
				'menuaction' => 'tracker.tracker_ui.edit',
			),
			'view_id' => 'tr_id',
			'view_popup'  => '780x580',
			'view_list' => 'tracker.tracker_ui.index',
			'add' => array(
				'menuaction' => 'tracker.tracker_ui.edit',
			),
			'add_app'    => 'link_app',
			'add_id'     => 'link_id',
			'add_popup'  => '780x580',
			'file_access' => 'tracker.tracker_bo.file_access',
			'file_access_user' => true,	// file_access supports 4th parameter $user
			'merge' => true,
			'entry' => 'Ticket',
			'entries' => 'Tickets',
			'push_data'  => ['tr_tracker','tr_category','tr_version','tr_owner','tr_assigned'],
		);

		// Populate default types with queues
		$tracker = new tracker_bo();
		$queues = $tracker->get_tracker_labels();
		foreach($queues as $id => $name)
		{
			$link['default_types'][$id] = array('name' => $name, 'non_deletable' => true);
		}
		return $link;
	}

	/**
	 * hooks to build trackers's sidebox-menu plus the admin and preferences sections
	 *
	 * @param string/array $args hook args
	 */
	static function all_hooks($args)
	{
		$appname = 'tracker';
		$location = is_array($args) ? $args['location'] : $args;
		//echo "<p>tr_admin_prefs_sidebox_hooks::all_hooks(".print_r($args,True).") appname='$appname', location='$location'</p>\n";

		if ($location == 'sidebox_menu')
		{
			// Magic etemplate2 favorites menu (from nextmatch widget)
			display_sidebox($appname, lang('Favorites'), Framework\Favorites::list_favorites($appname));

			$file = array(
				'Tracker list' => Egw::link('/index.php',array(
					'menuaction' => 'tracker.tracker_ui.index',
					'ajax' => 'true')
				),
				array(
					'text' => lang('Add %1',lang(Link::get_registry($appname, 'entry'))),
					'no_lang' => true,
					'link' => "javascript:egw.open('','$appname','add')"
				),
			);

			$file[] = ['text'=>'--'];
			$file['Placeholders'] = Egw::link('/index.php','menuaction=tracker.tracker_merge.show_replacements');
			display_sidebox($appname,$GLOBALS['egw_info']['apps'][$appname]['title'].' '.lang('Menu'),$file);
		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site configuration' => Egw::link('/index.php','menuaction=tracker.tracker_admin.admin&ajax=true'),
				'Define escalations' => Egw::link('/index.php','menuaction=tracker.tracker_admin.escalations&ajax=true'),
				'Custom fields' => Egw::link('/index.php','menuaction=tracker.tracker_customfields.index&use_private=1&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Admin'),$file);
			}
		}
	}

	/**
	 * populates $GLOBALS['settings'] for the preferences
	 */
	static function settings()
	{
		// Versions for default version
		$versions = array('~no-default~'=>lang('None'));
		$bo = new tracker_bo();
		$versions += $bo->get_tracker_labels('version');
		$notify_options = array(
                        '0'   => lang('No'),
                        '-1d' => lang('one day after'),
                        '0d'  => lang('same day'),
                        '1d'  => lang('one day in advance'),
                        '2d'  => lang('%1 days in advance',2),
                        '3d'  => lang('%1 days in advance',3),
                );

		$settings = array(
			array(
				'type'  => 'section',
				'title' => lang('General settings'),
				'no_lang'=> true,
				'xmlrpc' => False,
				'admin'  => False
			),
			/* disabled until we have a home app again
			'homepage_display' => array(
				'type'   => 'check',
				'label'  => 'Tracker for the  home screen',
				'name'   => 'homepage_display',
				'values' => array(
					'no'  => 'No',
					'yes' => 'Yes'
				),
				'help'   => 'Should there be a tracker-box on  home screen?',
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> false
			),*/
			'allow_defaultproject' => array(
				'type'   => 'check',
				'label'  => 'Allow default projects for tracker',
				'name'   => 'allow_defaultproject',
				'help'   => 'Allow the predefinition of projects that will be assigned to new tracker-items.',
				'xmlrpc' => True,
				'admin'  => False,
				'forced' => true,
			),
			'default_version' => array(
				'type'   => 'select',
				'values' => $versions,
				'label'  => 'Default version for new tracker entries',
				'name'   => 'default_version',
				'help'   => 'Pre-selected version when creating a new tracker',
				'run_lang' => false,
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> false,
			),
		);
		foreach($bo->get_tracker_labels() + array(0 => lang('All')) as $tr_id => $label)
		{
			if($bo->user_category_preference[$tr_id])
			{
				$id = $tr_id.'_cat_default';
				$settings[$id] = array(
					'type'   => 'select',
					'values' => $bo->get_tracker_labels('cat',$tr_id),
					'label'  => lang('Default category for new %1 entries',$label == lang('All') ? '' : $label),
					'name'   => $id,
					'help'   => 'Pre-selected category when creating a new entry',
					'run_lang' => false,
					'xmlrpc' => True,
					'admin'  => False,
					'default'=> false,
				);
			}
		}

		$settings += array(
			'limit_des_lines' => array(
				'type'   => 'input',
				'size'   => 5,
				'label'  => 'Limit number of description lines (default 5, 0 for no limit)',
				'name'   => 'limit_des_lines',
				'help'   => 'How many description lines should be directly visible. Further lines are available via a scrollbar.',
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> 5,
			),
			array(
				'type'  => 'section',
				'title' => lang('Notification settings'),
				'no_lang'=> true,
				'xmlrpc' => False,
				'admin'  => False
			),
			'notify_creator' => array(
				'type'   => 'check',
				'label'  => 'Receive notifications about created tracker-items',
				'name'   => 'notify_creator',
				'help'   => 'Should the Tracker send you notification mails, if tracker items you created get updated?',
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> true,
			),
			'notify_assigned' => array(
				'type'   => 'check',
				'label'  => 'Receive notifications about assigned tracker-items',
				'name'   => 'notify_assigned',
				'help'   => 'Should the Tracker send you notification mails, if tracker items assigned to you get updated?',
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> true,
			),
			'notify_own_modification' => array(
				'type'   => 'check',
				'label'  => 'Receive notifications about own changes in tracker-items',
				'name'   => 'notify_own_modification',
				'help'   => 'Show the Tracker send you notification mails, in tracker items that you updates?',
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> false,
			),
			'notify_start' => array(
				'type'   => 'select',
				'label'  => 'Receive notifications about starting entries you created or are assigned to',
				'name'   => 'notify_start',
				'help'   => 'Do you want a notification, if items you are responsible for are about to start?',
				'values' => $notify_options,
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> '0d',       // Same day
			),
			'notify_due' => array(
				'type'   => 'select',
				'label'  => 'Receive notifications about due entries you created or are assigned to',
				'name'   => 'notify_due',
				'help'   => 'Do you want a notification, if items you are responsible for are due?',
				'values' => $notify_options,
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> '0d',       // Same day
			),
			'data_settings' => array(
				'type'  => 'section',
				'title' => lang('Data exchange settings'),
				'no_lang'=> true,
				'xmlrpc' => False,
				'admin'  => False
			),
		);
		// Merge print
		if ($GLOBALS['egw_info']['user']['apps']['filemanager'])
		{
			$merge = new tracker_merge();
			$settings += $merge->merge_preferences();
		}

		return $settings;
	}

	/**
	 * Verification hook called if settings / preferences get stored
	 *
	 * Installs a task to send async notifications at 2h everyday
	 *
	 * @param array $data
	 */
	static function verify_settings($data)
	{
		if ($data['prefs']['notify_due'] || $data['prefs']['notify_start'])
		{
			$async = new Api\Asyncservice();

			if (!$async->read(tracker_escalations::ASYNC_NOTIFICATION))
			{
				$async->set_timer(array('hour' => 2),tracker_escalations::ASYNC_NOTIFICATION,
					'tracker_escalations::preference_notifications',null);
			}
		}
	}

	/**
	 * Mail integration hook to import mail message contents into a tracker entry
	 *
	 * @return string method to be executed for tracker mail integration
	 */
	public static function mail_import($args)
	{
		unset($args);	// not used, but required by function signature

		return array(
			'menuaction' => 'tracker.tracker_ui.mail_import',
			'popup' => Link::get_registry('tracker', 'add_popup'),
			'app_entry_method' => 'tracker.tracker_bo.ajax_getTicketId'
		);
	}

	/**
	 * Returns a list of custom widgets classes for etemplate2
	 */
	public static function widgets()
	{
		return array('tracker_assigned_etemplate_widget');
	}
}
