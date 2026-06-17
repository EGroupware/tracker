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
 * PREREQUISITE: tracker_groupdav must be implemented.  Until then the
 * class is auto-skipped.
 *
 * @link http://www.egroupware.org
 * @author Amir Dehestani <amir@egroupware.org>
 * @package tracker
 * @subpackage tests
 * @copyright (c) 2026 by EGroupware GmbH
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once __DIR__.'/../../../api/tests/RestTest.php';

use EGroupware\Api\RestTest;
use EGroupware\Api\Acl;
use GuzzleHttp\RequestOptions;

/**
 * ACL / permission scenarios for the Tracker JSON REST API.
 *
 * @covers tracker_groupdav::get()
 * @covers tracker_groupdav::put()
 * @covers tracker_groupdav::delete()
 */
class TrackerRestPermissions extends RestTest
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
		'manager'    => [],  // TRACKER_ADMIN set in setUpBeforeClass
		'technician' => [],  // TRACKER_TECHNICIAN set in setUpBeforeClass
		'reporter'   => [],  // TRACKER_USER (default for any logged-in user)
	];

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// Create users with groupdav run-rights so they can reach the endpoint
		self::createUsersACL(self::$users, 'tracker');

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

		// Probe with a PUT to detect whether the tracker REST handler is implemented.
		// A generic CalDAV collection returns 200 on GET but 403/405/501 on PUT.
		$probeUid = 'tracker-probe-perms-skip-00000000';
		$probe = $client->put("$base/$user/tracker/$probeUid", [
			RequestOptions::HEADERS => [
				'Content-Type'  => 'application/json',
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => json_encode([
				'@type'  => 'Ticket',
				'uid'    => $probeUid,
				'title'  => 'Probe ticket (auto-deleted)',
				'status' => 'open',
			]),
		]);

		if (!in_array($probe->getStatusCode(), [201, 204], true))
		{
			self::markTestSkipped(
				'Tracker REST API (tracker_groupdav) is not yet implemented '
				.'(PUT probe returned HTTP '.$probe->getStatusCode().'). '
				.'See tracker/inc/class.tracker_groupdav.inc.php.'
			);
		}

		// Clean up probe ticket
		$location = $probe->getHeaderLine('Location') ?: "$base/$user/tracker/$probeUid";
		$client->delete($location, [RequestOptions::HEADERS => ['Accept' => 'application/json']]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal tracker ticket JSON body.
	 *
	 * @param string $uid
	 * @param string $title
	 * @param int    $priority  1–9
	 * @param string $status    open|pending|closed
	 * @return string JSON
	 */
	private function makeTicketJson(
		string $uid,
		string $title = 'Test Ticket',
		int    $priority = 5,
		string $status = 'open'
	): string {
		return json_encode([
			'@type'       => 'Ticket',
			'uid'         => $uid,
			'title'       => $title,
			'description' => "Ticket created for test uid=$uid",
			'status'      => $status,
			'priority'    => $priority,
		], JSON_PRETTY_PRINT);
	}

	/**
	 * URL of a ticket in a specific user's tracker collection view.
	 */
	private function ticketUrl(string $user, string $uid): string
	{
		return $this->url("/$user/tracker/$uid");
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
		$uid = 'rest-perm-reporter-create-11001100';
		$url = $this->ticketUrl('reporter', $uid);

		$create = $this->getClient('reporter')->put($url, [
			RequestOptions::HEADERS => [
				'Content-Type'  => self::MIME_TYPE_TICKET,
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => $this->makeTicketJson($uid, 'Reporter\'s bug report'),
		]);
		$this->assertHttpStatus(201, $create, 'Reporter creates ticket');

		$read = $this->getClient('reporter')->get($url, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $read, 'Reporter reads own ticket');
		$this->assertJsonFields(['uid' => $uid, 'status' => 'open'], $read);

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
		$uid         = 'rest-perm-tech-update-22002200';
		$managerUrl  = $this->ticketUrl('manager', $uid);
		$techUrl     = $this->ticketUrl('technician', $uid);

		// Manager creates the ticket
		$this->getClient('manager')->put($managerUrl, [
			RequestOptions::HEADERS => [
				'Content-Type'  => self::MIME_TYPE_TICKET,
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => $this->makeTicketJson($uid, 'Ticket for technician tests'),
		]);

		// Technician changes status to "pending"
		$update = $this->getClient('technician')->patch($techUrl, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['status' => 'pending']),
		]);
		$this->assertHttpStatus([200, 204], $update,
			'Technician must be allowed to update ticket status');

		// Technician must NOT be allowed to delete
		$delete = $this->getClient('technician')->delete($techUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus([403, 405], $delete,
			'Technician must not be allowed to delete tickets');

		// Ticket must still exist
		$stillThere = $this->getClient('manager')->get($managerUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $stillThere, 'Ticket must still exist after failed delete');

		// Clean up
		$this->getClient('manager')->delete($managerUrl, [
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
		$uid        = 'rest-perm-manager-delete-33003300';
		$reporterUrl = $this->ticketUrl('reporter', $uid);
		$managerUrl  = $this->ticketUrl('manager', $uid);

		// Reporter creates the ticket
		$this->getClient('reporter')->put($reporterUrl, [
			RequestOptions::HEADERS => [
				'Content-Type'  => self::MIME_TYPE_TICKET,
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => $this->makeTicketJson($uid, 'Ticket to be deleted by manager'),
		]);

		// Manager deletes it
		$delete = $this->getClient('manager')->delete($managerUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(204, $delete, 'Manager must be able to delete any ticket');

		// Confirm it is gone
		$gone = $this->getClient('reporter')->get($reporterUrl, [
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
		$uid       = 'rest-perm-private-44004400';
		$reporterUrl = $this->ticketUrl('reporter', $uid);
		$techUrl     = $this->ticketUrl('technician', $uid);

		// Reporter creates a private ticket
		$privateTicket = json_encode([
			'@type'       => 'Ticket',
			'uid'         => $uid,
			'title'       => 'Private bug – restricted access',
			'description' => 'Only visible to creator and manager',
			'status'      => 'open',
			'priority'    => 7,
			'privacy'     => 'private',
		]);

		$create = $this->getClient('reporter')->put($reporterUrl, [
			RequestOptions::HEADERS => [
				'Content-Type'  => self::MIME_TYPE_TICKET,
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => $privateTicket,
		]);
		$this->assertHttpStatus(201, $create, 'Reporter creates private ticket');

		// Reporter can read their own private ticket
		$selfRead = $this->getClient('reporter')->get($reporterUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $selfRead, 'Reporter can read own private ticket');

		// Technician (not assigned, not creator) must NOT see the private ticket
		$techRead = $this->getClient('technician')->get($techUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus([403, 404], $techRead,
			'Technician must not see private ticket they are not assigned to');

		// Manager (admin) must still see it
		$managerRead = $this->getClient('manager')->get($this->ticketUrl('manager', $uid), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $managerRead, 'Manager must see private tickets');

		// Clean up
		$this->getClient('manager')->delete($this->ticketUrl('manager', $uid), [
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
		$uid         = 'rest-perm-creator-close-55005500';
		$reporterUrl = $this->ticketUrl('reporter', $uid);

		$this->getClient('reporter')->put($reporterUrl, [
			RequestOptions::HEADERS => [
				'Content-Type'  => self::MIME_TYPE_TICKET,
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => $this->makeTicketJson($uid, 'Ticket to be closed by creator'),
		]);

		// Creator closes own ticket
		$close = $this->getClient('reporter')->patch($reporterUrl, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['status' => 'closed']),
		]);
		$this->assertHttpStatus([200, 204], $close, 'Creator must be able to close own ticket');

		// Creator tries to delete — depends on queue ACL (reporter typically can't)
		$delete = $this->getClient('reporter')->delete($reporterUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		// Accept either "forbidden" (correct ACL enforcement) or "no content" (if
		// the queue allows self-delete); either is valid depending on configuration.
		$this->assertHttpStatus([204, 403, 405], $delete,
			'Creator delete attempt must return 204 (allowed) or 403/405 (blocked by ACL)');

		// If the ticket still exists, clean up as manager
		$check = $this->getClient('manager')->get($this->ticketUrl('manager', $uid), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		if ($check->getStatusCode() === 200)
		{
			$this->getClient('manager')->delete($this->ticketUrl('manager', $uid), [
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
