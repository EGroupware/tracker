<?php
/**
 * Tracker - importing TTS tickets
 *
 * Should be removed after 1.4 (including templates/default/convert.tpl).
 * 
 * @link http://www.egroupware.org
 * @author Oscar van Eijk <oscar@oveas.com>
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$ 
 */

/*
 * This script is basically created to be used only once. It converts
 * tickets from TTS to the new Tracker application, introduced
 * in eGroupWare v1.3.
 * When all tickets have been converted, they will remain in the database.
 * There is no option to roll-back a conversion. If this needs to be
 * done, a manual update should be made on the database:
 *  UPDATE phpgw_tts_tickets SET ticket_converted = 'N' [WHERE...]
 * Remember to remove the converted tickets from Tracker as well....
 */

$GLOBALS['egw_info'] = array(
	'flags' => array(
		'currentapp' => 'tracker',
		'admin_only' => true,
		'noheader'   => true,
		'enable_categories_class' => true,
	),
);
include('../header.inc.php');

if ($_POST['cancel'])
{
	$GLOBALS['egw']->redirect_link('/tracker/index.php');
}

TTSCONV_check_update_tts();

$_confirmation = '';
if($_POST['submit'])
{
	$_converted = TTSCONV_perform_conversion ($_POST);
	// if ($_converted < 0) then there's an error... ToDo
	$_confirmation = lang('%1 tickets have been converted',"<strong>$_converted</strong>");
}
TTSCONV_ticket_overview($_confirmation);

function TTSCONV_check_update_tts()
{
	if (version_compare($GLOBALS['egw_info']['apps']['tts']['version'],'1.2.008') < 0)
	{
		die('This tool can only update from the 1.2 Version of TTS, for older versions you have to (install and) update TTS first!');
	}
	if (version_compare($GLOBALS['egw_info']['apps']['tts']['version'],'1.3.002') < 0)
	{
		// we are running a small instant update here
		require_once(EGW_API_INC.'/class.schema_proc.inc.php');
		$schema_proc = new schema_proc();
		$schema_proc->AddColumn('phpgw_tts_tickets','ticket_converted',array(
			'type' => 'char',
			'precision' => '1',
			'nullable' => False,
			'default' => 'N'
		));
		$schema_proc->AddColumn('phpgw_tts_tickets','tracker_id',array(
			'type' => 'int',
			'precision' => '4',
			'nullable' => False,
			'default' => -1
		));
		$GLOBALS['egw_info']['apps']['tts']['version'] = '1.3.002';		// just in case we use 'php4-restore' sessions

		$GLOBALS['egw']->db->update('egw_applications',array('app_version' => '1.3.002'),array('app_name' => 'tts'),__LINE__,__FILE__);

		echo "<p>Updated TTS to last version 1.3.002!</p>\n";
	}
}

