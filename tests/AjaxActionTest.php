<?php
/**
 * Test the ajax endpoint the tracker list's context-menu actions now call
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

use EGroupware\Api;
use EGroupware\Api\LoggedInTest;

require_once realpath(__DIR__ . '/../../api/tests/LoggedInTest.php');

/**
 * tracker_ui::ajax_action() is a new endpoint: Close, Mark read/unread and the Queue, Version,
 * Priority, Status, Resolution, Category and Completion sub-menus used to submit the whole
 * eTemplate, rebuilding the list and losing its scroll position and selection.
 *
 * WHY THIS EXISTS
 * The endpoint was written and shipped without ever being executed against a real ticket: the
 * conversion was checked by driving actions with a non-existent row id, which short-circuits
 * (`if (!$this->read($tr_id)) continue;`) before the handler body runs. That let a PHP 8 fatal
 * through elsewhere in the same work (addressbook's cat_set).
 *
 * SETUP
 * Each test creates its own ticket in the first configured tracker queue and deletes it again in
 * tearDown. Skipped rather than failed if the instance has no tracker queue configured, since
 * that is an installation state, not a regression.
 *
 * PASS CRITERIA
 * The ticket really changed (read back through tracker_bo), and the response carries an
 * egw.refresh call - without which a converted action updates no rows.
 */
class AjaxActionTest extends LoggedInTest
{
	/** @var \tracker_bo */
	protected $bo;
	protected $tr_id;
	protected $tracker;

	protected function setUp() : void
	{
		Api\Json\Response::get()->initResponseArray();
		$this->bo = new \tracker_bo();
		$this->tracker = array_key_first((array)$this->bo->trackers);
		if (empty($this->tracker))
		{
			$this->markTestSkipped('no tracker queue configured on this instance');
		}
	}

	protected function tearDown() : void
	{
		if ($this->tr_id)
		{
			$this->bo->delete(['tr_id' => $this->tr_id]);
			$this->tr_id = null;
		}
	}

	protected function refreshCall() : ?array
	{
		$response = Api\Json\Response::get();
		$prop = (new \ReflectionClass($response))->getProperty('responseArray');
		$prop->setAccessible(true);
		foreach((array)$prop->getValue($response) as $chunk)
		{
			$chunk = (array)$chunk;
			if (($chunk['type'] ?? null) === 'apply' && ($chunk['data']['func'] ?? null) === 'egw.refresh')
			{
				return (array)$chunk['data']['parms'];
			}
		}
		return null;
	}

	protected function makeTicket() : int
	{
		$this->bo->init();
		$this->bo->data = [
			'tr_summary' => 'AjaxActionTest',
			'tr_tracker' => $this->tracker,
			'tr_status'  => \tracker_bo::STATUS_OPEN,
			'tr_creator' => $GLOBALS['egw_info']['user']['account_id'],
			'tr_description' => 'created by tracker/tests/AjaxActionTest.php',
		];
		$this->assertSame(0, $this->bo->save(), 'could not create the test ticket');
		return $this->tr_id = $this->bo->data['tr_id'];
	}

	protected function readTicket() : array
	{
		return (array)$this->bo->read($this->tr_id);
	}

	/**
	 * The regression shape: the endpoint has to reach action()'s body and persist.
	 */
	public function testCloseSetsTheStatusToClosed()
	{
		$this->makeTicket();
		$this->assertNotSame(\tracker_bo::STATUS_CLOSED, $this->readTicket()['tr_status']);

		(new \tracker_ui())->ajax_action('close', [$this->tr_id], false, []);

		$this->assertSame(\tracker_bo::STATUS_CLOSED, $this->readTicket()['tr_status'],
			'close must set the status to closed');
		$this->assertNotNull($this->refreshCall(),
			'the endpoint must answer with egw.refresh, or the list updates no rows');
	}

	/**
	 * close_100_<resolution> - the second Close action, which also sets completion and resolution.
	 */
	public function testCloseWithCompletionSetsIt()
	{
		$this->makeTicket();

		(new \tracker_ui())->ajax_action('close_100', [$this->tr_id], false, []);

		$ticket = $this->readTicket();
		$this->assertSame(\tracker_bo::STATUS_CLOSED, $ticket['tr_status']);
		$this->assertEquals(100, $ticket['tr_completion']);
	}

	/**
	 * One of the generated sub-menus, whose children all inherit the same handler.
	 */
	public function testCompletionChangeThroughTheEndpoint()
	{
		$this->makeTicket();

		(new \tracker_ui())->ajax_action('completion_50', [$this->tr_id], false, []);

		$this->assertEquals(50, $this->readTicket()['tr_completion']);
	}

	/**
	 * _targetapp must be a real app name: egw.refresh() resolves it before its msg-only
	 * early-return, and a name that is not an app throws in the kdots framework, before the
	 * result message is ever shown.
	 */
	public function testRefreshTargetappIsARealApp()
	{
		$this->makeTicket();

		(new \tracker_ui())->ajax_action('close', [$this->tr_id], false, []);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms);
		$this->assertSame('tracker', $parms[4],
			'never the msg-only-push-refresh sentinel');
	}

	/**
	 * "Select all" re-runs the list query to expand the selection; with nothing cached that used
	 * to run unfiltered, ie. every ticket the user can see.
	 */
	public function testSelectAllWithoutACachedQueryTouchesNothing()
	{
		$this->makeTicket();
		Api\Cache::unsetSession('tracker', 'index');

		(new \tracker_ui())->ajax_action('close', [], true, []);

		$this->assertNotSame(\tracker_bo::STATUS_CLOSED, $this->readTicket()['tr_status'],
			'select-all with no cached query must NOT fall back to acting on everything');
	}
}
