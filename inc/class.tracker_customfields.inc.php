<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @author Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

require_once(EGW_INCLUDE_ROOT . '/admin/inc/class.customfields.inc.php');
/**
* Customfields class - manages customfields for tracker, with per-queue fields
*/
class tracker_customfields extends customfields
{
	public $appname = 'tracker';
	
	/**
	 * Constructor
	 *
	 */
	function __construct()
	{
		parent::__construct('tracker');
	}
}
