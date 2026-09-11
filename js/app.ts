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
// et2_selectbox is now a shim (`class et2_selectbox extends Et2Select {}`) and is passed as a
// runtime instanceof-filter value to iterateOver() below; since production templates are already
// unconditionally preprocessor-rewritten to <et2-select>, real widgets are instances of Et2Select
// directly, never of this never-instantiated shim subclass - so that filter already matches
// nothing in practice (kept as-is, not this deletion's concern to fix). See
// app-ts-modernization.md and widget-migration-status.md.
import {Et2Button} from "../../api/js/etemplate/Et2Button/Et2Button";
import {et2_selectbox} from "../../api/js/etemplate/legacy-shims/et2_widget_selectbox";
import {etemplate2} from "../../api/js/etemplate/etemplate2";
import type {et2_htmlarea} from "../../api/js/etemplate/legacy-shims/et2_widget_htmlarea";
import type {et2_checkbox} from "../../api/js/etemplate/legacy-shims/et2_widget_checkbox";
import type {et2_selectAccount} from "../../api/js/etemplate/legacy-shims/et2_widget_selectAccount";
import "./Et2TrackerAssigned.ts";
import type {Et2TrackerAssigned} from "./Et2TrackerAssigned";
import {Et2Dialog} from "../../api/js/etemplate/Et2Dialog/Et2Dialog";
import type {LitElement} from "lit";
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {Et2ButtonToggle} from "../../api/js/etemplate/Et2Button/Et2ButtonToggle";
import type {EgwFrameworkApp} from "../../kdots/js/EgwFrameworkApp";
import type {Et2LinkList} from "../../api/js/etemplate/Et2Link/Et2LinkList";
import type {Et2Nextmatch} from "../../api/js/etemplate/Et2Nextmatch/Et2Nextmatch";
import type {Et2Datagrid} from "../../api/js/etemplate/Et2Datagrid/Et2Datagrid";
// egw/app are ambient globals (declare global {} in egw_global.d.ts, unconditionally included
// via tsconfig's "**/*.d.ts") - no import needed or possible.

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
				// Called with the real widget (not the no-arg call this used to be), so the
				// start/end date range widgets are correctly enabled/disabled from the actual,
				// already-restored "filter" value on first load - see et2-nextmatch-conversion.md's
				// "toolbar control that mirrors an nm filter" startup pitfall.
				this.filter_change(null, this.et2.getWidgetById('filter'));
				if (this.et2.getArrayMgr('content').getEntry('nm[only_tracker]'))
				{
					// there's no this.et2.getWidgetById('colfilter[tr_tracker]').hide() and
					// document.getElementById(...).style.display = 'none' hides already hidden
					// selectbox and not the choosen container :(
					const chznNode = document.getElementById('tracker_index_col_filter_tr_tracker__chzn');
					if(chznNode) chznNode.style.display = 'none';
				}
				break;
			case 'tracker.escalations':
				// Set any filters with multiple values to multiple
				_et2.widgetContainer.getWidgetById('escalation').iterateOver((widget) =>
				{
					if( typeof widget.options.value === 'object' && widget.options.value.length > 1)
					{
						let button = null;
						// Find associated expand button
						widget.getParent().getParent().iterateOver((widget) => {button = widget;}, this, Et2Button);
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
				let nm = this.et2 ? <Et2Nextmatch>this.et2.getWidgetById('nm') : null;
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

		// Try and find the nextmatch widget, and set its filters
		const et2 = etemplate2.getById('tracker-index');
		const nm = et2?.widgetContainer?.getWidgetById('nm');
		if(nm)
		{
			state = nm.getValue();
		}

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
	filter_change(ev? : Event, filter? : Et2Select)
	{
		const dates = this.et2.getWidgetById('tracker.index.dates');
		if (filter && dates)
		{
			dates.set_disabled(filter.value !== "custom");
			if (!filter.value) (<Et2Nextmatch>this.nm).activeFilters.startdate = null;
			if (filter.value === "custom")
			{
				const filterDrawer = (<EgwFrameworkApp>filter.closest('egw-app'))?.filtersDrawer;
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
			case 'tr_tracker':
				// Keep the "Assigned to" filter's account-search scoped to the current queue
				// regardless of which control changed it (toolbar or drawer) - checkNmFilterChanged()
				// fires for every col_filter change via the et2-filter event, not just the toolbar's
				// own onchange, so this also covers the drawer's Tracker Queue filter that onchange
				// never sees.
				const assignedFilter = <Et2TrackerAssigned>this.et2.getWidgetById('col_filter[tr_assigned]');
				if(assignedFilter) assignedFilter.tracker = value;
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
			const popup : any = egw().open_link('/index.php?menuaction=tracker.tracker_ui.tprint&tr_id='+id[1],'', <string>egw().link_get_registry('tracker','add_popup'),'tracker');
			popup.onload = () => popup.print();
		}
	}

	/**
	 * Check if the edit window is a popup, then set window focus
	 */
	edit_popup()
	{
		// this.et2 (Et2Template) IS the DOM node/element itself (extends LitElement, an HTMLElement) -
		// there's no separate ".node" sub-property. The old ".node" access always silently evaluated
		// to undefined, meaning this whole method has been dead code; fixed to use this.et2 directly.
		if (typeof this.et2?.baseURI != 'undefined' && !this.et2.baseURI.match(/no_?popup/))
		{
			window.focus();

			if (this.et2.baseURI.match('composeid')) //tracker created by mail application
			{
				window.resizeTo(750,550);
			}
		}
	}

	/**
	 * canned_comment_request
	 *
	 */
	canned_comment_requst()
	{
		const editor = this.et2.getWidgetById('reply_message');
		const id = this.et2.getValueById('canned_response');
		if (id && editor)
		{
			// Need to specify the popup's egw
			this.et2.egw().request('tracker.tracker_ui.ajax_canned_comment',[id,document.getElementById('tracker-edit_reply_message').style.display == 'none']);
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
		this.et2.querySelectorAll<Et2LinkList>('et2-link-list').forEach(link =>
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
		const nm = <Et2Nextmatch>_selected[0].manager.data.nextmatch;
		let et2 = nm.getInstanceManager();
		let assigned = <Et2TrackerAssigned>et2.widgetContainer.getWidgetById('assigned');
		if(assigned)
		{
			assigned.set_value([]);
			et2.widgetContainer.getWidgetById('assigned_action[title]').set_value('');
			et2.widgetContainer.getWidgetById('assigned_action[title]').set_class('');
			et2.widgetContainer.getWidgetById('assigned_popup[assigned_action][ok]').set_disabled(_selected.length !== 1);
			et2.widgetContainer.getWidgetById('assigned_popup[assigned_action][add]').set_disabled(_selected.length === 1);
			et2.widgetContainer.getWidgetById('assigned_popup[assigned_action][delete]').set_disabled(_selected.length === 1);
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

		// Field pre-population above is app-specific and stays here; actually finding/showing
		// the popup is Et2NextmatchActionController's job (executeAction's "open_popup" case) -
		// no direct dependency on the legacy et2_extension_nextmatch_actions.js helper needed.
		nm.executeAction(_action.id, {ids: _selected.map(s => s.id), all: false}, {nmAction: "open_popup"});
	}

	/**
	 * Submit one of the index nextmatch action popups (admin / link / assigned / group).
	 *
	 * Replaces the legacy nm_submit_popup() + window.nm_popup_action/nm_popup_ids globals - the
	 * popups are real <et2-dialog>s now, so Et2NextmatchActionController.openActionPopup() takes
	 * the "already a dialog" fast path (sets .selectedIds, calls .show()) and none of
	 * nm_open_popup()'s runtime button-wrapping (which used to set those globals) happens any more.
	 *
	 * ButtonMixin._handleClick() has already set the clicked button's own `clicked = true` before
	 * this onclick runs, so the button's own id (eg. "admin_popup[update]") lands in the submitted
	 * content - that's what tells the server which button was pressed, see tracker_ui::process()'s
	 * `key($action[$multi_action . '_action'] ?? [])`. executeAction() triggers the normal
	 * whole-template submit, with the nextmatch payload (action id, selected, select_all,
	 * checkboxes) merged in by Et2Nextmatch's own value getter. Returning false stops the button
	 * from also running its own default (would-be second) submit.
	 *
	 * @param _event
	 * @param _widget the button that was clicked
	 * @param _action_id the nm action id this popup was opened for ("admin"/"assigned"/"group")
	 *  - the same for every button inside one popup, matching the legacy
	 *  nm_popup_action's behaviour; the button's own id/value is what varies.
	 */
	submit_popup(_event : Event, _widget, _action_id : string) : boolean
	{
		const dialog = _widget.closest('et2-dialog');
		const nm = <Et2Nextmatch>_widget.getInstanceManager()?.widgetContainer?.getWidgetById('nm');
		if(!nm)
		{
			return false;
		}
		// Prefer the live selection - it still carries "select all", which the dialog's own
		// .selectedIds (a plain array of ids set by openActionPopup()) does not.
		const selection = nm.getSelection();
		if(!selection.all && dialog?.selectedIds?.length)
		{
			selection.ids = dialog.selectedIds;
		}
		nm.executeAction(_action_id, selection, {nmAction: "submit"});
		dialog?.hide();
		return false;
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
		// Returning the promise (instead of just calling and discarding it) keeps this override's
		// inferred return type assignable to EgwApp.viewEntry()'s Promise<Et2Dialog> - execution order
		// is unchanged, since `return` doesn't await it.
		const promise = super.viewEntry(_action, _senders);

		// Et2Nextmatch has no public API to find a rendered row's DOM node by uid (the legacy
		// nm.getController()._indexMap this replaces has no public replacement either - see
		// et2-nextmatch-conversion.md's legacy API replacement table). Reach into Et2Datagrid's own
		// internal row lookup instead, the same way Addressbook's CRM.ts reaches into its live row
		// list - a real DOM query past a private-in-TS-only boundary, not a sanctioned API.
		let nm = <Et2Nextmatch>this.et2.getWidgetById('nm');
		let datagrid = <Et2Datagrid>nm?.shadowRoot?.querySelector('et2-datagrid');
		let row = datagrid?._findRenderedRowElement(_senders[0]['id']);
		row?.querySelector('.tracker_unseen')?.classList.remove('tracker_unseen');

		return promise;
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

		// Update reply - getComplete()'s "value" is typed as a generic Object, not the dialog's
		// actual content shape
		dialog.getComplete().then(async([button, value] : [number, any]) =>
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
		const extras = {
			link_app: 'tracker',
			link_id: <string|boolean>false
		};
		for(let i = 0; i < _selected.length; i++)
		{
			// Remove UID prefix for just contact_id
			const ids = _selected[i].id.split('::');
			ids.shift();

			extras.link_id = ids.join('::');
			break;
		}

		egw.open("", "timesheet", "list", extras, 'timesheet');
	}
}

app.classes.tracker = trackerAPP;