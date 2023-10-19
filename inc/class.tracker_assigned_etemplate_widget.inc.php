<?php
/**
 * EGroupware - eTemplate serverside of assigned user list widget
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package etemplate
 * @subpackage api
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @copyright 2016 Nathan Gray
 * @version $Id$
 */

use EGroupware\Api;
use EGroupware\Api\Link;
use EGroupware\Api\Etemplate;

/**
 * Assigned user
 *
 *  Needed because assignable users is different from the full user list so we want them separate
 *
 * The naming convention is <appname>_<subtype>_etemplate_widget
 */
class tracker_assigned_etemplate_widget extends Etemplate\Widget\Select
{
	/**
	 *  Make sure all the needed select options are there
	 *
	 * @param string $cname
	 * @param array $expand values for keys 'c', 'row', 'c_', 'row_', 'cont'
	 */
	public function beforeSendToClient($cname, array $expand = null)
	{
		$form_name = self::form_name($cname, $this->id, $expand);

		$value =& self::get_array(self::$request->content, $form_name);
		if(!$value)
		{
			return;
		}

		if(!is_array(self::$request->sel_options[$form_name]))
		{
			self::$request->sel_options[$form_name] = array();
		}
		$sel_options =& self::$request->sel_options[$form_name];
		$names = Link::titles('api-accounts', $value);
		foreach($value as $account)
		{
			$sel_options[] = ['value' => $account, 'label' => $names[$account] ?? " [$account] ?"];
		}

	}

	/**
	 * Handle ajax searches for owner across all supported resources
	 *
	 * @return Array List of matching results
	 */
	public static function ajax_search($search_text = null, array $search_options = [])
	{
		$bo = new tracker_bo();
		$query = strtolower($search_text ?? $_REQUEST['query']);
		$results = [];
		$staff = $bo->get_staff($search_options['tracker'] ?? 0, 2, $bo->allow_assign_users ? 'usersANDtechnicians' : 'technicians');

		foreach($staff as $account_id => $name)
		{
			if($query == $account_id || str_contains(strtolower($name), $query))
			{
				$results[] = ['value' => $account_id, 'label' => $name];
			}
		}

		$total = count($results);
		$results = array_slice($results, 0, Link::DEFAULT_NUM_ROWS);
		$results['total'] = $total;

		// switch regular JSON response handling off
		Api\Json\Request::isJSONRequest(false);

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($results);
		exit;
	}

	/**
	 * Validate input
	 *
	 * @param string $cname current namespace
	 * @param array $expand values for keys 'c', 'row', 'c_', 'row_', 'cont'
	 * @param array $content
	 * @param array &$validated =array() validated content
	 */
	public function validate($cname, array $expand, array $content, &$validated = array()) : void
	{
		$form_name = self::form_name($cname, $this->id, $expand);

		if(!$this->is_readonly($cname, $form_name))
		{
			$value = $value_in =& self::get_array($content, $form_name);
			if(!is_array($value))
			{
				$value = array($value);
			}
			$multiple = $this->attrs['multiple'] || $this->getElementAttribute($form_name, 'multiple') || $this->getElementAttribute($form_name, 'rows') > 1;

			$valid =& self::get_array($validated, $form_name, true);

			$bo = new tracker_bo();
			$tracker = $this->attrs['tracker'] ?? 0;
			$staff = $bo->get_staff($tracker, 2, $bo->allow_assign_users ? 'usersANDtechnicians' : 'technicians');

			$value = array_intersect($value, array_keys($staff));

			if(!$multiple && is_array($value) && count($value) > 1)
			{
				$value = array_shift($value);
			}
			if(isset($value))
			{
				self::set_array($validated, $form_name, $value);
			}
		}
	}
}

Etemplate\Widget::registerWidget(__NAMESPACE__ . '\\tracker_assigned_etemplate_widget', array('et2-tracker-assigned'));