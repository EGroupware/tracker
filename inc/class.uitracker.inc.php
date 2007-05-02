<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$ 
 */

require_once(EGW_INCLUDE_ROOT.'/tracker/inc/class.botracker.inc.php');
require_once(EGW_INCLUDE_ROOT.'/etemplate/inc/class.uietemplate.inc.php');

/**
 * User Interface of the tracker
 */
class uitracker extends botracker
{
	/**
	 * Functions callable via menuaction
	 *
	 * @var array
	 */
	var $public_functions = array(
		'edit'  => true,
		'index' => true,
		'admin' => true,
	);
	/**
	 * Displayed instead of the '@' in email-addresses
	 * 
	 * @var string
	 */
	var $mangle_at = ' -at- ';

	/**
	 * Constructor
	 *
	 * @return botracker
	 */
	function uitracker()
	{
		$this->botracker();
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
		$tabs = 'description|comments|add_comment|links|history|bounties';

		if (!is_array($content))
		{
			// edit or new?
			if ((int)$_GET['tr_id'])
			{
				if (!$this->read($_GET['tr_id']))
				{
					$msg = lang('Tracker item not found !!!');
					$this->init();
				}
			}
			else	// new item
			{
				$this->init();
			}
			// for new items we use the session-state or $_GET['tracker']
			if (!$this->data['tr_id'])
			{
				if (($state = $GLOBALS['egw']->session->appsession('index','tracker'.(isset($this->trackers[(int)$_GET['only_tracker']]) ? 
					'-'.$_GET['only_tracker'] : ''))))
				{
					$this->data['tr_tracker'] = $state['col_filter']['tr_tracker'];
					$this->data['cat_id']     = $state['filter'];
					$this->data['tr_version'] = $state['filter2'];
				}			
				if (isset($this->trackers[(int)$_GET['tracker']]))
				{
					$this->data['tr_tracker'] = (int)$_GET['tracker'];
				}
				$this->data['tr_priority'] = 5;
			}
			if ($_GET['nopopup']) $popup = false;
			
			if ($popup)
			{
				$GLOBALS['egw_info']['flags']['java_script'] .= "<script>\nwindow.focus();\n</script>\n";
			}
			// check if user has rights to create new entries and fail if not
			if (!$this->data['tr_id'] && !$this->check_rights($this->field_acl['add']))
			{
				$msg = lang('Permission denied !!!');
				if ($popup)
				{
					$GLOBALS['egw']->framework->render('<h1 style="color: red;">'.$msg."</h1>\n",null,true);
					$GLOBALS['egw']->common->egw_exit();
				}
				else
				{
					unset($_GET['tr_id']);	// in case it's still set
					return $this->index(null,$this->data['tr_tracker'],$msg);
				}
				break;
			}
		}
		else	// submitted form
		{
			list($button) = @each($content['button']); unset($content['button']);
			if ($content['bounties']['bounty']) $button = 'bounty'; unset($content['bounties']['bounty']);
			$popup = $content['popup']; unset($content['popup']);

			$this->data = $content;
			unset($this->data['bounties']['new']); 

			switch($button)
			{
				case 'save':
					if (!$this->data['tr_id'] && !$this->check_rights($this->field_acl['add']))
					{
						$msg = lang('Permission denied !!!');
						break;
					}
					if ($this->save() == 0)
					{
						$msg = lang('Entry saved');

						if (is_array($content['link_to']['to_id']) && count($content['link_to']['to_id']))
						{
							if (!is_object($GLOBALS['egw']->link))
							{
								$GLOBALS['egw']->link =& CreateObject('phpgwapi.bolink');
							}
							$GLOBALS['egw']->link->link('tracker',$this->data['tr_id'],$content['link_to']['to_id']);
						}
						$js = "opener.location.href=opener.location.href.replace(/&tr_id=[0-9]+/,'')+(opener.location.href.indexOf('?')<0?'?':'&')+'msg=".
							addslashes(urlencode($msg))."&tracker=$content[tr_tracker]';";
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
						$GLOBALS['egw']->common->egw_exit();
					}
					else
					{
						unset($_GET['tr_id']);	// in case it's still set
						return $this->index(null,$this->data['tr_tracker'],$msg);
					}
					break;

				case 'vote':
					if ($this->cast_vote())
					{
						$msg = lang('Thank you for voting.');
						if ($popup)
						{
							$js = "opener.location.href=opener.location.href.replace(/&tr_id=[0-9]+/,'')+'&msg=".addslashes(urlencode($msg))."';";
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
		if (!($tracker = $this->data['tr_tracker'])) list($tracker) = @each($this->trackers);

		$readonlys = $this->readonlys_from_acl();
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
		$statis = $this->stati + $this->get_tracker_labels('stati',$tracker);
		$content += array(
			'msg' => $msg,
			'on_cancel' => $popup ? 'window.close();' : '',
			'no_vote' => '',
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
					'pr' => array('Public','Private'),
					'Cl' => 'date-time',
					'Re' => &$this->resolutions,
				),
			),
		);
		if ($this->allow_bounties && !$this->is_anonymous())
		{
			$content['bounties']['user_name'] = $GLOBALS['egw_info']['user']['account_fullname'];
			$content['bounties']['user_email'] = $GLOBALS['egw_info']['user']['account_email'];
		}
		$preserv['popup'] = $popup;

		if (!$tr_id && isset($_REQUEST['link_app']) && isset($_REQUEST['link_id']) && !is_array($content['link_to']['to_id']))
		{
			if (!is_object($GLOBALS['egw']->link))
			{
				$GLOBALS['egw']->link =& CreateObject('phpgwapi.bolink');
			}
			$link_ids = is_array($_REQUEST['link_id']) ? $_REQUEST['link_id'] : array($_REQUEST['link_id']);
			foreach(is_array($_REQUEST['link_app']) ? $_REQUEST['link_app'] : array($_REQUEST['link_app']) as $n => $link_app)
			{
				$link_id = $link_ids[$n];
				if (preg_match('/^[a-z_0-9-]+:[:a-z_0-9-]+$/i',$link_app.':'.$link_id))	// gard against XSS
				{
					$GLOBALS['egw']->link->link('tracker',$content['link_to']['to_id'],$link_app,$link_id);
				}
			}
		}
		$sel_options = array(
			'tr_tracker'  => &$this->trackers,
			'cat_id'      => $this->get_tracker_labels('cat',$tracker),
			'tr_version'  => $this->get_tracker_labels('version',$tracker),
			'tr_priority' => &$this->priorities,
			'tr_status'   => &$statis,
			'tr_resolution' => &$this->resolutions,
			'tr_assigned' => $this->get_staff($tracker,$this->allow_assign_groups),
			'canned_response' => $this->get_tracker_labels('response'),
		);
		foreach($this->field2history as $field => $status)
		{
			$sel_options['status'][$status] = $this->field2label[$field];
		}
		$sel_options['status']['xb'] = 'Bounty deleted';
		$sel_options['status']['bo'] = 'Bounty set';
		$sel_options['status']['Bo'] = 'Bounty confirmed';
		
		$readonlys[$tabs] = array(
			'comments' => !$tr_id || !$content['num_replies'],
			'add_comment' => !$tr_id || $readonlys['reply_message'],
			'history'  => !$tr_id,
			'bounties' => !$this->allow_bounties,
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
					($GLOBALS['egw_info']['user']['preferences']['common']['timeformat']==12?' h:i a':' H:i')),$voted) :
					lang('You need to login to vote!');
			}
		}
		if ($readonlys['canned_response'])
		{
			$content['no_canned'] = true;
		}
		$content['no_links'] = $readonlys['link_to'];
		$content['bounties']['no_set_bounties'] = $readonlys['bounty'];

