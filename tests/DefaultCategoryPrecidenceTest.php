<?php

/**
 * Tracker test for checking that the various combinations of site configuration,
 * defaults (for all and per tracker) and preferences (for all and per tracker)
 * result in the correct initial settings for a new ticket.
 *
 * Site configuration (All) < Site config (Tracker) < Preference < Current nextmatch
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package tracker
 * @copyright (c) 2017  Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @ticket 23706
 */

namespace Egroupware\Tracker;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');	// Application test base

use Egroupware\Api\Categories;
use Egroupware\Api\Etemplate;

class DefaultCategoryPrecidenceTest extends \EGroupware\Api\AppTest
{
	/**
	 * Hold on to the previous global default, so it can be restored after
	 */
	protected $previous_default;

	/**
	 * Keep track of the tracker ID so even if the test fails, we can remove it
	 * in tearDown()
	 *
	 * @var tr_id
	 */
	protected $tr_id;

	/**
	 * Which field is being tested
	 */
	protected $field = 'cat_id';

	/**
	 * We need one tracker queue to test global default, but only need one more
	 * to test tracker default, preference and state
	 */
	protected $global_tracker;
	protected $other_tracker;

	/**
	 * Values for the different settings, need different ones for each so
	 * we can tell them apart.
	 */
	public static $admin_all = '';
	public static $admin_tracker = '';
	public static $preference = '';
	public static $filter_setting = '';

	public static function setUpBeforeClass()
	{
		// Test works on its own with this, but fails with the rest.
		// There's no good reason commenting this out should work.
		parent::setUpBeforeClass();

	}
	public static function tearDownAfterClass()
	{

		parent::tearDownAfterClass();
	}

	/**
	 * Create the various options needed for the test(s)
	 */
	public function createTestOptions()
	{
		$cats = new Categories(Categories::GLOBAL_ACCOUNT,'tracker');
		$trackers = $this->ui->get_tracker_labels();
		$this->global_tracker = key($trackers);
		next($trackers);
		$this->other_tracker = key($trackers);

		$cat = array(
			'access' => 'public',
			'data'   => array('type' => 'cat'),
			'description'  => 'Test',
		);

		static::$admin_all = $cats->add($cat + array(
			'name' => 'Admin All',
			'data' => array('type' => 'cat', 'isdefault' => true)
		));
		static::$admin_tracker = $cats->add($cat + array(
			'name' => 'Admin Tracker',
			'parent' => $this->other_tracker,
			'data' => array('type' => 'cat', 'isdefault' => true)
		));
		static::$preference = $cats->add($cat + array('name' => 'Preference'));
		static::$filter_setting = $cats->add($cat + array('name' => 'Current filter'));
		\Egroupware\Api\Cache::setSession('tracker','index',$state);

		// Need to reset before they're available
		$this->ui->all_cats = null;
		$this->ui->load_config();
		$this->ui->init();
	}

	/**
	 * Delete the various options created for the test(s)
	 */
	public static function deleteTestOptions()
	{
		$cats = new Categories(Categories::GLOBAL_ACCOUNT,'tracker');
		$cats->delete(static::$admin_all);
		$cats->delete(static::$admin_tracker);
		$cats->delete(static::$preference);
		$cats->delete(static::$filter_setting);
	}

	public function setUp()
	{
		parent::setUp();

		$this->ui = new \tracker_ui();
		$this->ui->template = $this->createPartialMock(Etemplate::class, array('exec'));

		// Create testing options
		$this->createTestOptions();

		// Clear default
		$this->clearDefault();
		$this->clearDefault($this->global_tracker);
		$this->clearDefault($this->other_tracker);

	}

	public function tearDown()
	{
		parent::tearDown();

		// Restore defaults
		foreach($this->previous_default as $tracker => $default)
		{
			$this->ui->set_default_category($tracker, $default);
		}

		// Clear these so they don't override other tests
		$trackers = $this->ui->get_tracker_labels();
		$tracker = key($trackers);
		$this->setPreference($tracker, 0);
		$this->setState(0);

		// Remove test options
		$this->deleteTestOptions();

		$this->ui = null;
	}

