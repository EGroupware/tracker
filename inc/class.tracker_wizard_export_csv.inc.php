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
		$this->export_fields = array(
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
			'tr_votes'       => 'Votes',
			'bounties'       => 'Bounty',
			'tr_group'	 => 'Group',
			'tr_cc'		 => 'CC',
			'num_replies'    => 'Number of replies',
		);

		// Custom fields
		$custom = config::get_customfields('tracker', true);
		foreach($custom as $name => $data) {
			$this->export_fields['#'.$name] = $data['label'];
		}
	}
}