		$what = $tracker ? $this->trackers[$tracker] : lang('Tracker');
		$GLOBALS['egw_info']['flags']['app_header'] = $tr_id ? lang('Edit %1',$what) : lang('New %1',$what);

		$tpl =& new etemplate('tracker.edit');

		return $tpl->exec('tracker.uitracker.edit',$content,$sel_options,$readonlys,$preserv,$popup ? 2 : 0);
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
		foreach($this->field_acl as $name => $rigths)
		{
			$readonlys[$name] = !$this->check_rights($rigths);
		}
		return $readonlys;
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

		$GLOBALS['egw']->session->appsession('index','tracker'.($query_in['only_tracker'] ? '-'.$query_in['only_tracker'] : ''),$query=$query_in);

		$tracker = $query['col_filter']['tr_tracker'];
		if (!($query['col_filter']['cat_id'] = $query['filter'])) unset($query['col_filter']['cat_id']);
		if (!($query['col_filter']['tr_version'] = $query['filter2'])) unset($query['col_filter']['tr_version']);
		
		if (!($query['col_filter']['tr_creator'])) unset($query['col_filter']['tr_creator']);

		if ($query['col_filter']['tr_assigned'] < 0)	// resolve groups with it's members
		{
			$query['col_filter']['tr_assigned'] = $GLOBALS['egw']->accounts->members($query['col_filter']['tr_assigned'],true);
			$query['col_filter']['tr_assigned'][] = $query_in['col_filter']['tr_assigned'];
		}
		elseif($query['col_filter']['tr_assigned'] === 'not')
		{
			$query['col_filter'][] = 'tr_assigned IS NULL';
			unset($query['col_filter']['tr_assigned']);
		}
		//echo "<p align=right>uitracker::get_rows() order='$query[order]', sort='$query[sort]', search='$query[search]', start=$query[start], num_rows=$query[num_rows], col_filter=".print_r($query['col_filter'],true)."</p>\n";
		$total = parent::get_rows($query,$rows,$readonlys,$this->allow_voting||$this->allow_bounties);	// true = count votes and/or bounties
		
