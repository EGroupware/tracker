<?php
/**
 * EGroupware Tracker: ticket #124831 - a reply created via the REST API always
 * notified external "Kopie" (cc) addresses, even when the caller tried to request
 * that no notifications be sent, because the REST JSON schema had no field for it
 * (EGroupware\Tracker\JsTracker::parseJsReply() silently dropped anything it didn't
 * recognize, so the caller also got no error telling them it was ignored).
 *
 * Fixed by adding a "notify" (boolean) field to the reply and ticket JSON schemas,
 * mapped to tracker_bo's existing no_notifications flag and gated by the same
 * field_acl role restriction the classic UI already enforces (only assignees,
 * technicians and admins may suppress notifications).
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @subpackage tests
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once realpath(__DIR__.'/../../notifications/tests/MockedNotifications.php');
require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api\AppTest;
use EGroupware\Notifications\MockedNotifications;

/**
 * @ticket 124831
 */
class ReplyRestNoNotificationsTest extends AppTest
{
	/**
	 * @var \tracker_bo
	 */
	protected $bo;

	protected $tr_id;

	protected $notified;

	/**
	 * Api\Storage\Tracking::send_notification() only calls notification->send()
	 * synchronously when this flag is set - otherwise it defers via
	 * Api\Egw::on_shutdown(), which only ever runs at the very end of the whole
	 * PHPUnit process, long after this test's assertions have already run.
	 */
	protected $restore_async_flag;

	protected function setUp() : void
	{
		$this->bo = new \tracker_bo();

		// Notification fails if user has no email address, so skip rather than false-fail
		$email = $GLOBALS['egw']->accounts->id2name($GLOBALS['egw_info']['user']['account_id'], 'account_email');
		if (!$email)
		{
			$this->markTestSkipped('User account needs email address');
		}

		$this->restore_async_flag = $GLOBALS['egw_info']['flags']['async-service'] ?? null;
		$GLOBALS['egw_info']['flags']['async-service'] = true;

		// Capture every notification recipient
		$this->notified = [];
		$this->bo->tracking = new \tracker_tracking($this->bo, MockedNotifications::class);
		$this->bo->tracking->notify_current_user = true;
		$notified =& $this->notified;
		MockedNotifications::set_callback(function() use (&$notified) {
			foreach ((array)$this->receivers as $receiver)
			{
				$notified[] = is_object($receiver) ? ($receiver->account_email ?? null) : null;
			}
			return true;
		});
	}

	protected function tearDown() : void
	{
		if (isset($this->restore_async_flag))
		{
			$GLOBALS['egw_info']['flags']['async-service'] = $this->restore_async_flag;
		}
		else
		{
			unset($GLOBALS['egw_info']['flags']['async-service']);
		}

		parent::tearDown();

		if ($this->tr_id)
		{
			$this->bo->delete($this->tr_id);
			// Once more for history
			$this->bo->delete($this->tr_id);
		}
		$this->bo = null;
	}

	/**
	 * Create a ticket assigned to the current user (so field_acl's
	 * TRACKER_ITEM_ASSIGNEE right for "no_notifications" is satisfied - same
	 * restriction the classic UI applies) with an external "Kopie" cc address.
	 */
	protected function createAssignedTicketWithCc(string $cc): void
	{
		$this->bo->data['tr_summary']  = 'Ticket #124831 test ('.static::class.')';
		$this->bo->data['tr_status']   = \tracker_bo::STATUS_OPEN;
		$this->bo->data['tr_tracker']  = $this->bo->default_tracker ?: key($this->bo->trackers);
		$this->bo->data['tr_assigned'] = [$this->bo->user];
		$this->bo->save();
		$this->tr_id = $this->bo->data['tr_id'];

		$this->bo->data['tr_cc'] = $cc;
		$this->bo->save();

		// Reset: only interested in what happens once the reply is added
		$this->notified = [];
	}

