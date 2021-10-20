<?php
/**
 * Tracker - history and notifications
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Storage\Customfields;

/**
 * Tracker - tracking object for the tracker
 */
class tracker_tracking extends Api\Storage\Tracking
{
	/**
	 * Application we are tracking (required!)
	 *
	 * @var string
	 */
	var $app = 'tracker';
	/**
	 * Name of the id-field, used as id in the history log (required!)
	 *
	 * @var string
	 */
	var $id_field = 'tr_id';
	/**
	 * Name of the field with the creator id, if the creator of an entry should be notified
	 *
	 * @var string
	 */
	var $creator_field = 'tr_creator';
	/**
	 * Name of the field with the id(s) of assinged users, if they should be notified
	 *
	 * @var string
	 */
	var $assigned_field = 'tr_assigned';
	/**
	 * Translate field-name to 2-char history status
	 *
	 * @var array
	 */
	var $field2history = array();
	/**
	 * Should the user (passed to the track method or current user if not passed) be used as sender or get_config('sender')
	 *
	 * @var boolean
	 */
	var $prefer_user_as_sender = false;
	/**
	 * Instance of the botracker class calling us
	 *
	 * @access private
	 * @var tracker_bo
	 */
	var $tracker;

	/**
	 * Constructor
	 *
	 * @param tracker_bo $botracker
	 * @return tracker_tracking
	 */
	function __construct(tracker_bo $botracker, $notification_class=false)
	{
		$this->tracker = $botracker;
		$this->field2history = $botracker->field2history;

		parent::__construct('tracker', $notification_class);	// adding custom fields for tracker
	}

	/**
	 * Tracks the changes in one entry $data, by comparing it with the last version in $old
	 *
	 * Overridden from parent to hide restricted comments
	 *
	 * @param array $data current entry
	 * @param array $old =null old/last state of the entry or null for a new entry
	 * @param int $user =null user who made the changes, default to current user
	 * @param boolean $deleted =null can be set to true to let the tracking know the item got deleted or undeleted
	 * @param array $changed_fields =null changed fields from ealier call to $this->changed_fields($data,$old), to not compute it again
	 * @param boolean $skip_notification =false do NOT send any notification
	 * @return int|boolean false on error, integer number of changes logged or true for new entries ($old == null)
	 */
	public function track(array $data,array $old=null,$user=null,$deleted=null,array $changed_fields=null,$skip_notification=false)
	{
		$this->user = !is_null($user) ? $user : $GLOBALS['egw_info']['user']['account_id'];

		$changes = true;

		// Hide restricted comments from reply count
		foreach((array)$data['replies'] as $reply)
		{
			if (!empty($reply['reply_visible']))
			{
				$data['num_replies']--;
			}
		}
		if ($old && $this->field2history)
		{
			// If someone made a restricted comment, hide that from change tracking (notification & history)
			$old['num_replies'] = $data['num_replies'] - (!$data['reply_message'] || $data['reply_visible'] != 0 ? 0 : 1);

			$changes = $this->save_history($data,$old,$deleted,$changed_fields);
		}
		// check if the not tracked field num_replies changed and count that as change to
		// so new comments without other changes give a notification
		if (!$changes && $old && $old['num_replies'] != $data['num_replies'])
		{
			$changes = true;
		}
		// do not run do_notifications if we have no changes, unless there was a restricted comment just made
		if (($changes || ($data['reply_visible'] != 0)) && !$skip_notification && !$this->do_notifications2($data,$old,$deleted,$changes))
		{
			$changes = false;
		}
		return $changes;
	}

