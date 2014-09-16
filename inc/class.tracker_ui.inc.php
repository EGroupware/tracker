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
 * User Interface of the tracker
 */
class tracker_ui extends tracker_bo
{
	/**
	 * Functions callable via menuaction
	 *
	 * @var array
	 */
	var $public_functions = array(
		'edit'  => true,
		'index' => true,
		'tprint'=> true,
		'import_mail' => True,
	);
	/**
	 * Displayed instead of the '@' in email-addresses
	 *
	 * @var string
	 */
	var $mangle_at = ' -at- ';
	/**
	 * reference to the preferences of the user
	 *
	 * @var array
	 */
	var $prefs;

	/**
	 * allowed units and hours per day, can be overwritten by the projectmanager configuration, default all units, 8h
	 *
	 * @var string
	 */
	var $duration_format = ',';	// comma is necessary!

	/**
	 * Constructor
	 *
	 * @return tracker_ui
	 */
	function __construct()
	{
		parent::__construct();
		$this->prefs =& $GLOBALS['egw_info']['user']['preferences']['tracker'];

		// read the duration format from project-manager
		if ($GLOBALS['egw_info']['apps']['projectmanager'])
		{
			$pm_config = config::read('projectmanager');
			$this->duration_format = str_replace(',','',$pm_config['duration_units']).','.$pm_config['hours_per_workday'];
			unset($pm_config);
		}
	}

	/**
	 * Print a tracker item
	 *
	 * @param array $content=null eTemplate content
	 * @return string html-content, if sitemgr otherwise null
	 */
	function tprint($content=null)
	{
		// Check if exists
		if ((int)$_GET['tr_id'])
		{
			if (!$this->read($_GET['tr_id']))
			{
				return lang('Tracker item not found !!!');
			}
		}
		else	// new item
		{
			return lang('Tracker item not found !!!');
		}
		if (!is_object($this->tracking))
		{
			$this->tracking = new tracker_tracking($this);
		}

		if ($this->data['tr_edit_mode'] == 'html')
		{
			$this->tracking->html_content_allow = true;
		}

		$details = $this->tracking->get_body(true,$this->data,$this->data);
		if (!$details)
		{
			return implode(', ',$this->tracking->errors);
		}
		// Adding Automatic print func
		$GLOBALS['egw']->js->set_onload('window.print();');
		$GLOBALS['egw']->framework->render($details,'',false);
	}

