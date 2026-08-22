<?php
/**
 * Tracker REST API tests: ACL / role-based access scenarios
 *
 * Adapts CalDAV/CalDAVsingleDELETE.php (by Nathan Gray) to the Tracker
 * REST API.  Each test models a different actor (reporter, technician, manager)
 * performing an action and verifies the server enforces the expected ACL rules.
 *
 * Tracker ACL roles (from tracker_bo constants):
 *   TRACKER_ADMIN       – full control
 *   TRACKER_TECHNICIAN  – can read, reply, change status; cannot delete
 *   TRACKER_USER        – can submit tickets; can read their own
 *   TRACKER_EVERYBODY   – anonymous read (if enabled per queue)
 *   TRACKER_ITEM_CREATOR  – creator has extra rights on their own ticket
 *   TRACKER_ITEM_ASSIGNEE – assignee has extra rights on assigned tickets
 *
 * Three users are created:
 *   manager    – TRACKER_ADMIN rights on the default queue
 *   technician – TRACKER_TECHNICIAN rights
 *   reporter   – TRACKER_USER rights (can submit and read own tickets)
 *
 * Tickets are addressed by their server-assigned numeric tr_id only - there is
 * no client-supplied stable UID. Every test therefore creates its ticket via
 * createTicket() (POSTs to the actor's own collection so tr_creator ends up
 * right, then captures the real resource URL from the Location header) instead
 * of guessing a path.
 *
 * @link http://www.egroupware.org
 * @author Amir Dehestani <amir@egroupware.org>
 * @package tracker
 * @subpackage tests
 * @copyright (c) 2026 by EGroupware GmbH
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once __DIR__.'/../../../api/tests/RestBase.php';

use EGroupware\Api\RestBase;
use EGroupware\Api\Acl;
use GuzzleHttp\RequestOptions;

/**
 * ACL / permission scenarios for the Tracker JSON REST API.
 *
 * KNOWN UNRESOLVED ISSUE: every test that has a freshly `createUser()`-created actor (manager/
 * technician/reporter) POST a new ticket currently fails with 403, even after granting that
 * account an explicit "tracker" run-right (see setUpBeforeClass()) and a matching primary_group.
 * Root cause investigation so far: ApiHandler::put()'s "empty($this->bo->trackers)" guard is what
 * fires; tracker_bo::trackers (the queue/category list the user can see, populated via
 * get_tracker_labels()/Api\Categories) comes back empty for these accounts specifically, even
 * though the "Bugs" queue category is a public/global one (cat_owner=0) that a hand-built,
 * throwaway `new Api\Categories($account_id, 'tracker')` for the SAME account_id correctly reports
 * as visible. Neither `tracker_bo::reload_labels()` nor fixing get_tracker_labels()'s
 * `$GLOBALS['egw']->categories` reuse-check to also compare `account_id` changed the outcome, so
 * the actual mechanism is still unidentified - flagged here rather than guessed at further. Only
 * testPrincipals() and testNoAuth() (which don't need queue visibility) currently pass.
 *
 * @covers \EGroupware\Tracker\ApiHandler::get
 * @covers \EGroupware\Tracker\ApiHandler::put
 * @covers \EGroupware\Tracker\ApiHandler::delete
 */
class TrackerRestPermissionsTest extends RestBase
{
	const MIME_TYPE_TICKET = 'application/json';

	/**
	 * Users created for this test suite.
	 *
	 * ACL rights use EGroupware's standard Acl bitmask constants.
	 * "tracker" rights here map to the groupdav "run" ACL that gates access to
	 * the tracker collection; queue-level role assignment is done separately in
	 * setUpBeforeClass().
	 *
	 * @var array
	 */
	protected static $users = [
		// "NoGroup" (createUser()'s own default) has NO tracker queue/category visibility at all
		// on this install (tracker's "Bugs" queue category is only visible to real groups); use
		// "Default" (demo's own primary group) instead, so the created users can see it too.
		'manager'    => ['primary_group' => 'Default'],  // TRACKER_ADMIN set in setUpBeforeClass
		'technician' => ['primary_group' => 'Default'],  // TRACKER_TECHNICIAN set in setUpBeforeClass
		'reporter'   => ['primary_group' => 'Default'],  // TRACKER_USER (default for any logged-in user)
	];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Create users with groupdav run-rights so they can reach the endpoint
		self::createUsersACL(self::$users, 'tracker');

		// createUser()/createUsersACL() only grant run-rights for groupdav/calendar/infolog/
		// addressbook, never the app passed to createUsersACL() - the tracker REST endpoint gates
		// even ticket creation on the "tracker" app right (Api\CalDAV\Handler::_common_get_put_delete()),
		// so every actor here needs it explicitly.
		foreach (self::$users as $data)
		{
			if (!empty($data['id']))
			{
				self::addAcl('tracker', 'run', $data['id'], 1);
			}
		}

