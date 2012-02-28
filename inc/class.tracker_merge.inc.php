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
		$this->table_plugins['comment/-1'] = 'comment';
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
		if (!($replacements = $this->tracker_replacements($id,'', $content)))
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
				if($reply['reply_visible'] > 0) {
					$message = '['.$message.']';
				}
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
	public function tracker_replacements($id,$prefix='', &$content='') 
	{
		$record = new tracker_egw_record($id);
		$info = array();

		// Convert to human friendly values
		$types = tracker_egw_record::$types;
		// Get lookups for human-friendly values
		$lookups = array(
			'tr_tracker'    => $this->bo->trackers,
			'tr_version'    => $this->bo->get_tracker_labels('version', null),
			'tr_status'     => $this->bo->get_tracker_stati(null),
			'tr_resolution' => $this->bo->get_tracker_labels('resolution',null),
			'tr_private'	=> array(false => lang('no'),'1'=>lang('yes'))
		);
		foreach($lookups['tr_tracker'] as $t_id => $name) {
			$lookups['tr_version'] += $this->bo->get_tracker_labels('version', $t_id);
			$lookups['tr_status'] += $this->bo->get_tracker_stati($t_id);
			$lookups['tr_resolution'] += $this->bo->get_tracker_labels('resolution', $t_id);
		}
		$array = array();

		// Signature
		if($this->bo->notification[$record->tr_tracker]['use_signature'])
		{
			if(trim(strip_tags($this->bo->notification[$record->tr_tracker]['signature'])))
			{
				$array['signature'] = $this->bo->notification[$record->tr_tracker]['signature'];
			}
			else
			{
				$array['signature'] = $this->bo->notification[0]['signature'];
			}
		}

		importexport_export_csv::convert($record, $types, 'tracker', $lookups);
		$array += $record->get_record_array();

		// HTML link to ticket
		$tracker = new tracker_tracking($this->bo);
		$array['tr_link'] = $tracker->get_link($array, array());

		// Set any missing custom fields, or the marker will stay
		foreach($this->bo->customfields as $name => $field)
		{
			if(!$array['#'.$name]) $array['#'.$name] = '';
		}

		
		// Links
		$pattern = '@\$(links|attachments|links_attachments)\/?(title|href|link)?\/?([a-z]*)\$@';
		static $link_cache;
		if(preg_match_all($pattern, $content, $matches))
		{
			foreach($matches[0] as $i => $placeholder)
			{
				$placeholder = substr($placeholder, 1, -1);
				if($link_cache[$id][$placeholder]) 
				{
					$array[$placeholder] = $link_cache[$id][$placeholder];
					continue;
				}
				switch($matches[1][$i])
				{
					case 'links':
						$array[$placeholder] = $this->get_links('tracker', $id, '!'.egw_link::VFS_APPNAME, array(),$matches[2][$i]);
						break;
					case 'attachments':
						$array[$placeholder] = $this->get_links('tracker', $id, egw_link::VFS_APPNAME,array(),$matches[2][$i]);
						break;
					default:
						$array[$placeholder] = $this->get_links('tracker', $id, $matches[3][$i], array(), $matches[2][$i]);
						break;
				}
				$link_cache[$id][$placeholder] = $array[$placeholder];
			}
		}

		// Add markers
		foreach($array as $key => &$value)
		{
			if(!$value) $value = '';
			$info['$$'.($prefix ? $prefix.'/':'').$key.'$$'] = $value;
		}
		// Special comments - already have $$
		$comments = $this->get_comments($id);
		foreach($comments[-1] as $key => $comment)
		{
			$info += $comment;
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
		$comments = $this->get_comments($id);

		return $comments[$n];
	}

	/**
	 * Get the comments for this tracker entry
	 */
	protected function get_comments($tr_id)
	{
		static $comments;
		if($comments[$tr_id]) return $comments[$tr_id];

		$this->bo->read($tr_id);
		$tracker = $this->bo->data;

		// Clear it to keep memory down - just this ticket
		$comments = array();
		$last_creator_comment = array();
		$last_assigned_comment = array();
		foreach($tracker['replies'] as $i => $reply) {
			if($reply['reply_visible'] > 0) {
				$reply['reply_message'] = '['.$reply['reply_message'].']';
			}
			$comments[$tr_id][] = array(
				'$$comment/date$$' => $this->format_datetime($reply['reply_created']),
				'$$comment/message$$' => $reply['reply_message'],
				'$$comment/restricted$$' => $reply['reply_visible'] ? ('[' .lang('restricted comment').']') : '',
				'$$comment/user$$' => common::grab_owner_name($reply['reply_creator'])
			);
			if($reply['reply_creator'] == $tracker['tr_creator'] && !$last_creator_comment) $last_creator_comment = $reply;
			if(in_array($reply['reply_creator'], $tracker['tr_assigned']) && !$last_assigned_comment) $last_assigned_comment = $reply;
		}

		// Special comments
		foreach(array('' => $tracker['replies'][0], '/creator' => $last_creator_comment, '/assigned_to' => $last_assigned_comment) as $key => $comment) {
			$comments[$tr_id][-1][$key] = array(
				'$$comment/-1'.$key.'/date$$' => $comment ? $this->format_datetime($comment['reply_created']) : '',
				'$$comment/-1'.$key.'/message$$' => $comment['reply_message'],
				'$$comment/-1'.$key.'/restricted$$' => $comment['reply_visible'] ? ('[' .lang('restricted comment').']') : '',
				'$$comment/-1'.$key.'/user$$' => $comment ? common::grab_owner_name($comment['reply_creator']) : ''
			);
		}

		return $comments[$tr_id];
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
		$fields = array('tr_id' => lang('Tracker ID')) + $this->bo->field2label + array(
			'tr_modifier' => lang('Last modified by'), 
			'tr_modified' => lang('last modified'),
		);
		$fields['bounty'] = lang('bounty');
		$fields['tr_link'] = lang('Link to ticket');
		$fields['all_comments'] = lang("All comments together, User\tDate\tMessage");
		$fields['signature'] = lang('Notification signature');
		$fields['comment/-1/...'] = 'Only the last comment';
		$fields['comment/-1/creator/...'] = 'Only the last comment by the creator';
		$fields['comment/-1/assigned_to/...'] = 'Only the last comment by one of the assigned users';
		foreach($fields as $name => $label)
		{
			if (in_array($name,array('link_to','canned_response','reply_message','add','vote','no_notifications','num_replies','customfields'))) continue;	// dont show them

			if (in_array($name,array('tr_summary', 'tr_description')) && $n&1)		// main values, which should be in the first column
			{
				echo "</tr>\n";
				$n++;
			}
			if (!($n&1)) echo '<tr>';
			echo '<td>{{'.$name.'}}</td><td>'.lang($label).'</td>';
			if ($n&1) echo "</tr>\n";
			$n++;
		}

		echo '<tr><td colspan="4"><h3>'.lang('Comments').":</h3></td></tr>";
		echo '<tr><td colspan="4">{{table/comment}}</td></tr>';
		foreach(array(
			'date' => 'date', 
			'user' => 'Username',
			'message' => 'Message',
			'restricted' => 'If the message was restricted',
		) as $name => $label) {
			echo '<tr><td /><td>{{comment/'.$name.'}}</td><td>'.lang($label).'</td></tr>';
		}
 		echo '<tr><td>{{endtable}}</td></tr>';
		
		echo '<tr><td colspan="4"><h3>'.lang('Custom fields').":</h3></td></tr>";
		foreach($this->bo->customfields as $name => $field)
		{
			echo '<tr><td>{{#'.$name.'}}</td><td colspan="3">'.$field['label']."</td></tr>\n";
		}

		echo '<tr><td colspan="4"><h3>'.lang('General fields:')."</h3></td></tr>";
		foreach(array(
			'links' => lang('Titles of any entries linked to the current record, excluding attached files'),
 			'attachments' => lang('List of files linked to the current record'),
			'links_attachments' => lang('Links and attached files'),
			'links/[appname]' => lang('Links to specified application.  Example: {{links/infolog}}'),
			'links/href' => lang('Links wrapped in an HREF tag with download link'),
			'links/link' => lang('Download url for links'),
			'date' => lang('Date'),
			'user/n_fn' => lang('Name of current user, all other contact fields are valid too'),
			'user/account_lid' => lang('Username'),
			'pagerepeat' => lang('For serial letter use this tag. Put the content, you want to repeat between two Tags.'),
			'label' => lang('Use this tag for addresslabels. Put the content, you want to repeat, between two tags.'),
			'labelplacement' => lang('Tag to mark positions for address labels'),
			'IF fieldname' => lang('Example {{IF n_prefix~Mr~Hello Mr.~Hello Ms.}} - search the field "n_prefix", for "Mr", if found, write Hello Mr., else write Hello Ms.'),
			'NELF' => lang('Example {{NELF role}} - if field role is not empty, you will get a new line with the value of field role'),
			'NENVLF' => lang('Example {{NELFNV role}} - if field role is not empty, set a LF without any value of the field'),
			'LETTERPREFIX' => lang('Example {{LETTERPREFIX}} - Gives a letter prefix without double spaces, if the title is empty for example'),
			'LETTERPREFIXCUSTOM' => lang('Example {{LETTERPREFIXCUSTOM n_prefix title n_family}} - Example: Mr Dr. James Miller'),
			) as $name => $label)
		{
			echo '<tr><td>{{'.$name.'}}</td><td colspan="3">'.$label."</td></tr>\n";
		}

		echo "</table>\n";

		common::egw_footer();
	}
}