	/**
	 * No default set, should go to the first defined category
	 */
	public function testNoDefault()
	{
		$cats = $this->ui->get_tracker_labels('cat');
		$expected = key($cats);
		$this->ui->data = array();

		// Mock the etemplate call to check the results
		$this->ui->template->expects($this->once())
			->method('exec')
			->will(
				$this->returnCallback(function($method, $content) {
					$this->assertEquals($cats, $content[$this->field],
							$this->makeMessage($cats, $content[$this->field])
					);
					return true;
				})
			);

		// Make a call to edit, looks like initial load
		$this->ui->edit();
	}

	/**
	 * Test that global default works
	 */
	public function testAllTrackersDefault()
	{
		// Set global default
		$this->ui->set_default_category(false, static::$admin_all);

		// Mock the etemplate call to check the results
		$this->ui->template->expects($this->exactly(2))
			->method('exec')
			->will(
				$this->returnCallback(function($method, $content) {
					$this->assertEquals(static::$admin_all, $content[$this->field],
							$this->makeMessage(static::$admin_all, $content[$this->field])
					);
					return true;
				})
			);

		// Set up to use the tracker with global default
		$this->ui->data['tr_tracker'] = $this->global_tracker;
		// Make a call to edit, looks like initial load
		$this->ui->edit();

		// Check the other tracker
		$this->ui->data['tr_tracker'] = $this->other_tracker;
		// Make a call to edit, looks like initial load
		$this->ui->edit();
	}

	/**
	 * Check that tracker specific default overrides global default
	 */
	public function testTrackerSpecificDefault()
	{
		// Set global default
		$this->ui->set_default_category(false, static::$admin_all);
		// Set tracker default
		$this->ui->set_default_category($this->other_tracker, static::$admin_tracker);


		// Check can only call expects once, so callback needs an if
		$this->ui->template->expects($this->exactly(2))
			->method('exec')
			->will(
				$this->returnCallback(function($method, $content) {
					if($content['tr_tracker'] == $this->global_tracker)
					{
						// Global default
						$this->assertEquals(static::$admin_all, $content[$this->field],
								$this->makeMessage(static::$admin_all, $content[$this->field])
						);
					}
					else if ($content['tr_tracker'] == $this->other_tracker)
					{
						// Tracker default
						$this->assertEquals(static::$admin_tracker, $content[$this->field],
								$this->makeMessage(static::$admin_tracker, $content[$this->field])
						);
					}
					return true;
				})
			);

		// Set up to use the tracker with global default
		$this->ui->data['tr_tracker'] = $this->global_tracker;
		// Make a call to edit, looks like initial load
		$this->ui->edit();


		// Set up to use the other tracker with tracker default
		$this->ui->data['tr_tracker'] = $this->other_tracker;

		// Make a call to edit, looks like initial load
		$this->ui->edit();
	}

	/**
	 * Check that preference overrides global default & tracker specific default
	 */
	public function testPreferenceDefault()
	{
		// Set global default
		$this->ui->set_default_category(false, static::$admin_all);
		// Set tracker default
		$this->ui->set_default_category($this->other_tracker, static::$admin_tracker);
		// Set preference
		$this->setPreference($this->other_tracker, static::$preference);

		// Check can only call expects once, so callback needs an if
		$this->ui->template->expects($this->exactly(2))
			->method('exec')
			->will(
				$this->returnCallback(function($method, $content) {
					if($content['tr_tracker'] == $this->global_tracker)
					{
						// Global default
						$this->assertEquals(static::$admin_all, $content[$this->field],
								$this->makeMessage(static::$admin_all, $content[$this->field])
						);
					}
					else if ($content['tr_tracker'] == $this->other_tracker)
					{
						// Preference
						$this->assertEquals(static::$preference, $content[$this->field],
								$this->makeMessage(static::$preference, $content[$this->field])
						);
					}
					return true;
				})
			);

		// Set up to use the tracker with global default
		$this->ui->data['tr_tracker'] = $this->global_tracker;
		// Make a call to edit, looks like initial load
		$this->ui->edit();


		// Set up to use the other tracker with tracker default
		$this->ui->data['tr_tracker'] = $this->other_tracker;

		// Make a call to edit, looks like initial load
		$this->ui->edit();
	}