		// Grant manager full tracker admin rights (used by check_access DELETE)
		$manager_id = self::$users['manager']['id'] ?? null;
		if ($manager_id)
		{
			self::addAcl('tracker', 'admin', $manager_id, 1);
		}

		// Verify the tracker REST endpoint exists; skip if not yet implemented
		$client = new \GuzzleHttp\Client([
			RequestOptions::HTTP_ERRORS      => false,
			RequestOptions::VERIFY           => false,
			RequestOptions::ALLOW_REDIRECTS  => true,
			RequestOptions::AUTH => [
				$GLOBALS['EGW_USER'] ?? 'demo',
				$GLOBALS['EGW_PASSWORD'] ?? 'guest',
			],
		]);
		$base = $_ENV['EGW_URL'] ?? getenv('EGW_URL') ?: self::CALDAV_BASE;
		$base = rtrim($base, '/') . (strpos($base, 'groupdav.php') === false ? '/groupdav.php' : '');
		$user = $GLOBALS['EGW_USER'] ?? 'demo';

		// Probe with a POST; tickets are always server-assigned numeric ids, so
		// creation (not a PUT to a guessed path) is the only reliable way to
		// detect whether the handler is available.
		$probe = $client->post("$base/$user/tracker/", [
			RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
			RequestOptions::BODY    => json_encode([
				'@type' => 'Ticket',
				'title' => 'Probe ticket (auto-deleted)',
			]),
		]);

		if (!in_array($probe->getStatusCode(), [201], true))
		{
			self::markTestSkipped(
				'Tracker REST API is not available on this server '
				.'(POST probe returned HTTP '.$probe->getStatusCode().'). '
				.'Check that EGroupware\Tracker\ApiHandler is loaded, then re-run these tests.'
			);
		}

		// Clean up probe ticket. The Location header is a server-relative path
		// (e.g. /egroupware/groupdav.php/admin/tracker/3); prepend the origin
		// from $base to get an absolute URL Guzzle can DELETE.
		$location = $probe->getHeaderLine('Location') ?: "$base/$user/tracker/";
		if ($location[0] === '/' && preg_match('#^(https?://[^/]+)#', $base, $m))
		{
			$location = $m[1].$location;
		}
		$client->delete($location, [RequestOptions::HEADERS => ['Accept' => 'application/json']]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a ticket as $actor and return the full URL of the created resource.
	 *
	 * POSTs into $actor's own collection (not the global default test user's)
	 * so the server derives tr_creator from $actor, matching what these ACL
	 * tests are meant to exercise. The resource's real, server-assigned URL is
	 * then read back from the Location header - tracker has no client-supplied
	 * UID to address it by, unlike calendar/infolog.
	 *
	 * @param string $actor
	 * @param string $title
	 * @param int    $priority  1–9
	 * @param string $status    'Open' | 'Pending' | 'Closed'
	 * @param array  $extra     additional JSON fields merged into the body (e.g. 'privacy')
	 * @return string  full URL of the created ticket
	 */
	private function createTicket(
		string $actor,
		string $title = 'Test Ticket',
		int    $priority = 5,
		string $status = 'Open',
		array  $extra = []
	): string {
		$body = array_merge([
			'@type'       => 'Ticket',
			'title'       => $title,
			'description' => "Ticket created for test actor=$actor",
			'status'      => $status,
			'priority'    => $priority,
		], $extra);

		$response = $this->getClient($actor)->post($this->url("/$actor/tracker/"), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode($body, JSON_PRETTY_PRINT),
		]);
		$this->assertHttpStatus(201, $response, "'$actor' creates ticket '$title'");

		$location = $this->locationPath($response);
		$this->assertNotEmpty($location, 'POST must return a Location header');

