<?php
/**
 * EGroupware - Setup
 *
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package tracker
 * @subpackage setup
 * @version $Id$
 */

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


function tracker_upgrade0_1_008()
{
	// Add configurable statis (stored as egw_tracker global cats)
	// Needs a int tr_status (migrate actual data to the new $stati array

	// Rename actual tr_status column
	$GLOBALS['egw_setup']->oProc->RenameColumn('egw_tracker','tr_status','char_tr_status');

	// Create the new (int) tr_status column
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_status',array(
			'type' => 'int',
			'precision' => '4',
			'nullable' => False,
			'default'  => -100, // Open State
	));

	// Update the data
	//		'-100' => 'Open',
	//		'-101' => 'Closed',
	//		'-102' => 'Deleted',
	//		'-103' => 'Pending',
	$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-100 where char_tr_status='o'",__LINE__,__FILE__);
	$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-101 where char_tr_status='c'",__LINE__,__FILE__);
	$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-102 where char_tr_status='d'",__LINE__,__FILE__);
	$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_status=-103 where char_tr_status='p'",__LINE__,__FILE__);

	// Drop the old char tr_status column
	$GLOBALS['egw_setup']->oProc->DropColumn('egw_tracker',array(
		'fd' => array(
			'tr_id' => array('type' => 'auto','nullable' => False),
			'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False),
			'cat_id' => array('type' => 'int','precision' => '4'),
			'tr_version' => array('type' => 'int','precision' => '4'),
			'tr_status' => array('type' => 'int','precision' => '4','default' => -100),
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
			'tr_resolution' => array('type' => 'char','precision' => '1','default' => '')
		),
		'pk' => array('tr_id'),
		'fk' => array(),
		'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_assigned',array('cat_id','tr_status','tr_assigned')),
		'uc' => array()
	),'char_tr_status');

	return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.009';
}


function tracker_upgrade0_1_009()
{
	// Add CC to tracker table

	// Create the new (text) tr_cc column
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_cc',array(
			'type' => 'text',
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '0.1.010';
}


function tracker_upgrade0_1_010()
{
	 return $GLOBALS['setup_info']['tracker']['currentver'] = '1.4';
}


function tracker_upgrade1_4()
{
	/* done by RefreshTable() anyway
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_group',array(
		'type' => 'int',
		'precision' => '4'
	));*/
	$GLOBALS['egw_setup']->oProc->RefreshTable('egw_tracker',array(
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
			'tr_group' => array('type' => 'int','precision' => '4')
		),
		'pk' => array('tr_id'),
		'fk' => array(),
		'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_assigned','tr_group',array('cat_id','tr_status','tr_assigned')),
		'uc' => array()
	));
	$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_group=(select account_primary_group from egw_accounts where egw_accounts.account_id=egw_tracker.tr_creator)",__LINE__,__FILE__);
	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.4.001';
}


function tracker_upgrade1_4_001()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_edit_mode',array(
		'type'	  => 'varchar',
		'precision' => '5',
		'default'   => 'ascii',
	));

	// Set all the current intems to ascii mode
	$GLOBALS['egw_setup']->oProc->query("UPDATE egw_tracker SET tr_edit_mode='ascii'",__LINE__,__FILE__);

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.4.002';
}


function tracker_upgrade1_4_002()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_tracker_assignee',array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_assigned' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False)
		),
		'pk' => array('tr_id','tr_assigned'),
		'fk' => array(),
		'ix' => array('tr_tracker'),
		'uc' => array()
	));
	$GLOBALS['egw_setup']->db->query('INSERT INTO egw_tracker_assignee (tr_id,tr_assigned,tr_tracker) SELECT tr_id,tr_assigned,tr_tracker FROM egw_tracker WHERE tr_assigned IS NOT NULL',__LINE__,__FILE__);

	// Drop the old char tr_status column
	$GLOBALS['egw_setup']->oProc->DropColumn('egw_tracker',array(
		'fd' => array(
			'tr_id' => array('type' => 'auto','nullable' => False),
			'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False),
			'cat_id' => array('type' => 'int','precision' => '4'),
			'tr_version' => array('type' => 'int','precision' => '4'),
			'tr_status' => array('type' => 'int','precision' => '4','default' => '-100'),
			'tr_description' => array('type' => 'text'),
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
			'tr_edit_mode' => array('type' => 'varchar','precision' => '5','default' => 'ascii')
		),
		'pk' => array('tr_id'),
		'fk' => array(),
		'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_group',array('cat_id','tr_status')),
		'uc' => array()
	),'tr_assigned');

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.001';
}


