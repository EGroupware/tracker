<?php
/**
 * eGroupWare
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package importexport
 * @link http://www.egroupware.org
 * @author Cornelius Weiss <nelius@cwtech.de>
 * @author Nathan Gray
 * @copyright 2010 Nathan Gray
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Link;

/**
 * class import_csv for tracker
 */
class tracker_import_csv extends importexport_basic_import_csv
{
	public static $special_fields = array(
		'addressbook'     => 'Link to Addressbook, use nlast,nfirst[,org] or contact_id from addressbook',
		'link_1'      => '1. link: appname:appid the entry should be linked to, eg.: addressbook:123',
		'link_2'      => '2. link: appname:appid the entry should be linked to, eg.: addressbook:123',
		'link_3'      => '3. link: appname:appid the entry should be linked to, eg.: addressbook:123',
	);

	/**
	 * conditions for actions
	 *
	 * @var array
	 */
	protected static $conditions = array( 'exists' );

	/**
	 * @var business object
	 */
	private $bo;

	/**
	* For figuring out if a record has changed
	*/
	protected $tracking;

	/**
	 * imports entries according to given definition object.
	 * @param resource $_stream
	 * @param string $_charset
	 * @param definition $_definition
	 */
	public function init( importexport_definition &$_definition, importexport_import_csv &$import_csv )
	{
		// fetch the bo
		$this->bo = new tracker_bo();

		// Get the tracker for changes
		$this->tracking = new tracker_tracking($this->bo);

		// Add extra conversions
		$import_csv->conversion_class = $this;

		// set Owner
		$plugin_options = $_definition->plugin_options;
		$plugin_options['record_owner'] = isset( $_definition->plugin_options['record_owner'] ) ?
			$_definition->plugin_options['record_owner'] : $this->user;
		$_definition->plugin_options = $plugin_options;

		// Process cat_id as a normal select
		$this->types = tracker_egw_record::$types;
		unset($this->types['select-cat']);
		$this->types['select'][] = 'cat_id';

		$this->lookups = array(
			'tr_tracker'    => $this->bo->trackers,
		);
	}

