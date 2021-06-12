/**
 * EGroupware - Tracker - Javascript UI
 *
 * @link https://www.egroupware.org
 * @package tracker
 * @author Hadi Nategh	<hn-AT-egroupware.org>
 * @author Ralf Becker <rb-AT-egroupware.org>
 * @copyright (c) 2008-21 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

import {EgwApp} from "../../api/js/jsapi/egw_app";
import {et2_nextmatch} from "../../api/js/etemplate/et2_extension_nextmatch";
import {et2_button} from "../../api/js/etemplate/et2_widget_button";
import {et2_selectbox} from "../../api/js/etemplate/et2_widget_selectbox";
import {etemplate2} from "../../api/js/etemplate/etemplate2";
import {et2_link_list} from "../../api/js/etemplate/et2_widget_link";
import {nm_open_popup} from "../../api/js/etemplate/et2_extension_nextmatch_actions.js";

/**
 * UI for tracker
 */
 class trackerAPP extends EgwApp
{

	// Filter push messages to see if we can ignore it
	protected push_filter_fields = ["tr_tracker", "tr_version","tr_creator","tr_assigned"];

	/**
	 * Constructor
	 */
	constructor()
	{
		super('tracker');
	}

	/**
	 * Destructor
	 */
	destroy(_app)
	{
		super.destroy(_app);
	}

