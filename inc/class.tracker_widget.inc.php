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
class tracker_widget
{
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
	 * @param string $ui '' for html
	 */
	function __construct($ui)
	{
		$this->ui = $ui;
		$this->tracker = new tracker_bo();
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
				translation::add_app('addressbook');
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
					case '':	// Sum of the alternatives
						$cell['type'] = 'float';
						$cell['size'] = ',,,%0.2lf';
						$value = 0.0;
						foreach(explode(':',$alternatives) as $name)
						{
							$value += str_replace(array(' ',','),array('','.'),$this->data[$name]);
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
		$fileds['tr_modified'] = 'Modified';
		$fileds['tr_modifier'] = 'Modifier';

		foreach(config::get_customfields('tracker') as $name => $data)
		{
			$fields['#'.$name] = lang($data['label']);
		}
		return $fields;
	}
}
