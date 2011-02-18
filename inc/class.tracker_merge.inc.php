<?php
/**
 * Tracker - document merge
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @author Nathan Gray
 * @package tracker
 * @copyright (c) 2007-9 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @copyright 2011 Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * Tracker - document merge object
 */
class tracker_merge extends bo_merge
{
	/**
	 * Functions that can be called via menuaction
	 *
	 * @var array
	 */
	var $public_functions = array(
		'show_replacements'		=> true,
		'tracker_replacements'	=> true,
	);

	/**
	 * Business object to pull records from
	 */
	protected $bo = null;

	/**
	 * Constructor
	 *
	 */
	function __construct()
	{
		parent::__construct();
		$this->table_plugins['comment'] = 'comment';
		$this->bo = new tracker_bo();
	}

	/**
	 * Get replacements
	 *
	 * @param int $id id of entry
	 * @param string &$content=null content to create some replacements only if they are use
	 * @return array|boolean
	 */
	protected function get_replacements($id,&$content=null)
	{
		if (!($replacements = $this->tracker_replacements($id)))
		{
			return false;
		}
		if(strpos($content,'all_comments') !== false) {
			$this->bo->read($id);
			$tracker = $this->bo->data;
			$replies = array();
			foreach($tracker['replies'] as $id => $reply) {
				// User date format
				$date = egw_time::to($reply['reply_created']);
				$name = common::grab_owner_name($reply['reply_creator']);
				$message = str_replace("\r\n", "\n", $reply['reply_message']);
				$restricted = $reply['reply_visible'] ? ('[' .lang('restricted comment').']') : '';
				$replies[$id] = "$date \t$name \t$restricted\n$message";
			}
			$replacements['$$all_comments$$'] = implode("\n",$replies);
		}
		return $replacements;
	}

	/**
	 * Get tracker replacements
	 *
	 * @param int $id id of entry
	 * @param string $prefix='' prefix like eg. 'erole'
	 * @return array|boolean
	 */
	public function tracker_replacements($id,$prefix='') 
	{
		$record = new tracker_egw_record($id);
		$info = array();

		// Convert to human friendly values
		$types = tracker_export_csv::$types;
		$types['select'][] = 'tr_private';
		// Get lookups for human-friendly values
		$lookups = array(
			'tr_tracker'    => $this->bo->trackers,
			'tr_version'    => $this->bo->get_tracker_labels('version', null),
			'tr_status'     => $this->bo->get_tracker_stati(null),
			'tr_resolution' => tracker_bo::$resolutions,
			'tr_private'	=> array('' => lang('no'),'1'=>lang('yes'))
		);
		foreach($lookups['tr_tracker'] as $t_id => $name) {
			$lookups['tr_version'] += $this->bo->get_tracker_labels('version', $t_id);
			$lookups['tr_status'] += $this->bo->get_tracker_stati($t_id);
		}
		importexport_export_csv::convert($record, $types, 'tracker', $lookups);
		// Set any missing custom fields, or the marker will stay
		$array = $record->get_record_array();
		foreach($this->bo->customfields as $name => $field)
		{
			if(!$array['#'.$name]) $array['#'.$name] = '';
		}

		// Add markers
		foreach($array as $key => &$value)
		{
			if(!$value) $value = '';
			$info['$$'.($prefix ? $prefix.'/':'').$key.'$$'] = $value;
		}
		return $info;
	}

	/**
	 * Table plugin for comments
	 *
	 * @param string $plugin
	 * @param int $id
	 * @param int $n
	 * @return array
        */
        public function comment($plugin,$id,$n)
        {
		static $comments;

		if($comments[$id][$n]) return $comments[$id][$n];

		$this->bo->read($id);
		$tracker = $this->bo->data;

		$comments = array(); // Clear it to keep memory down
		foreach($tracker['replies'] as $i => $reply) {
			$comments[$id][] = array(
				'$$comment/date$$' => $this->format_datetime($reply['reply_created']),
				'$$comment/message$$' => $reply['reply_message'],
				'$$comment/restricted$$' => $reply['reply_visible'] ? ('[' .lang('restricted comment').']') : '',
			) + $this->contact_replacements($reply['reply_creator'], 'comment/user');
		}
		return $comments[$id][$n];
	}