	/**
	* Import a single record
	*
	* You don't need to worry about mappings or translations, they've been done already.
	* You do need to handle the conditions and the actions taken.
	*
	* Updates the count of actions taken
	*
	* @return boolean success
	*/
	protected function import_record(importexport_iface_egw_record &$egw_record, &$import_csv)
	{
		$record = $egw_record->get_record_array();
		$_definition = $this->definition;

		// Set creator/group, unless it's supposed to come from CSV file
		foreach(array('owner' => 'creator', 'group' => 'group', 'assigned' => 'assigned') as $option => $field) {
			if($_definition->plugin_options[$option.'_from_csv'] && $record['tr_'.$field]) {
				if(!is_numeric($record['tr_'.$field]))
				{
					// Automatically handle text owner without explicit translation
					$new_owner = importexport_helper_functions::account_name2id($record['tr_'.$field]);
					if($new_owner == '') {
						$this->errors[$import_csv->get_current_position()] = lang(
							'Unable to convert "%1" to account ID.  Using plugin setting (%2) for %3.',
							$record['tr_'.$field],
							Api\Accounts::username($_definition->plugin_options['record_'.$option]),
							lang($this->bo->field2label['tr_'.$field])
						);
						$record['tr_'.$field] = $_definition->plugin_options['record_'.$option];
					} else {
						$record['tr_'.$field] = $new_owner;
					}
				}
			} elseif ($_definition->plugin_options['record_'.$option]) {
				$record['tr_'.$field] = $_definition->plugin_options['record_'.$option];
			}
		}

		// Lookups - from human friendly to integer
			$lookups = array(
			'tr_version'    => $this->bo->get_tracker_labels('version', null),
			'tr_status'     => $this->bo->get_tracker_stati(null),
			'tr_resolution' => $this->bo->get_tracker_labels('resolution',null),
			'cat_id'	=> $this->bo->get_tracker_labels('cat', null)
		);
		if(($id = $record['tr_tracker']) && $lookups['tr_tracker'][$id]) {
			$lookups['tr_version'] += $this->bo->get_tracker_labels('version', $id);
			$lookups['tr_status'] += $this->bo->get_tracker_stati($id);
			$lookups['tr_resolution'] += $this->bo->get_tracker_labels('resolution', $id);
		}

		// Translate lookups
		foreach($lookups as $field => &$l_values)
		{
			foreach($l_values as &$l_label)
			{
				$l_label = lang($l_label);
			}
		}
		$all_lookups = $this->lookups + $lookups;

		foreach(array('tr_tracker', 'tr_version','tr_status','tr_priority','tr_resolution','cat_id') as $field) {
			if(!is_numeric($record[$field]) || $_definition->plugin_options['convert'] == 1) {
				$translate_key = 'translate'.(substr($field,0,2) == 'tr' ? substr($field,2) : '_cat_id');
				$key = false;

				//echo "Checking $field. Currently {$record[$field]}.<br />";

				// Check for key as value - importing DB values, or from conversion
				if(is_numeric($record[$field]) && $all_lookups[$field][$record[$field]]) $key = $record[$field];

				// Look for human values - existing ones should already be IDs
				if(!$key)
				{
					$key = array_search($record[$field], $all_lookups[$field]);
				}
				if($key !== false) {
					$record[$field] = $key;
				} elseif(array_key_exists($translate_key, $_definition->plugin_options)) {
					$t_field = $_definition->plugin_options[$translate_key];
					//echo "Got some options here :$t_field<br />";
					switch ($t_field) {
						case '':
						case '0':
							// Skip that field
							unset($record[$field]);
							break;
						case '~skip~':
							$this->results['skipped']++;
							continue 2;
						default:
							if(strpos($t_field, 'add') === 0) {
								// Add the thing in.  Takes some extra measures for tracker
								// Check for a parent
								list($name, $parent_name) = explode('~',$t_field);
								if($parent_name) {
									$parent = importexport_helper_functions::cat_name2id($parent_name);
								}

								// Get type
								$type = substr($field,0,2) == 'tr' ? substr($field,3) : 'cat';
								if($type == 'status') $type = 'stati';

								// Get category
								$cat_id = $GLOBALS['egw']->categories->name2id( $record[$field]);
								if($cat_id) $cat = $GLOBALS['egw']->categories->read($cat_id);

								// Add in extra data
								if($cat_id == 0 || $cat['data']['type'] != $type
									|| $GLOBALS['egw']->categories->is_global($cat_id)) // Global doesn't count
								{
									$cat_id = $GLOBALS['egw']->categories->add( array(
										'name' => $record[$field],
										'access' => 'public',
										'owner' => 0,
										'parent' => $parent,
										'descr' => $record[$field]. ' ('. lang('Automatically created by importexport'). ')',
										'data' => serialize(array('type' => $type))
									));
								}
								$record[$field] = $cat_id;
							} elseif(($key = array_search($t_field, $all_lookups[$field]))) {
								$record[$field] = $key;
							} else {
								$record[$field] = $t_field;
							}
							break;
					}
				}
			}
			if($field == 'tr_tracker') {
				$all_lookups['tr_priority'] = $this->bo->get_tracker_priorities($record['tr_tracker'], $record['cat_id']);
				$all_lookups['cat_id']	= $this->bo->get_tracker_labels('cat', $record['tr_tracker']);
			}
			//echo "Final: {$record[$field]}<br />";
		}

		// Special values
		if ($record['addressbook'] && !is_numeric($record['addressbook']))
		{
			list($lastname,$firstname,$org_name) = explode(',',$record['addressbook']);
			$record['addressbook'] = self::addr_id($lastname,$firstname,$org_name);
		}

		// Comments
		if($record['replies']) {
			if(substr($record['replies'], 0, 2) == 'a:') {
				// Tracker export with DB values, all comments serialized
				$record['replies'] = unserialize($record['replies']);
				$replies = array();
				foreach($record['replies'] as $id => $reply) {
					// User date format
					$date = date($GLOBALS['egw_info']['user']['preferences']['common']['dateformat'] . ', '.
						($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] == '24' ? 'H' : 'h').':i:s',$reply['reply_created']);
					$name = Api\Accounts::username($reply['reply_creator']);
					$message = str_replace("\r\n", "\n", $reply['reply_message']);

					$replies[$id] = "$date\t$name\t$message";
				}
				$record['replies'] = implode("\n",$replies);
			}
			// Import all comments as a single comment
			$record += array(
				'reply_creator' => $this->user,
				'reply_created' => time(),
				'reply_message' => $record['replies']
			);
			unset($record['replies']);
		}


