<?php
/**
 * Tracker REST API tests: create, read, update and delete tickets via JSON
 *
 * These tests target the live \EGroupware\Tracker\ApiHandler REST endpoint at:
 *   /{user}/tracker/{id}
 *
 * Tickets are addressed by their numeric tr_id only - unlike calendar/infolog,
 * ApiHandler has no concept of a client-supplied stable UID. Every test that
 * needs to reference a ticket after creating it therefore POSTs first and
 * captures the numeric id from the Location header, then chains the rest of
 * the lifecycle with @depends.
 *
 * JSON payload convention used here (see doc/Tracker.md for the full contract):
 *   - "@type"       => "Ticket"      (identifies the resource type)
 *   - "title"       => string        (maps to tr_summary)
 *   - "description" => string        (maps to tr_description)
 *   - "status"      => "Open" | "Closed" | "Pending" | "Deleted"  (maps to tr_status)
 *   - "priority"    => 1..9          (maps to tr_priority; 5 = medium)
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
use GuzzleHttp\RequestOptions;

/**
 * Basic CRUD lifecycle for Tracker tickets via the JSON REST API.
 *
 * @covers \EGroupware\Tracker\ApiHandler::get
 * @covers \EGroupware\Tracker\ApiHandler::put
 * @covers \EGroupware\Tracker\ApiHandler::delete
 */
class TrackerRestCreateReadDelete extends RestTest
{
	/**
	 * MIME type used for tracker ticket resources.
	 */
	const MIME_TYPE_TICKET = 'application/json';

	/**
	 * Full URL of the ticket created by testCreate(); every @depends test in the
	 * lifecycle chain reads/writes this same ticket.
	 */
	protected static ?string $ticketUrl = null;

	/**
	 * Minimal JSON body for a new tracker ticket.
	 */
	const TICKET_JSON = <<<EOJSON
{
    "@type": "Ticket",
    "title": "REST API Test Ticket",
    "description": "Created by TrackerRestCreateReadDelete test suite",
    "status": "Open",
    "priority": 5
}
EOJSON;

	// -------------------------------------------------------------------------
	// Skip guard
	// -------------------------------------------------------------------------

	/**
	 * Probe the tracker collection before running any test. If the server
	 * doesn't accept a POST-created ticket, the tracker REST API isn't
	 * available and the whole class is skipped rather than failing.
	 */
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		// We need a real HTTP client here; use default (demo) credentials.
		// Bypass getClient() to avoid setUp ordering issues.
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

