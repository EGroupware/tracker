<?php

/**
 * Tracker test for marking the comment just added as new in the notification
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

class NewCommentNotificationTest extends AppTest
{
	/**
	 * @var \tracker_bo
	 */
	protected $bo;

	/**
	 * Keep track of the ticket, so we can remove it in tearDown(), even if the test fails
	 *
	 * @var int
	 */
	protected $tr_id;

	/**
	 * Html bodies of the notifications sent to the current user
	 *
	 * @var string[]
	 */
	public static $sent = [];

	/**
	 * Original async-service flag
	 *
	 * @var mixed
	 */
	protected $async_service;

	protected function setUp() : void
	{
		$this->bo = new \tracker_bo();

		if (!$GLOBALS['egw']->accounts->id2name($GLOBALS['egw_info']['user']['account_id'], 'account_email'))
		{
			$this->markTestSkipped('User account needs email address');
		}
		// send notifications immediately, not on shutdown, so we can check them
		$this->async_service = $GLOBALS['egw_info']['flags']['async-service'] ?? null;
		$GLOBALS['egw_info']['flags']['async-service'] = true;

		static::$sent = [];
		$user = (int)$GLOBALS['egw_info']['user']['account_id'];
		MockedNotifications::set_callback(function() use ($user)
		{
			foreach($this->receivers as $receiver)
			{
				if ((int)$receiver->account_id === $user)
				{
					NewCommentNotificationTest::$sent[] = $this->message_html;
				}
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
	 * A comment, restricted or not, is listed under "What changed" and marked as new
	 *
	 * Behaviour under test:
	 * - the notification about a new comment marks that comment as new,
	 *   also if it is a restricted comment, which used to give a notification without any changes.
	 *
	 * Setup strategy:
	 * - ticket assigned to the current user, so it gets the notification with restricted comments
	 * - one public and one restricted comment, each saved without other changes
	 * - notification html is captured via MockedNotifications
	 *
	 * Pass criteria:
	 * - "What changed" lists the comment just added and only that comment is marked new
	 */
	public function testNewCommentIsMarkedNew()
	{
		$this->bo->tracking = new \tracker_tracking($this->bo, MockedNotifications::class);
		$this->bo->tracking->notify_current_user = true;

		$user = $GLOBALS['egw_info']['user']['account_id'];
		$this->bo->data = [
			'tr_tracker'   => array_key_first($this->bo->trackers),
			'tr_summary'   => 'Test new comment notification',
			'tr_status'    => \tracker_bo::STATUS_OPEN,
			'tr_creator'   => $user,
			'tr_assigned'  => [$user],
			'tr_edit_mode' => 'ascii',
			'tr_description' => 'Description',
		];
		$this->assertSame(0, $this->bo->save(), 'Creating the ticket failed');
		$this->tr_id = $this->bo->data['tr_id'];

		foreach([
			0 => 'Public comment for new comment test',
			1 => 'Restricted comment for new comment test',
		] as $visible => $comment)
		{
			// like editing the ticket, which sets eg. see_restricted_replies
			$this->bo->read($this->tr_id);
			static::$sent = [];
			$this->assertSame(0, $this->bo->save(['reply_message' => $comment, 'reply_visible' => $visible]),
				"Saving comment '$comment' failed");

			$this->assertCount(1, static::$sent, "Expected one notification for comment '$comment'");
			$html = static::$sent[0];

			$this->assertStringContainsString(lang('What changed'), $html, "No 'What changed' for comment '$comment'");
			$changes = substr($html, strpos($html, lang('What changed')));
			$changes = substr($changes, 0, strpos($changes, 'egwNotificationButton'));
			$this->assertStringContainsString($comment, $changes, "Comment '$comment' not listed under 'What changed'");

			// the new comment is marked twice: under "What changed" and in the list of all comments
			$this->assertSame(2, substr_count($html, 'egwNotificationComment egwNotificationNew'),
				"Not only comment '$comment' is marked as new");
		}
	}
}
