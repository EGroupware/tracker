<?php
/**
 * EGroupware Tracker - validation of the "assigned to" widget, as used for the list's filter and the edit dialog
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @subpackage tests
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once realpath(__DIR__.'/../../api/tests/Etemplate/WidgetBaseTest.php');

use EGroupware\Api\Etemplate;

/**
 * The widget only accepts staff of the queue, which is right for assigning a ticket. As filter of
 * the list it is a single selection, which also offers "Not assigned": that has to pass as the
 * plain value the list's query compares with, not get dropped or wrapped in an array.
 */
class AssignedWidgetValidateTest extends Etemplate\WidgetBaseTest
{
	/**
	 * Validate a value with the widget
	 *
	 * @param mixed $value
	 * @param string $attrs further attributes of the widget
	 * @return array validated content
	 */
	private function validated($value, string $attrs='') : array
	{
		Etemplate::reset_request();
		$widget = new \tracker_assigned_etemplate_widget('<et2-tracker-assigned id="tr_assigned" '.$attrs.'/>');
		// as tracker_ui::get_rows() offers it for the filter of the list
		$request = new \ReflectionProperty(Etemplate\Widget::class, 'request');
		$request->setAccessible(true);
		$request->getValue()->sel_options = ['tr_assigned' => ['not' => 'Not assigned']];

		$validated = [];
		$widget->validate('', [], ['tr_assigned' => $value], $validated);
		return $validated;
	}

	public function testFilterAcceptsAnOfferedOptionAsSingleValue()
	{
		$this->assertSame(['tr_assigned' => 'not'], $this->validated('not'));
	}

	public function testFilterDropsWhatIsNeitherStaffNorOffered()
	{
		$this->assertSame([], array_filter($this->validated('no-such-option')));
	}

	public function testStaffIsASingleValueForAFilterAndAListForAMultipleSelection()
	{
		$staff = array_keys((new \tracker_bo())->get_staff(0, 2, 'usersANDtechnicians'));
		if (!$staff)
		{
			$this->markTestSkipped('no tracker staff on this instance');
		}
		$this->assertEquals(['tr_assigned' => $staff[0]], $this->validated((string)$staff[0]));
		$this->assertEquals(['tr_assigned' => [$staff[0]]], $this->validated([(string)$staff[0], 'no-such-option'], 'multiple="true"'));
	}
}
