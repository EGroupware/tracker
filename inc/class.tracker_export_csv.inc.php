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

use EGroupware\Api;

/**
 * export tickets to CSV
 */
class tracker_export_csv implements importexport_iface_export_plugin
{

	public function __construct()
	{
		Api\Translation::add_app('tracker');
		$this->ui = new tracker_ui();
		$this->get_selects();
	}
	/**
	 * Exports records as defined in $_definition
	 *
	 * @param egw_record $_definition
	 */
	public function export( $_stream, importexport_definition $_definition)
	{
		$options = $_definition->plugin_options;


		$selection = array();
		$query_key = 'index'.($options['tracker'] ? '-'.$options['tracker'] : '');
		$query = $old_query = Api\Cache::getSession('tracker',$query_key);
		switch($options['selection'])
		{
			case 'search':
				// ui selection with checkbox 'use_all'
				$query['num_rows'] = -1;	// all
				$query['csv_export'] = true;	// so get_rows method _can_ produce different content or not store state in the session
				$readonlys = null;
				$this->ui->get_rows($query,$selection,$readonlys);

				// Reset nm params
				Api\Cache::setSession('tracker',$query_key, $old_query);
				break;
			case 'filter':
			case 'all':
				$query = array(
					'num_rows' => -1,		// all
					'order' => 'tr_id',
					'csv_export' => true,	// so get_rows method _can_ produce different content or not store state in the session
				);
				if($options['selection'] == 'filter')
				{
					importexport_helper_functions::get_filter_fields($_definition->application, $this);
					$query['col_filter'] = $_definition->filter;

					// Backend expects a string
					if($query['col_filter']['info_responsible'])
					{
						$query['col_filter']['info_responsible'] = implode(',',$query['col_filter']['info_responsible']);
					}

					// Handle ranges
					foreach($query['col_filter'] as $field => $value)
					{
						if(!is_array($value) || (!$value['from'] && !$value['to'])) continue;

						// Ranges are inclusive, so should be provided that way (from 2 to 10 includes 2 and 10)
						if($value['from']) $query['col_filter'][] = "$field >= " . (int)$value['from'];
						if($value['to']) $query['col_filter'][] = "$field <= " . (int)$value['to'];
						unset($query['col_filter'][$field]);
					}
				}

				$this->ui->get_rows($query,$selection,$readonlys);

				// Reset nm params
				Api\Cache::setSession('tracker',$query_key, $old_query);
			break;
			default:
				$selection = explode(',',$options['selection']);
			break;
		}

		$export_object = new importexport_export_csv($_stream, (array)$options);
		$export_object->set_mapping($options['mapping']);

		if($options['convert'])

		foreach ($selection as $record)
		{
			if(!is_array($record) || !$record['tr_id']) continue;

			// Add in comments & bounties
			if($options['mapping']['replies'] || $options['mapping']['bounties'])
			{
				$this->ui->read($record['tr_id']);
				$record = $this->ui->data;
			}

			$_record = new tracker_egw_record();
			$_record->set_record($record);

			if($options['convert'])
			{
				// Set per-category priorities
				$this->selects['tr_priority'] = $this->ui->get_tracker_priorities($record['tr_tracker'], $record['cat_id']);

				importexport_export_csv::convert($_record, tracker_egw_record::$types, 'tracker', $this->selects);
				$this->convert($_record, $options);
			}
			else
			{
				// Implode arrays, so they don't say 'Array'
				foreach($_record->get_record_array() as $key => $value)
				{
					if(in_array($key, array('replies', 'bounties')))
					{
						$_record->$key = count($value) > 0 ? serialize($value) : null;
						continue;
					}
					if(is_array($value)) $_record->$key = implode(',', $value);
				}
			}
			$export_object->export_record($_record);
			unset($_record);
		}
		return $export_object;
	}

	/**
	 * returns translated name of plugin
	 *
	 * @return string name
	 */
	public static function get_name()
	{
		return lang('Tracker CSV export');
	}

	/**
	 * returns translated (user) description of plugin
	 *
	 * @return string descriprion
	 */
	public static function get_description()
	{
		return lang("Exports a list of tracker tickets to a CSV File.");
	}