	/**
	 * Create a ticket the current user only created (not assigned/technician/admin
	 * on) with an external "Kopie" cc address, to exercise the field_acl gate that
	 * should silently ignore "notify" for a caller without the right role.
	 */
	protected function createUnassignedTicketWithCc(string $cc): void
	{
		$this->bo->data['tr_summary'] = 'Ticket #124831 test ('.static::class.')';
		$this->bo->data['tr_status']  = \tracker_bo::STATUS_OPEN;
		$this->bo->data['tr_tracker'] = $this->bo->default_tracker ?: key($this->bo->trackers);
		$this->bo->save();
		$this->tr_id = $this->bo->data['tr_id'];

		if (!empty($this->bo->readonlys_from_acl()['no_notifications']))
		{
			// good, current user has no right to suppress notifications on this ticket
		}
		else
		{
			$this->markTestSkipped('Current test user has assignee/technician/admin rights on this queue - '.
				'cannot exercise the "unauthorized caller" branch');
		}

		$this->bo->data['tr_cc'] = $cc;
		$this->bo->save();

		// Reset: only interested in what happens once the reply is added
		$this->notified = [];
	}

	/**
	 * Mirrors what EGroupware\Tracker\ApiHandler::createReply() does with the
	 * parsed request body, including the field_acl gate on "no_notifications".
	 */
	protected function applyReplyLikeApiHandler(array $parsed): void
	{
		$this->bo->data['reply_message'] = $parsed['reply_message'];
		$this->bo->data['reply_visible'] = $parsed['reply_visible'] ?? 0;

		if (array_key_exists('no_notifications', $parsed) &&
			empty($this->bo->readonlys_from_acl()['no_notifications']))
		{
			$this->bo->data['no_notifications'] = $parsed['no_notifications'];
		}
		$this->bo->save();
	}

	/**
	 * A REST client that sends {"message": "...", "notify": false} on a reply must
	 * not have the external cc address notified.
	 */
	public function testReplyWithNotifyFalseSuppressesExternalCc()
	{
		$cc = 'external-cc-124831@example.invalid';

		$json = json_encode(['message' => 'Reply from REST API client', 'notify' => false]);
		$parsed = JsTracker::parseJsReply($json, [], 'POST');
		$this->assertTrue($parsed['no_notifications'] ?? null,
			'JsTracker::parseJsReply() should map "notify": false to no_notifications=true');

		$this->createAssignedTicketWithCc($cc);
		$this->applyReplyLikeApiHandler($parsed);

		$this->assertNotContains($cc, $this->notified,
			'ticket #124831: external "Kopie" address was notified for a REST-created reply, even though the '.
			'REST client requested no notifications ("notify": false in the reply body)');
	}

	/**
	 * Regression guard: without "notify" in the request, a reply still notifies
	 * the external cc address as before - the fix must not change default behaviour.
	 */
	public function testReplyWithoutNotifyFieldStillNotifiesExternalCc()
	{
		$cc = 'external-cc-124831-default@example.invalid';

		$json = json_encode(['message' => 'Reply from REST API client']);
		$parsed = JsTracker::parseJsReply($json, [], 'POST');
		$this->assertArrayNotHasKey('no_notifications', $parsed,
			'no "notify" field in the request should mean no_notifications is left untouched');

		$this->createAssignedTicketWithCc($cc);
		$this->applyReplyLikeApiHandler($parsed);

		$this->assertContains($cc, $this->notified,
			'default behaviour (no "notify" field) must keep notifying the external "Kopie" address');
	}

	/**
	 * A caller without assignee/technician/admin rights on the ticket cannot use
	 * "notify": false to suppress notifications - same restriction the classic UI
	 * already enforces via field_acl.
	 */
	public function testReplyWithNotifyFalseIgnoredWithoutRights()
	{
		$cc = 'external-cc-124831-unauthorized@example.invalid';

		$json = json_encode(['message' => 'Reply from REST API client', 'notify' => false]);
		$parsed = JsTracker::parseJsReply($json, [], 'POST');

		$this->createUnassignedTicketWithCc($cc);
		$this->applyReplyLikeApiHandler($parsed);

		$this->assertContains($cc, $this->notified,
			'a caller without assignee/technician/admin rights must not be able to suppress notifications '.
			'via "notify": false');
	}

