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

require_once(EGW_INCLUDE_ROOT.'/etemplate/inc/class.so_sql.inc.php');

/**
 * Storage Object of the tracker
 */
class sotracker extends so_sql
{
	/**
	 * Table-name for the replies
	 *
	 * @var string
	 */
	var $replies_table = 'egw_tracker_replies';
	/**
	 * Table-name for the votes
	 *
	 * @var string
	 */
	var $votes_table = 'egw_tracker_votes';
	/**
	 * Table-name for the bounties
	 *
	 * @var string
	 */
	var $bounties_table = 'egw_tracker_bounties';

	/**
	 * Constructor
	 *
	 * @return sotracker
	 */
	function sotracker()
	{
		$this->so_sql('tracker','egw_tracker');
	}
	
	/**
	 * Read a tracker item
	 *
	 * Reimplemented to read the replies and bounties (non-admin only confirmed ones) too
	 * 
	 * @param array $keys array with keys in form internalName => value, may be a scalar value if only one key
	 * @param string/array $extra_cols string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $join sql to do a join, added as is after the table-name, eg. ", table2 WHERE x=y" or 
	 * @return array/boolean data if row could be retrived else False
	*/
	function read($keys,$extra_cols='',$join='')
	{
		if (($ret = parent::read($keys,$extra_cols,$join)))
		{
			$this->db->select($this->replies_table,'*',array('tr_id' => $this->data['tr_id']),__LINE__,__FILE__,false,'ORDER BY reply_created DESC');
			$this->data['replies'] = array();
			while (($row = $this->db->row(true)))
			{
				$this->data['replies'][] = $row;
			}
			$this->data['num_replies'] = count($this->data['replies']);
			
			$bounty_where = array('tr_id' => $this->data['tr_id']);
			if (method_exists($this,'is_admin') && !$this->is_admin($this->data['tr_tracker']))
			{
				$bounty_where[] = 'bounty_confirmed IS NOT NULL';
			}
			$this->data['bounties'] = $this->read_bounties($bounty_where);
		}
		return $ret;
	}

	/**
	 * Save a tracker item
	 *
	 * Reimplemented to save the reply too
	 * 
	 * @param array $keys if given $keys are copied to data before saveing => allows a save as
	 * @return int 0 on success and errno != 0 else
	 */
	function save($keys=null)
	{
		if ($keys)
		{
			$this->data_merge($keys);
		}
		if (($ret = parent::save()) == 0)
		{
			if ($this->data['reply_message'])
			{
				$this->db->insert($this->replies_table,$this->data,false,__LINE__,__FILE__);
				// add the new replies to this->data[replies]
				if (!is_array($this->data['replies'])) $this->data['replies'] = array();
				array_unshift($this->data['replies'],array(
					'reply_id'      => $this->db->get_last_insert_id($this->replies_table,'reply_id'),
					'tr_id'         => $this->data['tr_id'],
					'reply_creator' => $this->data['reply_creator'],
					'reply_created' => $this->data['reply_created'],
					'reply_message' => $this->data['reply_message'],
				));
			}
		}
		return $ret;
	}
	