	/**
	 * Check that the current filter (in nextmatch) overrides all
	 */
	public function testFilterDefault()
	{
		// Set global default
		$this->ui->set_default_category(false, static::$admin_all);
		// Set tracker default
		$this->ui->set_default_category($this->other_tracker, static::$admin_tracker);
		// Set preference
		$this->setPreference($this->other_tracker, static::$preference);
		// Set filter
		$this->setState(static::$filter_setting);

		// Check can only call expects once, so callback needs an if
		$this->ui->template->expects($this->exactly(2))
			->method('exec')
			->will(
				$this->returnCallback(function($method, $content) {
					if($content['tr_tracker'] == $this->global_tracker)
					{
						// Global default overridden
						$this->assertEquals(static::$filter_setting, $content[$this->field],
								$this->makeMessage(static::$filter_setting, $content[$this->field])
						);
					}
					else if ($content['tr_tracker'] == $this->other_tracker)
					{
						// Current filter
						$this->assertEquals(static::$filter_setting, $content[$this->field],
								$this->makeMessage(static::$filter_setting, $content[$this->field])
						);
					}
					return true;
				})
			);

		// Set up to use the tracker with global default
		$this->ui->data['tr_tracker'] = $this->global_tracker;
		// Make a call to edit, looks like initial load
		$this->ui->edit();


		// Set up to use the other tracker with tracker default
		$this->ui->data['tr_tracker'] = $this->other_tracker;

		// Make a call to edit, looks like initial load
		$this->ui->edit();
	}

	protected function makeMessage($expected, $actual)
	{
		$message = 'Expected ';
		switch($expected)
		{
			case static::$admin_all: $message.='admin_all'; break;
			case static::$admin_tracker: $message.='admin_tracker'; break;
			case static::$preference: $message.='preference'; break;
			case static::$filter_setting: $message.='filter setting'; break;
			default:
				$message.=' unknown value (' . Categories::id2name($expected).')';
				break;
		}
		$message .= ' but got ';
		switch($actual)
		{
			case static::$admin_all: $message.='admin_all'; break;
			case static::$admin_tracker: $message.='admin_tracker'; break;
			case static::$preference: $message.='preference'; break;
			case static::$filter_setting: $message.='filter setting'; break;
			default:
				$message.=' unknown value (' . Categories::id2name($actual).')';
				break;
		}
		return $message;
	}

	/**
	 * Set the state filter, which should have the highest precidence
	 *
	 * @param int $value
	 */
	protected function setState($value)
	{
		$state = array(
			$this->field	=>	$value
		);
		\Egroupware\Api\Cache::setSession('tracker','index',$state);
	}

	/**
	 * Set the preference, which should have the second highest precidence
	 */
	protected function setPreference($tracker, $value)
	{
		 $GLOBALS['egw_info']['user']['preferences']['tracker'][$tracker.'_cat_default'] = $value;
	}

	/**
	 * Clear a default
	 */
	protected function clearDefault($tracker = null)
	{
		$this->ui->get_tracker_labels('cat', $tracker, $this->previous_default[$tracker]);
		if($this->previous_default[$tracker])
		{
			$this->ui->set_default_category($tracker);
		}
		// Need to reset before they're available
		$this->ui->all_cats = null;
		$this->ui->load_config();
		$this->ui->init();
	}
}