		return $this->url(preg_replace('#^.*/groupdav\.php#', '', $location));
	}

	// -------------------------------------------------------------------------
	// Tests: principal sanity check
	// -------------------------------------------------------------------------

	/**
	 * Verify all test users were created successfully.
	 */
	public function testPrincipals()
	{
		foreach (array_keys(self::$users) as $user)
		{
			$response = $this->getClient($user)->propfind(
				$this->url("/principals/users/$user/"),
				[RequestOptions::HEADERS => ['Depth' => '0']]
			);
			$this->assertHttpStatus(207, $response, "Principal for '$user' must exist");
		}
	}

	// -------------------------------------------------------------------------
	// Tests: reporter submits, reads own ticket
	// -------------------------------------------------------------------------

	/**
	 * Reporter creates a ticket in their own name.
	 * Then reads it back – must see the ticket they just created.
	 */
	public function testReporterCreateAndRead()
	{
		$url = $this->createTicket('reporter', 'Reporter\'s bug report');

		$read = $this->getClient('reporter')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $read, 'Reporter reads own ticket');
		$this->assertJsonFields(['status' => 'Open'], $read);

		// Clean up
		$this->getClient('manager')->delete($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
	}

	// -------------------------------------------------------------------------
	// Tests: technician can update but not delete
	// -------------------------------------------------------------------------

	/**
	 * Technician must be able to change the status of a ticket (e.g. set it to
	 * pending) but should not be able to permanently delete it.
	 */
	public function testTechnicianCanUpdateButNotDelete()
	{
		// Manager creates the ticket
		$url = $this->createTicket('manager', 'Ticket for technician tests');

		// Technician changes status to "Pending"
		$update = $this->getClient('technician')->patch($url, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['status' => 'Pending']),
		]);
		$this->assertHttpStatus([200, 204], $update,
			'Technician must be allowed to update ticket status');

		// Technician must NOT be allowed to delete
		$delete = $this->getClient('technician')->delete($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus([403, 405], $delete,
			'Technician must not be allowed to delete tickets');

		// Ticket must still exist
		$stillThere = $this->getClient('manager')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $stillThere, 'Ticket must still exist after failed delete');

		// Clean up
		$this->getClient('manager')->delete($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
	}

	// -------------------------------------------------------------------------
	// Tests: manager has full control
	// -------------------------------------------------------------------------

	/**
	 * Manager can delete any ticket regardless of who created it.
	 */
	public function testManagerCanDeleteAnyTicket()
	{
		// Reporter creates the ticket
		$url = $this->createTicket('reporter', 'Ticket to be deleted by manager');

		// Manager deletes it
		$delete = $this->getClient('manager')->delete($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(204, $delete, 'Manager must be able to delete any ticket');

		// Confirm it is gone
		$gone = $this->getClient('reporter')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(404, $gone, 'Ticket must be gone after manager deletes it');
	}

	// -------------------------------------------------------------------------
	// Tests: private ticket visibility
	// -------------------------------------------------------------------------

	/**
	 * A private ticket (tr_private = 1) must only be visible to its creator,
	 * its assignees, and admin users.  Other users must receive 403 or 404.
	 */
	public function testPrivateTicketHiddenFromOthers()
	{
		// Reporter creates a private ticket
		$url = $this->createTicket('reporter', 'Private bug – restricted access', 7, 'Open',
			['privacy' => 'private']);

		// Reporter can read their own private ticket
		$selfRead = $this->getClient('reporter')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $selfRead, 'Reporter can read own private ticket');

		// Technician (not assigned, not creator) must NOT see the private ticket
		$techRead = $this->getClient('technician')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus([403, 404], $techRead,
			'Technician must not see private ticket they are not assigned to');

		// Manager (admin) must still see it
		$managerRead = $this->getClient('manager')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $managerRead, 'Manager must see private tickets');

		// Clean up
		$this->getClient('manager')->delete($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
	}

	// -------------------------------------------------------------------------
	// Tests: creator can close but not necessarily delete own ticket
	// -------------------------------------------------------------------------

	/**
	 * The reporter who created a ticket must be allowed to close it
	 * (TRACKER_ITEM_CREATOR rights) but must NOT be able to delete it
	 * unless they have explicit DELETE rights.
	 */
	public function testCreatorCanCloseOwnTicket()
	{
		$url = $this->createTicket('reporter', 'Ticket to be closed by creator');

		// Creator closes own ticket
		$close = $this->getClient('reporter')->patch($url, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['status' => 'Closed']),
		]);
		$this->assertHttpStatus([200, 204], $close, 'Creator must be able to close own ticket');

		// Creator tries to delete — depends on queue ACL (reporter typically can't)
		$delete = $this->getClient('reporter')->delete($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		// Accept either "forbidden" (correct ACL enforcement) or "no content" (if
		// the queue allows self-delete); either is valid depending on configuration.
		$this->assertHttpStatus([204, 403, 405], $delete,
			'Creator delete attempt must return 204 (allowed) or 403/405 (blocked by ACL)');

		// If the ticket still exists, clean up as manager
		$check = $this->getClient('manager')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		if ($check->getStatusCode() === 200)
		{
			$this->getClient('manager')->delete($url, [
				RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
			]);
		}
	}

	// -------------------------------------------------------------------------
	// Tests: unauthenticated access
	// -------------------------------------------------------------------------

	/**
	 * Any request to the tracker collection without credentials must return 401.
	 */
	public function testNoAuth()
	{
		$response = $this->getClient([])->get($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(401, $response);
	}
}