function TTSCONV_ticket_overview ($_confirmation = '') {
/*
 * Display the amount of tickets that can be converted and give the user
 * the option to strart the conversions
 */

	$GLOBALS['egw_info']['flags']['app_header'] = $GLOBALS['egw_info']['apps']['tracker']['title'].' - '.lang('Import TTS tickets');
	$GLOBALS['egw']->common->egw_header();

	$GLOBALS['egw']->template->set_file('convert','convert.tpl');

	$GLOBALS['egw']->db->query(
			  'SELECT COUNT(*) AS cnt '
			. 'FROM   phpgw_tts_tickets '
			. "WHERE  ticket_status <> 'X' " ,__LINE__,__FILE__);
	$GLOBALS['egw']->db->next_record();
	$open_tickets = $GLOBALS['egw']->db->f('cnt');

	$GLOBALS['egw']->db->query(
			  'SELECT COUNT(*) AS cnt '
			. 'FROM   phpgw_tts_tickets '
			. "WHERE  ticket_status <> 'X' "
			. "AND    ticket_converted = 'Y' ",__LINE__,__FILE__);
	$GLOBALS['egw']->db->next_record();
	$open_conv_tickets = $GLOBALS['egw']->db->f('cnt');

	$GLOBALS['egw']->db->query(
			  'SELECT COUNT(*) AS cnt '
			. 'FROM   phpgw_tts_tickets '
			. "WHERE  ticket_status = 'X' ",__LINE__,__FILE__);
	$GLOBALS['egw']->db->next_record();
	$closed_tickets = $GLOBALS['egw']->db->f('cnt');

	$GLOBALS['egw']->db->query(
			  'SELECT COUNT(*) AS cnt '
			. 'FROM   phpgw_tts_tickets '
			. "WHERE  ticket_status = 'X' "
			. "AND    ticket_converted = 'Y' " ,__LINE__,__FILE__);
	$GLOBALS['egw']->db->next_record();
	$closed_conv_tickets = $GLOBALS['egw']->db->f('cnt');

	$GLOBALS['egw']->template->set_block('convert','form');

	$GLOBALS['egw']->template->set_var('form_action', $GLOBALS['egw']->link('/tracker/import_tts.php'));

	$GLOBALS['egw']->template->set_var('confirmation', $_confirmation);
	$GLOBALS['egw']->template->set_var('lang_total', lang('ticket count'));
	$GLOBALS['egw']->template->set_var('lang_converted', lang('converted count'));
	$GLOBALS['egw']->template->set_var('lang_open_cnt', lang('open'));
	$GLOBALS['egw']->template->set_var('lang_close_cnt', lang('closed'));
	$GLOBALS['egw']->template->set_var('lang_conv_open', lang('convert all open tickets')
					. ' ('.($open_tickets - $open_conv_tickets).')');
	$GLOBALS['egw']->template->set_var('lang_conv_closed', lang('convert all closed tickets')
					. ' ('.($closed_tickets - $closed_conv_tickets).')');

	$GLOBALS['egw']->template->set_var('open_cnt', $open_tickets);
	$GLOBALS['egw']->template->set_var('open_cnv_cnt', $open_conv_tickets);
	$GLOBALS['egw']->template->set_var('close_cnt', $closed_tickets);
	$GLOBALS['egw']->template->set_var('close_cnv_cnt', $closed_conv_tickets);

	// Disable the chekboxes if nothing needs to be convered
	if ($open_tickets == $open_conv_tickets)
	{
		$GLOBALS['egw']->template->set_var('convopen_disa', 'disabled');
	}
	else
	{
		$GLOBALS['egw']->template->set_var('convopen_disa', '');
	}
	if ($closed_tickets == $closed_conv_tickets)
	{
		$GLOBALS['egw']->template->set_var('convclose_disa', 'disabled');
	}
	else
	{
		$GLOBALS['egw']->template->set_var('convclose_disa', '');
	}
	

	if (($open_tickets == $open_conv_tickets) && ($closed_tickets == $closed_conv_tickets))
	{
		// If all tickets were already converted, there's nothing left to do,
		// so just print a message.
		$GLOBALS['egw']->template->set_var('btn_or_msg', lang('all tickets are already converted to Tracker'));
	}
	else
	{
		$_btn = '<input type="submit" name="submit" value="'.lang('Save').'">&nbsp;'
			.'<input type="submit" name="cancel" value="'.lang('Cancel').'">';
		$GLOBALS['egw']->template->set_var('btn_or_msg', $_btn);

		// Select Global- and Tracker- categories
		$tr_catlist = clone($GLOBALS['egw']->db);
		$GLOBALS['egw']->db->query('SELECT cat_id, cat_name FROM egw_categories '
				. "WHERE cat_appname = 'tracker' and cat_parent = 0",__LINE__,__FILE__);
		if ($GLOBALS['egw']->db->num_rows() == 0)
		{
			// Overwrite (and thus, abuse :-S) the Confirmation
			$GLOBALS['egw']->template->set_var('confirmation', "<font color='red' size='+1'><b>".lang('No Trackers found, aborting').'</b></font>');
		}
		else
		{
			while ($GLOBALS['egw']->db->next_record()) {
				$tr_catlist->query('SELECT cat_id, cat_name FROM egw_categories '
						. "WHERE cat_appname = 'tracker' and cat_parent = "
						. $GLOBALS['egw']->db->f('cat_id') . ' ',__LINE__,__FILE__);
				if ($tr_catlist->num_rows() > 0)
				{
					$Tracker_groups[$GLOBALS['egw']->db->f('cat_id')] = $GLOBALS['egw']->db->f('cat_name');
					while ($tr_catlist->next_record())
					{
						$Tracker_cats[$GLOBALS['egw']->db->f('cat_id')][$tr_catlist->f('cat_id')] = $tr_catlist->f('cat_name');
					}
				}
			}

			$GLOBALS['egw']->db->query('SELECT DISTINCT(ticket_category) FROM phpgw_tts_tickets',__LINE__,__FILE__);
			$GLOBALS['egw']->template->set_var('lang_catconv', lang('select which categories should be used in Tracker for the TTS categories'));
			$_selects = '';
			$_rowclasses = array('row_on', 'row_off');
			$_switch = 1;
			while ($GLOBALS['egw']->db->next_record())
			{
				$_selects .= '<tr class="'.$_rowclasses[$_switch].'"><td>'
					. $GLOBALS['egw']->categories->id2name($GLOBALS['egw']->db->f('ticket_category'))
					. '</td><td align="center"> =&gt; </td>';
				$_selects .= '<td><select name="cat_conv['.$GLOBALS['egw']->db->f('ticket_category').']">';
				foreach ($Tracker_groups as $_groupID => $_group)
				{
					$_selects .= '<optgroup label="'.$_group.'"><br />';
					foreach ($Tracker_cats[$_groupID] as $_catID => $_cat)
					{
						$_selects .= '<option value="'.$_groupID.':'.$_catID.'">'.$_cat.'</option><br />';
					}
					$_selects .= '</optgroup><br />';
				}
				$_selects .= '</select></td></tr>';
				$_switch = 1 - $_switch;
			}
			$GLOBALS['egw']->template->set_var('convert_categories', $_selects);
		}
	}

	$GLOBALS['egw']->template->pfp('out','form');
	$GLOBALS['egw']->common->egw_footer();
}

