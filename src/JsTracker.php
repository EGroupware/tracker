<?php
/**
 * EGroupware Tracker: REST API - JsTracker JSON format
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @author EGroupware GmbH
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Tracker;

use EGroupware\Api;

/**
 * Render and parse tracker items as JSON for the REST API.
 *
 * JSON representation fields:
 *   @type, id, summary, description, tracker, status, priority, completion,
 *   startDate, dueDate, closed, private, category, version, creator, created,
 *   modified, modifier, assigned, cc, group, egroupware.org:customfields, etag
 */
class JsTracker extends Api\CalDAV\JsBase
{
	const APP = 'tracker';

	const TYPE_TICKET = 'Ticket';

	/**
	 * Status integer → label map (mirrors tracker_so constants)
	 */
	const STATUS_LABELS = [
		\tracker_so::STATUS_OPEN    => 'Open',
		\tracker_so::STATUS_CLOSED  => 'Closed',
		\tracker_so::STATUS_DELETED => 'Deleted',
		\tracker_so::STATUS_PENDING => 'Pending',
	];

	/**
	 * Build the JSON representation of a tracker item.
	 *
	 * @param int|array $ticket  tracker item (tr_* prefixed array) or tr_id
	 * @param bool|"pretty" $encode  true = JSON string, false = raw array
	 * @return string|array
	 * @throws Api\Exception\NotFound
	 */
	public static function JsTicket($ticket, $encode = true)
	{
		static $bo = null;
		if (!isset($bo)) $bo = new \tracker_bo();

		if (is_scalar($ticket) && !($ticket = $bo->read(['tr_id' => $ticket])))
		{
			throw new Api\Exception\NotFound();
		}

		// strip tr_ prefix so we work with clean keys
		if (isset($ticket['tr_id']))
		{
			$ticket = Api\Db::strip_array_keys($ticket, 'tr_');
		}

		// resolve any custom tracker stati stored in $bo->estat
		$status_label = self::STATUS_LABELS[$ticket['status']] ??
			($bo->estat[$ticket['tracker']][$ticket['status']] ?? (string)$ticket['status']);

		$data = array_filter([
			self::AT_TYPE   => self::TYPE_TICKET,
			'id'            => (int)$ticket['id'],
			'summary'       => $ticket['summary'],
			'description'   => $ticket['description'] ?: null,
			'tracker'       => (int)$ticket['tracker'] ?: null,
			'status'        => $status_label,
			'priority'      => (int)$ticket['priority'],
			'completion'    => (int)$ticket['completion'],
			'startDate'     => !empty($ticket['startdate']) ? self::UTCDateTime($ticket['startdate'], true) : null,
			'dueDate'       => !empty($ticket['duedate'])   ? self::UTCDateTime($ticket['duedate'], true)   : null,
			'closed'        => !empty($ticket['closed'])    ? self::UTCDateTime($ticket['closed'], true)    : null,
			'private'       => (bool)$ticket['private'],
			'category'      => self::categories($ticket['cat_id']),
			'version'       => $ticket['version'] ? self::categories($ticket['version']) : null,
			'creator'       => self::account($ticket['creator']),
			'created'       => self::UTCDateTime($ticket['created'], true),
			'modified'      => !empty($ticket['modified']) ? self::UTCDateTime($ticket['modified'], true) : null,
			'modifier'      => !empty($ticket['modifier'])  ? self::account($ticket['modifier'])  : null,
			'assigned'      => !empty($ticket['assigned'])  ? self::assigned($ticket['assigned']) : null,
			'cc'            => $ticket['cc'] ?: null,
			'group'         => !empty($ticket['group'])     ? self::account($ticket['group'])     : null,
			'egroupware.org:customfields' => self::customfields($ticket),
			'etag'          => ApiHandler::etag($ticket),
		]);

		// @type and private must always be present even when falsy
		$data[self::AT_TYPE] = self::TYPE_TICKET;
		$data['private']     = (bool)$ticket['private'];

		if ($encode)
		{
			return Api\CalDAV::json_encode($data, $encode === 'pretty');
		}
		return $data;
	}

	/**
	 * Format the assigned list (array of account-ids) as JSON.
	 *
	 * @param array|int $assigned
	 * @return array|null
	 */
	protected static function assigned($assigned)
	{
		if (!$assigned) return null;
		$result = [];
		foreach ((array)$assigned as $uid)
		{
			if ($uid) $result[] = self::account($uid);
		}
		return $result ?: null;
	}

