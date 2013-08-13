<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006-11 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

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
		$link = array(
			'query' => 'tracker.tracker_bo.link_query',
			'title' => 'tracker.tracker_bo.link_title',
			'titles' => 'tracker.tracker_bo.link_titles',
			'view'  => array(
				'menuaction' => 'tracker.tracker_ui.edit',
			),
			'view_id' => 'tr_id',
			'view_popup'  => '780x535',
			'view_list' => 'tracker.tracker_ui.index',
			'add' => array(
				'menuaction' => 'tracker.tracker_ui.edit',
			),
			'add_app'    => 'link_app',
			'add_id'     => 'link_id',
			'add_popup'  => '750x500',
			'file_access' => 'tracker.tracker_bo.file_access',
			'file_access_user' => true,	// file_access supports 4th parameter $user
			'merge' => true,
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
			$file = array(
			);
			display_sidebox($appname,$GLOBALS['egw_info']['apps'][$appname]['title'].' '.lang('Menu'),$file);
		}

		if ($GLOBALS['egw_info']['user']['apps']['preferences'] && $location != 'admin')
		{
			$file = array(
				'Preferences'     => egw::link('/index.php','menuaction=preferences.uisettings.index&appname='.$appname,'preferences'),
			);
			if ($location == 'preferences')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Preferences'),$file);
			}
		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'] && $location != 'preferences')
		{
			$file = Array(
				'Site configuration' => egw::link('/index.php','menuaction=tracker.tracker_admin.admin'),
				'Define escalations' => egw::link('/index.php','menuaction=tracker.tracker_admin.escalations'),
				'Custom fields' => egw::link('/index.php','menuaction=tracker.tracker_customfields.edit&use_private=1'),
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
			'homepage_display' => array(
				'type'   => 'check',
				'label'  => 'Tracker for the main screen',
				'name'   => 'homepage_display',
				'values' => array(
					'no'  => 'No',
					'yes' => 'Yes'
				),
				'help'   => 'Should there be a tracker-box on main screen?',
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> false
			),
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
				'label'  => 'Recieve notifications about own changes in tracker-items',
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
			$link = egw::link('/index.php','menuaction=tracker.tracker_merge.show_replacements');

			$settings['default_document'] = array(
				'type'   => 'vfs_file',
				'size'   => 60,
				'label'  => 'Default document to insert entries',
				'name'   => 'default_document',
				'help'   => lang('If you specify a document (full vfs path) here, %1 displays an extra document icon for each entry. That icon allows to download the specified document with the data inserted.',lang('tracker')).' '.
					lang('The document can contain placeholder like {{%3}}, to be replaced with the data (%1full list of placeholder names%2).','<a href="'.$link.'" target="_blank">','</a>','tr_summary').' '.
					lang('The following document-types are supported:'). implode(',',bo_merge::get_file_extensions()),
				'run_lang' => false,
				'xmlrpc' => True,
				'admin'  => False,
			);
			$settings['document_dir'] = array(
				'type'   => 'vfs_dirs',
				'size'   => 60,
				'label'  => 'Directory with documents to insert entries',
				'name'   => 'document_dir',
				'help'   => lang('If you specify a directory (full vfs path) here, %1 displays an action for each document. That action allows to download the specified document with the data inserted.', lang('tracker')).' '.
					lang('The document can contain placeholder like {{%3}}, to be replaced with the data (%1full list of placeholder names%2).','<a href="'.$link.'" target="_blank">','</a>','tr_summary').' '.
					lang('The following document-types are supported:') . implode(',',bo_merge::get_file_extensions()),
				'run_lang' => false,
				'xmlrpc' => True,
				'admin'  => False,
				'default' => '/templates/tracker',
			);
		}

		// Import / Export for nextmatch
		if ($GLOBALS['egw_info']['user']['apps']['importexport'])
		{
			$definitions = new importexport_definitions_bo(array(
				'type' => 'export',
				'application' => 'tracker'
			));
			$options = array(
				'~nextmatch~'	=>	lang('Old fixed definition')
			);
			foreach ((array)$definitions->get_definitions() as $identifier)
			{
				try
				{
					$definition = new importexport_definition($identifier);
				}
				catch (Exception $e)
				{
					// permission error
					continue;
				}
				if ($title = $definition->get_title())
				{
					$options[$title] = $title;
				}
				unset($definition);
			}
			$default_def = 'export-tracker';
			$settings['nextmatch-export-definition'] = array(
				'type'   => 'select',
				'values' => $options,
				'label'  => 'Export definition to use for nextmatch export',
				'name'   => 'nextmatch-export-definition',
				'help'   => lang('If you specify an export definition, it will be used when you export'),
				'run_lang' => false,
				'xmlrpc' => True,
				'admin'  => False,
				'default'=> isset($options[$default_def]) ? $default_def : false,
			);
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
			$async = new asyncservice();

			if (!$async->read(tracker_escalations::ASYNC_NOTIFICATION))
			{
				$async->set_timer(array('hour' => 2),tracker_escalations::ASYNC_NOTIFICATION,
					'tracker_escalations::preference_notifications',null);
			}
		}
	}
}
