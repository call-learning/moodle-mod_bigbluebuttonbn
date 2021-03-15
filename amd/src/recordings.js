// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

import {call as ajaxCall} from 'core/ajax';
import Notification from 'core/notification';
//import get_string from 'core/str';
import $ from 'jquery';
import DataTable from 'mod_bigbluebuttonbn/local/libraries/jquery.dataTable';

$.fn.dataTable = DataTable; // Make sure datatable is setup correctly.

class Recordings {
    columns = {};
    data = {};
    locale = 'en';
    windowVideoPlay = null;
    table = null;
    bbbid = 0;


    constructor(bbbid, locale, columns, data) {
        this.bbbid = bbbid;
        this.locale = locale;
        this.data = data;
        this.columns = columns;
        this.datatableInit();
    }

    datatableInit() {
        // TODO: Init translations with datatable.
        // TODO: add paginator and sort module ?
        $('#bigbluebuttonbn_recordings_table').append('<table></table>');
        this.table = $('#bigbluebuttonbn_recordings_table table').dataTable(
            {
                columns: this.columns,
                data: this.data,
                paginatorLocation: ['header', 'footer']
            }
        );
    }


    filterByText(searchvalue) {
        if (this.table) {
            this.table.set('data', this.datatable.data);
            if (searchvalue) {
                var tlist = this.table.data;
                var rsearch = new RegExp('<span>.*?' + this.escapeRegex(searchvalue) + '.*?</span>', 'i');
                var filterdata = tlist.filter({asList: true}, function (item) {
                    var name = item.get('recording');
                    var description = item.get('description');
                    return (
                        (name && rsearch.test(name)) || (description && rsearch.test(description))
                    );
                });
                this.table.set('data', filterdata);
            }
        }
    }

    recordingElementPayload(element) {
        var nodeelement = $(element);
        var node = nodeelement.parent('div');
        return {
            action: nodeelement.data('action'),
            recordingid: node.data('recordingid'),
            meetingid: node.data('meetingid')
        };
    }

    recordingAction(element, confirmation, extras) {
        var payload = this.recordingElementPayload(element);
        for (var attrname in extras) {
            payload[attrname] = extras[attrname];
        }
        // The action doesn't require confirmation.
        if (!confirmation) {
            this.recordingActionPerform(payload);
            return;
        }
        // Create the confirmation dialogue.
        var confirm = new M.core.confirm({
            modal: true,
            centered: true,
            question: this.recordingConfirmationMessage(payload)
        });
        // If it is confirmed.
        confirm.on('complete-yes', function() {
            this.recordingActionPerform(payload);
        }, this);
    }

    recordingActionPerform(data) {
        M.mod_bigbluebuttonbn.helpers.toggleSpinningWheelOn(data);
        M.mod_bigbluebuttonbn.broker.recordingActionPerform(data);

        var thisbbb = this;
        this.datasource.sendRequest({
            request: "&id=" + this.bbbid + "&action=recording_list_table",
            callback: {
                success: function (data) {
                    var bbinfo = data.data;
                    if (bbinfo.recordings_html === false &&
                        (bbinfo.profile_features.indexOf('all') != -1 || bbinfo.profile_features.indexOf('showrecordings') != -1)) {
                        thisbbb.locale = bbinfo.locale;
                        thisbbb.datatable.columns = bbinfo.data.columns;
                        thisbbb.datatable.data = thisbbb.datatableInitFormatDates(bbinfo.data.data);
                    }
                }
            }
        });
    }

}

/**
 * Initialise recordings code.
 *
 * @method init
 * @param {object} dataobj
 */
export const init = (dataobj) => {
    const promise = ajaxCall(
        [{
            methodname: 'mod_bigbluebutton_recording_list_table',
            args: {
                bigbluebuttonbnid: Number.parseInt(dataobj.bigbluebuttonbnid),
            }
        }]);
    promise[0]
        .done((bbinfo) => {
            const tabledata = bbinfo.tabledata;
            if (tabledata.recordings_html === false &&
                (tabledata.profile_features.indexOf('all') !== -1
                    || tabledata.profile_features.indexOf('showrecordings') !== -1))  {
                const recording = new Recordings(
                    dataobj.bigbluebuttonbnid,
                    dataobj.locale,
                    tabledata.columns,
                    JSON.parse(tabledata.data));
                $('#bigbluebuttonbn_recordings_searchform input[type=submit]').click(
                    (e) => {
                        e.preventDefault();
                        e.stopPropagation();

                        let value = null;
                        if (e.target.get('id') === 'searchsubmit') {
                            value = $('#searchtext').get('value');
                        } else {
                            $('#searchtext').set('value', '');
                        }

                        recording.filterByText(value);
                    }
                );
                //M.mod_bigbluebuttonbn.helpers.init();
            }
            return tabledata;
        }
    ).catch(Notification.exception);
};
