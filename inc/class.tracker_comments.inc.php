<?php

use EGroupware\Api;

class tracker_comments extends Api\Storage\Base
{
	public $timestamp_type = 'object';
	public $timestamps = ['reply_created'];

	private static array $comment_count_cache = array();

	/**
	 * Constructor
	 */
	function __construct()
	{
		$this->columns_to_search = array(tracker_so::REPLIES_TABLE . '.reply_message');
		parent::__construct('tracker', tracker_so::REPLIES_TABLE);
		$this->timestamp_type = 'object';
	}

	function get_comment_count($tr_id, $include_restricted = false)
	{
		if(!isset(static::$comment_count_cache[$tr_id][$include_restricted]))
		{
			$search = ['tr_id' => $tr_id];
			if(!$include_restricted)
			{
				$search['reply_visible'] = 0;
			}
			$this->search($search, true, '', '', '', false, 'AND', 0);
			static::$comment_count_cache[$tr_id][$include_restricted] = $this->total;
		}
		return static::$comment_count_cache[$tr_id][$include_restricted];
	}

	function get_tracker_comments($tr_id, $include_restricted = false)
	{
		$search = ['tr_id' => $tr_id];
		if(!$include_restricted)
		{
			$search['reply_visible'] = 0;
		}
		return $this->search($search, false, 'reply_created DESC');
	}

	function db2data($data = null)
	{
		if(!empty($data['reply_created']))
		{
			$data['reply_servertime'] = new Api\DateTime($data['reply_created'], Api\DateTime::$server_timezone);
		}
		return parent::db2data($data);
	}
}