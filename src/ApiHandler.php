<?php
/**
 * EGroupware Tracker: REST API handler
 *
 * Provides a GroupDAV-compatible REST endpoint for Tracker items:
 *   GET    /egroupware/groupdav.php/{user}/tracker/        → list tickets (JSON)
 *   GET    /egroupware/groupdav.php/{user}/tracker/{id}    → single ticket (JSON)
 *   POST   /egroupware/groupdav.php/{user}/tracker/        → create ticket
 *   PUT    /egroupware/groupdav.php/{user}/tracker/{id}    → replace ticket
 *   PATCH  /egroupware/groupdav.php/{user}/tracker/{id}    → partial update
 *   DELETE /egroupware/groupdav.php/{user}/tracker/{id}    → delete ticket
 *
 * Discovery: Api\CalDAV automatically detects this class because its FQCN is
 * EGroupware\Tracker\ApiHandler (ucfirst of app-name) and exposes the tracker
 * collection in the root alongside calendar, addressbook, infolog, timesheet.
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @author EGroupware GmbH
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

use EGroupware\Api;

/**
 * REST API for Tracker
 */
class ApiHandler extends Api\CalDAV\Handler
{
	/**
	 * @var \tracker_bo
	 */
	protected \tracker_bo $bo;

	/**
	 * No file extension — IDs are plain integers in the URL path.
	 *
	 * @var string
	 */
	static $path_extension = '';

	/**
	 * Options for json_encode calls used in responses
	 */
	const JSON_RESPONSE_OPTIONS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	/**
	 * Number of rows fetched per DB query (chunk / page size)
	 */
	const CHUNK_SIZE = 500;