	/**
	 * Edit a tracker item in a popup
	 *
	 * @param array $content=null eTemplate content
	 * @param string $msg=''
	 * @param boolean $popup=true use or not use a popup
	 * @return string html-content, if sitemgr otherwise null
	 */
	function edit($content=null,$msg='',$popup=true)
	{
		if ($this->htmledit || (isset($content['tr_edit_mode']) && $content['tr_edit_mode']=='html'))
		{
			$rte_features = $GLOBALS['egw_info']['user']['preferences']['common']['rte_features'];
			$tr_description_options = $rte_features.',240px,100%,false';
			$tr_reply_options = $rte_features.',215px,100%,false';
		}
		else
		{
			$tr_description_options = 'ascii,230px,99%';
			$tr_reply_options = 'ascii,205px,99%';
		}

		//_debug_array($content);
		if (!is_array($content))
		{
			if ($_GET['msg']) $msg = strip_tags($_GET['msg']);

			// edit or new?
			if ((int)$_GET['tr_id'])
			{
				$own_referer = common::get_referer();
				if (!$this->read($_GET['tr_id']))
				{
					$msg = lang('Tracker item not found !!!');
					$this->init();
				}
				else
				{
					// Set the ticket as seen by this user
					$seen = self::seen($this->data, true);

					// editing, preventing/fixing mixed ascii-html
					if ($this->data['tr_edit_mode'] == 'ascii' && $this->htmledit)
					{
						// non html items edited by html (add nl2br)
						$tr_description_options = 'simple,240px,100%,false,,1';
					}
					if ($this->data['tr_edit_mode'] == 'html' && !$this->htmledit)
					{
						// html items edited in ascii mode (prevent changing to html)
						$tr_description_options = 'simple,240px,100%,false';
						$tr_reply_options = 'simple,215px,100%,false';
					}
					//echo "<p>data[tr_edit_mode]={$this->data['tr_edit_mode']}, this->htmledit=".array2string($this->htmledit)."</p>\n";
					// Ascii Replies are converted to html, if htmledit is disabled (default), we allways convert, as this detection is weak
					foreach ($this->data['replies'] as &$reply)
					{
						//error_log(__METHOD__.__LINE__.' Ticket:#'.$this->data['tr_id'].':'.strlen( $reply['reply_message']));
						if (!($this->htmledit || $this->data['tr_edit_mode'] == 'html') || (strlen($reply['reply_message'])==strlen(strip_tags($reply['reply_message'])))) //(stripos($reply['reply_message'], '<br') === false && stripos($reply['reply_message'], '<p>') === false))
						{
							$reply['reply_message'] = nl2br(html::htmlspecialchars($reply['reply_message']));
						}
						if (($this->htmledit || $this->data['tr_edit_mode'] == 'html') && strlen( $reply['reply_message'] ) > 64000)
						{
							//error_log(__METHOD__.__LINE__.' Ticket:#'.$this->data['tr_id'].':'.strlen( $reply['reply_message']));
							$htmLawed_config = array(
								'keep_bad'=>2, //remove tags but keep element content (4 and 6 keep element content only if text (pcdata) is valid in parent element as per specs, this may lead to textloss if balance is switched on)
								'balance'=>1,//turn off tag-balancing (config['balance']=>0). That will not introduce any security risk; only standards-compliant tag nesting check/filtering will be turned off (basic tag-balance will remain; i.e., there won't be any unclosed tag, etc., after filtering)
								'direct_list_nest' => 1,
								'allow_for_inline' => array('table','div','li','p'),//block elements allowed for nesting when only inline is allowed; Example span does not allow block elements as table; table is the only element tested so far
							);
							$reply['reply_message'] = html::purify( $reply['reply_message'] ,$htmLawed_config);
						}
					}
				}
				$needInit = false;
			}
			else	// new item
			{
				$needInit = true;
				$regardInInit = array();
			}
			// for new items we use the session-state or $_GET['tracker']
			if (!$this->data['tr_id'])
			{
				$regardInInit = array();
				if (($state = egw_session::appsession('index','tracker'.
					(isset($this->trackers[(int)$_GET['only_tracker']]) ? '-'.$_GET['only_tracker'] : ''))))
				{
					$this->data['tr_tracker'] = $regardInInit['tr_tracker'] = $state['col_filter']['tr_tracker'] ? $state['col_filter']['tr_tracker'] : $this->data['tr_tracker'];
					$this->data['cat_id']     = $regardInInit['cat_id'] = $state['cat_id'];
					$this->data['tr_version'] = $regardInInit['tr_version'] = $state['filter2'] ? $state['filter2'] : $GLOBALS['egw_info']['user']['preferences']['tracker']['default_version'];
				}
				if (isset($this->trackers[(int)$_GET['tracker']]))
				{
					$this->data['tr_tracker'] = $regardInInit['tr_tracker'] = (int)$_GET['tracker'];
				}
				$this->data['tr_priority'] = $regardInInit['tr_priority'] = 5;
			}
			// initialize and try to merge what we already have
			if ($needInit)
			{
				$this->init($regardInInit);
			}
			if ($_GET['no_popup'] || $_GET['nopopup']) $popup = false;

			if ($popup)
			{
				$GLOBALS['egw_info']['flags']['java_script'] .= "<script>\nwindow.focus();\n</script>\n";
			}
			// check if user has rights to create new entries and fail if not
			if (!$this->data['tr_id'] && !$this->check_rights($this->field_acl['add'],null,null,null,'add'))
			{
				$msg = lang('Permission denied !!!');
				if ($popup)
				{
					$GLOBALS['egw']->framework->render('<h1 style="color: red;">'.$msg."</h1>\n",null,true);
					common::egw_exit();
				}
				else
				{
					unset($_GET['tr_id']);	// in case it's still set
					return $this->index(null,$this->data['tr_tracker'],$msg);
				}
			}
			// on resticted trackers, check if the user has read access, OvE, 20071012
			$restrict = false;
			if($this->data['tr_id'])
			{
				if (!$this->is_staff($this->data['tr_tracker']) &&	// user has to be staff or
					!array_intersect($this->data['tr_assigned'],	// he or a group he is a member of is assigned
						array_merge((array)$this->user,$GLOBALS['egw']->accounts->memberships($this->user,true))))
				{
					// if we have group OR creator restrictions
					if ($this->restrictions[$this->data['tr_tracker']]['creator'] ||
						$this->restrictions[$this->data['tr_tracker']]['group'])
					{
						// we need to be creator OR group member
						if (!($this->restrictions[$this->data['tr_tracker']]['creator'] &&
								$this->data['tr_creator'] == $this->user ||
							$this->restrictions[$this->data['tr_tracker']]['group'] &&
								in_array($this->data['tr_group'], $GLOBALS['egw']->accounts->memberships($this->user,true))))
						{
							$restrict = true;	// if not --> no access
						}
					}
					// Check queue access if enabled and that no has access to queue 0 (All)
					if ($this->enabled_queue_acl_access && !$this->trackers[$this->data['tr_tracker']] && !$this->is_user(0,$this->user))
					{
						$restrict = true;
					}
				}
			}
			if ($restrict)
			{
				$msg = lang('Permission denied !!!');
				if ($popup)
				{
					$GLOBALS['egw']->framework->render('<h1 style="color: red;">'.$msg."</h1>\n",null,false);
					common::egw_exit();
				}
				else
				{
					unset($_GET['tr_id']);	// in case it's still set
					return $this->index(null,$this->data['tr_tracker'],$msg);
				}
			}
		}
		else	// submitted form
		{
			//_debug_array($content);
			list($button) = @each($content['button']); unset($content['button']);
			if ($content['bounties']['bounty']) $button = 'bounty'; unset($content['bounties']['bounty']);
			$popup = $content['popup']; unset($content['popup']);
			$own_referer = $content['own_referer']; unset($content['own_referer']);

			$this->data = $content;
			unset($this->data['bounties']['new']);
			switch($button)
			{
				case 'save':
					if (!$this->data['tr_id'] && !$this->check_rights($this->field_acl['add'],null,null,null,'add'))
					{
						$msg = lang('Permission denied !!!');
						break;
					}

					$readonlys = $this->readonlys_from_acl();

					// Save Current edition mode preventing mixed types -> we do not handle that info in tracker_bo
					// so we seem to allow mixed type
					if ($this->data['tr_edit_mode'] == 'html' && !$this->htmledit)
					{
						$this->data['tr_edit_mode'] = 'html';
					}
					elseif ($this->data['tr_edit_mode'] == 'ascii' && $this->htmledit && $readonlys['tr_description'])
					{
						$this->data['tr_edit_mode'] = 'ascii';
					}
					else
					{
						$this->htmledit ? $this->data['tr_edit_mode'] = 'html' : $this->data['tr_edit_mode'] = 'ascii';
					}
					$ret = $this->save();
					if ($ret === false)
					{
						$msg = lang('Nothing to save.');
						$state = egw_session::appsession('index','tracker'.($only_tracker ? '-'.$only_tracker : ''));
						//$js = "opener.location.href=opener.location.href.replace(/&tr_id=[0-9]+/,'')+(opener.location.href.indexOf('?')<0?'?':'&')+'msg=".addslashes(urlencode($msg)).
						$js = "opener.egw_refresh('".str_replace("'","\\'",$msg)."','tracker',{$this->data['tr_id']},'edit');";
							// only change to current tracker, if not all trackers displayed
							($state['col_filter']['tr_tracker'] ? '&tracker='.$this->data['tr_tracker'] : '')."';";

					}
					elseif ($ret === 'tr_modifier' || $ret === 'tr_modified')
					{
						$msg .= ($msg ? ', ' : '') .lang('Error: the entry has been updated since you opened it for editing!').'<br />'.
							lang('Copy your changes to the clipboard, %1reload the entry%2 and merge them.','<a href="'.
								htmlspecialchars(egw::link('/index.php',array(
									'menuaction' => 'tracker.tracker_ui.edit',
									'tr_id'    => $this->data['tr_id'],
									//'referer'    => $referer,
								))).'">','</a>');
						break;
					}
					elseif ($ret == 0)
					{
						$msg = lang('Entry saved');
						//apply defaultlinks
						usort($this->all_cats,create_function('$a,$b','return strcasecmp($a["name"],$b["name"]);'));
						foreach($this->all_cats as $cat)
						{
							if (!is_array($data = unserialize($cat['data']))) $data = array('type' => $data);
							//echo "<p>".$this->data['tr_tracker'].": $cat[name] ($cat[id]/$cat[parent]/$cat[main]): ".print_r($data,true)."</p>\n";

							if ($cat['parent'] == $this->data['tr_tracker'] && $data['type'] != 'tracker' && $data['type']=='project')
							{
								if (!egw_link::get_link('tracker',$this->data['tr_id'],'projectmanager',$data['projectlist']))
								{
									egw_link::link('tracker',$this->data['tr_id'],'projectmanager',$data['projectlist']);
								}
							}
						}
						if (is_array($content['link_to']['to_id']) && count($content['link_to']['to_id']))
						{
							egw_link::link('tracker',$this->data['tr_id'],$content['link_to']['to_id']);
						}
						$state = egw_session::appsession('index','tracker'.($only_tracker ? '-'.$only_tracker : ''));
						$js = "opener.egw_refresh('".str_replace("'","\\'",$msg)."','tracker',{$this->data['tr_id']},'edit');";
					}
					else
					{
						$msg = lang('Error saving the entry!!!');
						break;
					}
					// fall-through for save
				case 'cancel':
					if ($popup)
					{
						$js .= 'window.close();';
						echo "<html>\n<body>\n<script>\n$js\n</script>\n</body>\n</html>\n";
						common::egw_exit();
					}
					unset($_GET['tr_id']);	// in case it's still set
					if($own_referer && strpos($own_referer,'cd=yes') === false)
					{
						// Go back to where you came from
						egw::redirect_link($own_referer);
					}
					return $this->index(null,$this->data['tr_tracker'],$msg);

				case 'vote':
					if ($this->cast_vote())
					{
						$msg = lang('Thank you for voting.');
						if ($popup)
						{
							$js = "opener.egw_refresh('".str_replace("'","\\'",$msg)."','tracker',{$this->data['tr_id']},'edit');";
							$GLOBALS['egw_info']['flags']['java_script'] .= "<script>\n$js\n</script>\n";
						}
					}
					break;

				case 'bounty':
					if (!$this->allow_bounties) break;
					$bounty = $content['bounties']['new'];
					if (!$this->is_anonymous())
					{
						if (!$bounty['bounty_name']) $bounty['bounty_name'] = $GLOBALS['egw_info']['user']['account_fullname'];
						if (!$bounty['bounty_email']) $bounty['bounty_email'] = $GLOBALS['egw_info']['user']['account_email'];
					}
					if (!$bounty['bounty_amount'] || !$bounty['bounty_name'] || !$bounty['bounty_email'])
					{
						$msg = lang('You need to specify amount, donators name AND email address!');
					}
					elseif ($this->save_bounty($bounty))
					{
						$msg = lang('Thank you for setting this bounty.').
							' '.lang('The bounty will NOT be shown, until the money is received.');
						array_unshift($this->data['bounties'],$bounty);
						unset($content['bounties']['new']);
					}
					break;

				default:
					if (!$this->allow_bounties) break;
					// check delete bounty
					list($id) = @each($this->data['bounties']['delete']);
					if ($id)
					{
						unset($this->data['bounties']['delete']);
						if ($this->delete_bounty($id))
						{
							$msg = lang('Bounty deleted');
							foreach($this->data['bounties'] as $n => $bounty)
							{
								if ($bounty['bounty_id'] == $id)
								{
									unset($this->data['bounties'][$n]);
									break;
								}
							}
						}
						else
						{
							$msg = lang('Permission denied !!!');
						}
					}
					else
					{
						// check confirm bounty
						list($id) = @each($this->data['bounties']['confirm']);
						if ($id)
						{
							unset($this->data['bounties']['confirm']);
							foreach($this->data['bounties'] as $n => $bounty)
							{
								if ($bounty['bounty_id'] == $id)
								{
									if ($this->save_bounty($this->data['bounties'][$n]))
									{
										$msg = lang('Bounty confirmed');
										$js = "opener.location.href=opener.location.href.replace(/&tr_id=[0-9]+/,'')+'&msg=".addslashes(urlencode($msg))."';";
										$GLOBALS['egw_info']['flags']['java_script'] .= "<script>\n$js\n</script>\n";
									}
									else
									{
										$msg = lang('Permission denied !!!');
									}
									break;
								}
							}
						}
					}
					break;
			}
		}
		$tr_id = $this->data['tr_id'];
		if (!($tracker = $this->data['tr_tracker']))
		{
			reset($this->trackers);
			list($tracker) = @each($this->trackers);
		}
		if (!$readonlys) $readonlys = $this->readonlys_from_acl();

		if ($this->data['tr_edit_mode'] == 'ascii' && $this->data['tr_description'] && $readonlys['tr_description'])
		{
			// non html view in a readonly htmlarea (div) needs nl2br
			$tr_description_options = 'simple,240px,100%,false,,1';
		}

		$preserv = $content = $this->data;
		if ($content['num_replies']) array_unshift($content['replies'],false);	// need array index starting with 1!
		if ($this->allow_bounties)
		{
			if (is_array($content['bounties']))
			{
				$total = 0;
				foreach($content['bounties'] as $bounty)
				{
					$total += $bounty['bounty_amount'];
					// confirmed bounties cant be deleted and need no confirm button
					$readonlys['delete['.$bounty['bounty_id'].']'] =
						$readonlys['confirm['.$bounty['bounty_id'].']'] = !$this->is_admin($tracker) || $bounty['bounty_confirmed'];
				}
				$content['bounties']['num_bounties'] = count($content['bounties']);
				array_unshift($content['bounties'],false);	// we need the array index to start with 2!
				array_unshift($content['bounties'],false);
				$content['bounties']['total'] = $total ? sprintf('%4.2lf',$total) : '';
			}
			$content['bounties']['currency'] = $this->currency;
			$content['bounties']['is_admin'] = $this->is_admin($tracker);
		}
		$statis = $this->get_tracker_stati($tracker);
		$content += array(
			'msg' => $msg,
			'tr_description_options' => $tr_description_options,
			'tr_description_mode'    => $readonlys['tr_description'],
			'tr_reply_options' => $tr_reply_options,
			'on_cancel' => $popup ? 'window.close();' : '',
			'no_vote' => '',
			'show_dates' => $this->show_dates,
			'link_to' => array(
				'to_id' => $tr_id,
				'to_app' => 'tracker',
			),
			'status_help' => !$this->pending_close_days ? lang('Pending items never get close automatic.') :
				lang('Pending items will be closed automatic after %1 days without response.',$this->pending_close_days),
			'history' => array(
				'id'  => $tr_id,
				'app' => 'tracker',
				'status-widgets' => array(
					'Co' => 'select-percent',
					'St' => &$statis,
					'Ca' => 'select-cat',
					'Tr' => 'select-cat',
					'Ve' => 'select-cat',
					'As' => 'select-account',
					'Cr' => 'select-account',
					'pr' => array('Public','Private'),
					'Cl' => 'date-time',
					'tr_startdate' => 'date-time',
					'tr_duedate' => 'date-time',
					'Re' => self::$resolutions + $this->get_tracker_labels('resolution',$tracker),
					'Gr' => 'select-account',
				),
			),
		);
		if ($this->allow_bounties && !$this->is_anonymous())
		{
			$content['bounties']['user_name'] = $GLOBALS['egw_info']['user']['account_fullname'];
			$content['bounties']['user_email'] = $GLOBALS['egw_info']['user']['account_email'];
		}
		$preserv['popup'] = $popup;
		$preserv['own_referer'] = $own_referer;

		if (!$tr_id && isset($_REQUEST['link_app']) && isset($_REQUEST['link_id']) && !is_array($content['link_to']['to_id']))
		{
			$link_ids = is_array($_REQUEST['link_id']) ? $_REQUEST['link_id'] : array($_REQUEST['link_id']);
			foreach(is_array($_REQUEST['link_app']) ? $_REQUEST['link_app'] : array($_REQUEST['link_app']) as $n => $link_app)
			{
				$link_id = $link_ids[$n];
				if (preg_match('/^[a-z_0-9-]+:[:a-z_0-9-]+$/i',$link_app.':'.$link_id))	// gard against XSS
				{
					switch($link_app)
					{
						case 'infolog':
							static $infolog_bo;
							if(!$infolog_bo) $infolog_bo = new infolog_bo();
							$infolog = $app_entry = $infolog_bo->read($link_id);
							$content = array_merge($content, array(
								'tr_owner'	=> $infolog['info_owner'],
								'tr_private'	=> $infolog['info_access'] == 'private',
								'tr_summary'	=> $infolog['info_subject'],
								'tr_description'	=> $infolog['info_des'],
								'tr_cc'		=> $infolog['info_cc'],
								'tr_created'	=> $infolog['info_startdate']
							));

							// Categories are different, no globals.  Match by name.
							$match = array(
								$infolog_bo->enums['type'][$infolog['info_type']] => array(
									'field'	=> 'tr_tracker',
									'source'=> $this->trackers
								),
								categories::id2name($infolog['info_cat']) => array(
									'field'	=> 'cat_id',
									'source'=> $this->get_tracker_labels('cat',$tracker)
								)
							);
							foreach($match as $info_field => $info)
							{
								$content[$info['field']] = array_search($info_field,$info['source']);
							}

							// Try to match priorities
							foreach($this->get_tracker_priorities($content['tr_tracker'], $content['cat_id']) as $p => $label)
							{
								if(stripos($label, $infolog_bo->enums['priority'][$infolog['info_priority']]) !== false)
								{
									$content['tr_priority'] = $p;
									break;
								}
							}

							// Add responsible as participant - filtered later
							foreach($infolog['info_responsible'] as $responsible) {
								$content['tr_assigned'][] = $responsible;
							}

							// Copy infolog's links
							foreach(egw_link::get_links('infolog',$link_id) as $copy_link)
							{
								egw_link::link('tracker', $content['link_to']['to_id'], $copy_link['app'], $copy_link['id'],$copy_link['remark']);
							}
							break;

					}
					// Copy same custom fields
					$_cfs = config::get_customfields('tracker');
					$link_app_cfs = config::get_customfields($link_app);
					foreach($_cfs as $name => $settings)
					{
						if($link_app_cfs[$name]) $event['#'.$name] = $app_entry['#'.$name];
					}
					egw_link::link('tracker',$content['link_to']['to_id'],$link_app,$link_id);
				}
			}
		}
		// options for creator selectbox (allways add current selected user!)
		if ($readonlys['tr_creator'])
		{
			$creators = array();
		}
		else
		{
			$creators = $this->get_staff($tracker,0,'usersANDtechnicians');
		}
		if ($content['tr_creator'] && !isset($creators[$content['tr_creator']]))
		{
			$creators[$content['tr_creator']] = common::grab_owner_name($content['tr_creator']);
		}

		// Comment visibility
		if (is_array($content['replies']))
		{
			foreach($content['replies'] as $key => &$reply)
			{
				if (isset($content['replies'][$key]['reply_visible'])) {
					$reply['reply_visible_class'] = 'reply_visible_'.$reply['reply_visible'];
				}
			}
		}
		$content['no_comment_visibility'] = !$this->check_rights(TRACKER_ADMIN|TRACKER_TECHNICIAN|TRACKER_ITEM_ASSIGNEE,null,null,null,'no_comment_visibility') ||
			!$this->allow_restricted_comments;

		$sel_options = array(
			'tr_tracker'  => &$this->trackers,
			'cat_id'      => $this->get_tracker_labels('cat',$tracker),
			'tr_version'  => $this->get_tracker_labels('version',$tracker),
			'tr_priority' => $this->get_tracker_priorities($tracker,$content['cat_id']),
			'tr_status'   => &$statis,
			'tr_resolution' => $this->get_tracker_labels('resolution',$tracker),
			'tr_assigned' => $this->get_staff($tracker,$this->allow_assign_groups,$this->allow_assign_users?'usersANDtechnicians':'technicians'),
			'tr_creator'  => $creators,
			// New items default to primary group is no right to change the group
			'tr_group' => $this->get_groups(!$this->check_rights($this->field_acl['tr_group'],$tracker,null,null,'tr_group') && !$this->data['tr_id']),
			'canned_response' => $this->get_tracker_labels('response'),
		);

		foreach($this->field2history as $field => $status)
		{
			$sel_options['status'][$status] = $this->field2label[$field];
		}
		$sel_options['status']['xb'] = 'Bounty deleted';
		$sel_options['status']['bo'] = 'Bounty set';
		$sel_options['status']['Bo'] = 'Bounty confirmed';

		$readonlys['tabs'] = array(
			'comments' => !$tr_id || !$content['num_replies'],
			'add_comment' => !$tr_id || $readonlys['reply_message'],
			'history'  => !$tr_id,
			'bounties' => !$this->allow_bounties,
			'custom'   => !config::get_customfields('tracker', false, $content['tr_tracker']),
		);
		if ($tr_id && $readonlys['reply_message'])
		{
			$readonlys['button[save]'] = true;
		}
		if (!$tr_id && $readonlys['add'])
		{
			$msg = lang('Permission denied !!!');
			$readonlys['button[save]'] = true;
		}
		if (!$this->allow_voting || !$tr_id || $readonlys['vote'] || ($voted = $this->check_vote()))
		{
			$readonlys['button[vote]'] = true;
			if ($tr_id && $this->allow_voting)
			{
				$content['no_vote'] = is_int($voted) ? lang('You voted %1.',
					date($GLOBALS['egw_info']['user']['preferences']['common']['dateformat'].
					($GLOBALS['egw_info']['user']['preferences']['common']['timeformat']==12?' h:i a':' H:i'),$voted)) :
					lang('You need to login to vote!');
			}
		}
		if ($readonlys['canned_response'])
		{
			$content['no_canned'] = true;
		}
		$content['no_links'] = $readonlys['link_to'];
		$content['bounties']['no_set_bounties'] = $readonlys['bounty'];

		$what = ($tracker && isset($this->trackers[(is_array($tracker)?$tracker[0]:$tracker)]) ? $this->trackers[(is_array($tracker)?$tracker[0]:$tracker)] : lang('Tracker'));
		$GLOBALS['egw_info']['flags']['app_header'] = $tr_id ? lang('Edit %1',$what) : lang('New %1',$what);

		$tpl = new etemplate('tracker.edit');
		// use a type-specific template (tracker.edit.xyz), if one exists, otherwise fall back to the generic one
		if (!$tpl->read('tracker.edit'.(isset($this->trackers[(is_array($tracker)?$tracker[0]:$tracker)])?'.'.trim($this->trackers[(is_array($tracker)?$tracker[0]:$tracker)]):'')))
		{
			$tpl->read('tracker.edit');
		}

		if ($this->tracker_has_cat_specific_priorities($tracker))
		{
			$tpl->set_cell_attribute('cat_id','onchange',true);
		}
		// No notifications needs label hidden too
		if($readonlys['no_notifications'])
		{
			$tpl->set_cell_attribute('no_notifications', 'disabled', true);
		}

		if ($content['tr_assigned'] && !is_array($content['tr_assigned']))
		{
			$content['tr_assigned'] = explode(',',$content['tr_assigned']);
		}
		if (count($content['tr_assigned']) > 1)
		{
			$tpl->set_cell_attribute('tr_assigned','size','3+');
		}
		return $tpl->exec('tracker.tracker_ui.edit',$content,$sel_options,$readonlys,$preserv,$popup ? 2 : 0);
	}

