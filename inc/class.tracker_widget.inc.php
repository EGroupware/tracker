<?php
/**
 * EGroupware  eTemplate extension - Tracker widget
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package etemplate
 * @subpackage extensions
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker@outdoor-training.de>
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Etemplate;

/**
 * eTemplate extension: Tracker widget
 *
 * This widget can be used to display data from an Tracker specified by it's id
 *
 * The tracker-value widget takes 3 comma-separated arguments (beside the name) in the options/size field:
 * 1) name of the field (as provided by the tracker-fields widget)
 * 2) an optional compare value: if given the selected field is compared with its value and an X is printed on equality, nothing otherwise
 * 3) colon (:) separted list of alternative fields: the first non-empty one is used if the selected value is empty
 * There's a special field "sum" in 1), which sums up all fields given in alternatives.
 */
class tracker_widget extends Etemplate\Widget\Entry
{

	/**
	 * Array with a transformation description, based on attributes to modify.
	 * @see etemplate_widget_transformer
	 *
	 * @var array
	 */
	protected static $transformation = array(
		'type' => array(
			'tracker-fields' => array(
				'sel_options' => array('__callback__' => '_get_fields'),
				'type' => 'select',
				'no_lang' => true,
				'options' => 'None',
			),
			'__default__' => array(
				'options' => array(
					// Others added automatically in constructor
					'__default__' => array('type' => 'label'),
				),
				'no_lang' => true,
			),
		),
	);
	/**
	 * exported methods of this class
	 *
	 * @var array $public_functions
	 */
	var $public_functions = array(
		'pre_process' => True,
	);
	/**
	 * availible extensions and there names for the editor
	 *
	 * @var string/array $human_name
	 */
	var $human_name = array(
		'tracker-value'  => 'Tracker value',
		'tracker-fields' => 'Tracker fields',
	);
	/**
	 * Instance of the tracker_bo class
	 *
	 * @var tracker_bo
	 */
	var $tracker;
	/**
	 * Cached tracker
	 *
	 * @var array
	 */
	var $data;

	/**
	 * Constructor of the extension
	 *
	 */
	function __construct($xml)
	{
		parent::__construct($xml);
		$this->tracker = new tracker_bo();

		// Automatically add all known types from egw_record
		if(count(self::$transformation['type']['__default__']['options']) == 2)
		{
			foreach(tracker_egw_record::$types as $type => $fields)
			{
				foreach($fields as $field)
				{
					if(self::$transformation['type']['__default__']['options'][$field]) continue;
					self::$transformation['type']['__default__']['options'][$field] = array(
						'type' => $type
					);
				}
			}
		}
	}

	/**
	 * Get tracker data, if $value not already contains them
	 *
	 * @param int|string|array $value
	 * @param array $attrs
	 * @return array
	 */
	public function get_entry($value, array $attrs)
	{
		if(!$value)
		{
			return [];
		}

		// Already done
		if(is_array($value) && !(array_key_exists('app', $value) && array_key_exists('id', $value)))
		{
			return $value;
		}

		// Link entry, already in array format
		if(is_array($value) && array_key_exists('app', $value) && array_key_exists('id', $value))
		{
			$value = $value['id'];
		}

		// Link entry, in string format
		if(substr($value, 0, 8) == 'tracker:')
		{
			$value = substr($value, 8);
		}

		switch($attrs['type'])
		{
			case 'tracker-value':
			default:
				if (!($entry = $this->tracker->read($value)))
				{
					$entry = array();
				}
				break;
		}
		//error_log(__METHOD__."('$value') returning ".array2string($entry));
		return $entry;
	}

	function _get_fields()
	{
		static $fields=null;

		if (!isset($fields))
		{
			$fields = array(
				'' => lang('Sum'),
			);

			static $remove = array(
				'link_to','canned_response','reply_message','add','vote',
				'no_notifications','bounty','num_replies','customfields',
			);
			$fields += array_diff_key($this->tracker->field2label, array_flip($remove));
			$fileds['tr_id'] = 'ID';
			$fileds['tr_modified'] = 'Modified';
			$fileds['tr_modifier'] = 'Modifier';

			foreach(Api\Storage\Customfields::get('tracker') as $name => $data)
			{
				$fields['#'.$name] = lang($data['label']);
			}
		}
		return $fields;
	}
}

Etemplate\Widget::registerWidget(__NAMESPACE__ . '\\tracker_widget', array('tracker_widget'));