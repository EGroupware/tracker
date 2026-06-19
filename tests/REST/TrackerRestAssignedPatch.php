<?php
/**
 * Tracker REST API tests: PATCH the JSCalendar "participants" object
 *
 * Tracker tickets reuse the standardized JsTask vocabulary, so assignees and CC
 * are exposed through a single "participants" object (JsCalendar::Responsible()):
 *   - the ticket creator is the participant with role "owner"
 *   - assigned accounts are participants with role "attendee"
 *   - CC e-mail addresses are participants with role "informational"
 *
 * Verifies that:
 *  - GET returns participants as a map keyed by uid, with the creator as owner
 *  - PATCH { "participants": { "<uid>": { …, roles:{attendee:true} } } } assigns a user
 *  - PATCH { "participants": { "<uid>": null } } removes that assignee again
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @subpackage tests
 * @author EGroupware GmbH
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

require_once __DIR__.'/../../../api/tests/RestTest.php';

use EGroupware\Api\RestTest;
use GuzzleHttp\RequestOptions;

/**
 * Tests for the JSCalendar "participants" object on tracker tickets.
 *
 * Test order:
 *   testCreate                – POST a new ticket (creator only)
 *   testParticipantsOwnerOnly – fresh ticket has exactly one participant: the owner
 *   testPatchAddAssignee      – PATCH assigns a user → participant gains the attendee role
 *   testPatchRemoveAssignee   – PATCH with null removes the assignee → owner role only
 *   testDelete                – clean up
 */
class TrackerRestAssignedPatch extends RestTest
{
	const MIME_TYPE_TICKET = 'application/json';

	/**
	 * Full URL of the created ticket (set after testCreate).
	 */
	protected static ?string $ticketUrl = null;

	/**
	 * The owner participant captured from GET: ['uid' => string, 'name' => ?string, 'email' => ?string].
	 */
	protected static ?array $owner = null;

	// -------------------------------------------------------------------------
	// Skip guard
	// -------------------------------------------------------------------------

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$client = new \GuzzleHttp\Client([
			RequestOptions::HTTP_ERRORS     => false,
			RequestOptions::VERIFY          => false,
			RequestOptions::ALLOW_REDIRECTS => true,
			RequestOptions::AUTH => [
				$GLOBALS['EGW_USER']     ?? 'demo',
				$GLOBALS['EGW_PASSWORD'] ?? 'guest',
			],
		]);

		$base = $_ENV['EGW_URL'] ?? getenv('EGW_URL') ?: self::CALDAV_BASE;
		$base = rtrim($base, '/') . (strpos($base, 'groupdav.php') === false ? '/groupdav.php' : '');
		$user = $GLOBALS['EGW_USER'] ?? 'demo';

		$probe = $client->post("$base/$user/tracker/", [
			RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
			RequestOptions::BODY    => json_encode([
				'@type' => 'Ticket',
				'title' => 'Probe ticket (auto-deleted)',
			]),
		]);

		if ($probe->getStatusCode() !== 201)
		{
			self::markTestSkipped(
				'Tracker REST API is not yet implemented '
				.'(POST probe returned HTTP '.$probe->getStatusCode().'). '
				.'Implement the tracker REST handler, then re-run these tests.'
			);
		}