	/**
	 * Parse a JSON tracker ticket (PUT / POST / PATCH body).
	 *
	 * @param string $json         raw request body
	 * @param array  $old          existing record for PATCH merging
	 * @param ?string $content_type
	 * @param string $method       PUT / POST / PATCH
	 * @return array  with tr_* keys ready for tracker_bo::save()
	 * @throws Api\CalDAV\JsParseException
	 */
	public static function parseJsTicket(string $json, array $old = [], ?string $content_type = null, string $method = 'PUT'): array
	{
		try
		{
			$data = json_decode($json, true, 10, JSON_THROW_ON_ERROR);

			// For PATCH: only parse what's in the request body.
			// Do NOT re-serialize $old and merge — that converts raw IDs to display names
			// and causes lookup failures.  so_sql::save() will merge the partial update
			// with the existing $this->bo->data that was already loaded by read().
			if ($method !== 'PATCH' && empty($data['summary']))
			{
				throw new Api\CalDAV\JsParseException("Required field 'summary' missing");
			}

			$ticket = [];

			foreach ($data as $name => $value)
			{
				switch ($name)
				{
					case 'summary':
						$ticket['tr_summary'] = $value;
						break;

					case 'description':
						$ticket['tr_description'] = $value;
						break;

					case 'tracker':
						$ticket['tr_tracker'] = self::parseInt($value);
						break;

					case 'status':
						$ticket['tr_status'] = self::parseStatus($value);
						break;

					case 'priority':
						$ticket['tr_priority'] = self::parseInt($value);
						break;

					case 'completion':
						$ticket['tr_completion'] = min(100, max(0, self::parseInt($value)));
						break;

					case 'startDate':
						$ticket['tr_startdate'] = $value ? self::parseDateTime($value) : null;
						break;

					case 'dueDate':
						$ticket['tr_duedate'] = $value ? self::parseDateTime($value) : null;
						break;

					case 'private':
						$ticket['tr_private'] = $value ? 1 : 0;
						break;

					case 'category':
						$ticket['cat_id'] = self::parseCategories($value, false);
						break;

					case 'version':
						$ticket['tr_version'] = $value ? self::parseInt($value) : null;
						break;

					case 'creator':
						$ticket['tr_creator'] = self::parseAccount($value);
						break;

					case 'assigned':
						$ticket['tr_assigned'] = self::parseAssigned($value);
						break;

					case 'cc':
						$ticket['tr_cc'] = $value;
						break;

					case 'group':
						$ticket['tr_group'] = $value ? self::parseAccount($value) : null;
						break;

					case 'egroupware.org:customfields':
						$ticket = array_merge($ticket, self::parseCustomfields($value));
						break;

					// read-only / auto-set fields — silently ignore
					case self::AT_TYPE:
					case 'id':
					case 'etag':
					case 'created':
					case 'modified':
					case 'modifier':
					case 'closed':
						break;

					default:
						error_log(__METHOD__ . "() unknown field $name=" . json_encode($value, self::JSON_OPTIONS_ERROR) . ' --> ignored');
						break;
				}
			}
		}
		catch (\Throwable $e)
		{
			self::handleExceptions($e, 'JsTracker', $name ?? '', $value ?? null);
		}

		return $ticket;
	}

	/**
	 * Parse a status label back to its integer value.
	 *
	 * Accepts both the built-in labels (Open / Closed / Deleted / Pending) and
	 * any custom queue stati stored in tracker config.
	 *
	 * @param string $value
	 * @return int
	 * @throws Api\CalDAV\JsParseException
	 */
	public static function parseStatus(string $value): int
	{
		// built-in stati
		if (($id = array_search($value, self::STATUS_LABELS, true)) !== false)
		{
			return (int)$id;
		}

		// custom tracker-specific stati
		static $bo = null;
		if (!isset($bo)) $bo = new \tracker_bo();

		foreach ((array)$bo->estat as $tracker_stati)
		{
			if (($id = array_search($value, (array)$tracker_stati, true)) !== false)
			{
				return (int)$id;
			}
		}

		throw new Api\CalDAV\JsParseException("Invalid status '$value'");
	}

	/**
	 * Parse the assigned field: accepts a single account-object or an array of them.
	 *
	 * @param mixed $value
	 * @return array  flat array of account_ids
	 */
	protected static function parseAssigned($value): array
	{
		if (!$value) return [];
		if (isset($value['uid'])) $value = [$value]; // single object
		$ids = [];
		foreach ($value as $item)
		{
			if (($uid = self::parseAccount($item)))
			{
				$ids[] = $uid;
			}
		}
		return $ids;
	}
}
