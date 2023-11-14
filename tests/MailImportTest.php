<?php


/**
 * Tracker test for matching mail subjects with existing tickets
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package tracker
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace Egroupware\Tracker;

require_once realpath(__DIR__ . '/../../api/tests/AppTest.php');    // Application test base

use EGroupware\Api\AppTest;

/**
 * Check import from mail
 *
 */
class MailImportTest extends AppTest
{

	protected static $accounts = [
		[
			'account_lid'       => 'user_test',
			'account_firstname' => 'Test User',
			'account_lastname'  => 'Not Tech',
			'email'             => 'not_tech@example.com'
		],
		[
			'account_lid'       => 'user_tech',
			'account_firstname' => 'Test User',
			'account_lastname'  => 'Tracker Tech',
			'email'             => 'tracker_tech@example.com'
		]
	];
	protected static $account_ids = [];
	protected static $tech_account;

	protected static $contacts = [
		[
			'n_fn'  => 'Known Contact',
			'email' => 'known_email@example.com'
		]
	];
	protected static $contact_ids = [];

	protected static $bo;

	protected static $original_addressbook_prefs = [];

	/**
	 * Create the tickets to match against
	 */
	public static function setUpBeforeClass() : void
	{
		parent::setUpBeforeClass();

		static::$original_addressbook_prefs = $GLOBALS['egw_info']['user']['preferences']['addressbook'];
		$GLOBALS['egw_info']['user']['preferences']['addressbook']['private_addressbook'] = false;

		self::$bo = new \tracker_bo();
		$contact_bo = new \addressbook_bo();

		foreach(self::$accounts as $account)
		{
			$command = new \admin_cmd_edit_user(false, $account);
			$command->comment = 'Needed for unit test ' . __CLASS__;
			$command->run();
			self::$account_ids[] = $command->account;
			$contact = $contact_bo->read('account:' . $command->account);
			$contact = array_merge($contact, $account);
			$contact_bo->save($contact, true);
		}
		// Set the last one as tracker tech
		self::$bo->users[0][] = static::$tech_account = $command->account;

		foreach(self::$contacts as $contact)
		{
			$existing = $contact_bo->search($contact);
			foreach($existing as $e)
			{
				$contact_bo->delete($e);
			}
			self::$contact_ids[] = $contact_bo->save($contact);
		}
	}

	public static function tearDownAfterClass() : void
	{
		foreach(self::$account_ids as $account_id)
		{
			$GLOBALS['egw']->accounts->delete($account_id);
		}
		$contact_bo = new \addressbook_bo();
		$contact_bo->delete(self::$contact_ids);
	}

	/**
	 * Test creator is correctly determined from mail address if mail is from staff with tracker access
	 */
	public function testCreatorFromAccountMail()
	{
		$content = self::$bo->prepare_import_mail(
			[
				['email' => 'tracker_tech@example.com', 'name' => 'Sending user'],
				['email' => "known_email@example.com", 'name' => 'known contact'],
				['email' => 'extra@example.com', 'name' => 'extra email, who knows']
			],
			'Email subject - no number',
			'Hey, the thing is broken again',
			[],
			null
		);

		// Sent from tech user -> owned by sending account
		$this->assertEquals(
			$content['tr_creator'],
			static::$tech_account,
			'Ticket from user with access should be owned by that user'
		);
	}

	/**
	 * Test creator is correctly determined from mail address if mail is from staff without tracker access
	 */
	public function testCreatorNonTechUser()
	{
		$content = self::$bo->prepare_import_mail(
			[
				['email' => 'not_tech@example.com', 'name' => 'Sending user'],
				['email' => "known_email@example.com", 'name' => 'known contact'],
				['email' => 'extra@example.com', 'name' => 'extra email, who knows']
			],
			'Test ticket emailed from user who is not a tracker tech',
			'Hey, the thing is broken again',
			[],
			null
		);
		// Sent from non-tech user -> owned by current user
		$this->assertEquals(
			$content['tr_creator'],
			$GLOBALS['egw_info']['user']['account_id'],
			'Ticket from no access user should be owned by current user'
		);

	}

	/**
	 * Test creator is correctly determined from mail address if mail is from a known contact
	 */
	public function testCreatorContact()
	{
		$content = self::$bo->prepare_import_mail(
			[
				['email' => "known_email@example.com", 'name' => 'known contact'],
				['email' => 'extra@example.com', 'name' => 'extra email, who knows']
			],
			'Test ticket emailed from user who is not a tracker tech',
			'Hey, the thing is broken again',
			[],
			null
		);
		// Sent from non-tech user -> owned by current user
		$this->assertEquals(
			$content['tr_creator'],
			$GLOBALS['egw_info']['user']['account_id'],
			'Ticket from contact should be owned by current user'
		);

	}
}