	/**
	 * field_acl is fully admin-configurable per queue (Tracker config -> Field ACL),
	 * not a fixed set of roles - a site that broadened "no_notifications" to
	 * TRACKER_EVERYBODY must let even a plain, unassigned caller suppress
	 * notifications via "notify": false.
	 */
	public function testReplyWithNotifyFalseHonorsFieldAclGrantedToRegularUser()
	{
		$cc = 'external-cc-124831-configured-grant@example.invalid';

		// Simulate an admin having broadened this queue's "no_notifications" field
		// ACL to TRACKER_EVERYBODY via Tracker config
		$this->bo->field_acl['no_notifications'] = TRACKER_EVERYBODY;

		$this->bo->data['tr_summary'] = 'Ticket #124831 test ('.__METHOD__.')';
		$this->bo->data['tr_status']  = \tracker_bo::STATUS_OPEN;
		$this->bo->data['tr_tracker'] = $this->bo->default_tracker ?: key($this->bo->trackers);
		// deliberately NOT assigned to self - only the reconfigured field_acl grants the right
		$this->bo->save();
		$this->tr_id = $this->bo->data['tr_id'];

		$this->assertEmpty($this->bo->readonlys_from_acl()['no_notifications'] ?? null,
			'sanity: TRACKER_EVERYBODY should grant the right regardless of role');

		$this->bo->data['tr_cc'] = $cc;
		$this->bo->save();
		$this->notified = [];

		$json = json_encode(['message' => 'Reply from REST API client', 'notify' => false]);
		$parsed = JsTracker::parseJsReply($json, [], 'POST');
		$this->applyReplyLikeApiHandler($parsed);

		$this->assertNotContains($cc, $this->notified,
			'a site that configured "no_notifications" field ACL to TRACKER_EVERYBODY must let any caller '.
			'suppress notifications');
	}

	/**
	 * The inverse: a site that restricts "no_notifications" to admins only must
	 * ignore "notify": false from a mere assignee, even though the default
	 * field_acl would normally allow assignees.
	 */
	public function testReplyWithNotifyFalseHonorsFieldAclRestrictedToAdminOnly()
	{
		$cc = 'external-cc-124831-configured-restrict@example.invalid';

		// Simulate an admin having restricted this queue's "no_notifications" field
		// ACL to TRACKER_ADMIN only via Tracker config
		$this->bo->field_acl['no_notifications'] = TRACKER_ADMIN;

		$this->bo->data['tr_summary']  = 'Ticket #124831 test ('.__METHOD__.')';
		$this->bo->data['tr_status']   = \tracker_bo::STATUS_OPEN;
		$this->bo->data['tr_tracker']  = $this->bo->default_tracker ?: key($this->bo->trackers);
		$this->bo->data['tr_assigned'] = [$this->bo->user]; // assignee - would normally be enough
		$this->bo->save();
		$this->tr_id = $this->bo->data['tr_id'];

		if (empty($this->bo->readonlys_from_acl()['no_notifications']))
		{
			$this->markTestSkipped('Current test user is a tracker admin - cannot exercise the '.
				'"restricted further" branch');
		}

		$this->bo->data['tr_cc'] = $cc;
		$this->bo->save();
		$this->notified = [];

		$json = json_encode(['message' => 'Reply from REST API client', 'notify' => false]);
		$parsed = JsTracker::parseJsReply($json, [], 'POST');
		$this->applyReplyLikeApiHandler($parsed);

		$this->assertContains($cc, $this->notified,
			'a site that restricted "no_notifications" field ACL to admins only must ignore "notify": false '.
			'from a mere assignee');
	}
}
