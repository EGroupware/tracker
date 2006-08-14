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


	$phpgw_baseline = array(
		'egw_tracker' => array(
			'fd' => array(
				'tr_id' => array('type' => 'auto','nullable' => False),
				'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False),
				'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False),
				'cat_id' => array('type' => 'int','precision' => '4'),
				'tr_version' => array('type' => 'int','precision' => '4'),
				'tr_status' => array('type' => 'char','precision' => '1','default' => 'o'),
				'tr_description' => array('type' => 'text'),
				'tr_assigned' => array('type' => 'int','precision' => '4'),
				'tr_private' => array('type' => 'int','precision' => '2','default' => '0'),
				'tr_budget' => array('type' => 'float','precision' => '4'),
				'tr_completion' => array('type' => 'int','precision' => '2','default' => '0'),
				'tr_creator' => array('type' => 'int','precision' => '4','nullable' => False),
				'tr_created' => array('type' => 'int','precision' => '8','nullable' => False),
				'tr_modifier' => array('type' => 'int','precision' => '4'),
				'tr_modified' => array('type' => 'int','precision' => '8'),
				'tr_closed' => array('type' => 'int','precision' => '8'),
				'tr_priority' => array('type' => 'int','precision' => '2','default' => '5')
			),
			'pk' => array('tr_id'),
			'fk' => array(),
			'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_assigned',array('cat_id','tr_status','tr_assigned')),
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
		)
	);
