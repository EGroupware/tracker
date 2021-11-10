<?php
/**
 * EGroupware Tracker - Escalation of tickets
 *
 * Sponsored by Hexagon Metrolegy (www.hexagonmetrology.net)
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2008-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;

/**
 * Escalation of tickets
 */
class tracker_escalations extends Api\Storage\Base2
{
	/**
	 * Name of escalations table
	 */
	const ESCALATIONS_TABLE = 'egw_tracker_escalations';

	/**
	 * Async jobs
	 */
	const ASYNC_JOB_NAME = 'tracker-escalations';
	const ASYNC_NOTIFICATION = 'tracker-pref-notification';

	/**
	 * Values for esc_type column
	 */
	const CREATION = 0;
	const MODIFICATION = 1;
	const REPLIED = 2;
	const REPLIED_CREATOR = 3;
	const REPLIED_ASSIGNED = 4;
	const REPLIED_NOT_CREATOR = 7;
	const START = 5;
	const DUE = 6;

	/**
	 * Values for checking before or after an event
	 * 'Before' is only valid for start & due dates
	 */
	const BEFORE = 1;
	const AFTER = 2;

	/**
	 * Fields not in the main table, which need to be merged or set
	 *
	 * @var array
	 */
	var $non_db_cols = array('set');

	/**
	 * Notification options
	 */
	public static $notification = array(
		'all'		=> 'all',
		'responsible'	=> 'responsible',
		'none'		=> 'none'
	);