function tracker_upgrade1_5_001()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_tracker_escalations',array(
		'fd' => array(
			'esc_id' => array('type' => 'auto','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'cat_id' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'tr_version' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'tr_status' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
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
			'esc_reply_message' => array('type' => 'text')
		),
		'pk' => array('esc_id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('tr_tracker','cat_id','tr_version','tr_status','tr_priority'))
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.002';
}


function tracker_upgrade1_5_002()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_tracker_escalated',array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'esc_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'esc_created' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp')
		),
		'pk' => array('tr_id','esc_id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.003';
}


function tracker_upgrade1_5_003()
{
	// drop not used egw_tracker_assignee.tr_tracker column
	$GLOBALS['egw_setup']->oProc->DropColumn('egw_tracker_assignee',array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_assigned' => array('type' => 'int','precision' => '4','nullable' => False)
		),
		'pk' => array('tr_id','tr_assigned'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),'tr_tracker');

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.004';
}


function tracker_upgrade1_5_004()
{
	$GLOBALS['egw_setup']->oProc->RefreshTable('egw_tracker_escalations',array(
		'fd' => array(
			'esc_id' => array('type' => 'auto','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'cat_id' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'tr_version' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
			'tr_status' => array('type' => 'int','precision' => '4','nullable' => False,'default' => '0'),
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
			'esc_reply_message' => array('type' => 'text')
		),
		'pk' => array('esc_id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array(array('tr_tracker','cat_id','tr_version','tr_status','tr_priority','esc_time','esc_type'))
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.005';
}


function tracker_upgrade1_5_005()
{
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker_escalations','tr_status',array(
		'type' => 'varchar',
		'precision' => '255',
		'nullable' => False,
		'default' => '0'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.006';
}


function tracker_upgrade1_5_006()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_seen',array(
		'type' => 'text'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.5.007';
}


function tracker_upgrade1_5_007()
{
	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.6';
}

function tracker_upgrade1_6()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_tracker_extra',array(
		'fd' => array(
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_extra_name' => array('type' => 'varchar','precision' => '64','nullable' => False),
			'tr_extra_value' => array('type' => 'text')
		),
		'pk' => array('tr_id','tr_extra_name'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.7.001';
}

/**
 * Setting default for tr_resolution to 'n'
 *
 * @return string '1.7.002'
 */
function tracker_upgrade1_7_001()
{
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker','tr_resolution',array(
		'type' => 'char',
		'precision' => '1',
		'default' => 'n'
	));
	$GLOBALS['egw_setup']->oProc->query("UPDATE egw_tracker SET tr_resolution='n' WHERE tr_resolution IS NULL OR tr_resolution=''",__LINE__,__FILE__);

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.7.002';
}


function tracker_upgrade1_7_002()
{
	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.8';
}

function tracker_upgrade1_8()
{
	/* done by RefreshTable() anyway
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_replies','visible',array(
		'type' => 'int',
		'precision' => '1',
		'nullable' => False
	));*/
	$GLOBALS['egw_setup']->oProc->RefreshTable('egw_tracker_replies',array(
		'fd' => array(
			'reply_id' => array('type' => 'auto','nullable' => False),
			'tr_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'reply_creator' => array('type' => 'int','precision' => '4','nullable' => False),
			'reply_created' => array('type' => 'int','precision' => '8','nullable' => False),
			'reply_message' => array('type' => 'text'),
			'reply_visible' => array('type' => 'int','precision' => '1','nullable' => False, 'default' => 0)
		),
		'pk' => array('reply_id'),
		'fk' => array(),
		'ix' => array('reply_visible',array('tr_id','reply_created')),
		'uc' => array()
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.001';
}


function tracker_upgrade1_9_001()
{
	// Rename actual tr_resolution column
	$GLOBALS['egw_setup']->oProc->RenameColumn('egw_tracker','tr_resolution','char_tr_resolution');

	// Create the new (int) tr_resolution column
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_resolution',array(
			'type' => 'int',
			'precision' => '4',
	));

	// Create new resolutions
	$resolutions = array(
		'n' => 'None',
		'a' => 'Accepted',
		'd' => 'Duplicate',
		'f' => 'Fixed',
		'i' => 'Invalid',
		'I' => 'Info only',
		'l' => 'Later',
		'o' => 'Out of date',
		'p' => 'Postponed',
		'O' => 'Outsourced',
		'r' => 'Rejected',
		'R' => 'Remind',
		'w' => 'Wont fix',
		'W' => 'Works for me',
	);

	//echo "Updating resolutions...<br />"; flush();
	foreach($resolutions as $code => $name) {
		$GLOBALS['egw_setup']->db->insert($GLOBALS['egw_setup']->cats_table,array(
			'cat_owner'  => categories::GLOBAL_ACCOUNT,
			'cat_access' => 'public',
			'cat_appname'=> 'tracker',
			'cat_name'   => $name,
			'cat_description' => 'Resolution added by setup.',
			'cat_data'   => serialize(array('type' => 'resolution')),
			'last_mod'   => time(),
		),false,__LINE__,__FILE__);
		$cat_id = $GLOBALS['egw_setup']->db->get_last_insert_id($GLOBALS['egw_setup']->cats_table,'cat_id');
		$GLOBALS['egw_setup']->db->update($GLOBALS['egw_setup']->cats_table,array(
			'cat_main' => $cat_id,
		),array(
			'cat_id' => $cat_id,
		),__LINE__,__FILE__);
		$GLOBALS['egw_setup']->oProc->query("update egw_tracker set tr_resolution=$cat_id where char_tr_resolution='$code'",__LINE__,__FILE__);
	}

	$GLOBALS['egw_setup']->oProc->RefreshTable('egw_tracker',array(
		'fd' => array(
			'tr_id' => array('type' => 'auto','nullable' => False),
			'tr_summary' => array('type' => 'varchar','precision' => '80','nullable' => False),
			'tr_tracker' => array('type' => 'int','precision' => '4','nullable' => False),
			'cat_id' => array('type' => 'int','precision' => '4'),
			'tr_version' => array('type' => 'int','precision' => '4'),
			'tr_status' => array('type' => 'int','precision' => '4','default' => '-100'),
			'tr_description' => array('type' => 'text'),
			'tr_private' => array('type' => 'int','precision' => '2','default' => '0'),
			'tr_budget' => array('type' => 'decimal','precision' => '20','scale' => '2'),
			'tr_completion' => array('type' => 'int','precision' => '2','default' => '0'),
			'tr_creator' => array('type' => 'int','precision' => '4','nullable' => False),
			'tr_created' => array('type' => 'int','precision' => '8','nullable' => False),
			'tr_modifier' => array('type' => 'int','precision' => '4'),
			'tr_modified' => array('type' => 'int','precision' => '8'),
			'tr_closed' => array('type' => 'int','precision' => '8'),
			'tr_priority' => array('type' => 'int','precision' => '2','default' => '5'),
			'tr_resolution' => array('type' => 'int','precision' => '4'),
			'tr_cc' => array('type' => 'text'),
			'tr_group' => array('type' => 'int','precision' => '11'),
			'tr_edit_mode' => array('type' => 'varchar','precision' => '5','default' => 'ascii'),
			'tr_seen' => array('type' => 'text')
		),
		'pk' => array('tr_id'),
		'fk' => array(),
		'ix' => array('tr_summary','tr_tracker','tr_version','tr_status','tr_resolution','tr_group',array('cat_id','tr_status')),
		'uc' => array()
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.003';	// we already use categories::GLOBAL_ACCOUNT
}


/**
 * Fix tracker global categories / resolutions created by 1.9.001 update, with wrong cat_onwer=-1
 */
function tracker_upgrade1_9_002()
{
	$GLOBALS['egw_setup']->db->update($GLOBALS['egw_setup']->cats_table,array(
		'cat_owner'  => categories::GLOBAL_ACCOUNT,
	),array(
		'cat_owner'  => -1,
		'cat_appname'=> 'tracker',
	),__LINE__,__FILE__);

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.003';	// we already use categories::GLOBAL_ACCOUNT
}

function tracker_upgrade1_9_003()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_startdate',array(
		'type' => 'int',
		'precision' => '8',
		'comment' => 'Date ticket is scheduled to begin'
	));
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker','tr_duedate',array(
		'type' => 'int',
		'precision' => '8',
		'comment' => 'Date ticket is required to be resolved by'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.004';
}


