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
			'wizard_step45' => lang('Import options'),
			'wizard_step50' => lang('Manage mapping'),
			'wizard_step60' => lang('Choose \'creator\' of imported data'),
		);

		$this->step_templates['wizard_step45'] = 'tracker.wizard_import_options';

		// Field mapping
		$bo = new tracker_bo();
		$this->mapping_fields = array('tr_id' => lang('Tracker ID')) + $bo->field2label;
		$this->mapping_fields = array(
			'tr_id'          => 'Tracker ID',
			'tr_summary'     => 'Summary',
			'tr_tracker'     => 'Queue',
			'cat_id'         => 'Category',
			'tr_version'     => 'Version',
			'tr_status'      => 'Status',
			'tr_description' => 'Description',
			'replies'        => 'Comments',
			'tr_assigned'    => 'Assigned to',
			'tr_private'     => 'Private',
			'tr_resolution'  => 'Resolution',
			'tr_completion'  => 'Completed',
			'tr_priority'    => 'Priority',
			'tr_closed'      => 'Closed',
			'tr_creator'     => 'Created by',
			'tr_modifier'    => 'Modified by',
			'tr_modified'    => 'Last Modified',
			'tr_created'     => 'Created',
			//'tr_votes'       => 'Votes', // Not importable
			//'bounties'       => 'Bounty', // Not importable
			'tr_group'	 => 'Group',
			'tr_cc'		 => 'CC',
			'num_replies'    => 'Number of replies',
		);

		// List each custom field
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

	function wizard_step40(&$content, &$sel_options, &$readonlys, &$preserv)
	{
		$result = parent::wizard_step40($content, $sel_options, $readonlys, $preserv);
		// Hide category choice, replace is the only one that makes sense
		$content['no_cats'] = true;
		return $result;
	}

	function wizard_step45(&$content, &$sel_options, &$readonlys, &$preserv)
	{
		if($this->debug) error_log(__METHOD__.'->$content '.print_r($content,true));

		// return from step45
		if ($content['step'] == 'wizard_step45')
		{
			switch (array_search('pressed', $content['button']))
			{
				case 'next':
					return $GLOBALS['egw']->importexport_definitions_ui->get_step($content['step'],1);
				case 'previous' :
					return $GLOBALS['egw']->importexport_definitions_ui->get_step($content['step'],-1);
				case 'finish':
					return 'wizard_finish';
				default :
					return $this->wizard_step45($content,$sel_options,$readonlys,$preserv);
			}
		}
		// init step45
		else
		{
			$content['message'] = $this->steps['wizard_step45'];
			$content['step'] = 'wizard_step45';

			$ui = new tracker_ui();
			$options = array(
				false => lang('Ignore'),
				'~skip~' => lang('Skip record'),
			);
			$add_to = lang('Add to') . ':';
			$add_queue[$add_to] = array('add~' => lang('All'));
			foreach($ui->trackers as $id => $label) {
				$add_queue[$add_to]['add~'.$id] = $label;
			}
			$set_to = lang('Set to') . ':';
			$sel_options = array(
                                'translate_tracker'	=> $options + array('add' => lang('Add')) + array($set_to => $ui->trackers),
                                'translate_version'	=> $options + $add_queue + array($set_to => $ui->get_tracker_labels('version', null)),
                                'translate_status'	=> $options + $add_queue + array($set_to => $ui->get_tracker_stati(null)),
                                'translate_resolution'	=> $options + $add_queue + array($set_to => $ui->get_tracker_labels('resolution', null)),
                                'translate_cat_id'	=> $options + $add_queue + array($set_to => $ui->get_tracker_labels('cat', null)),
                        );
                        foreach($sel_options['translate_tracker'][$set_to] as $id => $name) {
                                $sel_options['translate_version'][$set_to] += $ui->get_tracker_labels('version', $id);
                                $sel_options['translate_cat_id'][$set_to] += $ui->get_tracker_labels('cat', $id);
                                $sel_options['translate_status'][$set_to] += $ui->get_tracker_stati($id);
                                $sel_options['translate_resolution'][$set_to] += $ui->get_tracker_labels('resolution', $id);
                        }

			$preserv = $content;
			foreach($sel_options as $field => $options) {
				if(!array_key_exists($field,$content)) $content[$field] = $content['plugin_options'][$field];
			}
			unset ($preserv['button']);
			return $this->step_templates['wizard_step45'];
		}
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

		// return from step60
		if ($content['step'] == 'wizard_step60')
		{
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
			$content['step'] = 'wizard_step60';
			$preserv = $content;
			foreach(array('owner', 'group', 'assigned') as $field) {
				if(!array_key_exists('record_'.$field, $content) && $content['plugin_options']) {
					$content['record_'.$field] = $content['plugin_options']['record_'.$field];
				}
				if(!array_key_exists($field.'_from_csv', $content) && $content['plugin_options']) {
					$content[$field.'_from_csv'] = $content['plugin_options'][$field.'_from_csv'];
				}
				if(!array_key_exists('change_'.$field, $content) && $content['plugin_options']) {
					$content['change_'.$field] = $content['plugin_options']['change_'.$field];
				}
			}

			if(!in_array('tr_creator', $content['field_mapping'])) {
				$content['no_owner_map'] = true;
			}

			unset ($preserv['button']);
			return 'tracker.importexport_wizard_chooseowner';
		}
		
	}
}