		$egw_record->set_record($record);

		if ( $_definition->plugin_options['conditions'] ) {
			foreach ( $_definition->plugin_options['conditions'] as $condition ) {
				$results = array();
				switch ( $condition['type'] ) {
					// exists
					case 'exists' :
						if($record[$condition['string']]) {
							$results = $this->bo->search(array($condition['string'] => $record[$condition['string']]));
						}
						if ( is_array( $results ) && count( array_keys( $results )) >= 1 ) {
							// apply action to all records matching this exists condition
							$action = $condition['true'];
							foreach ( (array)$results as $result ) {
								$record['tr_id'] = $result['tr_id'];
								if ( $_definition->plugin_options['update_cats'] == 'add' ) {
									if ( !is_array( $result['cat_id'] ) ) $result['cat_id'] = explode( ',', $result['cat_id'] );
									if ( !is_array( $record['cat_id'] ) ) $record['cat_id'] = explode( ',', $record['cat_id'] );
									$record['cat_id'] = implode( ',', array_unique( array_merge( $record['cat_id'], $result['cat_id'] ) ) );
								}
								$egw_record->set_record($record);
								$success = $this->action(  $action['action'], $egw_record, $import_csv->get_current_position() );
							}
						} else {
							$action = $condition['false'];
							$success = ($this->action(  $action['action'], $egw_record, $import_csv->get_current_position() ));
						}
						break;

					// not supported action
					default :
						die('condition / action not supported!!!');
						break;
				}
				if ($action['last']) break;
			}
		} else {
			// unconditional insert
			$success = $this->action( 'insert', $egw_record, $import_csv->get_current_position() );
		}
		return $success;
	}

	/**
	 * perform the required action
	 *
	 * @param int $_action one of $this->actions
	 * @param array $_data tracker data for the action
	 * @return bool success or not
	 */
	protected function action ( $_action, importexport_iface_egw_record &$record, $record_num = 0 ) {
		$_data = $record->get_record_array();
		$result = true;
		switch ($_action) {
			case 'none' :
				return true;
			case 'update' :
				// Only update if there are changes
				$old = $this->bo->read($_data['tr_id']);

				if(!$this->definition->plugin_options['change_creator']) {
					// Don't change creator of an existing ticket
					unset($_data['tr_created']);
				}

				// Merge to deal with fields not in import record
				$_data = array_merge($old, $_data);
				$changed = $this->tracking->changed_fields($_data, $old);
				if(count($changed) == 0 && !$this->definition->plugin_options['update_timestamp']) {
					$this->results['unchanged']++;
					break;
				}

				// Fall through
			case 'insert' :
				if($_action == 'insert')
				{
					unset($_data['tr_id']);
				}
				// Defaults
				if(!$_data['tr_priority']) $_data['tr_priority'] = 5;
				if(!$_data['tr_completion']) $_data['tr_completion'] = 0;
				if(!array_key_exists('tr_private', $_data) || $_data['tr_private'] === '') {
					$_data['tr_private'] = $this->bo->create_new_as_private ? 1 : 0;
				}
				if($_data['tr_private'] === null) $_data['tr_private'] = 0;

				// Can't change modifier - bo prevents it
				unset($_data['tr_modifier']);
				unset($_data['tr_modified']);

				if($this->definition->plugin_options['no_notification'])
				{
					$this->bo->data['no_notifications'] = true;
				}
				if ( $this->dry_run ) {
					//print_r($_data);
					$this->results[$_action]++;
					break;
				} else {
					$result = $this->bo->save( $_data);
					if($result) {
						$this->errors[$record_num] = lang('Permissions error - %1 could not %2',
							$GLOBALS['egw']->accounts->id2name($_data['owner']),
							lang($_action) . (is_numeric($result) ? '' : ' ' . $result)
						);
					} else {
						$this->results[$_action]++;
					}
					break;
				}
			default:
				throw new Api\Exception('Unsupported action');
		}

		// Process some additional fields
		foreach(array_keys(self::$special_fields) as $field)
		{
			if(!$_data[$field]) continue;

			// Links
			if(strpos('link', $field) === 0)
			{
				list($app, $id) = explode(':', $_data[$field]);
			}
			else
			{
				$app = $field;
				$id = $_data[$field];
			}
			if ($app && $id && $this->bo->data['tr_id'])
			{
				Link::link('tracker',$this->bo->data['tr_id'],$app,$id);
			}
		}
		return true;
	}

	/**
	 * returns translated name of plugin
	 *
	 * @return string name
	 */
	public static function get_name() {
		return lang('Tracker CSV import');
	}

	/**
	 * returns translated (user) description of plugin
	 *
	 * @return string descriprion
	 */
	public static function get_description() {
		return lang("Imports entries into the tracker from a CSV File. CSV means 'Comma separated Values'. However in the options Tab you can also choose other seperators.");
	}

	/**
	 * retruns file suffix(s) plugin can handle (e.g. csv)
	 *
	 * @return string suffix (comma seperated)
	 */
	public static function get_filesuffix() {
		return 'csv';
	}

	/**
	 * return etemplate components for options.
	 * @abstract We can't deal with etemplate objects here, as an uietemplate
	 * objects itself are scipt orientated and not "dialog objects"
	 *
	 * @return array (
	 * 		name 		=> string,
	 * 		content		=> array,
	 * 		sel_options => array,
	 * 		preserv		=> array,
	 * )
	 */
	public function get_options_etpl(importexport_definition &$definition=null)
	{
		// lets do it!
	}

	/**
	 * returns etemplate name for slectors of this plugin
	 *
	 * @return string etemplate name
	 */
	public function get_selectors_etpl() {
		// lets do it!
	}

	/**
        * Returns warnings that were encountered during importing
        * Maximum of one warning message per record, but you can append if you need to
        *
        * @return Array (
        *       record_# => warning message
        *       )
        */
        public function get_warnings() {
		return $this->warnings;
	}

	/**
        * Returns errors that were encountered during importing
        * Maximum of one error message per record, but you can append if you need to
        *
        * @return Array (
        *       record_# => error message
        *       )
        */
        public function get_errors() {
		return $this->errors;
	}

	/**
        * Returns a list of actions taken, and the number of records for that action.
        * Actions are things like 'insert', 'update', 'delete', and may be different for each plugin.
        *
        * @return Array (
        *       action => record count
        * )
        */
        public function get_results() {
                return $this->results;
        }
	// end of iface_export_plugin

	// Extra conversion functions - must be static
	public static function addr_id( $_n_family,$n_given=null,$org_name=null ) {

		// find in Addressbook, at least n_family AND (n_given OR org_name) have to match
		static $contacts=null;
		if (!isset($contacts))
		{
			$contacts = new Api\Contacts();
		}
		if (is_null($n_given) && is_null($org_name))
		{
			// Maybe all in one
			list($_n_family, $n_given, $org_name) = explode(',', $_n_family);
		}
		$n_family = trim($_n_family);
		if(!is_null($n_given)) $n_given = trim($n_given);
		if (!is_null($org_name))        // org_name given?
		{
			$org_name = trim($org_name);
			$addrs = $contacts->read( 0,0,array('id'),'',"n_family=$n_family,n_given=$n_given,org_name=$org_name" );
			if (!count($addrs))
			{
				$addrs = $contacts->read( 0,0,array('id'),'',"n_family=$n_family,org_name=$org_name",'','n_family,org_name');
			}
		}
		if (!is_null($n_given) && (is_null($org_name) || !count($addrs)))       // first name given and no result so far
		{
			$addrs = $contacts->search(array('n_family' => $n_family, 'n_given' => $n_given));
		}
		if (is_null($n_given) && is_null($org_name))    // just one name given, check against fn (= full name)
		{
			$addrs = $contacts->read( 0,0,array('id'),'',"n_fn=$n_family",'','n_fn' );
		}
		if (count($addrs))
		{
			return $addrs[0]['id'];
		}
		return False;
	}
}
