<?php

/**
 * Tracker test for making sure HTML tickets send HTML bodies and text tickets
 * send text bodies, but also taking into account that the setting for HTML editing
 * can be changed at any time.
 *
 * Tracking system actually sends both HTML & plain-text for the body every time,
 * but we don't want HTML to be stripped & forced into plain-text and we don't
 * want to send plain-text that has encoded HTML entities.
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package tracker
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace Egroupware\Tracker;

require_once realpath(__DIR__.'/../../notifications/tests/MockedNotifications.php');

use EGroupware\Notifications\MockedNotifications;
use EGroupware\Api\Config;

class NotificationBodyTest extends \EGroupware\Api\AppTest
{

	/**
	 * Original HTML editing configuration
	 */
	protected static $edit_mode;

	/**
	 * Original notification preference
	 */
	protected static $self_notify;

	/**
	 * Keep track of the tracker ID so even if the test fails, we can remove it
	 * in tearDown()
	 *
	 * @var tr_id
	 */
	protected $tr_id;

	public static function setUpBeforeClass() : void
	{
		// Test works on its own with this, but fails with the rest.
		// There's no good reason commenting this out should work.
		//parent::setUpBeforeClass();

		// Change configuration
		$config = Config::read('tracker');
		static::$edit_mode = $config['htmledit'];

		// Need to turn on self notifications
		static::$self_notify = $GLOBALS['egw_info']['user']['preferences']['tracker']['notify_own_modification'];
		$GLOBALS['egw']->preferences->add('tracker','notify_own_modification', true);
		$GLOBALS['egw']->preferences->add('tracker','notify_creator', true);
		$GLOBALS['egw_info']['user']['preferences']['tracker']['notify_own_modification'] = true;
		$GLOBALS['egw_info']['user']['preferences']['tracker']['notify_creator'] = true;
	}
	public static function tearDownAfterClass() : void
	{
		Config::save_value('htmledit', static::$edit_mode, 'tracker', true);
		$GLOBALS['egw']->preferences->add('tracker','notify_own_modification', static::$self_notify);
		$GLOBALS['egw_info']['user']['preferences']['tracker']['notify_own_modification'] = static::$self_notify;

		parent::tearDownAfterClass();
	}

	protected function setUp() : void
	{
		$this->bo = new \tracker_bo();

		// Notification fails if user has no email address, so try to add one
		$email = $GLOBALS['egw']->accounts->id2name($GLOBALS['egw']->accounts,'account_email');
		if(!$email && $GLOBALS['egw_info']['user']['person_id'])
		{
			$account = array(
				'id' => $GLOBALS['egw_info']['user']['person_id'],
				'email' => 'demo@example.org'
			);
			$GLOBALS['egw']->contacts->save($account, true);
			\EGroupware\Api\Accounts::cache_invalidate($GLOBALS['egw_info']['user']['account_id']);
		}
		$email = $GLOBALS['egw']->accounts->id2name($GLOBALS['egw']->accounts,'account_email');
		if(!$email)
		{
			$this->markTestSkipped('User account needs email address');
		}
	}

	protected function tearDown() : void
	{
		parent::tearDown();

		// Clean up
		$this->bo->delete($this->tr_id);
		// Once more for history
		$this->bo->delete($this->tr_id);

		$this->bo = null;
	}

	protected function assertPreConditions() : void
	{
		// Make sure we can change the fields needed to trigger the notification
		$readonlys = $this->bo->readonlys_from_acl();
		$this->assertFalse((boolval(in_array('tr_duedate',$readonlys) && $readonlys['tr_duedate'])));

		// User account requires an email or we don't do any notifications
		$email = $GLOBALS['egw']->accounts->id2name($GLOBALS['egw_info']['user']['account_id'],'account_email');
		$this->assertInternalType('string', $email, 'User account needs email address');
	}

	/**
	 * Test the notifications with the HTML Edit site configuration setting
	 * turned off.
	 *
	 * @param Boolean $mode_config Setting for the Site Configuration HTML editing
	 * @param String $mode Notification type, 'ascii' or 'html'
	 * @param Array $ticket Array of ticket data for this test
	 * @param String $expected Expected ticket description [fragment]
	 *
	 * @ticket 24188
	 * @dataProvider ticketProvider
	 */
	public function testNotificationsForDescriptionWithHTMLAndText($mode_config, $mode, $ticket, $expected)
	{
		// Set the config
		Config::save_value('htmledit', $mode_config, 'tracker', true);

		// Set up crazy static callback
		$test = $this;
		$callback = function() use ($test, $mode, $expected) {
			switch ($mode)
			{
				case 'ascii':
					$message = "message_plain";
					break;
				default:
					$message = "message_{$mode}";
					break;
			}
			$test->assertNotEmpty($this->$message, 'Notification message missing');

			$test->assertContains($expected,$this->$message);
			return true;
		};
		MockedNotifications::set_callback($callback);
		$this->bo->tracking = new \tracker_tracking($this->bo, MockedNotifications::class);

		// Turn this on, or current user won't get notification
		$this->bo->tracking->notify_current_user = true;

		// Initial save - no notification
		$this->bo->data = $ticket;
		$this->bo->save();

		$tr_id = $this->bo->data['tr_id'];

		// Change completion triggers notification
		$this->bo->save(array('tr_duedate' => time()));
	}

	/**
	 * Generate minimum required data for tickets with various combinations of
	 * text & html edit settings, and text & HTML descriptions.
	 */
	public static function ticketProvider()
	{
		// This stuff is common to every ticket
		$base = array(
			'tr_summary'     => 'Test tracker ',
			'tr_status'      => \tracker_bo::STATUS_OPEN,
			'tr_creator'     => $GLOBALS['egw_info']['user']['account_id'],
		);

		// Different for each, expectation is for the different edit mode *on the ticket*
		$descriptions = array(
			'text' => array(
				'original' => "Test element for test\nText description, text mode",
				// Expectation
				'ascii'    => "Test element for test\nText description, text mode",
				'html'     => "Test element for test<br />\nText description, text mode",
			),
			'html' => array(
				'original' => '<b>​Testing html in comments.<br />↵<ul>This is HTML in a description</ul></b>',
				// Expectation
				'ascii'    => '<b>​Testing html in comments.<br />↵<ul>This is HTML in a description</ul></b>',
				'html'     => '<b>​Testing html in comments.<br />↵<ul>This is HTML in a description</ul></b>'
			),
			'malicious' => array(
				'original' => 'l33t <script>kiddy</script>',

				// Expectation
				'ascii'    => 'l33t <script>kiddy</script>',
				'html'     => 'l33t '
			),
			'tricky (#24188)' => array(
				// If you type stuff, it gets escaped by UI
				'original' => 'see assigned test(s)

#4 &lt;signal handler called&gt;',

				// Expectation
				'ascii'    => 'see assigned test(s)

#4 <signal handler called>',
				'html'     => 'see assigned test(s)<br />
<br />
#4 &lt;signal handler called&gt;'
			)
		);

		$modes = array('ascii','html');

		$tickets = array();
		foreach ($descriptions as $type => $ticket)
		{
			foreach($modes as $mode)
			{
				$new = $base + array('tr_description' => $ticket['original']) + array(
					'tr_edit_mode' => $mode,
				);
				$new['tr_summary'] .= " (Desc: $type, Edit mode: $mode)";

				// Add once with HTML edit on, once with it off though it should not
				// have any effect - tr_edit_mode should be what matters
				$tickets[] = Array(true, $mode, $new, $ticket[$mode]);
				$tickets[] = Array(false, $mode, $new, $ticket[$mode]);
			}
		}

		return $tickets;
	}
}