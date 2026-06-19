<?php
/**
 * Tracker REST API tests: create, read, update and delete tickets via JSON
 * 
 * These tests target the groupdav.php endpoint at:
 *   /{user}/tracker/{uid}
 *
 * PREREQUISITE: A tracker_groupdav handler class must be implemented following
 * the same pattern as calendar_groupdav and infolog_groupdav.  Until that class
 * exists the tests will return 404 for all /tracker/ paths and will be skipped
 * automatically (see setUpBeforeClass).
 *
 * JSON payload convention used here:
 *   - "@type"       => "Ticket"      (identifies the resource type)
 *   - "uid"         => string        (stable cross-system identifier)
 *   - "title"       => string        (maps to tr_summary)
 *   - "description" => string        (maps to tr_description)
 *   - "status"      => "open" | "closed" | "pending"   (maps to tr_status)
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
 * @covers tracker_groupdav::get()
 * @covers tracker_groupdav::put()
 * @covers tracker_groupdav::delete()
 */
class TrackerRestCreateReadDelete extends RestTest
{
	/**
	 * MIME type used for tracker ticket resources.
	 * "application/json" is the generic fallback; once a tracker_groupdav
	 * handler is implemented it may define a specific subtype such as
	 * "application/egw-tracker+json".
	 */
	const MIME_TYPE_TICKET = 'application/json';

	/**
	 * UID of the test ticket — full path is built dynamically using EGW_USER.
	 */
	const TICKET_UID = 'rest-api-test-ticket-11223344';

	/**
	 * Build the path for the test ticket using the configured EGW_USER.
	 */
	protected function ticketUrl(): string
	{
		return $this->appUrl('tracker', self::TICKET_UID);
	}

	/**
	 * Minimal JSON body for a new tracker ticket.
	 *
	 * The uid must match the last path segment of TICKET_UID so the server can
	 * map a PUT to a stable record (same convention as calendar events).
	 */
	const TICKET_JSON = <<<EOJSON
{
    "@type": "Ticket",
    "uid": "rest-api-test-ticket-11223344",
    "title": "REST API Test Ticket",
    "description": "Created by TrackerRestCreateReadDelete test suite",
    "status": "open",
    "priority": 5
}
EOJSON;

	// -------------------------------------------------------------------------
	// Skip guard
	// -------------------------------------------------------------------------

	/**
	 * Probe the tracker collection before running any test.  If the server
	 * returns 404 the tracker REST API is not yet implemented and the whole
	 * class is skipped rather than failing.
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

		// Probe with a PUT to detect whether the tracker REST handler is implemented.
		// A generic CalDAV collection returns 200 on GET but 403/405/501 on PUT.
		// Only 201 or 204 confirms that the handler can actually create tickets.
		$probeUid = 'tracker-probe-skip-check-00000000';
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
				.'Implement tracker_groupdav following the calendar_groupdav pattern, '
				.'then re-run these tests.'
			);
		}

		// Clean up probe ticket
		$location = $probe->getHeaderLine('Location') ?: "$base/$user/tracker/$probeUid";
		$client->delete($location, [RequestOptions::HEADERS => ['Accept' => 'application/json']]);
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
	 * PUT a new ticket as JSON.  The server must respond with 201 Created.
	 */
	public function testCreate()
	{
		$response = $this->getClient()->put($this->ticketUrl(), [
			RequestOptions::HEADERS => [
				'Content-Type'  => self::MIME_TYPE_TICKET,
				'If-None-Match' => '*',
			],
			RequestOptions::BODY => self::TICKET_JSON,
		]);

		$this->assertHttpStatus(201, $response, 'Creating a new tracker ticket');
	}

	/**
	 * GET the just-created ticket; the response must include the fields we sent.
	 */
	public function testRead()
	{
		$response = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(200, $response, 'Reading the created ticket');
		$this->assertJsonFields([
			'@type'       => 'Ticket',
			'uid'         => 'rest-api-test-ticket-11223344',
			'title'       => 'REST API Test Ticket',
			'status'      => 'open',
			'priority'    => 5,
		], $response, 'Ticket fields after create');
	}

	/**
	 * PATCH the ticket to change its status to "pending".
	 * The server must respond 200 (with updated body) or 204.
	 */
	public function testUpdateStatus()
	{
		$patch = json_encode(['status' => 'pending']);

		$response = $this->getClient()->patch($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => $patch,
		]);

		$this->assertHttpStatus([200, 204], $response, 'Patching ticket status to pending');

		// Read back to verify
		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);
		$this->assertJsonFields(['status' => 'pending'], $get, 'Status must be persisted');
	}

	/**
	 * Full PUT to update multiple fields at once (title + priority + description).
	 */
	public function testUpdateFull()
	{
		$updated = json_encode([
			'@type'       => 'Ticket',
			'uid'         => 'rest-api-test-ticket-11223344',
			'title'       => 'REST API Test Ticket (updated)',
			'description' => 'Updated by testUpdateFull',
			'status'      => 'open',
			'priority'    => 8,
		]);

		$response = $this->getClient()->put($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => $updated,
		]);

		$this->assertHttpStatus([200, 204], $response, 'Full PUT update of ticket');

		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertJsonFields([
			'title'    => 'REST API Test Ticket (updated)',
			'priority' => 8,
		], $get, 'Updated fields must be persisted');
	}

	/**
	 * Close a ticket via PATCH; the status must become "closed" and a closed
	 * timestamp should be present in the response.
	 */
	public function testClose()
	{
		$response = $this->getClient()->patch($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['status' => 'closed']),
		]);

		$this->assertHttpStatus([200, 204], $response, 'Closing ticket');

		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);

		$body = json_decode((string)$get->getBody(), true);
		$this->assertEquals('closed', $body['status'] ?? null, 'Ticket status must be closed');
		$this->assertNotEmpty($body['closed'] ?? null,
			'A closed timestamp must be present when status is closed');
	}

	/**
	 * DELETE the ticket; the server must respond with 204 No Content.
	 */
	public function testDelete()
	{
		$response = $this->getClient()->delete($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(204, $response, 'Deleting the ticket');
	}

	/**
	 * After deletion, a GET must return 404 Not Found.
	 */
	public function testReadAfterDelete()
	{
		$response = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);

		$this->assertHttpStatus(404, $response, 'Ticket must not exist after delete');
	}

	// -------------------------------------------------------------------------
	// Collection operations
	// -------------------------------------------------------------------------

	/**
	 * POST a new ticket to the collection.  The server must respond with 201
	 * and a Location header.  We clean up afterwards.
	 */
	public function testCreateViaPost()
	{
		$ticket = [
			'@type'       => 'Ticket',
			'uid'         => 'rest-api-post-ticket-99887766',
			'title'       => 'POST-created ticket',
			'description' => 'Created via POST to the tracker collection',
			'status'      => 'open',
			'priority'    => 3,
		];

		$response = $this->getClient()->post($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode($ticket),
		]);

		$this->assertHttpStatus(201, $response, 'Creating ticket via POST to collection');
		$this->assertNotEmpty($response->getHeaderLine('Location'),
			'POST must return a Location header');

		// Clean up
		$location = $this->locationPath($response);
		if ($location)
		{
			$this->getClient()->delete($this->url($location));
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
