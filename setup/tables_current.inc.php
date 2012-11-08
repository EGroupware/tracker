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
			'tr_id' => array('type' => 'auto','nullable' => False,'comment' => 'id of the ticket'),
			'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False,'comment' => 'summary of the ticket'),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False,'comment' => 'tracker queue'),
			'cat_id' => array('type' => 'int','precision' => '4','comment' => 'category of the ticket'),
			'tr_version' => array('type' => 'int','precision' => '4','comment' => 'version of the ticket'),
			'tr_status' => array('type' => 'int','precision' => '4','default' => '-100','comment' => 'status of the ticket'),
			'tr_description' => array('type' => 'text','comment' => 'long description'),
			'tr_private' => array('type' => 'int','precision' => '2','default' => '0','comment' => '1=private'),
			'tr_budget' => array('type' => 'decimal','precision' => '20','scale' => '2','comment' => 'budget for bounties'),
			'tr_completion' => array('type' => 'int','precision' => '2','default' => '0','comment' => 'completion of the ticket'),
			'tr_creator' => array('type' => 'int','precision' => '4','nullable' => False,'comment' => 'account id of the creator'),
			'tr_created' => array('type' => 'int','precision' => '8','nullable' => False,'comment' => 'timestamp of the creation'),
			'tr_modifier' => array('type' => 'int','precision' => '4','comment' => 'account id of last modified'),
			'tr_modified' => array('type' => 'int','precision' => '8','comment' => 'timestamp of last modified'),
			'tr_closed' => array('type' => 'int','precision' => '8','comment' => 'timestamp of ticket closed'),
			'tr_priority' => array('type' => 'int','precision' => '2','default' => '5','comment' => 'priority of the ticket'),
			'tr_resolution' => array('type' => 'int','precision' => '4','comment' => 'resolution of the ticket'),
			'tr_cc' => array('type' => 'text','comment' => 'cc-field for notification'),
			'tr_group' => array('type' => 'int','precision' => '11','comment' => 'group-id to which the ticket belongs'),
			'tr_edit_mode' => array('type' => 'varchar','precision' => '5','default' => 'ascii','comment' => 'ascii or html'),
			'tr_seen' => array('type' => 'text','comment' => 'flag if the ticket has been already viewed'),
			'tr_startdate' => array('type' => 'int','precision' => '8','comment' => 'Date ticket is scheduled to begin'),
			'tr_duedate' => array('type' => 'int','precision' => '8','comment' => 'Date ticket is required to be resolved by')
		),
		'pk' => array('tr_id'),
		'fk' => array(),
		'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_resolution','tr_group',array('cat_id','tr_status')),
		'uc' => array()
	),
	'egw_tracker_replies' => array(
		'fd' => array(
			'reply_id' => array('type' => 'auto','nullable' => False),
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'reply_creator' => array('type' => 'int','precision' => '4','nullable' => False),
			'reply_created' => array('type' => 'int','precision' => '8','nullable' => False),
			'reply_message' => array('type' => 'text'),
			'reply_visible' => array('type' => 'int','precision' => '1','nullable' => False,'default' => '0')
		),
		'pk' => array('reply_id'),
		'fk' => array(),
		'ix' => array('reply_visible',array('tr_id','reply_created')),
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
	),
	'egw_tracker_assignee' => array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_assigned' => array('type' => 'int','precision' => '4','nullable' => False)
		),
		'pk' => array('tr_id','tr_assigned'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'egw_tracker_escalations' => array(
		'fd' => array(
			'esc_id' => array('type' => 'auto','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'cat_id' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'tr_version' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'tr_status' => array('type' => 'varchar','precision' => '255','nullable' => False,'default' => '0'),
			'tr_priority' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'esc_title' => array('type' => 'varchar','precision' => '128','nullable' => False),
			'esc_time' => array('type' => 'int','precision' => '4','nullable' => False),
			'esc_type' => array('type' => 'int','precision' => '1','nullable' => False,'default' => '0'),
			'esc_tr_assigned' => array('type' => 'varchar','precision' => '255'),
			'esc_add_assigned' => array('type' => 'bool'),
			'esc_tr_tracker' => array('type' => 'int','precision' => '4'),
			'esc_cat_id' => array('type' => 'int','precision' => '4'),
			'esc_tr_version' => array('type' => 'int','precision' => '4'),
			'esc_tr_status' => array('type' => 'int','precision' => '4'),
			'esc_tr_priority' => array('type' => 'int','precision' => '4'),
			'esc_reply_message' => array('type' => 'text'),
			'esc_reply_visible' => array('type' => 'int','precision' => '1'),
			'esc_match_repeat' => array('type' => 'int','precision' => '4','default' => '0'),
			'esc_notify' => array('type' => 'varchar','precision' => '15')
		),
		'pk' => array('esc_id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('tr_tracker','cat_id','tr_version','tr_status','tr_priority','esc_time','esc_type'))
	),
	'egw_tracker_escalated' => array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'esc_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'esc_created' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp')
		),
		'pk' => array('tr_id','esc_id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'egw_tracker_extra' => array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_extra_name' => array('type' => 'varchar','precision' => '64','nullable' => False),
			'tr_extra_value' => array('type' => 'text')
		),
		'pk' => array('tr_id','tr_extra_name'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	)
);