	/**
	 * Searches / lists tracker items
	 *
	 * Reimplemented to join with the votes table and respect the private attribute
	 *
	 * @param array/string $criteria array of key and data cols, OR a SQL query (content for WHERE), fully quoted (!)
	 * @param boolean/string/array $only_keys=true True returns only keys, False returns all cols. or 
	 *	comma seperated list or array of columns to return
	 * @param string $order_by='' fieldnames + {ASC|DESC} separated by colons ',', can also contain a GROUP BY (if it contains ORDER BY)
	 * @param string/array $extra_cols='' string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $wildcard='' appended befor and after each criteria
	 * @param boolean $empty=false False=empty criteria are ignored in query, True=empty have to be empty in row
	 * @param string $op='AND' defaults to 'AND', can be set to 'OR' too, then criteria's are OR'ed together
	 * @param mixed $start=false if != false, return only maxmatch rows begining with start, or array($start,$num), or 'UNION' for a part of a union query
	 * @param array $filter=null if set (!=null) col-data pairs, to be and-ed (!) into the query without wildcards
	 * @param string $join_in='' sql to do a join, added as is after the table-name, eg. "JOIN table2 ON x=y" or
	 *	"LEFT JOIN table2 ON (x=y AND z=o)", Note: there's no quoting done on $join, you are responsible for it!!!
	 * @return boolean/array of matching rows (the row is an array of the cols) or False
	 */
	function &search($criteria,$only_keys=True,$order_by='',$extra_cols='',$wildcard='',$empty=False,$op='AND',$start=false,$filter=null,$join_in=true)
	{
		$join = $join_in && $join_in != 1 ? $join_in : '';
		if (is_string($criteria) && $criteria)
		{
			$pattern = $criteria;
			$criteria = array();
			foreach(array($this->table_name.'.tr_id','tr_summary','tr_description','reply_message') as $col)
			{
				$criteria[$col] = $pattern;
			}
			$join .= " LEFT JOIN $this->replies_table ON $this->table_name.tr_id=$this->replies_table.tr_id";
			if ($this->db->capabilities['distinct_on_text']) $only_keys = 'DISTINCT '.$this->table_name.'.*';
		}
		if ($join_in === true || $join_in == 1)
		{
			if (!is_array($extra_cols)) $extra_cols = $extra_cols ? explode(',',$extra_cols) : array();
			
			if ($this->db->capabilities['sub_queries'])	// everything, but old MySQL
			{
				$extra_cols[] = "(SELECT COUNT(*) FROM $this->votes_table WHERE $this->table_name.tr_id=$this->votes_table.tr_id) AS votes";
				$extra_cols[] = "(SELECT SUM(bounty_amount) FROM $this->bounties_table WHERE $this->table_name.tr_id=$this->bounties_table.tr_id AND bounty_confirmed IS NOT NULL) AS bounties";
			}
			else	// MySQL < 4.1
			{
				// join with votes
				$join .= " LEFT JOIN $this->votes_table ON $this->table_name.tr_id=$this->votes_table.tr_id";
				$extra_cols[] = 'COUNT(vote_time) AS votes';
				// join with bounties
				$join .= " LEFT JOIN $this->bounties_table ON $this->table_name.tr_id=$this->bounties_table.tr_id AND bounty_confirmed IS NOT NULL";
				$extra_cols[] = 'SUM(bounty_amount) AS bounties';
				// fixes to get tr_id non-ambigues
				if (is_bool($only_keys)) $only_keys = $this->table_name.($only_keys ? '.tr_id' : '.*');
				if (strpos($order_by,'tr_id') !== false) $order_by = str_replace('tr_id',$this->table_name.'.tr_id',$order_by);
				// group by the tr_id of the two join tables to count the votes and sum the bounties
				$order_by = ' GROUP BY '.$this->table_name.'.tr_id ORDER BY '.($order_by ? $order_by : 'bounties DESC');
			}
			// default sort is after bountes and votes, only adding them if they are not already there, as doublicate order gives probs on MsSQL
			if (strpos($order_by,'bounties') === false) $order_by .= ($order_by ? ',' : '').'bounties DESC';
			if (strpos($order_by,'votes') === false) $order_by .= ($order_by ? ',' : '').'votes DESC';
		}

		// Check for Tracker restrictions, OvE, 20071012
		if ($this->user != 0) // Skip this in the cron- runs (close_pending(), OvE, 20071124)
		{
			if ($filter['tr_tracker'])
			{
				// Single tracker
				if ($this->restrictions[$filter['tr_tracker']]['group'] && !($this->is_staff($filter['tr_tracker'])))
				{
					$filter[] = '(tr_group IN (' . implode(',', $GLOBALS['egw']->accounts->memberships($this->user,true)) . '))'; 
				}
				if ($this->restrictions[$filter['tr_tracker']]['creator'] && !($this->is_staff($filter['tr_tracker'])))
				{
					$filter[] = '(tr_creator = ' . $this->user . ')'; 
				} 
			}
			else
			{
				// All trackers
				$group_restrictions = array(); 
				$creator_restrictions = array();
				$all_restricions = array();
				$restrict = array();
				if (!$this->restrictions) $this->restrictions = array();
				foreach($this->restrictions as $tracker => $restrictions)
				{
					if($tracker == 0)
					{
						continue; // Not implemented for 'all trackers'				
					}
					if (($restrictions['group'] || $restrictions['creator']) AND !($this->is_staff($tracker)))
					{
						if ($restrictions['group'])
						{
							array_push($group_restrictions, $tracker);
							array_push($all_restricions, $tracker);
						}
						if ($restrictions['creator'])
						{
							array_push($creator_restrictions, $tracker);
							array_push($all_restricions, $tracker);
						}
					}
				}
	
				if (!empty($group_restrictions))
				{
					$restrict[] = '(tr_tracker IN (' . implode(',', $group_restrictions) . ') AND tr_group IN (' . implode(',', $GLOBALS['egw']->accounts->memberships($this->user,true)) . '))';				
				}
				if (!empty($creator_restrictions))
				{
					$restrict[] = '(tr_tracker IN (' . implode(',', $creator_restrictions) . ') AND tr_creator = ' . $this->user . ')';
				}
				if (!empty($all_restricions))
				{
					$restrict[] = '(tr_tracker NOT IN (' . implode(',', $all_restricions) . '))';
				}
				if (!empty($restrict))
				{
					$filter[] = '(' . implode(' OR ', $restrict) . ')';
				}
			} 
		} 
		//$this->debug = 4;

		// private ACL: private items are only visible for create, assiged or tracker admins
		if ($this->user && method_exists($this,'is_admin') && !$this->is_admin($filter['tr_tracker']))
		{
			$filter[] = '(tr_private=0 OR tr_creator='.$this->user.' OR tr_assigned IN ('.$this->user.','.implode(',',$GLOBALS['egw']->accounts->memberships($this->user,true)).'))';
		}
       // Handle the special filters
       switch ($filter['tr_status'])
       {
               case 'not-closed':
                       unset($filter['tr_status']);
                       $filter[] = "((tr_status != '-101') and (tr_status != '-102'))";
                       break;
               case 'own-not-closed':
                       unset($filter['tr_status']);
                       unset($filter['tr_creator']);
                       $filter[] = "(tr_creator=".$this->user.")";
                       $filter[] = "((tr_status != '-101') and (tr_status != '-102'))";
                       break;
               case 'without-reply-not-closed':
                       unset($filter['tr_status']);
                       if ($this->db->capabilities['sub_queries'])     // everything, but old MySQL
                       {
                               $filter[] = "((SELECT COUNT(*) FROM egw_tracker_replies WHERE egw_tracker.tr_id=egw_tracker_replies.tr_id) = 0)";
                       }
                       else    // MySQL < 4.1
                       {
                               // Not allready join comments tables
                               if (!$criteria and !$this->db->capabilities['sub_queries'])
                               {
                                       $join .= " LEFT JOIN $this->replies_table ON $this->table_name.tr_id=$this->replies_table.tr_id";
                               }
                               $extra_cols[] = 'COUNT(reply_id) AS replies';
                               $filter[] = "(replies = 0)";
                       }
                       $filter[] = "((tr_status != '-101') and (tr_status != '-102'))";
                       break;
               case 'own-without-reply-not-closed':
                       unset($filter['tr_status']);
                       unset($filter['tr_creator']);
                       if ($this->db->capabilities['sub_queries'])     // everything, but old MySQL
                       {
                               $filter[] = "((SELECT COUNT(*) FROM egw_tracker_replies WHERE egw_tracker.tr_id=egw_tracker_replies.tr_id) = 0)";
                       }
                       else    // MySQL < 4.1
                       {
                               // Not allready join comments tables
                               if (!$criteria and !$this->db->capabilities['sub_queries'])
                               {
                                       $join .= " LEFT JOIN $this->replies_table ON $this->table_name.tr_id=$this->replies_table.tr_id";
                               }
                               $extra_cols[] = 'COUNT(reply_id) AS replies';
                               $filter[] = "(replies = 0)";
                       }
                       $filter[] = "(tr_creator=".$this->user.")";
                       $filter[] = "((tr_status != '-101') and (tr_status != '-102'))";
                       break;
               case 'without-30-days-reply-not-closed':
                       unset($filter['tr_status']);
                       if ($this->db->capabilities['sub_queries'])     // everything, but old MySQL
                       {
                               $filter[] = "((SELECT COUNT(*) FROM egw_tracker_replies WHERE egw_tracker.tr_id=egw_tracker_replies.tr_id and reply_created > ".mktime(0, 0, 0, date("m")-1, date("d"),date("Y")).") = 0)";
                       }
                       else    // MySQL < 4.1
                       {
                               // Not allready join comments tables
                               if (!$criteria and !$this->db->capabilities['sub_queries'])
                               {
                                       $join .= " LEFT JOIN $this->replies_table ON $this->table_name.tr_id=$this->replies_table.tr_id";
                               }
                               $extra_cols[] = 'COUNT(reply_id) AS replies';
                               $filter[] = "(replies = 0)";
                       }
                       $filter[] = "((tr_status != '-101') and (tr_status != '-102'))";
                       break;
       }		
		return parent::search($criteria,$only_keys,$order_by,$extra_cols,$wildcard,$empty,$op,$start,$filter,$join);
	}
	
