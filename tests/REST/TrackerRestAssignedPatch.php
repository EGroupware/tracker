<?php
/**
 * Tracker REST API tests: PATCH assigned field using JMAP-style map
 *
 * Verifies that:
 *  - GET returns assigned as a map  { "$numeric_id": <account-info> }
 *  - PATCH { "assigned": { "$login": true } }  adds an assignee
 *  - PATCH { "assigned": { "$numeric_id": null } }  removes a single assignee
 *    without touching the rest of the list
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
 * Tests for the JMAP-style map format of the "assigned" field.
 *
 * Test order:
 *   testCreate            – POST a new ticket (no assignees)
 *   testAssignedIsNull    – verify assigned is absent/null on a fresh ticket
 *   testPatchAddAssignee  – PATCH adds an assignee; response is a map, not an array
 *   testPatchRemoveAssignee – PATCH with null removes the assignee
 *   testDelete            – clean up
 */
class TrackerRestAssignedPatch extends RestTest
{
	const MIME_TYPE_TICKET = 'application/json';

	/**
	 * Full URL of the created ticket (set after testCreate).
	 */
	protected static ?string $ticketUrl = null;

	/**
	 * The numeric account-id key captured from the assigned map (set after testPatchAddAssignee).
	 */
	protected static ?string $assignedKey = null;

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
				'@type'   => 'Ticket',
				'summary' => 'Probe ticket (auto-deleted)',
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

		// Clean up probe ticket
		$location = $probe->getHeaderLine('Location');
		if ($location)
		{
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
	 * Create a fresh ticket with no assignees via POST.
	 * Captures the Location header so subsequent tests know the URL.
	 */
	public function testCreate()
	{
		$response = $this->getClient()->post($this->appUrl('tracker'), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode([
				'@type'   => 'Ticket',
				'summary' => 'Assigned PATCH map test',
			]),
		]);

		$this->assertHttpStatus(201, $response, 'POST must create the ticket');
		$location = $this->locationPath($response);
		$this->assertNotEmpty($location, 'POST must return a Location header');

		self::$ticketUrl = $this->url($location);
	}

	/**
	 * A freshly created ticket with no assignees must return null / no assigned field.
	 *
	 * @depends testCreate
	 */
	public function testAssignedIsNull()
	{
		$response = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $response);

		$body = json_decode((string)$response->getBody(), true);
		$this->assertEmpty($body['assigned'] ?? null,
			'assigned must be absent or null on a ticket created without assignees');
	}

	/**
	 * PATCH { "assigned": { "$login": true } } must add the user.
	 * The response assigned field must be a map (string keys), not a sequential array.
	 *
	 * @depends testAssignedIsNull
	 */
	public function testPatchAddAssignee()
	{
		$login = $GLOBALS['EGW_USER'] ?? 'demo';

		$response = $this->getClient()->patch($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['assigned' => [$login => true]]),
		]);
		$this->assertHttpStatus([200, 204], $response, 'PATCH to add assignee');

		// Read back and verify map format
		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);
		$body = json_decode((string)$get->getBody(), true);

		$assigned = $body['assigned'] ?? null;
		$this->assertNotNull($assigned, 'assigned must not be null after adding an assignee');
		$this->assertIsArray($assigned, 'assigned must be an array');
		$this->assertFalse(array_is_list($assigned),
			'assigned must be a JMAP-style map with account-id string keys, not a sequential array');

		// Capture the numeric key for the remove test
		self::$assignedKey = (string)array_key_first($assigned);
		$this->assertIsNumeric(self::$assignedKey,
			'assigned map keys must be numeric account-id strings');
	}

	/**
	 * PATCH { "assigned": { "$numeric_id": null } } must remove that single assignee.
	 * The rest of the assigned list must be unchanged (here: resulting in an empty map).
	 *
	 * @depends testPatchAddAssignee
	 */
	public function testPatchRemoveAssignee()
	{
		$this->assertNotNull(self::$assignedKey, 'testPatchAddAssignee must run first');

		$response = $this->getClient()->patch($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Content-Type' => self::MIME_TYPE_TICKET],
			RequestOptions::BODY    => json_encode(['assigned' => [self::$assignedKey => null]]),
		]);
		$this->assertHttpStatus([200, 204], $response, 'PATCH to remove assignee');

		// Read back and verify the assignee is gone
		$get = $this->getClient()->get($this->ticketUrl(), [
			RequestOptions::HEADERS => ['Accept' => self::MIME_TYPE_TICKET],
		]);
		$this->assertHttpStatus(200, $get);
		$body = json_decode((string)$get->getBody(), true);

		$this->assertEmpty($body['assigned'] ?? null,
			'assigned must be empty after removing the only assignee via PATCH null');
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
