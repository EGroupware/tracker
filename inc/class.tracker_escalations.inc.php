<?php
/**
 * eGroupWare Tracker - Escalation of tickets
 *
 * Sponsored by Hexagon Metrolegy (www.hexagonmetrology.net)
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2008 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * Escalation of tickets
 */
class tracker_escalations extends so_sql
{
	/**
	 * Name of escalations table
	 */
	const ESCALATIONS_TABLE = 'egw_tracker_escalations';
	/**
	 * Values for esc_type column
	 */
	const CREATION = 0;
	const MODIFICATION = 1;
	const REPLIED = 2;

	/**
	 * Constructor
	 *
	 * @return tracker_ui
	 */
	function __construct()
	{
		parent::so_sql('tracker',self::ESCALATIONS_TABLE,null,'',true);
	}

	/**
	 * initializes data with the content of key
	 *
	 * @param array $keys array with keys in form internalName => value
	 * @return array internal data after init
	 */
	function init($keys=array())
	{
		$this->data = array(
			'tr_status' => -100,	// offen
		);
		$this->data_merge($keys);

		if (isset($keys['set']))
		{
			$this->data['set'] = $keys['set'];
		}
		return $this->data;
	}

	/**
	 * changes the data from the db-format to your work-format
	 *
	 * It gets called everytime when data is read from the db.
	 * This default implementation only converts the timestamps mentioned in $this->timestampfs from server to user time.
	 * You can reimplement it in a derived class
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 */
	function db2data($data=null)
	{
		if (!is_array($data))
		{
			$data = &$this->data;
		}
		foreach($data as $key => &$value)
		{
			if (substr($key,0,4) == 'esc_' && !in_array($key,array('esc_id','esc_title','esc_time','esc_type')))
			{
				$data['set'][substr($key,4)] = $value;
				if (!is_null($value))
				{
					static $col2action;
					if (is_null($col2action))
					{
						$col2action = array(
							'esc_tr_priority' => lang('priority'),
							'esc_tr_tracker'  => lang('queue'),
							'esc_tr_status'   => lang('status'),
							'esc_cat_id'      => lang('category'),
							'esc_tr_version'  => lang('version'),
							'esc_tr_assigned' => lang('assigned to'),
						);
					}
					$action = lang('Set %1',$col2action[$key]).': ';
					switch($key)
					{
						case 'esc_tr_assigned':	// actions with (multiple) users in data
							if ($data['esc_add_assigned']) $action = lang('Add assigned').': ';
							$users = array();
							foreach((array)$value as $uid)
							{
								$users[] = $GLOBALS['egw']->common->grab_owner_name($uid);
							}
							$action .= implode(', ',$users);
							break;
						case 'esc_add_assigned':
							continue 2;
						case 'esc_tr_priority':
							$priorities = ExecMethod('tracker.tracker_bo.get_tracker_priorities',$data['tr_tracker']);
							$action .= $priorities[$value];
							break;
						case 'esc_tr_status':
							if ($value < 0)
							{
								$action .= lang(tracker_bo::$stati[$value]);
								break;
							}
							// fall through for category labels
						case 'esc_cat_id':
						case 'esc_tr_version':
						case 'esc_tr_tracker':
							$action .= $GLOBALS['egw']->categories->id2name($cat_id);
							break;
						case 'esc_reply_message':
							$action = lang('Add comment').":\n".$value;
							break;
					}
					$actions[] = $action;
				}
				unset($data[$key]);
			}
		}
		if ($actions)
		{
			$data['esc_action_label'] = implode("\n",$actions);
		}
		return parent::db2data($data);
	}

	/**
	 * changes the data from your work-format to the db-format
	 *
	 * It gets called everytime when data gets writen into db or on keys for db-searches.
	 * This default implementation only converts the timestamps mentioned in $this->timestampfs from user to server time.
	 * You can reimplement it in a derived class
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 */
	function data2db($data=null)
	{
		if (!is_array($data))
		{
			$data = &$this->data;
		}
		if (isset($data['set']))
		{
			foreach($data['set'] as $key => $value)
			{
				$data['esc_'.$key] = is_array($value) ? implode(',',$value) : $value;
			}
			unset($data['set']);
		}
		return parent::db2data($data);
	}

	function get_rows($query,&$rows,&$readonlys)
	{
		$Ok = parent::get_rows($query,$rows,$readonlys);

		//_debug_array($rows);

		return $Ok;
	}
}
