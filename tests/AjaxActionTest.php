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

	/**
	 * A real eTemplate request id, the way the browser sends one along - the endpoint refuses
	 * without it, see Nextmatch::validateExecId().  Writing to the request is what persists it.
	 */
	protected function execId() : string
	{
		$request = \EGroupware\Api\Etemplate\Request::read();
		$id = $request->id();
		$request->content = ['nm' => []];
		unset($request);
		return $id;
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

		(new \tracker_ui())->ajax_action($this->execId(), 'close', [$this->tr_id], false, []);

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

		(new \tracker_ui())->ajax_action($this->execId(), 'close_100', [$this->tr_id], false, []);

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

		(new \tracker_ui())->ajax_action($this->execId(), 'completion_50', [$this->tr_id], false, []);

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

		(new \tracker_ui())->ajax_action($this->execId(), 'close', [$this->tr_id], false, []);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms);
		$this->assertSame('tracker', $parms[4],
			'never the msg-only-push-refresh sentinel');
	}

	/**
	 * The popup actions (Change group, Change assigned, Multiple changes) show a small form whose
	 * OK button used to submit the whole eTemplate, just so index() could assemble $content into
	 * something action() understands. That assembly now happens client-side, so these pin the two
	 * shapes action() accepts - no server change was made for either.
	 */
	public function testGroupPopupCompositeIdSetsTheGroup()
	{
		$this->makeTicket();
		$group = $this->aGroupId();
		if (!$group)
		{
			$this->markTestSkipped('no group to assign');
		}
		// tracker_bo::save() validates tr_group against the queue's own configuration, which has
		// nothing to do with the action id being tested. If this instance will not take the group
		// at all, skip rather than report a red that is about the fixture.
		$this->bo->read($this->tr_id);
		$this->bo->data['tr_group'] = $group;
		if ($this->bo->save() !== 0)
		{
			$this->markTestSkipped('this instance does not accept tr_group=' . $group);
		}
		$this->bo->read($this->tr_id);
		$this->bo->data['tr_group'] = null;
		$this->bo->save();

		// action() strips the verb: list(,$settings) = explode('_', $settings)
		(new \tracker_ui())->ajax_action($this->execId(), 'group_set_' . $group, [$this->tr_id], false, []);

		$this->assertEquals($group, $this->readTicket()['tr_group'],
			'group_<verb>_<id> must set the group, whatever the verb');
	}

	public function testAssignedPopupCompositeIdSetsAssigned()
	{
		$this->makeTicket();
		$me = $GLOBALS['egw_info']['user']['account_id'];

		(new \tracker_ui())->ajax_action($this->execId(), 'assigned_ok_' . $me, [$this->tr_id], false, []);

		$this->assertContains((string)$me, array_map('strval', (array)$this->readTicket()['tr_assigned']),
			'assigned_ok_<ids> must set who it is assigned to');
	}

	/**
	 * "Multiple changes" is the other shape: the whole popup as an array, which action() applies
	 * field by field via its is_array($action) && $action['update'] branch.
	 */
	public function testMultipleChangesArrayShapeAppliesFields()
	{
		$this->makeTicket();
		$this->assertNotEquals(50, $this->readTicket()['tr_completion']);

		(new \tracker_ui())->ajax_action($this->execId(), ['update' => true, 'tr_completion' => 50],
			[$this->tr_id], false, []);

		$this->assertEquals(50, $this->readTicket()['tr_completion'],
			'the array shape must apply each field given');
	}

	/**
	 * "Multiple changes" is the one action whose payload is a whole field map rather than an id,
	 * and ajax_action() has no eTemplate behind it to validate that map.  On the submit path the
	 * array arrived as process_exec()'s validated $content['admin_popup'], so it could only ever
	 * hold the widgets index.xet declares; reaching action() directly, it could hold anything, and
	 * every key is written onto $this->data before save().  Pin that the field set is bounded
	 * server-side, or a crafted request rewrites columns the popup never offered.
	 */
	public function testMultipleChangesIgnoresFieldsThePopupDoesNotOffer()
	{
		$this->makeTicket();
		$before = $this->readTicket();
		$this->assertNotEquals(50, $before['tr_completion']);

		(new \tracker_ui())->ajax_action($this->execId(), [
			'update'        => true,
			'tr_completion' => 50,          // declared in the popup - must apply
			'tr_creator'    => 1,           // not declared - must NOT apply
			'tr_private'    => 1,           // not declared - must NOT apply
		], [$this->tr_id], false, []);

		$after = $this->readTicket();
		$this->assertEquals(50, $after['tr_completion'], 'a field the popup offers must still apply');
		$this->assertEquals($before['tr_creator'], $after['tr_creator'],
			'tr_creator is not in the popup, so the action must not be able to set it');
		$this->assertEquals($before['tr_private'], $after['tr_private'],
			'tr_private is not in the popup, so the action must not be able to set it');
	}

	/**
	 * A group the ticket can actually be given.
	 *
	 * tracker_bo::save() validates tr_group and rejects an arbitrary group - it has to be one of
	 * the user's own memberships (see tracker_so's own tr_group filter). Using any group id at
	 * all made this fail on a save() error that has nothing to do with the action id.
	 */
	protected function aGroupId() : ?int
	{
		$memberships = (array)$GLOBALS['egw']->accounts->memberships(
			$GLOBALS['egw_info']['user']['account_id'], true);
		return $memberships ? (int)reset($memberships) : null;
	}

	/**
	 * "Select all" re-runs the list query to expand the selection; with nothing cached that used
	 * to run unfiltered, ie. every ticket the user can see.
	 */
	public function testSelectAllWithoutACachedQueryTouchesNothing()
	{
		$this->makeTicket();
		Api\Cache::unsetSession('tracker', 'index');

		(new \tracker_ui())->ajax_action($this->execId(), 'close', [], true, []);

		$this->assertNotSame(\tracker_bo::STATUS_CLOSED, $this->readTicket()['tr_status'],
			'select-all with no cached query must NOT fall back to acting on everything');
	}
}
