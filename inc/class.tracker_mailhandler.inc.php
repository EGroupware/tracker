<?php
/**
 * EGroupware Tracker - Handle incoming mails
 *
 * This class handles incoming mails in the async services.
 * It is an addition for the eGW Tracker app by Ralf Becker
 *
 * @link http://www.egroupware.org
 * @author Oscar van Eijk <oscar.van.eijk-AT-oveas.com>
 * @package tracker
 * @copyright (c) 2008 by Oscar van Eijk <oscar.van.eijk-AT-oveas.com>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Link;

use EGroupware\Api\Mail;

class tracker_mailhandler extends tracker_bo
{
	/**
	 * UID of the mailsender, 0 if not recognized
	 *
	 * @var int
	 */
	var $mailSender;

	/**
	 * Subject line of the incoming mail
	 *
	 * @var string
	 */
	var $mailSubject;

	/**
	 * Text from the mailbody (1st part)
	 *
	 * @var string
	 */
	var $mailBody;

	/**
	 * Identification of the mailbox
	 *
	 * @var string
	 */
	var $mailBox;

	/**
	 * List with all messages retrieved from the server
	 *
	 * @var array
	 */
	var $msgList = array();

	/**
	 * Mailbox stream
	 *
	 * @var int
	 */
	var $mbox;

	/**
	 * smtpObject for autoreplies and worwarding
	 *
	 * @var send object
	 */
	var $smtpMail;

	/**
	 * Ticket ID or 0 if not recognize
	 *
	 * @var int
	 */
	var $ticketId;

	/**
	 * User ID currently executing. Used in case we execute in fallback
	 *
	 * @var int
	 */
	var $originalUser;

	/**
	 * Supported mailservertypes, extracted from parent::mailservertypes
	 *
	 * @var array
	 */
	var $serverTypes = array();

	/**
	 * How much should be logged to the apache error-log
	 *
	 * 0 = Nothing
	 * 1 = only errors
	 * 2 = more debug info
	 * 3 = complete debug info
	 */
	const LOG_LEVEL = 0;

	/**
	 * Constructor
	 * @param array mailhandlingConfig - optional mailhandling config, to overrule the config loaded by parent
	 */
	function __construct($mailhandlingConfig=null)
	{
		parent::__construct();
		if (!is_null($mailhandlingConfig)) $this->mailhandling = $mailhandlingConfig;
		// In case we run in fallback, make sure the original user gets restored
		$this->originalUser = $this->user;
		foreach($this->mailservertypes as $typ)
		{
			$this->serverTypes[] = $typ[0];
		}
		if (($this->mailBox = self::get_mailbox(0)) === false)
		{
			return false;
		}
	}

	/**
	 * Destructor, close the stream if not done before.
	 */
	function __destruct()
	{
	}

	/**
	 * Compare 2 given mailbox settings (for a given set of properties)
	 * @param defaultImap Object $reference reference
	 * @param defaultImap Object $profile compare to reference
	 * @return mixed false/array with the differences found; empty array when no differences for the predefined set of keys are found; false if either one is not of type defaultImap
	 */
	static function compareMailboxSettings($reference, $profile)
	{
		$diff = array();
		if (!($reference instanceof Mail\Imap)) return false;
		if (!($profile instanceof Mail\Imap)) return false;
		//error_log(__METHOD__.__LINE__.' Reference:'.get_class($reference));
		//error_log(__METHOD__.__LINE__.' Reference:'.array2string($reference));
		//error_log(__METHOD__.__LINE__.' Profile:'.get_class($profile));
		//error_log(__METHOD__.__LINE__.' Profile:'.array2string($profile));
		if (get_class($reference) != get_class($profile)) return false;
		if ($profile instanceof Mail\Imap)
		{
			if ($reference->ImapServerId != $profile->ImapServerId) $diff['ImapServerId']=array('reference'=>$reference->ImapServerId,'profile'=>$profile->ImapServerId);
			try
			{
				if ($reference->acc_imap_host != $profile->acc_imap_host) $diff['acc_imap_host']=array('reference'=>$reference->acc_imap_host,'profile'=>$profile->acc_imap_host);
				if ($reference->acc_imap_port != $profile->acc_imap_port) $diff['acc_imap_port']=array('reference'=>$reference->acc_imap_port,'profile'=>$profile->acc_imap_port);
				if ($reference->acc_imap_username != $profile->acc_imap_username) $diff['acc_imap_username']=array('reference'=>$reference->acc_imap_username,'profile'=>$profile->acc_imap_username);
				if ($reference->acc_imap_password != $profile->acc_imap_password) $diff['acc_imap_password']=array('reference'=>$reference->acc_imap_password,'profile'=>$profile->acc_imap_password);
			}
			catch(Exception $e)
			{
				unset($e);	// not used
				if ($reference->hostspec != $profile->hostspec) $diff['acc_imap_host']=array('reference'=>$reference->hostspec,'profile'=>$profile->hostspec);
				if ($reference->port != $profile->port) $diff['acc_imap_port']=array('reference'=>$reference->port,'profile'=>$profile->port);
				if ($reference->username != $profile->username) $diff['acc_imap_username']=array('reference'=>$reference->username,'profile'=>$profile->username);
				if ($reference->password != $profile->password) $diff['acc_imap_password']=array('reference'=>$reference->password,'profile'=>$profile->password);
			}
		}
		return $diff;
	}

	/**
	 * Compose the mailbox identification
	 *
	 * @return string mailbox identification as '{server[:port]/type}folder'
	 */
	function get_mailbox($queue = 0)
	{
		if (empty($this->mailhandling[$queue]['server']))
		{
			return false; // Or should we default to 'localhost'?
		}
		if ($this->mailhandling[$queue]['servertype']<=2)
		{
			try
			{
				$params['acc_imap_type'] = 'EGroupware\\Api\\Mail\\Imap';
				$params['acc_id'] = 'tracker_'.trim($queue);
				$params['acc_imap_host'] = $this->mailhandling[$queue]['server'];
				$params['acc_imap_ssl'] = ($this->mailhandling[$queue]['servertype']==2?3:($this->mailhandling[$queue]['servertype']==1?2:0));
				$params['acc_imap_port'] = $this->mailhandling[$queue]['serverport'];
				$params['acc_imap_username'] = $this->mailhandling[$queue]['username'];
				$params['acc_imap_password'] = $this->mailhandling[$queue]['password'];
				$params['acc_sieve_enabled'] = false;
				$params['acc_smtp_type'] = 'EGroupware\\Api\\Mail\\Smtp'; // needed, else the constructor fails
				$eaaccount = new Mail\Account($params);
				$icServer = $eaaccount->imapServer();
				return $icServer;
			}
			catch (Exception $e)
			{
				error_log(__METHOD__.__LINE__.' Failed loading mail profile:'.$e->getMessage());
				return false;
			}
		}
	}

	/**
	 * Get all mails from the server. Invoked by the async timer
	 *
	 * @param int|string|array $queue Which tracker queue to check mail for (array('tr_tracker' => $queue)
	 * @param boolean TestConnection=false
	 * @return boolean true=run finished, false=an error occured
	 */
	function check_mail($queue = 0, $TestConnection=false)
	{
		$matches = null;
		// new format with array('tr_tracker' => $queue)
		if (is_array($queue))
		{
			$queue = $queue['tr_tracker'];
		}
		// remove quotes added by async-service
		elseif(!is_numeric($queue) && preg_match('/([0-9]+)/', $queue, $matches))
		{
			$queue = $matches[1];
		}
		// Config for all passes null
		if(!$queue) {
			$queue = 0;
		} else {
			// Mailbox for all is pre-loaded, for others we have to change it
			$this->mailBox = self::get_mailbox($queue);
		}
		if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__." for $queue on".' # Instance='.$GLOBALS['egw_info']['user']['domain']);

		if ($this->mailBox === false)
		{
			if ($TestConnection) throw new Api\Exception\WrongUserinput(lang("incomplete server profile for mailhandling provided; Disabling mailhandling for Queue %1", $queue));
			// this line should prevent adding garbage to mailhandlerconfig
			if (!isset($this->mailhandling[$queue]) || empty($this->mailhandling[$queue]) || $this->mailhandling[$queue]['interval']==0) return false;
			error_log(__METHOD__.','.__LINE__.lang("incomplete server profile for mailhandling provided; Disabling mailhandling for Queue %1", $queue.' # Instance='.$GLOBALS['egw_info']['user']['domain']));
			$this->mailhandling[$queue]['interval']=0;
			$this->save_config();
			return false;
		}
		if ($this->mailBox instanceof Mail\Imap)
		{
			if (/*$this->mailhandling[$queue]['auto_reply'] ||*/ $this->mailhandling[$queue]['autoreplies'] || $this->mailhandling[$queue]['unrecognized_mails'])
			{
				if(is_object($this->smtpMail))
				{
					unset($this->smtpMail);
				}
				try
				{
					$this->smtpMail = new Api\Mailer();
					if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.array2string($this->smtpMail));
				} catch(Exception $e) {
					// ignore exception, but log it, to block the account and give a correct error-message to user
					error_log(__METHOD__."(".__LINE__.") "." Could not initiate smtpMail for notification purpose:".$e->getMessage());
					unset($this->smtpMail);
				}

			}
			$rFP=Api\Cache::getCache(Api\Cache::INSTANCE,'email','rememberFailedProfile_'.trim($this->mailBox->ImapServerId));
			if ($rFP && !empty($rFP))
			{
				$d = self::compareMailboxSettings($this->mailBox,$rFP);
				if ($d===false || empty($d))
				{
					if ($TestConnection==false)
					{
						error_log(__METHOD__.','.__LINE__." ".lang("eGroupWare Tracker Mailhandling: could not connect previously, and profile did not change"));
						error_log(__METHOD__.','.__LINE__." ".lang("refused to open mailbox: %1",array2string($this->mailBox)));
						$previousInterval = $this->mailhandling[$queue]['interval'];
						$this->mailhandling[$queue]['interval']=$this->mailhandling[$queue]['interval']*2;
						$this->save_config();
						Api\Cache::setCache(Api\Cache::INSTANCE,'email','rememberFailedProfile_'.trim($this->mailBox->ImapServerId),array(),$expiration=60*10);
						if ($GLOBALS['egw_info']['server']['admin_mails'] && $this->smtpMail)
						{
							// notify admin(s) via email
							$from    = 'eGroupWareTrackerMailHandling@'.$GLOBALS['egw_info']['server']['mail_suffix'];
							$subject = lang("eGroupWare Tracker Mailhandling: could not connect previously, and profile did not change");
							$body    = lang("refused to open mailbox therefore changed Interval from %1 to %2",$previousInterval,$this->mailhandling[$queue]['interval']);
							$body    .= "\n";
							$body    .= lang("Mailbox settings used: %1",array2string($this->mailBox));

							$admin_mails = explode(',',$GLOBALS['egw_info']['server']['admin_mails']);
							foreach($admin_mails as $to)
							{
								try {
										$GLOBALS['egw']->send->msg('email',$to,$subject,$body,'','','',$from,$from);
								}
								catch(Exception $e) {
									// ignore exception, but log it, to block the account and give a correct error-message to user
									error_log(__METHOD__."('$to') ".$e->getMessage());
								}
							}
						}
						return false;
					}
				}
			}
			$mailobject	= Mail::getInstance(false,$this->mailBox->ImapServerId,false,$this->mailBox);
			if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.'#'.array2string($this->mailBox));

			$connectionFailed = false;
			// connect
			try
			{
				$mailobject->openConnection($this->mailBox->ImapServerId);
				$_folderName = (!empty($this->mailhandling[$queue]['folder'])?$this->mailhandling[$queue]['folder']:'INBOX');
				$mailobject->reopen($_folderName);
			}
			catch (Exception $e)
			{
				$connectionFailed=true;
				$mailobjecterrorMessage = $e->getMessage();
			}
			if ($TestConnection===true)
			{
				if (self::LOG_LEVEL>0) error_log(__METHOD__.','.__LINE__." failed to open mailbox:".array2string($mailobject->icServer));
				if ($connectionFailed) throw new Api\Exception\WrongUserinput(lang("failed to open mailbox: %1 -> disabled for automatic mailprocessing!",($mailobjecterrorMessage?$mailobjecterrorMessage:lang('could not connect'))));
				return true;//everythig all right
			}
			if ($connectionFailed)
			{
				Api\Cache::setCache(Api\Cache::INSTANCE,'email','rememberFailedProfile_'.trim($this->mailBox->ImapServerId),$this->mailBox,$expiration=60*60*5);
				if (self::LOG_LEVEL>0) error_log(__METHOD__.','.__LINE__." failed to open mailbox:".array2string($this->mailBox));
				return false;
			}
			else
			{
				Api\Cache::setCache(Api\Cache::INSTANCE,'email','rememberFailedProfile_'.trim($this->mailBox->ImapServerId),array(),$expiration=60*10);
			}
			// load lang stuff for mailheaderInfoSection creation
			Api\Translation::add_app('mail');
			// retrieve list
			if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__." Processing mailbox {$_folderName} with ServerID:".$mailobject->icServer->ImapServerId." for queue $queue\n".array2string($mailobject->icServer));
			$_filter=array('status'=>array('UNSEEN','UNDELETED'));
			if (!empty($this->mailhandling[$queue]['address']))
			{
				$_filter['type']='TO';
				$_filter['string']=trim($this->mailhandling[$queue]['address']);
			}
			$_reverse=1;
			$_rByUid = true;
			$_sortResult = $mailobject->getSortedList($_folderName, $_sort=0, $_reverse, $_filter, $_rByUid, false);
			$sortResult = $_sortResult['match']->ids;
			if (self::LOG_LEVEL>1 && $sortResult) error_log(__METHOD__.__LINE__.'#'.array2string($sortResult));
			$deletedCounter = 0;
			$mailobject->reopen($_folderName);
			foreach ((array)$sortResult as $uid)
			{
				if (empty($uid)) continue;
				if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__.'# fetching Data for:'.array2string(array('uid'=>$uid,'folder'=>$_folderName)).' Mode:'.$this->htmledit.' SaveAsOption:'.$GLOBALS['egw_info']['user']['preferences']['mail']['saveAsOptions']);
				if ($uid)
				{
					$this->user = $this->originalUser;
					$htmlEditOrg = $this->htmledit; // preserve that, as an existing ticket may be of a different mode
					if (self::process_message2($mailobject, $uid, $_folderName, $queue) && $this->mailhandling[$queue]['delete_from_server'])
					{
						try
						{
							$mailobject->deleteMessages($uid, $_folderName, 'move_to_trash');
							$deletedCounter++;
						}
						catch (Exception $e)
						{
							error_log(__METHOD__.__LINE__." Failed to move Message (".array2string($uid).") from Folder $_folderName to configured TrashFolder Error:".$e->getMessage());
						}
					}
					$this->htmledit = $htmlEditOrg;
				}
			}
			// Expunge deleted mails, if any
			if ($deletedCounter) // NOTE THERE MAY BE DELETED MESSAGES AFTER THE PROCESSING
			{
				$mailobject->reopen($_folderName);
				$rv = $mailobject->compressFolder($_folderName);
				if (self::LOG_LEVEL && PEAR::isError($rv)) error_log(__METHOD__." failed to expunge Message(s) from Folder: ".$_folderName.' due to:'.$rv->message);
			}

			// Close the connection
			//$mailobject->closeConnection(); // not sure we should do that, as this seems to kill more then our connection

			$this->user = $this->originalUser;
			return true;
		}
	}

	/**
	 * determines the mime type of a eMail in accordance to the imap_fetchstructure
	 * found at http://www.linuxscope.net/articles/mailAttachmentsPHP.html
	 * by Kevin Steffer
	 */
	function get_mime_type(&$structure)
	{
	}

	function get_part($stream, $msg_number, $mime_type, $structure = false, $part_number = false)
	{
	} // END OF FUNCTION

	/**
	 * Extract the latest reply from mail body message
	 *
	 *
	 * @param {string} $mailBody string of mail body message
	 *
	 * @return {string} latest reply of mail body message
	 *
	 * @todo Find an optimize and accurate pattern/method to recognize content of mail message (e.g. Recognition/Classification algorithm like Perceptron or similar)
	 */
	function extract_latestReply ($mailBody)
	{
		$mailCntArray = preg_split("/(\r\n|\n|\r)/",$mailBody);
		$fRline = true;
		$oMInx = 0;
		foreach (array_keys($mailCntArray) as $key)
		{
			if (preg_match("/^-----.*".lang("original message")."---.*/i", $mailCntArray[$key]))
			{
				$oMInx = $key;
			}
			if (preg_match("/^>.*/",$mailCntArray[$key]))
			{
				if ($fRline && $oMInx > 0)
				{
					$fRline = false;
					for ($i =  $oMInx; $i<$key; $i++)
					{
						unset ($mailCntArray[$i]);
					}
				}
				unset($mailCntArray[$key]);
			}
		}
		return join("\n", $mailCntArray);
	}

	/**
	 * Retrieve and decode a bodypart
	 *
	 * @param int Message ID from the server
	 * @param string The body part, defaults to "1"
	 * @return string The decoded bodypart
	 */
	function get_mailbody ($mid, $section=false, $structure = false)
	{
	}

	/**
	 * Check if this is an automated message (bounce, autoreply...)
	 * @TODO This is currently a very basic implementation, the intention is to implement more checks,
	 * eg, filter failing addresses and remove them from CC.
	 *
	 * @param int $mid Message ID
	 * @param array $msgHeader IMap header
	 * @return boolean
	 */
	function is_automail($mid, $msgHeader)
	{
	}

	/**
	 * Check if this is an automated message (bounce, autoreply...)
	 * @TODO This is currently a very basic implementation, the intention is to implement more checks,
	 * eg, filter failing addresses and remove them from CC.
	 *
	 * @param object mailobject holding the server, and its connection
	 * @param int message ID from the server
	 * @param string subject the messages subject
	 * @param array msgHeaders full headers retrieved for message
	 * @param int queue the queue we are in
	 * @return boolean status
	 */
	function is_automail2($mailobject, $uid, $subject, $msgHeaders, $queue=0)
	{
		// This array can be filled with checks that should be made.
		// 'bounces' and 'autoreplies' (level 1) are the keys coded below, the level 2 arrays
		// must match $msgHeader properties.
		//
		$autoMails = array(
			 'bounces' => array(
				 'subject' => array(
				)
				,'from' => array(
					 'mailer-daemon'
				)
			)
			,'autoreplies' => array(
				 'subject' => array(
					 'out of the office',
					 'out of office',
					 'autoreply'
					)
				,'auto-submitted' => array(
					'auto-replied'
				)
			)
		);

		// Check for bounced messages
		foreach ($autoMails['bounces'] as $_k => $_v) {
			if (count($_v) == 0) {
				continue;
			}
			$_re = '/(' . implode('|', $_v) . ')/i';
			if (preg_match($_re, $msgHeader[strtoupper($_k)])) {
				switch ($this->mailhandling[0]['bounces']) {
					case 'delete' :		// Delete, whatever the overall delete setting is
						$returnVal = $mailobject->deleteMessages($uid, $_folderName, 'move_to_trash');
						break;
					case 'forward' :	// Return the status of the forward attempt
						$returnVal = $this->forward_message2($mailobject, $uid, $mailcontent['subject'], lang("automatic mails (bounces) are configured to be forwarded"), $queue);
						if ($returnVal)
						{
							$mailobject->flagMessages('seen', $uid, $_folderName);
							$mailobject->flagMessages('forwarded', $uid, $_folderName);
						}
					default :			// default: 'ignore'
						break;
				}
				return true;
			}
		}

		// Check for autoreplies
		foreach ($autoMails['autoreplies'] as $_k => $_v) {
			if (count($_v) == 0) {
				continue;
			}
			$_re = '/(' . implode('|', $_v) . ')/i';
			if (preg_match($_re, $msgHeader[strtoupper($_k)])) {
				switch ($this->mailhandling[0]['autoreplies']) {
					case 'delete' :		// Delete, whatever the overall delete setting is
						$returnVal = $mailobject->deleteMessages($uid, $_folderName, 'move_to_trash');
						break;
					case 'forward' :	// Return the status of the forward attempt
						$returnVal = $this->forward_message2($mailobject, $uid, $mailcontent['subject'], lang("automatic mails (replies) are configured to be forwarded"), $queue);
						if ($returnVal)
						{
							$mailobject->flagMessages('seen', $uid, $_folderName);
							$mailobject->flagMessages('forwarded', $uid, $_folderName);
						}
						break;
					case 'process' :	// Process normally...
						return false;	// ...so act as if it's no automail
					default :			// default: 'ignore'
						break;
				}
				return true;
			}
		}
	}

	/**
	 * Decode a mail header
	 *
	 * @param string Pointer to the (possibly) encoded header that will be changes
	 */
	function decode_header (&$header)
	{
	}

	/**
	 * Process a messages from the mailbox
	 *
	 * @param int Message ID from the server
	 * @param int queue tracking queue_id
	 * @return boolean true=message successfully processed, false=message couldn't or shouldn't be processed
	 */
	function process_message ($mid, $queue)
	{
	}

	/**
	 * Process a messages from the mailbox
	 *
	 * @param int mailobject that holds connection to the server
	 * @param int Message ID from the server
	 * @param string _folderName the folder where the messages should reside in
	 * @param int queue tracking queue_id
	 * @return boolean true=message successfully processed, false=message couldn't or shouldn't be processed
	 */
	function process_message2 ($mailobject, $uid, $_folderName, $queue)
	{
		$senderIdentified = true;
		$sR = $mailobject->getHeaders($_folderName, $_startMessage=1, 1, 'INTERNALDATE', true, array(), $uid, false);
		$s = $sR['header'][$uid];
		$subject_in = $mailobject->decode_subject($s['subject']);// we use the needed headers for determining beforehand, if we have a new ticket, or a comment
		// FLAGS - control in case filter wont work
		$flags = $s;//implicit with retrieved information on getHeaders
		if ($flags['deleted'] || $flags['seen'])
		{
			return false; // Already seen or deleted (in case our filter did not work as intended)
		}
		// should do the same as checking only recent, but is more robust as recent is a flag with some sideeffects
		// message should be marked/flagged as seen after processing
		// (don't forget to flag the message if forwarded; as forwarded is not supported with all IMAP use Seen instead)
		if (($flags['recent'] && $flags['seen']) ||
			($flags['answered'] && $flags['seen']) || // is answered and seen
			$flags['draft']) // is Draft
		{
			if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__.':'."UID:$uid in Folder $_folderName with".' Subject:'.$subject_in.
				"\n Date:".$s['date'].
	            "\n Flags:".print_r($flags,true).
				"\n Stopped processing Mail ($uid). Not recent, new, or already answered, or draft");
			return false;
		}
		$subject = Mail::adaptSubjectForImport($subject_in);
		$tId = $this->get_ticketId($subject);
		if ($tId)
		{
			$t = $this->read($tId);
			$this->htmledit = $t['tr_edit_mode']=='html';
		}
		$addHeaderInfoSection = false;
		if (isset($this->mailhandling[$queue]['mailheaderhandling']) && $this->mailhandling[$queue]['mailheaderhandling']>0)
		{
			//$tId == 0 will be new ticket, else will indicate comment
			if ($this->mailhandling[$queue]['mailheaderhandling']==1) $addHeaderInfoSection=($tId == 0 ? true : false);
			if ($this->mailhandling[$queue]['mailheaderhandling']==2) $addHeaderInfoSection=($tId == 0 ? false: true);
			if ($this->mailhandling[$queue]['mailheaderhandling']==3) $addHeaderInfoSection=true;
		}
		if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__."# $uid with title:".$subject.($tId==0?' for new ticket':' for ticket:'.$tId).'. FetchMailHeader:'.$addHeaderInfoSectiont.' mailheaderhandling:'.$this->mailhandling[$queue]['mailheaderhandling']);
		$mailcontent = $mailobject::get_mailcontent($mailobject,$uid,$partid='',$_folderName,$this->htmledit,$addHeaderInfoSection,(!($GLOBALS['egw_info']['user']['preferences']['mail']['saveAsOptions']==='text_only')));

		// on we go, as everything seems to be in order. flagging the message
		$rv = $mailobject->flagMessages('seen', $uid, $_folderName);
		if ( PEAR::isError($rv)) error_log(__METHOD__.__LINE__." failed to flag Message $uid as Seen in Folder: ".$_folderName.' due to:'.$rv->message);

		// this one adds the mail itself (as message/rfc822 (.eml) file) to the infolog as additional attachment
		// this is done to have a simple archive functionality
		if ($mailcontent && $GLOBALS['egw_info']['user']['preferences']['mail']['saveAsOptions']==='add_raw')
		{
			$message = $mailobject->getMessageRawBody($uid, $partid, $_folderName);
			$headers = $mailobject->getMessageHeader($uid, $partid,true,false,$_folderName);
			$subject = Mail::adaptSubjectForImport($headers['SUBJECT']);
			$attachment_file =tempnam($GLOBALS['egw_info']['server']['temp_dir'],$GLOBALS['egw_info']['flags']['currentapp']."_");
			$tmpfile = fopen($attachment_file,'w');
			fwrite($tmpfile,$message);
			fclose($tmpfile);
			$size = filesize($attachment_file);
			$mailcontent['attachments'][] = array(
					'name' => trim($subject).'.eml',
					'mimeType' => 'message/rfc822',
					'type' => 'message/rfc822',
					'tmp_name' => $attachment_file,
					'size' => $size,
				);
		}
		if (self::LOG_LEVEL>1 && $mailcontent)
		{
			error_log(__METHOD__.__LINE__.'#'.array2string($mailcontent));
			if (!empty($mailcontent['attachments'])) error_log(__METHOD__.__LINE__.'#'.array2string($mailcontent['attachments']));
		}
		if (!$mailcontent)
		{
			error_log(__METHOD__.__LINE__." Could not retrieve Content for message $uid in $_folderName for Server with ID:".$mailobject->icServer->ImapServerId." for Queue: $queue");
			return false;
		}
		// prepare the data to be saved
		// (use bo function connected to the ui interface mail import, so after preparing we need to adjust stuff)
		$mailcontent['subject'] = Mail::adaptSubjectForImport($mailcontent['subject']);
		$this->data = $this->prepare_import_mail(
			$mailcontent['mailaddress'],
			$mailcontent['subject'],
			$mailcontent['message'],
			$mailcontent['attachments'],
			($tId?$tId:0),
			$queue
		);
		if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.array2string($this->data));
		if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.' Mailaddress:'.array2string($mailcontent['mailaddress']));
		if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__.':'.$this->mailhandling[$queue]['unrecognized_mails'].':'.($this->data['tr_id']?$this->data['reply_creator']:$this->data['tr_creator']).' vs. '.array2string($this->user).' Ticket:'.$this->data['tr_id'].' Message:'.$this->data['msg']);

		// handle auto - mails
		if ($this->is_automail2($mailobject, $uid, $mailcontent['subject'], $mailcontent['headers'], $queue)) {
			if (self::LOG_LEVEL>1) error_log(__METHOD__.' Automails will not be processed.');
			return false;
		}
		if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.array2string($this->data['msg']).':'.$this->data['tr_creator'].'=='.$this->data['reply_creator'].'=='. $this->user);
		// Handle unrecognized mails: we get a warning from prepare_import_mail, when mail is not recognized
		// ToDo: Introduce a key, to be able to tell the error-condition
		if (!empty($this->data['msg']) && (($this->data['tr_creator'] == $this->user) || ($this->data['tr_id'] && $this->data['reply_creator'] == $this->user)))
		{
			if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__.array2string($this->data['msg']).':'.$this->data['tr_creator'].'=='. $this->user);
			if ($this->data['tr_id'] && $this->data['reply_creator'] == $this->user) unset($this->data['reply_creator']);
			$senderIdentified = false;
			$replytoAddress = $mailcontent['mailaddress'];
			if (self::LOG_LEVEL>1) error_log(__METHOD__.__LINE__.' ReplyToAddress:'.$replytoAddress);
			switch ($this->mailhandling[$queue]['unrecognized_mails'])
			{
				case 'ignore' :		// Do nothing
					return false;
				case 'delete' :		// Delete, whatever the overall delete setting is
					$mailobject->deleteMessages($uid, $_folderName, 'move_to_trash');
					return false;	// Prevent from a second delete attempt
				case 'forward' :	// Return the status of the forward attempt
					$returnVal = $this->forward_message2($mailobject, $uid, $mailcontent['subject'], $this->data['msg'], $queue);
					if ($returnVal)
					{
						$mailobject->flagMessages('seen', $uid, $_folderName);
						$mailobject->flagMessages('forwarded', $uid, $_folderName);
					}
					return $returnVal;
				case 'default' :	// Save as default user; handled below
				default :			// Duh ??
					break;
			}
		}
		else
		{
			$replytoAddress = ($this->data['tr_id']?$this->data['reply_creator']:$this->data['tr_creator']);
		}

		// do not fetch the possible ticketID (again), use what is returned by prepare_import_mail
		$this->ticketId = $this->data['tr_id'];


		if ($this->ticketId == 0) // Create new ticket?
		{
			if (empty($this->mailhandling[$queue]['default_tracker']))
			{
				return false; // Not allowed
			}
			if (!$senderIdentified) // Unknown user
			{
				if (empty($this->mailhandling[$queue]['unrec_mail']))
				{
					return false; // Not allowed for unknown users
				}
				$this->mailSender = $this->mailhandling[$queue]['unrec_mail']; // Ok, set default user
			}
		}
		else
		{
			$this->mailSender = (!$senderIdentified?$this->mailhandling[$queue]['unrec_mail']:$this->data['reply_creator']);
		}

		if ($this->ticketId == 0)
		{

			$this->data['tr_tracker'] = $this->mailhandling[$queue]['default_tracker'];
			$this->data['cat_id'] = $this->mailhandling[$queue]['default_cat'];
			$this->data['tr_version'] = $this->mailhandling[$queue]['default_version'];
			$this->data['tr_priority'] = 5;
			if (!$senderIdentified && isset($this->mailSender))  $this->data['tr_creator'] = $this->user = $this->mailSender;
			//error_log(__METHOD__.__LINE__.array2string($this->data));
		}
		else
		{
			// Extract latest reply from the mail message content and replace it for last comment
			$this->data['reply_message'] = $this->extract_latestReply($this->data['reply_message']);

			if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.array2string($this->data['reply_message']));
			if (!$senderIdentified)
			{
				if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.':'.$this->data['tr_creator'].':'.$this->mailhandling[$queue]['unrec_mail'].':'.$this->user.':'.$this->mailSender.'#');
				switch ($this->mailhandling[$queue]['unrec_reply'])
				{
					case 0 :
						$this->user = (!empty($this->data['tr_creator'])?$this->data['tr_creator']:(!empty($this->mailhandling[$queue]['unrec_mail'])?$this->mailhandling[$queue]['unrec_mail']:$this->user));
						break;
					case 1 :
						$this->user = 0;
						break;
					default :
						$this->user = (!empty($this->mailhandling[$queue]['unrec_mail'])?$this->mailhandling[$queue]['unrec_mail']:0);
						break;
				}
			}
			else
			{
				$this->user = $this->mailSender;
			}
		}
		if ($this->ticketId == 0 && (!isset($this->mailhandling[$queue]['auto_cc']) || $this->mailhandling[$queue]['auto_cc']==false))
		{
			unset($this->data['tr_cc']);
		}

		if ($this->data['popup']) unset($this->data['popup']);
		// Save Current edition mode preventing mixed types
		if ($this->data['tr_edit_mode'] == 'html' && !$this->htmledit)
		{
			$this->data['tr_edit_mode'] = 'html';
		}
		elseif ($this->data['tr_edit_mode'] == 'ascii' && $this->htmledit)
		{
			$this->data['tr_edit_mode'] = 'ascii';
		}
		else
		{
			$this->htmledit ? $this->data['tr_edit_mode'] = 'html' : $this->data['tr_edit_mode'] = 'ascii';
		}
		if (self::LOG_LEVEL>1 && $replytoAddress) error_log(__METHOD__.__LINE__.' Replytoaddress:'.array2string($replytoAddress).' Text:'.$this->mailhandling[$queue]['reply_text']);
		// Save the ticket and let tracker_bo->save() handle the autorepl, if required
		$saverv = $this->save(null,
			(($this->mailhandling[$queue]['auto_reply'] == 2		// Always reply or
			|| ($this->mailhandling[$queue]['auto_reply'] == 1	// only new tickets
				&& $this->ticketId == 0)					// and this is a new one
				) && (										// AND
					$senderIdentified		 				// we know this user
				|| (!$senderIdentified						// or we don't and
				&& $this->mailhandling[$queue]['reply_unknown'] == 1 // don't care
			))) == true
				? array(
					'reply_text' => $this->mailhandling[$queue]['reply_text'],
					// UserID or mail address
					'reply_to' => ($replytoAddress ? $replytoAddress : $this->user),
				)
				: null
		);
		// attachments must be saved/linked after saving the ticket
		if (($saverv==0) && is_array($mailcontent['attachments']))
		{
			foreach ($mailcontent['attachments'] as $attachment)
			{
				//error_log(__METHOD__.__LINE__.'#'.$attachment['tmp_name'].'#'.$this->data['tr_id']);
				if(is_readable($attachment['tmp_name']))
				{
					//error_log(__METHOD__.__LINE__.'# trying to link '.$attachment['tmp_name'].'# to:'.$this->data['tr_id']);
					Link::attach_file('tracker',$this->data['tr_id'],$attachment);
				}
			}
		}

		return !$saverv;
	}

	/**
	 * flag message after processing
	 *
	 */
	function flagMessageAsSeen($mid, $messageHeader)
	{
	}

	/**
	 * Get an email address in plain format, no matter how the address was specified
	 *
	 * @param string $addr a string (probably) containing an email address
	 */
	function extract_mailaddress($addr='')
	{
	}

	/**
	 * Retrieve the user ID based on the mail address that was extracted from the mailheaders
	 *
	 * @param string $mail_addr ='' the mail address.
	 */
	function search_user($mail_addr='')
	{
	}

	/**
	 * Forward a mail that was not recognized
	 *
	 * @param int message ID from the server
	 * @return boolean status
	 */
	function forward_message($mid=0, &$headers=null, $queue=0)
	{
	}

	/**
	 * Forward a mail that was not recognized
	 *
	 * @param object mailobject holding the server, and its connection
	 * @param int message ID from the server
	 * @param string subject the messages subject
	 * @param array _message full retrieved message
	 * @param int queue the queue we are in
	 * @return boolean status
	 */
	function forward_message2($mailobject, $uid, $subject, $_message, $queue=0)
	{
		$this->smtpMail->ClearAddresses();
		$this->smtpMail->clearParts();
		$this->smtpMail->AddAddress(
			$this->mailhandling[$queue]['forward_to'],
			$this->mailhandling[$queue]['forward_to']
		);

		// Mailer Headers
		$headers = array (
			'X-EGroupware-type'		=> 'tracker-forward',
			'X-EGroupware-Tracker'	=> $queue,
			'X-EGroupware-Install'	=> $GLOBALS['egw_info']['server']['install_id'].
				'@'.$GLOBALS['egw_info']['server']['default_domain'],
			'Subject'				=> lang('[FWD]').' '.$subject
		);

		$notificationSender = !empty($this->notification[$queue]['sender']) ?
				$this->notification[$queue]['sender'] : $this->notification[0]['sender'];

		if (!empty($notificationSender))
		{
			$this->smtpMail->setFrom($notificationSender, $notificationSender);
		}
		else
		{
			$this->smtpMail->setFrom(
				Api\Accounts::id2name($this->mailSender, 'account_email'),
				Api\Accounts::id2name($this->mailSender, 'account_lid')
			);
		}

		$this->smtpMail->addHeaders($headers);
		$this->smtpMail->setBody(
			lang("This message was forwarded to you from EGroupware-Tracker".
					" Mailhandling: %1. \r\nSee attachment (original mail)".
					" for further details\r\n %2", $queue, $_message)
		);

		$rawBody = $mailobject->getMessageRawBody(
			$uid,
			'',
			!empty($this->mailhandling[$queue]['folder']) ?	$this->mailhandling[$queue]['folder'] : 'INBOX'
		);

		$this->smtpMail->AddStringAttachment($rawBody, $subject, 'message/rfc822');

		if(!$error=$this->smtpMail->Send())
		{
			error_log(__METHOD__.__LINE__." Failed forwarding message via email.$error".print_r($this->smtpMail->ErrorInfo,true));
			return false;
		}
		if (self::LOG_LEVEL>2) error_log(__METHOD__.__LINE__.array2string($this->smtpMail));
		return true;
	}

	/**
	 * Check if exist and if not start or stop an async job to check incoming mails
	 *
	 * @param int $queue ID of the queue to check email for
	 * @param int $interval =1 >0=start, 0=stop
	 */
	static function set_async_job($queue=0, $interval=0)
	{
		$async = new Api\Asyncservice();
		$job_id = 'tracker-check-mail' . ($queue ? '-'.$queue : '');

		// Make sure an existing timer is cancelled
		$async->cancel_timer($job_id);

		if ($interval > 0)
		{
			if ($interval == 60)
			{
				$async->set_timer(array('hour' => '*'),$job_id,'tracker.tracker_mailhandler.check_mail',array('tr_tracker' => $queue));
			}
			else
			{
				$async->set_timer(array('min' => "*/$interval"),$job_id,'tracker.tracker_mailhandler.check_mail',array('tr_tracker' => $queue));
			}
		}
	}
}