	/**
	 * retruns file suffix for exported file
	 *
	 * @return string suffix
	 */
	public static function get_filesuffix()
	{
		return 'csv';
	}

	public static function get_mimetype()
	{
		return 'text/csv';
	}

	/**
	 * Return array of settings for export dialog
	 *
	 * @param $definition Specific definition
	 *
	 * @return array (
	 * 		name 		=> string,
	 * 		content		=> array,
	 * 		sel_options	=> array,
	 * 		readonlys	=> array,
	 * 		preserv		=> array,
	 * )
	 */
	public function get_options_etpl(importexport_definition &$definition = NULL)
	{
		return false;
	}

	/**
	 * returns selectors information
	 *
	 */
	public function get_selectors_etpl()
	{
		return array(
			'name'	=> 'importexport.export_csv_selectors',
		);
	}

	/**
	 * Do some conversions from internal format and structures to human readable / exportable
	 * formats
	 *
	 * @param tracker_egw_record $record Record to be converted
	 */
	protected static function convert(tracker_egw_record &$record, array $options = array())
	{
		unset($options);	// not used, but required by function signature
		$record->tr_description = htmlspecialchars_decode(strip_tags($record->tr_description));

		if(is_array($record->replies))
		{
			$replies = array();
			foreach($record->replies as $id => $reply)
			{
				// User date format
				$date = date($GLOBALS['egw_info']['user']['preferences']['common']['dateformat'] . ', '.
					($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] == '24' ? 'H' : 'h').':i:s',$reply['reply_created']);
				$name = Api\Accounts::username($reply['reply_creator']);
				$message = str_replace("\r\n", "\n", htmlspecialchars_decode(strip_tags($reply['reply_message'])));

				$replies[$id] = "$date \t$name \t$message";
			}
			$record->replies = implode("\n",$replies);
		}

		if(is_array($record->bounties))
		{
			if( count($record->bounties) > 0)
			{
				$bounties = array();
				$total = 0;
				foreach($record->bounties as $bounty)
				{
					$date = date($GLOBALS['egw_info']['user']['preferences']['common']['dateformat'] . ', '.
						($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] == '24' ? 'H' : 'h').':i:s',$bounty['bounty_created']);
					$name = Api\Accounts::username($bounty['bounty_creator']);
					$total += $bounty['bounty_amount'];
					$bounties[] = "$date\t$name\t".$bounty['bounty_amount'];
				}
				$record->bounties = lang('Total: ') . $total . "\n" . implode("\n",$bounties);
			}
			else
			{
				// No bounties
				$record->bounties = '';
			}
		}
	}

	/**
	 * Get lookups for human-friendly values
	 */
	public function get_selects()
	{
		$this->selects = array(
			'tr_tracker'	=> $this->ui->trackers,
			'tr_version'	=> $this->ui->get_tracker_labels('version', null),
			'tr_status'	=> $this->ui->get_tracker_stati(null),
			'tr_resolution'	=> $this->ui->get_tracker_labels('resolution',null),
			'tr_priority'	=> $this->ui->get_tracker_priorities(),
			'tr_private'	=> array('' => lang('no'),0 => lang('no'),'1'=>lang('yes')),
		);
		foreach(array_keys($this->selects['tr_tracker']) as $id)
		{
			$this->selects['tr_version'] += $this->ui->get_tracker_labels('version', $id);
			$this->selects['tr_status'] += $this->ui->get_tracker_stati($id);
			$this->selects['tr_resolution'] += $this->ui->get_tracker_labels('resolution',$id);
			$this->selects['tr_priority'] += $this->ui->get_tracker_priorities($id);
		}
	}

	/**
	 * Adjust automatically generated filter fields
	 */
	public function get_filter_fields(Array &$filters)
    {
		// When filtering, use only categories flagged as category
		$filters['cat_id']['type'] = 'select';
		$filters['cat_id']['values'] = $this->ui->get_tracker_labels('cat',null);

		foreach($filters as $field_name => &$settings)
		{
			if($this->selects[$field_name]) $settings['values'] = $this->selects[$field_name];
		}
	}

	/**
	 * Get the class name for the egw_record to use while exporting
	 *
	 * @return string;
	 */
	public static function get_egw_record_class()
	{
		return 'tracker_egw_record';
	}
}