	/**
	 * query rows for the nextmatch widget
	 *
	 * @param array $query with keys 'start', 'search', 'order', 'sort', 'col_filter'
	 *	For other keys like 'filter', 'cat_id' you have to reimplement this method in a derived class.
	 * @param array &$rows returned rows/competitions
	 * @param array &$readonlys eg. to disable buttons based on acl
	 * @return int total number of rows
	 */
	function get_rows(&$query_in,&$rows,&$readonlys)
	{
		if (!$this->allow_voting && $query_in['order'] == 'votes' ||	// in case the tracker-config changed in that session
			!$this->allow_bounties && $query_in['order'] == 'bounties') $query_in['order'] = 'tr_id';

		$query = $query_in;
		if (!$query['csv_export'])	// do not store query for csv-export in session
		{
			egw_session::appsession('index','tracker'.($query_in['only_tracker'] ? '-'.$query_in['only_tracker'] : ''),$query);
		}
		$tracker = $query['col_filter']['tr_tracker'];
		// Explode multiples into array
		if(!is_array($tracker) && strpos($tracker,',') !== false)
		{
			$tracker = $query['col_filter']['tr_tracker'] = explode(',',$query['col_filter']['tr_tracker']);
		}
		if (!($query['col_filter']['cat_id'] = $query['cat_id'])) unset($query['col_filter']['cat_id']);
		if (!($query['col_filter']['tr_version'] = $query['filter2'])) unset($query['col_filter']['tr_version']);

		if (!($query['col_filter']['tr_creator'])) unset($query['col_filter']['tr_creator']);

		if ($query['col_filter']['tr_assigned'] < 0)	// resolve groups with it's members
		{
			$query['col_filter']['tr_assigned'] = $GLOBALS['egw']->accounts->members($query['col_filter']['tr_assigned'],true);
			$query['col_filter']['tr_assigned'][] = $query_in['col_filter']['tr_assigned'];
		}
		elseif(!$query['col_filter']['tr_assigned'])
		{
			unset($query['col_filter']['tr_assigned']);
		}

		// save the state of the index page (filters) in the user prefs
		// need to save state, before resolving diverse col-filters, eg. to all group-members or sub-cats
		$state = serialize(array(
			'cat_id'     => $query['cat_id'],	// cat
			'filter'     => $query['filter'],	// dates
			'filter2'    => $query['filter2'],	// version
			'order'      => $query['order'],
			'sort'       => $query['sort'],
			'num_rows'   => $query['num_rows'],
			'col_filter' => array(
				'tr_tracker'  => $query['col_filter']['tr_tracker'],
				'tr_creator'  => $query['col_filter']['tr_creator'],
				'tr_assigned' => $query['col_filter']['tr_assigned'],
				'tr_status'   => $query['col_filter']['tr_status'],
			),
		));
		if (!$query['csv_export'] && $GLOBALS['egw']->session->session_flags != 'A' &&	// store the current state of non-anonymous users in the prefs
			$state != $GLOBALS['egw_info']['user']['preferences']['tracker']['index_state'])
		{
			//$msg .= "save the index state <br>";
			$GLOBALS['egw']->preferences->add('tracker','index_state',$state);
			// save prefs, but do NOT invalid the cache (unnecessary)
			$GLOBALS['egw']->preferences->save_repository(false,'user',false);
		}

		$tracker = $query['col_filter']['tr_tracker'];
		if (!($query['col_filter']['cat_id'] = $query['cat_id'])) unset($query['col_filter']['cat_id']);
		if (!($query['col_filter']['tr_version'] = $query['filter2'])) unset($query['col_filter']['tr_version']);

		if (!($query['col_filter']['tr_creator'])) unset($query['col_filter']['tr_creator']);

		if ($query['col_filter']['tr_assigned'] < 0)	// resolve groups with it's members
		{
			$query['col_filter']['tr_assigned'] = $GLOBALS['egw']->accounts->members($query['col_filter']['tr_assigned'],true);
			$query['col_filter']['tr_assigned'][] = $query_in['col_filter']['tr_assigned'];
		}
		elseif($query['col_filter']['tr_assigned'] === 'not')
		{
			$query['col_filter']['tr_assigned'] = null;
		}
		elseif(!$query['col_filter']['tr_assigned'])
		{
			unset($query['col_filter']['tr_assigned']);
		}
		if (empty($query['col_filter']['tr_tracker']))
		{
			$trtofilter = array_keys($this->trackers);
			//_debug_array($trtofilter);
			$query['col_filter']['tr_tracker'] = $trtofilter;
		}

		// Get list of currently displayed trackers, so we can get all valid statuses
		if($query['col_filter']['tr_tracker']) {
			$trackers = is_array($query['col_filter']['tr_tracker']) ? $query['col_filter']['tr_tracker'] : array($query['col_filter']['tr_tracker']);
		}
		else
		{
			$trackers = array();
		}

		if ($query['col_filter']['multi_queue']) unset($query['col_filter']['multi_queue']);

		//echo "<p align=right>uitracker::get_rows() order='$query[order]', sort='$query[sort]', search='$query[search]', start=$query[start], num_rows=$query[num_rows], col_filter=".print_r($query['col_filter'],true)."</p>\n";

		$total = parent::get_rows($query,$rows,$readonlys,$this->allow_voting||$this->allow_bounties);	// true = count votes and/or bounties
		foreach($rows as $n => $row)
		{
			// Check if this is a new (unseen) ticket for the current user
			if (self::seen($row, false))
			{
				$rows[$n]['seen_class'] = 'seen';
			}
			else
			{
				$rows[$n]['seen_class'] = 'unseen';
			}

			$trackers[] = $row['tr_tracker'];

			// show the right tracker and/or cat specific priority label
			if ($row['tr_priority'])
			{
				if (is_null($prio_labels) || $this->priorities && ($row['tr_tracker'] != $prio_tracker || $row['cat_id'] != $prio_cat))
				{
					$prio_labels = $this->get_tracker_priorities($prio_tracker=$row['tr_tracker'],$prio_cat = $row['cat_id']);
					if ($prio_labels === self::$stock_priorities)	// show only the numbers for the stock priorities
					{
						$prio_labels = array_combine(array_keys(self::$stock_priorities),array_keys(self::$stock_priorities));
					}
				}
				$rows[$n]['prio_label'] = $prio_labels[$row['tr_priority']];
			}
			if (isset($rows[$n]['tr_description'])) $rows[$n]['tr_description'] = nl2br($rows[$n]['tr_description']);
			if ($row['overdue']) $rows[$n]['overdue_class'] = 'overdue';
			if ($row['bounties']) $rows[$n]['currency'] = $this->currency;
			// in case there is no pref setting for show_sum_timesheet anymore, check for selected column tr_sum_timesheets
			if ((!$this->prefs['show_sum_timesheet'] || !isset($this->prefs['show_sum_timesheet'])) && (stripos($query_in['selectcols'],'tr_sum_timesheets')!==false) ) $this->prefs['show_sum_timesheet']=1;
			if (isset($GLOBALS['egw_info']['user']['apps']['timesheet']) && $this->prefs['show_sum_timesheet'])
			{
				unset($links);
				if (($links = egw_link::get_links('tracker',$row['tr_id'])) &&
					isset($GLOBALS['egw_info']['user']['apps']['timesheet']))
				{
					// loop through all links of the entries
					$timesheets = array();
					foreach ($links as $link)
					{
						if ($link['app'] == 'projectmanager')
						{
							//$info['pm_id'] = $link['id'];
						}
						if ($link['app'] == 'timesheet') $timesheets[] = $link['id'];
					}
					if (isset($GLOBALS['egw_info']['user']['apps']['timesheet']) && $timesheets && $this->prefs['show_sum_timesheet'])
					{
						$sum = ExecMethod('timesheet.timesheet_bo.sum',$timesheets);
						$rows[$n]['tr_sum_timesheets'] = $sum['duration'];
					}
				}
			}
			//_debug_array($rows[$n]);
			//echo "<p>".$this->trackers[$row['tr_tracker']]."</p>";
			$id=$row['tr_id'];
			$readonlys["infolog[$id]"]= !(isset($GLOBALS['egw_info']['user']['apps']['infolog']) && $this->allow_infolog);
			$readonlys["timesheet[$id]"]= !(isset($GLOBALS['egw_info']['user']['apps']['timesheet']) && ($this->is_admin($row['tr_tracker']) or ($this->is_technician($row['tr_tracker']))));
			$readonlys["document[$id]"] = !$GLOBALS['egw_info']['user']['preferences']['tracker']['default_document'];
			$readonlys['checked'] = !($this->is_admin($row['tr_tracker']) || $this->is_technician($row['tr_tracker']));
		}

		$rows['duration_format'] = ','.$this->duration_format.',,1';
		$rows['sel_options']['tr_assigned'] = array('not' => lang('Not assigned'));

		// Add allowed staff
		foreach((array)$tracker as $tr_id)
		{
			$rows['sel_options']['tr_assigned'] += $this->get_staff($tr_id,2,$this->allow_assign_users?'usersANDtechnicians':'technicians');
		}
		$rows['sel_options']['assigned'] = $rows['sel_options']['tr_assigned']; // For context menu popup
		unset($rows['sel_options']['assigned']['not']);


		$versions = $cats = $resolutions = $statis = array();
		foreach((array)$tracker as $tr_id)
		{
			$versions += $this->get_tracker_labels('version',$tr_id);
			$cats += $this->get_tracker_labels('cat',$tr_id);
			$resolutions += $this->get_tracker_labels('resolution',$tr_id);
			$statis += $this->get_tracker_stati($tr_id);
		}

		$trackers = array_unique($trackers);
		if($trackers)
		{
			foreach($trackers as $tracker_id)
			{
				$statis += $this->get_tracker_stati($tracker_id);
				$resolutions += $this->get_tracker_labels('resolution',$tracker_id);
			}
		}

		$rows['sel_options']['tr_status'] = $this->filters+$statis;
		$rows['sel_options']['cat_id'] = $cats;
		$rows['sel_options']['filter2'] = array(lang('All'))+$versions;
		$rows['sel_options']['tr_version'] =& $versions;
		$rows['sel_options']['tr_resolution'] =& $resolutions;
		if ($this->is_admin($tracker))
		{
			$rows['sel_options']['canned_response'] = $this->get_tracker_labels('response',$tracker);
			$rows['sel_options']['tr_status_admin'] =& $statis;
			$rows['is_admin'] = true;
		}
		if (!$this->allow_voting)
		{
			$rows['no_votes'] = true;
			$query_in['options-selectcols']['votes'] = false;
		}
		if (!$this->allow_bounties)
		{
			$rows['no_bounties'] = true;
			$query_in['options-selectcols']['bounties'] = false;
		}

		if ($query['col_filter']['cat_id']) $rows['no_cat_id'] = true;

		// enable tracker column if all trackers are shown
		if ($tracker && !$query['multi_queue']) $rows['no_tr_tracker'] = true;

		// disable start date / due date column, if disabled in config
		if(!$this->show_dates)
		{
			$rows['no_tr_startdate_tr_duedate'] = true;
		}

		// Disable checkbox column
		$rows['no_check'] = $readonlys['checked'];
		$trackerlabel = array();
		foreach((array)$tracker as $t){$trackerlabel[]=$this->trackers[$t];}
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Tracker').': '.($tracker ? join(',',$trackerlabel) : lang('All'));
		return $total;
	}

