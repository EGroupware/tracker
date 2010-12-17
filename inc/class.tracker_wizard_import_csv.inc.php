<?php
/**
 * eGroupWare - Wizard for Tracker CSV import
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package tracker
 * @subpackage importexport
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @version $Id$
 */

class tracker_wizard_import_csv extends importexport_wizard_basic_import_csv
{

	/**
	 * constructor
	 */
	function __construct()
	{
		parent::__construct();

		$this->steps += array(
			'wizard_step50' => lang('Manage mapping'),
			'wizard_step60' => lang('Choose \'creator\' of imported data'),
		);

		// Field mapping
		$bo = new tracker_bo();
		$this->mapping_fields = array('tr_id' => lang('ID')) + $bo->field2label;

		// Change label from what's there
		$this->mapping_fields['tr_tracker'] = lang('Queue');

		// These aren't in the list
                $this->mapping_fields += array(
                        'tr_modifier'   => lang('Modified by'),
                        'tr_modified'   => lang('Modified'),
                        'tr_created'    => lang('Created'),
                        'tr_votes'      => lang('Votes'),
			// Bounty and Comments don't import nicely.
                );

		// These aren't importable fields
                unset($this->mapping_fields['link_to']);
                unset($this->mapping_fields['canned_response']);
                unset($this->mapping_fields['reply_message']);
                unset($this->mapping_fields['add']);
                unset($this->mapping_fields['vote']);
                unset($this->mapping_fields['no_notifications']);
                unset($this->mapping_fields['bounty']);


		// List each custom field
		unset($this->mapping_fields['custom']);
		$custom = config::get_customfields('tracker');
		foreach($custom as $name => $data) {
			$this->mapping_fields['#'.$name] = $data['label'];
		}

		$this->mapping_fields += tracker_import_csv::$special_fields;

		// Actions
		$this->actions = array(
			'none'		=>	lang('none'),
			'update'	=>	lang('update'),
			'insert'	=>	lang('insert'),
			'delete'	=>	lang('delete'),
		);

		// Conditions
		$this->conditions = array(
			'exists'	=>	lang('exists'),
		);
	}

	function wizard_step50(&$content, &$sel_options, &$readonlys, &$preserv)
	{
		$result = parent::wizard_step50($content, $sel_options, $readonlys, $preserv);
		
		return $result;
	}
	
	function wizard_step60(&$content, &$sel_options, &$readonlys, &$preserv)
	{
		if($this->debug) error_log(__METHOD__.'->$content '.print_r($content,true));
		unset($content['no_owner_map']);
		// Check that record owner has access
		$access = true;
		if($content['creator'])
		{
			$bo = new tracker_bo();
			$access = $bo->check_access(0,EGW_ACL_EDIT, $content['creator']);
		}

		// return from step60
		if ($content['step'] == 'wizard_step60')
		{
			if(!$access) {
				$step = $content['step'];
				unset($content['step']);
				return $step;
			}
			switch (array_search('pressed', $content['button']))
			{
				case 'next':
					return $GLOBALS['egw']->importexport_definitions_ui->get_step($content['step'],1);
				case 'previous' :
					return $GLOBALS['egw']->importexport_definitions_ui->get_step($content['step'],-1);
				case 'finish':
					return 'wizard_finish';
				default :
					return $this->wizard_step60($content,$sel_options,$readonlys,$preserv);
			}
		}
		// init step60
		else
		{
			$content['msg'] = $this->steps['wizard_step60'];
			if(!$access) {
				$content['msg'] .= "\n* " . lang('Owner does not have edit rights');
			}
			$content['step'] = 'wizard_step60';
			if(!array_key_exists($content['creator']) && $content['plugin_options']) {
				$content['creator'] = $content['plugin_options']['creator'];
			}
			if(!array_key_exists($content['creator_from_csv']) && $content['plugin_options']) {
				$content['creator_from_csv'] = $content['plugin_options']['creator_from_csv'];
			}
			if(!array_key_exists($content['change_creator']) && $content['plugin_options']) {
				$content['change_creator'] = $content['plugin_options']['change_creator'];
			}

			if(!in_array('tr_creator', $content['field_mapping'])) {
				$content['no_owner_map'] = true;
			}

			$preserv = $content;
			unset ($preserv['button']);
			return 'infolog.importexport_wizard_chooseowner';
		}
		
	}
}