	/**
	 * Generate table with replacements for the preferences
	 *
	 */
	public function show_replacements()
	{
		$GLOBALS['egw_info']['flags']['app_header'] = lang('tracker').' - '.lang('Replacements for inserting entries into documents');
		$GLOBALS['egw_info']['flags']['nonavbar'] = false;
		common::egw_header();

		echo "<table width='90%' align='center'>\n";
		echo '<tr><td colspan="4"><h3>'.lang('Tracker fields:')."</h3></td></tr>";

		$n = 0;
		$fields = array('tr_id' => lang('Tracker ID')) + $this->bo->field2label;
		$fields['bounty'] = lang('bounty');
		$fields['all_comments'] = lang("All comments together, User\tDate\tMessage");
		foreach($fields as $name => $label)
		{
			if (in_array($name,array('link_to','canned_response','reply_message','add','vote','no_notifications','num_replies','customfields'))) continue;	// dont show them

			if (in_array($name,array('tr_summary', 'tr_description')) && $n&1)		// main values, which should be in the first column
			{
				echo "</tr>\n";
				$n++;
			}
			if (!($n&1)) echo '<tr>';
			echo '<td>$$'.$name.'$$</td><td>'.$label.'</td>';
			if ($n&1) echo "</tr>\n";
			$n++;
		}

		echo '<tr><td colspan="4"><h3>'.lang('Comments').":</h3></td></tr>";
		echo '<tr><td colspan="4">$$table/comment$$</td></tr>';
		foreach(array(
			'date' => 'date', 
			'user/n_fn' => 'User - All contact fields are valid',
			'message' => 'Message',
			'restricted' => 'If the message was restricted'
		) as $name => $label) {
			echo '<tr><td /><td>$$comment/'.$name.'$$</td><td>'.lang($label).'</td></tr>';
		}
 		echo '<tr><td>$$endtable$$</td></tr>';
		
		echo '<tr><td colspan="4"><h3>'.lang('Custom fields').":</h3></td></tr>";
		foreach($this->bo->customfields as $name => $field)
		{
			echo '<tr><td>$$#'.$name.'$$</td><td colspan="3">'.$field['label']."</td></tr>\n";
		}

		echo '<tr><td colspan="4"><h3>'.lang('General fields:')."</h3></td></tr>";
		foreach(array(
			'date' => lang('Date'),
			'user/n_fn' => lang('Name of current user, all other contact fields are valid too'),
			'user/account_lid' => lang('Username'),
			'pagerepeat' => lang('For serial letter use this tag. Put the content, you want to repeat between two Tags.'),
			'label' => lang('Use this tag for addresslabels. Put the content, you want to repeat, between two tags.'),
			'labelplacement' => lang('Tag to mark positions for address labels'),
			'IF fieldname' => lang('Example $$IF n_prefix~Mr~Hello Mr.~Hello Ms.$$ - search the field "n_prefix", for "Mr", if found, write Hello Mr., else write Hello Ms.'),
			'NELF' => lang('Example $$NELF role$$ - if field role is not empty, you will get a new line with the value of field role'),
			'NENVLF' => lang('Example $$NELFNV role$$ - if field role is not empty, set a LF without any value of the field'),
			'LETTERPREFIX' => lang('Example $$LETTERPREFIX$$ - Gives a letter prefix without double spaces, if the title is emty for example'),
			'LETTERPREFIXCUSTOM' => lang('Example $$LETTERPREFIXCUSTOM n_prefix title n_family$$ - Example: Mr Dr. James Miller'),
			) as $name => $label)
		{
			echo '<tr><td>$$'.$name.'$$</td><td colspan="3">'.$label."</td></tr>\n";
		}

		echo "</table>\n";

		common::egw_footer();
	}
}