	/**
	 * Constructor
	 *
	 * @return tracker_ui
	 */
	function __construct($id = null)
	{
		parent::__construct('tracker',self::ESCALATIONS_TABLE,null,'',true);
		$uni_cols = array('esc_time','esc_type','tr_tracker','cat_id','tr_status','tr_resolution','tr_version');
		$this->db_uni_cols = array(array_combine($uni_cols,$uni_cols));

		if (!is_null($id) && !$this->read($id))
		{
			throw new Api\Exception\NotFound();
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
		$this->data_merge($keys['escalation']);

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
		foreach(array('tr_status', 'tr_tracker','cat_id','tr_version','tr_priority','tr_resolution') as $array)
		{
			if (isset($data[$array]) && strpos($data[$array],',') !== false)
			{
				$data[$array] = explode(',',$data[$array]);
			}
			$data['escalation'][$array] = $data[$array];
		}
		$bo = new tracker_bo();
		foreach($data as $key => &$value)
		{
			if (substr($key,0,4) == 'esc_' && !in_array($key,array('esc_id','esc_title','esc_time','esc_type','esc_match_repeat','esc_limit','esc_run_on_existing')))
			{
				if ($key == 'esc_tr_assigned')
				{
					$value = $value ? explode(',',$value) : array();
				}
				$data['set'][substr($key,4)] = $value;
				if (!is_null($value))
				{
					static $col2action=null;
					if (is_null($col2action))
					{
						$col2action = array(
							'esc_tr_priority' => lang('priority'),
							'esc_tr_tracker'  => lang('queue'),
							'esc_tr_status'   => lang('status'),
							'esc_cat_id'      => lang('category'),
							'esc_tr_version'  => lang('version'),
							'esc_tr_assigned' => lang('assigned to'),
							'esc_tr_resolution'=>lang('resolution'),
						);
					}
					$action = lang('Set %1',$col2action[$key]).': ';
					switch($key)
					{
						case 'esc_tr_assigned':
							if ($data['esc_add_assigned']) $action = lang('Add assigned').': ';
							$users = array();
							foreach((array)$value as $uid)
							{
								$users[] = Api\Accounts::username($uid);
							}
							$action .= implode(', ',$users);
							break;
						case 'esc_notify':
						case 'esc_reply_visible':
						case 'esc_add_assigned':
							continue 2;
						case 'esc_tr_priority':
							$priorities = ExecMethod('tracker.tracker_bo.get_tracker_priorities',
									is_array($data['tr_tracker']) ? $data['tr_tracker'][0] : $data['tr_tracker']
							);
							$action .= $priorities[$value];
							break;
						case 'esc_tr_resolution':
							$resolutions = $bo->get_tracker_labels('resolution',is_array($data['tr_tracker']) ? $data['tr_tracker'][0] : $data['tr_tracker']);
							$action .= $resolutions[$value];
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
							$action .= $GLOBALS['egw']->categories->id2name($value);
							break;
						case 'esc_reply_message':
							$action = ($data['esc_reply_visible'] ? lang('Add restricted comment') : lang('Add comment')).":\n".$value;
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
		foreach(array('tr_status', 'tr_tracker','cat_id','tr_version','tr_priority','tr_resolution') as $array)
		{
			if (is_array($data[$array]))
			{
				$data[$array] = implode(',',$data[$array]);
			}
		}
		return parent::db2data($data);
	}

	/**
	 * Get an SQL filter to include in a tracker search returning only matches of a given escalation
	 *
	 * @param boolean $due =false true = return only tickets due to escalate, default false = return all tickets matching the escalation filter
	 * @return array|boolean array with filter or false if escalation not found
	 */
	function get_filter($due=false)
	{
		$filter = array();

		if ($this->tr_tracker)  $filter['tr_tracker'] = $this->tr_tracker;
		if ($this->tr_status)   $filter['tr_status'] = $this->tr_status;
		if ($this->tr_priority) $filter['tr_priority'] = $this->tr_priority;
		if ($this->tr_resolution) $filter['tr_resolution'] = $this->tr_resolution;
		if ($this->cat_id)      $filter['cat_id'] = $this->cat_id;
		if ($this->tr_version)  $filter['tr_version'] = $this->tr_version;
		if ($this->esc_limit)	$filter[] = '(match_count < ' . $this->esc_limit .' OR match_count IS NULL)';

		if ($due)
		{
			//echo "<p>time=".time()."=".date('Y-m-d H:i:s').", esc_time=$this->esc_time, time()-esc_time*60=".(time()-$this->esc_time*60).'='.date('Y-m-d H:i:s',time()-$this->esc_time*60)."</p>\n";
			$filter[] = $this->get_time_col().' < '.(time()-$this->esc_time*60);
		}
		else if ($this->esc_time < 0)
		{
			// Not actually running, limit to a week to prevent everything showing
			$filter[] = $this->get_time_col() . ' < ' . strtotime("+1 week", time());
		}
		//echo $this->get_time_col() . ' < ' . date('Y-m-d H:i',time() - $this->esc_time*60  ) . "\n";
		if($this->esc_type == self::START && $this->esc_time < 0)
		{
			// 'Before start date' only matches start dates in the future
			$filter[] = $this->get_time_col() . ' > ' . time();
			//echo $this->get_time_col() . ' > ' . date('Y-m-d H:i',time()) . "\n";
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
			case self::START:
				return 'tr_startdate';
			case self::DUE:
				return 'tr_duedate';
			case self::REPLIED:
				return "(SELECT MAX(reply_created) FROM egw_tracker_replies r WHERE r.tr_id = egw_tracker.tr_id)";
			case self::REPLIED_CREATOR:
				return "(SELECT MAX(reply_created) FROM egw_tracker_replies r WHERE r.tr_id = egw_tracker.tr_id
					AND r.reply_creator = egw_tracker.tr_creator)";
			case self::REPLIED_ASSIGNED:
				return "(SELECT MAX(reply_created) FROM egw_tracker_replies r
					JOIN egw_tracker_assignee ON r.tr_id = egw_tracker_assignee.tr_id
					WHERE r.tr_id = egw_tracker.tr_id)";
			case self::REPLIED_NOT_CREATOR:
				return "(SELECT MAX(reply_created) FROM egw_tracker_replies r WHERE r.tr_id = egw_tracker.tr_id
					AND r.reply_creator != egw_tracker.tr_creator)";
		}
	}

	/**
	 * Private tracker_bo instance to run the escalations
	 *
	 * @var tracker_bo
	 */
	private static $tracker;

	/**
	 * Escalate a given ticket, using this escalation
	 *
	 * @param int|array $ticket
	 */
	function escalate_ticket($ticket)
	{
		if (is_null(self::$tracker))
		{
			self::$tracker = new tracker_bo();
			self::$tracker->user = 0;
		}
		if(!is_object(self::$tracker->tracking))
		{
			self::$tracker->tracking = new tracker_tracking(self::$tracker);
		}
		else
		{
			unset(self::$tracker->tracking->skip_notify);
		}
		if (!is_array($ticket) && !($ticket = self::$tracker->read($ticket)))
		{
			return false;
		}

		//echo self::$tracker->link_title($ticket['tr_id']) . "\n";
		foreach($this->set as $name => $value)
		{
			if (!is_null($value) && $value)
			{
				switch($name)
				{
					case 'add_assigned':
						break;
					case 'notify':
						break;
					case 'tr_assigned':
						if ($this->set['add_assigned'])
						{
							$ticket['tr_assigned'] = array_unique(array_merge($ticket['tr_assigned'],(array)$value));
							break;
						}
						// fall through for SET assigned
					default:
						$ticket[$name] = $value;
						break;
				}
			}
		}
		self::$tracker->init($ticket);

		// Notifications needs to be done after init
		if($value = $this->set['notify'])
		{
			// Change notifications
			if($value == 'none')
			{
				self::$tracker->data['no_notifications'] = true;
			}
			else if ($value == 'responsible')
			{
				// Skip owner & cc
				$ticket['skip_notify'] = array(
					$GLOBALS['egw']->accounts->id2name($ticket['tr_creator'],'account_email'),
				);
				self::$tracker->tracking->skip_notify = array_merge($ticket['skip_notify'], self::$tracker->tracking->get_config('copy',$ticket));
			}

		}
		if (($result = self::$tracker->save($ticket)) != 0)
		{
			return false;	// error saving the ticket
		}
		$count = $this->db->select(tracker_so::ESCALATED_TABLE,
			array('match_count'),array(
				'tr_id' =>  $ticket['tr_id'],
				'esc_id' => $this->id
			),__LINE__,__FILE__,'tracker'
		)->fetchColumn(0);

		if ($count < 127)
		{
			$this->db->insert(tracker_so::ESCALATED_TABLE, ['match_count' => $count + 1], [
				'tr_id' => $ticket['tr_id'],
				'esc_id' => $this->id
			], __LINE__, __FILE__, 'tracker');
		}
		return true;
	}

	/**
	 * Reset the escalations on a ticket appropriately when the ticket is modified
	 *
	 * @param $ticket Array of ticket data
	 * @param $changed array of fields that have changed
	 */
	public function reset($ticket, $changed = array())
	{
		$change_fields = array('tr_tracker', 'tr_resolution', 'cat_id', 'tr_version', 'tr_status', 'tr_priority');

		// Find escalations that have already affected this ticket
		$filter = array('tr_id' => $ticket['tr_id']);
		$join = 'JOIN egw_tracker_escalated ON egw_tracker_escalated.esc_id = egw_tracker_escalations.esc_id';
		$escalations = $this->search('',false,'','',false,'AND',false,$filter,$join);

		foreach((array)$escalations as $esc)
		{
			// Check primary date column
			$delete = false;
			switch($esc['esc_type'])
			{
				// Creation date doesn't change
				// case self::CREATION
				case self::MODIFICATION:
					if(in_array('tr_modified', $changed)) $delete = true;
					break;
				case self::REPLIED:
					if($ticket['reply_created']) $delete = true;
					break;
				case self::REPLIED_CREATOR:
					if($ticket['reply_creator'] == $ticket['tr_creator']) $delete = true;
					break;
				case self::REPLIED_ASSIGNED:
					$assigned = $this->tracker->check_rights(TRACKER_ITEM_ASSIGNEE, false, $ticket, $ticket['reply_creator']);
					if($ticket['reply_creator'] && $assigned) $delete = true;
					break;
				case self::REPLIED_NOT_CREATOR:
					if($ticket['reply_creator'] != $ticket['tr_creator']) $delete = true;
					break;
				case self::START:
					if(in_array('tr_startdate', $changed)) $delete = true;
					break;
				case self::DUE:
					if(in_array('tr_duedate', $changed)) $delete = true;
					break;
			}
			if(!$delete)
			{
				// Check for escalation filter fields against changed fields
				foreach($change_fields as $field)
				{
					if($esc[$field] && in_array($field,$changed))
					{
						$delete = true;
						break;
					}
				}
			}
			if($delete) {
				$this->db->delete(
					tracker_so::ESCALATED_TABLE,
					array('tr_id' => $ticket['tr_id'], 'esc_id'=>$esc['esc_id']),
					__LINE__,__FILE__
				);
			}
		}
	}

	/**
	 * Test and escalate all due tickets for this escalation
	 *
	 */
	function do_escalation()
	{
		if (is_null(self::$tracker))
		{
			self::$tracker = new tracker_bo();
			self::$tracker->user = 0;
		}

		//echo "\n".$this->esc_title . "\n----------------------\n";

		// filter only due tickets
		$filter = $this->get_filter(true);
		// not having this escalation already done
		$join = null;
		$filter[] = self::$tracker->escalated_filter($this->id,$join,
			$this->data['esc_match_repeat'] ? time() - $this->data['esc_match_repeat']*60 : false
		);

		if (($due_tickets = self::$tracker->search(array(),false,'esc_start',$this->get_time_col().' AS esc_start',
			'',false,'AND',false,$filter,$join)))
		{
			//echo count($due_tickets) ." matching tickets:\n";
			foreach($due_tickets as $ticket)
			{
				$this->escalate_ticket($ticket);
			}
		}
		//else echo "\nNo tickets\n--------\n\n";
	}

	/**
	 * Async job running all escalations
	 *
	 */
	function do_all_escalations()
	{
		if (($escalations = $this->search(array(),false)))
		{
//			$this->db->transaction_begin();
			foreach($escalations as $escalation)
			{
				$this->init($escalation);
				$this->do_escalation();
			}
//			$this->db->transaction_commit();
		}
		else	// no escalations (any more) --> delete async job
		{
			self::set_async_job(false);
		}
	}


	/**
	 * Check if exist and if not start or stop an async job to close pending items
	 *
	 * @param boolean $start =true true=start, false=stop
	 */
	static function set_async_job($start=true)
	{
		//echo '<p>'.__METHOD__.'('.($start?'true':'false').")</p>\n";

		$async = new Api\Asyncservice();

		if ($start === !$async->read(self::ASYNC_JOB_NAME))
		{
			if ($start)
			{
				$async->set_timer(array('min' => '*/5'),self::ASYNC_JOB_NAME,'tracker.tracker_escalations.do_all_escalations',null);
			}
			else
			{
				$async->cancel_timer(self::ASYNC_JOB_NAME);
			}
		}
	}

	/**
	 * Reimplemented save to start the async job
	 *
	 * @param array $keys
	 * @param array $extra_where
	 * @param boolean $escalate_existing Mark existing (matching) tickets as escalated without taking the action.
	 * @return int
	 */
	function save($keys=null,$extra_where=null, $escalate_existing = true)
	{
		self::set_async_job(true);

		$result = parent::save($keys,$extra_where);

		if($result != 0 || !$escalate_existing) return $result;


		if (is_null(self::$tracker))
		{
			self::$tracker = new tracker_bo();
			self::$tracker->user = 0;
		}

		// filter only due tickets
		$filter = $this->get_filter(true);
		// not having this escalation already done
		$join = null;
		$filter[] = self::$tracker->escalated_filter($this->id,$join,
			$this->data['esc_match_repeat'] ? time() - $this->data['esc_match_repeat']*60 : false
		);

		if (($due_tickets = self::$tracker->search(array(),false,'esc_start',$this->get_time_col().' AS esc_start',
			'',false,'AND',false,$filter,$join)))
		{
			// error_log(count($due_tickets) . ' escalated with no action');
			foreach($due_tickets as $ticket)
			{
				$this->db->insert(tracker_so::ESCALATED_TABLE,array('match_count' => $this->match_count),
				array(
					'tr_id' =>  $ticket['tr_id'],
					'esc_id' => $this->id
				),__LINE__,__FILE__,'tracker');

			}
		}
		return $result;
	}

	/**
	 * Give simple notifications per user preferences
	 *
	 * These notifications are done in addition to escalations.
	 */
	public static function preference_notifications()
	{

		// Remember so we can restore after
		$save_account_id = $GLOBALS['egw_info']['user']['account_id'];
                $save_prefs      = $GLOBALS['egw_info']['user']['preferences'];

		// Map of preference -> filter
		static $preferences = array(
			'notify_start'	=> 'tr_startdate IS NOT NULL AND tr_startdate ',
			'notify_due'	=> 'tr_duedate IS NOT NULL AND tr_duedate  '
		);


		if (is_null(self::$tracker))
		{
				self::$tracker = new tracker_bo();
				self::$tracker->user = 0;
		}
		if(!is_object(self::$tracker->tracking))
		{
				self::$tracker->tracking = new tracker_tracking(self::$tracker);
		}
		else
		{
				unset(self::$tracker->tracking->skip_notify);
		}

		// Get a list of users
		$users = self::$tracker->users_with_open_entries();

		$notified = null;
		foreach($users as $user)
		{
			if (isset($notified)) $notified=array();
			// Create environment for user
			if (!($email = $GLOBALS['egw']->accounts->id2name($user,'account_email'))) continue;

			self::$tracker->user = $GLOBALS['egw_info']['user']['account_id'] = $user;
			$GLOBALS['egw']->preferences->__construct($user);
			$GLOBALS['egw_info']['user']['preferences'] = $GLOBALS['egw']->preferences->read_repository();
			$GLOBALS['egw']->acl->__construct($user);

			// load the right language if needed
			if ($GLOBALS['egw_info']['user']['preferences']['common']['lang'] != Api\Translation::$userlang)
			{
				Api\Translation::init();
				// Make sure translations are loaded
				Api\Translation::add_app('tracker');
			}

			// Load date/time preferences into Api\DateTime
			Api\DateTime::init();

			// Keep a list of tickets so we only send the user one notification / ticket
			$notified = array();

			// Step through preferences
			foreach($preferences as $pref => $filter)
			{
				if (!($pref_value = $GLOBALS['egw_info']['user']['preferences']['tracker'][$pref])) continue;

				$pref_time= mktime(0,0,0,date('m'),date('d'), date('Y')) + 24*60*60*(int)$pref_value;
				$_filter = array(
					"($filter >= $pref_time) AND ($filter < " . ($pref_time + 24*60*60).')',
					'tr_tracker'	=> array_keys(self::$tracker->trackers),
					'tr_status'	=> 'ownorassigned-not-closed'
				);
//echo "\nUser: $user Preference: $pref=$pref_value Filter: $filter\n";
//echo date('Y-m-d H:i', $pref_time) . ' <= ' . $pref . ' < ' . date('Y-m-d H:i', $pref_time+24*60*60) . "\n";

				if (self::$tracker->user != $user)
				{
					self::$tracker->user = $user;
				}

				// Get matching tickets
				$tickets = self::$tracker->search(array(),'tr_id','','','',False,'AND',false,$_filter);
//error_log(__METHOD__.__LINE__.' Tickets for User:'.$user.'->'.array2string($tickets));
				if(!$tickets) continue;

				foreach($tickets as $ticket)
				{
//echo self::$tracker->link_title($ticket['tr_id']) . "\n";
					// Stop if already notified because of this ticket
					if(!$ticket['tr_id'] || in_array($ticket['tr_id'], $notified)) continue;

					// Remember to prevent more notifications
					$notified[] = $ticket['tr_id'];
					$ticket = self::$tracker->read($ticket['tr_id']);

					switch($pref)
					{
						case 'notify_start':
							$ticket['prefix'] = lang('Starting').' ';
							$ticket['message'] = lang('%1 is starting %2',
								self::$tracker->link_title($ticket['tr_id']),
								$ticket['tr_startdate'] ? Api\DateTime::to($ticket['tr_startdate']) : ''
							);
							break;
						case 'notify_due':
							$ticket['prefix'] = lang('Due') . ' ';
							$ticket['message'] = lang('%1 is due %2',
								self::$tracker->link_title($ticket['tr_id']),
								$ticket['tr_duedate'] ? Api\DateTime::to($ticket['tr_duedate']) : ''
							);
							break;
					}

					// Send notification
					self::$tracker->tracking->send_notification($ticket, null, $email, $user, $pref);
				}
				unset($tickets);
			}

		}

		// Restore
		$GLOBALS['egw_info']['user']['account_id']  = $save_account_id;
		$GLOBALS['egw_info']['user']['preferences'] = $save_prefs;
	}
}