	/**
	 * Send an autoreply to the ticket creator or replier by the mailhandler
	 *
	 * @param array $data current entry
	 * @param array $autoreply values for:
	 *			'reply_text' => Texline to add to the mail message
	 *			'reply_to' => UserID or email address
	 * @param array $old =null old/last state of the entry or null for a new entry
	 */
	function autoreply($data,$autoreply,$old=null)
	{
		if (is_integer($autoreply['reply_to'])) // Mail from a known user
		{
			if ($this->notify_current_user)
			{
				return; // Already notified while saving
			}
			else
			{
				$this->notify_current_user = true; // Ensure send_notification() doesn't fail this check
			}
			$email = $GLOBALS['egw']->accounts->id2name($this->user,'account_email');
		}
		else
		{
			$this->notify_current_user = true; // Ensure send_notification() doesn't fail this check
			$email = $autoreply['reply_to']; // mail from an unknown user (set here, so we need to send a notification)
		}
		//error_log(__METHOD__.__LINE__.array2string($autoreply));
		if ($autoreply['reply_text'])
		{
			$data['reply_text'] = $autoreply['reply_text'];
			$this->ClearBodyCache();
		}
		// Send notification to the creator only; assignee, CC etc have been notified already
		$this->send_notification($data,$old,$email,(is_integer($autoreply['reply_to'])?$data[$this->creator_field]:$this->get_config('lang',$data)));
	}

	/**
	 * Send notifications for changed entry
	 *
	 * Overridden to keep signature
	 *
	 * @internal use only track($data,$old,$user)
	 * @param array $data current entry
	 * @param array $old =null old/last state of the entry or null for a new entry
	 * @param boolean $deleted =null can be set to true to let the tracking know the item got deleted or undelted
	 * @return boolean true on success, false on error (error messages are in $this->errors)
	 */
	public function do_notifications($data, $old, $deleted = null, &$email_notified = null)
	{
		return $this->do_notifications2($data, $old, $deleted);
	}

	/**
	 * Send notifications for changed entry (different name, as we are no longer allowed to change function signature)
	 *
	 * @internal use only track($data,$old,$user)
	 * @param array $data current entry
	 * @param array $old =null old/last state of the entry or null for a new entry
	 * @param boolean $deleted =null can be set to true to let the tracking know the item got deleted or undelted
	 * @return boolean true on success, false on error (error messages are in $this->errors)
	 */
	public function do_notifications2($data,$old,$deleted, $changes)
	{
		$skip = $this->get_config('skip_notify',$data,$old);
		$email_notified = $skip ? $skip : array();

		// Send all to others
		$creator = $data[$this->creator_field];
		$creator_field = $this->creator_field;
		if(!($this->tracker->is_admin($data['tr_tracker'], $creator, true) || $this->tracker->is_technician($data['tr_tracker'], $creator)))
		{
			// Notify the creator with full info if they're an admin or technician
			$this->creator_field = null;
		}

		// Don't send CC
		$private = $data['tr_private'];
		$data['tr_private'] = true;

		// Send notification - $email_notified will be skipped
		$success = parent::do_notifications($data, $old, $deleted, $email_notified);

		//error_log(__METHOD__.__LINE__." email notified with restricted comments:".array2string($email_notified));

		if(!$changes)
		{
			// Only thing that really changed was a restricted comment
			//error_log(__METHOD__.':'.__LINE__.' Stopping, no other changes');
			return $success;
		}
		// clears the cached notifications body
		$this->ClearBodyCache();

		// Edit messages
		foreach($data['replies'] ?? [] as $key => $reply)
		{
			if (!empty($reply['reply_visible']))
			{
				unset($data['replies'][$key]);
			}
		}

		// Send to creator (if not already notified) && CC
		if(!($this->tracker->is_admin($data['tr_tracker'], $creator, true) || $this->tracker->is_technician($data['tr_tracker'], $creator)))
		{
			$this->creator_field = $creator_field;
		}
		$this->tracker->preset_replies[$data['tr_id']] = $data['replies'] ?? [];
		$data['tr_private'] = $private;
		//$already_notified = $email_notified;
		$ret = $success && parent::do_notifications($data, $old, $deleted, $email_notified);
		//error_log(__METHOD__.__LINE__." email notified, restricted comments removed:".array2string(array_diff($email_notified,$already_notified)));

		return $ret;
	}