	/**
	 * Hook for timesheet to set some extra data and links
	 *
	 * @param array $data
	 * @param int $data[id] tracker_id
	 * @return array with key => value pairs to set in new timesheet and link_app/link_id arrays
	 */
	function timesheet_set($data)
	{
		$set = array();
		if ((int)$data['id'] && ($ticket = $this->read($data['id'])))
		{
			//error_log(__METHOD__.__LINE__.$this->exclude_app_on_timesheetcreation);
			foreach(egw_link::get_links('tracker',$ticket['tr_id'],'','link_lastmod DESC',true) as $link)
			{
				//if ($link['app'] != 'timesheet' && $link['app'] != egw_link::VFS_APPNAME)
				if (stripos($this->exclude_app_on_timesheetcreation.','.'timesheet'.','.egw_link::VFS_APPNAME,$link['app'])===false)
				{
					$set['link_app'][] = $link['app'];
					$set['link_id'][]  = $link['id'];
				}
			}
		}
		return $set;
	}

	/**
	 * Hook for InfoLog to set some extra data and links
	 *
	 * @param array $data
	 * @param int $data[id] tracker_id
	 * @return array with key => value pairs to set in new infolog and link_app/link_id arrays
	 */
	function infolog_set($data)
	{
		if (!($tracker = $this->read($data['id'])))
		{
			return array();
		}
		$set = array(
			'info_subject' => $tracker['tr_summary'],
			'info_des'     => $tracker['tr_description'],
			'info_contact' => 'tracker:'.$tracker['tr_id'],
		);
		// copy links
		foreach(egw_link::get_links('tracker',$tracker['tr_id'],'','link_lastmod DESC',true) as $link)
		{
			$set['link_app'][] = $link['app'];
			$set['link_id'][]  = $link['id'];

			// prefer addressbook or projectmanager link as primary contact over default of this ticket
			if (in_array($link['app'], array('addressbook','projectmanager')) &&
				strpos($set['info_contact'], 'addressbook:') !== 0)
			{
				$set['info_contact'] = $link['app'].':'.$link['id'];
			}
		}
		// copy same named customfields
		foreach(config::get_customfields('infolog') as $name => $nul)
		{
			if(array_key_exists('#'.$name, $tracker))
			{
				$set['#'.$name] = $tracker['#'.$name];
			}
		}
		return $set;
	}
	/**
	 * Check if a ticket has already been seen
	 *
	 * @param array $data=null Ticket data
	 * @param boolean $update=false Set ticket as seen when true
	 * @param boolean $been_seen=true Mark the ticket as seen/unseen by current user
	 * @return boolean true=seen before false=new ticket
	 */
	function seen(&$data, $update=false, $been_seen = true)
	{
		$seen = array();
		if ($data['tr_seen']) $seen = unserialize($data['tr_seen']);
		if ($update === false)
		{
			return in_array($this->user, $seen);
		}
		if($been_seen)
		{
			$seen[] = $this->user;
		}
		else
		{
			$key = array_search($this->user,$seen);
			if($key !== false)
			{
				unset($seen[$key]);
			}
		}
		$this->db->update('egw_tracker', array('tr_seen' => serialize(array_unique($seen))),
			array('tr_id' => $data['tr_id']),__LINE__,__FILE__,'tracker');
		return false; // This time still false...
	}

