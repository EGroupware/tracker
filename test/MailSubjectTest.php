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

require_once realpath(__DIR__.'/../../api/src/test/AppTest.php');	// Application test base

use Egroupware\Api;

/**
 * Test for extracting and matching mail subjects with existing tickets
 *
 */
class MailSubjectMatchTest extends \EGroupware\Api\AppTest
{

	protected static $bo;
	protected static $tracker_ids;

	// Test patterns
	// Because the comparison looks for the ID, we replace # with the actual ID
	protected static $patterns = Array(
		// Tracker summary,
		//		test subject, should match
		Array('Test ticket', 'tests' => Array(
			['Test ticket', FALSE],
			['Not test ticket', FALSE],
			[' Test ticket #', FALSE],
			['Test #: Test ticket', TRUE],
			['RE: Test #: Test ticket', TRUE],
			['RE: Different Queue even #: Right ID, but totally wrong summary', FALSE]
		)),
		Array('This tracker has an extremely long summary, and that may cause us a bunch of problems.  Use the description for lots of long text, not the summary', 'tests' => Array(
			['Re: Ignored #: This tracker has an extremely long summary, and that may cause us a bunch of problems.  Use the description for lots of long text, not the summary', TRUE]
		)),
		// This from a client that had a problem with it
		Array('BDIP BV - DIESEL6000E SIL - 17/2013-65818935-006', 'tests' => Array(
			['RE: Breakdown #: BDIP BV - DIESEL6000E SIL - 17/2013-65818935-006', TRUE]
		)),
		// Summary is 72 characters long, mail subject is longer
		Array('Outilsud - VK37 38 - GENERATOR6000DE XL - 26/2011-61831101-002 - ????????', 'tests' => Array(
			['Breakdown #: Outilsud - VK37 38 - GENERATOR6000DE XL - 26/2011-61831101-002 - ????????', TRUE],
			// Match even if mail chops the subject
			['Breakdown #: Outilsud - VK37 38 - GENERATOR6000DE XL - 26/2011-61831101-002 - ???????', TRUE]
		)),
		Array('Stef Engelen T33K sn 07002714 AVP34892-01G onderhoud en nazicht in WP', 'tests' => Array(
			['Re: Breakdown #: Stef Engelen T33K sn 07002714 AVP34892-01G onderhoud en nazicht in WP', TRUE]
		))
	);

	/**
	 * Create the tickets to match against
	 */
	public static function setUpBeforeClass()
	{
		parent::setUpBeforeClass();

		// Create tickets to match against
		self::$bo = new \tracker_bo();
		self::$tracker_ids = [];

		foreach(self::$patterns as &$ticket)
		{
			list($summary) = $ticket;

			self::$tracker_ids[] = $tr_id = self::makeTracker($summary);

			foreach($ticket['tests'] as &$test)
			{
				$test[0] = str_replace('#','#'.$tr_id, $test[0]);
			}
		}
	}

	public static function tearDownAfterClass()
	{
		foreach(self::$tracker_ids as $tracker_id)
		{
			self::$bo->delete($tracker_id);
		}
	}
	/**
	 * Test various subject strings match (or not) an existing ticket
	 * 
	 */
	public function testSubject()
	{
		foreach(self::$patterns as $index =>  $pattern)
		{
			list($summary) = $pattern;
			foreach($pattern['tests'] as $test)
			{
				list($subject, $success) = $test;

				$this->assertEquals(
						$success ? self::$tracker_ids[$index] : 0,
						self::$bo->get_ticketId($subject),
						"$subject should" . ($success ? '' : ' not') . " match ticket ID " . self::$tracker_ids[$index] . ': ' . $summary
				);
			}
		}
	}


	protected static function makeTracker($subject)
	{
		self::$bo->data = array(
			'tr_summary'     => $subject,
			'tr_description' => 'Test ticket for matching mail subject to tracker summary',
			'tr_status'      => \tracker_bo::STATUS_OPEN,
			'tr_owner'       => $GLOBALS['egw_info']['user']['account_id']
		);
		self::$bo->save();
		return self::$bo->data['tr_id'];
	}
}
