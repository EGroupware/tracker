<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @subpackage setup
 * @copyright (c) 2006 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id: class.bocontacts.inc.php 22159 2006-07-22 18:02:15Z ralfbecker $ 
 */

$setup_info['tracker']['name']      = 'tracker';
$setup_info['tracker']['version']   = '0.1.006';
$setup_info['tracker']['app_order'] = 5;
$setup_info['tracker']['tables']    = array('egw_tracker','egw_tracker_replies','egw_tracker_votes');
$setup_info['tracker']['enable']    = 1;

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
$setup_info['tracker']['hooks']['preferences'] = 'tracker.tr_admin_prefs_sidebox_hooks.all_hooks';
$setup_info['tracker']['hooks']['settings'] = 'tracker.tr_admin_prefs_sidebox_hooks.settings';
$setup_info['tracker']['hooks']['admin'] = 'tracker.tr_admin_prefs_sidebox_hooks.all_hooks';
$setup_info['tracker']['hooks']['sidebox_menu'] = 'tracker.tr_admin_prefs_sidebox_hooks.all_hooks';
$setup_info['tracker']['hooks']['search_link'] = 'tracker.botracker.search_link';

/* Dependencies for this app to work */
$setup_info['tracker']['depends'][] = array(
	 'appname' => 'phpgwapi',
	 'versions' => Array('1.2','1.3')
);
$setup_info['tracker']['depends'][] = array(
	 'appname' => 'etemplate',
	 'versions' => Array('1.2','1.3')
);