		// Clean up probe ticket. The Location header is a server-relative path
		// (e.g. /egroupware/groupdav.php/admin/tracker/3), so prepend the origin
		// from $base to get an absolute URL Guzzle can DELETE.
		$location = $probe->getHeaderLine('Location');
		if ($location)
		{
			if ($location[0] === '/' && preg_match('#^(https?://[^/]+)#', $base, $m))
			{
				$location = $m[1].$location;
			}
			$client->delete($location, [RequestOptions::HEADERS => ['Accept' => 'application/json']]);
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function ticketUrl(): string
	{
		return self::$ticketUrl ?? $this->appUrl('tracker');
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	/**
	 * Create a fresh ticket (creator only) via POST.
	 * Captures the Location header so subsequent tests know the URL.
	 */
	public function testCreate()
	{
		$response = $this->getClient()->post($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode([
				'@type' => 'Ticket',
				'title' => 'Participants PATCH test',
			]),
		]);

		$this->assertHttpStatus(201, $response, 'POST must create the ticket');
		$location = $this->locationPath($response);
		$this->assertNotEmpty($location, 'POST must return a Location header');

		// Strip the /egroupware/groupdav.php prefix and rebuild a full URL via url()
		$path = preg_replace('#^.*/groupdav\.php#', '', $location);
		self::$ticketUrl = $this->url($path);
	}

	/**
	 * A freshly created ticket has exactly one participant — the creator as owner.
	 * Captures that participant so the assignee PATCH can reference the same account.
	 *
	 * @depends testCreate
	 */
	public function testParticipantsOwnerOnly()
	{
		$response = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $response);

		$body = json_decode((string)$response->getBody(), true);
		$participants = $body['participants'] ?? null;
		$this->assertNotEmpty($participants, 'A ticket always has the creator as owner participant');
		$this->assertIsArray($participants);
		$this->assertFalse(array_is_list($participants),
			'participants must be a map keyed by uid, not a sequential array');

		$uid   = (string)array_key_first($participants);
		$owner = $participants[$uid];
		$this->assertSame(true, $owner['roles']['owner'] ?? null,
			'The only participant on a fresh ticket must have the owner role');
		$this->assertArrayNotHasKey('attendee', $owner['roles'],
			'A fresh ticket must not have any attendee/assignee yet');

		self::$owner = [
			'uid'   => $uid,
			'name'  => $owner['name']  ?? null,
			'email' => $owner['email'] ?? null,
		];
	}

	/**
	 * PATCH a participant with the attendee role to assign a user.
	 * Read back: the participant must now carry the attendee role.
	 *
	 * @depends testParticipantsOwnerOnly
	 */
	public function testPatchAddAssignee()
	{
		$this->assertNotNull(self::$owner, 'testParticipantsOwnerOnly must run first');

		$participant = array_filter([
			'@type' => 'Participant',
			'name'  => self::$owner['name'],
			'email' => self::$owner['email'],
			'roles' => ['attendee' => true],
		]);

		$response = $this->getClient()->patch($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode([
				'participants' => [self::$owner['uid'] => $participant],
			]),
		]);
		$this->assertHttpStatus([200, 204], $response, 'PATCH to assign a user');

		// Read back and verify the assignee carries the attendee role
		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);
		$body = json_decode((string)$get->getBody(), true);

		$assignee = $body['participants'][self::$owner['uid']] ?? null;
		$this->assertNotNull($assignee, 'assigned participant must still be present');
		$this->assertSame(true, $assignee['roles']['attendee'] ?? null,
			'assigned user must carry the attendee role after PATCH');
	}

	/**
	 * PATCH { "participants": { "<uid>": null } } must remove that assignee.
	 * The creator stays as owner, but the attendee role is gone.
	 *
	 * @depends testPatchAddAssignee
	 */
	public function testPatchRemoveAssignee()
	{
		$this->assertNotNull(self::$owner, 'testPatchAddAssignee must run first');

		$response = $this->getClient()->patch($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode([
				'participants' => [self::$owner['uid'] => null],
			]),
		]);
		$this->assertHttpStatus([200, 204], $response, 'PATCH to remove assignee');

		// Read back and verify the attendee role is gone (owner remains)
		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);
		$body = json_decode((string)$get->getBody(), true);

		$owner = $body['participants'][self::$owner['uid']] ?? null;
		$this->assertNotNull($owner, 'creator must remain as owner participant');
		$this->assertArrayNotHasKey('attendee', $owner['roles'] ?? [],
			'attendee role must be gone after removing the assignee');
	}

	/**
	 * Clean up the test ticket.
	 *
	 * @depends testPatchRemoveAssignee
	 */
	public function testDelete()
	{
		$response = $this->getClient()->delete($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(204, $response, 'DELETE must remove the test ticket');
	}
}
