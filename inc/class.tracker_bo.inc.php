<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006-12 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * Some constants for the check_rights function
 */
define('TRACKER_ADMIN',1);
define('TRACKER_TECHNICIAN',2);
define('TRACKER_USER',4);		// non-anonymous user with tracker-rights
define('TRACKER_EVERYBODY',8);	// everyone incl. anonymous user
define('TRACKER_ITEM_CREATOR',16);
define('TRACKER_ITEM_ASSIGNEE',32);
define('TRACKER_ITEM_NEW',64);
define('TRACKER_ITEM_GROUP',128);

/**
 * Business Object of the tracker
 */
class tracker_bo extends tracker_so
{
	/**
	 * Timestamps which need to be converted to user-time and back
	 *
	 * @var array
	 */
	var $timestamps = array('tr_created','tr_modified','tr_closed','reply_created');
	/**
	 * Current user
	 *
	 * @var int;
	 */
	var $user;

	/**
	 * Existing trackers (stored as app-global cats with cat_data='tracker')
	 *
	 * @var array
	 */
	var $trackers;
	/**
	 * Existing priorities
	 *
	 * @var array
	 */
	static $stock_priorities = array(
		1 => '1 - lowest',
		2 => '2',
		3 => '3',
		4 => '4',
		5 => '5 - medium',
		6 => '6',
		7 => '7',
		8 => '8',
		9 => '9 - highest',
	);
	/**
	 * Priorities by tracker or key=0 for all trackers
	 *
	 * Not set trackers use the key=0 or if that's not set the stock priorities
	 *
	 * @var array
	 */
	protected $priorities;
	/**
	 * Stati used by all trackers
	 *
	 * @var array
	 */
	static $stati = array(
		self::STATUS_OPEN    => 'Open(status)',
		self::STATUS_CLOSED  => 'Closed',
		self::STATUS_DELETED => 'Deleted',
		self::STATUS_PENDING => 'Pending',
	);
	/**
	 * Resolutions used by all trackers historically
	 *
	 * Kept around for history display, but no longer used
	 * @var array
	 */
	static $resolutions = array(
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
	var $new_resolution;
	/**
	 * Technicians by tracker or key=0 for all trackers
	 *
	 * @var array
	 */
	var $technicians;
	/**
	 * Admins by tracker or key=0 for all trackers
	 *
	 * @var array
	 */
	var $admins;
	/**
	 * Users by tracker or key=0 for all trackers
	 *
	 * @var array
	 */
	var $users;
	/**
	 * ACL for the fields of the tracker
	 *
	 * field-name is the key with values or'ed together from the TRACKER_ constants
	 *
	 * @var array
	 */
	var $field_acl;
	/**
	 * Restricions settings (tracker specific, keys: group, creator)
	 *
	 * @var array
	 */
	var $restrictions;
	/**
	 * Enabled the acl queue access?
	 *
	 * @var boolean
	 */
	var $enabled_queue_acl_access = false;
	/**
	 * Mailhandler settings (tracker unspecific)
	 *  Keys:
	 *   interval
	 *   address
	 *   server
	 *   servertype
	 *   serverport
	 *   folder
	 *   username
	 *   password
	 *   delete_from_server (true/false)
	 *   default_tracker (<empty>=reject new tickets|TrackerID)
	 *   unrecognized_mails (ignore/delete/forward/default)
	 *   unrec_reply (0=Creator/1=Nobody)
	 *   unrec_mail (<empty>=ignore|UID)
	 *   forward_to
	 *   auto_reply (0=Never/1=New/2=Always)
	 *   reply_unknown (1=Yes/0=No)
	 *   reply_text (text message)
	 *   bounces (ignore/delete/forward)
	 *   autoreplies (ignore/delete/forward/process)
	 *
	 * @var array
	 */
	var $mailhandling = array();
	/**
	 * Supported server types for mail handling as an array of arrays with spec => descr
	 *
	 * @var array
	 */
	var $mailservertypes = array(
		0 => array('imap/notls', 'Standard IMAP'),
		1 => array('imap/tls', 'IMAP, TLS secured'),
		2 => array('imap/ssl', 'IMAP, SSL secured'),
		3 => array('pop3', 'POP3'),
	);

	/**
	 * how to handle mailheaderinfo, provided as an array of arrays with spec => descr
	 *
	 * @var array
	 */
	var $mailheaderhandling = array(
		0 => array('noinfo', 'no, no additional Mailheader to description and comments'),
		1 => array('infotodesc', 'yes, add Mailheader to description'),
		2 => array('infotocomment', 'yes, add Mailheader to comments'),
		3 => array('infotoboth', 'yes, add Mailheader to both (description and comments)'),
	);

	/**
	 * Translates field / acl-names to labels
	 *
	 * @var array
	 */
	var $field2label = array(
		'tr_summary'     => 'Summary',
		'tr_tracker'     => 'Tracker',
		'cat_id'         => 'Category',
		'tr_version'     => 'Version',
		'tr_status'      => 'Status',
		'tr_description' => 'Description',
		'tr_assigned'    => 'Assigned to',
		'tr_private'     => 'Private',
//		'tr_budget'      => 'Budget',
		'tr_resolution'  => 'Resolution',
		'tr_completion'  => 'Completed',
		'tr_priority'    => 'Priority',
		'tr_startdate'   => 'Start date',
		'tr_duedate'     => 'Due date',
		'tr_closed'      => 'Closed',
		'tr_creator'     => 'Created by',
		'tr_created'     => 'Created on',
		'tr_group'		 => 'Group',
		// pseudo fields used in edit
		'link_to'        => 'Attachments & Links',
		'canned_response' => 'Canned response',
		'reply_message'  => 'Add comment',
		'add'            => 'Add',
		'vote'           => 'Vote for it!',
		'no_notifications'	=> 'No notifications',
		'bounty'         => 'Set bounty',
		'tr_cc'			 => 'CC',
		'num_replies'    => 'Number of replies',
		'customfields'   => 'Custom fields',
	);
	/**
	 * Translate field-name to 2-char history status
	 *
	 * @var array
	 */
	var $field2history = array(
		'tr_summary'     => 'Su',
		'tr_tracker'     => 'Tr',
		'cat_id'         => 'Ca',
		'tr_version'     => 'Ve',
		'tr_status'      => 'St',
		'tr_description' => 'De',
		'tr_creator'     => 'Cr',
		'tr_assigned'    => 'As',
		'tr_private'     => 'pr',
//		'tr_budget'      => 'Bu',
		'tr_completion'  => 'Co',
		'tr_priority'    => 'Pr',
		'tr_startdate'   => 'tr_startdate',
		'tr_duedate'     => 'tr_duedate',
		'tr_closed'      => 'Cl',
		'tr_resolution'  => 'Re',
		'tr_cc'			 => 'Cc',
		'tr_group'		 => 'Gr',
		// no need to track number of replies, as replies are versioned
		//'num_replies'    => 'Nr',
/* the following bounty-stati are only for reference
		'bounty-set'     => 'bo',
		'bounty-deleted' => 'xb',
		'bounty-confirmed'=> 'Bo',
*/
		// all custom fields together
		'customfields'	=> '#c',
	);
	/**
	 * Allow to assign tracker items to groups:  0=no; 1=yes, display groups+users; 2=yes, display users+groups
	 *
	 * @var int
	 */
	var $allow_assign_groups=1;
	/**
	 * Allow to vote on tracker items
	 *
	 * @var boolean
	 */
	var $allow_voting=true;
	/**
	 * How many days to mark a not responded item overdue
	 *
	 * @var int
	 */
	var $overdue_days=14;
	/**
	 * How many days to mark a pending item closed
	 *
	 * @var int
	 */
	var $pending_close_days=7;
	/**
	 * Permit html editing on details and comments
	 */
	var $htmledit = false;
	var $all_cats;
	var $historylog;
	/**
	 * Instance of the tracker_tracking object
	 *
	 * @var tracker_tracking
	 */
	var $tracking;
	/**
	 * Names of all config vars
	 *
	 * @var array
	 */
	var $config_names = array(
		'technicians','admins','users','notification','projects','priorities','restrictions',	// tracker specific
		'field_acl','allow_assign_groups','allow_voting','overdue_days','pending_close_days','htmledit','create_new_as_private','allow_assign_users','allow_infolog','allow_restricted_comments','mailhandling',	// tracker unspecific
		'allow_bounties','currency','enabled_queue_acl_access','exclude_app_on_timesheetcreation','show_dates'
	);
	/**
	 * Notification settings (tracker specific, keys: sender, link, copy, lang)
	 *
	 * @var array
	 */
	var $notification;
	/**
	 * Allow bounties to be set on tracker items
	 *
	 * @var string
	 */
	var $allow_bounties = true;
	/**
	 * Currency used by the bounties
	 *
	 * @var string
	 */
	var $currency = 'Euro';
	/**
	 * Filters to manage advanced logical statis
	 */
	var $filters = array(
		'closed'				=> '&#9830; Closed',
		'not-closed'				=> '&#9830; Not closed',
		'own-not-closed'			=> '&#9830; Own not closed',
		'without-reply-not-closed' 		=> '&#9830; Without reply not closed',
		'own-without-reply-not-closed' 		=> '&#9830; Own without reply not closed',
		'without-30-days-reply-not-closed'	=> '&#9830; Without 30 days reply not closed',
	);

	/**
	 * Filter for search limiting the date-range
	 *
	 * @var array
	 */
	var $date_filters = array(      // Start: year,month,day,week, End: year,month,day,week
		'Overdue'     => false,
		'Today'       => array(0,0,0,0,  0,0,1,0),
		'Yesterday'   => array(0,0,-1,0, 0,0,0,0),
		'This week'   => array(0,0,0,0,  0,0,0,1),
		'Last week'   => array(0,0,0,-1, 0,0,0,0),
		'This month'  => array(0,0,0,0,  0,1,0,0),
		'Last month'  => array(0,-1,0,0, 0,0,0,0),
		'Last 3 months' => array(0,-3,0,0, 0,0,0,0),
		'This quarter'=> array(0,0,0,0,  0,0,0,0),	// Just a marker, needs special handling
		'Last quarter'=> array(0,-4,0,0, 0,-4,0,0),	// Just a marker
		'This year'   => array(0,0,0,0,  1,0,0,0),
		'Last year'   => array(-1,0,0,0, 0,0,0,0),
		'2 years ago' => array(-2,0,0,0, -1,0,0,0),
		'3 years ago' => array(-3,0,0,0, -2,0,0,0),
	);

	/**
	 * Maximum number of line characters (-_+=~) allowed in a mail, to not stall the layout.
	 * Longer lines / biger number of these chars are truncated to that max. number or chars.
	 *
	 * @var int
	 */
	var $max_line_chars = 40;

	/**
	 * Constructor
	 *
	 * @return tracker_bo
	 */
	function __construct()
	{
		parent::__construct();

		$this->user = $GLOBALS['egw_info']['user']['account_id'];
		$this->today = mktime(0,0,0,date('m',$this->now),date('d',$this->now),date('Y',$this->now));

		$this->trackers = $this->get_tracker_labels();

		// read the tracker-configuration
		$this->load_config();
	}

	/**
	 * initializes data with the content of key
	 *
	 * Reimplemented to set some defaults
	 *
	 * @param array $keys=array() array with keys in form internalName => value
	 * @return array internal data after init
	 */
	function init($keys=array())
	{
		parent::init();

		if (is_array($this->trackers))	// init is called from so_sql::__construct(), where $this->trackers is NOT set
		{
			$this->data['tr_tracker'] = key($this->trackers);	// Need some tracker so creator rights are correct
		}
		$this->data['tr_creator'] = $GLOBALS['egw_info']['user']['account_id'];
		$this->data['tr_private'] = $this->create_new_as_private;
		$this->data['tr_group'] = $GLOBALS['egw_info']['user']['account_primary_group'];

		$this->data_merge($keys);

		return $this->data;
	}

	/**
	 * Changes the data from the db-format to your work-format
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 * @return array with changed data
	 */
	function db2data($data=null)
	{
		if (($intern = !is_array($data)))
 		{
 			$data =& $this->data;
 		}
		if (is_array($data['replies']))
		{
			foreach($data['replies'] as &$reply)
			{
				$reply['reply_servertime'] = $reply['reply_created'];
				$reply['reply_created'] = egw_time::server2user($reply['reply_created'],$this->timestamp_type);
			}
		}
		// check if item is overdue
		if ($this->overdue_days > 0)
		{
			$modified = $data['tr_modified'] ? $data['tr_modified'] : $data['tr_created'];
			$limit = $this->now - $this->overdue_days * 24*60*60;
			$data['overdue'] = !in_array($data['tr_status'],$this->get_tracker_stati(null,true)) && 	// only open items can be overdue
				(!$data['tr_modified'] || $data['tr_modifier'] == $data['tr_creator']) && $modified < $limit;

		}

		// Consider due date independent of overdue days
		$data['overdue'] |= ($data['tr_duedate'] && $this->now > $data['tr_duedate'] && !in_array($data['tr_status'], $this->get_tracker_stati(null,true)));

		if (is_numeric($data['tr_completion'])) $data['tr_completion'] .= '%';

		// Keep a copy of the timestamps in server time, so notifications can change them for each user
		foreach($this->timestamps as $field)
		{
			$data[$field . '_servertime'] = $data[$field];
		}

		// will run all regular timestamps ($this->timestamps) trough egw_time::server2user()
		return parent::db2data($intern ? null : $data);	// important to use null, if $intern!
	}

	/**
	 * Changes the data from your work-format to the db-format
	 *
	 * @param array $data if given works on that array and returns result, else works on internal data-array
	 * @return array with changed data
	 */
	function data2db($data=null)
	{
		if (($intern = !is_array($data)))
		{
			$data = &$this->data;
		}
		if (substr($data['tr_completion'],-1) == '%') $data['tr_completion'] = (int) round(substr($data['tr_completion'],0,-1));

		// will run all regular timestamps ($this->timestamps) trough egw_time::user2server()
		return parent::data2db($intern ? null : $data);	// important to use null, if $intern!
	}

	/**
	 * Read a tracker item
	 *
	 * Reimplemented to store the old status
	 *
	 * @param array $keys array with keys in form internalName => value, may be a scalar value if only one key
	 * @param string|array $extra_cols string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $join sql to do a join, added as is after the table-name, eg. ", table2 WHERE x=y" or
	 * @param int $user=null for which user to check, default current user
	 * @return array|boolean data if row could be retrived else False
	*/
	function read($keys,$extra_cols='',$join='',$user=null)
	{
		// Read restricted doesn't include assigned, it's not known yet
		$read_restricted = $this->is_admin(is_array($keys) ? $keys['tr_id'] : $keys, $user) ||
			$this->is_technician(is_array($keys) ? $keys['tr_id'] : $keys, $user);

		if (($ret = parent::read($keys, $extra_cols, $join, $read_restricted, $user)))
		{
			$this->data['old_status'] = $this->data['tr_status'];

			if ($this->deny_private()) $ret = $this->data = false;
		}
		return $ret;
	}

	/**
	 * saves the content of data to the db
	 *
	 * @param array $keys if given $keys are copied to data before saveing => allows a save as
	 * @param array $autoreply when called from the mailhandler, contains data for the autoreply
	 * (only for forwarding to tracling)
	 * @return int 0 on success and errno != 0 else
	 */
	function save($keys=null, $autoreply=null)
	{
		if ($keys) $this->data_merge($keys);

		if (!$this->data['tr_id'])	// new entry
		{
			// next 2 lines are to set / initialize new_resolution
			$resolutions = $this->get_tracker_labels('resolution',$this->data['tr_tracker']); // possible specific
			//error_log(__METHOD__.__LINE__.'->'.array2string($this->new_resolution));
			$this->data['tr_created'] = (isset($this->data['tr_created'])&&!empty($this->data['tr_created'])?$this->data['tr_created']:$this->now);
			$this->data['tr_creator'] = $this->data['tr_creator'] ? $this->data['tr_creator'] : $this->user;
			$this->data['tr_version'] = $this->data['tr_version'] ? $this->data['tr_version'] : $GLOBALS['egw_info']['user']['preferences']['tracker']['default_version'];
			$this->data['tr_status'] = $this->data['tr_status'] ? $this->data['tr_status'] : self::STATUS_OPEN;
			$this->data['tr_resolution'] = $this->data['tr_resolution'] ? $this->data['tr_resolution'] : ($this->new_resolution[$this->data['tr_tracker']]?$this->new_resolution[$this->data['tr_tracker']]:$this->new_resolution['all']);
			$this->data['tr_seen'] = serialize(array($this->user));

			if (!$this->data['tr_group'])
			{
				$this->data['tr_group'] = $GLOBALS['egw']->accounts->data['account_primary_group'];
			}

			if ($this->data['cat_id'] && !$this->data['tr_assigned'])
			{
				$this->autoassign();
			}
		}
		else
		{
			// check if we have a real modification
			// read the old record
			$new =& $this->data;
			unset($this->data);
			$this->read($new['tr_id']);
			$old =& $this->data;
			unset($this->data);
			$this->data =& $new;

			if (!is_object($this->tracking)) $this->tracking = new tracker_tracking($this);
			$changed = $this->tracking->changed_fields($new, $old);

			if (!$changed && !((isset($this->data['reply_message']) && !empty($this->data['reply_message'])) ||
				(isset($this->data['canned_response']) && !empty($this->data['canned_response']))))
			{
				//error_log(__METHOD__.__LINE__."  no change --> no save needed");
				return false;
			}
			// Check for modifying field without access
			$readonlys = $this->readonlys_from_acl();
			foreach($changed as $field)
			{
				if ($readonlys[$field])
				{
					//error_log(__METHOD__.__LINE__.' Field:'.$field.'->'.array2string($readonlys).function_backtrace());
					return $field;
				}
			}

			// Changes mark the ticket unseen for everbody but the current
			// user if the ticket wasn't closed at the same time
			if (!in_array($this->data['tr_status'],$this->get_tracker_stati(null, true)))
			{
				$seen = array();
				$this->data['tr_seen'] = unserialize($this->data['tr_seen']);

				// This only matters if no other changes have been made
				if($this->data['reply_visible'] && empty($changed))
				{
					// Keep those that can't see the comment
					$seen = array_intersect($this->data['tr_seen'], array_keys(array_diff(
						$this->get_staff($this->data['tracker_id'], 2, 'users'),
						$this->get_staff($this->data['tracker_id'], 2, 'technicians')
					)));
				}
				$seen[] = $this->user;
				$this->data['tr_seen'] = serialize($seen);
			}
			$this->data['tr_modified'] = $this->now;
			$this->data['tr_modifier'] = $this->user;
			$changed[] = 'tr_modified';

			// set close-date if status is closed and not yet set
			if (in_array($this->data['tr_status'],$this->get_tracker_stati(null, true)) && is_null($this->data['tr_closed']))
			{
				$this->data['tr_closed'] = $this->now;
				$changed[] = 'tr_closed';
			}
			// unset closed date, if item is re-opend
			if (!in_array($this->data['tr_status'],$this->get_tracker_stati(null, true)) && !is_null($this->data['tr_closed']))
			{
				$this->data['tr_closed'] = null;
				$changed[] = 'tr_closed';
			}
			if ($this->data['reply_message'] || $this->data['canned_response'])
			{
				if ($this->data['canned_response'])
				{
					$this->data['reply_message'] = $this->get_canned_response($this->data['canned_response']).
						($this->data['reply_message'] ? "\n\n".$this->data['reply_message'] : '');
				}
				$this->data['reply_created'] = (isset($this->data['reply_created'])&&!empty($this->data['reply_created'])?$this->data['reply_created']:$this->now);
				$this->data['reply_creator'] = $this->user;

				// replies set status pending back to open
				if ($this->data['old_status'] == self::STATUS_PENDING && $this->data['old_status'] == $this->data['tr_status'])
				{
					$this->data['tr_status'] = self::STATUS_OPEN;
				}
			}

			// Reset escalation flags on variable fields (comment, modified, etc.)
			$esc = new tracker_escalations();
			$esc->reset($this->data, $changed);
		}
		if (!($err = parent::save()))
		{
			// create (and remove) links in custom fields
                        customfields_widget::update_customfield_links('tracker',$this->data,$old,'tr_id');

			// so other apps can update eg. their titles and the cached title gets unset
			egw_link::notify_update('tracker',$this->data['tr_id'],$this->data);

			if (!is_object($this->tracking))
			{
				$this->tracking = new tracker_tracking($this);
			}
			if($this->prefs['notify_own_modification'])
			{
				$this->tracking->notify_current_user = true;
			}
			$this->tracking->html_content_allow = true;
			if (!$this->tracking->track($this->data,$old,$this->user,null,null,$this->data['no_notifications']))
			{
				return implode(', ',$this->tracking->errors);
			}
			if ($autoreply)
			{
				$this->tracking->autoreply($this->data,$autoreply,$old);
			}
		}
		return $err;
	}

	/**
	 * Get a list of all groups
	 *
	 * @param boolean $primary=false, when not ACL to change the group, return primary group only on new tickets
	 * @return array with gid => group-name pairs
	 */
	function &get_groups($primary=false)
	{
		static $groups;
		static $primary_group;

		if($primary)
		{
			if (isset($primary_group))
			{
				return $primary_group;
			}
		}
		else
		{
			if(isset($groups))
			{
				return $groups;
			}
		}

		$groups = array();
		$primary_group = array();
		$group_list = $GLOBALS['egw']->accounts->search(array('type' => 'groups', 'order' => 'account_lid', 'sort' => 'ASC'));
		foreach($group_list as $gid)
		{
			$groups[$gid['account_id']] = $gid['account_lid'];
		}
		$primary_group[$GLOBALS['egw']->accounts->data['account_primary_group']] = $groups[$GLOBALS['egw']->accounts->data['account_primary_group']];

		return ($primary ? $primary_group : $groups);
	}

	/**
	 * Get the staff (technicians or admins) of a tracker
	 *
	 * @param int $tracker tracker-id or 0, 0 = staff of all trackers!
	 * @param int $return_groups=2 0=users, 1=groups+users, 2=users+groups
	 * @param string $what='technicians' technicians=technicians (incl. admins), admins=only admins, users=only users
	 * @return array with uid => user-name pairs
	 */
	function &get_staff($tracker,$return_groups=2,$what='technicians')
	{
		static $staff_cache;

		//echo "botracker::get_staff($tracker,$return_groups,$what)".function_backtrace()."<br>";
		//error_log(__METHOD__.__LINE__.array2string($tracker));
		// some caching
		if (!empty($tracker) && isset($staff_cache[$tracker]) && isset($staff_cache[$tracker][(int)$return_groups]) &&
			isset($staff_cache[$tracker][(int)$return_groups][$what]))
		{
			//echo "from cache"; _debug_array($staff_cache[$tracker][$return_groups][$what]);
			return $staff_cache[$tracker][(int)$return_groups][$what];
		}
		$staff = array();

		switch($what)
		{
			case 'users':
			case 'usersANDtechnicians':
				if (is_null($this->users) || $this->users==='NULL') $this->users = array();
				foreach($tracker ? array(0,$tracker) : array_keys($this->users) as $t)
				{
					if (is_array($this->users[$t])) $staff = array_merge($staff,$this->users[$t]);
				}
				if ($what == 'users') break;
			case 'technicians':
				if (is_null($this->technicians) || $this->technicians==='NULL') $this->technicians = array();
				foreach($tracker ? array(0,$tracker) : array_keys($this->technicians) as $t)
				{
					if (is_array($this->technicians[$t])) $staff = array_merge($staff,$this->technicians[$t]);
				}
				// fall through, as technicians include admins
			case 'admins':
				if (is_null($this->admins) || $this->admins==='NULL') $this->admins = array();
				foreach($tracker ? array(0,$tracker) : array_keys($this->admins) as $t)
				{
					if (is_array($this->admins[$t])) $staff = array_merge($staff,$this->admins[$t]);
				}
				break;
		}

		// split users and groups and resolve the groups into there users
		$users = $groups = array();
		foreach(array_unique($staff) as $uid)
		{
			if ($GLOBALS['egw']->accounts->get_type($uid) == 'g')
			{
				if ($return_groups) $groups[(string)$uid] = $GLOBALS['egw']->common->grab_owner_name($uid);
				foreach((array)$GLOBALS['egw']->accounts->members($uid,true) as $u)
				{
					if (!isset($users[$u])) $users[$u] = $GLOBALS['egw']->common->grab_owner_name($u);
				}
			}
			else // users
			{
				if (!isset($users[$uid])) $users[$uid] = $GLOBALS['egw']->common->grab_owner_name($uid);
			}
		}
		// sort alphabetic
		natcasesort($users);
		natcasesort($groups);

		// groups or users first
		$staff = $this->allow_assign_groups == 1 ? $groups : $users;

		if ($this->allow_assign_groups)	// do we need a second one
		{
			foreach($this->allow_assign_groups == 1 ? $users : $groups as $uid => $label)
			{
				$staff[$uid] = $label;
			}
		}
		//_debug_array($staff);
		return $staff_cache[$tracker][(int)$return_groups][$what] = $staff;
	}

	/**
	 * Check if a user (default current user) is an admin for the given tracker
	 *
	 * @param int $tracker ID of tracker
	 * @param int $user=null ID of user, default current user $this->user
	 * @return boolean
	 */
	function is_admin($tracker,$user=null)
	{
		if (is_null($user)) $user = $this->user;

		$admins =& $this->get_staff($tracker,0,'admins');

		return $this->user===0 || isset($admins[$user]); // this->user is set to 0 by close_pending
	}

	/**
	 * Check if a user (default current user) is an technichan for the given tracker
	 *
	 * @param int $tracker ID of tracker
	 * @param int $user=null ID of user, default current user $this->user
	 * @return boolean
	 */
	function is_technician($tracker,$user=null,$checkgroups=false)
	{
		if (is_null($user)) $user = $this->user;

		$technicians =& $this->get_staff($tracker,$checkgroups ? 2 : 0,'technicians');

		return isset($technicians[$user]);
	}

	/**
	 * Check if a user (default current user) is an user for the given tracker
	 *
	 * If queue ACL access is NOT enabled, we return is_tracker_user() (user is non-anonymous and has tracker run-rights)
	 *
	 * @param int $tracker ID of tracker
	 * @param int $user=null ID of user, default current user $this->user
	 * @return boolean
	 */
	function is_user($tracker,$user=null)
	{
		if (is_null($user)) $user = $this->user;

		$users =& $this->get_staff($tracker,0,'users');

		return isset($users[$user]);
	}

	/**
	 * Check if a user (default current user) is staff member for the given tracker
	 *
	 * @param int $tracker ID of tracker
	 * @param int $user=null ID of user, default current user $this->user
	 * @return boolean
	 */
	function is_staff($tracker,$user=null)
	{
		if (is_null($user)) $user = $this->user;

		return ($this->is_technician($tracker,$user) || $this->is_admin($tracker,$user));
	}

	/**
	 * Check if a user (default current user) is anonymous
	 *
	 * @param int $user=null ID of user, default current user $this->user
	 * @return boolean
	 */
	function is_anonymous($user=null)
	{
		static $cache = array();	// some caching to not read acl multiple times from the database ($user != $this->user)

		if (!$user) $user = $this->user;

		$anonymous =& $cache[$user];

		if (!isset($anonymous))
		{
			if ($user == $this->user)
			{
				$anonymous = $GLOBALS['egw']->acl->check('anonymous',1,'phpgwapi');
			}
			else
			{
				$rights = $GLOBALS['egw']->acl->get_all_location_rights($user,'phpgwapi',$use_memberships=false);
				$anonymous = (boolean)$rights['anonymous'];
			}
		}
		return $anonymous;
	}

	/**
	 * Check if a user (default current user) is a non-anoymous user with run-rights for tracker
	 *
	 * @param int $user=null ID of user, default current user $this->user
	 * @return boolean
	 */
	function is_tracker_user($user=null)
	{
		static $cache = array();	// some caching to not read acl multiple times from the database ($user != $this->user)

		if (is_null($user)) $user = $this->user;

		$is_user =& $cache[$user];

		if (!isset($is_user))
		{
			if ($this->is_anonymous($user))
			{
				$reason = 'anonymous';
				$is_user = false;
			}
			elseif ($user == $this->user)
			{
				$is_user = isset($GLOBALS['egw_info']['user']['apps']['tracker']);
				$reason = 'egw_info[user][apps][tracker] is '.(!$is_user ? 'NOT ' : '').'set';
			}
			else
			{
				$rights = $GLOBALS['egw']->acl->get_all_location_rights($user,'tracker',$use_memberships=true);
				$is_user = (boolean)$rights['run'];
				$reason = 'has '.(!$is_user ? 'NO' : '').'run rights';
			}
		}
		//error_log(__METHOD__."($user) this->user=$this->user returning ($reason) ".array2string($is_user));
		return $is_user;
	}

	/**
	 * Check if given or current ticket is private and user is not creator, assignee or admin
	 *
	 * @param array $data=null array with ticket or null for $this->data
	 * @param int $user=null account_id or null for current user
	 * @return boolean true = deny access to private ticket, false grant access (ticket not private or access allowed)
	 */
	function deny_private(array $data=null,$user=null)
	{
		if (!$user) $user = $this->user;
		if (!$data) $data = $this->data;

		return $data['tr_private'] && !($user == $data['tr_creator'] || $this->is_admin($data['tr_tracker'],$user) ||
			$data['tr_assigned'] && in_array($user,$data['tr_assigned']));
	}

	/**
	 * Check what rights the current user has on a given or the current tracker item ($this->data) or a given tracker
	 *
	 * @param int $needed or'ed together: TRACKER_ADMIN|TRACKER_TECHNICIAN|TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE
	 * @param int $check_only_tracker=null should only the given tracker be checked and NO $this->data specific checks be performed, default no
	 * @param int|array $data=null array with tracker item, integer tr_id or default null for loaded tracker item ($this->tracker)
	 * @param int $user=null for which user to check, default current user
	 * @param string $name=null something to put in error_log
	 * @return boolean true if user has the $needed rights, false otherwise
	 */
	function check_rights($needed,$check_only_tracker=null,$data=null,$user=null,$name=null)
	{
		if (!$user) $user = $this->user;

		if (!$data)
		{
			$data = $this->data;
		}
		elseif(!is_array($data))
		{
			$backup = $this->data;
			if (!($data = $this->read(array('tr_id' => $data))))
			{
				$access = false;
				$line = __LINE__;
			}
			$this->data = $backup;
		}
		$tracker = $check_only_tracker ? $check_only_tracker : $data['tr_tracker'];

		if (isset($access))
		{
			// nothing to do, already set
		}
		elseif (!$needed)
		{
			$access = false;
			$line = __LINE__;
		}
		// private tickets are only visible to creator, assignee and admins
		elseif(!$check_only_tracker && $this->deny_private($data,$user))
		{
			$access = false;
			$line = __LINE__.' (private)';
		}
		elseif ($needed & TRACKER_EVERYBODY)
		{
			$access = true;
			$line = __LINE__;
		}
		// item creator
		elseif (!$check_only_tracker && $needed & TRACKER_ITEM_CREATOR && $user == $data['tr_creator'])
		{
			$access = true;
			$line = __LINE__;
		}
		// item group
		elseif (!$check_only_tracker && $needed & TRACKER_ITEM_GROUP &&
			($memberships = $GLOBALS['egw']->accounts->memberships($user,true)) && in_array($data['tr_group'],$memberships))
		{
			$access = true;
			$line = __LINE__;
		}
		// tracker user
		elseif ($needed & TRACKER_USER && $this->is_tracker_user($user))
		{
			$access = true;
			$line = __LINE__;
		}
		// tracker admins and technicians
		elseif ($tracker)
		{
			if ($needed & TRACKER_ADMIN && $this->is_admin($tracker,$user))
			{
				$access = true;
				$line = __LINE__;
			}
			elseif ($needed & TRACKER_TECHNICIAN && $this->is_technician($tracker,$user))
			{
				$access = true;
				$line = __LINE__;
			}
		}
		if (isset($access))
		{
			// nothing to do, already set
		}
		// new items: everyone is the owner of new items
		elseif (!$check_only_tracker && !$data['tr_id'])
		{
			$access = !!($needed & (TRACKER_ITEM_CREATOR|TRACKER_ITEM_NEW));
			$line = __LINE__;
		}
		// assignee
		elseif (!$check_only_tracker && ($needed & TRACKER_ITEM_ASSIGNEE) && $data['tr_assigned'])
		{
			foreach((array)$data['tr_assigned'] as $assignee)
			{
				if ($user == $assignee)
				{
					$access = true;
					$line = __LINE__;
					break;
				}
				// group assinged
				if ($this->allow_assign_groups && $assignee < 0)
				{
					if (($members = $GLOBALS['egw']->accounts->members($assignee,true)) && in_array($user,$members))
					{
						$access = true;
						$line = __LINE__;
						break;
					}
				}
			}
		}
		if (!isset($access))
		{
			$access = false;
			$line = __LINE__;
		}
		//error_log(__METHOD__."($needed, $check_only_tracker, tr_id=$data[tr_id], user=$user) '$name' returning in $line ".array2string($access).(!$needed ? ': '.function_backtrace() : ''));
		return $access;
	}

	/**
	 * Check access to the file store
	 *
	 * We need to map Tracker ACL to read or write access of the filestore:
	 * - read access: a non-anonymous Tracker user, plus beeing able to read the tracker item
	 * - write access: user is allowed to upload files or link with other entries
	 *
	 * @param int|array $id id of entry or entry array
	 * @param int $check EGW_ACL_READ for read and EGW_ACL_EDIT for write or delete access
	 * @param string $rel_path=null currently not used in Tracker
	 * @param int $user=null for which user to check, default current user
	 * @return boolean true if access is granted or false otherwise
	 */
	function file_access($id,$check,$rel_path=null,$user=null)
	{
		static $cache = array();	// as tracker does NOT cache read items, we run a cache here to not query items multiple times

		if (!$user) $user = $this->user;

		if (!is_array($id))
		{
			$access =& $cache[$user][(int)$id][$check];
		}
		if (!isset($access))
		{
			$needed = $check == EGW_ACL_READ ? TRACKER_USER : $this->field_acl['link_to'];
			$name = 'file_access '.($check == EGW_ACL_READ ? 'read' : 'write');

			$access = $this->check_rights($needed,null,$id,$user,$name);
		}
		//error_log(__METHOD__."($id,$check,'$rel_path',$user) returning ".array2string($access));
		return $access;
	}

	/**
	 * Check if users is allowed to vote and has not already voted
	 *
	 * @param int $tr_id=null tracker-id, default current tracker-item ($this->data)
	 * @return int|boolean true for no rights, timestamp voted or null
	 */
	function check_vote($tr_id=null)
	{
		if (is_null($tr_id)) $tr_id = $this->data['tr_id'];

		if (!$tr_id || !$this->check_rights($this->field_acl['vote'],null,null,null,'vote')) return true;

		if ($this->is_anonymous())
		{
			$ip = $_SERVER['REMOTE_ADDR'].(isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? ':'.$_SERVER['HTTP_X_FORWARDED_FOR'] : '');
		}
		if (($time = parent::check_vote($tr_id,$this->user,$ip)))
		{
			$time += $this->tz_offset_s;
		}
		return $time;
	}

	/**
	 * Cast vote for given tracker-item
	 *
	 * @param int $tr_id=null tracker-id, default current tracker-item ($this->data)
	 * @return boolean true=vote casted, false=already voted before
	 */
	function cast_vote($tr_id=null)
	{
		if (is_null($tr_id)) $tr_id = $this->data['tr_id'];

		if ($this->check_vote($tr_id)) return false;

		$ip = $_SERVER['REMOTE_ADDR'].(isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? ':'.$_SERVER['HTTP_X_FORWARDED_FOR'] : '');

		return parent::cast_vote($tr_id,$this->user,$ip);
	}

	/**
	 * Get tracker specific labels: tracker, version, categorie
	 *
	 * The labels are saved as categories and can be tracker specific (sub-cat of the tracker) or for all trackers.
	 * The "cat_data" column stores if a tracker-cat is a "tracker", "version", "cat" or empty
	 *
	 * @param string $type='trackers' 'tracker', 'version', 'cat', 'resolution'
	 * @param int $tracker=null tracker to use or null to use $this->data['tr_tracker']
	 */
	function get_tracker_labels($type='tracker',$tracker=null)
	{
		if (is_null($this->all_cats))
		{
			if (!isset($GLOBALS['egw']->categories))
			{
				$GLOBALS['egw']->categories = new categories($this->user,'tracker');
			}
			if (isset($GLOBALS['egw']->categories) && $GLOBALS['egw']->categories->app_name == 'tracker')
			{
				$cats = $GLOBALS['egw']->categories;
			}
			else
			{
				$cats = new categories($this->user,'tracker');
			}
			$this->all_cats = $cats->return_array('all',0,false);
			if (!is_array($this->all_cats)) $this->all_cats = array();
			//_debug_array($this->all_cats);
		}
		if (!$tracker) $tracker = $this->data['tr_tracker'];

		$labels = array();
		$new_resolution = false;
		foreach($this->all_cats as $cat)
		{
			$cat_data = unserialize($cat['data']);
			$cat_type = isset($cat_data['type']) ? $cat_data['type'] : 'cat';
			if ($cat_type == $type && ($cat['parent'] == 0 || $cat['main'] == $tracker && $cat['id'] != $tracker))
			{
				$labels[$cat['id']] = $cat['name'];
				if ($cat_type=='resolution' && $cat_data['isdefault']) $new_resolution[(!empty($tracker) && $cat['main'] == $tracker?$tracker:'all')] = $cat['id'];
			}
		}
		if ($new_resolution) config::save_value('new_resolution',$new_resolution,'tracker');
		$this->load_config();

		if ($type == 'tracker' && !$GLOBALS['egw_info']['user']['apps']['admin'] && $this->enabled_queue_acl_access)
		{
			foreach ($labels as $tracker_id => $tracker_name)
			{
				if (!$this->is_user($tracker_id,$this->user) && !$this->is_technician($tracker_id,$this->user) && !$this->is_admin($tracker_id,$this->user))
				{
					unset($labels[$tracker_id]);
				}
			}
		}

		natcasesort($labels);

		//echo "botracker::get_tracker_labels('$type',$tracker)"; _debug_array($labels);
		return $labels;
	}

	/**
	 * Get tracker specific stati
	 *
	 * There's a bunch of pre-defined stati, plus statis stored as labels, which can be per tracker
	 *
	 * @param int $tracker=null tracker to use of null to use $this->data['tr_tracker']
	 * @param boolean $closed True to get 'closed' stati, false to get open stati, null for all
	 */
	function get_tracker_stati($tracker=null, $closed = null)
	{
		$stati = self::$stati + $this->get_tracker_labels('stati',$tracker);
		if($closed === null) return $stati;

		$filtered = (!$closed ? array(self::STATUS_OPEN    => 'Open(status)') : array(self::STATUS_CLOSED  => 'Closed'));

		foreach($stati as $id => $name)
		{
			if($id > 0 && $data = $GLOBALS['egw']->categories->id2name($id,'data'))
			{
				if($closed == $data['closed']) $filtered[$id] = $name;
			}
		}
		return $filtered;
	}

	/**
	 * Get tracker and category specific priorities
	 *
	 * Currently priorities are a fixed list with numeric values from 1 to 9 as keys and customizable labels
	 *
	 * @param int $tracker=null tracker to use or null to use tracker unspecific priorities
	 * @param int $cat_id=null category to use or null to use categorie unspecific priorities
	 * @param boolean $remove_empty=true should empty labels be displayed, default no
	 * @return array
	 */
	function get_tracker_priorities($tracker=null,$cat_id=null,$remove_empty=true)
	{
		if (isset($this->priorities[$tracker.'-'.$cat_id]))
		{
			$prios = $this->priorities[$tracker.'-'.$cat_id];
		}
		elseif (isset($this->priorities[$tracker]))
		{
			$prios = $this->priorities[$tracker];
		}
		elseif (isset($this->priorities['0-'.$cat_id]))
		{
			$prios = $this->priorities['0-'.$cat_id];
		}
		elseif(isset($this->priorities[0]))
		{
			$prios = $this->priorities[0];
		}
		else
		{
			$prios = self::$stock_priorities;
		}
		if ($remove_empty)
		{
			foreach($prios as $key => $val)
			{
				if ($val === '') unset($prios[$key]);
			}
		}
		//echo "<p>".__METHOD__."(tracker=$tracker,$remove_empty) prios=".array2string($prios)."</p>\n";
		return $prios;
	}

	/**
	 * Check if the given tracker uses category specific priorities and eg. need to reload of user changes the cat
	 *
	 * @param int $tracker
	 * @return boolean
	 */
	function tracker_has_cat_specific_priorities($tracker)
	{
		if (!$this->priorities) return false;

		$prefix = (int)$tracker.'-';
		$len = strlen($prefix);
		foreach(array_keys($this->priorities) as $key)
		{
			if (substr($key,0,$len) == $prefix || substr($key,0,2) == '0-') return true;
		}
		return false;
	}

	/**
	 * Reload the labels (tracker, cats, versions, projects)
	 *
	 */
	function reload_labels()
	{
		unset($this->all_cats);
		$this->trackers = $this->get_tracker_labels();
	}

	/**
	 * Get the canned response via it's id
	 *
	 * Canned responses are now saved in the the data array, as the description is limited to 255 chars, which is to small.
	 *
	 * @param int $id
	 * @return string|boolean string with the response or false if id not found
	 */
	function get_canned_response($id)
	{
		foreach($this->all_cats as $cat)
		{
			if (($data = unserialize($cat['data'])) && $data['type'] == 'response' && $cat['id'] == $id)
			{
				return $data['response'] ? $data['response'] : $cat['description'];
			}
		}
		return false;
	}

	/**
	 * Try to autoassign to a new tracker item
	 *
	 * @return int|boolean account_id or false
	 */
	function autoassign()
	{
		foreach($this->all_cats as $cat)
		{
			if ($cat['id'] == $this->data['cat_id'])
			{
				$data = unserialize($cat['data']);
				$user = $data['autoassign'];

				if ($user && $this->is_technician($this->data['tr_tracker'],$user,true))
				{
					return $this->data['tr_assigned'] = $user;
				}
			}
		}
		return false;
	}

	/**
	 * get title for an tracker item identified by $entry
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param int|array $entry int ts_id or array with tracker item
	 * @return string|boolean string with title, null if tracker item not found, false if no perms to view it
	 */
	function link_title( $entry )
	{
		if (!is_array($entry))
		{
			$entry = $this->read( $entry );
		}
		if (!$entry)
		{
			return $entry;
		}
		return $this->trackers[$entry['tr_tracker']].' #'.$entry['tr_id'].': '.$entry['tr_summary'];
	}

	/**
	 * get titles for multiple tracker items
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param array $ids array with tracker id's
	 * @return array with titles, see link_title
	 */
	function link_titles( $ids )
	{
		$titles = array();
		if (($tickets = $this->search(array('tr_id' => $ids),'tr_id,tr_tracker,tr_summary')))
		{
			foreach($tickets as $ticket)
			{
				$titles[$ticket['tr_id']] = $this->link_title($ticket);
			}
		}
		// we assume all not returned tickets are not readable by the user, as we notify egw_link about each deleted ticket
		foreach($ids as $id)
		{
			if (!isset($titles[$id])) $titles[$id] = false;
		}
		return $titles;
	}

	/**
	 * query tracker for entries matching $pattern, we search only open entries
	 *
	 * Is called as hook to participate in the linking
	 *
	 * @param string $pattern pattern to search
	 * @param array $options Array of options for the search
	 * @return array with ts_id - title pairs of the matching entries
	 */
	function link_query( $pattern, Array &$options = array() )
	{
		$limit = false;
		$result = array();
		if($options['start'] || $options['num_rows']) {
			$limit = array($options['start'], $options['num_rows']);
		}
		foreach((array) $this->search($pattern,false,'tr_summary ASC','','%',false,'OR',$limit,array('tr_status != '. self::STATUS_DELETED)) as $item )
		{
			if ($item) $result[$item['tr_id']] = $this->link_title($item);
		}
		$options['total'] = $this->total;
		return $result;
	}

	/**
	 * query rows for the nextmatch widget
	 *
	 * @param array $query with keys 'start', 'search', 'order', 'sort', 'col_filter'
	 *	For other keys like 'filter', 'cat_id' you have to reimplement this method in a derived class.
	 * @param array &$rows returned rows/competitions
	 * @param array &$readonlys eg. to disable buttons based on acl, not use here, maybe in a derived class
	 * @param string $join='' sql to do a join, added as is after the table-name, eg. ", table2 WHERE x=y" or
	 *	"LEFT JOIN table2 ON (x=y)", Note: there's no quoting done on $join!
	 * @param boolean $need_full_no_count=false If true an unlimited query is run to determine the total number of rows, default false
	 * @return int total number of rows
	 */
	function get_rows(&$query,&$rows,&$readonlys,$join=true,$need_full_no_count=false)
	{
		if($query['filter'])
		{
			$query['col_filter'][] = $this->date_filter($query['filter'],$query['startdate'],$query['enddate']);
		}
		return parent::get_rows($query,$rows,$readonlys,$join,$need_full_no_count);
	}

	/**
	 * Add a new tracker-queue
	 *
	 * @param string $name
	 * @return int|boolean integer tracker-id on success or false otherwise
	 */
	function add_tracker($name)
	{
		$cats = new categories(categories::GLOBAL_ACCOUNT,'tracker');	// global cat!
		if ($name && ($id = $cats->add(array(
			'name'   => $name,
			'descr'  => 'tracker',
			'data'   => serialize(array('type' => 'tracker')),
			'access' => 'public',
		))))
		{
			$this->trackers[$id] = $name;

			// Update cf type list
			$types = config::get_content_types('tracker');
			$types[$id] = array('name' => $name, 'non_deletable' => true);
			config::save_value('types',$types, 'tracker');

			return $id;
		}
		return false;
	}

	/**
	 * Rename a tracker-queue
	 *
	 * @param int $tracker
	 * @param string $name
	 * @return boolean true on success or false otherwise
	 */
	function rename_tracker($tracker,$name)
	{
		$cats = new categories(categories::GLOBAL_ACCOUNT,'tracker');
		if ($tracker > 0 && !empty($name) && ($data = $cats->read($tracker)))
		{
			if ($data['name'] != $name)
			{
				$data['name'] = $this->trackers[$tracker] = $name;
				$cats->edit($data);

				// Update cf type list
				$types = config::get_content_types('tracker');
				$types[$tracker]['name'] = $name;
				config::save_value('types',$types, 'tracker');
			}
			return true;
		}
		return false;
	}

	/**
	 * Delete a tracker include all items, categories, staff, ...
	 *
	 * @param int $tracker
	 * @return boolean true on success, false otherwise
	 */
	function delete_tracker($tracker)
	{
		if (!$tracker) return false;

		if (!is_object($this->historylog))
		{
			$this->historylog =& CreateObject('phpgwapi.historylog','tracker');
		}
		$ids = $this->query_list($this->table_name.'.tr_id','',array('tr_tracker' => $tracker));
		if ($ids) $this->historylog->delete($ids);

		$GLOBALS['egw']->categories->delete($tracker,true);

		// Update cf type list
		$types = config::get_content_types('tracker');
		unset($types[$tracker]);
		config::save_value('types',$types, 'tracker');

		$this->reload_labels();
		unset($this->admins[$tracker]);
		unset($this->technicians[$tracker]);
		unset($this->users[$tracker]);
		$this->mailhandling[$tracker]['interval'] = 0; // Cancel async job
		$this->delete(array('tr_tracker' => $tracker));
		$this->save_config();

		return true;
	}

	/**
	 * Save the tracker configuration stored in various class-vars
	 */
	function save_config()
	{
		foreach($this->config_names as $name)
		{
			#echo "<p>calling config::save_value('$name','{$this->$name}','tracker')</p>\n";
			config::save_value($name,$this->$name,'tracker');
		}
		self::set_async_job($this->pending_close_days > 0);

		$mailhandler = new tracker_mailhandler();
		foreach((array)$this->mailhandling as $queue_id => $handling) {
			$mailhandler->set_async_job($queue_id, $handling['interval']);
		}
	}

	/**
	 * Load the tracker config into various class-vars
	 *
	 */
	function load_config()
	{
		$migrate_config = false;	// update old config-values, can be removed soon
		foreach((array)config::read('tracker') as $name => $value)
		{
			if (substr($name,0,13) == 'notification_')	// update old config-values, can be removed soon
			{
				$this->notification[0][substr($name,13)] = $value;
				config::save_value($name,null,'tracker');
				$migrate_config = true;
				continue;
			}
			$this->$name = $value;
		}
		if ($migrate_config)	// update old config-values, can be removed soon
		{
			foreach($this->notification as $name => $value)
			{
				config::save_value($name,$value,'tracker');
			}
		}

		if(!$this->new_resolution)
		{
			$categories = new categories(null, 'tracker');
			$new_resolution = $categories->name2id('None');
			if ($new_resolution)
			{
				$this->new_resolution['all'] = $new_resolution;
				config::save_value('new_resolution',$this->new_resolution,'tracker');
			}
		}

		if (is_array($this->notification) && !$this->notification[0]['lang'])
		{
			$this->notification[0]['lang'] = $GLOBALS['egw']->preferences->default_prefs('common', 'lang');
		}
		foreach(array(
			'tr_summary'     => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_tracker'     => TRACKER_ITEM_NEW|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'cat_id'         => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_version'     => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_status'      => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_description' => TRACKER_ITEM_NEW,
			'tr_creator'     => TRACKER_ADMIN,
			'tr_assigned'    => TRACKER_ITEM_CREATOR|TRACKER_ADMIN,
			'tr_private'     => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_budget'      => TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_resolution'  => TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_completion'  => TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_priority'    => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_startdate'   => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_duedate'     => TRACKER_ITEM_CREATOR|TRACKER_ADMIN,
			'tr_cc'			 => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'tr_group'		 => TRACKER_TECHNICIAN|TRACKER_ADMIN,
			'customfields'   => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			// set automatic by botracker::save()
			'tr_id'          => 0,
			'tr_created'     => 0,
			'tr_modifier'    => 0,
			'tr_modified'    => 0,
			'tr_closed'      => 0,
			// pseudo fields used in edit
			'link_to'        => TRACKER_ITEM_CREATOR|TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'canned_response' => TRACKER_ITEM_ASSIGNEE|TRACKER_ADMIN,
			'reply_message'  => TRACKER_USER,
			'add'            => TRACKER_USER,
			'vote'           => TRACKER_EVERYBODY,	// TRACKER_USER for NO anon user
			'bounty'         => TRACKER_EVERYBODY,
			'no_notifications'	=> TRACKER_ITEM_ASSIGNEE|TRACKER_TECHNICIAN|TRACKER_ADMIN,
		) as $name => $value)
		{
			if (!isset($this->field_acl[$name])) $this->field_acl[$name] = $value;
		}

		// Add date filters if using start/due dates
		if($this->show_dates)
		{
			$this->date_filters = array(
				'started'  => false,
				'upcoming' => false
			) + $this->date_filters;
		}
	}

	/**
	 * Check if exist and if not start or stop an async job to close pending items
	 *
	 * @param boolean $start=true true=start, false=stop
	 */
	static function set_async_job($start=true)
	{
		//echo '<p>'.__METHOD__.'('.($start?'true':'false').")</p>\n";

		$async = new asyncservice();

		if ($start === !$async->read('tracker-close-pending'))
		{
			if ($start)
			{
				$async->set_timer(array('hour' => '*'),'tracker-close-pending','tracker.tracker_bo.close_pending',null);
			}
			else
			{
				$async->cancel_timer('tracker-close-pending');
			}
		}
	}

	/**
	 * Close pending tracker items, which are not answered withing $this->pending_close_days days
	 */
	function close_pending()
	{
		$this->user = 0;	// we dont want to run under the id of the current or the user created the async job

		if (($ids = $this->query_list('tr_id','tr_id',array(
			'tr_status' => self::STATUS_PENDING,
			'tr_modified < '.(time()-$this->pending_close_days*24*60*60),
		))))
		{
			if (($default_lang = $GLOBALS['egw']->preferences->default_prefs('common','lang')) &&	// load the system default language
				translation::$userlang != $default_lang)
			{
				$save_lang = $GLOBALS['egw_info']['user']['preferences']['common']['lang'];
				$GLOBALS['egw_info']['user']['preferences']['common']['lang'] = $default_lang;
				translation::init();
			}
			translation::add_app('tracker');

			foreach($ids as $tr_id)
			{
				if ($this->read($tr_id))
				{
					$this->data['tr_status'] = self::STATUS_CLOSED;
					$this->data['reply_message'] = lang('This Tracker item was closed automatically by the system. It was previously set to a Pending status, and the original submitter did not respond within %1 days.',$this->pending_close_days);
					$this->save();
				}
			}
			if ($save_lang)
			{
				$GLOBALS['egw_info']['user']['preferences']['common']['lang'] = $save_lang;
				translation::init();
			}
		}
	}

	/**
	 * Read bounties specified by the given keys
	 *
	 * Reimplement to convert to user-time
	 *
	 * @param array|int $keys array with key(s) or integer bounty-id
	 * @return array with bounties
	 */
	function read_bounties($keys)
	{
		if (!$this->allow_bounties) return array();

		if (($bounties = parent::read_bounties($keys)))
		{
			foreach($bounties as $n => $bounty)
			{
				foreach(array('bounty_created','bounty_confirmed') as $name)
				{
					if ($bounty[$name]) $bounties[$n][$name] += $this->tz_offset_s;
				}
			}
		}
		return $bounties;
	}

	/**
	 * Save or update a bounty
	 *
	 * @param array &$data
	 * @return int|boolean integer bounty_id or false on error
	 */
	function save_bounty(&$data)
	{
		if (!$this->allow_bounties) return false;

		if (($new = !$data['bounty_id']))	// new bounty
		{
			if (!$data['bounty_amount'] || !$data['bounty_name'] || !$data['bounty_email']) return false;

			$data['bounty_creator'] = $this->user;
			$data['bounty_created'] = $this->now;
			if (!$data['tr_id']) $data['tr_id'] = $this->data['tr_id'];
		}
		else
		{
			if (!$this->is_admin($this->data['tr_tracker']) ||
				!($bounties = $this->read_bounties(array('bounty_id' => $data['bounty_id']))))
			{
				return false;
			}
			$old = $bounties[0];

			$data['bounty_confirmer'] = $this->user;
			$data['bounty_confirmed'] = $this->now;
		}
		// convert to server-time
		foreach(array('bounty_created','bounty_confirmed') as $name)
		{
			if ($data[$name]) $data[$name] -= $this->tz_offset_s;
		}
		if (($data['bounty_id'] = parent::save_bounty($data)))
		{
			$this->_bounty2history($data,$old);
		}
		// convert back to user-time
		foreach(array('bounty_created','bounty_confirmed') as $name)
		{
			if ($data[$name]) $data[$name] += $this->tz_offset_s;
		}
		return $data['bounty_id'];
	}

	/**
	 * Delete a bounty, the bounty must not be confirmed and you must be an tracker-admin!
	 *
	 * @param int $bounty_id
	 * @return boolean true on success or false otherwise
	 */
	function delete_bounty($id)
	{
		//echo "<p>botracker::delete_bounty($id)</p>\n";
		if (!($bounties = $this->read_bounties(array('bounty_id' => $id))) ||
			$bounties[0]['bounty_confirmed'] || !$this->is_admin($this->data['tr_tracker']))
		{
			return false;
		}
		if (parent::delete_bounty($id))
		{
			$this->_bounty2history(null,$bounties[0]);

			return true;
		}
		return false;
	}

	/**
	 * Historylog a bounty
	 *
	 * @internal
	 * @param array $new new value
	 * @param array $old=null old value
	 */
	function _bounty2history($new,$old=null)
	{
		if (!is_object($this->historylog))
		{
			$this->historylog =& CreateObject('phpgwapi.historylog','tracker');
		}
		if (is_null($new) && $old)
		{
			$status = 'xb';	// bounty deleted
		}
		elseif ($new['bounty_confirmed'])
		{
			$status = 'Bo';	// bounty confirmed
		}
		else
		{
			$status = 'bo';	// bounty set
		}
		$this->historylog->add($status,$this->data['tr_id'],$this->_serialize_bounty($new),$this->_serialize_bounty($old));
	}

	/**
	 * Serialize the bounty for the historylog
	 *
	 * @internal
	 * @param array $bounty
	 * @return string
	 */
	function _serialize_bounty($bounty)
	{
		return !is_array($bounty) ? $bounty : '#'.$bounty['bounty_id'].', '.$bounty['bounty_name'].' <'.$bounty['bounty_email'].
			'> ('.$GLOBALS['egw']->accounts->id2name($bounty['bounty_creator']).') '.
			$bounty['bounty_amount'].' '.$this->currency.($bounty['bounty_confirmed'] ? ' Ok' : '');
	}

	/**
	 * Try to extract a ticket number from a subject line
	 *
	 * @param string the subjectline from the incoming message, may be modified when we find some id, but not matching available trackers
	 * @return int ticket ID, or 0 of no ticket ID was recognized
	 */
	function get_ticketId(&$subj='')
	{
		if (empty($subj))
		{
			return 0; // Don't bother...
		}

		// The subject line is expected to be in the format:
		// [Re: |Fwd: |etc ]<Tracker name> #<id>: <Summary>
		// allow colon or dash to separate Id from summary, as our notifications use a dash (' - ') and not a colon (': ')
		preg_match_all("/(.*)( #[0-9]+:? ?-? )(.*)$/",$subj, $tr_data);
		if (!$tr_data[2])
		{
			return 0; //
		}
		if (strpos($tr_data[1][0],'#') !== false) // there is more than one part of the subject, that could be a tracker ID
		{
			// try once more, and modify the tr_data as we go for comparsion with tracker subject
			$buff = $tr_data;
			unset($tr_data);
			preg_match_all("/(.*)( #[0-9]+:? ?-? )(.*)$/",$buff[1][0], $tr_data);
			$tr_data[0][0] = $buff[0][0];
			$tr_data[3][0] = $tr_data[3][0].$buff[2][0].$buff[3][0];
		}
		preg_match_all("/[0-9]+/",$tr_data[2][0], $tr_id);
		$tracker_id = $tr_id[0][0];
		if (!is_numeric($tracker_id)) return 0; // nothing found that looks like an ID
		//error_log(__METHOD__.array2string(array(0=>$tracker_id,1=>$subj)));
		$trackerData = $this->search(array('tr_id' => $tracker_id),'tr_summary');
		if (is_numeric($tracker_id) && empty($trackerData)) // we have a numeric ID, but we could not find it in our database, is it external?
		{
			// we modify the subject as external tracker ids mess up our recognition of tracker ids
			if ($tracker_id > 0) $subj = $tr_data[1][0].str_replace('#','ID:',$tr_data[2][0]).$tr_data[3][0];
			return 0;
		}
		// Use strncmp() here, since a Fwd might add a sqr bracket.
		if (strncmp($trackerData[0]['tr_summary'], $tr_data[3][0], strlen($trackerData[0]['tr_summary'])))
		{
			//_debug_array($trackerData);
			return 0; // Summary doesn't match. Should this be ok?
		}
		return $tracker_id;
	}

	/**
	 * prepares the content of an email to be imported as tracker
	 *
	 * @author Klaus Leithoff <kl@stylite.de>
	 * @param string $_email_address rfc822 conform emailaddresses
	 * @param string $_subject
	 * @param string $_message
	 * @param array $_attachments
	 * @param string $_date
	 * @return array $content array for tracker_ui
	 */
	function prepare_import_mail($_email_address,$_subject,$_message,$_attachments,$_date)
	{
		$address_array = imap_rfc822_parse_adrlist($_email_address,'');
		foreach ((array)$address_array as $address)
		{
			$email[] = $emailadr = sprintf('%s@%s',
				trim($address->mailbox),
				trim($address->host));
				$name[] = !empty($address->personal) ? $address->personal : $emailadr;
		}
		// shorten long (> $this->max_line_chars) lines of "line" chars (-_+=~) in mails
		$_message = preg_replace_callback('/[-_+=~\.]{'.$this->max_line_chars.',}/m',
			create_function('$matches',"return substr(\$matches[0],0,$this->max_line_chars);"),$_message);
		//$type = isset($this->enums['type']['email']) ? 'email' : 'note';
		//$status = isset($this->status['defaults'][$type]) ? $this->status['defaults'][$type] : 'done';
		$ticketId = $this->get_ticketId($_subject);
		//_debug_array('TickedId found:'.$ticketId);
		// we have to check if we know this ticket before proceeding
		if ($ticketId == 0)
		{
			$trackerentry = array(
				'tr_id' => 0,
				'tr_cc' => implode(', ',$email),
				'tr_summary' => $_subject,
				'tr_description' => $_message,
				'referer' => false,
				'popup' => true,
				'link_to' => array(
					'to_app' => 'tracker',
					'to_id' => 0,
				),
			);
			// find the addressbookentry to link with
			$addressbook = new addressbook_bo();
			$contacts = array();
			foreach ($email as $mailadr)
			{
				$contacts = array_merge($contacts,(array)$addressbook->search(
					array(
						'email' => $mailadr,
						'email_home' => $mailadr
					),'contact_id,contact_email,contact_email_home,egw_addressbook.account_id as account_id','','','',false,'OR',false,null,'',false));
			}
			if (!$contacts || !is_array($contacts) || !is_array($contacts[0]))
			{
				$trackerentry['msg'] = lang('Attention: No Contact with address %1 found.',implode(', ',$email));
				$trackerentry['tr_creator'] = $this->user;	// use current user as creator instead
			}
			else
			{
				// create as "ordinary" links and try to find/set the creator according to the sender (if it is a valid user to the all queues (tracker=0))
				foreach ($contacts as $contact)
				{
					egw_link::link('tracker',$trackerentry['link_to']['to_id'],'addressbook',$contact['id']);
					$staff = $this->get_staff($tracker=0,0,'usersANDtechnicians');
					if (empty($trackerentry['tr_creator'])&& $contact['account_id']>0)
					{
						$buff = explode(',',strtolower($trackerentry['tr_cc'])) ;
						unset($trackerentry['tr_cc']);
						foreach (array('email','email_home') as $k => $n)
						{
							if (!empty($contact[$n]) && !empty($buff))
							{
								$break = false;
								$cnt = count($buff);
								$i = 0;
								while ( $break == false )
								{
									$key = array_search(strtolower($contact[$n]),$buff);
									//_debug_array('found:'.$n.'->'.$key);
									if ($key !== false && isset($staff[$contact['account_id']]))
									{
										unset($buff[$key]);
										if (empty($trackerentry['tr_creator'])) $trackerentry['tr_creator'] = $contact['account_id'];
									}
									$i++;
									if ($key==false || $i>=$cnt) $break=true;
								}
							}
						}
						$trackerentry['tr_cc'] = implode(',',$buff);
					}
				}
				if (empty($trackerentry['tr_creator'])) $trackerentry['tr_creator']=$this->user;
			}
		}
		else
		{
			$this->read($ticketId);
			//echo "<p>data[tr_edit_mode]={$this->data['tr_edit_mode']}, this->htmledit=".array2string($this->htmledit)."</p>\n";
			// Ascii Replies are converted to html, if htmledit is disabled (default), we allways convert, as this detection is weak
			if (is_array($this->data['replies']))
			{
				foreach ($this->data['replies'] as &$reply)
				{
					if (!$this->htmledit || stripos($reply['reply_message'], '<br') === false && stripos($reply['reply_message'], '<p>') === false)
					{
						$reply['reply_message'] = nl2br(html::htmlspecialchars($reply['reply_message']));
					}
				}
			}
			$trackerentry = $this->data;
			$trackerentry['reply_message'] = $_message;
			$trackerentry['popup'] = true;

		}
		$queue = 0; // all; we use this, as we do not have a queue, when preparing a new ticket
		if (isset($trackerentry['tr_tracker']) && !empty($trackerentry['tr_tracker'])) $queue = $trackerentry['tr_tracker'];
		// since we only add replies for existing tickets, we do not mess with tr_cc in that case
		if ($ticketId==0 && $queue==0 && (!isset($this->mailhandling[$queue]['auto_cc']) || empty($this->mailhandling[$queue]['auto_cc']))) unset($trackerentry['tr_cc']);
		if (is_array($_attachments))
		{
			foreach ($_attachments as $attachment)
			{
				$is_vfs = false;
				if (parse_url($attachment['tmp_name'],PHP_URL_SCHEME) == 'vfs' && egw_vfs::is_readable($attachment['tmp_name']))
				{
					$is_vfs = true;
				}
				if(is_readable($attachment['tmp_name']) || $is_vfs)
				{
					egw_link::link('tracker',$trackerentry['link_to']['to_id'],'file',$attachment);
				}
			}
		}
		return $trackerentry;
	}

	/**
	 * return SQL implementing filtering by date
	 *
	 * @param string $name
	 * @param int &$start
	 * @param int &$end_param
	 * @return string
	 */
	function date_filter($name,&$start,&$end_param)
	{
		$end = $end_param;

		if ($name == 'custom' && $start)
		{
			if ($end)
			{
				$end += 24*60*60;
			}
			else
			{
				$end = $start + 8*24*60*60;
			}
		}
		else if (strtolower($name) == 'overdue')
		{
                        $limit = $this->now - $this->overdue_days * 24*60*60;

			return "(tr_duedate IS NOT NULL and tr_duedate < {$this->now}
OR tr_duedate IS NULL AND
    CASE
        WHEN tr_modified IS NULL
        THEN
            tr_created < $limit
        ELSE
            tr_modified < $limit
    END
			) ";
		}
		else if (strtolower($name) == 'started')
		{
			return "(tr_startdate IS NULL OR tr_startdate < {$this->now} )" ;
		}
		else if (strtolower($name) == 'upcoming')
		{
			return "(tr_startdate IS NOT NULL and tr_startdate > {$this->now} )";
		}
		else
		{
			if (!isset($this->date_filters[$name]))
			{
				return '1=1';
			}
			$year  = (int) date('Y',$this->today);
			$month = (int) date('m',$this->today);
			$day   = (int) date('d',$this->today);

			list($syear,$smonth,$sday,$sweek,$eyear,$emonth,$eday,$eweek) = $this->date_filters[$name];

			if(stripos($name, 'quarter') !== false)
			{
				// Handle quarters
				$start = mktime(0,0,0,((int)floor(($smonth+$month) / 3.1)) * 3 + 1, 1, $year);
				$end = mktime(0,0,0,((int)floor(($emonth+$month) / 3.1)+1) * 3 + 1, 1, $year);
			}
			elseif ($syear || $eyear)
			{
				$start = mktime(0,0,0,1,1,$syear+$year);
				$end   = mktime(0,0,0,1,1,$eyear+$year);
			}
			elseif ($smonth || $emonth)
			{
				$start = mktime(0,0,0,$smonth+$month,1,$year);
				$end   = mktime(0,0,0,$emonth+$month,1,$year);
			}
			elseif ($sday || $eday)
			{
				$start = mktime(0,0,0,$month,$sday+$day,$year);
				$end   = mktime(0,0,0,$month,$eday+$day,$year);
			}
			elseif ($sweek || $eweek)
			{
				$wday = (int) date('w',$this->today); // 0=sun, ..., 6=sat
				switch($GLOBALS['egw_info']['user']['preferences']['calendar']['weekdaystarts'])
				{
					case 'Sunday':
						$weekstart = $this->today - $wday * 24*60*60;
						break;
					case 'Saturday':
						$weekstart = $this->today - (6-$wday) * 24*60*60;
						break;
					case 'Moday':
					default:
						$weekstart = $this->today - ($wday ? $wday-1 : 6) * 24*60*60;
						break;
				}
				$start = $weekstart + $sweek*7*24*60*60;
				$end   = $weekstart + $eweek*7*24*60*60;
			}
			$end_param = $end - 24*60*60;
		}
		//echo "<p align='right'>date_filter($name,$start,$end) today=".date('l, Y-m-d H:i',$this->today)." ==> ".date('l, Y-m-d H:i:s',$start)." <= date < ".date('l, Y-m-d H:i:s',$end)."</p>\n";
		// convert start + end from user to servertime for the filter
		return '('.($start-$this->tz_offset_s).' <= tr_created AND tr_created < '.($end-$this->tz_offset_s).')';
	}

