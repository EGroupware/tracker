<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2013 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

require_once(EGW_INCLUDE_ROOT.'/etemplate/inc/class.sitemgr_module.inc.php');

/**
 * SiteMgr module for the new tracker
 *
 */
class module_tracker_add extends sitemgr_module
{
	function module_tracker_add()
	{
		$this->arguments = array(
			'tracker' => array(		// will be passed as $only_tracker argument to uitracker::index()
				'type' => 'select',
				'label' => lang('Tracker'),
				'options' => array(
					'' => lang('All'),
				)+ExecMethod2('tracker.tracker_bo.get_tracker_labels','tracker')
			),
			'success' => array(
				'type' => 'htmlarea',
				'label' => lang('Success message'),
			),
		);
		$this->title = lang('Add a ticket');
		$this->description = lang('This module allows to add a ticket.');

		$this->etemplate_method = 'tracker.tracker_ui.edit';
	}

	/**
	 * generate the module content AND process submitted forms
	 *
	 * Reimplemented to give a custom success-message
	 *
	 * @param array &$arguments $arguments['arg1']-$arguments['arg3'] will be passed for non-submitted forms (first call)
	 * @param array $properties
	 * @return string the html content
	 */
	function get_content(&$arguments,$properties)
	{
		if ($_SERVER['REQUEST_METHOD'] == 'GET')
		{
			$_GET['tracker'] = $arguments['tracker'];
		}
		$ret = parent::get_content($arguments, $properties);

		if ($_SERVER['REQUEST_METHOD'] == 'POST' && !etemplate::validation_errors())
		{
			$ret = $arguments['success'];
		}
		return $ret;
	}
}
