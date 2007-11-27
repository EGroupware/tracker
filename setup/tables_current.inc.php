<?php

	/**
	 * eGroupWare - Setup
	 * http://www.egroupware.org 
	 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
	 *
	 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
	 * @package tracker
	 * @subpackage setup
	 * @version $Id$
	 */


	$phpgw_baseline = array(
		'egw_tracker' => array(
			'fd' => array(
				'tr_id' => array('type' => 'auto','nullable' => False),
				'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False),
				'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False),
				'cat_id' => array('type' => 'int','precision' => '4'),
				'tr_version' => array('type' => 'int','precision' => '4'),
				'tr_status' => array('type' => 'int','precision' => '4','default' => '-100'),
				'tr_description' => array('type' => 'text'),
				'tr_assigned' => array('type' => 'int','precision' => '4'),
				'tr_private' => array('type' => 'int','precision' => '2','default' => '0'),
				'tr_budget' => array('type' => 'decimal','precision' => '20','scale' => '2'),
				'tr_completion' => array('type' => 'int','precision' => '2','default' => '0'),
				'tr_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'tr_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'tr_modifier' => array('type' => 'int','precision' => '4'),
				'tr_modified' => array('type' => 'int','precision' => '8'),
				'tr_closed' => array('type' => 'int','precision' => '8'),
				'tr_priority' => array('type' => 'int','precision' => '2','default' => '5'),
				'tr_resolution' => array('type' => 'char','precision' => '1','default' => ''),
				'tr_cc' => array('type' => 'text'),
				'tr_group' => array('type' => 'int','precision' => '11'),
				'tr_edit_mode' => array('type' => 'varchar','precision' => '5','default' => 'ascii'),
			),
			'pk' => array('tr_id'),
			'fk' => array(),
			'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_assigned','tr_group',array('cat_id','tr_status','tr_assigned')),
			'uc' => array()
		),
		'egw_tracker_replies' => array(
			'fd' => array(
				'reply_id' => array('type' => 'auto','nullable' => False),
				'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
				'reply_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'reply_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'reply_message' => array('type' => 'text')
			),
			'pk' => array('reply_id'),
			'fk' => array(),
			'ix' => array(array('tr_id','reply_created')),
			'uc' => array()
		),
		'egw_tracker_votes' => array(
			'fd' => array(
				'tr_id' => array('type' => 'int','precision' => '4'),
				'vote_uid' => array('type' => 'int','precision' => '4'),
				'vote_ip' => array('type' => 'varchar','precision' => '128'),
				'vote_time' => array('type' => 'int','precision' => '8','nullable' => False)
			),
			'pk' => array('tr_id','vote_uid','vote_ip'),
			'fk' => array(),
			'ix' => array(),
			'uc' => array()
		),
		'egw_tracker_bounties' => array(
			'fd' => array(
				'bounty_id' => array('type' => 'auto','nullable' => False),
				'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
				'bounty_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'bounty_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'bounty_amount' => array('type' => 'decimal','precision' => '20','scale' => '2','nullable' => False),
				'bounty_name' => array('type' => 'varchar','precision' => '64'),
				'bounty_email' => array('type' => 'varchar','precision' => '128'),
				'bounty_confirmer' => array('type' => 'int','precision' => '4'),
				'bounty_confirmed' => array('type' => 'int','precision' => '8'),
				'bounty_payedto' => array('type' => 'varchar','precision' => '128'),
				'bounty_payed' => array('type' => 'int','precision' => '8')
			),
			'pk' => array('bounty_id'),
			'fk' => array(),
			'ix' => array('tr_id'),
			'uc' => array()
		)
	);
