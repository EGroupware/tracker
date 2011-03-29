<?php
/**
 * eGroupWare - Tracker - importexport
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package tracker
 * @subpackage importexport
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @copyright 2010 Nathan Gray
 * @version $Id
 */

/**
 * class tracker_egw_record
 * compability layer for iface_egw_record needet for importexport
 */
class tracker_egw_record implements importexport_iface_egw_record
{

	public static $trackers;
	private $identifier = '';
	private $record = array();
	private $bo;



	/**
	 * constructor
	 * reads record from backend if identifier is given.
	 *
	 * @param string $_identifier
	 */
	public function __construct( $_identifier='' ){
		$this->identifier = $_identifier;
		if($_identifier) {
			$this->bo = new tracker_bo();
			$this->bo->read($this->identifier);
			$this->record = $this->bo->data;
		}
	}

	/**
	 * magic method to get attributes of record
	 *
	 * @param string $_attribute_name
	 */
	public function __get($_attribute_name) {
		return $this->record[$_attribute_name];
	}

	/**
	 * magic method to set attributes of record
	 *
	 * @param string $_attribute_name
	 * @param data $data
	 */
	public function __set($_attribute_name, $data) {
		$this->record[$_attribute_name] = $data;
	}

	/**
	 * converts this object to array.
	 * @abstract We need such a function cause PHP5
	 * dosn't allow objects do define it's own casts :-(
	 * once PHP can deal with object casts we will change to them!
	 *
	 * @return array complete record as associative array
	 */
	public function get_record_array() {
		return $this->record;
	}

	/**
	 * gets title of record
	 *
	 *@return string title
	 */
	public function get_title() {
		return self::$trackers[$this->record['tr_tracker']].' #'.$this->record['tr_id'].': '.$this->record['tr_summary'];
	}

	/**
	 * sets complete record from associative array
	 *
	 * @todo add some checks
	 * @return void
	 */
	public function set_record(array $_record){
		$this->record = $_record;
	}

	/**
	 * gets identifier of this record
	 *
	 * @return string identifier of current record
	 */
	public function get_identifier() {
		return $this->identifier;
	}

	/**
	 * saves record into backend
	 *
	 * @return string identifier
	 */
	public function save ( $_dst_identifier ) {

	}

	/**
	 * copies current record to record identified by $_dst_identifier
	 *
	 * @param string $_dst_identifier
	 * @return string dst_identifier
	 */
	public function copy ( $_dst_identifier ) {

	}

	/**
	 * moves current record to record identified by $_dst_identifier
	 *
	 * @param string $_dst_identifier
	 * @return string dst_identifier
	 */
	public function move ( $_dst_identifier ) {

	}

	/**
	 * deletes current record from backend
	 *
	 */
	public function delete () {

	}

	/**
	 * destructor
	 *
	 */
	public function __destruct() {
		unset ($this->bo);
	}

} // end of tracker_egw_record
if(!is_array(tracker_egw_record::$trackers)) {
	$bo = new tracker_bo();
	tracker_egw_record::$trackers = $bo->trackers;
}
