<?php

	/**
	 * eGroupWare - Setup
	 * http://www.egroupware.org 
	 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
	 *
	 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
	 * @package tracker
	 * @subpackage setup
	 * @version $Id: class.db_tools.inc.php 21408 2006-04-21 10:31:06Z nelius_weiss $
	 */

	$test[] = '0.1.005';
	function tracker_upgrade0_1_005()
	{
		$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker','tr_budget',array(
			'type' => 'decimal',
			'precision' => '20',
			'scale' => '2'
		));
		$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_resolution',array(
			'type' => 'char',
			'precision' => '1',
			'default' => ''
		));

		return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.006';
	}
?>
