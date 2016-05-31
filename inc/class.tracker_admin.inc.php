<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) - Admin Interface
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006-10 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * Admin User Interface of the tracker
 */
class tracker_admin extends tracker_bo
{
	/**
	 * Functions callable via menuaction
	 *
	 * @var array
	 */
	var $public_functions = array(
		'admin' => true,
		'escalations' => true,
	);
	/**
	 * reference to the preferences of the user
	 *
	 * @var array
	 */
	var $prefs;

	/**
	 * Constructor
	 *
	 * @return tracker_admin
	 */
	function __construct()
	{
		// check if user has admin rights and bail out if not
		if (!$GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$GLOBALS['egw']->framework->render('<h1 style="color: red;">'.lang('Permission denied !!!')."</h1>\n",null,true);
			return;
		}
		parent::__construct();

		$this->prefs =& $GLOBALS['egw_info']['user']['preferences']['tracker'];
	}

	/**
	 * Site configuration
	 *
	 * @param array $content=null
	 * @return string
	 */
	function admin($content=null,$msg='')
	{
		//_debug_array($content);
		$tracker = (int) $content['tracker'];

		// apply preferences for assigning of defaultprojects, and provide the project list
		if ($this->prefs['allow_defaultproject'] && $tracker)
		{
			$allow_defaultproject = $this->prefs['allow_defaultproject'];
		}

		if (is_array($content))
		{
			list($button) = @each($content['button']);
			$defaultresolution = false;
			if (isset($content['resolutions']['isdefaultresolution']))
			{
				$name = 'resolutions';
				$defaultresolution = $content[$name]['isdefaultresolution'];
				unset($content[$name]['isdefaultresolution']);
			}
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

				case 'rename':
					if (!$content['add_name'])
					{
						$msg = lang('You need to enter a name');
					}
					elseif($tracker && $this->rename_tracker($tracker,$content['add_name']))
					{
						$msg = lang('Tracker queue renamed');
					}
					else
					{
						$msg = lang('Error renaming tracker queue!');
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
						foreach(array_diff($this->config_names,array('field_acl','technicians','admins','users','restrictions','notification','mailhandling','priorities')) as $name)
						{
							if (in_array($name,array('overdue_days','pending_close_days')) &&
								$content[$name] === '')
							{
								$content[$name] = '0';	// otherwise it does NOT get stored
							}
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
								'TRACKER_ITEM_GROUP'    => TRACKER_ITEM_GROUP,
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
					// tracker specific config and mail handling
					foreach(array('technicians','admins','users','notification','restrictions','mailhandling') as $name)
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

					// build the (normalized!) priority array
					$prios = array();
					foreach($content['priorities'] as $value => $data)
					{
						if ($value == 'cat_id')
						{
							$cat_id = $data;
							continue;
						}
						$value = (int) $data['value'];
						$prios[(int)$value] = (string)$data['label'];
					}
					if(!array_diff($prios,array('')))	// user deleted all label --> use the one from the next level above
					{
						$prios = null;
					}
					// priorities are only stored if they differ from the stock-priorities or the default chain of get_tracker_priorities()
					if ($prios !== $this->get_tracker_priorities($tracker,$cat_id,false))
					{
						$key = (int)$tracker;
						if ($cat_id) $key .= '-'.$cat_id;
						if (is_null($prios))
						{
							unset($this->priorities[$key]);
						}
						else
						{
							$this->priorities[$key] = $prios;
						}
						$need_update = true;
					}
					if ($need_update)
					{
						$this->save_config();
						$validationError=false;
						//$this->load_config();
						if (!is_array($this->mailhandling) && !empty($this->mailhandling))
						{
							$this->mailhandling=array(0=>array('interval'=>0));
							$validationError=true;
						}
						$mailhandler = new tracker_mailhandler($this->mailhandling);
						foreach((array)$this->mailhandling as $queue_id => $handling) {
							if (is_array($this->mailhandling[$queue_id]) && $this->mailhandling[$queue_id]['interval'])
							{
								try
								{
									$mailhandler->check_mail($queue_id,true);
								}
								catch (egw_exception_assertion_failed $e)
								{	// not sure that this is needed to pass on exeptions
									$msg .= ($msg?' ':'').$e->getMessage();
									if (is_array($this->mailhandling[$queue_id])) $this->mailhandling[$queue_id]['interval']=0;
									$validationError=true;
								}
							}
						}

						if ($validationError) $this->save_config();
						$msg .= ($msg?' ':'').lang('Configuration updated.').' ';
					}
					$reload_labels = false;
					$cats = null;
					foreach(array(
						'cats'      => lang('Category'),
						'versions'  => lang('Version'),
						'projects'  => lang('Projects'),
						'statis'    => lang('Stati'),
						'resolutions'=> lang('Resolution'),
						'responses' => lang('Canned response'),
					) as $name => $what)
					{
						foreach($content[$name] as $cat)
						{
							//_debug_array(array($name=>$cat));
							if (!is_array($cat) || !$cat['name']) continue;	// ignore empty (new) cats

								if($name == 'resolutions') error_log(array2string($cat));
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
								case 'resolutions':
									$new_cat_descr .= 'resolution';
									break;
								case 'projects':
									$new_cat_descr .= 'project';
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
									break;
								}
							}
							// check if new cat or changed, in case of projects the id and a free name is stored
							if (!$old_cat || $cat['name'] != $old_cat['name'] ||
								($tracker && in_array($tracker, (array)$old_cat['data']['denyglobal']) != !empty($cat['denyglobal'])) ||
								($name == 'cats' && (int)$cat['autoassign'] != (int)$old_cat['data']['autoassign']) ||
								($name == 'statis' && (int)$cat['closed'] != (int)$old_cat['data']['closed']) ||
								($name == 'projects' && (int)$cat['projectlist'] != (int)$old_cat['data']['projectlist']) ||
								($name == 'responses' && $cat['description'] != $old_cat['data']['response']) ||
								($name == 'resolutions' && (($defaultresolution && ($cat['id']==$defaultresolution || $cat['isdefault'] && $cat['id']!=$defaultresolution))||!$defaultresolution && $cat['isdefault']) ))
							{
								if ($tracker && !$cat['parent'])
								{
									if ($old_cat['data']['denyglobal'] && !$cat['denyglobal'] &&
										($k = array_search($tracker, $old_cat['data']['denyglobal'])) !== false)
									{
										unset($old_cat['data']['denyglobal'][$k]);
										//error_log(__METHOD__."() unsetting old_cat[data][denyglobal][$k]");
									}
									elseif ($cat['denyglobal'])
									{
										$old_cat['data']['denyglobal'][] = $cat['denyglobal'];
										//error_log(__METHOD__."() adding $tracker to old_cat[data][denyglobal]");
									}
								}
								$old_cat['name'] = $cat['name'];
								switch($name)
								{
									case 'cats':
										$old_cat['data']['autoassign'] = $cat['autoassign'];
										break;
									case 'statis':
										$old_cat['data']['closed'] = $cat['closed'];
										break;
									case 'projects':
										$old_cat['data']['projectlist'] = $cat['projectlist'];
										break;
									case 'responses':
										$old_cat['data']['response'] = $cat['description'];
										break;
									case 'resolutions':
										if ($cat['id']==$defaultresolution)
										{
											$no_change = $cat['isdefault'];
											$old_cat['data']['isdefault'] = $cat['isdefault'] = true;
											if($no_change)
											{
												// No real change - use 2 because switch is a loop in PHP
												continue 2;
											}
										}
										else
										{
											if (isset($old_cat['data']['isdefault'])) unset($old_cat['data']['isdefault']);
											if (isset($cat['isdefault'])) unset($cat['isdefault']);
										}
										break;
								}
								//echo "update to"; _debug_array($old_cat);
								if (!isset($cats))
								{
									$cats = new categories(categories::GLOBAL_ACCOUNT,'tracker');
								}
								if (($id = $cats->add($old_cat)))
								{
									$msg .= $old_cat['id'] ? lang("Tracker-%1 '%2' updated.",$what,$cat['name']) : lang("Tracker-%1 '%2' added.",$what,$cat['name']);
									$reload_labels = true;
								}
							}
						}
					}
					if ($reload_labels)
					{
						$this->reload_labels();
					}
					// Reload tracker app
					if(egw_json_response::isJSONResponse())
					{
						// egw_framework::redirect_link() will exit, we need to keep going
						egw_json_response::get()->redirect(egw_framework::link('/index.php', array(
							'menuaction' => 'tracker.tracker_ui.index',
							// reload is not a special flag, it just makes a different
							// url to avoid smart refresh of just nextmatch
							'reload',
							'ajax' => 'true'
						)), false, 'tracker');
					}
					if ($button == 'apply') break;
					// fall-through for save
				case 'cancel':
					egw::redirect_link('/index.php', array(
						'menuaction' => 'admin.admin_ui.index',
						'ajax' => 'true'
					), 'admin');
					break;

				default:

					foreach(array(
						'cats'      => lang('Category'),
						'versions'  => lang('Version'),
						'projects'  => lang('Projects'),
						'statis'    => lang('State'),
						'resolutions'=> lang('Resolution'),
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
			'users' => $this->users[$tracker],
			'notification' => $this->notification[$tracker],
			'restrictions' => $this->restrictions[$tracker],
			'mailhandling' => $this->mailhandling[$tracker],
			'tabs' => $content['tabs'],
			// keep priority cat only if tracker is unchanged, otherwise reset it
			'priorities' => $tracker == $content['tracker'] ? array('cat_id' => $content['priorities']['cat_id']) : array(),
		);

		foreach(array_diff($this->config_names,array('admins','technicians','users','notification','restrictions','mailhandling','priorities')) as $name)
		{
			$content[$name] = $this->$name;
		}
		$readonlys = array(
			'button[delete]' => !$tracker,
			'delete[0]' => true,
			'button[rename]' => !$tracker,
			'tabs' => array('tracker.admin.acl'=>$tracker),
		);
		// cats & versions & responses & projects
		$v = $c = $r = $s = $p = $i = 1;
		usort($this->all_cats,create_function('$a,$b','return strcasecmp($a["name"],$b["name"]);'));
		foreach($this->all_cats as $cat)
		{
			if (!is_array($data = $cat['data'])) $data = array('type' => $data);
			//echo "<p>$cat[name] ($cat[id]/$cat[parent]/$cat[main]): ".print_r($data,true)."</p>\n";

			if ($data['type'] != 'tracker' && ($cat['parent'] == $tracker || !$cat['parent']))
			{
				switch ($data['type'])
				{
					case 'version':
						$content['versions'][$n=$v++] = $cat + $data;
						break;
					case 'response':
						if ($data['response']) $cat['description'] = $data['response'];
						$content['responses'][$n=$r++] = $cat;
						if ($tracker != $cat['parent']) $readonlys['responses'][$n]['description'] = true;
						break;
					case 'project':
						$content['projects'][$n=$p++] = $cat + $data;
						if ($tracker != $cat['parent']) $readonlys['responses'][$n]['projectlist'] = true;
						break;
					case 'stati':
						$content['statis'][$n=$s++] = $cat + $data;
						if ($tracker != $cat['parent']) $readonlys['statis'][$n]['closed'] = true;
						break;
					case 'resolution':
						$content['resolutions'][$n=$i++] = $cat + $data;
						if ($data['isdefault']) $content['resolutions']['isdefaultresolution'] = $cat['id'];
						if ($tracker != $cat['parent']) $readonlys['resolutions']['isdefaulresolution['.$cat['id'].']'] = true;
						break;
					default:	// cat
						$data['type'] = 'cat';
						$content['cats'][$n=$c++] = $cat + $data;
						if ($tracker != $cat['parent']) $readonlys['cats'][$n]['autoassign'] = true;
						break;
				}
				$namespace = $data['type'].'s';
				// non-global --> disable deny global checkbox
				if ($tracker && $cat['parent'] == $tracker)
				{
					$readonlys[$namespace][$n.'[denyglobal]'] = true;
				}
				// global cat, but not all tracker --> disable name, autoassign and delete
				elseif ($tracker && !$cat['parent'])
				{
					$readonlys[$namespace][$n]['name'] = $readonlys[$namespace]['delete'][$cat['id']] = true;
				}
				if ($tracker && isset($data['denyglobal']) && in_array($tracker, $data['denyglobal']))
				{
					$content[$namespace][$n]['denyglobal'] = $tracker;
				}
			}
		}
		$readonlys['versions'][$v.'[denyglobal]'] = $readonlys['cats'][$c.'[denyglobal]'] =
			$readonlys['responses'][$r.'[denyglobal]'] = $readonlys['projects'][$p.'[denyglobal]'] =
			$readonlys['statis'][$s.'[denyglobal]'] = $readonlys['resolutions'][$i.'[denyglobal]'] = true;
		$content['versions'][$v++] = $content['cats'][$c++] = $content['responses'][$r++] =
			$content['projects'][$p++] = $content['statis'][$s++] = $content['resolutions'][$i++] =
			array('id' => 0,'name' => '');	// one empty line for adding
		// field_acl
		$f = 1;
		foreach($this->field2label as $name => $label)
		{
			if (in_array($name,array('num_replies', 'tr_created'))) continue;

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
				'TRACKER_ITEM_GROUP'    => !!($rights & TRACKER_ITEM_GROUP),
			);
		}

		$n = 2;	// cat selection + table header
		foreach($this->get_tracker_priorities($tracker,$content['priorities']['cat_id'],false) as $value => $label)
		{
			$content['priorities'][$n++] = array(
				'value' => self::$stock_priorities[$value],
				'label' => $label,
			);
		}
		//_debug_array($content);
		if (is_array($content['exclude_app_on_timesheetcreation']) && !in_array('timesheet',$content['exclude_app_on_timesheetcreation'])) $content['exclude_app_on_timesheetcreation'][]='timesheet';
		if (isset($content['exclude_app_on_timesheetcreation']) && !is_array($content['exclude_app_on_timesheetcreation']) && stripos($content['exclude_app_on_timesheetcreation'],'timesheet')===false) $content['exclude_app_on_timesheetcreation']=(strlen(trim($content['exclude_app_on_timesheetcreation']))>0?$content['exclude_app_on_timesheetcreation'].',':'').'timesheet';
		if (!isset($content['exclude_app_on_timesheetcreation'])) $content['exclude_app_on_timesheetcreation']='timesheet';
		if ($allow_defaultproject)	$content['allow_defaultproject'] = $this->prefs['allow_defaultproject'];
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
			'lang' => ($tracker ? array('' => lang('default')) : array() )+
				translation::get_installed_langs(),
			'cat_id' => $this->get_tracker_labels('cat',$tracker),
			// Mail handling
			'interval' => array(
				0 => 'Disabled',
				5 => 5,
				10 => 10,
				15 => 15,
				20 => 20,
				30 => 30,
				60 => 60
			),
			'servertype' => array(),
			'default_tracker' => ($tracker ? array($tracker => $this->trackers[$tracker]) : $this->trackers),
			// TODO; enable the default_trackers onChange() to reload categories
			'default_cat' => $this->get_tracker_labels('cat',$content['mailhandling']['default_tracker']),
			'default_version' => $this->get_tracker_labels('version',$content['mailhandling']['default_tracker']),
			'unrec_reply' => array(
				0 => 'Creator',
				1 => 'Nobody',
			),
			'auto_reply' => array(
				0 => lang('Never'),
				1 => lang('Yes, new tickets only'),
				2 => lang('Yes, always'),
			),
			'reply_unknown' => array(
				0 => 'Creator',
				1 => 'Nobody',
			),
			'exclude_app_on_timesheetcreation' => egw_link::app_list('add'),
		);
		foreach($this->mailservertypes as $ind => $typ)
		{
			$sel_options['servertype'][] = $typ[1];
		}
		foreach($this->mailheaderhandling as $ind => $typ)
		{
			$sel_options['mailheaderhandling'][] = $typ[1];
		}
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Tracker configuration').($tracker ? ': '.$this->trackers[$tracker] : '');
		$tpl = new etemplate_new('tracker.admin');
		return $tpl->exec('tracker.tracker_admin.admin',$content,$sel_options,$readonlys,$content);
	}