function tracker_upgrade1_9_004()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalations','esc_reply_visible',array(
		'type' => 'int',
		'precision' => '1'
	));
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalations','esc_match_repeat',array(
		'type' => 'int',
		'precision' => '4',
		'default' => '0'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.005';
}



function tracker_upgrade1_9_005()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalations','esc_notify',array(
		'type' => 'varchar',
		'precision' => '15'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.006';
}


function tracker_upgrade1_9_006()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalations','esc_limit',array(
		'type' => 'int',
		'precision' => '1',
		'comment' => 'Limit on how many times one ticket will match'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.007';
}


function tracker_upgrade1_9_007()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalated','match_count',array(
		'type' => 'int',
		'precision' => '1',
		'nullable' => False,
		'default' => '1'
	));

	// drop all unique indexes limiting escalations still created on new instances
	$def = $GLOBALS['egw_setup']->oProc->GetIndexes('egw_tracker_escalations');
	foreach((array)$def['uc'] as $columns)
	{
		$GLOBALS['egw_setup']->oProc->DropIndex('egw_tracker_escalations', $columns);
	}

	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker_escalations','tr_tracker',array(
		'type' => 'varchar',
		'precision' => '55',
		'nullable' => False,
		'default' => '0'
	));
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker_escalations','cat_id',array(
		'type' => 'varchar',
		'precision' => '55',
		'nullable' => False,
		'default' => '0'
	));
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker_escalations','tr_version',array(
		'type' => 'varchar',
		'precision' => '55',
		'nullable' => False,
		'default' => '0'
	));
	$GLOBALS['egw_setup']->oProc->AlterColumn('egw_tracker_escalations','tr_priority',array(
		'type' => 'varchar',
		'precision' => '55',
		'nullable' => False,
		'default' => '0'
	));
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalations','tr_resolution',array(
		'type' => 'varchar',
		'precision' => '55',
		'nullable' => False
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.008';
}

