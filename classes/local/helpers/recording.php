<?php
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
/**
 * The mod_bigbluebuttonbn recordings instance helper
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Laurent David  (laurent [at] call-learning [dt] fr)
 */
namespace mod_bigbluebuttonbn\local\helpers;
use html_table;
use html_table_row;
use html_writer;
use mod_bigbluebuttonbn_generator;

defined('MOODLE_INTERNAL') || die();

/**
 * Utility class for recordings instance helper
 *
 * @package mod_bigbluebuttonbn
 * @copyright 2021 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording {

    /**
     * Helper function to retrieve recordings from a BigBlueButton server.
     *
     * @param string|array $meetingids   list of meetingIDs "mid1,mid2,mid3" or array("mid1","mid2","mid3")
     * @param string|array $recordingids list of $recordingids "rid1,rid2,rid3" or array("rid1","rid2","rid3") for filtering
     *
     * @return associative array with recordings indexed by recordID, each recording is a non sequential associative array
     */
    public static function bigbluebuttonbn_get_recordings_array($meetingids, $recordingids = []) {
        $meetingidsarray = $meetingids;
        if (!is_array($meetingids)) {
            $meetingidsarray = explode(',', $meetingids);
        }
        // If $meetingidsarray is empty there is no need to go further.
        if (empty($meetingidsarray)) {
            return array();
        }
        $recordings = \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recordings_array_fetch($meetingidsarray);
        // Sort recordings.
        uasort($recordings, "\\mod_bigbluebuttonbn\\local\\helpers\\recording::bigbluebuttonbn_recording_build_sorter");
        // Filter recordings based on recordingIDs.
        $recordingidsarray = $recordingids;
        if (!is_array($recordingids)) {
            $recordingidsarray = explode(',', $recordingids);
        }
        if (empty($recordingidsarray)) {
            // No recording ids, no need to filter.
            return $recordings;
        }
        return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recordings_array_filter($recordingidsarray, $recordings);
    }

    /**
     * Helper function to fetch recordings from a BigBlueButton server.
     *
     * @param array $meetingidsarray array with meeting ids in the form array("mid1","mid2","mid3")
     *
     * @return array (associative) with recordings indexed by recordID, each recording is a non sequential associative array
     */
    public static function bigbluebuttonbn_get_recordings_array_fetch($meetingidsarray) {
        if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST)
            || defined('BEHAT_SITE_RUNNING')
            || defined('BEHAT_TEST')
            || defined('BEHAT_UTIL')) {
            // Just return the fake recording.
            global $CFG;
            require_once($CFG->libdir . '/testing/generator/lib.php');
            require_once(__DIR__ . '/tests/generator/lib.php');
            return mod_bigbluebuttonbn_generator::bigbluebuttonbn_get_recordings_array_fetch($meetingidsarray);
        }
        $recordings = array();
        // Execute a paginated getRecordings request.
        $pagecount = 25;
        $pages = floor(count($meetingidsarray) / $pagecount) + 1;
        if (count($meetingidsarray) > 0 && count($meetingidsarray) % $pagecount == 0) {
            $pages--;
        }
        for ($page = 1; $page <= $pages; ++$page) {
            $mids = array_slice($meetingidsarray, ($page - 1) * $pagecount, $pagecount);
            $recordings += \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recordings_array_fetch_page($mids);
        }
        return $recordings;
    }

    /**
     * Helper function to fetch one page of upto 25 recordings from a BigBlueButton server.
     *
     * @param array  $mids
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recordings_array_fetch_page($mids) {
        $recordings = array();
        // Do getRecordings is executed using a method GET (supported by all versions of BBB).
        $url = \mod_bigbluebuttonbn\local\bigbluebutton::action_url('getRecordings', ['meetingID' => implode(',', $mids)]);
        $xml = \mod_bigbluebuttonbn\local\bigbluebutton::bigbluebuttonbn_wrap_xml_load_file($url);
        if ($xml && $xml->returncode == 'SUCCESS' && isset($xml->recordings)) {
            // If there were meetings already created.
            foreach ($xml->recordings->recording as $recordingxml) {
                $recording = \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_array_value($recordingxml);
                $recordings[$recording['recordID']] = $recording;

                // Check if there is childs.
                if (isset($recordingxml->breakoutRooms->breakoutRoom)) {
                    foreach ($recordingxml->breakoutRooms->breakoutRoom as $breakoutroom) {
                        $url = \mod_bigbluebuttonbn\local\bigbluebutton::action_url(
                            'getRecordings',
                            ['recordID' => implode(',', (array) $breakoutroom)]
                        );
                        $xml = \mod_bigbluebuttonbn\local\bigbluebutton::bigbluebuttonbn_wrap_xml_load_file($url);
                        if ($xml && $xml->returncode == 'SUCCESS' && isset($xml->recordings)) {
                            // If there were meetings already created.
                            foreach ($xml->recordings->recording as $recordingxml) {
                                $recording =
                                    \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_array_value($recordingxml);
                                $recordings[$recording['recordID']] = $recording;
                            }
                        }
                    }
                }
            }
        }
        return $recordings;
    }

    /**
     * Helper function to remove a set of recordings from an array.
     *
     * @param array  $rids
     * @param array  $recordings
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recordings_array_filter($rids, &$recordings) {
        foreach ($recordings as $key => $recording) {
            if (!in_array($recording['recordID'], $rids)) {
                unset($recordings[$key]);
            }
        }
        return $recordings;
    }

    /**
     * Helper function to retrieve imported recordings from the Moodle database.
     * The references are stored as events in bigbluebuttonbn_logs.
     *
     * @param string $courseid
     * @param string $bigbluebuttonbnid
     * @param bool   $subset
     *
     * @return associative array with imported recordings indexed by recordID, each recording
     * is a non sequential associative array that corresponds to the actual recording in BBB
     */
    public static function bigbluebuttonbn_get_recordings_imported_array($courseid = 0, $bigbluebuttonbnid = null, $subset = true) {
        global $DB;
        $select = bigbluebuttonbn_get_recordings_imported_sql_select($courseid, $bigbluebuttonbnid, $subset);
        $recordsimported = $DB->get_records_select('bigbluebuttonbn_logs', $select);
        $recordsimportedarray = array();
        foreach ($recordsimported as $recordimported) {
            $meta = json_decode($recordimported->meta, true);
            $recording = $meta['recording'];
            // Override imported flag with actual ID.
            $recording['imported'] = $recordimported->id;
            if (isset($recordimported->protected)) {
                $recording['protected'] = (string) $recordimported->protected;
            }
            $recordsimportedarray[$recording['recordID']] = $recording;
        }
        return $recordsimportedarray;
    }

    /**
     * Helper function to convert an xml recording object to an array in the format used by the plugin.
     *
     * @param object $recording
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recording_array_value($recording) {
        // Add formats.
        $playbackarray = array();
        foreach ($recording->playback->format as $format) {
            $playbackarray[(string) $format->type] = array('type' => (string) $format->type,
                'url' => trim((string) $format->url), 'length' => (string) $format->length);
            // Add preview per format when existing.
            if ($format->preview) {
                $playbackarray[(string) $format->type]['preview'] =
                    \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_preview_images($format->preview);
            }
        }
        // Add the metadata to the recordings array.
        $metadataarray =
            \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_array_meta(get_object_vars($recording->metadata));
        $recordingarray = array('recordID' => (string) $recording->recordID,
            'meetingID' => (string) $recording->meetingID, 'meetingName' => (string) $recording->name,
            'published' => (string) $recording->published, 'startTime' => (string) $recording->startTime,
            'endTime' => (string) $recording->endTime, 'playbacks' => $playbackarray);
        if (isset($recording->protected)) {
            $recordingarray['protected'] = (string) $recording->protected;
        }
        return $recordingarray + $metadataarray;
    }

    /**
     * Helper function to convert an xml recording preview images to an array in the format used by the plugin.
     *
     * @param object $preview
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recording_preview_images($preview) {
        $imagesarray = array();
        foreach ($preview->images->image as $image) {
            $imagearray = array('url' => trim((string) $image));
            foreach ($image->attributes() as $attkey => $attvalue) {
                $imagearray[$attkey] = (string) $attvalue;
            }
            array_push($imagesarray, $imagearray);
        }
        return $imagesarray;
    }

    /**
     * Helper function to convert an xml recording metadata object to an array in the format used by the plugin.
     *
     * @param array $metadata
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recording_array_meta($metadata) {
        $metadataarray = array();
        foreach ($metadata as $key => $value) {
            if (is_object($value)) {
                $value = '';
            }
            $metadataarray['meta_' . $key] = $value;
        }
        return $metadataarray;
    }

    /**
     * Helper function to sort an array of recordings. It compares the startTime in two recording objecs.
     *
     * @param object $a
     * @param object $b
     *
     * @return array
     */
    public static function bigbluebuttonbn_recording_build_sorter($a, $b) {
        global $CFG;
        $resultless = !empty($CFG->bigbluebuttonbn_recordings_sortorder) ? -1 : 1;
        $resultmore = !empty($CFG->bigbluebuttonbn_recordings_sortorder) ? 1 : -1;
        if ($a['startTime'] < $b['startTime']) {
            return $resultless;
        }
        if ($a['startTime'] == $b['startTime']) {
            return 0;
        }
        return $resultmore;
    }

    /**
     * Perform deleteRecordings on BBB.
     *
     * @param string $recordids
     *
     * @return boolean
     */
    public static function bigbluebuttonbn_delete_recordings($recordids) {
        $ids = explode(',', $recordids);
        foreach ($ids as $id) {
            $xml = \mod_bigbluebuttonbn\local\bigbluebutton::bigbluebuttonbn_wrap_xml_load_file(
                \mod_bigbluebuttonbn\local\bigbluebutton::action_url('deleteRecordings', ['recordID' => $id])
            );
            if ($xml && $xml->returncode != 'SUCCESS') {
                return false;
            }
        }
        return true;
    }

    /**
     * Perform publishRecordings on BBB.
     *
     * @param string $recordids
     * @param string $publish
     */
    public static function bigbluebuttonbn_publish_recordings($recordids, $publish = 'true') {
        $ids = explode(',', $recordids);
        foreach ($ids as $id) {
            $xml = \mod_bigbluebuttonbn\local\bigbluebutton::bigbluebuttonbn_wrap_xml_load_file(
                \mod_bigbluebuttonbn\local\bigbluebutton::action_url('publishRecordings',
                    ['recordID' => $id, 'publish' => $publish])
            );
            if ($xml && $xml->returncode != 'SUCCESS') {
                return false;
            }
        }
        return true;
    }

    /**
     * Perform updateRecordings on BBB.
     *
     * @param string $recordids
     * @param array $params ['key'=>param_key, 'value']
     */
    public static function bigbluebuttonbn_update_recordings($recordids, $params) {
        $ids = explode(',', $recordids);
        foreach ($ids as $id) {
            $xml = \mod_bigbluebuttonbn\local\bigbluebutton::bigbluebuttonbn_wrap_xml_load_file(
                \mod_bigbluebuttonbn\local\bigbluebutton::action_url('updateRecordings', ['recordID' => $id] + (array) $params)
            );
            if ($xml && $xml->returncode != 'SUCCESS') {
                return false;
            }
        }
        return true;
    }

    /**
     * Helper function converts recording date used in row for the data used by the recording table.
     *
     * @param array $recording
     *
     * @return integer
     */
    public static function bigbluebuttonbn_get_recording_data_row_date($recording) {
        if (!isset($recording['startTime'])) {
            return 0;
        }
        return floatval($recording['startTime']);
    }

    /**
     * Helper function evaluates if recording preview should be included.
     *
     * @param array $bbbsession
     *
     * @return boolean
     */
    public static function bigbluebuttonbn_get_recording_data_preview_enabled($bbbsession) {
        return ((double) $bbbsession['serverversion'] >= 1.0 && $bbbsession['bigbluebuttonbn']->recordings_preview == '1');
    }

    /**
     * Helper function converts recording duration used in row for the data used by the recording table.
     *
     * @param array $recording
     *
     * @return integer
     */
    public static function bigbluebuttonbn_get_recording_data_row_duration($recording) {
        foreach (array_values($recording['playbacks']) as $playback) {
            // Ignore restricted playbacks.
            if (array_key_exists('restricted', $playback) && strtolower($playback['restricted']) == 'true') {
                continue;
            }
            // Take the lenght form the fist playback with an actual value.
            if (!empty($playback['length'])) {
                return intval($playback['length']);
            }
        }
        return 0;
    }

    /**
     * Helper function format recording date used in row for the data used by the recording table.
     *
     * @param integer $starttime
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_date_formatted($starttime) {
        global $USER;
        $starttime = $starttime - ($starttime % 1000);
        // Set formatted date.
        $dateformat = get_string('strftimerecentfull', 'langconfig') . ' %Z';
        return userdate($starttime / 1000, $dateformat, usertimezone($USER->timezone));
    }

    /**
     * Helper function builds recording actionbar used in row for the data used by the recording table.
     *
     * @param array $recording
     * @param array $tools
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_actionbar($recording, $tools) {
        $actionbar = '';
        foreach ($tools as $tool) {
            $buttonpayload =
                \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_actionbar_payload($recording, $tool);
            if ($tool == 'protect') {
                if (isset($recording['imported'])) {
                    $buttonpayload['disabled'] = 'disabled';
                }
                if (!isset($recording['protected'])) {
                    $buttonpayload['disabled'] = 'invisible';
                }
            }
            $actionbar .= bigbluebuttonbn_actionbar_render_button($recording, $buttonpayload);
        }
        $head = html_writer::start_tag('div', array(
            'id' => 'recording-actionbar-' . $recording['recordID'],
            'data-recordingid' => $recording['recordID'],
            'data-meetingid' => $recording['meetingID']));
        $tail = html_writer::end_tag('div');
        return $head . $actionbar . $tail;
    }

    /**
     * Helper function returns the corresponding payload for an actionbar button used in row
     * for the data used by the recording table.
     *
     * @param array $recording
     * @param array $tool
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recording_data_row_actionbar_payload($recording, $tool) {
        if ($tool == 'protect') {
            $protected = 'false';
            if (isset($recording['protected'])) {
                $protected = $recording['protected'];
            }
            return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_action_protect($protected);
        }
        if ($tool == 'publish') {
            return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_action_publish($recording['published']);
        }
        return array('action' => $tool, 'tag' => $tool);
    }

    /**
     * Helper function returns the payload for protect action button used in row
     * for the data used by the recording table.
     *
     * @param string $protected
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recording_data_row_action_protect($protected) {
        if ($protected == 'true') {
            return array('action' => 'unprotect', 'tag' => 'lock');
        }
        return array('action' => 'protect', 'tag' => 'unlock');
    }

    /**
     * Helper function returns the payload for publish action button used in row
     * for the data used by the recording table.
     *
     * @param string $published
     *
     * @return array
     */
    public static function bigbluebuttonbn_get_recording_data_row_action_publish($published) {
        if ($published == 'true') {
            return array('action' => 'unpublish', 'tag' => 'hide');
        }
        return array('action' => 'publish', 'tag' => 'show');
    }

    /**
     * Helper function builds recording preview used in row for the data used by the recording table.
     *
     * @param array $recording
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_preview($recording) {
        $options = array('id' => 'preview-' . $recording['recordID']);
        if ($recording['published'] === 'false') {
            $options['hidden'] = 'hidden';
        }
        $recordingpreview = html_writer::start_tag('div', $options);
        foreach ($recording['playbacks'] as $playback) {
            if (isset($playback['preview'])) {
                $recordingpreview .= \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_preview_images($playback);
                break;
            }
        }
        $recordingpreview .= html_writer::end_tag('div');
        return $recordingpreview;
    }

    /**
     * Helper function builds element with actual images used in recording preview row based on a selected playback.
     *
     * @param array $playback
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_preview_images($playback) {
        global $CFG;
        $recordingpreview = html_writer::start_tag('div', array('class' => 'container-fluid'));
        $recordingpreview .= html_writer::start_tag('div', array('class' => 'row'));
        foreach ($playback['preview'] as $image) {
            if ($CFG->bigbluebuttonbn_recordings_validate_url && !bigbluebuttonbn_is_valid_resource(trim($image['url']))) {
                return '';
            }
            $recordingpreview .= html_writer::start_tag('div', array('class' => ''));
            $recordingpreview .= html_writer::empty_tag(
                'img',
                array('src' => trim($image['url']) . '?' . time(), 'class' => 'recording-thumbnail pull-left')
            );
            $recordingpreview .= html_writer::end_tag('div');
        }
        $recordingpreview .= html_writer::end_tag('div');
        $recordingpreview .= html_writer::start_tag('div', array('class' => 'row'));
        $recordingpreview .= html_writer::tag(
            'div',
            get_string('view_recording_preview_help', 'bigbluebuttonbn'),
            array('class' => 'text-center text-muted small')
        );
        $recordingpreview .= html_writer::end_tag('div');
        $recordingpreview .= html_writer::end_tag('div');
        return $recordingpreview;
    }

    /**
     * Helper function renders recording types to be used in row for the data used by the recording table.
     *
     * @param array $recording
     * @param array $bbbsession
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_types($recording, $bbbsession) {
        $dataimported = 'false';
        $title = '';
        if (isset($recording['imported'])) {
            $dataimported = 'true';
            $title = get_string('view_recording_link_warning', 'bigbluebuttonbn');
        }
        $visibility = '';
        if ($recording['published'] === 'false') {
            $visibility = 'hidden ';
        }
        $id = 'playbacks-' . $recording['recordID'];
        $recordingtypes = html_writer::start_tag('div', array('id' => $id, 'data-imported' => $dataimported,
            'data-meetingid' => $recording['meetingID'], 'data-recordingid' => $recording['recordID'],
            'title' => $title, $visibility => $visibility));
        foreach ($recording['playbacks'] as $playback) {
            $recordingtypes .= \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_type($recording,
                $bbbsession, $playback);
        }
        $recordingtypes .= html_writer::end_tag('div');
        return $recordingtypes;
    }

    /**
     * Helper function renders the link used for recording type in row for the data used by the recording table.
     *
     * @param array $recording
     * @param array $bbbsession
     * @param array $playback
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_type($recording, $bbbsession, $playback) {
        global $CFG, $OUTPUT;
        if (!bigbluebuttonbn_include_recording_data_row_type($recording, $bbbsession, $playback)) {
            return '';
        }
        $text = \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_type_text($playback['type']);
        $href = $CFG->wwwroot . '/mod/bigbluebuttonbn/bbb_view.php?action=play&bn=' . $bbbsession['bigbluebuttonbn']->id .
            '&mid=' . $recording['meetingID'] . '&rid=' . $recording['recordID'] . '&rtype=' . $playback['type'];
        if (!isset($recording['imported']) || !isset($recording['protected']) || $recording['protected'] === 'false') {
            $href .= '&href=' . urlencode(trim($playback['url']));
        }
        $linkattributes = array(
            'id' => 'recording-play-' . $playback['type'] . '-' . $recording['recordID'],
            'class' => 'btn btn-sm btn-default',
            'onclick' => 'M.mod_bigbluebuttonbn.recordings.recordingPlay(this);',
            'data-action' => 'play',
            'data-target' => $playback['type'],
            'data-href' => $href,
        );
        if ($CFG->bigbluebuttonbn_recordings_validate_url && !bigbluebuttonbn_is_bn_server()
            && !bigbluebuttonbn_is_valid_resource(trim($playback['url']))) {
            $linkattributes['class'] = 'btn btn-sm btn-warning';
            $linkattributes['title'] = get_string('view_recording_format_errror_unreachable', 'bigbluebuttonbn');
            unset($linkattributes['data-href']);
        }
        return $OUTPUT->action_link('#', $text, null, $linkattributes) . '&#32;';
    }

    /**
     * Helper function to handle yet unknown recording types
     *
     * @param string $playbacktype : for now presentation, video, statistics, capture, notes, podcast
     *
     * @return string the matching language string or a capitalised version of the provided string
     */
    public static function bigbluebuttonbn_get_recording_type_text($playbacktype) {
        // Check first if string exists, and if it does'nt just default to the capitalised version of the string.
        $text = ucwords($playbacktype);
        $typestringid = 'view_recording_format_' . $playbacktype;
        if (get_string_manager()->string_exists($typestringid, 'bigbluebuttonbn')) {
            $text = get_string($typestringid, 'bigbluebuttonbn');
        }
        return $text;
    }

    /**
     * Helper function renders the name for meeting used in row for the data used by the recording table.
     *
     * @param array $recording
     * @param array $bbbsession
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_meeting($recording, $bbbsession) {
        $payload = array();
        $source = 'meetingName';
        $metaname = trim($recording['meetingName']);
        return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, $metaname, $source,
            $payload);
    }

    /**
     * Helper function renders the name for recording used in row for the data used by the recording table.
     *
     * @param array $recording
     * @param array $bbbsession
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_meta_activity($recording, $bbbsession) {
        $payload = array();
        if (bigbluebuttonbn_get_recording_data_row_editable($bbbsession)) {
            $payload = array('recordingid' => $recording['recordID'], 'meetingid' => $recording['meetingID'],
                'action' => 'edit', 'tag' => 'edit',
                'target' => 'name');
        }
        $oldsource = 'meta_contextactivity';
        if (isset($recording[$oldsource])) {
            $metaname = trim($recording[$oldsource]);
            return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, $metaname, $oldsource,
                $payload);
        }
        $newsource = 'meta_bbb-recording-name';
        if (isset($recording[$newsource])) {
            $metaname = trim($recording[$newsource]);
            return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, $metaname, $newsource,
                $payload);
        }
        $metaname = trim($recording['meetingName']);
        return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, $metaname, $newsource,
            $payload);
    }

    /**
     * Helper function renders the description for recording used in row for the data used by the recording table.
     *
     * @param array $recording
     * @param array $bbbsession
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_meta_description($recording, $bbbsession) {
        $payload = array();
        if (bigbluebuttonbn_get_recording_data_row_editable($bbbsession)) {
            $payload = array('recordingid' => $recording['recordID'], 'meetingid' => $recording['meetingID'],
                'action' => 'edit', 'tag' => 'edit',
                'target' => 'description');
        }
        $oldsource = 'meta_contextactivitydescription';
        if (isset($recording[$oldsource])) {
            $metadescription = trim($recording[$oldsource]);
            return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, $metadescription,
                $oldsource, $payload);
        }
        $newsource = 'meta_bbb-recording-description';
        if (isset($recording[$newsource])) {
            $metadescription = trim($recording[$newsource]);
            return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, $metadescription,
                $newsource, $payload);
        }
        return \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_data_row_text($recording, '', $newsource, $payload);
    }

    /**
     * Helper function renders text element for recording used in row for the data used by the recording table.
     *
     * @param array $recording
     * @param string $text
     * @param string $source
     * @param array $data
     *
     * @return string
     */
    public static function bigbluebuttonbn_get_recording_data_row_text($recording, $text, $source, $data) {
        $htmltext = '<span>' . htmlentities($text) . '</span>';
        if (empty($data)) {
            return $htmltext;
        }
        $target = $data['action'] . '-' . $data['target'];
        $id = 'recording-' . $target . '-' . $data['recordingid'];
        $attributes = array('id' => $id, 'class' => 'quickeditlink col-md-20',
            'data-recordingid' => $data['recordingid'], 'data-meetingid' => $data['meetingid'],
            'data-target' => $data['target'], 'data-source' => $source);
        $head = html_writer::start_tag('div', $attributes);
        $tail = html_writer::end_tag('div');
        $payload = array('action' => $data['action'], 'tag' => $data['tag'], 'target' => $data['target']);
        $htmllink = bigbluebuttonbn_actionbar_render_button($recording, $payload);
        return $head . $htmltext . $htmllink . $tail;
    }

    /**
     * Helper function builds the recording table.
     *
     * @param array $bbbsession
     * @param array $recordings
     * @param array $tools
     *
     * @return object
     */
    public static function bigbluebuttonbn_get_recording_table($bbbsession, $recordings,
        $tools = ['protect', 'publish', 'delete']) {
        global $DB;
        // Declare the table.
        $table = new html_table();
        $table->data = array();
        // Initialize table headers.
        $table->head[] = get_string('view_recording_playback', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_name', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_description', 'bigbluebuttonbn');
        if (recording::bigbluebuttonbn_get_recording_data_preview_enabled($bbbsession)) {
            $table->head[] = get_string('view_recording_preview', 'bigbluebuttonbn');
        }
        $table->head[] = get_string('view_recording_date', 'bigbluebuttonbn');
        $table->head[] = get_string('view_recording_duration', 'bigbluebuttonbn');
        $table->align = array('left', 'left', 'left', 'left', 'left', 'center');
        $table->size = array('', '', '', '', '', '');
        if ($bbbsession['managerecordings']) {
            $table->head[] = get_string('view_recording_actionbar', 'bigbluebuttonbn');
            $table->align[] = 'left';
            $table->size[] = (count($tools) * 40) . 'px';
        }
        // Get the groups of the user.
        $usergroups = groups_get_all_groups($bbbsession['course']->id, $bbbsession['userID']);

        // Build table content.
        foreach ($recordings as $recording) {
            $meetingid = $recording['meetingID'];
            $shortmeetingid = explode('-', $recording['meetingID']);
            if (isset($shortmeetingid[0])) {
                $meetingid = $shortmeetingid[0];
            }
            // Check if the record belongs to a Visible Group type.
            list($course, $cm) = get_course_and_cm_from_cmid($bbbsession['cm']->id);
            $groupmode = groups_get_activity_groupmode($cm);
            $displayrow = true;
            if (($groupmode != VISIBLEGROUPS)
                && !$bbbsession['administrator'] && !$bbbsession['moderator']) {
                $groupid = explode('[', $recording['meetingID']);
                if (isset($groupid[1])) {
                    // It is a group recording and the user is not moderator/administrator. Recording should not be included by default.
                    $displayrow = false;
                    $groupid = explode(']', $groupid[1]);
                    if (isset($groupid[0])) {
                        foreach ($usergroups as $usergroup) {
                            if ($usergroup->id == $groupid[0]) {
                                // Include recording if the user is in the same group.
                                $displayrow = true;
                            }
                        }
                    }
                }
            }
            if ($displayrow) {
                $rowdata = bigbluebuttonbn_get_recording_data_row($bbbsession, $recording, $tools);
                if (!empty($rowdata)) {
                    $row = \mod_bigbluebuttonbn\local\helpers\recording::bigbluebuttonbn_get_recording_table_row($bbbsession, $recording,
                        $rowdata);
                    array_push($table->data, $row);
                }
            }
        }
        return $table;
    }

    /**
     * Helper function builds the recording table row and insert into table.
     *
     * @param array $bbbsession
     * @param array $recording
     * @param object $rowdata
     *
     * @return object
     */
    public static function bigbluebuttonbn_get_recording_table_row($bbbsession, $recording, $rowdata) {
        $row = new html_table_row();
        $row->id = 'recording-tr-' . $recording['recordID'];
        $row->attributes['data-imported'] = 'false';
        $texthead = '';
        $texttail = '';
        if (isset($recording['imported'])) {
            $row->attributes['title'] = get_string('view_recording_link_warning', 'bigbluebuttonbn');
            $row->attributes['data-imported'] = 'true';
            $texthead = '<em>';
            $texttail = '</em>';
        }
        $rowdata->date_formatted = str_replace(' ', '&nbsp;', $rowdata->date_formatted);
        $row->cells = array();
        $row->cells[] = $texthead . $rowdata->playback . $texttail;
        $row->cells[] = $texthead . $rowdata->recording . $texttail;
        $row->cells[] = $texthead . $rowdata->description . $texttail;
        if (recording::bigbluebuttonbn_get_recording_data_preview_enabled($bbbsession)) {
            $row->cells[] = $rowdata->preview;
        }
        $row->cells[] = $texthead . $rowdata->date_formatted . $texttail;
        $row->cells[] = $rowdata->duration_formatted;
        if ($bbbsession['managerecordings']) {
            $row->cells[] = $rowdata->actionbar;
        }
        return $row;
    }

    /**
     * Get the basic data to display in the table view
     *
     * @param array $bbbsession the current session
     * @param array $enabledfeatures feature enabled for this activity
     * @return associative array containing the recordings indexed by recordID, each recording is also a
     * non sequential associative array itself that corresponds to the actual recording in BBB
     */
    public static function bigbluebutton_get_recordings_for_table_view($bbbsession, $enabledfeatures) {
        $bigbluebuttonbnid = null;
        if ($enabledfeatures['showroom']) {
            $bigbluebuttonbnid = $bbbsession['bigbluebuttonbn']->id;
        }
        // Get recordings.
        $recordings = bigbluebuttonbn_get_recordings(
            $bbbsession['course']->id, $bigbluebuttonbnid, $enabledfeatures['showroom'],
            $bbbsession['bigbluebuttonbn']->recordings_deleted
        );
        if ($enabledfeatures['importrecordings']) {
            // Get recording links.
            $bigbluebuttonbnid = $bbbsession['bigbluebuttonbn']->id;
            $recordingsimported = recording::bigbluebuttonbn_get_recordings_imported_array(
                $bbbsession['course']->id, $bigbluebuttonbnid, true
            );
            /* Perform aritmetic addition instead of merge so the imported recordings corresponding to existent
             * recordings are not included. */
            if ($bbbsession['bigbluebuttonbn']->recordings_imported) {
                $recordings = $recordingsimported;
            } else {
                $recordings += $recordingsimported;
            }
        }
        return $recordings;
    }

    /**
     * Helper function evaluates if recording row should be included in the table.
     *
     * @param array $bbbsession
     * @param array $recording
     *
     * @return boolean
     */
    public static function bigbluebuttonbn_include_recording_table_row($bbbsession, $recording) {
        // Exclude unpublished recordings, only if user has no rights to manage them.
        if ($recording['published'] != 'true' && !$bbbsession['managerecordings']) {
            return false;
        }
        // Imported recordings are always shown as long as they are published.
        if (isset($recording['imported'])) {
            return true;
        }
        // Administrators and moderators are always allowed.
        if ($bbbsession['administrator'] || $bbbsession['moderator']) {
            return true;
        }
        // When groups are enabled, exclude those to which the user doesn't have access to.
        if (isset($bbbsession['group']) && $recording['meetingID'] != $bbbsession['meetingid']) {
            return false;
        }
        return true;
    }

    /**
     * Helper function triggers a send notification when the recording is ready.
     *
     * @param object $bigbluebuttonbn
     *
     * @return void
     */
    public static function bigbluebuttonbn_send_notification_recording_ready($bigbluebuttonbn) {
        \mod_bigbluebuttonbn\local\notifier::notify_recording_ready($bigbluebuttonbn);
    }

    /**
     * Helper function renders recording table.
     *
     * @param array $bbbsession
     * @param array $recordings
     * @param array $tools
     *
     * @return array
     */
    public static function bigbluebuttonbn_output_recording_table($bbbsession, $recordings,
        $tools = ['protect', 'publish', 'delete']) {
        if (isset($recordings) && !empty($recordings)) {
            // There are recordings for this meeting.
            $table = recording::bigbluebuttonbn_get_recording_table($bbbsession, $recordings, $tools);
        }
        if (!isset($table) || !isset($table->data)) {
            // Render a table with "No recordings".
            return html_writer::div(
                get_string('view_message_norecordings', 'bigbluebuttonbn'),
                '',
                array('id' => 'bigbluebuttonbn_recordings_table')
            );
        }
        // Render the table.
        return html_writer::div(html_writer::table($table), '', array('id' => 'bigbluebuttonbn_recordings_table'));
    }
}
