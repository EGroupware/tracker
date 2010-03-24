<?php

if ($GLOBALS['egw_info']['user']['apps']['tracker'] && $GLOBALS['egw_info']['user']['preferences']['tracker']['homepage_display'])
{
	$save_app = $GLOBALS['egw_info']['flags']['currentapp'];
	$GLOBALS['egw_info']['flags']['currentapp'] = 'tracker';

	$GLOBALS['egw']->translation->add_app('tracker');

	$tracker = new tracker_ui();
	$queue = $GLOBALS['egw_info']['user']['preferences']['tracker']['queue'];
	$status = $GLOBALS['egw_info']['user']['preferences']['tracker']['status'];

	//if (in_array($showevents,array('1','2'))) $showevents = 'own-open-today';
	$html = $tracker->index(array('nm' => array('rows' => array('tr_status' => $status))),$queue,'',null,true);
	unset($tracker);

	$portalbox =& CreateObject('phpgwapi.listbox',array(
		'title'     => lang('Tracker'),
		'primary'   => $GLOBALS['egw_info']['theme']['navbar_bg'],
		'secondary' => $GLOBALS['egw_info']['theme']['navbar_bg'],
		'tertiary'  => $GLOBALS['egw_info']['theme']['navbar_bg'],
		'width'     => '100%',
		'outerborderwidth' => '0',
		'header_background_image' => $GLOBALS['egw']->common->image('phpgwapi/templates/default','bg_filler')
	));
	foreach(array('up','down','close','question','edit') as $key)
	{
		$portalbox->set_controls($key,Array('url' => '/set_box.php', 'app' => $app_id));
	}
	$portalbox->data = $data;

	if (!file_exists(EGW_SERVER_ROOT.($et_css_file ='/etemplate/templates/'.$GLOBALS['egw_info']['user']['preferences']['common']['template_set'].'/app.css')))
	{
		$et_css_file = '/etemplate/templates/default/app.css';
	}
	if (!file_exists(EGW_SERVER_ROOT.($css_file ='/tracker/templates/'.$GLOBALS['egw_info']['user']['preferences']['common']['template_set'].'/app.css')))
	{
		$css_file = '/tracker/templates/default/app.css';
	}
	echo '
<!-- BEGIN tracker info -->
<style type="text/css">
<!--
	@import url('.$GLOBALS['egw_info']['server']['webserver_url'].$et_css_file.');
	@import url('.$GLOBALS['egw_info']['server']['webserver_url'].$css_file.');
-->
</style>
'.	$portalbox->draw($html)."\n<!-- END tracker info -->\n";

	unset($css_file); unset($et_css_file);
	unset($portalbox);
	unset($html);
	$GLOBALS['egw_info']['flags']['currentapp'] = $save_app;
}
