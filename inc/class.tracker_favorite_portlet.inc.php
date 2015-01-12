<?php

/*
 * Egroupware - Tracker - A portlet for displaying a list of entries on the home tab
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package tracker
 * @subpackage home
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @version $Id$
 */

/**
 * The tracker_list_portlet uses a nextmatch / favorite
 * to display a list of entries.
 */
class tracker_favorite_portlet extends home_favorite_portlet
{

	/**
	 * Construct the portlet
	 *
	 */
	public function __construct(Array &$context = array(), &$need_reload = false)
	{
		$context['appname'] = 'tracker';
		
		// Let parent handle the basic stuff
		parent::__construct($context,$need_reload);

		$ui = new tracker_ui();

		$this->context['template'] = 'tracker.index.rows';
		$this->nm_settings += array(
			'get_rows'	=> 'tracker_favorite_portlet::get_rows',
			// Use a different template so it can be accessed from client side
			'template'	=> 'tracker.index.rows',
			// Use a reduced column set for home, user can change if needed
			'default_cols'   => 'tr_summary,tr_created_tr_modified,tr_status',
			'row_id'         => 'tr_id',
			'row_modified'   => 'tr_modified',

			'filter2'        => 0,	// all
			'filter2_label'  => lang('Version'),
			'filter2_no_lang'=> true,
		);
	}

	public function exec($id = null, etemplate_new &$etemplate = null)
	{
		$ui = new tracker_ui();

		$tracker = $this->nm_settings['col_filter']['tr_tracker'];

		$date_filters = array(lang('All'));
		foreach(array_keys($ui->date_filters) as $name)
		{
			$date_filters[$name] = lang($name);
		}
		$this->context['sel_options']['filter'] = $date_filters;
		$this->context['sel_options']['filter2'] = array('No details','Details');
		$this->context['sel_options'] += array(
			'tr_tracker'  => $ui->trackers,
			'tr_status'   => $ui->filters + $ui->get_tracker_stati($tracker),
			'tr_priority' => $ui->get_tracker_priorities($tracker,$this->nm_settings['cat_id']),
			'tr_resolution' => $ui->get_tracker_labels('resolution',$tracker),
			'tr_private'  => array('No', 'Yes'),
		);
		$this->nm_settings['actions'] = $ui->get_actions($tracker, $this->nm_settings['cat_id']);

		// disable start date / due date column, if disabled in config
		if(!$ui->show_dates)
		{
			// Need to set each field so parser takes the whole column
			$this->nm_settings['options-selectcols']['tr_startdate'] = false;
			$this->nm_settings['options-selectcols']['tr_duedate'] = false;
		}
		parent::exec($id, $etemplate);
	}

	/**
	 * Override from tracker to clear the app header
	 *
	 * @param type $query
	 * @param type $rows
	 * @param type $readonlys
	 * @return integer Total rows found
	 */
	public static function get_rows(&$query, &$rows, &$readonlys)
	{
		$ui = new tracker_ui();
		// Don't save in session, it causes problems with real tracker
		$query['csv_export'] = true;
		$total = $ui->get_rows($query, $rows, $readonlys);
		unset($GLOBALS['egw_info']['flags']['app_header']);
		return $total;
	}

	/**
	 * Here we need to handle any incoming data.  Setup is done in the constructor,
	 * output is handled by parent.
	 *
	 * @param type $id
	 * @param etemplate_new $etemplate
	 */
	public static function process($content = array())
	{
		parent::process($content);
		$ui = new tracker_ui();

		// This is just copy+pasted from tracker_ui line 1164, but we don't want
		// the etemplate exec to fire again.
		if (is_array($content) && isset($content['nm']['rows']['document']))  // handle insert in default document button like an action
		{
			list($id) = @each($content['nm']['rows']['document']);
			$content['nm']['action'] = 'document';
			$content['nm']['selected'] = array($id);
		}
		if ($content['admin_popup'] && $content['nm']['action'] == 'admin')
		{
			$content['nm']['action'] = $content['admin_popup'];
		}
		// Clear multiple action popup
		unset($content['admin']);

		if($content['nm']['action'])
		{
			if (!count($content['nm']['selected']) && !$content['nm']['select_all'])
			{
				$msg = lang('You need to select some entries first');
			}
			else
			{
				// Some processing to add values in for links and cats
				$multi_action = $content['nm']['action'];
				// Action has an additional action - add / delete, etc.  Buttons named <multi-action>_action[action_name]
				if(in_array($multi_action, array('link', 'assigned','group')))
				{
					$action = $content[$multi_action.'_popup'];
					$content['nm']['action'] .= '_' . key($action[$multi_action . '_action']);

					// Action handling function wants a single string value, so mush it together
					if(is_array($action[$multi_action]))
					{
						if($multi_action == 'link')
						{
							$action[$multi_action] = $action[$multi_action]['app'] . ':' . $action[$multi_action]['id'];
						}
						else
						{
							$action[$multi_action] = implode(',',$action[$multi_action]);
						}
					}
					$content['nm']['action'] .= '_' . $action[$multi_action];
					unset($content[$multi_action]);
					unset($content[$multi_action.'_popup']);
				}
				$success = $failed = $action_msg = null;
				if ($ui->action($content['nm']['action'],$content['nm']['selected'],$content['nm']['select_all'],
					$success,$failed,$action_msg,'index',$msg,$content['nm']['checkboxes']['no_notifications']))
				{
					$msg .= lang('%1 entries %2',$success,$action_msg);
					egw_json_response::get()->apply('egw.message',array($msg,'success'));
					foreach($content['nm']['selected'] as &$id)
					{
						$id = 'tracker::'.$id;
					}
					// Directly request an update - this will get tracker tab too
					egw_json_response::get()->apply('egw.dataRefreshUIDs',array($content['nm']['selected']));
				}
				else
				{
					if(is_null($msg) || $msg == '')
					{
						$msg = lang('%1 entries %2, %3 failed because of insufficent rights !!!',$success,$action_msg,$failed);
					}
					egw_json_response::get()->apply('egw.message',array($msg,'error'));
				}
			}
		}

	}
 }