		foreach($rows as $n => $row)
		{
			if ($row['overdue']) $rows[$n]['overdue_class'] = 'overdue';
			if ($row['bounties']) $rows[$n]['currency'] = $this->currency;
		}
		// set the options for assigned to depending on the tracker
		$rows['sel_options']['tr_assigned'] = array('not' => lang('Not assigned'))+$this->get_staff($tracker,2,true);
		$rows['sel_options']['filter'] = array(lang('All'))+$this->get_tracker_labels('cat',$tracker);
		$rows['sel_options']['filter2'] = array(lang('All'))+$this->get_tracker_labels('version',$tracker);
		$rows['sel_options']['tr_status'] = $this->stati+$this->get_tracker_labels('stati',$tracker);
		if ($this->is_admin($tracker))
		{
			$rows['sel_options']['canned_response'] = $this->get_tracker_labels('response',$tracker);
			$rows['sel_options']['cat_id'] =& $rows['sel_options']['filter'];
			$rows['sel_options']['tr_version'] =& $rows['sel_options']['filter2'];
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
		
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Tracker').': '.$this->trackers[$tracker];

		return $total;
	}

	/**
	 * Show a tracker
	 *
	 * @param array $content=null eTemplate content
	 * @param int $tracker=null id of tracker
	 * @param string $msg=''
	 * @param int $only_tracker=null show only the given tracker and not tracker-selection
	 * @return string html-content, if sitemgr otherwise null
	 */
	function index($content=null,$tracker=null,$msg='',$only_tracker=null)
	{
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
			if (!$tracker && (int)$_GET['tracker']) $tracker = $_GET['tracker'];
		}
		else
		{
			$only_tracker = $content['only_tracker']; unset($content['only_tracker']);

			if ($content['update'])

			{
				unset($content['update']);
				$checked = $content['nm']['rows']['checked']; unset($content['nm']);
				// remove all 'No change'
				foreach($content as $name => $value) if ($value === '') unset($content[$name]);
				
				if (!count($checked) || !count($content))
				{
					$msg = lang('You need to select something to change AND some tracker items!');
				}
				else
				{
					$n = 0;
					foreach($checked as $tr_id)
					{
						if (!$this->read($tr_id)) continue;
						foreach($content as $name => $value)
						{
							if ($value !== '') $this->data[$name] = $name == 'tr_assigned' && $value === 'not' ? NULL : $value;
						}
						if (!$this->save()) $n++;
					}
					$msg = lang('%1 entries updated.',$n);
				}
			}
		}
		if (!$tracker) $tracker = $content['nm']['col_filter']['tr_tracker'];

