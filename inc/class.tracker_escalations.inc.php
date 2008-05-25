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
class tracker_escalations extends so_sql2
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
	function __construct($id = null)
	{
		parent::so_sql('tracker',self::ESCALATIONS_TABLE,null,'',true);

		if (!is_null($id) && !$this->read($id))
		{
			throw new egw_exception_not_found();
		}
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

	/**
	 * Get an SQL filter to include in a tracker search returning only matches of a given escalation
	 *
	 * @param boolean $due=false true = return only tickets due to escalate, default false = return all tickets matching the escalation filter
	 * @return array|boolean array with filter or false if escalation not found
	 */
	function get_filter($due=false)
	{
		$filter = array();

		if ($this->tr_tracker)  $filter['tr_tracker'] = $this->tr_tracker;
		if ($this->tr_status)   $filter['tr_status'] = $this->tr_status;
		if ($this->tr_priority) $filter['tr_priority'] = $this->tr_priority;
		if ($this->cat_id)      $filter['cat_id'] = $this->cat_id;
		if ($this->tr_version)  $filter['tr_version'] = $this->tr_version;

		if ($due)
		{
			//echo "<p>time=".time()."=".date('Y-m-d H:i:s').", esc_time=$this->esc_time, time()-esc_time*60=".(time()-$this->esc_time*60).'='.date('Y-m-d H:i:s',time()-$this->esc_time*60)."</p>\n";
			$filter[] = $this->get_time_col().' < '.(time()-$this->esc_time*60);
		}

		return $filter;
	}

	/**
	 * Get SQL (usable as extra column) of time relevant for the escalation
	 *
	 * @return string
	 */
	function get_time_col()
	{
		switch($this->esc_type)
		{
			default:
			case self::CREATION:
				return 'tr_created';
			case self::MODIFICATION:
				return 'tr_modified';
			case self::REPLIED:
				return "(SELECT MAX(reply_created) FROM egw_tracker_replies r WHERE r.tr_id = egw_tracker.tr_id)";
		}
	}
}