	/**
	 * set fields readonly, depending on the rights the current user has on the actual tracker item
	 *
	 * @return array
	 */
	function readonlys_from_acl()
	{
		//echo "<p>uitracker::get_readonlys() is_admin(tracker={$this->data['tr_tracker']})=".$this->is_admin($this->data['tr_tracker']).", id={$this->data['tr_id']}, creator={$this->data['tr_creator']}, assigned={$this->data['tr_assigned']}, user=$this->user</p>\n";
		$readonlys = array();
		foreach((array)$this->field_acl as $name => $rigths)
		{
			$readonlys[$name] = !$rigths || !$this->check_rights($rigths, null, null, null, $name);
		}
		if ($this->customfields && $readonlys['customfields'])
		{
			foreach($this->customfields as $name => $data)
			{
				$readonlys['#'.$name] = $readonlys['customfields'];
			}
		}
		return $readonlys;
	}

	/**
	 * Get a list of users with open tickets, either created or assigned.
	 *
	 * Limits the amount of checking to do for notifications by only getting users with
	 * tickets where the start date, due date or created + limit is within 4 days
	 *
	 * @return array of user IDs
	 */
	public function users_with_open_entries()
	{

		$users = array();
	
		$config_limit = $this->now - $this->overdue_days * 24*60*60;
		$four_days = 4 * 24*60*60;
		
		$where = array(
			'tr_status' => array_keys($this->get_tracker_stati(null, false)),
			"(tr_duedate IS NOT NULL and ABS(tr_duedate - {$this->now}) < {$four_days}
OR tr_startdate IS NOT NULL AND ABS(tr_startdate - {$this->now}) < $four_days
OR tr_duedate IS NULL AND
    CASE
        WHEN tr_modified IS NULL
        THEN
            ABS(tr_created - $config_limit) < $four_days
        ELSE
            ABS(tr_modified - $config_limit) < $four_days
    END
                        ) "
		);

		// Creator
		$result = $this->db->select(self::TRACKER_TABLE, array('DISTINCT tr_creator'),$where,__LINE__,__FILE__);
		foreach($result as $user)
		{
			$users[] = $user['tr_creator'];
		}

		// Assigned
		$result = $this->db->select(
			self::ASSIGNEE_TABLE, array('DISTINCT tr_assigned'),$where,__LINE__,__FILE__,
			false, '',false,-1,
			'JOIN '.self::TRACKER_TABLE.' ON '.self::TRACKER_TABLE.'.tr_id = '.self::ASSIGNEE_TABLE.'.tr_id'
		);
		foreach($result as $user)
		{
			$user = $user['tr_assigned'];
			if($user < 0) $user = $GLOBALS['egw']->accounts->members($user,true);
			$users[] = $user;
		}

		return array_unique($users);
	}
}