	/**
	 * This function is called when the etemplate2 object is loaded
	 * and ready.  If you must store a reference to the et2 object,
	 * make sure to clean it up in destroy().
	 *
	 * @param {etemplate2} _et2
	 * @param {string} _name name of template loaded
	 */
	et2_ready(_et2, _name)
	{
		// call parent
		super.et2_ready(_et2, _name);

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
						let button = null;
						// Find associated expand button
						widget.getParent().getParent().iterateOver(function(widget) {button = widget;}, this, et2_button);
						this.multiple_assigned(false, button);
						widget.set_value(widget.options.value);
					}
				},this,et2_selectbox);
				break;
		}
	}

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
	observer(_msg, _app, _id, _type, _msg_type, _links)
	{
		if (typeof _links?.tracker != 'undefined')
		{
			if (_app === 'timesheet')
			{
				let nm = this.et2 ? <et2_nextmatch>this.et2.getWidgetById('nm') : null;
				if (nm) nm.applyFilters();
			}
		}
	}


	/**
	 * Retrieve the current state of the application for future restoration
	 *
	 * The state can be anything, as long as it's an object.  The contents are
	 * application specific.  Overriding the default implementation to always use
	 * the tracker list, not escalations.
	 * The return value of this function cannot be passed directly to setState(),
	 * since setState is expecting an additional wrapper, eg:
	 * {name: 'something', state: getState()}
	 *
	 * @return {object} Application specific map representing the current state
	 */
	getState() : {[propName:string]: any}
	{
		let state = {};

		// Try and find a nextmatch widget, and set its filters
		let et2 = etemplate2.getById('tracker-index');
		if(!et2) return {};
		et2.widgetContainer.iterateOver(function(_widget) {
				state = _widget.getValue();
			}, this, et2_nextmatch);

		return state;
	}

	/**
	 * Tracker list filter change, used to toggle date fields
	 */
	filter_change() : boolean
	{
		let filter = this.et2.getWidgetById('filter');
		let dates = this.et2.getWidgetById('tracker.index.dates');

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
	}

	/**
	 * User wants to share
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject} _selected
	 * @param _target
	 */
	share_link(_action, _selected, _target)
	{
		if(_action.id == 'shareWritableFilemanager')
		{
			// No checkbox for parent to find, explicitly set writable
			super.share_link(_action.parent.getActionById('shareFilemanager'), _selected, _target, true);
		}
		else
		{
			// Leave writable parameter undefined so parent can check
			super.share_link(_action, _selected, _target);
		}
	}

	/**
	 * Used in escalations on buttons to change filters from a single select to a multi-select
	 *
	 * @param {object} _event
	 * @param {et2_baseWidget} _widget
	 *
	 * Note: It's important to consider the menupop widget needs to be always first child of
	 * buttononly's parent, since we are getting the right selectbox by orders
	 */
	multiple_assigned(_event, _widget): boolean
	{
		_widget.set_disabled(true);

		let selectbox = _widget.getParent()._children[0];
		selectbox.set_multiple(true);
		selectbox.set_tags(true, '98%');
		return false;
	}

	/**
	 * tprint
	 * @param _action
	 * @param _senders
	 */
	tprint(_action,_senders)
	{

		let id = _senders[0].id.split('::');
		if (_action.id === 'print')
		{
			let popup : any = egw().open_link('/index.php?menuaction=tracker.tracker_ui.tprint&tr_id='+id[1],'', <string>egw().link_get_registry('tracker','add_popup'),'tracker');
			popup.onload = function (){this.print();};
		}
	}

	/**
	 * Check if the edit window is a popup, then set window focus
	 */
	edit_popup()
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
	}

	/**
	 * canned_comment_request
	 *
	 */
	canned_comment_requst()
	{
		let editor = this.et2.getWidgetById('reply_message');
		let id = this.et2.getWidgetById('canned_response').get_value();
		if (id && editor)
		{
			// Need to specify the popup's egw
			this.et2.egw().json('tracker.tracker_ui.ajax_canned_comment',[id,document.getElementById('tracker-edit_reply_message').style.display == 'none']).sendRequest(true);
		}
	}
	/**
	 * canned_comment_response
	 * @param _replyMsg
	 */
	canned_comment_response(_replyMsg)
	{
		this.et2.getWidgetById('canned_response').set_value('');
		let editor = this.et2.getWidgetById('reply_message');
		if(editor)
		{
			editor.set_value(_replyMsg);
		}
	}

	/**
	 * Update the UI to show the file after user adds a file to a comment
	 *
	 * @param {HTMLElement} dom_node
	 * @param {et2_widget} widget
	 * @returns {undefined}
	 */
	comment_add_vfs(dom_node, widget) {
		// Add the file into the existing list of files
		if(widget._type === 'vfs-select')
		{
			let upload = widget.getParent().getWidgetById(widget.options.method_id);

			// Could not find the upload widget
			if(!upload)
			{
				return;
			}
			let value = widget.get_value();
			for(let i in value)
			{
				upload._addFile({name: value[i], path: value[i]});
			}
		}

		// Update link widget on links tab
		widget.getRoot().iterateOver(
			function(widget) {
				widget._get_links();
			},
			this, et2_link_list
		);
	}

	/**
	 * acl_queue_access
	 *
	 * Enables or disables the Site configuration 'Staff'tab 'Users' widget
	 * based on the 'enabled_queue_acl_access' config setting
	 */
	acl_queue_access()
	{
		let queue_acl = this.et2.getWidgetById('enabled_queue_acl_access');

		// Check content too, in case we're viewing a specific queue and that widget
		// isn't there
		let content = this.et2.getArrayMgr('content').getEntry('enabled_queue_acl_access');
		if(queue_acl && queue_acl.get_value() === 'false' || content !== null && !content)
		{
			this.et2.getWidgetById('users').set_disabled(true);
		}
		else
		{
			this.et2.getWidgetById('users').set_disabled(false);
		}
	}

	/**
	 * Get title in order to set it as document title
	 * @returns {string}
	 */
	getWindowTitle()
	{
		let widget = this.et2.getWidgetById('tr_summary');
		if(widget) return widget.options.value;
	}

	/**
	 * Action handler for context menu change assigned action
	 *
	 * We populate the dialog with the current value.
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _selected
	 */
	change_assigned(_action, _selected)
	{
		let et2 = _selected[0].manager.data.nextmatch.getInstanceManager();
		let assigned = et2.widgetContainer.getWidgetById('assigned');
		if(assigned)
		{
			assigned.set_value([]);
			et2.widgetContainer.getWidgetById('assigned_action[title]').set_value('');
			et2.widgetContainer.getWidgetById('assigned_action[title]').set_class('');
			et2.widgetContainer.getWidgetById('assigned_action[ok]').set_disabled(_selected.length !== 1);
			et2.widgetContainer.getWidgetById('assigned_action[add]').set_disabled(_selected.length === 1);
			et2.widgetContainer.getWidgetById('assigned_action[delete]').set_disabled(_selected.length === 1);
		}

		if(_selected.length === 1)
		{
			let data = egw.dataGetUIDdata(_selected[0].id);

			if(assigned && data && data.data)
			{
				et2.widgetContainer.getWidgetById('assigned_action[title]').set_value(data.data.tr_summary);
				et2.widgetContainer.getWidgetById('assigned_action[title]').set_class(data.data.class);
				assigned.set_value(data.data.tr_assigned);
			}
		}

		nm_open_popup(_action, _selected);
	}

	/**
	 * Override the viewEntry to remove unseen class
	 * right after view the entry.
	 *
	 * @param {type} _action
	 * @param {type} _senders
	 */
	viewEntry(_action, _senders)
	{
		super.viewEntry(_action, _senders);
		let nm : et2_nextmatch = <et2_nextmatch>this.et2.getWidgetById('nm');
		let nm_indexes = nm.getController()._indexMap;
		let node : JQuery = null;
		for (let i in nm_indexes)
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
	}

	/**
	 * Handle context menu action on the comments to show the file buttons
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _entries
	 */
	reply_files(_action, _entries)
	{
		let row = null;
		for(let i in _entries)
		{
			row = _entries[i].iface.getDOMNode();
			jQuery('.et2_toolbar',row).removeClass('hide_buttons')
				.get(0).scrollIntoView();
		}
		jQuery("body").one('click',function() {
			jQuery('.et2_toolbar', row).addClass('hide_buttons');
		});
	}

	/**
	 * Handle context menu action on the comments to edit the comment
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _entries
	 */
	reply_edit(_action, _entries)
	{
		for(let i in _entries)
		{
			let row_id = _entries[i].id.split('row_')[1];
			if(typeof row_id !== 'string')
			{
				return;
			}
			let widget_id = row_id + '[reply_message]';
			let widget = _entries[i].iface.getWidget().getWidgetById(widget_id);

			// Trigger the edit mode
			widget.dblclick();
		}

	}
}

app.classes.tracker = trackerAPP;