	/**
	 * Get a notification-config value
	 *
	 * @param string $name
	 * 	- 'copy' array of email addresses notifications should be copied too, can depend on $data
	 *  - 'lang' string lang code for copy mail
	 *  - 'sender' string send email address
	 * @param array $data current entry
	 * @param array $old =null old/last state of the entry or null for a new entry
	 * @return mixed
	 */
	function get_config($name,$data,$old=null)
	{
		unset($old);	// not used

		$tracker = $data['tr_tracker'];

		$config = $this->tracker->notification[$tracker][$name] ?? $this->tracker->notification[0][$name] ?? null;

		switch($name)
		{
			case 'copy':
				// include the tr_cc addresses
				// If not set for this queue or all queues, default to true
				$no_external = $this->tracker->notification[$tracker]['no_external'] ??
					$this->tracker->notification[0]['no_external'] ?? null;

				if ($data['tr_private'] || $no_external)
				{
					return array();	// no copies for private entries
				}
				$config = $config ? preg_split('/, ?/',$config) : array();
				if ($data['tr_cc'])
				{
					$config = array_merge($config,preg_split('/, ?/',$data['tr_cc']));
				}
				break;
			case 'skip_notify':
				$config = array_merge((array)$config, $data['skip_notify'] ?? (array)($this->skip_notify ?? []));
				break;
			case 'reply_to':
				if (empty($config))	// if no explicit reply_to set in notifications use sender from mail config
				{
					$config = $this->tracker->notification[$tracker]['sender'] ?
						$this->tracker->notification[$tracker]['sender'] :
						$this->tracker->notification[0]['sender'];
				}
				break;
		}
		//error_log(__METHOD__.__LINE__.' Name:'.$name.' -> '.array2string($config).' Data:'.array2string($data));
		return $config;
	}

	/**
	 * Get the subject for a given entry, reimplementation for get_subject in Api\Storage\Tracking
	 *
	 * Default implementation uses the link-title
	 *
	 * @param array $data
	 * @param array $old
	 * @return string
	 */
	function get_subject($data, $old, $deleted = null, $receiver = null)
	{
		unset($old);	// not used

		return ($data['prefix']??'') . $this->tracker->trackers[$data['tr_tracker']].' #'.$data['tr_id'].': '.$data['tr_summary'];
	}

	/**
	 * Get the body of the notification message
	 * If there is a custom notification message configured, that will be used.  Otherwise, the
	 * default message will be used.
	 *
	 * @param boolean $html_email
	 * @param array $data
	 * @param array $old
	 * @param boolean $integrate_link to have links embedded inside the body
	 * @param int|string $receiver numeric account_id or email address
	 * @return string
	 */
	function get_body($html_email,$data,$old,$integrate_link = true,$receiver=null)
	{
		$notification = $this->tracker->notification[$data['tr_tracker']];
		$merge = new tracker_merge();

		// Set comments according to data, avoids re-reading from DB
		if (isset($data['replies'])) $merge->set_comments($data['tr_id'], $data['replies']);

		if(empty($notification['message']) || trim(strip_tags($notification['message'])) == '' || empty($notification['use_custom']))
		{
			$notification['message'] = $this->tracker->notification[0]['message'];
		}
		if(empty($notification['signature']) || trim(strip_tags($notification['signature'])) == '' || empty($notification['use_signature']))
		{
			$notification['signature'] = $this->tracker->notification[0]['signature'];
		}
		if(empty($notification['use_signature']) && empty($this->tracker->notification[0]['use_signature'])) $notification['signature'] = '';

		// If no signature set, use the global one
		if(empty($notification['signature']))
		{
			$notification['signature'] = parent::get_signature($data,$old,$receiver);
		}
		else
		{
			$error = null;
			$notification['signature'] = $merge->merge_string($notification['signature'], array($data['tr_id']), $error, 'text/html');
		}

		if((empty($notification['use_custom']) && empty($this->tracker->notification[0]['use_custom'])) || empty($notification['message']))
		{
			// Always use text mode for text tickets, HTML for HTML tickets
			$html = $this->html_content_allow;
			$this->html_content_allow = $data['tr_edit_mode'] !== 'ascii';

			$body = parent::get_body($html_email,$data,$old,$integrate_link,$receiver).($html_email?"<br />\n":"\n").
				$notification['signature'];

			$this->html_content_allow = $html;
			return $body;
		}

		$message = $this->sanitize_custom_message($notification['message'], $receiver);
		$message = $merge->merge_string($message, array($data['tr_id']), $error, 'text/html');
		if(strpos($notification['message'], '{{signature}}') === False)
		{
			$message.=($html_email?"<br />\n":"\n").
				$notification['signature'];
		}
		if($error)
		{
			error_log($error);
			return parent::get_body($html_email,$data,$old,$integrate_link,$receiver)."\n".$notification['signature'];
		}
		return $html_email ? $message : Api\Mail\Html::convertHTMLToText(Api\Html::purify($message), false, true, true);
	}

