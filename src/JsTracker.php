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
 * Field names follow the standardized JSCalendar Task (JsTask) vocabulary where
 * an equivalent attribute exists, so tracker tickets stay close to InfoLog tasks
 * (the exception being replies, which are tracker-specific).
 *
 * JSON representation fields:
 *   @type, id, title, description, tracker, status, priority, percentComplete,
 *   start, due, closed, privacy, categories, version, creator, created,
 *   updated, modifier, participants, group, egroupware.org:customfields, etag
 */
class JsTracker extends Api\CalDAV\JsCalendar
{
	const APP = 'tracker';

	const TYPE_TICKET = 'Ticket';

	const TYPE_REPLY = 'Reply';

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
			'title'         => $ticket['summary'],
			'description'   => $ticket['description'] ?: null,
			'tracker'       => (int)$ticket['tracker'] ?: null,
			'status'        => $status_label,
			'priority'      => (int)$ticket['priority'],
			'percentComplete' => (int)$ticket['completion'],
			'start'         => !empty($ticket['startdate']) ? self::UTCDateTime($ticket['startdate'], true) : null,
			'due'           => !empty($ticket['duedate'])   ? self::UTCDateTime($ticket['duedate'], true)   : null,
			'closed'        => !empty($ticket['closed'])    ? self::UTCDateTime($ticket['closed'], true)    : null,
			'categories'    => self::categories($ticket['cat_id']),
			'version'       => $ticket['version'] ? self::categories($ticket['version']) : null,
			'creator'       => self::account($ticket['creator']),
			'created'       => self::UTCDateTime($ticket['created'], true),
			'updated'       => !empty($ticket['modified']) ? self::UTCDateTime($ticket['modified'], true) : null,
			'modifier'      => !empty($ticket['modifier'])  ? self::account($ticket['modifier'])  : null,
			// assigned + cc are merged into a single JSCalendar "participants" object (owner=creator,
			// attendees=assigned accounts, informational=cc emails) using JsCalendar::Responsible()
			'participants'  => self::Responsible([
				'info_owner'       => $ticket['creator'] ?? null,
				'info_responsible' => array_filter((array)($ticket['assigned'] ?? [])),
				'info_cc'          => $ticket['cc'] ?? '',
			]) ?: null,
			'group'         => !empty($ticket['group'])     ? self::account($ticket['group'])     : null,
			'egroupware.org:customfields' => self::customfields($ticket),
			'etag'          => ApiHandler::etag($ticket),
		]);

		// @type and privacy must always be present even when falsy
		$data[self::AT_TYPE] = self::TYPE_TICKET;
		$data['privacy']     = $ticket['private'] ? 'private' : 'public';

		// Include replies when loaded via read_extra($read_replies=true)
		if (!empty($ticket['replies']))
		{
			$replies_map = [];
			foreach ($ticket['replies'] as $reply)
			{
				$replies_map[(string)$reply['reply_id']] = self::JsReply($reply, false);
			}
			$data['replies'] = $replies_map;
		}

		if ($encode)
		{
			return Api\CalDAV::json_encode($data, $encode === 'pretty');
		}
		return $data;
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

			if (!is_array($data))
			{
				if ($method !== 'PATCH')
				{
					throw new Api\CalDAV\JsParseException('Invalid JSON body, expected an object');
				}
				$data = [];
			}

			if ($method !== 'PATCH' && $data === [])
			{
				throw new Api\CalDAV\JsParseException('Empty request body');
			}

			// For PATCH: only parse what's in the request body.
			// Do NOT re-serialize $old and merge — that converts raw IDs to display names
			// and causes lookup failures.  so_sql::save() will merge the partial update
			// with the existing $this->bo->data that was already loaded by read().
			if ($method !== 'PATCH' && empty($data['title']))
			{
				throw new Api\CalDAV\JsParseException("Required field 'title' missing");
			}

			$ticket = [];

			foreach ($data as $name => $value)
			{
				switch ($name)
				{
					case 'title':
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

					case 'percentComplete':
						$ticket['tr_completion'] = min(100, max(0, self::parseInt($value)));
						break;

					case 'start':
						$ticket['tr_startdate'] = $value ? self::parseDateTime($value) : null;
						break;

					case 'due':
						$ticket['tr_duedate'] = $value ? self::parseDateTime($value) : null;
						break;

					case 'privacy':
						$ticket['tr_private'] = self::parsePrivacy($value) === 'private' ? 1 : 0;
						break;

					case 'categories':
						$ticket['cat_id'] = self::parseCategories($value, false);
						break;

					case 'version':
						$ticket['tr_version'] = $value ? self::parseInt($value) : null;
						break;

					case 'creator':
						$ticket['tr_creator'] = self::parseAccount($value);
						break;

					case 'participants':
						// JSCalendar participants → tracker assigned (responsible accounts) + cc (informational emails)
						$responsible = self::parseResponsible((array)$value, false);
						$ticket['tr_assigned'] = $responsible['info_responsible'];
						$ticket['tr_cc']       = $responsible['info_cc'];
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
					case 'updated':
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
	 * Build the JSON representation of a single reply.
	 *
	 * @param array         $reply  row from egw_tracker_replies (reply_* keys, reply_created in user-TZ)
	 * @param bool|"pretty" $encode true = JSON string, false = raw array
	 * @return string|array
	 */
	public static function JsReply(array $reply, $encode = true)
	{
		$data = [
			self::AT_TYPE => self::TYPE_REPLY,
			'id'          => (int)$reply['reply_id'],
			'message'     => (string)$reply['reply_message'],
			'creator'     => self::account((int)$reply['reply_creator']),
			'created'     => !empty($reply['reply_created'])
				? self::UTCDateTime($reply['reply_created'], true)
				: null,
			'restricted'  => (bool)$reply['reply_visible'],
		];

		if ($encode)
		{
			return Api\CalDAV::json_encode($data, $encode === 'pretty');
		}
		return $data;
	}

	/**
	 * Parse a reply JSON body (POST / PUT / PATCH request body).
	 *
	 * @param string $json   raw request body
	 * @param array  $old    existing reply row for PATCH merging (reply_* keys)
	 * @param string $method POST / PUT / PATCH
	 * @return array  reply_* prefixed fields ready for save_comment()
	 * @throws Api\CalDAV\JsParseException
	 */
	public static function parseJsReply(string $json, array $old = [], string $method = 'POST'): array
	{
		$name = $value = null;
		try
		{
			$data = json_decode($json, true, 5, JSON_THROW_ON_ERROR);

			if ($method !== 'PATCH' && empty($data['message']))
			{
				throw new Api\CalDAV\JsParseException("Required field 'message' missing");
			}

			$reply = [];
			foreach ($data as $name => $value)
			{
				switch ($name)
				{
					case 'message':
						$reply['reply_message'] = (string)$value;
						break;

					case 'restricted':
						$reply['reply_visible'] = $value ? 1 : 0;
						break;

					// read-only fields — silently ignore
					case self::AT_TYPE:
					case 'id':
					case 'creator':
					case 'created':
						break;

					default:
						error_log(__METHOD__ . "() unknown field $name=" . json_encode($value, self::JSON_OPTIONS_ERROR) . ' --> ignored');
						break;
				}
			}
		}
		catch (\Throwable $e)
		{
			self::handleExceptions($e, 'JsReply', $name ?? '', $value ?? null);
		}

		return $reply;
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
	 * Parse a categories object into a comma-separated list of tracker cat_id's.
	 *
	 * JsCalendar overrides parseCategories() to add categories in the calendar or
	 * InfoLog app; we override it again so tracker categories are resolved/created
	 * in the *tracker* app (static::APP), mirroring the generic JsBase behaviour.
	 *
	 * @param array $categories  category-name => true pairs
	 * @param bool  $multiple    false: only a single category is allowed (tracker default)
	 * @return ?string comma-separated cat_id's
	 * @throws Api\CalDAV\JsParseException
	 */
	protected static function parseCategories(array $categories, bool $multiple = false)
	{
		static $bo = null;
		$cat_ids = [];
		if ($categories)
		{
			if (count($categories) > 1 && !$multiple)
			{
				throw new Api\CalDAV\JsParseException("Only a single category is supported!");
			}
			if (!isset($bo)) $bo = new Api\Categories($GLOBALS['egw_info']['user']['account_id'], static::APP);
			foreach ($categories as $name => $true)
			{
				if (!($cat_id = $bo->name2id($name)))
				{
					$cat_id = $bo->add(['name' => $name, 'descr' => $name, 'access' => 'private']);
				}
				$cat_ids[] = $cat_id;
			}
		}
		return $cat_ids ? implode(',', $cat_ids) : null;
	}
}