/**
 * Add esc_run_on_existing column
 *
 * @return string
 */
function tracker_upgrade1_9_008()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_tracker_escalations','esc_run_on_existing',array(
		'type' => 'int',
		'precision' => '1',
		'nullable' => False,
		'default' => '1'
	));

	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.009';
}

/**
 * Removed update creating different unique index
 *
 * Not used anymore, as we drop all unique indexes in next update
 *
 * @return string
 */
function tracker_upgrade1_9_009()
{
	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.010';
}

/**
 * Drop unique index(es) limiting creating same escalation type on eg. different queues
 *
 * Was previously done by modifying index to contain more columns in update, but not new installations.
 * Now droping all existing unique indexes completly.
 *
 * @return string
 */
function tracker_upgrade1_9_010()
{
	// drop all unique indexes limiting escalations still created on new instances
	$def = $GLOBALS['egw_setup']->oProc->GetIndexes('egw_tracker_escalations');
	foreach((array)$def['uc'] as $columns)
	{
		$GLOBALS['egw_setup']->oProc->DropIndex('egw_tracker_escalations', $columns);
	}
	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.011';
}

/**
 * Dummy upgrade to change index url via setup.inc.php
 *
 * @return string
 */
function tracker_upgrade1_9_011()
{
	return $GLOBALS['setup_info']['tracker']['currentver'] = '1.9.012';
}


function tracker_upgrade1_9_012()
{
	return $GLOBALS['setup_info']['tracker']['currentver'] = '14.1';
}


/**
 * Setting all tr_private=NULL to "0"
 *
 * @return string
 */
function tracker_upgrade14_1()
{
	$GLOBALS['egw_setup']->db->update('egw_tracker', array('tr_private'=>0), array('tr_private IS NULL'), __LINE__, __FILE__, 'tracker');

	return $GLOBALS['setup_info']['tracker']['currentver'] = '14.1.001';
}
