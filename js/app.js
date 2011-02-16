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

/**
 * Javascript handling for multiple entry actions
 */
function do_action(selbox) {
	if(selbox.value == "") return;
	var prefix = selbox.id.substring(0,selbox.id.indexOf('['));
	var popup = document.getElementById(prefix + '[' + selbox.value + '_popup]');
	if(popup) {
		popup.style.display = 'block';
		return;
	}
	selbox.form.submit();
	selbox.value = "";
}

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