	/**
	 * Show a tracker
	 *
	 * @param array $content=null eTemplate content
	 * @param int $tracker=null id of tracker
	 * @param string $msg=''
	 * @param int $only_tracker=null show only the given tracker and not tracker-selection
	 * @param boolean $return_html=false if set to true, html content returned
	 * @return string html-content, if sitemgr otherwise null
	 */
	function index($content=null,$tracker=null,$msg='',$only_tracker=null, $return_html=false)
	{
		//_debug_array($this->trackers);
		if (!is_array($content))
		{
			if ($_GET['tr_id'])
			{
				if (!$this->read($_GET['tr_id']))
				{
					$msg = lang('Tracker item not found !!!');
				}
				else
				{
					return $this->edit(null,'',false);	// false = use no popup
				}
			}
			if (!$msg && $_GET['msg']) $msg = $_GET['msg'];
			if ($only_tracker && isset($this->trackers[$only_tracker]))
			{
				$tracker = $only_tracker;
			}
			else
			{
				$only_tracker = null;
			}
			// if there is no tracker specified, try the tracker submitted
			if (!$tracker && (int)$_GET['tracker']) $tracker = $_GET['tracker'];
			// if there is still no tracker, use the last tracker that was applied and saved to/with the view with the appsession
			if (!$tracker && ($state=egw_session::appsession('index','tracker'.($only_tracker ? '-'.$only_tracker : ''))))
			{
			      $tracker=$state['col_filter']['tr_tracker'];
			}
			$multi_queue = $this->prefs['multi_queue'];
		}
		else
		{
			$only_tracker = $content['only_tracker']; unset($content['only_tracker']);
			$tracker = $content['nm']['col_filter']['tr_tracker'];

			// Multiple queues at once
			list($multi_queue) = @each($content['nm']['col_filter']['multi_queue']);
			$multi_queue = $multi_queue == 'true' ? true : ($multi_queue == 'false' ? false : $this->prefs['multi_queue']);
			if($multi_queue != $this->prefs['multi_queue'])
			{
				// Store in preferences
				$this->prefs['multi_queue'] = $multi_queue;
				$GLOBALS['egw']->preferences->add('tracker','multi_queue',$multi_queue);
				// save prefs, but do NOT invalid the cache (unnecessary)
				$GLOBALS['egw']->preferences->save_repository(false,'user',false);
				if(!$multi_queue)
				{
					// Only select 1 queue when going from multi to single
					$tracker = explode(',',$tracker);
					$tracker = $content['nm']['col_filter']['tr_tracker'] = $tracker[0];
				}
			}

			if (is_array($content) && isset($content['nm']['rows']['document']))  // handle insert in default document button like an action
			{
				list($id) = @each($content['nm']['rows']['document']);
				$content['nm']['action'] = 'document';
				$content['nm']['selected'] = array($id);
			}
			if ($content['admin'] && $content['nm']['action'] == 'admin')
			{
				$content['nm']['action'] = $content['admin'];
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
						$content['nm']['action'] .= '_' . key($content[$multi_action . '_action']);

						if(is_array($content[$multi_action]))
						{
							$content[$multi_action] = implode(',',$content[$multi_action]);
						}
						$content['nm']['action'] .= '_' . $content[$multi_action];
						unset($content[$multi_action]);
					}
					if ($this->action($content['nm']['action'],$content['nm']['selected'],$content['nm']['select_all'],
						$success,$failed,$action_msg,'index',$msg,$content['nm']['checkboxes']['no_notifications']))
					{
						$msg .= lang('%1 entries %2',$success,$action_msg);
					}
					else
					{
						if(is_null($msg) || $msg == '')
						{
							$msg = lang('%1 entries %2, %3 failed because of insufficent rights !!!',$success,$action_msg,$failed);
						}
					}
				}
			}
		}

		if (!$tracker) $tracker = $content['nm']['col_filter']['tr_tracker'];
		$sel_options = array(
			'tr_tracker'  => &$this->trackers,
			'tr_status'   => $this->filters + $this->get_tracker_stati($tracker),
			'tr_priority' => $this->get_tracker_priorities($tracker,$content['cat_id']),
			'tr_resolution' => $this->get_tracker_labels('resolution',$tracker),
			'tr_private'  => array('No', 'Yes'),
		);
		if (($escalations = ExecMethod2('tracker.tracker_escalations.query_list','esc_title','esc_id')))
		{
			$sel_options['esc_id']['already escalated'] = $escalations;
			foreach($escalations as $esc_id => $label)
			{
				$sel_options['esc_id']['matching filter']['-'.$esc_id] = $label;
			}
		}
		// Merge print
		if ($GLOBALS['egw_info']['user']['preferences']['tracker']['document_dir'])
		{
			$documents = tracker_merge::get_documents($GLOBALS['egw_info']['user']['preferences']['tracker']['document_dir']);
			if($documents)
			{
				$sel_options['action'][lang('Insert in document').':'] = $documents;
			}
		}

		if (!is_array($content)) $content = array();
		$content = array_merge($content,array(
			'nm' => egw_session::appsession('index','tracker'.($only_tracker ? '-'.$only_tracker : '')),
			'msg' => $msg,
			'status_help' => !$this->pending_close_days ? lang('Pending items never get close automatic.') :
				lang('Pending items will be closed automatic after %1 days without response.',$this->pending_close_days),
		));

		if (!is_array($content['nm']))
		{
			$date_filters = array(lang('All'));
			foreach($this->date_filters as $name => $date)
			{
				$date_filters[$name] = lang($name);
			}
			$content['nm'] = array(
				'get_rows'       =>	'tracker.tracker_ui.get_rows',
				'cat_is_select'  => 'no_lang',
				'filter'         => 0,  // all
				'options-filter' => $date_filters,
				'filter_label'   => lang('Date filter'),
				'filter_no_lang'=> true,
				'filter2'        => 0,	// all
				'filter2_label'  => lang('Version'),
				'filter2_no_lang'=> true,
				'order'          =>	$this->allow_bounties ? 'bounties' : ($this->allow_voting ? 'votes' : 'tr_id'),// IO name of the column to sort after (optional for the sortheaders)
				'sort'           =>	'DESC',// IO direction of the sort: 'ASC' or 'DESC'
				'options-tr_assigned' => array('not' => lang('Noone')),
				'col_filter'     => array(
					'tr_status'  => 'not-closed',	// default filter: not closed
				),
	 			'header_left'    =>	$only_tracker ? null : 'tracker.index.left', // I  template to show left of the range-value, left-aligned (optional)
	 			'only_tracker'   => $only_tracker,
	 			'header_right'   =>	'tracker.index.right', // I  template to show right of the range-value, left-aligned (optional)
	 			'default_cols'   => '!esc_id,legacy_actions,tr_summary_tr_description',
				'row_id'         => 'tr_id',
			);
			// use the state of the last session stored in the user prefs
			if (($state = @unserialize($GLOBALS['egw_info']['user']['preferences']['tracker']['index_state'])))
			{
				$content['nm'] = array_merge($content['nm'],$state);
				$tracker = $content['nm']['col_filter']['tr_tracker'];
			}
			elseif (!$tracker)
			{
				reset($this->trackers);
				list($tracker) = @each($this->trackers);
			}
			// disable times column, if no timesheet rights
			if (!isset($GLOBALS['egw_info']['user']['apps']['timesheet']))
			{
				$content['nm']['options-selectcols']['tr_sum_timesheets'] = false;
			}
			// disable start date / due date column, if disabled in config
			if(!$this->show_dates)
			{
				// Need to set each field so parser takes the whole column
				$content['nm']['options-selectcols']['tr_startdate'] = false;
				$content['nm']['options-selectcols']['tr_duedate'] = false;
			}
		}
		$content['nm']['actions'] = $this->get_actions($tracker, $content['cat_id']);
		$content['nm']['multi_queue'] = $multi_queue;

		if($_GET['search'])
		{
			$content['nm']['search'] = $_GET['search'];
		}
		// if there is only one tracker, use that one and do NOT show the selectbox
		if (count($this->trackers) == 1)
		{
			reset($this->trackers);
			list($tracker) = @each($this->trackers);
			$readonlys['nm']['col_filter[tr_tracker]'] = true;
		}
		if (!$tracker)
		{
			$tracker = $content['nm']['col_filter']['tr_tracker'] = '';
		}
		else
		{
			$content['nm']['col_filter']['tr_tracker'] = $tracker;
		}

		// Turn on multi-queue widget
		$content['nm']['header_left'] = $content['nm']['multi_queue'] ? 'tracker.index.left_multiqueue' : 'tracker.index.left';

		$content['is_admin'] = $this->is_admin($tracker);
		//_debug_array($content);
		$readonlys['add'] = $readonlys['nm']['add'] = !$this->check_rights($this->field_acl['add'],$tracker,null,null,'add');
		$tpl = new etemplate();
		if (!$tpl->sitemgr || !$tpl->read('tracker.index.sitemgr'))
		{
			$tpl->read('tracker.index');
		}
		// disable filemanager icon, if user has no access to it
		$readonlys['filemanager/navbar'] = !isset($GLOBALS['egw_info']['user']['apps']['filemanager']);

		// Disable actions if there are none
		if(count($sel_options['action']) == 0)
		{
			$tpl->disable_cells('action', true);
			$tpl->disable_cells('use_all', true);
		}

		// Show only own groups in group popup if queue acl
		if($this->enabled_queue_acl_access)
		{
			$group = explode(',',$tpl->get_cell_attribute('group', 'size'));
			$group[1] = 'owngroups';
			$tpl->set_cell_attribute('group', 'size', implode(',',$group));
		}
		egw_framework::validate_file('.','app','tracker');
		// add scrollbar to long description, if user choose so in his prefs
		if ($this->prefs['limit_des_lines'] > 0 || (string)$this->prefs['limit_des_lines'] == '');
		{
			//$content['css'] = '<style type="text/css">@import url('.$GLOBALS['egw_info']['server']['webserver_url'].'/tracker/templates/default/app.css);'."</style>";

			$content['css'] .= '<style type="text/css">@media screen { .trackerDes {  '.
				($this->prefs['limit_des_width']?'max-width:'.$this->prefs['limit_des_width'].'em;':'').' max-height: '.
				(($this->prefs['limit_des_lines'] ? $this->prefs['limit_des_lines'] : 5) * 1.35).	// dono why em is not real lines
				'em; overflow: auto; }}
@media screen { .colfullWidth {
width:100%;
}</style>';
		}

		return $tpl->exec('tracker.tracker_ui.index',$content,$sel_options,$readonlys,array('only_tracker' => $only_tracker),$return_html);
	}

	/**
	 * Get actions / context menu items
	 *
	 * @param int $tracker=null
	 * @param int $cat_id=null
	 * @return array see nextmatch_widget::get_actions()
	 */
	private function get_actions($tracker=null, $cat_id=null)
	{
		for($i = 0; $i <= 100; $i += 10) $percent[$i] = $i.'%';

		$actions = array(
			'open' => array(
				'caption' => 'Open',
				'default' => true,
				'allowOnMultiple' => false,
				'url' => 'menuaction=tracker.tracker_ui.edit&tr_id=$id',
				'popup' => egw_link::get_registry('tracker', 'add_popup'),
				'group' => $group=1,
			),
			'print' => array(
				'caption' => 'Print',
				'allowOnMultiple' => false,
				'url' => 'menuaction=tracker.tracker_ui.tprint&tr_id=$id',
				'popup' => egw_link::get_registry('tracker', 'add_popup'),
				'group' => $group,
			),
			'add' => array(
				'caption' => 'Add',
				'group' => $group,
				'url' => 'menuaction=tracker.tracker_ui.edit',
				'popup' => egw_link::get_registry('tracker', 'add_popup'),
			),
			'select_all' => array(
				'caption' => 'Whole query',
				'checkbox' => true,
				'hint' => 'Apply the action on the whole query, NOT only the shown entries',
				'group' => ++$group,
			),
			'no_notifications' => array(
				'caption' => 'Do not notify',
				'checkbox' => true,
				'hint' => 'Do not notify of these changes',
				'group' => $group,
			),
			// modifying content of one or multiple infolog(s)
			'change' => array(
				'caption' => 'Change',
				'group' => ++$group,
				'icon' => 'edit',
				'disableClass' => 'rowNoEdit',
				'children' => array(
					'seen' => array(
						'caption' => 'Mark as read',
						'group' => 1,
					),
					'unseen' => array(
						'caption' => 'Mark as unread',
						'group' => 1,
					),
					'tracker' => array(
						'caption' => 'Tracker Queue',
						'prefix' => 'tracker_',
						'children' => $this->trackers,
						'disabled' => count($this->trackers) <= 1,
						'hideOnDisabled' => true,
						'icon' => 'tracker/navbar',
					),
					'cat' => array(
						'caption' => 'Category',
						'prefix' => 'cat_',
						'children' => $items=$this->get_tracker_labels('cat',$tracker),
						'disabled' => count($items) <= 1,
						'hideOnDisabled' => true,
					),
					'version' => array(
						'caption' => 'Version',
						'prefix' => 'version_',
						'children' => $items=$this->get_tracker_labels('version',$tracker),
						'disabled' => count($items) <= 1,
						'hideOnDisabled' => true,
					),
					'assigned' => array(
						'caption' => 'Assigned to',
						'icon' => 'users',
						'nm_action' => 'open_popup',
					),
					'priority' => array(
						'caption' => 'Priority',
						'prefix' => 'priority_',
						'children' => $items=$this->get_tracker_priorities($tracker,$cat_id),
						'disabled' => count($items) <= 1,
						'hideOnDisabled' => true,
					),
					'status' => array(
						'caption' => 'Status',
						'prefix' => 'status_',
						'children' => $items=$this->get_tracker_stati($tracker),
						'disabled' => count($items) <= 1,
						'hideOnDisabled' => true,
						'icon' => 'check',
					),
					'resolution' => array(
						'caption' => 'Resolution',
						'prefix' => 'resolution_',
						'children' => $items=$this->get_tracker_labels('resolution',$tracker), // ToDo: get tracker specific solutions as well, have them available only when applicable
						'disabled' => count($items) <= 1,
						'hideOnDisabled' => true,
					),
					'completion' => array(
						'caption' => 'Completed',
						'prefix' => 'completion_',
						'children' => $percent,
						'icon' => 'completed',
					),
					'group' => array(
						'caption' => 'Group',
						'nm_action' => 'open_popup',
					),
					'link' => array(
						'caption' => 'Links',
						'nm_action' => 'open_popup',
					),
				),
			),
			'close' => array(
				'caption' => 'Close',
				'icon' => 'check',
				'group' => $group,
				'disableClass' => 'rowNoClose',
			),

			'admin' => array(
				'caption' => 'Multiple changes',
				'group' => $group,
				'disabled' => !isset($GLOBALS['egw_info']['user']['apps']['admin']),
				'hideOnDisabled' => true,
				'nm_action' => 'open_popup',
				'icon' => 'user',
			),
		);
		++$group;	// integration with other apps
		if ($GLOBALS['egw_info']['user']['apps']['filemanager'])
		{
			$actions['filemanager'] = array(
				'icon' => 'filemanager/navbar',
				'caption' => 'Filemanager',
				'url' => 'menuaction=filemanager.filemanager_ui.index&path=/apps/tracker/$id',
				'allowOnMultiple' => false,
				'group' => $group,
			);
		}
		if ($GLOBALS['egw_info']['user']['apps']['timesheet'])
		{
			$actions['timesheet'] = array(	// interactive add for a single event
				'icon' => 'timesheet/navbar',
				'caption' => 'Timesheet',
				'url' => 'menuaction=timesheet.timesheet_ui.edit&link_app[]=tracker&link_id[]=$id',
				'group' => $group,
				'allowOnMultiple' => false,
				'popup' => egw_link::get_registry('timesheet', 'add_popup'),
			);
		}
		if ($GLOBALS['egw_info']['user']['apps']['infolog'] && $this->allow_infolog)
		{
			$actions['infolog'] = array(
				'icon' => 'infolog/navbar',
				'caption' => 'InfoLog',
				'url' => 'menuaction=infolog.infolog_ui.edit&action=tracker&action_id=$id',
				'group' => $group,
				'allowOnMultiple' => false,
				'popup' => egw_link::get_registry('infolog', 'add_popup'),
			);
		}

		$actions['documents'] = infolog_merge::document_action(
			$this->prefs['document_dir'], ++$group, 'Insert in document', 'document_',
			$this->prefs['default_document']
		);

		//echo "<p>".__METHOD__."($do_email, $tid_filter, $org_view)</p>\n"; _debug_array($actions);
		return $actions;
	}

	/**
	 * imports a mail as tracker
	 * two possible calls:
	 * 1. with function args set. (we come from send mail)
	 * 2. with $_GET['uid] = someuid (we come from display mail)
	 *
	 * @author klaus Leithoff <kl@stylite.de>
	 * @param string $_to_emailAddress
	 * @param string $_subject
	 * @param string $_body
	 * @param array $_attachments
	 * @param string $_date
	 */
	function import_mail($_to_emailAddress=false,$_subject=false,$_body=false,$_attachments=false,$_date=false)
	{
		$uid = $_GET['uid'];
		$partid = $_GET['part'];
		$mailbox = base64_decode($_GET['mailbox']);
		$mailClass = 'felamimail_bo';
		$sessionLocation = 'felamimail';
		// if rowid is set, we are called from new mail module.
		if (method_exists('mail_ui','splitRowID') && isset($_GET['rowid']) && !empty($_GET['rowid']))
		{
			// rowid holds all needed information: server, folder, uid, etc.
			$rowID = $_GET['rowid'];
			$hA = mail_ui::splitRowID($rowID);
			$sessionLocation = $hA['app']; // THIS is part of the row ID, we may use this for validation
			if ($sessionLocation != 'mail') throw new egw_exception_assertion_failed(lang('Application mail expected but got: %1',$sessionLocation));
			$uid = $hA['msgUID'];
			$mailbox = $hA['folder'];
			$icServerID = $hA['profileID'];
			$mailClass = 'mail_bo';
		}
		// load lang stuff for mailheaderInfoSection creation
		translation::add_app($sessionLocation);
		if ($_date == false || empty($_date)) $_date = $this->bo->user_time_now;
		if (!empty($_to_emailAddress))
		{
			$GLOBALS['egw_info']['flags']['currentapp'] = 'tracker';
			echo '<script>window.resizeTo(750,550);</script>';

			if (!($GLOBALS['egw_info']['user']['preferences'][$sessionLocation]['saveAsOptions']==='text_only')&&is_array($_attachments))
			{
				//echo __METHOD__.'<br>';
				//_debug_array($_attachments);
				$icServerID =& egw_cache::getSession($sessionLocation,'activeProfileID');
				$mailobject	= $mailClass::getInstance(true,$icServerID);
				$mailobject->openConnection();
				foreach ($_attachments as $attachment)
				{
					if (trim(strtoupper($attachment['type'])) == 'MESSAGE/RFC822' && !empty($attachment['uid']) && !empty($attachment['folder']))
					{
						$mailobject->reopen($attachment['folder']);

						// get the message itself, and attach it, as we are able to display it in egw
						// instead of fetching only the attachments attached files (as we did previously)
						$message = $mailobject->getMessageRawBody($attachment['uid'],$attachment['partID']);
						$headers = $mailobject->getMessageHeader($attachment['uid'],$attachment['partID'],true);
						$subject = str_replace('$$','__',($headers['SUBJECT']?$headers['SUBJECT']:lang('(no subject)')));
						$attachment_file =tempnam($GLOBALS['egw_info']['server']['temp_dir'],$GLOBALS['egw_info']['flags']['currentapp']."_");
						$tmpfile = fopen($attachment_file,'w');
						fwrite($tmpfile,$message);
						fclose($tmpfile);
						$size = filesize($attachment_file);
						$attachments[] = array(
								'name' => trim($subject).'.eml',
								'mimeType' => 'message/rfc822',
								'tmp_name' => $attachment_file,
								'size' => $size,
							);
					}
					else
					{
						if (!empty($attachment['folder']))
						{
							$is_winmail = $_GET['is_winmail'] ? $_GET['is_winmail'] : 0;
							$mailobject->reopen($attachment['folder']);
							$attachmentData = $mailobject->getAttachment($attachment['uid'],$attachment['partID'],$is_winmail);
							$attachment['file'] =tempnam($GLOBALS['egw_info']['server']['temp_dir'],$GLOBALS['egw_info']['flags']['currentapp']."_");
							$tmpfile = fopen($attachment['file'],'w');
							fwrite($tmpfile,$attachmentData['attachment']);
							fclose($tmpfile);
						}

						$attachments[] = array(
							'name' => $attachment['name'],
							'mimeType' => $attachment['type'],
							'tmp_name' => $attachment['file'],
							'size' => $attachment['size'],
						);
					}
				}
				$mailobject->closeConnection();
			}

			// this one adds the mail itself (as message/rfc822 (.eml) file) to the infolog as additional attachment
			// this is done to have a simple archive functionality (ToDo: opening .eml in email module)
			if ($_rawMailHeader && $_rawMailBody && $GLOBALS['egw_info']['user']['preferences'][$sessionLocation]['saveAsOptions']==='add_raw')
			{
				$message = ltrim(str_replace("\n","\r\n",$_rawMailHeader)).str_replace("\n","\r\n",$_rawMailBody);
				$subject = $mailClass::adaptSubjectForImport($subject);
				$attachment_file =tempnam($GLOBALS['egw_info']['server']['temp_dir'],$GLOBALS['egw_info']['flags']['currentapp']."_");
				$tmpfile = fopen($attachment_file,'w');
				fwrite($tmpfile,$message);
				fclose($tmpfile);
				$size = filesize($attachment_file);
				$attachments[] = array(
						'name' => trim($subject).'.eml',
						'mimeType' => 'message/rfc822',
						'tmp_name' => $attachment_file,
						'size' => $size,
					);
			}

			//_debug_array($_to_emailAddress);
			$toaddr = array();
			foreach(array('to','cc','bcc') as $x) if (is_array($_to_emailAddress[$x]) && !empty($_to_emailAddress[$x])) $toaddr = array_merge($toaddr,$_to_emailAddress[$x]);
			//_debug_array($attachments);
			$_body = strip_tags($mailClass::htmlspecialchars($_body)); //we need to fix broken tags (or just stuff like "<800 USD/p" )
			$_body = htmlspecialchars_decode($_body,ENT_QUOTES);
			$_subject = $mailClass::adaptSubjectForImport($_subject);
			$tId = $this->get_ticketId($_subject);
			if ($tId)
			{
				$t = $this->read($tId);
				$this->htmledit = $t['tr_edit_mode']=='html';
			}
			$body = $mailClass::createHeaderInfoSection(array('FROM'=>$_to_emailAddress['from'],
				'TO'=>(!empty($_to_emailAddress['to'])?implode(',',$_to_emailAddress['to']):null),
				'CC'=>(!empty($_to_emailAddress['cc'])?implode(',',$_to_emailAddress['cc']):null),
				'BCC'=>(!empty($_to_emailAddress['bcc'])?implode(',',$_to_emailAddress['bcc']):null),
				'SUBJECT'=>$_subject,
				'DATE'=>$mailClass::_strtotime($_date)),'',$this->htmledit).$_body;
			if ($this->htmledit) $body = '<pre>'.$body.'</pre>';
			$this->edit($this->prepare_import_mail(
				implode(',',$toaddr),$_subject,$body,$attachments,$_date
			));
			exit;
		}
		elseif ($uid && $mailbox)
		{
			$icServerID =& egw_cache::getSession($sessionLocation,'activeProfileID');
			$mailobject	= $mailClass::getInstance(true,$icServerID);
			$mailobject->openConnection();
			$mailobject->reopen($mailbox);
			$headers = $mailobject->getMessageHeader($uid, $partid,true);
			$subject = $mailClass::adaptSubjectForImport($headers['SUBJECT']);
			$tId = $this->get_ticketId($subject);
			if ($tId)
			{
				$t = $this->read($tId);
				$this->htmledit = $t['tr_edit_mode']=='html';
			}

			$mailcontent = $mailClass::get_mailcontent($mailobject,$uid,$partid,$mailbox,$this->htmledit,true,(!($GLOBALS['egw_info']['user']['preferences'][$sessionLocation]['saveAsOptions']==='text_only')));

			// this one adds the mail itself (as message/rfc822 (.eml) file) to the infolog as additional attachment
			// this is done to have a simple archive functionality (ToDo: opening .eml in email module)
			if ($GLOBALS['egw_info']['user']['preferences'][$sessionLocation]['saveAsOptions']==='add_raw')
			{
				$message = $mailobject->getMessageRawBody($uid, $partid);
				$headers = $mailobject->getMessageHeader($uid, $partid,true);
				$subject = $mailClass::adaptSubjectForImport($headers['SUBJECT']);
				$attachment_file =tempnam($GLOBALS['egw_info']['server']['temp_dir'],$GLOBALS['egw_info']['flags']['currentapp']."_");
				$tmpfile = fopen($attachment_file,'w');
				fwrite($tmpfile,$message);
				fclose($tmpfile);
				$size = filesize($attachment_file);
				$mailcontent['attachments'][] = array(
						'name' => trim($subject).'.eml',
						'mimeType' => 'message/rfc822',
						'tmp_name' => $attachment_file,
						'size' => $size,
					);
			}
			$mailcontent['subject'] = $mailClass::adaptSubjectForImport($mailcontent['subject']);
			return $this->edit($this->prepare_import_mail(
				$mailcontent['mailaddress'],
				$mailcontent['subject'],
				$mailcontent['message'],
				$mailcontent['attachments'],
				strtotime($mailcontent['headers']['DATE'])
			));
		}
		common::egw_header();
		echo "<script> window.close(); alert('Error: no mail (Mailbox / UID) given!');</script>";
		common::egw_exit();
		exit;
	}

	/**
	 * apply an action to multiple tracker entries
	 *
	 * @param string|int $action 'status_to',set status of entries
	 * @param array $checked tracker id's to use if !$use_all
	 * @param boolean $use_all if true use all entries of the current selection (in the session)
	 * @param int &$success number of succeded actions
	 * @param int &$failed number of failed actions (not enought permissions)
	 * @param string &$action_msg translated verb for the actions, to be used in a message like %1 entries 'deleted'
	 * @param string|array $session_name 'index' or 'email', or array with session-data depending if we are in the main list or the popup
	 * @param string &$msg
	 * @param boolean $no_notification
	 * @return boolean true if all actions succeded, false otherwise
	 */
	function action($action,$checked,$use_all,&$success,&$failed,&$action_msg,$session_name,&$msg,$no_notification)
	{
		//echo '<p>'.__METHOD__."('$action',".array2string($checked).','.(int)$use_all.",...)</p>\n";
		$success = $failed = 0;
		if ($use_all)
		{
			// get the whole selection
			$query = is_array($session_name) ? $session_name : $GLOBALS['egw']->session->appsession($session_name,'tracker');

			if ($use_all)
			{
				@set_time_limit(0);			// switch off the execution time limit, as it's for big selections to small
				$query['num_rows'] = -1;	// all
				$this->get_rows($query,$checked,$readonlys);
				// $this->get_rows gives some extra data.
				foreach($checked as $row => $data)
				{
					if(!is_numeric($row))
					{
						unset($checked[$row]);
					}
				}
			}
		}

		if (is_array($action) && $action['update'])
		{
			unset($action['update']);
			// remove all 'No change'
			foreach($action as $name => $value) if ($value === '') unset($action[$name]);

			if (!count($checked) || !count($action))
			{
				$msg = lang('You need to select something to change AND some tracker items!');
				$failed = true;
			}
			else
			{
				$n = 0;
				foreach($checked as $tr_id)
				{
					if (!$this->read($tr_id)) continue;
					foreach($action as $name => $value)
					{
						if ($name == 'tr_status_admin') $name = 'tr_status';
						$this->data[$name] = $name == 'tr_assigned' && $value === 'not' ? NULL : $value;
					}
					if($no_notification) $this->data['no_notifications'] = true;
					if (!$this->save())
					{
						$success++;
					}
					else
					{
						$failed++;
					}
				}
				$action_msg = lang('updated');
			}
		}
		else
		{
			// Dialogs to get options
			list($action, $settings) = explode('_', $action, 2);

			switch($action)
			{
				case 'close':
					$action_msg = lang('closed');
					foreach($checked as $tr_id)
					{
						if (!$this->read($tr_id)) continue;
						$this->data['tr_status'] = tracker_bo::STATUS_CLOSED;
						if($no_notification) $this->data['no_notifications'] = true;
						if (!$this->save())
						{
							$success++;
						}
						else
						{
							$failed++;
						}
					}
					break;
				case 'seen':
				case 'unseen':
					$action_msg = lang($action);
					foreach($checked as $tr_id)
					{
						if (!$this->read($tr_id)) continue;
						self::seen($this->data, true, $action == 'seen');
						$success++;
					}
					break;
				case 'group':
					// Popup adds an extra param (add/delete) that group doesn't need
					list(,$settings) = explode('_',$settings);
				case 'tracker':
				case 'cat':
				case 'version':
				case 'priority':
				case 'status':
				case 'resolution':
				case 'completion':
					$action_msg = lang('updated');
					foreach($checked as $tr_id)
					{
						if (!$this->read($tr_id)) continue;
						$this->data[($action == 'cat' ? 'cat_id' : 'tr_'.$action)] = $settings;
						if($no_notification) $this->data['no_notifications'] = true;
						if (!$this->save())
						{
							$success++;
						}
						else
						{
							$failed++;
						}
					}
					break;
				case 'assigned':
					$action_msg = lang('updated');
					foreach($checked as $tr_id)
					{
						if (!$this->read($tr_id)) continue;
						list($add_remove, $ids) = explode('_', $settings, 2);
						$ids = explode(',',$ids);
						$this->data['tr_assigned'] = $add_remove == 'add' ?
							array_merge($this->data['tr_assigned'],$ids) :
							array_diff($this->data['tr_assigned'],$ids);
						// No 0 allowed
						$this->data['tr_assigned'] = array_unique(array_diff($this->data['tr_assigned'], array(0)));
						if($no_notification) $this->data['no_notifications'] = true;
						if (!$this->save())
						{
							$success++;
						}
						else
						{
							$failed++;
						}
					}
					break;

				case 'link':
					list($add_remove, $link) = explode('_', $settings, 2);
					list($app, $link_id) = explode(':', $link);
					if(!$link_id)
					{
						$msg = lang('You need to select an entry for linking.');
						break;
					}
					$title = egw_link::title($app, $link_id);
					foreach($checked as $id)
					{
						if (!$this->read($id))
						{
							$failed++;
							continue;
						}
						if($add_remove == 'add')
						{
							$action_msg = lang('linked to %1', $title);
							if(egw_link::link('tracker', $id, $app, $link_id))
							{
								$success++;
							}
							else
							{
								$failed++;
							}
						}
						else
						{
							$action_msg = lang('unlinked from %1', $title);
							$count = egw_link::unlink(0, 'tracker', $id, '', $app, $link_id);
							$success += $count;
						}
					}
					return $failed == 0;
					break;

				case 'document':
					if (!$settings) $settings = $GLOBALS['egw_info']['user']['preferences']['tracker']['default_document'];
					$document_merge = new tracker_merge();
					$msg = $document_merge->download($settings, $checked, '', $GLOBALS['egw_info']['user']['preferences']['tracker']['document_dir']);
					$failed = count($checked);
					return false;
			}
		}
		return !$failed;
	}

	/**
	 * Fill in canned comment
	 *
	 * @param id Canned comment ID
	 */
	public function ajax_canned_comment($id, $ckeditor=true)
	{
		$response = new xajaxResponse();
		if($ckeditor) {
			$response->script('if(CKEDITOR) { var ckeditor = CKEDITOR.instances["exec[reply_message]"];} if(ckeditor) ckeditor.setData("'.
				str_replace(array("\r","\n"), array('',''), nl2br($this->get_canned_response($id))) .
			 '");');
		} else {
			$response->assign('exec[reply_message]', 'value', $this->get_canned_response($id));
		}

		return $response->getXML();
	}
}
