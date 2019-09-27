/**
 * EGroupware - Tracker - Javascript UI
 *
 * @link http://www.egroupware.org
 * @package tracker
 * @author Hadi Nategh	<hn-AT-stylite.de>
 * @copyright (c) 2008-16 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * UI for tracker
 *
 * @augments AppJS
 */
app.classes.tracker = (function(){ "use strict"; return AppJS.extend(
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
	 * @param {etemplate2} _et2
	 * @param {string} _name name of template loaded
	 */
	et2_ready: function(_et2, _name)
	{
		// call parent
		this._super.apply(this, arguments);

		switch(_name)
		{
			case 'tracker.admin':
				this.acl_queue_access();
				break;

			case 'tracker.edit':
				this.edit_popup();
				break;

			case 'tracker.index':
				this.filter_change();
				if (this.et2.getArrayMgr('content').getEntry('nm[only_tracker]'))
					// there's no this.et2.getWidgetById('colfilter[tr_tracker]').hide() and
					// jQuery(this.et2.getWidgetById('colfilter[tr_tracker]').getDOMNode()).hide()
					// hides already hiden selectbox and not the choosen container :(
					jQuery('#tracker_index_col_filter_tr_tracker__chzn').hide();
				break;
			case 'tracker.escalations':
				// Set any filters with multiple values to multiple
				_et2.widgetContainer.getWidgetById('escalation').iterateOver(function(widget) {
					if( typeof widget.options.value === 'object' && widget.options.value.length > 1)
					{
						var button = null;
						// Find associated expand button
						widget.getParent().getParent().iterateOver(function(widget) {button = widget;}, this, et2_button);
						this.multiple_assigned(false, button);
						widget.set_value(widget.options.value);
					}
				},this,et2_selectbox);
				break;
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
		if (typeof _links != 'underfined')
		{
			if (typeof _links.tracker != 'undefined')
			{
				switch (_app)
				{
					case 'timesheet':
						var nm = this.et2 ? this.et2.getWidgetById('nm') : null;
						if (nm) nm.applyFilters();
						break;
				}
			}
		}
	},

	/**
	 * Tracker list filter change, used to toggle date fields
	 */
	filter_change: function()
	{
		var filter = this.et2.getWidgetById('filter');
		var dates = this.et2.getWidgetById('tracker.index.dates');

		if (filter && dates)
		{
			dates.set_disabled(filter.getValue() !== "custom");
			if (filter.value == "custom")
			{
				window.setTimeout(function() {
					jQuery(this.et2.getWidgetById('startdate').getDOMNode()).find('input').focus();
				}.bind(this), 100);
			}
		}
		return true;
	},

	/**
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
	 * Check if the edit window is a popup, then set window focus
	 */
	edit_popup: function()
	{
		if (typeof this.et2.node !='undefined' && typeof this.et2.node.baseURI != 'undefined')
		{
			if (!this.et2.node.baseURI.match(/no_?popup/))
			{
				window.focus();

				if (this.et2.node.baseURI.match('composeid')) //tracker created by mail application
				{
					window.resizeTo(750,550);
				}
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
			editor.set_value(_replyMsg);
		}
	},
	/**
	 * acl_queue_access
	 *
	 * Enables or disables the Site configuration 'Staff'tab 'Users' widget
	 * based on the 'enabled_queue_acl_access' config setting
	 */
	acl_queue_access: function()
	{
		var queue_acl = this.et2.getWidgetById('enabled_queue_acl_access');

		// Check content too, in case we're viewing a specific queue and that widget
		// isn't there
		var content = this.et2.getArrayMgr('content').getEntry('enabled_queue_acl_access');
		if(queue_acl && queue_acl.get_value() === 'false' || content !== null && !content)
		{
			this.et2.getWidgetById('users').set_disabled(true);
		}
		else
		{
			this.et2.getWidgetById('users').set_disabled(false);
		}
	},

	/**
	 * Get title in order to set it as document title
	 * @returns {string}
	 */
	getWindowTitle: function()
	{
		var widget = this.et2.getWidgetById('tr_summary');
		if(widget) return widget.options.value;
	},

	/**
	 * Action handler for context menu change assigned action
	 *
	 * We populate the dialog with the current value.
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _selected
	 */
	change_assigned: function(_action, _selected)
	{
		var et2 = _selected[0].manager.data.nextmatch.getInstanceManager();
		var assigned = et2.widgetContainer.getWidgetById('assigned');
		if(assigned)
		{
			assigned.set_value([]);
			et2.widgetContainer.getWidgetById('assigned_action[title]').set_value('');
			et2.widgetContainer.getWidgetById('assigned_action[title]').set_class('');
			et2.widgetContainer.getWidgetById('assigned_action[ok]').set_disabled(_selected.length !== 1);
			et2.widgetContainer.getWidgetById('assigned_action[add]').set_disabled(_selected.length === 1)
			et2.widgetContainer.getWidgetById('assigned_action[delete]').set_disabled(_selected.length === 1)
		}

		if(_selected.length === 1)
		{
			var data = egw.dataGetUIDdata(_selected[0].id);

			if(assigned && data && data.data)
			{
				et2.widgetContainer.getWidgetById('assigned_action[title]').set_value(data.data.tr_summary);
				et2.widgetContainer.getWidgetById('assigned_action[title]').set_class(data.data.class)
				assigned.set_value(data.data.tr_assigned);
			}
		}

		nm_open_popup(_action, _selected);
	},

	/**
	 * Override the viewEntry to remove unseen class
	 * right after view the entry.
	 *
	 * @param {type} _action
	 * @param {type} _senders
	 */
	viewEntry: function (_action, _senders)
	{
		this._super.apply(this, arguments);
		var nm = this.et2.getWidgetById('nm');
		var nm_indexes = nm.controller._indexMap;
		var node = '';
		for (var i in nm_indexes)
		{
			if (nm_indexes[i]['uid'] == _senders[0]['id'])
			{
				node = nm_indexes[i].row._nodes[0].find('.tracker_unseen');
			}
		}

		if (node)
		{
			node.removeClass('tracker_unseen');
		}
	},

	/**
	 * Handle context menu action on the comments to show the file buttons
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _entries
	 */
	reply_files: function(_action, _entries)
	{
		for(var i in _entries)
		{
			var row = _entries[i].iface.getDOMNode();
			jQuery('.et2_toolbar',row).removeClass('hide_buttons')
					.get(0).scrollIntoView();
		}
		jQuery("body").one('click',function() {
				jQuery('.et2_toolbar', row).addClass('hide_buttons');
			});
	},

	/**
	 * Handle context menu action on the comments to edit the comment
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _entries
	 */
	reply_edit: function(_action, _entries)
	{
		for(var i in _entries)
		{
			var row_id = _entries[i].id.split('row_')[1];
			if(typeof row_id !== 'string')
			{
				return;
			}
			var widget_id = row_id + '[reply_message]';
			var widget = _entries[i].iface.getWidget().getWidgetById(widget_id);

			// Trigger the edit mode
			widget.dblclick();
		}

	}
});}).call(this);
