<?php
/**
 * EGroupware Tracker: regression test for get_rrows()'s date_filter() wrapping
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

/**
 * get_rrows() spliced date_filter()'s return (a plain SQL string) into $query['col_filter'] at a
 * bare integer key, unwrapped in Api\Db\SqlFragment::raw() - missed by the original conversion.
 * Any tracker list view with a date filter applied (the "overdue"/"started"/"upcoming"/named-period
 * dropdown on the tracker index page - a mainline feature, not an edge case) threw instead of
 * filtering.
 */
class GetRrowsDateFilterTest extends \EGroupware\Api\AppTest
{
	private function getRrows(array $query) : array
	{
		$bo = new tracker_bo();
		$rows = $readonlys = array();
		$bo->get_rrows($query, $rows, $readonlys);
		return $rows;
	}

	public function testOverdueDateFilterDoesNotThrow()
	{
		$rows = $this->getRrows(array('filter' => 'overdue', 'start' => 0, 'num_rows' => 1));

		$this->assertIsArray($rows);
	}

	public function testStartedDateFilterDoesNotThrow()
	{
		$rows = $this->getRrows(array('filter' => 'started', 'start' => 0, 'num_rows' => 1));

		$this->assertIsArray($rows);
	}

	public function testUpcomingDateFilterDoesNotThrow()
	{
		$rows = $this->getRrows(array('filter' => 'upcoming', 'start' => 0, 'num_rows' => 1));

		$this->assertIsArray($rows);
	}
}
