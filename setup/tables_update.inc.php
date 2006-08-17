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


	$test[] = '0.1.006';
	function tracker_upgrade0_1_006()
	{
		$GLOBALS['egw_setup']->oProc->CreateTable('egw_tracker_bounties',array(
			'fd' => array(
				'bounty_id' => array('type' => 'auto','nullable' => False),
				'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
				'bounty_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'bounty_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'bounty_amount' => array('type' => 'decimal','precision' => '20','scale' => '2','nullable' => False),
				'bounty_name' => array('type' => 'varchar','precision' => '64'),
				'bounty_email' => array('type' => 'varchar','precision' => '128'),
				'bounty_confirmer' => array('type' => 'int','precision' => '4'),
				'bounty_confirmed' => array('type' => 'int','precision' => '8')
			),
			'pk' => array('bounty_id'),
			'fk' => array(),
			'ix' => array('tr_id'),
			'uc' => array()
		));

		return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.007';
	}


	$test[] = '0.1.007';
	function tracker_upgrade0_1_007()
	{
		$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_bounties','bounty_payedto',array(
			'type' => 'varchar',
			'precision' => '128'
		));
		$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_bounties','bounty_payed',array(
			'type' => 'int',
			'precision' => '8'
		));

		return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.008';
	}
?>
