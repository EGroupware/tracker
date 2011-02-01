<?php
/**
 * Tracker - Universal tracker (bugs, feature requests, ...) with voting and bounties
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @author Nathan Gray
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

require_once(EGW_INCLUDE_ROOT . '/admin/inc/class.customfields.inc.php');
/**
* Customfields class - manages customfields for tracker, with per-queue fields
*/
class tracker_customfields extends customfields
{
	var $public_functions = array(
		'edit' => True
	);

	/**
	 * Constructor
	 *
	 */
	function __construct()
	{
		parent::__construct('tracker');
	}

	/**
	 * Edit/Create Custom fields with type
	 *
	 * @param $content Content from eTemplate 
	 */
	function edit($content = array())
	{
		$this->tmpl = new etemplate();

		$this->fields = config::get_customfields($this->appname,true);
		$this->tmpl->read('tracker.customfields');

		$this->content_types = config::get_content_types($this->appname);

		// Make sure all queues are in there
                $tracker = new tracker_bo();
                $queues = $tracker->get_tracker_labels();
                foreach($queues as $id => $name)
		{
			if(!$this->content_types[$id]) $this->content_types[$id] = array('name' => $name);
		}

		if ($content)
		{
			if($content['fields']['delete']) $this->delete_field($content);
			elseif($content['fields']['create']) $this->create_field($content);
			else
			{
				list($action) = @each($content['button']);
				switch($action)
				{
					default:
						if (!$content['fields']['create'] && !$content['fields']['delete'])
						{
							break;	// type change
						}
					case 'save':
					case 'apply':
						$this->update($content);
						if ($action != 'save')
						{
							break;
						}
					case 'cancel':
						$GLOBALS['egw']->redirect_link($content['referer'] ? $content['referer'] : '/admin/index.php');
						exit;
				}
			}
			$referer = $content['referer'];
		}
		else
		{
			$content['use_private'] = (boolean)$_GET['use_private'];

			$referer = $GLOBALS['egw']->common->get_referer();
		}
		$GLOBALS['egw_info']['flags']['app_header'] = $GLOBALS['egw_info']['apps'][$this->appname]['title'].' - '.lang('Custom fields');
		$readonlys = array();

		foreach($this->content_types as $type => $entry)
		{
			$this->types2[$type] = $entry['name'];
		}

		$content['fields'] = array('use_private' => $content['use_private']);
		$n = 0;
		foreach($this->fields as $name => $data)
		{
			if(!is_array($data))
			{
				$data = array();
				$data['label'] = $name;
				$data['order'] = ($n+1) * 10;
			}
			if (is_array($data['values']))
			{
				$values = '';
				foreach($data['values'] as $var => $value)
				{
					$values .= (!empty($values) ? "\n" : '').$var.'='.$value;
				}
				$data['values'] = $values;
			}
			$content['fields'][++$n] = (array)$data + array(
				'name'   => $name
			);
			$preserv_fields[$n]['old_name'] = $name;
			$readonlys['fields']["create$name"] = True;
		}
		$content['fields'][++$n] = array('name'=>'','order' => 10 * $n);	// new line for create
		$content['fields']['type2'] = 'enable';

		$readonlys['fields']["delete[]"] = True;
		$sel_options = array(
			'type2' => $this->types2
		);
		$this->tmpl->exec('tracker.tracker_customfields.edit',$content,$sel_options,$readonlys,array(
			'fields' => $preserv_fields,
			'appname' => $this->appname,
			'referer' => $referer,
			'use_private' => $content['use_private'],
		));
	}
}
