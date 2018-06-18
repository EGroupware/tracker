<?php
/**
 * EGroupware - Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @subpackage setup
 * @copyright (c) 2006-17 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

$setup_info['tracker']['name']      = 'tracker';
$setup_info['tracker']['version']   = '17.1.002';
$setup_info['tracker']['app_order'] = 5;
$setup_info['tracker']['tables']    = array('egw_tracker','egw_tracker_replies','egw_tracker_votes','egw_tracker_bounties','egw_tracker_assignee','egw_tracker_escalations','egw_tracker_escalated','egw_tracker_extra');
$setup_info['tracker']['enable']    = 1;
$setup_info['tracker']['index']     = 'tracker.tracker_ui.index&ajax=true';

$setup_info['tracker']['author'] =
$setup_info['tracker']['maintainer'] = array(
	'name'  => 'Ralf Becker',
	'email' => 'RalfBecker@outdoor-training.de'
);
$setup_info['tracker']['license']  = 'GPL';
$setup_info['tracker']['description'] =
'Universal tracker (bugs, feature requests, ...) with voting and bounties.';
$setup_info['tracker']['note'] = '';

/* The hooks this app includes, needed for hooks registration */
$setup_info['tracker']['hooks']['settings'] = 'tracker_hooks::settings';
$setup_info['tracker']['hooks']['admin'] = 'tracker_hooks::all_hooks';
$setup_info['tracker']['hooks']['sidebox_menu'] = 'tracker_hooks::all_hooks';
$setup_info['tracker']['hooks']['search_link'] = 'tracker_hooks::search_link';
$setup_info['tracker']['hooks']['deleteaccount'] = 'tracker.tracker_so.change_delete_owner';
$setup_info['tracker']['hooks']['timesheet_set'] = 'tracker.tracker_ui.timesheet_set';
$setup_info['tracker']['hooks']['infolog_set'] = 'tracker.tracker_ui.infolog_set';
$setup_info['tracker']['hooks']['verify_settings'] = 'tracker_hooks::verify_settings';
$setup_info['tracker']['hooks']['addressbook_view'] = 'tracker.tracker_ui.hook_view';
$setup_info['tracker']['hooks']['mail_import'] = 'tracker.tracker_hooks.mail_import';
$setup_info['tracker']['hooks']['change_account_ids'] = 'tracker_admin::change_account_ids';

/* Dependencies for this app to work */
$setup_info['tracker']['depends'][] = array(
	 'appname' => 'api',
	 'versions' => Array('17.1')
);
