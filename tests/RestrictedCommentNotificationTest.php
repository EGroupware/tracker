<?php

/**
 * Tracker test that a restricted comment is only sent to users who can see it
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace Egroupware\Tracker;

require_once realpath(__DIR__.'/../../notifications/tests/MockedNotifications.php');
require_once realpath(__DIR__ . '/../../api/tests/AppTest.php');

use EGroupware\Api\AppTest;
use EGroupware\Notifications\MockedNotifications;

class RestrictedCommentNotificationTest extends AppTest
{
	const RESTRICTED = 'Restricted comment for staff only';
	const CC = 'cc-restricted-test@example.org';
	/**
	 * Neither admin nor technician, but can see restricted comments while assigned
	 */
	const ASSIGNEE = 200;
	const ASSIGNEE_LID = 'technician';
	/**
	 * Neither admin nor technician, creator of the ticket and maybe assigned
	 */
	const OTHER = 201;
	const OTHER_LID = 'reporter';

	/**
	 * @var \tracker_bo
	 */
	protected $bo;

	/**
	 * @var int
	 */
	protected $tr_id;

	/**
	 * Sent notifications: receiver (account_id or email) => html and plain body
	 *
	 * @var string[]
	 */
	public static $sent = [];

	/**
	 * @var mixed
	 */
	protected $async_service;

	protected function setUp() : void
	{
		foreach([self::ASSIGNEE => self::ASSIGNEE_LID, self::OTHER => self::OTHER_LID] as $account_id => $account_lid)
		{
			if ($GLOBALS['egw']->accounts->id2name($account_id) !== $account_lid ||
				!$GLOBALS['egw']->accounts->id2name($account_id, 'account_email'))
			{
				$this->markTestSkipped("Test needs user #$account_id '$account_lid' with an email address");
			}
		}

		// only the current user is tracker staff (admin and technician), regardless of the configuration of this install
		$this->bo = new class extends \tracker_bo
		{
			public function is_admin($tracker, $user=null, $checkGivenUser=false)
			{
				return (int)($user ?? $this->user) === (int)$GLOBALS['egw_info']['user']['account_id'];
			}
			public function is_technician($tracker, $user=null, $checkgroups=false)
			{
				return $this->is_admin($tracker, $user);
			}
		};
		$this->bo->tracking = new \tracker_tracking($this->bo, MockedNotifications::class);

		// send notifications immediately, not on shutdown, so we can check them
		$this->async_service = $GLOBALS['egw_info']['flags']['async-service'] ?? null;
		$GLOBALS['egw_info']['flags']['async-service'] = true;

		static::$sent = [];
		MockedNotifications::set_callback(function()
		{
			foreach($this->receivers as $receiver)
			{
				RestrictedCommentNotificationTest::$sent[$receiver->account_id ?: $receiver->account_email] =
					$this->message_html."\n".$this->message_plain;
			}
			return true;
		});
	}

	protected function tearDown() : void
	{
		if ($this->tr_id)
		{
			$this->bo->delete($this->tr_id);
			// once more for history
			$this->bo->delete($this->tr_id);
		}
		$this->bo = null;

		if (isset($this->async_service))
		{
			$GLOBALS['egw_info']['flags']['async-service'] = $this->async_service;
		}
		else
		{
			unset($GLOBALS['egw_info']['flags']['async-service']);
		}
		parent::tearDown();
	}

	/**
	 * Restricted comment, saved alone or with other changes
	 *
	 * @return array[] label => [assigned before, changes saved with the comment, receivers with comment, receivers without]
	 */
	public static function changesProvider() : array
	{
		return [
			'only a restricted comment' => [
				[self::ASSIGNEE], [],
				[self::ASSIGNEE], [],
			],
			'restricted comment and changed status' => [
				[self::ASSIGNEE], ['tr_status' => \tracker_bo::STATUS_CLOSED],
				[self::ASSIGNEE], [self::OTHER, self::CC],
			],
			'restricted comment and unassigning a user, who can then no longer see it' => [
				[self::ASSIGNEE, self::OTHER], ['tr_assigned' => [self::ASSIGNEE]],
				[self::ASSIGNEE], [self::OTHER, self::CC],
			],
		];
	}

	/**
	 * Only users who can see restricted comments get them in the notification
	 *
	 * Behaviour under test:
	 * - the notification with restricted comments goes only to assignees, tracker admins and technicians,
	 *   not to the creator or CC and not to a user unassigned while the comment was added,
	 *   who used to get the comment, as Api\Storage\Tracking::do_notifications() notifies old assignees too
	 * - everyone else gets the notification without restricted comments, if there are other changes
	 *
	 * Setup strategy:
	 * - test double of tracker_bo makes only the current user staff, which adds the comment
	 * - ticket created by user OTHER, with an earlier public comment and a CC address
	 * - notification bodies are captured per receiver via MockedNotifications
	 *
	 * Pass criteria:
	 * - exactly the expected receivers get a notification, and only the expected ones contain the restricted comment
	 *
	 * @param int[] $assigned assigned before the comment
	 * @param array $changes saved together with the comment
	 * @param array $with receivers of a notification with the restricted comment
	 * @param array $without receivers of a notification without the restricted comment
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('changesProvider')]
	public function testRestrictedCommentOnlyToUsersWhoCanSeeIt(array $assigned, array $changes, array $with, array $without)
	{
		$user = (int)$GLOBALS['egw_info']['user']['account_id'];
		$this->bo->data = [
			'tr_tracker'   => array_key_first($this->bo->trackers),
			'tr_summary'   => 'Test restricted comment notification',
			'tr_status'    => \tracker_bo::STATUS_OPEN,
			'tr_creator'   => self::OTHER,
			'tr_assigned'  => array_merge([$user], $assigned),
			'tr_cc'        => self::CC,
			'tr_edit_mode' => 'ascii',
			'tr_description' => 'Description',
		];
		$this->assertSame(0, $this->bo->save(), 'Creating the ticket failed');
		$this->tr_id = $this->bo->data['tr_id'];

		// earlier public comment
		$this->bo->read($this->tr_id);
		$this->assertSame(0, $this->bo->save(['reply_message' => 'Public comment', 'reply_visible' => 0]),
			'Saving the public comment failed');

		// every save is a new request, which starts with an empty comment count cache
		(new \ReflectionProperty(\tracker_comments::class, 'comment_count_cache'))->setValue(null, []);
		$this->bo->read($this->tr_id);
		static::$sent = [];
		$this->assertSame(0, $this->bo->save(['reply_message' => self::RESTRICTED, 'reply_visible' => 1] + $changes),
			'Saving the restricted comment failed');

		$received = ['with' => [], 'without' => []];
		foreach(static::$sent as $receiver => $body)
		{
			$received[str_contains($body, self::RESTRICTED) ? 'with' : 'without'][] = (string)$receiver;
		}
		$expected = ['with' => array_map('strval', $with), 'without' => array_map('strval', $without)];
		foreach(['with', 'without'] as $type)
		{
			sort($expected[$type]);
			sort($received[$type]);
		}
		$this->assertEquals($expected, $received, 'Receivers of the notifications with and without the restricted comment');
	}
}
