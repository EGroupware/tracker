/*
 * Tracker Assigned widget
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package calendar
 * @subpackage etemplate
 * @link https://www.egroupware.org
 * @author Nathan Gray
 */

import {property} from "lit/decorators/property.js";
import {Et2SelectAccount} from "../../api/js/etemplate/Et2Select/Select/Et2SelectAccount";
import {nothing, TemplateResult} from "lit";

/**
 * Select widget customised for tracker to limit users
 *
 */
export class Et2TrackerAssigned extends Et2SelectAccount
{
	/**
	 * Limit users to those of a specific queue
	 *
	 * @type {number}
	 */
	@property() tracker : string | string[] = '0';

	constructor()
	{
		super();

		this.searchUrl = "tracker_assigned_etemplate_widget::ajax_search";
		this.searchOptions = {};
	}

	/**
	 * Do not pre-fill the list, we only want certain users, which are passed from client
	 *
	 * @protected
	 * @internal
	 */
	protected _getAccounts()
	{
		return Promise.resolve();
	}

	/**
	 * Start searching
	 *
	 * Overridden from parent to easily set the tracker
	 */
	public async startSearch()
	{
		this.searchOptions['tracker'] = this.tracker;
		return super.startSearch();
	}

	/**
	 * Override icon - none
	 *
	 * @param option
	 * @protected
	 */
	protected _iconTemplate(option) : TemplateResult<1>
	{
		return <TemplateResult<1>><unknown>nothing;
	}
}

if(!customElements.get("et2-tracker-assigned"))
{
	customElements.define("et2-tracker-assigned", Et2TrackerAssigned);
}