	/**
	 * Override parent to return nothing, it's taken care of in get_body()
	 *
	 * @see get_body()
	 */
	protected function get_signature($data,$old,$receiver)
	{
		unset($data,$old,$receiver);	// not used

		return false;
	}

	/**
	 * Get the modified / new message (1. line of mail body) for a given entry, can be reimplemented
	 *
	 * @param array $data
	 * @param array $old
	 * @return array (of strings) for multiline messages
	 */
	function get_message($data, $old, $receiver = null)
	{
		if (!empty($data['message'])) return $data['message'];

		$r = [];
		if (!empty($data['reply_text']))
		{
			$r[] = $data['reply_text'];
			$r[] = '---';// this is wanted for separation of reply_text to status/creation text
		}
		if (empty($data['tr_modified']) || !$old)
		{
			$r[] = lang('New ticket submitted by %1 at %2',
				Api\Accounts::username($data['tr_creator']),
				$this->datetime($data['tr_created_servertime']));
			return $r;
		}
		$r[] = lang('Ticket modified by %1 at %2',
			$data['tr_modifier'] ? Api\Accounts::username($data['tr_modifier']) : lang('Tracker'),
			$this->datetime($data['tr_modified_servertime']));
		return $r;
	}

	/**
	 * Get the details of an entry
	 *
	 * @param array $data
	 * @param int|string $receiver =null numeric account_id or email address
	 * @return array of details as array with values for keys 'label','value','type'
	 */
	function get_details($data, $receiver=null)
	{
		static $cats=null,$versions=null,$statis=null,$priorities=null,$resolutions=null;
		if (!$cats)
		{
			$cats = $this->tracker->get_tracker_labels('cat',$data['tr_tracker']);
			$versions = $this->tracker->get_tracker_labels('version',$data['tr_tracker']);
			$statis = $this->tracker->get_tracker_stati($data['tr_tracker']);
			$priorities = $this->tracker->get_tracker_priorities($data['tr_tracker']);
			$resolutions = $this->tracker->get_tracker_labels('resolution',$data['tr_tracker']);
		}
		if ($data['tr_assigned'])
		{
			foreach($data['tr_assigned'] as $uid)
			{
				$assigned[] = Api\Accounts::username($uid);
			}
			$assigned = implode(', ',$assigned);
		}
/*
		if ($data['reply_text'])
		{
			$details['reply_text'] = array(
				'value' => $data['reply_text'],
				'type' => 'message',
			);
		}
*/
		$detail_fields = array(
			'tr_tracker'     => $this->tracker->trackers[$data['tr_tracker']],
			'cat_id'         => $cats[$data['cat_id']],
			'tr_version'     => $versions[$data['tr_version']],
			'tr_startdate'   => !empty($data['tr_startdate']) ? $this->datetime(new Api\DateTime($data['tr_startdate'])) : '',
			'tr_duedate'     => !empty($data['tr_duedate']) ? $this->datetime(new Api\DateTime($data['tr_duedate'])) : '',
			'tr_status'      => lang($statis[$data['tr_status']]),
			'tr_resolution'  => lang($resolutions[$data['tr_resolution']] ?? ''),
			'tr_completion'  => (int)($data['tr_completion'] ?? 0).'%',
			'tr_priority'    => lang($priorities[$data['tr_priority']] ?? ''),
			'tr_creator'     => Api\Accounts::username($data['tr_creator']),
			'tr_created'     => $this->datetime(new Api\DateTime($data['tr_created'])),
			'tr_assigned'	 => !$data['tr_assigned'] ? lang('Not assigned') : $assigned,
			'tr_cc'			 => $data['tr_cc'],
			// The layout of tr_summary should NOT be changed in order for
			// tracker.tracker_mailhandler.get_ticketId() to work!
			'tr_summary'     => '#'.$data['tr_id'].' - '.$data['tr_summary'],
		);

		// Don't show start date / due date if disabled or not set
		$config = Api\Config::read('tracker');
		if(!$config['show_dates'])
		{
			unset($detail_fields['tr_startdate']);
			unset($detail_fields['tr_duedate']);
		}
		if (empty($data['tr_startdate'])) unset($detail_fields['tr_startdate']);
		if (empty($data['tr_duedate'])) unset($detail_fields['tr_duedate']);

		foreach($detail_fields as $name => $value)
		{
			$details[$name] = array(
				'label' => lang($this->tracker->field2label[$name]),
				'value' => $value,
			);
			if ($name == 'tr_summary') $details[$name]['type'] = 'summary';
		}
		// add custom fields for given type
		$details += $this->get_customfields($data, $data['tr_tracker'], $receiver);

		if (!empty($data['replies'])) //$data['reply_message'] && !$data['reply_visible'])
		{
			// At least one comment was made
			$reply = $data['replies'][0] ?? [];
			$details[] = array(
				'type' => 'message',
				'label' => lang('Comment by %1 at %2:', !empty($reply['reply_creator']) ? Api\Accounts::username($reply['reply_creator']) : lang('Tracker'), $this->datetime($reply['reply_servertime'])),
				'value' => ' '
			);
			$details[] = array(
				'type' => 'reply',
				'value' => $data['tr_edit_mode'] == 'ascii' ?
						preg_replace("@\n\n+@", "\n", $reply['reply_message'] ?? '') :
						preg_replace("@\n\n+|<br ?/?>\n?<br ?/?>@", "<br>", $reply['reply_message'] ?? '')
			);
			$n = 2;
		}
		$details[] = array(
			'value' => lang('Description'),
			'type' => 'summary'
		);
		$details['tr_description'] = array(
			'value' => $data['tr_edit_mode'] == 'ascii' ? htmlspecialchars_decode($data['tr_description']) : $data['tr_description'],
			'type'  => 'multiline',
		);
		if (!empty($data['replies']))
		{
			foreach($data['replies'] as $reply_index => $reply)
			{
				if (empty($reply['reply_message'])) continue;
				$reply['reply_message'] = $data['tr_edit_mode'] == 'ascii' ?
						preg_replace("@\n\n+@", "\n", $reply['reply_message']) :
						preg_replace("@\n\n+|<br ?/?>\n?<br ?/?>@", "<br>", $reply['reply_message']);
				$msg = array(	// first reply need to be checked against old to marked modified for new
					'value' => lang('Comment by %1 at %2:',$reply['reply_creator'] ? Api\Accounts::username($reply['reply_creator']) : lang('Tracker'),
						$this->datetime($reply['reply_servertime'])),
					'type'  => 'reply',
				);
				if(!$reply_index)
				{
					$details['replies'] = $msg;
				}
				else
				{
					$details[] = $msg;
				}
				$details[] = array(
					'value' => $reply['reply_message'],
					'type'  => 'multiline',
				);
			}
		}
		return $details;
	}

	/**
	 * Override to extend permission so tracker_merge can use it
	 */
	public function get_link($data,$old,$allow_popup=false,$receiver=null)
	{
		return parent::get_link($data,$old,$allow_popup,$receiver);
	}

	/**
	 * Compute changes between new and old data
	 *
	 * Reimplemented to cope with some tracker specialties:
	 * - tr_completion is postfixed with a percent
	 *
	 * @param array $data
	 * @param array $old =null
	 * @return array of keys with different values in $data and $old
	 */
	public function changed_fields(array $data,array $old=null)
	{
		$changed = parent::changed_fields($data, $old);

		// for tr_completion ignore percent postfix
		if (($k = array_search('tr_completion', $changed)) !== false &&
			(int)$data['tr_completion'] === (int)$old['tr_completion'])
		{
			unset($changed[$k]);
		}
		return $changed;
	}
}