	/**
	 * Delete tracker items with the given keys
	 *
	 * @param array $keys tr_id or array with tr_id or tr_tracker
	 * @return int affected rows / tracker-items, should be > 0 if ok, 0 if an error
	 */
	function delete($keys)
	{
		if (!$keys) $keys = array('tr_id' => $this->data['tr_id']);
		elseif (!is_array($keys)) $keys = array('tr_id' => $keys);
		
		$ids = "SELECT tr_id FROM $this->table_name WHERE ".$this->db->expression($this->table_name,$keys);
		$where = "tr_id IN ($ids)";
		if (!$this->db->capabilities['sub_queries'])
		{
			$this->db->query($ids,__LINE__,__FILE__);
			$ids = array();
			while ($this->db->next_record())
			{
				$ids[] = $this->db->f(0);
			}
			$where = 'tr_id IN ('.implode(',',$ids).')';
		}
		if ($ids)
		{
			$this->db->delete($this->replies_table,$where,__LINE__,__FILE__);
			$this->db->delete($this->votes_table,$where,__LINE__,__FILE__);
			$this->db->delete($this->bounties_table,$where,__LINE__,__FILE__);
		}
		return parent::delete($keys);
	}
	
	/**
	 * Check if users is allowed to vote - has not already voted
	 *
	 * @param int $tr_id tracker-id
	 * @param int $user account_id
	 * @param string $ip=null IP, if it should be checked too
	 */
	function check_vote($tr_id,$user,$ip=null)
	{
		$where = array(
			'tr_id'    => $tr_id,
			'vote_uid' => $user,
		);
		if ($ip) $where['vote_ip'] = $ip;
		
		$this->db->select($this->votes_table,'vote_time',$where,__LINE__,__FILE__);
		
		return $this->db->next_record() ? $this->db->f(0) : null;
	}

