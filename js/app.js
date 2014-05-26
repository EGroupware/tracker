/**
 * EGroupware - Tracker - Javascript UI
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @author Hadi Nategh	<hn-AT-stylite.de>
 * @copyright (c) 2008-13 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * UI for tracker
 *
 * @augments AppJS
 */
app.classes.tracker = AppJS.extend(
{
	appname: 'tracker',
	/**
	 * et2 widget container
	 */
	et2: null,
	/**
	 * path widget
	 */

	/**
	 * Constructor
	 *
	 * @memberOf app.tracker
	 */
	init: function()
	{
		// call parent
		this._super.apply(this, arguments);
	},

	/**
	 * Destructor
	 */
	destroy: function()
	{
		// call parent
		this._super.apply(this, arguments);
	},

	/**
	 * This function is called when the etemplate2 object is loaded
	 * and ready.  If you must store a reference to the et2 object,
	 * make sure to clean it up in destroy().
	 *
	 * @param et2 etemplate2 Newly ready object
	 */
	et2_ready: function(et2)
	{
		// call parent
		this._super.apply(this, arguments);
		if (et2.name === 'tracker.admin')
		{
			this.acl_queue_access();
		}

		if (et2.name === 'tracker.edit')
		{
			this.edit_popup();
		}
	},
	
	/**
	 * Observer method receives update notifications from all applications
	 * 
	 * @param {string} _msg message (already translated) to show, eg. 'Entry deleted'
	 * @param {string} _app application name
	 * @param {(string|number)} _id id of entry to refresh or null
	 * @param {string} _type either 'update', 'edit', 'delete', 'add' or null
	 * - update: request just modified data from given rows.  Sorting is not considered,
	 *		so if the sort field is changed, the row will not be moved.
	 * - edit: rows changed, but sorting may be affected.  Requires full reload.
	 * - delete: just delete the given rows clientside (no server interaction neccessary)
	 * - add: requires full reload for proper sorting
	 * @param {string} _msg_type 'error', 'warning' or 'success' (default)
	 * @param {object|null} _links app => array of ids of linked entries
	 * or null, if not triggered on server-side, which adds that info
	 */
	observer: function(_msg, _app, _id, _type, _msg_type, _links)
	{
		if (typeof _links['tracker'] != 'undefined')
		{	
			switch (_app)
			{
				case 'timesheet':
					var nm = this.et2 ? this.et2.getWidgetById('nm') : null;
					if (nm) nm.applyFilters();
					break;
			}
		}
	},
	
	/**
	 * expand_filter
	 * Used in escalations on buttons to change filters from a single select to a multi-select
	 *
	 * @param {object} _event
	 * @param {et2_baseWidget} _widget
	 *
	 * Note: It's important to consider the menupop widget needs to be always first child of
	 * buttononly's parent, since we are getting the right selectbox by orders
	 */
	multiple_assigned: function(_event, _widget)
	{
		_widget.set_disabled(true);

		var selectbox = _widget.getParent()._children[0]._children[0];
		selectbox.set_multiple(true);
		selectbox.set_tags(true, '98%');

		return false;
	},

	/**
	 * tprint
	 * @param _action
	 * @param _senders
	 */
	tprint: function(_action,_senders)
	{

		var id = _senders[0].id.split('::');
		if (_action.id === 'print')
		{
			var popup  = egw().open_link('/index.php?menuaction=tracker.tracker_ui.tprint&tr_id='+id[1],'',egw().link_get_registry('tracker','add_popup'),'tracker');
			popup.onload = function (){this.print();};
		}
	},

	/**
	 * edit_popup
	 * Check if the edit window is a popup, then set window focus
	 */
	edit_popup: function()
	{
		if (!this.et2.node.baseURI.match('[no][no_]popup'))
		{
			window.focus();
			if (this.et2.node.baseURI.match('composeid')) //tracker created by mail application
			{
				window.resizeTo(750,550);
			}
		}
	},

	/**
	 * canned_comment_request
	 *
	 */
	canned_comment_requst: function()
	{
		var editor = this.et2.getWidgetById('reply_message');
		var id = this.et2.getWidgetById('canned_response').get_value();
		if (id && editor)
		{
			// Need to specify the popup's egw
			this.et2.egw().json('tracker.tracker_ui.ajax_canned_comment',[id,document.getElementById('tracker-edit_reply_message').style.display == 'none']).sendRequest(true);
		}
	},
	/**
	 * canned_comment_response
	 * @param _replyMsg
	 */
	canned_comment_response: function(_replyMsg)
	{
		this.et2.getWidgetById('canned_response').set_value('');
		var editor = this.et2.getWidgetById('reply_message');
		if(editor)
		{
			editor.set_value(_replyMsg.replace(/(\r\n|\n|\r)/gm,""));
		}
	},
	/**
	 * acl_queue_access
	 */
	acl_queue_access: function()
	{

		var queue_acl = this.et2.getWidgetById('enabled_queue_acl_access');
		if(!queue_acl || queue_acl.get_value() === 'false')
		{

			this.et2.getWidgetById('users').set_disabled(true);
		}
		else
		{
			this.et2.getWidgetById('users').set_disabled(false);
		}
	}
});
