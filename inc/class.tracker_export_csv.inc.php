<?php
/**
 * eGroupWare
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package tracker
 * @subpackage importexport
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @copyright Nathan Gray
 * @version $Id
 */

/**
 * export tickets to CSV
 */
class tracker_export_csv implements importexport_iface_export_plugin {

	// Used in conversions
	static $types = array(
		'select-account' => array('tr_creator','tr_modifier','tr_group','tr_assigned'),
		'date-time' => array('tr_modified','tr_created','tr_closed'),
		'select-cat' => array('cat_id'),
		'select' => array('tr_tracker', 'tr_version','tr_status','tr_priority','tr_resolution'),
	);

	/**
	 * Exports records as defined in $_definition
	 *
	 * @param egw_record $_definition
	 */
	public function export( $_stream, importexport_definition $_definition) {
		$options = $_definition->plugin_options;

		$ui = new tracker_ui();
		
		$selection = array();
		$query_key = 'tracker'.($options['tracker'] ? '-'.$options['tracker'] : '');
		$query = $old_query = egw_session::appsession('index',$query_key);
		if ($options['selection'] == 'selected') {
			// ui selection with checkbox 'use_all'
			$query['num_rows'] = -1;	// all
			$ui->get_rows($query,$selection,$readonlys);
			
			// Reset nm params
			egw_session::appsession('index',$query_key, $old_query);
		}
		elseif ( $options['selection'] == 'all' ) {
			$query = array('num_rows' => -1);	// all
			$ui->get_rows($query,$selection,$readonlys);

			// Reset nm params
			egw_session::appsession('index',$query_key, $old_query);
		} else {
			$selection = explode(',',$options['selection']);
		}

		$export_object = new importexport_export_csv($_stream, (array)$options);
		$export_object->set_mapping($options['mapping']);

		// Get lookups for human-friendly values
		if($options['convert']) {
			$lookups = array(
				'tr_tracker'	=> $ui->trackers,
				'tr_version'	=> $ui->get_tracker_labels('version', null),
				'tr_status'	=> $ui->get_tracker_stati(null),
				'tr_resolution'	=> tracker_ui::$resolutions,
			);
			foreach($lookups['tr_tracker'] as $id => $name) {
				$lookups['tr_version'] += $ui->get_tracker_labels('version', $id);
				$lookups['tr_status'] += $ui->get_tracker_stati($id);
			}
		}

		foreach ($selection as $record) {
			if(!is_array($record) || !$record['tr_id']) continue;

			// Add in comments & bounties
			if($options['mapping']['replies'] || $options['mapping']['bounties']) {
				$ui->read($record['tr_id']);
				$record = $ui->data;
			}

			$_record = new tracker_egw_record();
			$_record->set_record($record);

			if($options['convert']) {
				// Set per-category priorities
				$lookups['tr_priority'] = $ui->get_tracker_priorities($record['tr_tracker'], $record['cat_id']);

				importexport_export_csv::convert($_record, self::$types, 'tracker', $lookups);
				$this->convert($_record, $options);
			} else {
				// Implode arrays, so they don't say 'Array'
				foreach($_record->get_record_array() as $key => $value) {
					if(in_array($key, array('replies', 'bounties'))) {
						$_record->$key = count($value) > 0 ? serialize($value) : null;
						continue;
					}
					if(is_array($value)) $_record->$key = implode(',', $value);
				}
			}
			$export_object->export_record($_record);
			unset($_record);
		}
	}

	/**
	 * returns translated name of plugin
	 *
	 * @return string name
	 */
	public static function get_name() {
		return lang('Tracker CSV export');
	}

	/**
	 * returns translated (user) description of plugin
	 *
	 * @return string descriprion
	 */
	public static function get_description() {
		return lang("Exports a list of tracker tickets to a CSV File.");
	}

	/**
	 * retruns file suffix for exported file
	 *
	 * @return string suffix
	 */
	public static function get_filesuffix() {
		return 'csv';
	}

	public static function get_mimetype() {
		return 'text/csv';
	}

	/**
	 * return html for options.
	 * this way the plugin has all opportunities for options tab
	 *
	 */
	public function get_options_etpl() {
	}

	/**
	 * returns selectors information
	 *
	 */
	public function get_selectors_etpl() {
		return array(
			'name'	=> 'tracker.export_csv_selectors'
		);
	}

	/**
	 * Do some conversions from internal format and structures to human readable / exportable
	 * formats
	 *
	 * @param tracker_egw_record $record Record to be converted
	 */
	protected static function convert(tracker_egw_record &$record, array $options = array()) {
		$record->tr_description = htmlspecialchars_decode(strip_tags($record->tr_description));

		if(is_array($record->replies)) {
			$replies = array();
			foreach($record->replies as $id => $reply) {
				// User date format
				$date = date($GLOBALS['egw_info']['user']['preferences']['common']['dateformat'] . ', '.
					($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] == '24' ? 'H' : 'h').':i:s',$reply['reply_created']);
				$name = common::grab_owner_name($reply['reply_creator']);
				$message = str_replace("\r\n", "\n", $reply['reply_message']);

				$replies[$id] = "$date \t$name \t$message";
			}
			$record->replies = implode("\n",$replies);
		}

		if(is_array($record->bounties)) {
			if( count($record->bounties) > 0) {
				$bounties = array();
				$total = 0;
				foreach($record->bounties as $key => $bounty) {
					$date = date($GLOBALS['egw_info']['user']['preferences']['common']['dateformat'] . ', '.
						($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] == '24' ? 'H' : 'h').':i:s',$bounty['bounty_created']);
					$name = common::grab_owner_name($bounty['bounty_creator']);
					$total += $bounty['bounty_amount'];
					$bounties[] = "$date\t$name\t".$bounty['bounty_amount'];
				}
				$record->bounties = lang('Total: ') . $total . "\n" . implode("\n",$bounties);
			} else {
				// No bounties
				$record->bounties = '';
			}
		}
	}
}