function TTSCONV_perform_conversion ($_data)
{
	$StatesResolutions  = TTSCONV_states_resolution();
	$CategoryConversion = $_data['cat_conv'];


	$ticketlist = clone($GLOBALS['egw']->db);

	$_converted = 0;
	if (!$_data['conv_open'] && !$_data['conv_closed'])
	{
		return $_converted;				// Nothing to do
	} elseif (!$_data['conv_open']) {			// Not open...
		$_select = "WHERE  ticket_status = 'X' ";	// ... so only closed
	} elseif (!$_data['conv_closed']) {			// Not closed...
		$_select = "WHERE  ticket_status <> 'X' ";	// ... so only open
	} else {
		$_select = ''; 					// Select all
	}

	$ticketlist->query(
			  'SELECT * '
			. 'FROM   phpgw_tts_tickets '
			. $_select ,__LINE__,__FILE__);
			
	if (($selected_tickets = $ticketlist->num_rows()) == 0)
	{
		// Hmmm.... should be an error actually; is that useful? ToDo
		return $_converted;
	}

	while ($ticketlist->next_record())
	{
		$_fields = array(
			 'tr_summary'        => substr ($ticketlist->f('ticket_subject'),0,80)
			,'tr_status'         => $StatesResolutions[$ticketlist->f('ticket_state')][0]
			,'tr_created'        => mktime (1, 0, 1, 1, 1, 1970)
			,'tr_description'    => $ticketlist->f('ticket_details')
			,'tr_private'        => 0  // Default to not private (not used in TTS)
			,'tr_budget'         => (ticket_billable_hours*ticket_billable_rate)
			,'tr_creator'        => intval($ticketlist->f('ticket_owner'))
			,'tr_priority'       => (9-(2*intval($ticketlist->f('ticket_priority'))))
			,'tr_resolution'     => $StatesResolutions[$ticketlist->f('ticket_state')][1]
		);

		if (!is_null($ticketlist->f('ticket_assignedto')))
		{
			$_fields['tr_assigned'] = intval($ticketlist->f('ticket_assignedto'));
		}

		if (!is_null($ticketlist->f('ticket_category')))
		{
			if (isset($CategoryConversion[$ticketlist->f('ticket_category')]))
			{
				list ($_fields['tr_tracker'], $_fields['cat_id']) = explode (':', $CategoryConversion[$ticketlist->f('ticket_category')]);
			}
			else
			{
				$_fields['cat_id'] = $ticketlist->f('ticket_category');
				$_fields['tr_tracker'] = $_fields['cat_id'];
			}
		}

		$GLOBALS['egw']->db->insert('egw_tracker'
			, $_fields
			, false
			, __LINE__
			, __FILE__
			, 'tracker');


		$tracker_id = $GLOBALS['egw']->db->get_last_insert_id('egw_tracker','tr_id');

		TTSCONV_ticket_history ($ticketlist->f('ticket_id'), $tracker_id);

		$GLOBALS['egw']->db->query('UPDATE phpgw_tts_tickets '
				. "SET ticket_converted = 'Y' "
				. ", tracker_id = $tracker_id "
				. 'WHERE ticket_id = ' . $ticketlist->f('ticket_id'),__LINE__,__FILE__);

		$_converted++;
	}
	return $_converted;
}

