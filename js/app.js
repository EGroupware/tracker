/**
 * Tracker - JavaScript
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb@stylite.de>
 * @package tracker
 * @copyright (c) 2010 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

function add_email_from_ab(ab_id,tr_cc)
{
	var ab = document.getElementById(ab_id); 
	
	if (!ab || !ab.value)
	{
		set_style_by_class('tr','hiddenRow','display','block');
	}
	else
	{
		var cc = document.getElementById(tr_cc); 
		
		for(var i=0; i < ab.options.length && ab.options[i].value != ab.value; ++i) ; 
		
		if (i < ab.options.length)
		{
			cc.value += (cc.value?', ':'')+ab.options[i].text.replace(/^.* <(.*)>$/,'$1');
			ab.value = '';
			ab.onchange();
			set_style_by_class('tr','hiddenRow','display','none');
		}
	}
	return false;
}

var tracker_popup_action, tracker_popup_senders;

/**
 * Open popup for a certain action requiring further input
 * 
 * @param _action
 * @param _senders
 */
function open_popup(_action, _senders)
{
	var prefix = 'exec';
	var popup = document.getElementById(prefix + '[' + _action.id + '_popup]');

	if (popup) {
		tracker_popup_action = _action;
		tracker_popup_senders = _senders;
		popup.style.display = 'block';
	}
}

/**
 * Submit a popup action
 */
function submit_popup(button)
{
	button.form.submit_button.value = button.name;	// set name of button (sub-action)

	// call regular nm_action to transmit action and senders correct
	nm_action(tracker_popup_action, tracker_popup_senders);
}

/**
 * Hide popup
 */
function hide_popup(element, div_id) 
{
	var prefix = element.id.substring(0,element.id.indexOf('['));
	var popup = document.getElementById(prefix+'['+div_id+']');

	// Hide popup
	if(popup) {
		popup.style.display = 'none';
	}
	return false;
}
