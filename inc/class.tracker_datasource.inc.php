<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package tracker
 * @copyright (c) 2006-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

use EGroupware\Api\Link;
use EGroupware\Api\Config;

include_once(EGW_INCLUDE_ROOT.'/projectmanager/inc/class.datasource.inc.php');

/**
 * DataSource for Tracker
 *
 * The Tracker datasource set's only real start- and endtimes and the assigned user as resources.
 */
class tracker_datasource extends datasource
{
	/**
	 * Constructor
	 */
	function datasource_tracker()
	{
		$this->datasource('tracker');

		$this->valid = PM_COMPLETION|PM_READ_START|PM_READ_END|PM_PLANNED_BUDGET|PM_RESOURCES|PM_CAT_ID;
	}

	/**
	 * Instance shared between all tracker_datasources
	 *
	 * @var tracker_bo
	 */
	protected static $botracker;

	/**
	 * get an entry from the underlaying app (if not given) and convert it into a datasource array
	 *
	 * @param mixed $data_id id as used in the link-class for that app, or complete entry as array
	 * @return array|boolean array with the data supported by that source or false on error (eg. not found, not availible)
	 */
	function get($data_id)
	{
		// we use $GLOBALS['boinfolog'] as an already running instance might be availible there
		if (!isset(self::$botracker))
		{
			self::$botracker = new tracker_bo();
		}
		if (!is_array($data_id))
		{
			$data =& self::$botracker->read((int) $data_id);

			if (!is_array($data)) return false;
		}
		else
		{
			$data =& $data_id;
		}

		return array(
			'pe_title'        => self::$botracker->link_title($data),
			'pe_completion'   => $data['tr_completion'],
			'pe_real_start'   => $data['tr_startdate'] ?: $data['tr_created'],
			'pe_real_end'     => $data['tr_enddate'] ?: $data['tr_closed'],
			'pe_planned_start'=> $data['tr_startdate'],
			'pe_planned_end'  => $data['tr_enddate'],
			'pe_resources'    => $data['tr_assigned'] ? (array)$data['tr_assigned'] : null,
			'pe_details'      => $data['tr_description'] ? nl2br($data['tr_description']) : '',
			'pe_planned_budget'   => $data['tr_budget'],
			'cat_id'          => $data['cat_id'],
		);
	}

	/**
	 * Delete the datasource of a project element
	 *
	 * @param int $id
	 * @return boolean true on success, false on error
	 */
	function delete($id)
	{
		// dont delete entries which are linked to elements other than their project
		if (count(Link::get_links('tracker',$id)) > 1)
		{
			return false;
		}
		// If the project is keeping history, just set status
		// We only do this since tracker doesn't have a delete & keep, just status
		$config = Config::read('projectmanager');
		if($config['history'])
		{
			return $this->change_status($id, 'deleted');
		}
		return self::$botracker->delete($id);
	}

	/**
	 * Change the status of an entry according to the project status
	 *
	 * @param int $id
	 * @param string $status
	 * @return boolean true if status changed, false otherwise
	 */
	function change_status($id,$status)
	{
		if (!is_object(self::$botracker))
		{
			self::$botracker = new tracker_bo();
		}
		if (($entry = self::$botracker->read($id)))
		{
			$stati = array_map('strtolower', self::$botracker->get_tracker_stati());
			if (in_array(strtolower($status), $stati))
			{
				self::$botracker->save(array('tr_status' => array_search(strtolower($status), $stati)));
				return true;
			}
			// Restore from deleted
			else if ($status == 'active' && $entry['tr_status'] == tracker_bo::STATUS_DELETED)
			{
				self::$botracker->save(array('tr_status' => tracker_bo::STATUS_OPEN));
				return true;
			}
		}
		return false;
	}
}