	/**
	 * Get escalation rows
	 *
	 * @param array $query
	 * @param array &$rows
	 * @param array &$readonlys
	 * @return int|boolean
	 */
	function get_rows($query,&$rows,&$readonlys)
	{
		$escalations = new tracker_escalations();
		$Ok = $escalations->get_rows($query,$rows,$readonlys);

		if ($rows)
		{
			$prio_labels = $prio_tracker = $prio_cat = null;
			foreach($rows as &$row)
			{
				// Show before / after
				$row['esc_before_after'] = ($row['esc_time'] < 0 ? tracker_escalations::BEFORE : tracker_escalations::AFTER);
				$row['esc_time'] = abs($row['esc_time']);

				// show the right tracker and/or cat specific priority label
				if ($row['tr_priority'])
				{
					if (is_null($prio_labels) || $row['tr_tracker'] != $prio_tracker || $row['cat_id'] != $prio_cat)
					{
						$prio_labels = $this->get_tracker_priorities(
							$prio_tracker=is_array($row['tr_tracker']) ? $row['tr_tracker'][0] : $row['tr_tracker'],
							$prio_cat = is_array($row['cat_id']) ? $row['cat_id'][0] : $row['cat_id']
						);
					}
					foreach((array)$row['tr_priority'] as $priority)
					{
						$row['prio_label'][]= $prio_labels[$priority];
					}
					$row['prio_label'] = implode(',',$row['prio_label']);
				}

				// Show repeat limit, if set
				if($row['esc_limit']) $row['esc_limit_label'] = lang('maximum %1 times', $row['esc_limit']);
			}
		}
		return $Ok;
	}