function TTSCONV_ticket_history ($tts_id, $tracker_id)
{
	$ticketdata = clone($GLOBALS['egw']->db);

	$ticketdata->query(
			  'SELECT * '
			. 'FROM   egw_history_log '
			. "WHERE  history_appname = 'tts' "
			. "AND    history_record_id = $tts_id");
			
	if ($ticketdata->num_rows() == 0)
	{
		return;
	}

	while ($ticketdata->next_record())
	{
		if ($ticketdata->f('history_status') == 'O') {
			$GLOBALS['egw']->db->update('egw_tracker'
				, array('tr_created'   => TTSCONV_timeval2timestamp($ticketdata->f('history_timestamp')))
				, array('tr_id' => $tracker_id)
				, __LINE__
				, __FILE__
				, 'tracker');
		} elseif ($ticketdata->f('history_status') == 'X') {
			$GLOBALS['egw']->db->update('egw_tracker'
				, array( 'tr_closed'   => TTSCONV_timeval2timestamp($ticketdata->f('history_timestamp'))
					,'tr_modified' => TTSCONV_timeval2timestamp($ticketdata->f('history_timestamp'))
					,'tr_modifier' => $ticketdata->f('history_owner'))
				, array('tr_id' => $tracker_id)
				, __LINE__
				, __FILE__
				, 'tracker');
		} elseif ($ticketdata->f('history_status') == 'C') {
			$_fields = array (
 				  'tr_id'         => $tracker_id
				, 'reply_creator' => $ticketdata->f('history_owner')
				, 'reply_created' => TTSCONV_timeval2timestamp($ticketdata->f('history_timestamp'))
				, 'reply_message' => $ticketdata->f('history_new_value')
			);
			$GLOBALS['egw']->db->insert('egw_tracker_replies'
				, $_fields
				, false
				, __LINE__
				, __FILE__
				, 'tracker');
//		} else
			// All other changes are ignored, since they're not
			// compatible with Tracker.
		}
	}
}

function TTSCONV_timeval2timestamp ($val)
{
	list ($dt, $tm) = explode (' ', $val);
	list ($y, $m, $d) = explode ('-', $dt);
	list ($H, $M, $S) = explode (':', $tm);
	return (mktime ($H, $M, $S, $m, $d, $y));
}

function TTSCONV_states_resolution ()
/*
 * Create an array that will be used in an attempt to translate States and
 * Resolutions from TTS to Tracker.
 */
{
	$StatesResolutions = array ();
	$GLOBALS['egw']->db->query('SELECT * FROM phpgw_tts_states ',__LINE__,__FILE__);
	while ($GLOBALS['egw']->db->next_record())
	{
		$_idx = $GLOBALS['egw']->db->f('state_id');
		$StatesResolutions[$_idx] = array ();

		if ($GLOBALS['egw']->db->f('state_open') == 'X')
		{
			$StatesResolutions[$_idx][0] = -101; //State is closed
		}
		else
		{
			$StatesResolutions[$_idx][0] = -100; //State is opened
		}

		// Check for the default state names in TTS and try
		// to translate them.
		if ($GLOBALS['egw']->db->f('state_name') == 'ACCEPTED')
		{
			$StatesResolutions[$_idx][1] = 'a';
		}
//		elseif ($GLOBALS['egw']->db->f('state_name') == 'REOPENED')
//		{
//			$StatesResolutions[$_idx][1] = '';
//		}
//		elseif ($GLOBALS['egw']->db->f('state_name') == 'RESOLVED')
//		{
//			$StatesResolutions[$_idx][1] = '';
//		}
//		elseif ($GLOBALS['egw']->db->f('state_name') == 'VERIFIED')
//		{
//			$StatesResolutions[$_idx][1] = '';
//		}
//		elseif ($GLOBALS['egw']->db->f('state_name') == 'CLOSED')
//		{
//			$StatesResolutions[$_idx][1] = '';
//		}
//		elseif ($GLOBALS['egw']->db->f('state_name') == 'TOVALIDATE')
//		{
//			$StatesResolutions[$_idx][1] = '';
//		}
//		elseif ($GLOBALS['egw']->db->f('state_name') == 'NEEDSWORK')
//		{
//			$StatesResolutions[$_idx][1] = '';
//		}
		elseif ($GLOBALS['egw']->db->f('state_name') == 'INVALID')
		{
			$StatesResolutions[$_idx][1] = 'i';
		}
		elseif ($GLOBALS['egw']->db->f('state_name') == 'DUPLICATE')
		{
			$StatesResolutions[$_idx][1] = 'd';
		}
		else
		{
			$StatesResolutions[$_idx][1] = ''; // Default: None
		}
	}
	return ($StatesResolutions);
}
?>
