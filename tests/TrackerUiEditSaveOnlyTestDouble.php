<?php
/**
 * EGroupware Tracker - test double isolating tracker_ui::edit()'s save-handling from its UI tail
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

/**
 * tracker_ui::edit()'s "submitted form" branch falls through from case 'save' into case 'cancel'
 * once popup=false, which ends in `return $this->index(...)` - a full page/framework render, far
 * too heavy (and side-effecting) for a unit test and irrelevant to what MailImportInlineImageTest
 * actually exercises (the save + mail_integration::fix_inline_images() resolution that happens
 * BEFORE that tail). Overriding index() as a no-op lets edit()'s real save logic run unmodified
 * while skipping only the UI rendering nobody is asserting on.
 */
class TrackerUiEditSaveOnlyTestDouble extends tracker_ui
{
	function index($content=null, $tracker=null, $msg='', $only_tracker=null, $return_html=false)
	{
		return null;
	}
}