		$statis = $this->stati + $this->get_tracker_labels('stati',$tracker);
		$sel_options = array(
			'tr_tracker'  => &$this->trackers,
			'tr_priority' => &$this->priorities,
			'tr_status'   => &$statis,
		);
		if (!is_array($content)) $content = array();
		$content = array_merge($content,array(
			'nm' => $GLOBALS['egw']->session->appsession('index','tracker'.($only_tracker ? '-'.$only_tracker : '')),
			'msg' => $msg,
			'status_help' => !$this->pending_close_days ? lang('Pending items never get close automatic.') :
				lang('Pending items will be closed automatic after %1 days without response.',$this->pending_close_days),
		));		
		if (!$tracker) $tracker = $content['nm']['col_filter']['tr_tracker'];
		if (!$tracker) list($tracker) = @each($this->trackers);

		if (!is_array($content['nm']))
		{
			$content['nm'] = array(
				'get_rows'       =>	'tracker.uitracker.get_rows',
				'no_cat'         => true,
				'filter2'        => 0,	// all
				'filter2_label'  => lang('Version'),
				'filter2_no_lang'=> true,
				'filter'         => 0, // all
				'filter_label'   => lang('Category'),
				'filter_no_lang' => true,
				'order'          =>	$this->allow_bounties ? 'bounties' : ($this->allow_voting ? 'votes' : 'tr_id'),// IO name of the column to sort after (optional for the sortheaders)
				'sort'           =>	'DESC',// IO direction of the sort: 'ASC' or 'DESC'
				'options-tr_assigned' => array('not' => lang('Noone')),
				'col_filter'     => array(
					'tr_status'  => '-100',	// default filter: open
				),
	 			'header_left'    =>	$only_tracker ? null : 'tracker.index.left', // I  template to show left of the range-value, left-aligned (optional)
	 			'only_tracker'   => $only_tracker,
	 			'header_right'   =>	'tracker.index.right', // I  template to show right of the range-value, left-aligned (optional)
			);
		}
		$content['nm']['col_filter']['tr_tracker'] = $tracker;
		$content['is_admin'] = $this->is_admin($tracker);
		
		$readonlys['add'] = $readonlys['nm']['add'] = !$this->check_rights($this->field_acl['add'],$tracker);

		$tpl =& new etemplate('tracker.index');