		// Probe with a POST to the collection; tickets are always server-assigned
		// numeric ids, so creation (not a PUT to a guessed path) is the only
		// reliable way to detect whether the handler is available.
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
				'Tracker REST API is not available on this server '
				.'(POST probe returned HTTP '.$probe->getStatusCode().'). '
				.'Check that EGroupware\Tracker\ApiHandler is loaded, then re-run these tests.'
			);
		}

		// Clean up probe ticket. The Location header is a server-relative path
		// (e.g. /egroupware/groupdav.php/admin/tracker/3); prepend the origin
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
	// Authentication
	// -------------------------------------------------------------------------

	/**
	 * Unauthenticated requests to the tracker collection must return 401.
	 */
	public function testNoAuth()
	{
		$response = $this->getClient([])->get($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(401, $response);
	}

	/**
	 * Authenticated GET on the tracker collection must return 200 with JSON.
	 */
	public function testAuth()
	{
		$response = $this->getClient()->get($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(200, $response);
		$this->assertStringContainsString('json', $response->getHeaderLine('Content-Type'),
			'Tracker collection response must be JSON');
	}

	// -------------------------------------------------------------------------
	// CRUD lifecycle
	// -------------------------------------------------------------------------

	/**
	 * POST a new ticket as JSON. The server must respond with 201 Created and a
	 * Location header pointing at the server-assigned numeric id.
	 */
	public function testCreate()
	{
		$response = $this->getClient()->post($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => self::TICKET_JSON,
		]);

		$this->assertHttpStatus(201, $response, 'Creating a new tracker ticket');

		$location = $this->locationPath($response);
		$this->assertNotEmpty($location, 'POST must return a Location header');

		// Strip the /egroupware/groupdav.php prefix and rebuild a full URL via url()
		$path = preg_replace('#^.*/groupdav\.php#', '', $location);
		self::$ticketUrl = $this->url($path);
	}

	/**
	 * GET the just-created ticket; the response must include the fields we sent.
	 *
	 * @depends testCreate
	 */
	public function testRead()
	{
		$response = $this->getClient()->get(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(200, $response, 'Reading the created ticket');
		$this->assertJsonFields([
			'@type'    => 'Ticket',
			'title'    => 'REST API Test Ticket',
			'status'   => 'Open',
			'priority' => 5,
		], $response, 'Ticket fields after create');
	}

	/**
	 * PATCH the ticket to change its status to "Pending".
	 * The server must respond 200 (with updated body) or 204.
	 *
	 * @depends testRead
	 */
	public function testUpdateStatus()
	{
		$patch = json_encode(['status' => 'Pending']);

		$response = $this->getClient()->patch(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => $patch,
		]);

		$this->assertHttpStatus([200, 204], $response, 'Patching ticket status to pending');

		// Read back to verify
		$get = $this->getClient()->get(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);
		$this->assertJsonFields(['status' => 'Pending'], $get, 'Status must be persisted');
	}

	/**
	 * Full PUT to update multiple fields at once (title + priority + description).
	 *
	 * @depends testUpdateStatus
	 */
	public function testUpdateFull()
	{
		$updated = json_encode([
			'@type'       => 'Ticket',
			'title'       => 'REST API Test Ticket (updated)',
			'description' => 'Updated by testUpdateFull',
			'status'      => 'Open',
			'priority'    => 8,
		]);

		$response = $this->getClient()->put(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => $updated,
		]);

		$this->assertHttpStatus([200, 204], $response, 'Full PUT update of ticket');

		$get = $this->getClient()->get(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertJsonFields([
			'title'    => 'REST API Test Ticket (updated)',
			'priority' => 8,
		], $get, 'Updated fields must be persisted');
	}

	/**
	 * Close a ticket via PATCH; the status must become "Closed" and a closed
	 * timestamp should be present in the response.
	 *
	 * @depends testUpdateFull
	 */
	public function testClose()
	{
		$response = $this->getClient()->patch(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['status' => 'Closed']),
		]);

		$this->assertHttpStatus([200, 204], $response, 'Closing ticket');

		$get = $this->getClient()->get(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);

		$body = json_decode((string)$get->getBody(), true);
		$this->assertEquals('Closed', $body['status'] ?? null, 'Ticket status must be closed');
		$this->assertNotEmpty($body['closed'] ?? null,
			'A closed timestamp must be present when status is closed');
	}

	/**
	 * DELETE the ticket; the server must respond with 204 No Content.
	 *
	 * @depends testClose
	 */
	public function testDelete()
	{
		$response = $this->getClient()->delete(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(204, $response, 'Deleting the ticket');
	}

	/**
	 * After deletion, a GET must return 404 Not Found.
	 *
	 * @depends testDelete
	 */
	public function testReadAfterDelete()
	{
		$response = $this->getClient()->get(self::$ticketUrl, [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(404, $response, 'Ticket must not exist after delete');
	}

	// -------------------------------------------------------------------------
	// Collection operations
	// -------------------------------------------------------------------------

	/**
	 * POST a new ticket to the collection. The server must respond with 201
	 * and a Location header. We clean up afterwards.
	 */
	public function testCreateViaPost()
	{
		$ticket = [
			'@type'       => 'Ticket',
			'title'       => 'POST-created ticket',
			'description' => 'Created via POST to the tracker collection',
			'status'      => 'Open',
			'priority'    => 3,
		];

		$response = $this->getClient()->post($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode($ticket),
		]);

		$this->assertHttpStatus(201, $response, 'Creating ticket via POST to collection');
		$this->assertNotEmpty($response->getHeaderLine('Location'),
			'POST must return a Location header');

		// Clean up. locationPath() returns a path that already includes
		// /groupdav.php, so strip it before handing the path back to url().
		$location = $this->locationPath($response);
		if ($location)
		{
			$path = preg_replace('#^.*/groupdav\.php#', '', $location);
			$this->getClient()->delete($this->url($path));
		}
	}

	/**
	 * GET the tracker collection with JSON Accept header.
	 * The response must be a JSON object with a "responses" array.
	 */
	public function testListCollection()
	{
		$response = $this->getClient()->get($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(200, $response, 'Listing tracker collection');

		$body = json_decode((string)$response->getBody(), true);
		$this->assertNotNull($body, 'Collection response must be valid JSON');
		$this->assertArrayHasKey('responses', $body,
			'Tracker collection JSON must have a "responses" key');
	}
}
