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
					'' => array('id' => '@value[@id]'),
					// Others added automatically in constructor
					'__default__' => array('type' => 'label', 'options' => ''),
				),
				'no_lang' => 1,
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
		// Already done
		if (is_array($value) && !(array_key_exists('app',$value) && array_key_exists('id', $value))) return $value;

		// Link entry, already in array format
		if(is_array($value) && array_key_exists('app', $value) && array_key_exists('id', $value)) $value = $value['id'];

		// Link entry, in string format
		if (substr($value,0,8) == 'tracker:') $value = substr($value,8);

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
		error_log(__METHOD__."('$value') returning ".array2string($entry));
		return $entry;
	}

	/**
	 * pre-processing of the extension
	 *
	 * This function is called before the extension gets rendered
	 *
	 * @param string $name form-name of the control
	 * @param mixed &$value value / existing content, can be modified
	 * @param array &$cell array with the widget, can be modified for ui-independent widgets
	 * @param array &$readonlys names of widgets as key, to be made readonly
	 * @param mixed &$extension_data data the extension can store persisten between pre- and post-process
	 * @param etemplate &$tmpl reference to the template we belong too
	 * @return boolean true if extra label is allowed, false otherwise
	 */
	function pre_process($name,&$value,&$cell,&$readonlys,&$extension_data,&$tmpl)
	{
		switch($cell['type'])
		{
			case 'tracker-fields':
				Api\Translation::add_app('addressbook');
				$cell['sel_options'] = $this->_get_fields();
				$cell['type'] = 'select';
				$cell['no_lang'] = 1;
				break;

			case 'tracker-value':
			default:
				if (substr($value,0,8) == 'tracker:') $value = substr($value,8);	// link-entry syntax
				if (!$value || !$cell['size'] || (!is_array($this->data) || $this->data['tr_id'] != $value) &&
					!($this->data = $this->tracker->read($value)))
				{
					$cell = $tmpl->empty_cell();
					$value = '';
					break;
				}
				list($type,$compare,$alternatives,$contactfield,$regex,$replace) = explode(',',$cell['size'],6);
				$value = $this->data[$type];
				$cell['size'] = '';
				$cell['no_lang'] = 1;
				$cell['readonly'] = true;

				switch($type)
				{
					case '':	// Sum of the alternatives, field-name can be prefixed with a minus to substract it's value
						$cell['type'] = 'float';
						$cell['size'] = ',,,%0.2lf';
						$value = 0.0;
						foreach(explode(':',$alternatives) as $name)
						{
							if ($name[0] === '-')
							{
								$val = '-'.$this->data[substr($name, 1)];
							}
							else
							{
								$val = $this->data[$name];
							}
							$value += str_replace(array(' ',','), array('','.'), $val);
						}
						$alternatives = '';
						break;

					case 'tr_created':
					case 'tr_startdate':
					case 'tr_duedate':
					case 'tr_modified':
					case 'tr_closed':
						$cell['type'] = 'date-time';
						break;

					case 'tr_assigned':
					case 'tr_creator':
					case 'tr_group':
					case 'tr_modifier':
						$cell['type'] = 'select-owner';
						break;

					case 'tr_tracker':
					case 'cat_id':
					case 'tr_version':
					case 'tr_status':
					case 'tr_resolution':
						$cell['type'] = 'select-cat';
						break;

					case 'tr_completion':
						$cell['type'] = 'select-percent';
						break;

					case 'tr_private':
						$cell['type'] = 'checkbox';
						break;

					default:
						if ($type{0} == '#')	// custom field --> use field-type itself
						{
							$field = $this->tracker->customfields[substr($type,1)];
							if (($cell['type'] = $field['type']))
							{
								if ($field['type'] == 'select')
								{
									$cell['sel_options'] = $field['values'];
								}
								break;
							}
						}
						$cell['type'] = 'label';
						break;
				}
				if ($alternatives && empty($value))	// use first non-empty alternative if value is empty
				{
					foreach(explode(':',$alternatives) as $name)
					{
						if (($value = $this->data[$name])) break;
					}
				}
				if (!empty($compare))				// compare with value and print a X is equal and nothing otherwise
				{
					$value = $value == $compare ? 'X' : '';
					$cell['type'] = 'label';
				}
				// modify the value with a regular expression
				if (!empty($regex))
				{
					$parts = explode('/',$regex);
					if (strchr(array_pop($parts),'e') === false)	// dont allow e modifier, which would execute arbitrary php code
					{
						$value = preg_replace($regex,$replace,$value);
					}
					$cell['type'] = 'label';
					$cell['size'] = '';
				}
				// use a contact widget to render the value, eg. to fetch contact data from an linked tracker
				if (!empty($contactfield))
				{
					$cell['type'] = 'contact-value';
					$cell['size'] = $contactfield;
				}
				break;
		}
		$cell['id'] = ($cell['id'] ? $cell['id'] : $cell['name'])."[$type]";

		return True;	// extra label ok
	}

	function _get_fields()
	{
		static $fields;

		if (!is_null($fields)) return $fields;

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
		return $fields;
	}
}
