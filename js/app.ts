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
import {nm_open_popup} from "../../api/js/etemplate/et2_extension_nextmatch_actions.js";
import {egw} from "../../api/js/jsapi/egw_global";
import {et2_template} from "../../api/js/etemplate/et2_widget_template";
import {et2_htmlarea} from "../../api/js/etemplate/et2_widget_htmlarea";
import {et2_checkbox} from "../../api/js/etemplate/et2_widget_checkbox";
import {et2_selectAccount} from "../../api/js/etemplate/et2_widget_selectAccount";
import "./Et2TrackerAssigned.ts";
import {Et2Dialog} from "../../api/js/etemplate/Et2Dialog/Et2Dialog";
import {LitElement} from "lit";
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {Et2ButtonToggle} from "../../api/js/etemplate/Et2Button/Et2ButtonToggle";

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
	 * Overwritten to fix previously used "0" instead of "" for filter and filter2
	 *
	 * @param {{name: string, state: object}|string} state Object (or JSON string) for a state.
	 *	Only state is required, and its contents are application specific.
	 * @return {{name: string, state: object}} state Object (or JSON string) for a state.
	 */
	fixState(state): { name: string, state: object, group: number|false }
	{
		state = super.fixState(state);

		// fix old state uses '0' instead of '' for all/empty
		if (state.state?.filter === '0') state.state.filter = '';
		if (state.state?.filter2 === '0') state.state.filter2 = '';

		return state;
	}

	/**
	 * Enable or disable the date filter
	 *
	 * If the filter is set to something that needs dates, we open the
	 * filter-box and show start- and endtime.
	 *
	 * @param ev
	 * @param filter
	 */
	filter_change(ev : Event, filter : Et2Select)
	{
		const dates = this.et2.getWidgetById('tracker.index.dates');
		if (filter && dates)
		{
			dates.set_disabled(filter.value !== "custom");
			if (!filter.value) this.nm.activeFilters.startdate = null;
			if (filter.value === "custom")
			{
				const filterDrawer = filter.closest('egw-app').filtersDrawer;
				if (filterDrawer && !filterDrawer.open)
				{
					filterDrawer.open = true;
				}
				window.setTimeout(() => dates.getWidgetById('startdate').focus());
			}
		}
		return true;
	}

	/**
	 * Show only unread has been clicked
	 */
	toggleUnread(_ev : Event, _widget : Et2ButtonToggle)
	{
		this.nm && this.nm.applyFilters({col_filter: {read: _widget.value ? '0' : ''}});
	}

	/**
	 * Check if any NM filter or search in app-toolbar needs to be updated to reflect NM internal state
	 *
	 * @param app_toolbar
	 * @param id
	 * @param value
	 */
	checkNmFilterChanged(app_toolbar, id : string, value : string)
	{
		super.checkNmFilterChanged(app_toolbar, id, value);

		switch (id)
		{
			case 'read':
				const unread_toggle = this.et2.getWidgetById('read');
				if (unread_toggle && unread_toggle.value != (value === '0')) {
					unread_toggle.value = value === '0';
				}
				break;
			case 'filter':
				this.filter_change(null, this.et2.getWidgetById(id));
				break;
		}
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
		let id = this.et2.getValueById('canned_response');
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
		(<et2_selectbox> this.et2.getWidgetById('canned_response')).set_value('');
		let editor = <et2_htmlarea>this.et2.getWidgetById('add_comment[reply_message]');
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
		const wait = [];
		// Add the file into the existing list of files
		widget.getInstanceManager().widgetContainer.querySelectorAll('et2-link-list').forEach(link =>
		{
			link.get_links();
			wait.push(link.updateComplete)
		});

		// Update link list widgets (including on links tab)
		this.et2.querySelectorAll('et2-link-list').forEach(link =>
		{
			link.get_links();
			wait.push(link.updateComplete)
		});
		// Files have been put where they need to be, clear widget value
		Promise.all([wait, wait]).then(() => {widget.value = null});
	}

	/**
	 * acl_queue_access
	 *
	 * Enables or disables the Site configuration 'Staff'tab 'Users' widget
	 * based on the 'enabled_queue_acl_access' config setting
	 */
	acl_queue_access()
	{
		let queue_acl = <et2_checkbox> this.et2.getWidgetById('enabled_queue_acl_access');

		// Check content too, in case we're viewing a specific queue and that widget
		// isn't there
		let content = this.et2.getArrayMgr('content').getEntry('enabled_queue_acl_access');
		if(queue_acl && queue_acl.get_value() === 'false' || content !== null && !content)
		{
			(<et2_selectAccount> this.et2.getWidgetById('users')).set_disabled(true);
		}
		else
		{
			(<et2_selectAccount> this.et2.getWidgetById('users')).set_disabled(false);
		}
	}

	/**
	 * Get title in order to set it as document title
	 * @returns {string}
	 */
	getWindowTitle()
	{
		return this.et2.getValueById('tr_summary');
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
		let assigned = <Et2TrackerAssigned>et2.widgetContainer.getWidgetById('assigned');
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
	 * Handle context menu action on the comments to edit the comment
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _entries
	 */
	reply_edit(_action, _entries)
	{
		let data = this.egw.dataGetUIDdata(_entries[0].id)?.data ?? {};

		// If you have more than one edit dialog open, we need the right data
		let instance = _entries[0].manager?.data?.context?.tracker?.et2.getInstanceManager() ?? this.et2.getInstanceManager();

		// Create dialog
		let dialog = this.editCommentDialog(
			instance.etemplate_exec_id,
			_entries[0].id, {
			...data,
				tr_edit_mode: instance.widgetContainer.getArrayMgr("content").getEntry("tr_edit_mode")
		});
		dialog.updateComplete.then(() => {dialog.querySelector('textarea')?.focus();});

		// Update reply
		dialog.getComplete().then(async([button, value]) =>
		{
			if(!button)
			{
				return;
			}
			let result = await this.egw.request("tracker_ui::ajax_update_reply",
				[value.reply_message, data.tr_id, data.reply_id]
			);

			// Update the row
			this.egw.dataRefreshUID(_entries[0].id);
		});
	}

	protected editCommentDialog(etemplate_exec_id : string, comment_id : string, data) : Et2Dialog
	{
		let dialog = <Et2Dialog><unknown>document.createElement('et2-dialog');
		dialog._setApiInstance(this.egw);
		dialog.transformAttributes({
			title: this.egw.lang('Edit comment'),
			id: "tracker-edit-comment-dialog",
			buttons: Et2Dialog.BUTTONS_OK_CANCEL,
			isModal: true,
			destroyOnClose: false,
			value: {
				etemplate_exec_id: etemplate_exec_id,
				content: data
			},
			template: "tracker.edit.comment_edit"
		});
		// Stop [Enter] key from closing the dialog
		dialog.updateComplete.then(() =>
		{
			dialog.querySelector("#tracker-edit-comment_edit").addEventListener("keyup", (e) => {e.stopImmediatePropagation()});
		})
		document.body.appendChild(<LitElement><unknown>dialog);
		dialog.getComplete().then(([button, value]) =>
		{
			// Carefully clear template preserving session
			dialog.eTemplate.clear(true, true);
			dialog.remove();
		})
		return dialog;
	}

	/**
	 * View a list of timesheets for the linked tracker entry
	 *
	 * Only one tracker entry at a time is allowed, we just pick the first one
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _selected
	 */
	timesheet_list(_action, _selected)
	{
		var extras = {
			link_app: 'tracker',
			link_id: false
		};
		for(var i = 0; i < _selected.length; i++)
		{
			// Remove UID prefix for just contact_id
			var ids = _selected[i].id.split('::');
			ids.shift();
			ids = ids.join('::');

			extras.link_id = ids;
			break;
		}

		egw.open("", "timesheet", "list", extras, 'timesheet');
	}
}

app.classes.tracker = trackerAPP;