	/**
	 * Cast vote for given tracker-item
	 *
	 * @param int $tr_id tracker-id
	 * @param int $user account_id
	 * @param string $ip IP
	 * @return boolean true=vote casted, false=already voted before
	 */
	function cast_vote($tr_id,$user,$ip)
	{
		return !!$this->db->insert($this->votes_table,array(
			'tr_id'     => $tr_id,
			'vote_uid'  => $user,
			'vote_ip'   => $ip,
			'vote_time' => time(),
		),false,__LINE__,__FILE__);
	}
	
	/**
	 * Save or update a bounty
	 * 
	 * @param array $data
	 * @return int/boolean integer bounty_id or false on error
	 */
	function save_bounty($data)
	{
		if ((int) $data['bounty_id'])
		{
			$where = array('bounty_id' => $data['bounty_id']);
			unset($data['bounty_id']);
			if ($this->db->update($this->bounties_table,$data,$where,__LINE__,__FILE__))
			{
				return $where['bounty_id'];
			}
		}
		else
		{
			if ($this->db->insert($this->bounties_table,$data,false,__LINE__,__FILE__))
			{
				return $this->db->get_last_insert_id($this->bounties_table,'bounty_id');
			}
		}
		return false;
	}
	
	/**
	 * Delete a bounty
	 * 
	 * @param int $bounty_id
	 * @return int number of deleted rows: 1 = success, 0 = failure
	 */
	function delete_bounty($id)
	{
		return $this->db->delete($this->bounties_table,array('bounty_id' => $id),__LINE__,__FILE__);
	}
	
	/**
	 * Read bounties specified by the given keys
	 * 
	 * @param array/int $keys array with key(s) or integer bounty-id
	 * @return array with bounties
	 */
	function read_bounties($keys)
	{
		if (!is_array($keys)) $keys = array('bounty_id' => $keys);

		$this->db->select($this->bounties_table,'*',$keys,__LINE__,__FILE__,false,'ORDER BY bounty_created DESC');
		$bounties = array();
		while (($row = $this->db->row(true)))
		{
			$bounties[] = $row;
		}
		return $bounties;
	}
}