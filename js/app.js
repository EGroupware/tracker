/**
 * Tracker - JavaScript
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <rb@stylite.de>
 * @package tracker
 * @copyright (c) 2010-11 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

"use strict";

/*egw:uses
        /phpgwapi/js/jsapi/app_base; 
*/

jQuery.extend(window, {
	add_email_from_ab: function(ab_id,tr_cc)
	{
		var ab = document.getElementById(ab_id); 
		
		if (!ab || !ab.value)
		{
			$j('tr.hiddenRow').css('display','table-row');
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
				$j('tr.hiddenRow').css('display','none');
			}
		}
		return false;
	},

	/**
	 * Used in escalations on buttons to change filters from a single select to a multi-select
	 */
	expand_filter: function(filter,widget)
	{
		$j(this).hide();
		var selectbox=document.getElementById(filter)
		if ($j('[id="'+filter+'"]').length != 1 && typeof widget != "undefined" && widget != null)
		{
			selectbox = widget.getParent().getWidgetById(filter).getInputNode();
			widget.getParent().getWidgetById(filter).set_tags(true);
		}
		else if (selectbox)
		{
			selectbox.name+='[]';
		}
		
		if($j().chosen)
		{
			$j(selectbox).unchosen();
		}
		selectbox.size=3;
		selectbox.multiple=true;
		if(selectbox.options[0].value=='')
		{
			selectbox.options[0]=null;
		}
		if($j().chosen) $j(selectbox).chosen();

		return false;
	}
});
