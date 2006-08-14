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
	 * Reimplemented to read the replies to
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
	 * @param string $join='' sql to do a join, added as is after the table-name, eg. "JOIN table2 ON x=y" or
	 *	"LEFT JOIN table2 ON (x=y AND z=o)", Note: there's no quoting done on $join, you are responsible for it!!!
	 * @return boolean/array of matching rows (the row is an array of the cols) or False
	 */
	function &search($criteria,$only_keys=True,$order_by='',$extra_cols='',$wildcard='',$empty=False,$op='AND',$start=false,$filter=null,$join=true)
	{
		if ($join === true || $join == 1)
		{
			$join = " LEFT JOIN $this->votes_table ON $this->table_name.tr_id=$this->votes_table.tr_id";
			if (!is_array($extra_cols)) $extra_cols = $extra_cols ? explode(',',$extra_cols) : array();
			$extra_cols[] = 'COUNT(vote_time) AS votes';
			$only_keys = $this->table_name.'.*';
			if (strstr($order_by,'tr_id')) $order_by = str_replace('tr_id',$this->table_name.'.tr_id',$order_by);
			$order_by = ' GROUP BY '.$this->table_name.'.tr_id ORDER BY '.$order_by;
		}
		// private ACL: private items are only visible for create, assiged or tracker admins
		if (method_exists($this,'is_admin') && !$this->is_admin($filter['tr_tracker']))
		{
			$filter[] = '(tr_private=0 OR tr_creator='.$this->user.' OR tr_assigned IN ('.$this->user.','.implode(',',$GLOBALS['egw']->accounts->memberships($this->user,true)).'))';
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
}