		return $tpl->exec('tracker.uitracker.index',$content,$sel_options,$readonlys,array('only_tracker' => $only_tracker));
	}
	
	/**
	 * Site configuration
	 *
	 * @param array $content=null
	 * @return string
	 */
	function admin($content=null,$msg='')
	{
		$tabs = 'cats|staff|config';

		if (!$GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$GLOBALS['egw']->framework->render('<h1 style="color: red;">'.lang('Permission denied !!!')."</h1>\n",null,true);
			return;
		}
		//_debug_array($content);
		$tracker = (int) $content['tracker'];

		if (is_array($content))
		{
			list($button) = @each($content['button']);
			
			switch($button)
			{
				case 'add':
					if (!$content['add_name'])
					{
						$msg = lang('You need to enter a name');
					}
					elseif (($id = $this->add_tracker($content['add_name'])))
					{
						$tracker = $id;
						$msg = lang('Tracker added');
					}
					else
					{
						$msg = lang('Error adding the new tracker!');
					}
					break;

				case 'delete':
					if ($tracker && isset($this->trackers[$tracker]))
					{
						$this->delete_tracker($tracker);
						$tracker = 0;
						$msg = lang('Tracker deleted');
					}
					break;

				case 'apply':
				case 'save':
					$need_update = false;
					if (!$tracker)	// tracker unspecific config
					{
						foreach(array_diff($this->config_names,array('field_acl','technicians','admins','notification')) as $name)
						{
							if ((string) $this->$name !== $content[$name])
							{
								$this->$name = $content[$name];
								$need_update = true;
							}
						}
						// field_acl				
						foreach($content['field_acl'] as $row)
						{
							$rights = 0;
							foreach(array(
								'TRACKER_ADMIN'         => TRACKER_ADMIN,
								'TRACKER_TECHNICIAN'    => TRACKER_TECHNICIAN,
								'TRACKER_USER'          => TRACKER_USER,
								'TRACKER_EVERYBODY'     => TRACKER_EVERYBODY,
								'TRACKER_ITEM_CREATOR'  => TRACKER_ITEM_CREATOR,
								'TRACKER_ITEM_ASSIGNEE' => TRACKER_ITEM_ASSIGNEE,
								'TRACKER_ITEM_NEW'      => TRACKER_ITEM_NEW,							
							) as $name => $right)
							{
								if ($row[$name]) $rights |= $right;
							}
							if ($this->field_acl[$row['name']] != $rights)
							{
								//echo "<p>$row[name] / $row[label]: rights: ".$this->field_acl[$row['name']]." => $rights</p>\n";
								$this->field_acl[$row['name']] = $rights;
								$need_update = true;
							}
						}
					}
					// tracker specific config
					foreach(array('technicians','admins','notification') as $name)
					{
						$staff =& $this->$name;
						if (!isset($staff[$tracker])) $staff[$tracker] = array();
						if (!isset($content[$name])) $content[$name] = array();

						if ($staff[$tracker] != $content[$name])
						{
							$staff[$tracker] = $content[$name];
							$need_update = true;
						}
					}
					if ($need_update)
					{
						$this->save_config();
						$msg = lang('Configuration updated.').' ';
					}
					$need_update = false;
					foreach(array(
						'cats'      => lang('Category'),
						'versions'  => lang('Version'),
						'statis'    => lang('Stati'),						
						'responses' => lang('Canned response'),
					) as $name => $what)
					{
						foreach($content[$name] as $cat)
						{
							if (!$cat['name']) continue;	// ignore empty (new) cats

							$new_cat_descr = 'tracker-';
							switch($name)
							{
								case 'cats':
									$new_cat_descr .= 'cat';
									break;
								case 'versions':
									$new_cat_descr .= 'version';
									break;
								case 'statis':
									$new_cat_descr .= 'stati';
									break;
							}
							$old_cat = array(	// some defaults for new cats
								'main'   => $tracker,
								'parent' => $tracker,
								'access' => 'public',
								'data'   => array('type' => substr($name,0,-1)),
								'description'  => $new_cat_descr,
							);
							// search cat in existing ones
							foreach($this->all_cats as $c)
							{
								if ($cat['id'] == $c['id'])
								{
									$old_cat = $c;
									$old_cat['data'] = unserialize($old_cat['data']);
									break;
								}
							}
							// check if new cat or changed
							if (!$old_cat || $cat['name'] != $old_cat['name'] || 
								$name == 'cats' && (int)$cat['autoassign'] != (int)$old_cat['data']['autoassign'] ||
								$name == 'responses' && $cat['description'] != $old_cat['data']['response'])
							{
								$old_cat['name'] = $cat['name'];
								switch($name)
								{
									case 'cats':
										$old_cat['data']['autoassign'] = $cat['autoassign'];
										break;
									case 'responses':
										$old_cat['data']['response'] = $cat['description'];
										break;
								}
								//echo "update to"; _debug_array($old_cat);
								$old_cat['data'] = serialize($old_cat['data']);
								$GLOBALS['egw']->categories->account_id = -1;	// global cat!
								if (($id = $GLOBALS['egw']->categories->add($old_cat)))
								{
									$msg .= $old_cat['id'] ? lang("Tracker-%1 '%2' updated.",$what,$cat['name']) : lang("Tracker-%1 '%2' added.",$what,$cat['name']);
									$need_update = true;
								}
							}
						}
					}
					if ($need_update)
					{
						$this->reload_labels();
					}
					if ($button == 'apply') break;
					// fall-through for save
				case 'cancel':
					$GLOBALS['egw']->redirect_link('/index.php',array(
						'menuaction' => 'tracker.uitracker.index',
						'msg' => $msg,
					));
					break;
					
				default:
					foreach(array(
						'cats'      => lang('Category'),
						'versions'  => lang('Version'),
						'statis'    => lang('State'),						
						'responses' => lang('Canned response'),
					) as $name => $what)
					{
						if (isset($content[$name]['delete']))
						{
							list($id) = each($content[$name]['delete']);
							if ((int)$id)
							{
								$GLOBALS['egw']->categories->delete($id);
								$msg = lang('Tracker-%1 deleted.',$what);
								$this->reload_labels();
							}
						}
					}
					break;
			}
			
		}
		$content = array(
			'msg' => $msg,
			'tracker' => $tracker,
			'admins' => $this->admins[$tracker],
			'technicians' => $this->technicians[$tracker],
			'notification' => $this->notification[$tracker],
			$tabs => $content[$tabs],
		);
		foreach(array_diff($this->config_names,array('admins','technicians','notification')) as $name)
		{
			$content[$name] = $this->$name;
		}
		// cats & versions
		$v = $c = $r = $s = 1;
		usort($this->all_cats,create_function('$a,$b','return strcasecmp($a["name"],$b["name"]);'));
		foreach($this->all_cats as $cat)
		{
			if (!is_array($data = unserialize($cat['data']))) $data = array('type' => $data);
			//echo "<p>$cat[name] ($cat[id]/$cat[parent]/$cat[main]): ".print_r($data,true)."</p>\n";

			if ($cat['parent'] == $tracker && $data['type'] != 'tracker')
			{
				switch ($data['type'])
				{
					case 'version':
						$content['versions'][$v++] = $cat + $data;
						break;
					case 'response':
						if ($data['response']) $cat['description'] = $data['response'];
						$content['responses'][$r++] = $cat;
						break;
					case 'stati':
						$content['statis'][$s++] = $cat + $data;
						break;
					default:	// cat
						$data['type'] = 'cat';
						$content['cats'][$c++] = $cat + $data;
						break;
				}
			}
		}
		$content['versions'][$v++] = $content['cats'][$c++] = $content['responses'][$r++] = $content['statis'][$s++] =
			array('id' => 0,'name' => '');	// one empty line for adding
		// field_acl
		$f = 1;
		foreach($this->field2label as $name => $label)
		{
			if ($name == 'tr_creator') continue;

			$rights = $this->field_acl[$name];
			$content['field_acl'][$f++] = array(
				'label'                 => $label,
				'name'                  => $name,
				'TRACKER_ADMIN'         => !!($rights & TRACKER_ADMIN),
				'TRACKER_TECHNICIAN'    => !!($rights & TRACKER_TECHNICIAN),
				'TRACKER_USER'          => !!($rights & TRACKER_USER),
				'TRACKER_EVERYBODY'     => !!($rights & TRACKER_EVERYBODY),
				'TRACKER_ITEM_CREATOR'  => !!($rights & TRACKER_ITEM_CREATOR),
				'TRACKER_ITEM_ASSIGNEE' => !!($rights & TRACKER_ITEM_ASSIGNEE),
				'TRACKER_ITEM_NEW'      => !!($rights & TRACKER_ITEM_NEW),
			);
		}
		//_debug_array($content);
		$sel_options = array(
			'tracker' => &$this->trackers,
			'allow_assign_groups' => array(
				0 => lang('No'),
				1 => lang('Yes, display groups first'),
				2 => lang('Yes, display users first'),
			),
			'allow_voting' => array('No','Yes'),
			'allow_bounties' => array('No','Yes'),
			'autoassign' => $this->get_staff($tracker),
			'lang' => $GLOBALS['egw']->translation->get_installed_langs(),
		);
		$readonlys = array(
			'button[delete]' => !$tracker,
			'delete[0]' => true,
		);
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Tracker configuration').($tracker ? ': '.$this->trackers[$tracker] : '');
		$tpl =& new etemplate('tracker.admin');

		return $tpl->exec('tracker.uitracker.admin',$content,$sel_options,$readonlys,$content);
	}
}