<?php
/**
 * eGroupWare - Wizard for Tracker CSV export
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package tracker
 * @subpackage importexport
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @version $Id$
 */

class tracker_wizard_export_csv extends importexport_wizard_basic_export_csv
{
	public function __construct() {
		parent::__construct();

		// Field mapping
		$bo = new tracker_bo();
		$this->export_fields = array('tr_id' => lang('ID')) + $bo->field2label;

		// Change label from what's there
		$this->export_fields['tr_tracker'] = lang('Queue');

		// These aren't in the list
		$this->export_fields += array(
			'tr_modifier'	=> lang('Modified by'),
			'tr_modified'	=> lang('Modified'),
			'tr_created'	=> lang('Created'),
			'tr_votes'	=> lang('Votes'),
			'bounties'	=> lang('Bounty'),
			
		);

		// These aren't exportable fields
		unset($this->export_fields['link_to']);
		unset($this->export_fields['canned_response']);
		unset($this->export_fields['reply_message']);
		unset($this->export_fields['add']);
		unset($this->export_fields['vote']);
		unset($this->export_fields['no_notifications']);
		unset($this->export_fields['bounty']);

		// Add in comments
		$this->export_fields['replies'] = lang('Comments');

		// Custom fields
		unset($this->export_fields['customfields']); // Heading, not a real field
		$custom = config::get_customfields('tracker', true);
		foreach($custom as $name => $data) {
			$this->export_fields['#'.$name] = $data['label'];
		}
	}
}