	/**
	 * Constructor
	 *
	 * @param string $app  ignored (always 'tracker')
	 * @param Api\CalDAV $caldav  the calling CalDAV server
	 */
	public function __construct($app, Api\CalDAV $caldav)
	{
		parent::__construct('tracker', $caldav);
		self::$path_extension = '';
		$this->bo = new \tracker_bo();

		// Tracker requires at least one queue (category) to search/list tickets.
		// Auto-create a "Default" queue on first REST API use if none exist.
		if (empty($this->bo->trackers))
		{
			$cats = new Api\Categories(0, 'tracker');
			$cats->add([
				'name'   => 'Default',
				'owner'  => 0,
				'access' => 'public',
				'data'   => ['type' => 'tracker'],
			]);
			// Reload after creation so search() finds the new queue.
			$this->bo->trackers = $this->bo->get_tracker_labels();
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PROPFIND — list collection
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handle PROPFIND / collection GET — returns a list of all accessible tickets.
	 *
	 * @param string $path
	 * @param array  &$options
	 * @param array  &$files
	 * @param int    $user   account_id of the collection owner
	 * @param string $id     ='' single-resource request
	 * @return bool|string  true on success, HTTP status string on failure
	 */
	public function propfind($path, &$options, &$files, $user, $id = '')
	{
		$filter = [];

		// Restrict to a specific owner when user prefix is present in the URL.
		// Passing null means "all accessible tickets" — the BO enforces ACL.
		if ($user)
		{
			$filter['tr_creator'] = $user;
		}

		$nresults = null;
		if (($id || $options['root']['name'] !== 'propfind') &&
			!$this->_report_filters($options, $filter, $id, $nresults))
		{
			return false;
		}

		if ($id)
		{
			$path = dirname($path) . '/';
		}

		// sync-collection report
		if ($options['root']['name'] === 'sync-collection')
		{
			$files['sync-token']        = [$this, 'get_sync_collection_token'];
			$files['sync-token-params'] = [$path, $user];

			$this->sync_collection_token = $this->more_results = null;
			$filter['order']             = 'COALESCE(tr_modified,tr_created) ASC';
			$filter['sync-collection']   = true;
		}

		$files['files'] = $this->propfind_generator($path, $filter, $files['files'] ?? [], $nresults);

		return true;
	}

	/**
	 * Return the ctag (collection change tag) for the tracker collection.
	 *
	 * @param string $path
	 * @param int    $user
	 * @return string
	 */
	public function getctag($path, $user)
	{
		// Use the most recently modified/created ticket as the ctag.
		$rows = $this->bo->search('', ['tr_id'], 'COALESCE(tr_modified,tr_created) DESC', '', '', false, 'AND', [0, 1],
			$user ? ['tr_creator' => $user] : [], false);
		if ($rows)
		{
			$row = reset($rows);
			return (string)$row['tr_id'];
		}
		return '0';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// propfind_generator
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Generator that yields resource entries for propfind in CHUNK_SIZE batches.
	 *
	 * @param string   $path
	 * @param array    &$filter
	 * @param array    $extra       extra resources (e.g. the collection root)
	 * @param int|null $nresults    optional limit
	 * @param bool     $report_not_found_multiget_ids
	 * @return \Generator
	 */
	public function propfind_generator(
		string $path,
		array &$filter,
		array $extra = [],
		$nresults = null,
		bool $report_not_found_multiget_ids = true
	): \Generator {
		$starttime = microtime(true);

		$yielded = 0;
		foreach ($extra as $resource)
		{
			if (++$yielded && isset($nresults) && $yielded > $nresults)
			{
				$this->more_results = true;
				return;
			}
			yield $resource;
		}

		$order = $filter['order'] ?? 'COALESCE(egw_tracker.tr_modified,egw_tracker.tr_created) DESC';
		unset($filter['order']);

		$sync_collection_report = $filter['sync-collection'] ?? false;
		unset($filter['sync-collection']);

		[$sync_token, $sync_token_offset] = $filter['sync_token_offset'] ?? [0, 0];
		unset($filter['sync_token_offset']);
		$initial_offset = $sync_token_offset;

		// full-text search via criteria (first param), not col_filter
		$criteria = $filter['__search__'] ?? '';
		unset($filter['__search__']);

		$page_size = isset($nresults) ? min($nresults, self::CHUNK_SIZE) : self::CHUNK_SIZE;

		for (
			$chunk = 0;
			($tickets = $this->bo->search(
				$criteria, false, $order, '', '', false, 'AND',
				[$initial_offset + $chunk * $page_size, $page_size],
				$filter, false
			));
			++$chunk
		)
		{
			foreach ($tickets as &$ticket)
			{
				if ($sync_token !== ($modified = $ticket['tr_modified'] ?? $ticket['tr_created']))
				{
					$sync_token        = $modified;
					$sync_token_offset = 0;
				}
				$sync_token_offset++;

				// strip prefix once — JsTracker expects no prefix
				$entry = Api\Db::strip_array_keys($ticket, 'tr_');

				if (!empty($this->requested_multiget_ids) &&
					($k = array_search($entry['id'], $this->requested_multiget_ids)) !== false)
				{
					unset($this->requested_multiget_ids[$k]);
				}

				// sync-collection: deleted items have no properties
				if ($sync_collection_report && (string)$ticket['tr_status'] === \tracker_so::STATUS_DELETED)
				{
					yield ['path' => $path . urldecode($this->get_path($entry))];
					if (++$yielded && isset($nresults) && $yielded >= $nresults) break 2;
					continue;
				}

				try
				{
					$content = JsTracker::JsTicket($ticket, false);
				}
				catch (\Throwable $e)
				{
					error_log(__METHOD__ . "() ticket tr_id={$ticket['tr_id']}: " . $e->getMessage());
					continue;
				}

				$response_content = Api\CalDAV::isJSON() || !is_array($content) ? $content : Api\CalDAV::json_encode($content);

				$props = [
					'getcontenttype'  => Api\CalDAV::mkprop('getcontenttype', 'application/json'),
					'getlastmodified' => Api\DateTime::user2server($ticket['tr_modified'] ?? $ticket['tr_created'], 'utc'),
					'displayname'     => $ticket['tr_summary'],
					'getcontentlength' => bytes($response_content),
					'data'             => Api\CalDAV::mkprop('data', $response_content),
				];

				yield $this->add_resource($path, $entry, $props);

				if (++$yielded && isset($nresults) && $yielded >= $nresults) break 2;
			}

			if ($this->bo->total <= $yielded + $initial_offset) break;
		}

		if ($sync_collection_report)
		{
			$this->sync_collection_token = $sync_token . self::SYNC_TOKEN_OFFSET_DELIMITER . $sync_token_offset;
			if ($this->bo->total > $yielded + $initial_offset)
			{
				$this->more_results = true;
			}
		}

		if ($report_not_found_multiget_ids && !empty($this->requested_multiget_ids))
		{
			foreach ($this->requested_multiget_ids as $id)
			{
				if (++$yielded && isset($nresults) && $yielded > $nresults)
				{
					$this->more_results = true;
					return;
				}
				yield ['path' => $path . $id . self::$path_extension];
			}
		}

		if ($this->debug)
		{
			error_log(__METHOD__ . "($path) took " . (microtime(true) - $starttime) . "s for $yielded resources");
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Filter helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Map JSON attribute names / REST filter parameters to internal tracker_so column names.
	 *
	 * Supported filter keys:
	 *   search   → full-text search string
	 *   status   → ticket status label
	 *   priority → numeric priority
	 *   tracker  → queue/tracker id
	 *   assigned → account UID or id
	 *   linked   → "<app>:<id>"  (linked via Api\Link)
	 *   #<cf>    → custom field value
	 *
	 * @param array $filter  raw REST filter from query string
	 * @return array  col_filter array suitable for tracker_bo::search()
	 */
	protected function filter2col_filter(array $filter): array
	{
		$cols = [];
		foreach ($filter as $name => $value)
		{
			switch ($name)
			{
				case 'search':
					// passed as criteria to search(), not as col_filter
					$cols['__search__'] = $value;
					break;

				case 'status':
					try
					{
						$cols['tr_status'] = JsTracker::parseStatus($value);
					}
					catch (\Throwable $e)
					{
						throw new Api\Exception("Invalid status filter: " . $e->getMessage(), 400);
					}
					break;

				case 'priority':
					$cols['tr_priority'] = (int)$value;
					break;

				case 'tracker':
					$cols['tr_tracker'] = (int)$value;
					break;

				case 'assigned':
					// assigned expects the account-id in the ASSIGNEE join
					$cols['tr_assigned'] = is_numeric($value) ? (int)$value :
						(int)$GLOBALS['egw']->accounts->name2id($value);
					break;

				case 'linked':
					if (!preg_match('/^([a-z_]+):(\d+)$/i', $value, $m) ||
						!isset($GLOBALS['egw_info']['user']['apps'][$m[1]]) ||
						(int)$m[2] <= 0)
					{
						throw new Api\Exception("Invalid linked-filter '$value', must be '<app>:<id>'", 400);
					}
					$ids = Api\Link::get_links($m[1], $m[2], 'tracker');
					$cols['tr_id'] = $ids ?: [0];
					break;

				default:
					if ($name[0] === '#')
					{
						// custom field
						$cols[$name] = $value;
					}
					else
					{
						$cols['tr_' . $name] = $value;
					}
					break;
			}
		}
		return $cols;
	}

	/**
	 * Process CalDAV REPORT filters (also handles JSON/REST query parameters).
	 *
	 * @param array $options
	 * @param array &$filters
	 * @param string $id
	 * @param int|null &$nresults
	 * @return bool
	 */
	public function _report_filters($options, &$filters, $id, &$nresults)
	{
		if (Api\CalDAV::isJSON() && !empty($options['filters']) && is_array($options['filters']))
		{
			$mapped = $this->filter2col_filter($options['filters']);
			// full-text search is passed separately from col_filter
			if (isset($mapped['__search__']))
			{
				$filters['__search__'] = $mapped['__search__'];
				unset($mapped['__search__']);
			}
			$filters = $mapped + $filters;
		}

		// nresults from CalDAV limit element
		foreach ((array)($options['other'] ?? []) as $option)
		{
			if ($option['name'] === 'nresults')
			{
				$nresults = (int)$option['data'];
			}
			elseif ($option['name'] === 'sync-token' && !empty($option['data']))
			{
				$parts                        = explode('/', $option['data']);
				$filters['sync_token_offset'] = explode(self::SYNC_TOKEN_OFFSET_DELIMITER, array_pop($parts)) + [null, 0];
				$filters[]                    = 'COALESCE(tr_modified,tr_created)>=' . (int)$filters['sync_token_offset'][0];
				$filters['tr_status']         = 'all';
			}
		}

		// single-resource request
		if ($id)
		{
			$filters['tr_id'] = self::$path_extension ? basename($id, self::$path_extension) : $id;
		}

		return true;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// GET — single ticket
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handle GET request for a single tracker item.
	 *
	 * @param array  &$options
	 * @param int    $id
	 * @param int    $user  =null  account_id
	 * @return bool|string
	 */
	public function get(&$options, $id, $user = null)
	{
		header('Content-Type: application/json');

		// ── Reply sub-resource ─────────────────────────────────────────────────
		if (preg_match('#/tracker/\d+/replies(?:/(\d+))?/?$#', $options['path'], $m))
		{
			return $this->getReplies($options, (int)$id, isset($m[1]) ? (int)$m[1] : null);
		}
		// ──────────────────────────────────────────────────────────────────────

		if (!is_array($ticket = $this->_common_get_put_delete('GET', $options, $id)))
		{
			return $ticket;
		}

		// Load replies so JsTicket() includes the reply map
		$this->bo->read_extra(
			$this->bo->is_admin($this->bo->data['tr_tracker']),
			$this->bo->is_technician($this->bo->data['tr_tracker']),
			null, true
		);
		$ticket = Api\Db::strip_array_keys($this->bo->data, 'tr_');

		try
		{
			if (($type = Api\CalDAV::isJSON()))
			{
				$options['data']     = JsTracker::JsTicket($ticket, $type);
				$options['mimetype'] = 'application/json';

				header('Content-Encoding: identity');
				header('ETag: "' . $this->get_etag($ticket) . '"');
				return true;
			}
		}
		catch (\Throwable $e)
		{
			return $this->handleException($e);
		}

		return '501 Not Implemented';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PUT / POST / PATCH — create or update
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handle PUT / POST / PATCH for a tracker item.
	 *
	 * @param array  &$options
	 * @param int    $id
	 * @param int    $user          =null  collection owner
	 * @param string $prefix        =null  user prefix from path
	 * @param string $method        ='PUT' PUT / POST / PATCH
	 * @param string $content_type  =null
	 * @return bool|string
	 */
	public function put(&$options, $id, $user = null, $prefix = null, string $method = 'PUT', ?string $content_type = null)
	{
		// ── Reply sub-resource ─────────────────────────────────────────────────
		if (preg_match('#/tracker/\d+/replies(?:/(\d+))?/?$#', $options['path'], $m))
		{
			if ($method === 'POST')  return $this->createReply($options, (int)$id);
			if (isset($m[1]))        return $this->updateReply($options, (int)$id, (int)$m[1], $method);
			return '405 Method Not Allowed';
		}
		// ──────────────────────────────────────────────────────────────────────

		$old = $this->_common_get_put_delete($method, $options, $id);
		if (!is_null($old) && !is_array($old))
		{
			return $old;
		}

		try
		{
			$ticket = JsTracker::parseJsTicket($options['content'], $old ?: [], $content_type, $method);
		}
		catch (\Throwable $e)
		{
			return $this->handleException($e);
		}

		if (is_array($old))
		{
			$ticket['tr_id']      = $old['tr_id'] ?? $old['id'];
			$ticket['tr_creator'] = $old['tr_creator'] ?? $old['creator'];
			$ticket['tr_created'] = $old['tr_created'] ?? $old['created'];
			$retval = true;

			// Pre-filter fields the user cannot modify (field_acl check).
			// readonlys_from_acl() uses $this->bo->data loaded by read() above.
			// Silently skipping them is correct REST behaviour — the caller can't
			// know which fields the server enforces as read-only for their role.
			$readonlys = $this->bo->readonlys_from_acl();
			foreach (array_keys($ticket) as $field)
			{
				if (!empty($readonlys[$field]))
				{
					unset($ticket[$field]);
				}
			}
		}
		else
		{
			// new ticket
			if (!isset($ticket['tr_tracker']))
			{
				// use the first available tracker queue the user has access to
				$ticket['tr_tracker'] = key($this->bo->trackers);
			}
			if (!isset($ticket['tr_creator']))
			{
				$ticket['tr_creator'] = $prefix && $user ? $user : $GLOBALS['egw_info']['user']['account_id'];
			}
			$retval = '201 Created';
		}

		// apply ETag precondition
		if ($this->http_if_match)
		{
			$ticket['etag'] = self::etag2value($this->http_if_match);
		}

		$err = $this->bo->save($ticket);
		if ($err)
		{
			if ($this->debug)
			{
				error_log(__METHOD__ . "() save() failed: " . var_export($err, true));
			}
			return '403 Forbidden';
		}

		// re-read after save to get auto-set fields (tr_modified, tr_status, …)
		$saved = Api\Db::strip_array_keys($this->bo->data, 'tr_');

		$this->put_response_headers($saved, $options['path'], $retval, false);

		return $retval;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// DELETE
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Handle DELETE request for a tracker item.
	 *
	 * @param array  &$options
	 * @param int    $id
	 * @param int    $user  account_id of collection owner
	 * @return bool|string
	 */
	public function delete(&$options, $id, $user)
	{
		// ── Reply sub-resource ─────────────────────────────────────────────────
		if (preg_match('#/tracker/\d+/replies/(\d+)/?$#', $options['path'], $m))
		{
			return $this->deleteReply($options, (int)$id, (int)$m[1]);
		}
		// ──────────────────────────────────────────────────────────────────────

		if (!is_array($ticket = $this->_common_get_put_delete('DELETE', $options, $id)))
		{
			return $ticket;
		}

		$tr_id = $ticket['tr_id'] ?? $ticket['id'];
		$ok    = $this->bo->delete(['tr_id' => $tr_id]);

		return $ok !== false;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// read / check_access — required by Handler base
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Read a single tracker entry by ID.
	 *
	 * @param int|string $id
	 * @return array|false|null  array = found, false = no rights, null = not found
	 */
	public function read($id)
	{
		$ret = $this->bo->read(['tr_id' => $id]);
		if (is_array($ret))
		{
			// strip prefix so Handler base can work with 'id', 'modified', etc.
			$ret = Api\Db::strip_array_keys($this->bo->data, 'tr_');
		}
		return $ret;
	}

	/**
	 * Check if the current user has the required ACL level on a tracker entry.
	 *
	 * @param int   $acl    Api\Acl::READ / EDIT / DELETE (1 / 2 / 8)
	 * @param array|int $entry  tracker entry array (with tr_* or stripped keys) or tr_id
	 * @return bool|null  true = access, false = no access, null = not found
	 */
	public function check_access($acl, $entry)
	{
		// null entry means "new record" — return null (not false) so _common_get_put_delete
		// doesn't block creation.  Matches the timesheet pattern: null means "not applicable".
		if (is_null($entry))
		{
			return null;
		}

		// Normalise to tr_* prefixed keys so check_rights finds what it needs.
		if (is_array($entry) && isset($entry['id']) && !isset($entry['tr_id']))
		{
			$data = [];
			foreach ($entry as $k => $v)
			{
				$data['tr_' . $k] = $v;
			}
			// cat_id has no tr_ prefix in the tracker schema
			if (isset($entry['cat_id'])) $data['cat_id'] = $entry['cat_id'];
		}
		elseif (is_array($entry))
		{
			$data = $entry;
		}
		else
		{
			// scalar ID — pass directly; tracker_bo::check_rights will read the entry
			$data = $entry;
		}

		switch ($acl)
		{
			case Api\Acl::READ:
				$needed = TRACKER_ADMIN | TRACKER_TECHNICIAN | TRACKER_USER |
					TRACKER_ITEM_CREATOR | TRACKER_ITEM_ASSIGNEE | TRACKER_EVERYBODY;
				break;

			case Api\Acl::EDIT:
				$needed = TRACKER_ADMIN | TRACKER_TECHNICIAN | TRACKER_ITEM_CREATOR | TRACKER_ITEM_ASSIGNEE;
				break;

			case Api\Acl::DELETE:
				$needed = TRACKER_ADMIN | TRACKER_ITEM_CREATOR;
				break;

			default:
				$needed = TRACKER_ADMIN;
				break;
		}

		return $this->bo->check_rights($needed, null, $data);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Reply sub-resource handlers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * GET /tracker/{id}/replies[/{reply_id}]
	 *
	 * Without reply_id: returns all visible replies as `{ "<reply_id>": {…}, … }`.
	 * With reply_id:    returns the single Reply object.
	 *
	 * @param array    &$options
	 * @param int      $ticket_id
	 * @param int|null $reply_id  null = list all
	 * @return bool|string  true (body in $options['data']) or HTTP status string
	 */
	protected function getReplies(array &$options, int $ticket_id, ?int $reply_id)
	{
		$tid          = $ticket_id;
		$ticket_check = $this->_common_get_put_delete('GET', $options, $tid);
		if (!is_array($ticket_check))
		{
			return is_string($ticket_check) ? $ticket_check : '404 Not found';
		}

		$this->bo->read_extra(
			$this->bo->is_admin($this->bo->data['tr_tracker']),
			$this->bo->is_technician($this->bo->data['tr_tracker']),
			null, true
		);
		$replies = $this->bo->data['replies'] ?? [];

		if ($reply_id !== null)
		{
			foreach ($replies as $reply)
			{
				if ((int)$reply['reply_id'] === $reply_id)
				{
					$options['data']     = JsTracker::JsReply($reply);
					$options['mimetype'] = 'application/json';
					header('Content-Encoding: identity');
					return true;
				}
			}
			return '404 Not found';
		}

		$map = [];
		foreach ($replies as $reply)
		{
			$map[(string)$reply['reply_id']] = JsTracker::JsReply($reply, false);
		}
		$options['data']     = Api\CalDAV::json_encode($map);
		$options['mimetype'] = 'application/json';
		header('Content-Encoding: identity');
		return true;
	}

	/**
	 * POST /tracker/{id}/replies/
	 *
	 * Creates a new reply on the given ticket.
	 * Returns 201 Created with a Location header pointing to the new reply.
	 *
	 * @param array &$options
	 * @param int   $ticket_id
	 * @return string  HTTP status string
	 */
	protected function createReply(array &$options, int $ticket_id): string
	{
		$tid          = $ticket_id;
		$ticket_check = $this->_common_get_put_delete('GET', $options, $tid);
		if (!is_array($ticket_check))
		{
			return is_string($ticket_check) ? $ticket_check : '403 Forbidden';
		}

		try
		{
			$parsed = JsTracker::parseJsReply($options['content'], [], 'POST');
		}
		catch (\Throwable $e)
		{
			return $this->handleException($e);
		}

		$this->bo->data['reply_message'] = $parsed['reply_message'];
		$this->bo->data['reply_visible']  = $parsed['reply_visible'] ?? 0;
		// reply_creator and reply_created are set automatically by tracker_bo::save()

		$err = $this->bo->save();
		if ($err)
		{
			return '403 Forbidden';
		}

		// tracker_bo::save() prepends the new reply via array_unshift
		$reply_id = (int)$this->bo->data['replies'][0]['reply_id'];
		$base     = preg_replace('#/replies.*$#', '', rtrim($options['path'], '/'));
		header('Location: ' . $this->base_uri . $base . '/replies/' . $reply_id);

		return '201 Created';
	}

	/**
	 * PUT / PATCH /tracker/{id}/replies/{reply_id}
	 *
	 * Replaces or partially updates a reply.  Only `message` and `restricted`
	 * can be changed; all other fields are read-only.  Admin/technicians may
	 * edit any reply; regular users may only edit their own replies.
	 *
	 * @param array  &$options
	 * @param int    $ticket_id
	 * @param int    $reply_id
	 * @param string $method   PUT or PATCH
	 * @return string  HTTP status string
	 */
	protected function updateReply(array &$options, int $ticket_id, int $reply_id, string $method): string
	{
		$tid          = $ticket_id;
		$ticket_check = $this->_common_get_put_delete('GET', $options, $tid);
		if (!is_array($ticket_check))
		{
			return is_string($ticket_check) ? $ticket_check : '404 Not found';
		}

		$this->bo->read_extra(
			$this->bo->is_admin($this->bo->data['tr_tracker']),
			$this->bo->is_technician($this->bo->data['tr_tracker']),
			null, true
		);

		$reply = null;
		foreach ($this->bo->data['replies'] ?? [] as $r)
		{
			if ((int)$r['reply_id'] === $reply_id)
			{
				$reply = $r;
				break;
			}
		}
		if ($reply === null) return '404 Not found';

		$uid = (int)$GLOBALS['egw_info']['user']['account_id'];
		if ((int)$reply['reply_creator'] !== $uid &&
			!$this->bo->check_rights(TRACKER_ADMIN | TRACKER_TECHNICIAN, null, $this->bo->data))
		{
			return '403 Forbidden';
		}

		try
		{
			$parsed = JsTracker::parseJsReply($options['content'], $reply, $method);
		}
		catch (\Throwable $e)
		{
			return $this->handleException($e);
		}

		$update = ['reply_id' => $reply_id, 'tr_id' => $ticket_id];
		$update['reply_message'] = $parsed['reply_message'] ?? $reply['reply_message'];
		if (array_key_exists('reply_visible', $parsed))
		{
			$update['reply_visible'] = $parsed['reply_visible'];
		}
		elseif ($method !== 'PATCH')
		{
			$update['reply_visible'] = (int)$reply['reply_visible'];
		}

		try
		{
			$this->bo->save_comment($update);
		}
		catch (\Throwable $e)
		{
			return $this->handleException($e);
		}

		return '204 No Content';
	}

	/**
	 * DELETE /tracker/{id}/replies/{reply_id}
	 *
	 * Deletes a single reply.  Admin/technicians may delete any reply; regular
	 * users may only delete their own replies.
	 *
	 * @param array &$options
	 * @param int   $ticket_id
	 * @param int   $reply_id
	 * @return string  HTTP status string
	 */
	protected function deleteReply(array &$options, int $ticket_id, int $reply_id): string
	{
		$tid          = $ticket_id;
		$ticket_check = $this->_common_get_put_delete('GET', $options, $tid);
		if (!is_array($ticket_check))
		{
			return is_string($ticket_check) ? $ticket_check : '404 Not found';
		}

		$this->bo->read_extra(
			$this->bo->is_admin($this->bo->data['tr_tracker']),
			$this->bo->is_technician($this->bo->data['tr_tracker']),
			null, true
		);

		$reply = null;
		foreach ($this->bo->data['replies'] ?? [] as $r)
		{
			if ((int)$r['reply_id'] === $reply_id)
			{
				$reply = $r;
				break;
			}
		}
		if ($reply === null) return '404 Not found';

		$uid = (int)$GLOBALS['egw_info']['user']['account_id'];
		if ((int)$reply['reply_creator'] !== $uid &&
			!$this->bo->check_rights(TRACKER_ADMIN | TRACKER_TECHNICIAN, null, $this->bo->data))
		{
			return '403 Forbidden';
		}

		$GLOBALS['egw']->db->delete(
			\tracker_so::REPLIES_TABLE,
			['reply_id' => $reply_id, 'tr_id' => $ticket_id],
			__LINE__, __FILE__, 'tracker'
		);

		return '204 No Content';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Exception helper (mirrors timesheet ApiHandler)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Turn an exception into an appropriate HTTP error response.
	 *
	 * @param \Throwable $e
	 * @return string  HTTP status string
	 */
	protected function handleException(\Throwable $e): string
	{
		_egw_log_exception($e);
		header('Content-Type: application/json');
		echo json_encode(
			[
				'error'   => $code = $e->getCode() ?: 500,
				'message' => $e->getMessage(),
				'details' => $e->details ?? null,
				'script'  => $e->script ?? null,
			] + (empty($GLOBALS['egw_info']['server']['exception_show_trace']) ? [] : [
				'trace' => array_map(static function ($trace) {
					$trace['file'] = str_replace(EGW_SERVER_ROOT . '/', '', $trace['file'] ?? '');
					return $trace;
				}, $e->getTrace()),
			]),
			self::JSON_RESPONSE_OPTIONS
		);
		return (400 <= $code && $code < 600 ? $code : 500) . ' ' . $e->getMessage();
	}
}