	/**
	 * Define escalations
	 *
	 * @param array $content
	 * @param string $msg
	 */
	function escalations(array $content=null,$msg='')
	{
		$escalations = new tracker_escalations();

		if (!is_array($content))
		{
			$content['nm'] = array(
				'get_rows'       =>	'tracker.tracker_admin.get_rows',
				'no_cat'         => true,
				'no_filter2'=> true,
				'no_filter' => true,
				'order'          =>	'esc_time',
				'sort'           =>	'ASC',// IO direction of the sort: 'ASC' or 'DESC'
				'row_id'	=>	'esc_id',
				'placeholder_actions' => array(),
				'actions'	=>	array(
					'edit' => array(
						'caption' => 'edit',
						'default' => true,
						'allowOnMultiple' => false,
					),
					'delete' => array(
						'caption' => 'delete',
						'allowOnMultiple' => false,
					)
				)
			);
		}
		else
		{
			list($button) = @each($content['button']);
			unset($content['button']);
			$escalations->init($content);

			switch($button)
			{
				case 'save':
				case 'apply':
					// 'Before' only valid for start & due dates
					if($content['esc_before_after'] == tracker_escalations::BEFORE &&
						!in_array($content['esc_type'],array(tracker_escalations::START,tracker_escalations::DUE)))
					{
						$msg = lang('"%2" only valid for start date and due date.  Use "%1".',lang('after'),lang('before'));
						$escalations->data['esc_before_after'] = tracker_escalations::AFTER;
						break;
					}
					// Handle before time
					$escalations->data['esc_time'] *= ($content['esc_before_after'] == tracker_escalations::BEFORE ? -1 : 1);

					if (($err = $escalations->not_unique()))
					{
						$msg = lang('There already an escalation for that filter!');
						$button = '';
					}
					elseif (($err = $escalations->save(null,null,!$content['esc_run_on_existing'])) == 0)
					{
						$msg = $content['esc_id'] ? lang('Escalation saved.') : lang('Escalation added.');
					}
					if ($button == 'apply' || $err) break;
					// fall-through
				case 'cancel':
					$escalations->init();
					break;
			}
			if($content['nm']['rows']['edit'] || $content['nm']['rows']['delete'])
			{
				$content['nm']['action'] = key($content['nm']['rows']);
				$content['nm']['selected'] = array(key($content['nm']['rows'][$content['nm']['action']]));
			}
			if($content['nm']['action'])
			{
				$action = $content['nm']['action'];
				list($id) = $content['nm']['selected'];
				$id = (int)$id;
				unset($content['nm']['action']);
				unset($content['nm']['selected']);
				switch($action)
				{
					case 'edit':
						if (!$escalations->read($id))
						{
							$msg = lang('Escalation not found!');
							$escalations->init();
						}
						break;
					case 'delete':
						if (!$escalations->delete(array('esc_id' => $id)))
						{
							$msg = lang('Error deleting escalation!');
						}
						else
						{
							$msg = lang('Escalation deleted.');
						}
						break;
				}
			}
		}
		$content = $escalations->data + array(
			'nm' => $content['nm'],
			'msg' => $msg,
		);

		// Handle before time
		$content['esc_before_after'] = ($content['esc_time'] < 0 ? tracker_escalations::BEFORE : tracker_escalations::AFTER);
		$content['esc_time'] = abs($content['esc_time']);

		$readonlys = $preserv = array();
		$preserv['esc_id'] = $content['esc_id'];
		$preserv['nm'] = $content['nm'];


		$tracker = $content['tr_tracker'];
		$sel_options = array(
			'tr_tracker'  => &$this->trackers,
			'esc_before_after' => array(
				tracker_escalations::AFTER => lang('after'),
				tracker_escalations::BEFORE => lang('before'),
			),
			'esc_type'    => array(
				tracker_escalations::CREATION => lang('creation date'),
				tracker_escalations::MODIFICATION => lang('last modified'),
				tracker_escalations::START => lang('start date'),
				tracker_escalations::DUE => lang('due date'),
				tracker_escalations::REPLIED => lang('last reply'),
				tracker_escalations::REPLIED_CREATOR => lang('last reply by creator'),
				tracker_escalations::REPLIED_ASSIGNED => lang('last reply by assigned'),
				tracker_escalations::REPLIED_NOT_CREATOR => lang('last reply by anyone but creator'),
			),
			'notify' => tracker_escalations::$notification,
			'cat_id' => array(),
			'tr_version' => array(),
			'tr_resolution' => array(),
			'tr_priority' => array(),
			'tr_status' => array(),
			'tr_assigned' => array()
		);

		foreach(($content['tr_tracker'] ? (array)$content['tr_tracker'] : array_keys($this->trackers)) as $tracker)
		{
			$sel_options['cat_id'] 		+= $this->get_tracker_labels('cat',$tracker);
			$sel_options['tr_version']	+= $this->get_tracker_labels('version',$tracker);
			$sel_options['tr_resolution']	+= $this->get_tracker_labels('resolution',$tracker);
			$sel_options['tr_priority']	+= $this->get_tracker_priorities($tracker,$content['cat_id']);
			$sel_options['tr_status']	+= $this->get_tracker_stati($tracker);
			$sel_options['tr_assigned']	+= $this->get_staff($tracker,$this->allow_assign_groups);
		}
		if ($content['set']['tr_assigned'] && !is_array($content['set']['tr_assigned']))
		{
			$content['set']['tr_assigned'] = explode(',',$content['set']['tr_assigned']);
		}
		$tpl = new etemplate_new('tracker.escalations');
		if (count($content['set']['tr_assigned']) > 1)
		{
			$widget =& $tpl->get_widget_by_name('tr_assigned');	//$tpl->set_cell_attribute() sets all widgets with this name, so the action too!
			$widget['size'] = '3+';
		}
		if ($content['tr_status'] && !is_array($content['tr_status']))
		{
			$content['tr_status'] = explode(',',$content['tr_status']);
		}
		foreach(array('tr_status', 'tr_tracker','cat_id','tr_version','tr_priority','tr_resolution') as $array)
                {
			if (count($content[$array]) > 1)
			{
				// Old etemplate support
				if(method_exists($tpl, 'get_widget_by_name'))
				{
					$widget =& $tpl->get_widget_by_name($array);
					$widget['size'] = '3+';
				} else {
					$tpl->setElementAttribute($array, 'empty_label', 'all');
					$tpl->setElementAttribute($array, 'rows', '3');
					$tpl->setElementAttribute($array, 'tags', true);
				}
			}
		}
		$content['set']['no_comment_visibility'] = !$this->allow_restricted_comments;
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Tracker').' - '.lang('Define escalations');
		//_debug_array($content);
		return $tpl->exec('tracker.tracker_admin.escalations',$content,$sel_options,$readonlys,$preserv);
	}
}
