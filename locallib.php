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
 * Internal library of functions for module BigBlueButtonBN.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2010 onwards, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Jesus Federico  (jesus [at] blindsidenetworks [dt] com)
 * @author    Fred Dixon  (ffdixon [at] blindsidenetworks [dt] com)
 */

use mod_bigbluebuttonbn\local\bbb_constants;
use mod_bigbluebuttonbn\local\bigbluebutton;
use mod_bigbluebuttonbn\local\helpers\meeting;
use mod_bigbluebuttonbn\local\helpers\recording;
use mod_bigbluebuttonbn\plugin;

defined('MOODLE_INTERNAL') || die;

global $CFG;

/**
 * Returns user roles in a context.
 *
 * @param object $context
 * @param integer $userid
 *
 * @return array $userroles
 */
function bigbluebuttonbn_get_user_roles($context, $userid) {
    global $DB;
    $userroles = get_user_roles($context, $userid);
    if ($userroles) {
        $where = '';
        foreach ($userroles as $userrole) {
            $where .= (empty($where) ? ' WHERE' : ' OR') . ' id=' . $userrole->roleid;
        }
        $userroles = $DB->get_records_sql('SELECT * FROM {role}' . $where);
    }
    return $userroles;
}

/**
 * Returns guest role wrapped in an array.
 *
 * @return array
 */
function bigbluebuttonbn_get_guest_role() {
    $guestrole = get_guest_role();
    return array($guestrole->id => $guestrole);
}

/**
 * Returns an array containing all the users in a context.
 *
 * @param context $context
 *
 * @return array $users
 */
function bigbluebuttonbn_get_users(context $context = null) {
    $users = (array) get_enrolled_users($context, '', 0, 'u.*', null, 0, 0, true);
    foreach ($users as $key => $value) {
        $users[$key] = fullname($value);
    }
    return $users;
}

/**
 * Returns an array containing all the users in a context wrapped for html select element.
 *
 * @param context_course $context
 * @param null $bbactivity
 * @return array $users
 * @throws coding_exception
 * @throws moodle_exception
 */
function bigbluebuttonbn_get_users_select(context_course $context, $bbactivity = null) {
    // CONTRIB-7972, check the group of current user and course group mode.
    $groups = null;
    $users = (array) get_enrolled_users($context, '', 0, 'u.*', null, 0, 0, true);
    $course = get_course($context->instanceid);
    $groupmode = groups_get_course_groupmode($course);
    if ($bbactivity) {
        list($bbcourse, $cm) = get_course_and_cm_from_instance($bbactivity->id, 'bigbluebuttonbn');
        $groupmode = groups_get_activity_groupmode($cm);

    }
    if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
        global $USER;
        $groups = groups_get_all_groups($course->id, $USER->id);
        $users = [];
        foreach ($groups as $g) {
            $users += (array) get_enrolled_users($context, '', $g->id, 'u.*', null, 0, 0, true);
        }
    }
    return array_map(
            function($u) {
                return array('id' => $u->id, 'name' => fullname($u));
            },
            $users);
}

/**
 * Returns an array containing all the roles in a context.
 *
 * @param context $context
 * @param bool $onlyviewableroles
 *
 * @return array $roles
 */
function bigbluebuttonbn_get_roles(context $context = null, bool $onlyviewableroles = true) {
    global $CFG;

    if ($onlyviewableroles == true && $CFG->branch >= 35) {
        $roles = (array) get_viewable_roles($context);
        foreach ($roles as $key => $value) {
            $roles[$key] = $value;
        }
    } else {
        $roles = (array) role_get_names($context);
        foreach ($roles as $key => $value) {
            $roles[$key] = $value->localname;
        }
    }

    return $roles;
}

/**
 * Returns an array containing all the roles in a context wrapped for html select element.
 *
 * @param context $context
 * @param bool $onlyviewableroles
 *
 * @return array $users
 */
function bigbluebuttonbn_get_roles_select(context $context = null, bool $onlyviewableroles = true) {
    global $CFG;

    if ($onlyviewableroles == true && $CFG->branch >= 35) {
        $roles = (array) get_viewable_roles($context);
        foreach ($roles as $key => $value) {
            $roles[$key] = array('id' => $key, 'name' => $value);
        }
    } else {
        $roles = (array) role_get_names($context);
        foreach ($roles as $key => $value) {
            $roles[$key] = array('id' => $value->id, 'name' => $value->localname);
        }
    }

    return $roles;
}

/**
 * Returns role that corresponds to an id.
 *
 * @param string|integer $id
 *
 * @return object $role
 */
function bigbluebuttonbn_get_role($id) {
    $roles = (array) role_get_names();
    if (is_numeric($id) && isset($roles[$id])) {
        return (object) $roles[$id];
    }
    foreach ($roles as $role) {
        if ($role->shortname == $id) {
            return $role;
        }
    }
}

/**
 * Returns an array to populate a list of participants used in mod_form.js.
 *
 * @param context $context
 * @param null|object $bbactivity
 * @return array $data
 */
function bigbluebuttonbn_get_participant_data($context, $bbactivity = null) {
    $data = array(
        'all' => array(
            'name' => get_string('mod_form_field_participant_list_type_all', 'bigbluebuttonbn'),
            'children' => []
        ),
    );
    $data['role'] = array(
        'name' => get_string('mod_form_field_participant_list_type_role', 'bigbluebuttonbn'),
        'children' => bigbluebuttonbn_get_roles_select($context, true)
      );
    $data['user'] = array(
        'name' => get_string('mod_form_field_participant_list_type_user', 'bigbluebuttonbn'),
        'children' => bigbluebuttonbn_get_users_select($context, $bbactivity),
    );
    return $data;
}

/**
 * Returns an array to populate a list of participants used in mod_form.php.
 *
 * @param object $bigbluebuttonbn
 * @param context $context
 *
 * @return array
 */
function bigbluebuttonbn_get_participant_list($bigbluebuttonbn, $context) {
    global $USER;
    if ($bigbluebuttonbn == null) {
        return bigbluebuttonbn_get_participant_rules_encoded(
            bigbluebuttonbn_get_participant_list_default($context, $USER->id)
        );
    }
    if (empty($bigbluebuttonbn->participants)) {
        $bigbluebuttonbn->participants = "[]";
    }
    $rules = json_decode($bigbluebuttonbn->participants, true);
    if (empty($rules)) {
        $rules = bigbluebuttonbn_get_participant_list_default($context, bigbluebuttonbn_instance_ownerid($bigbluebuttonbn));
    }
    return bigbluebuttonbn_get_participant_rules_encoded($rules);
}

/**
 * Returns an array to populate a list of participants used in mod_form.php with default values.
 *
 * @param context $context
 * @param integer $ownerid
 *
 * @return array
 */
function bigbluebuttonbn_get_participant_list_default($context, $ownerid = null) {
    $participantlist = array();
    $participantlist[] = array(
        'selectiontype' => 'all',
        'selectionid' => 'all',
        'role' => bbb_constants::BIGBLUEBUTTONBN_ROLE_VIEWER,
    );
    $defaultrules = explode(',', \mod_bigbluebuttonbn\local\config::get('participant_moderator_default'));
    foreach ($defaultrules as $defaultrule) {
        if ($defaultrule == '0') {
            if (!empty($ownerid) && is_enrolled($context, $ownerid)) {
                $participantlist[] = array(
                    'selectiontype' => 'user',
                    'selectionid' => (string) $ownerid,
                    'role' => bbb_constants::BIGBLUEBUTTONBN_ROLE_MODERATOR);
            }
            continue;
        }
        $participantlist[] = array(
            'selectiontype' => 'role',
            'selectionid' => $defaultrule,
            'role' => bbb_constants::BIGBLUEBUTTONBN_ROLE_MODERATOR);
    }
    return $participantlist;
}

/**
 * Returns an array to populate a list of participants used in mod_form.php with bigbluebuttonbn values.
 *
 * @param array $rules
 *
 * @return array
 */
function bigbluebuttonbn_get_participant_rules_encoded($rules) {
    foreach ($rules as $key => $rule) {
        if ($rule['selectiontype'] !== 'role' || is_numeric($rule['selectionid'])) {
            continue;
        }
        $role = bigbluebuttonbn_get_role($rule['selectionid']);
        if ($role == null) {
            unset($rules[$key]);
            continue;
        }
        $rule['selectionid'] = $role->id;
        $rules[$key] = $rule;
    }
    return $rules;
}

/**
 * Returns an array to populate a list of participant_selection used in mod_form.php.
 *
 * @return array
 */
function bigbluebuttonbn_get_participant_selection_data() {
    return [
        'type_options' => [
            'all' => get_string('mod_form_field_participant_list_type_all', 'bigbluebuttonbn'),
            'role' => get_string('mod_form_field_participant_list_type_role', 'bigbluebuttonbn'),
            'user' => get_string('mod_form_field_participant_list_type_user', 'bigbluebuttonbn'),
        ],
        'type_selected' => 'all',
        'options' => ['all' => '---------------'],
        'selected' => 'all',
    ];
}

/**
 * Evaluate if a user in a context is moderator based on roles and participation rules.
 *
 * @param context $context
 * @param array $participantlist
 * @param integer $userid
 *
 * @return boolean
 */
function bigbluebuttonbn_is_moderator($context, $participantlist, $userid = null) {
    global $USER;
    if (!is_array($participantlist)) {
        return false;
    }
    if (empty($userid)) {
        $userid = $USER->id;
    }
    $userroles = bigbluebuttonbn_get_guest_role();
    if (!isguestuser()) {
        $userroles = bigbluebuttonbn_get_user_roles($context, $userid);
    }
    return bigbluebuttonbn_is_moderator_validator($participantlist, $userid, $userroles);
}

/**
 * Iterates participant list rules to evaluate if a user is moderator.
 *
 * @param array $participantlist
 * @param integer $userid
 * @param array $userroles
 *
 * @return boolean
 */
function bigbluebuttonbn_is_moderator_validator($participantlist, $userid, $userroles) {
    // Iterate participant rules.
    foreach ($participantlist as $participant) {
        if (bigbluebuttonbn_is_moderator_validate_rule($participant, $userid, $userroles)) {
            return true;
        }
    }
    return false;
}

/**
 * Evaluate if a user is moderator based on roles and a particular participation rule.
 *
 * @param object $participant
 * @param integer $userid
 * @param array $userroles
 *
 * @return boolean
 */
function bigbluebuttonbn_is_moderator_validate_rule($participant, $userid, $userroles) {
    if ($participant['role'] == bbb_constants::BIGBLUEBUTTONBN_ROLE_VIEWER) {
        return false;
    }
    // Validation for the 'all' rule.
    if ($participant['selectiontype'] == 'all') {
        return true;
    }
    // Validation for a 'user' rule.
    if ($participant['selectiontype'] == 'user') {
        if ($participant['selectionid'] == $userid) {
            return true;
        }
        return false;
    }
    // Validation for a 'role' rule.
    $role = bigbluebuttonbn_get_role($participant['selectionid']);
    if ($role != null && array_key_exists($role->id, $userroles)) {
        return true;
    }
    return false;
}

/**
 * Helper returns error message key for the language file that corresponds to a bigbluebutton error key.
 *
 * @param string $messagekey
 * @param string $defaultkey
 *
 * @return string
 */
function bigbluebuttonbn_get_error_key($messagekey, $defaultkey = null) {
    if ($messagekey == 'checksumError') {
        return 'index_error_checksum';
    }
    if ($messagekey == 'maxConcurrent') {
        return 'view_error_max_concurrent';
    }
    return $defaultkey;
}

/**
 * Helper evaluates if a voicebridge number is unique.
 *
 * @param integer $instance
 * @param integer $voicebridge
 *
 * @return string
 */
function bigbluebuttonbn_voicebridge_unique($instance, $voicebridge) {
    global $DB;
    if ($voicebridge == 0) {
        return true;
    }
    $select = 'voicebridge = ' . $voicebridge;
    if ($instance != 0) {
        $select .= ' AND id <>' . $instance;
    }
    if (!$DB->get_records_select('bigbluebuttonbn', $select)) {
        return true;
    }
    return false;
}

/**
 * Helper estimate a duration for the meeting based on the closingtime.
 *
 * @param integer $closingtime
 *
 * @return integer
 */
function bigbluebuttonbn_get_duration($closingtime) {
    $duration = 0;
    $now = time();
    if ($closingtime > 0 && $now < $closingtime) {
        $duration = ceil(($closingtime - $now) / 60);
        $compensationtime = intval((int) \mod_bigbluebuttonbn\local\config::get('scheduled_duration_compensation'));
        $duration = intval($duration) + $compensationtime;
    }
    return $duration;
}

/**
 * Helper return array containing the file descriptor for a preuploaded presentation.
 *
 * @param context $context
 * @param string $presentation
 * @param integer $id
 *
 * @return array
 */
function bigbluebuttonbn_get_presentation_array($context, $presentation, $id = null) {
    global $CFG;
    if (empty($presentation)) {
        if ($CFG->bigbluebuttonbn_preuploadpresentation_enabled) {
            // Item has not presentation but presentation is enabled..
            // Check if exist some file by default in general mod setting ("presentationdefault").
            $fs = get_file_storage();
            $files = $fs->get_area_files(
                context_system::instance()->id,
                'mod_bigbluebuttonbn',
                'presentationdefault',
                0,
                "filename",
                false
            );

            if (count($files) == 0) {
                // Not exist file by default in "presentationbydefault" setting.
                return array('url' => null, 'name' => null, 'icon' => null, 'mimetype_description' => null);
            }

            // Exists file in general setting to use as default for presentation. Cache image for temp public access.
            $file = reset($files);
            unset($files);
            $pnoncevalue = null;
            if (!is_null($id)) {
                // Create the nonce component for granting a temporary public access.
                $cache = cache::make_from_params(
                    cache_store::MODE_APPLICATION,
                    'mod_bigbluebuttonbn',
                    'presentationdefault_cache'
                );
                $pnoncekey = sha1(context_system::instance()->id);
                /* The item id was adapted for granting public access to the presentation once in order
                 * to allow BigBlueButton to gather the file. */
                $pnoncevalue = bigbluebuttonbn_generate_nonce();
                $cache->set($pnoncekey, array('value' => $pnoncevalue, 'counter' => 0));
            }

            $url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $pnoncevalue,
                $file->get_filepath(),
                $file->get_filename()
            );
            return (array('name' => $file->get_filename(), 'icon' => file_file_icon($file, 24),
                'url' => $url->out(false), 'mimetype_description' => get_mimetype_description($file)));
        }

        return array('url' => null, 'name' => null, 'icon' => null, 'mimetype_description' => null);
    }
    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'mod_bigbluebuttonbn',
        'presentation',
        0,
        'itemid, filepath, filename',
        false
    );
    if (count($files) == 0) {
        return array('url' => null, 'name' => null, 'icon' => null, 'mimetype_description' => null);
    }
    $file = reset($files);
    unset($files);
    $pnoncevalue = null;
    if (!is_null($id)) {
        // Create the nonce component for granting a temporary public access.
        $cache = cache::make_from_params(
            cache_store::MODE_APPLICATION,
            'mod_bigbluebuttonbn',
            'presentation_cache'
        );
        $pnoncekey = sha1($id);
        /* The item id was adapted for granting public access to the presentation once in order
         * to allow BigBlueButton to gather the file. */
        $pnoncevalue = bigbluebuttonbn_generate_nonce();
        $cache->set($pnoncekey, array('value' => $pnoncevalue, 'counter' => 0));
    }
    $url = moodle_url::make_pluginfile_url(
        $file->get_contextid(),
        $file->get_component(),
        $file->get_filearea(),
        $pnoncevalue,
        $file->get_filepath(),
        $file->get_filename()
    );
    return array('name' => $file->get_filename(), 'icon' => file_file_icon($file, 24),
        'url' => $url->out(false), 'mimetype_description' => get_mimetype_description($file));
}

/**
 * Helper generates a nonce used for the preuploaded presentation callback url.
 *
 * @return string
 */
function bigbluebuttonbn_generate_nonce() {
    $mt = microtime();
    $rand = mt_rand();
    return md5($mt . $rand);
}

/**
 * Helper generates a random password.
 *
 * @param integer $length
 * @param string $unique
 *
 * @return string
 */
function bigbluebuttonbn_random_password($length = 8, $unique = "") {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    do {
        $password = substr(str_shuffle($chars), 0, $length);
    } while ($unique == $password);
    return $password;
}

/**
 * Helper register a bigbluebuttonbn event.
 *
 * @param string $type
 * @param object $bigbluebuttonbn
 * @param array $options [timecreated, userid, other]
 *
 * @return void
 */
function bigbluebuttonbn_event_log($type, $bigbluebuttonbn, $options = []) {
    global $DB;
    if (!in_array($type, \mod_bigbluebuttonbn\event\events::$events)) {
        // No log will be created.
        return;
    }
    $course = $DB->get_record('course', array('id' => $bigbluebuttonbn->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bigbluebuttonbn->id, $course->id, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    $params = array('context' => $context, 'objectid' => $bigbluebuttonbn->id);
    if (array_key_exists('timecreated', $options)) {
        $params['timecreated'] = $options['timecreated'];
    }
    if (array_key_exists('userid', $options)) {
        $params['userid'] = $options['userid'];
    }
    if (array_key_exists('other', $options)) {
        $params['other'] = $options['other'];
    }
    $event = call_user_func_array(
        '\mod_bigbluebuttonbn\event\\' . $type . '::create',
        array($params)
    );
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('bigbluebuttonbn', $bigbluebuttonbn);
    $event->trigger();
}

/**
 * Updates the meeting info cached object when a participant has joined.
 *
 * @param string $meetingid
 * @param bool $ismoderator
 *
 * @return void
 */
function bigbluebuttonbn_participant_joined($meetingid, $ismoderator) {
    $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'mod_bigbluebuttonbn', 'meetings_cache');
    $result = $cache->get($meetingid);
    $meetinginfo = json_decode($result['meeting_info']);
    $meetinginfo->participantCount += 1;
    if ($ismoderator) {
        $meetinginfo->moderatorCount += 1;
    }
    $cache->set($meetingid, array('creation_time' => $result['creation_time'],
        'meeting_info' => json_encode($meetinginfo)));
}

/**
 * Publish an imported recording.
 *
 * @param string $id
 * @param boolean $publish
 *
 * @return boolean
 */
function bigbluebuttonbn_publish_recording_imported($id, $publish = true) {
    global $DB;
    // Locate the record to be updated.
    $record = $DB->get_record('bigbluebuttonbn_logs', array('id' => $id));
    $meta = json_decode($record->meta, true);
    // Prepare data for the update.
    $meta['recording']['published'] = ($publish) ? 'true' : 'false';
    $record->meta = json_encode($meta);
    // Proceed with the update.
    $DB->update_record('bigbluebuttonbn_logs', $record);
    return true;
}

/**
 * Delete an imported recording.
 *
 * @param string $id
 *
 * @return boolean
 */
function bigbluebuttonbn_delete_recording_imported($id) {
    global $DB;
    // Execute delete.
    $DB->delete_records('bigbluebuttonbn_logs', array('id' => $id));
    return true;
}

/**
 * Update an imported recording.
 *
 * @param string $id
 * @param array $params ['key'=>param_key, 'value']
 *
 * @return boolean
 */
function bigbluebuttonbn_update_recording_imported($id, $params) {
    global $DB;
    // Locate the record to be updated.
    $record = $DB->get_record('bigbluebuttonbn_logs', array('id' => $id));
    $meta = json_decode($record->meta, true);
    // Prepare data for the update.
    $meta['recording'] = $params + $meta['recording'];
    $record->meta = json_encode($meta);
    // Proceed with the update.
    if (!$DB->update_record('bigbluebuttonbn_logs', $record)) {
        return false;
    }
    return true;
}

/**
 * Protect/Unprotect an imported recording.
 *
 * @param string $id
 * @param boolean $protect
 *
 * @return boolean
 */
function bigbluebuttonbn_protect_recording_imported($id, $protect = true) {
    global $DB;
    // Locate the record to be updated.
    $record = $DB->get_record('bigbluebuttonbn_logs', array('id' => $id));
    $meta = json_decode($record->meta, true);
    // Prepare data for the update.
    $meta['recording']['protected'] = ($protect) ? 'true' : 'false';
    $record->meta = json_encode($meta);
    // Proceed with the update.
    $DB->update_record('bigbluebuttonbn_logs', $record);
    return true;
}

/**
 * Sets a custom config.xml file for being used on create.
 *
 * @param string $meetingid
 * @param string $configxml
 *
 * @return object
 */
function bigbluebuttonbn_set_config_xml($meetingid, $configxml) {
    $urldefaultconfig = \mod_bigbluebuttonbn\local\config::get('server_url') . 'api/setConfigXML?';
    $configxmlparams = bigbluebuttonbn_set_config_xml_params($meetingid, $configxml);
    $xml = bigbluebutton::bigbluebuttonbn_wrap_xml_load_file(
        $urldefaultconfig,
        'POST',
        $configxmlparams,
        'application/x-www-form-urlencoded'
    );
    return $xml;
}

/**
 * Sets qs used with a custom config.xml file request.
 *
 * @param string $meetingid
 * @param string $configxml
 *
 * @return string
 */
function bigbluebuttonbn_set_config_xml_params($meetingid, $configxml) {
    $params = 'configXML=' . urlencode($configxml) . '&meetingID=' . urlencode($meetingid);
    $sharedsecret = \mod_bigbluebuttonbn\local\config::get('shared_secret');
    $configxmlparams = $params . '&checksum=' . sha1('setConfigXML' . $params . $sharedsecret);
    return $configxmlparams;
}

/**
 * Sets a custom config.xml file for being used on create.
 *
 * @param string $meetingid
 * @param string $configxml
 *
 * @return array
 */
function bigbluebuttonbn_set_config_xml_array($meetingid, $configxml) {
    $configxml = bigbluebuttonbn_set_config_xml($meetingid, $configxml);
    $configxmlarray = (array) $configxml;
    if ($configxmlarray['returncode'] != 'SUCCESS') {
        debugging('BigBlueButton was not able to set the custom config.xml file', DEBUG_DEVELOPER);
        return '';
    }
    return $configxmlarray['configToken'];
}

/**
 * Helper function builds a row for the data used by the recording table.
 *
 * @param array $bbbsession
 * @param array $recording
 * @param array $tools
 *
 * @return array
 */
function bigbluebuttonbn_get_recording_data_row($bbbsession, $recording, $tools = ['protect', 'publish', 'delete']) {
    if (!recording::bigbluebuttonbn_include_recording_table_row($bbbsession, $recording)) {
        return;
    }
    $rowdata = new stdClass();
    // Set recording_types.
    $rowdata->playback = recording::bigbluebuttonbn_get_recording_data_row_types($recording, $bbbsession);
    // Set activity name.
    $rowdata->recording = recording::bigbluebuttonbn_get_recording_data_row_meta_activity($recording, $bbbsession);
    // Set activity description.
    $rowdata->description = recording::bigbluebuttonbn_get_recording_data_row_meta_description($recording, $bbbsession);
    if (recording::bigbluebuttonbn_get_recording_data_preview_enabled($bbbsession)) {
        // Set recording_preview.
        $rowdata->preview = recording::bigbluebuttonbn_get_recording_data_row_preview($recording);
    }
    // Set date.
    $rowdata->date = recording::bigbluebuttonbn_get_recording_data_row_date($recording);
    // Set formatted date.
    $rowdata->date_formatted = recording::bigbluebuttonbn_get_recording_data_row_date_formatted($rowdata->date);
    // Set formatted duration.
    $rowdata->duration_formatted = $rowdata->duration = recording::bigbluebuttonbn_get_recording_data_row_duration($recording);
    // Set actionbar, if user is allowed to manage recordings.
    if ($bbbsession['managerecordings']) {
        $rowdata->actionbar = recording::bigbluebuttonbn_get_recording_data_row_actionbar($recording, $tools);
    }
    return $rowdata;
}

/**
 * Helper function evaluates if a row for the data used by the recording table is editable.
 *
 * @param array $bbbsession
 *
 * @return boolean
 */
function bigbluebuttonbn_get_recording_data_row_editable($bbbsession) {
    return ($bbbsession['managerecordings'] && ((double) $bbbsession['serverversion'] >= 1.0 || $bbbsession['bnserver']));
}

/**
 * Helper function validates a remote resource.
 *
 * @param string $url
 *
 * @return boolean
 */
function bigbluebuttonbn_is_valid_resource($url) {
    $urlhost = parse_url($url, PHP_URL_HOST);
    $serverurlhost = parse_url(\mod_bigbluebuttonbn\local\config::get('server_url'), PHP_URL_HOST);
    // Skip validation when the recording URL host is the same as the configured BBB server.
    if ($urlhost == $serverurlhost) {
        return true;
    }
    // Skip validation when the recording URL was already validated.
    $validatedurls = plugin::bigbluebuttonbn_cache_get('recordings_cache', 'validated_urls', array());
    if (array_key_exists($urlhost, $validatedurls)) {
        return $validatedurls[$urlhost];
    }
    // Validate the recording URL.
    $validatedurls[$urlhost] = true;
    $curlinfo = bigbluebutton::bigbluebuttonbn_wrap_xml_load_file_curl_request($url, 'HEAD');
    if (!isset($curlinfo['http_code']) || $curlinfo['http_code'] != 200) {
        $error = "Resources hosted by " . $urlhost . " are unreachable. Server responded with code " . $curlinfo['http_code'];
        debugging($error, DEBUG_DEVELOPER);
        $validatedurls[$urlhost] = false;
    }
    plugin::bigbluebuttonbn_cache_set('recordings_cache', 'validated_urls', $validatedurls);
    return $validatedurls[$urlhost];
}

/**
 * Helper function render a button for the recording action bar
 *
 * @param array $recording
 * @param array $data
 *
 * @return string
 */
function bigbluebuttonbn_actionbar_render_button($recording, $data) {
    global $OUTPUT;
    if (empty($data)) {
        return '';
    }
    $target = $data['action'];
    if (isset($data['target'])) {
        $target .= '-' . $data['target'];
    }
    $id = 'recording-' . $target . '-' . $recording['recordID'];
    $onclick = 'M.mod_bigbluebuttonbn.recordings.recording' . ucfirst($data['action']) . '(this); return false;';
    if ((boolean) \mod_bigbluebuttonbn\local\config::get('recording_icons_enabled')) {
        // With icon for $manageaction.
        $iconattributes = array('id' => $id, 'class' => 'iconsmall');
        $linkattributes = array(
            'id' => $id,
            'onclick' => $onclick,
            'data-action' => $data['action'],
        );
        if (!isset($recording['imported'])) {
            $linkattributes['data-links'] = bigbluebuttonbn_count_recording_imported_instances(
                $recording['recordID']
            );
        }
        if (isset($data['disabled'])) {
            $iconattributes['class'] .= ' fa-' . $data['disabled'];
            $linkattributes['class'] = 'disabled';
            unset($linkattributes['onclick']);
        }
        $icon = new pix_icon(
            'i/' . $data['tag'],
            get_string('view_recording_list_actionbar_' . $data['action'], 'bigbluebuttonbn'),
            'moodle',
            $iconattributes
        );
        return $OUTPUT->action_icon('#', $icon, null, $linkattributes, false);
    }
    // With text for $manageaction.
    $linkattributes = array('title' => get_string($data['tag']), 'class' => 'btn btn-xs btn-danger',
        'onclick' => $onclick);
    return $OUTPUT->action_link('#', get_string($data['action']), null, $linkattributes);
}

/**
 * Helper function enqueues one user for being validated as for completion.
 *
 * @param object $bigbluebuttonbn
 * @param string $userid
 *
 * @return void
 */
function bigbluebuttonbn_enqueue_completion_update($bigbluebuttonbn, $userid) {
    try {
        // Create the instance of completion_update_state task.
        $task = new \mod_bigbluebuttonbn\task\completion_update_state();
        // Add custom data.
        $data = array(
            'bigbluebuttonbn' => $bigbluebuttonbn,
            'userid' => $userid,
        );
        $task->set_custom_data($data);
        // CONTRIB-7457: Task should be executed by a user, maybe Teacher as Student won't have rights for overriding.
        // $ task -> set_userid ( $ user -> id );.
        // Enqueue it.
        \core\task\manager::queue_adhoc_task($task);
    } catch (Exception $e) {
        mtrace("Error while enqueuing completion_update_state task. " . (string) $e);
    }
}

/**
 * Helper function enqueues completion trigger.
 *
 * @param object $bigbluebuttonbn
 * @param string $userid
 *
 * @return void
 */
function bigbluebuttonbn_completion_update_state($bigbluebuttonbn, $userid) {
    global $CFG;
    require_once($CFG->libdir.'/completionlib.php');
    list($course, $cm) = get_course_and_cm_from_instance($bigbluebuttonbn, 'bigbluebuttonbn');
    $completion = new completion_info($course);
    if (!$completion->is_enabled($cm)) {
        mtrace("Completion not enabled");
        return;
    }
    if (bigbluebuttonbn_get_completion_state($course, $cm, $userid, COMPLETION_AND)) {
        mtrace("Completion succeeded for user $userid");
        $completion->update_state($cm, COMPLETION_COMPLETE, $userid, true);
    } else {
        mtrace("Completion did not succeed for user $userid");
    }
}

/**
 * Helper evaluates if the bigbluebutton server used belongs to blindsidenetworks domain.
 *
 * @return boolean
 */
function bigbluebuttonbn_is_bn_server() {
    if (\mod_bigbluebuttonbn\local\config::get('bn_server')) {
        return true;
    }
    $parsedurl = parse_url(\mod_bigbluebuttonbn\local\config::get('server_url'));
    if (!isset($parsedurl['host'])) {
        return false;
    }
    $h = $parsedurl['host'];
    $hends = explode('.', $h);
    $hendslength = count($hends);
    return ($hends[$hendslength - 1] == 'com' && $hends[$hendslength - 2] == 'blindsidenetworks');
}

/**
 * Helper function returns a list of courses a user has access to, wrapped in an array that can be used
 * by a html select.
 *
 * @param array $bbbsession
 *
 * @return array
 */
function bigbluebuttonbn_import_get_courses_for_select(array $bbbsession) {
    if ($bbbsession['administrator']) {
        $courses = get_courses('all', 'c.fullname ASC');
        // It includes the name of the site as a course (category 0), so remove the first one.
        unset($courses['1']);
    } else {
        $courses = enrol_get_users_courses($bbbsession['userID'], false, 'id,shortname,fullname');
    }
    $coursesforselect = [];
    foreach ($courses as $course) {
        $coursesforselect[$course->id] = $course->fullname . " (" . $course->shortname . ")";
    }
    return $coursesforselect;
}

/**
 * Helper function to convert an html string to plain text.
 *
 * @param string $html
 * @param integer $len
 *
 * @return string
 */
function bigbluebuttonbn_html2text($html, $len = 0) {
    $text = strip_tags($html);
    $text = str_replace('&nbsp;', ' ', $text);
    $textlen = strlen($text);
    $text = mb_substr($text, 0, $len);
    if ($textlen > $len) {
        $text .= '...';
    }
    return $text;
}

/**
 * Helper function to obtain the tags linked to a bigbluebuttonbn activity
 *
 * @param string $id
 *
 * @return string containing the tags separated by commas
 */
function bigbluebuttonbn_get_tags($id) {
    if (class_exists('core_tag_tag')) {
        return implode(',', core_tag_tag::get_item_tags_array('core', 'course_modules', $id));
    }
    return implode(',', tag_get_tags('bigbluebuttonbn', $id));
}

/**
 * Helper function to define the sql used for gattering the bigbluebuttonbnids whose meetingids should be included
 * in the getRecordings request
 *
 * @param string $courseid
 * @param string $bigbluebuttonbnid
 * @param bool   $subset
 *
 * @return string containing the sql used for getting the target bigbluebuttonbn instances
 */
function bigbluebuttonbn_get_recordings_sql_select($courseid, $bigbluebuttonbnid = null, $subset = true) {
    if (empty($courseid)) {
        $courseid = 0;
    }
    if (empty($bigbluebuttonbnid)) {
        return "course = '{$courseid}'";
    }
    if ($subset) {
        return "id = '{$bigbluebuttonbnid}'";
    }
    return "id <> '{$bigbluebuttonbnid}' AND course = '{$courseid}'";
}

/**
 * Helper function to define the sql used for gattering the bigbluebuttonbnids whose meetingids should be included
 * in the getRecordings request considering only those that belong to deleted activities.
 *
 * @param string $courseid
 * @param string $bigbluebuttonbnid
 * @param bool   $subset
 *
 * @return string containing the sql used for getting the target bigbluebuttonbn instances
 */
function bigbluebuttonbn_get_recordings_deleted_sql_select($courseid = 0, $bigbluebuttonbnid = null, $subset = true) {
    $sql = "log = '" . bbb_constants::BIGBLUEBUTTONBN_LOG_EVENT_DELETE . "' AND meta like '%has_recordings%' AND meta like '%true%'";
    if (empty($courseid)) {
        $courseid = 0;
    }
    if (empty($bigbluebuttonbnid)) {
        return $sql . " AND courseid = {$courseid}";
    }
    if ($subset) {
        return $sql . " AND bigbluebuttonbnid = '{$bigbluebuttonbnid}'";
    }
    return $sql . " AND courseid = {$courseid} AND bigbluebuttonbnid <> '{$bigbluebuttonbnid}'";
}

/**
 * Helper function to define the sql used for gattering the bigbluebuttonbnids whose meetingids should be included
 * in the getRecordings request considering only those that belong to imported recordings.
 *
 * @param string $courseid
 * @param string $bigbluebuttonbnid
 * @param bool   $subset
 *
 * @return string containing the sql used for getting the target bigbluebuttonbn instances
 */
function bigbluebuttonbn_get_recordings_imported_sql_select($courseid = 0, $bigbluebuttonbnid = null, $subset = true) {
    $sql = "log = '" . bbb_constants::BIGBLUEBUTTONBN_LOG_EVENT_IMPORT . "'";
    if (empty($courseid)) {
        $courseid = 0;
    }
    if (empty($bigbluebuttonbnid)) {
        return $sql . " AND courseid = '{$courseid}'";
    }
    if ($subset) {
        return $sql . " AND bigbluebuttonbnid = '{$bigbluebuttonbnid}'";
    }
    return $sql . " AND courseid = '{$courseid}' AND bigbluebuttonbnid <> '{$bigbluebuttonbnid}'";
}

/**
 * Helper function to get recordings and imported recordings together.
 *
 * @param string $courseid
 * @param string $bigbluebuttonbnid
 * @param bool   $subset
 * @param bool   $includedeleted
 *
 * @return associative array containing the recordings indexed by recordID, each recording is also a
 * non sequential associative array itself that corresponds to the actual recording in BBB
 */
function bigbluebuttonbn_get_allrecordings($courseid = 0, $bigbluebuttonbnid = null, $subset = true, $includedeleted = false) {
    $recordings = bigbluebuttonbn_get_recordings($courseid, $bigbluebuttonbnid, $subset, $includedeleted);
    $recordingsimported = recording::bigbluebuttonbn_get_recordings_imported_array($courseid, $bigbluebuttonbnid, $subset);
    return ($recordings + $recordingsimported);
}

/**
 * Helper function to retrieve recordings from the BigBlueButton. The references are stored as events
 * in bigbluebuttonbn_logs.
 *
 * @param string $courseid
 * @param string $bigbluebuttonbnid
 * @param bool   $subset
 * @param bool   $includedeleted
 *
 * @return associative array containing the recordings indexed by recordID, each recording is also a
 * non sequential associative array itself that corresponds to the actual recording in BBB
 */
function bigbluebuttonbn_get_recordings($courseid = 0, $bigbluebuttonbnid = null, $subset = true, $includedeleted = false) {
    global $DB;
    $select = bigbluebuttonbn_get_recordings_sql_select($courseid, $bigbluebuttonbnid, $subset);
    $bigbluebuttonbns = $DB->get_records_select_menu('bigbluebuttonbn', $select, null, 'id', 'id, meetingid');
    /* Consider logs from deleted bigbluebuttonbn instances whose meetingids should be included in
     * the getRecordings request. */
    if ($includedeleted) {
        $selectdeleted = bigbluebuttonbn_get_recordings_deleted_sql_select($courseid, $bigbluebuttonbnid, $subset);
        $bigbluebuttonbnsdel = $DB->get_records_select_menu(
            'bigbluebuttonbn_logs',
            $selectdeleted,
            null,
            'bigbluebuttonbnid',
            'bigbluebuttonbnid, meetingid'
        );
        if (!empty($bigbluebuttonbnsdel)) {
            // Merge bigbluebuttonbnis from deleted instances, only keys are relevant.
            // Artimetic merge is used in order to keep the keys.
            $bigbluebuttonbns += $bigbluebuttonbnsdel;
        }
    }
    // Gather the meetingids from bigbluebuttonbn logs that include a create with record=true.
    if (empty($bigbluebuttonbns)) {
        return array();
    }
    // Prepare select for loading records based on existent bigbluebuttonbns.
    $sql = 'SELECT DISTINCT meetingid, bigbluebuttonbnid FROM {bigbluebuttonbn_logs} WHERE ';
    $sql .= '(bigbluebuttonbnid=' . implode(' OR bigbluebuttonbnid=', array_keys($bigbluebuttonbns)) . ')';
    // Include only Create events and exclude those with record not true.
    $sql .= ' AND log = ? AND meta LIKE ? AND meta LIKE ?';
    // Execute select for loading records based on existent bigbluebuttonbns.
    $records = $DB->get_records_sql_menu($sql, array(bbb_constants::BIGBLUEBUTTONBN_LOG_EVENT_CREATE, '%record%', '%true%'));
    // Get actual recordings.
    return recording::bigbluebuttonbn_get_recordings_array(array_keys($records));
}

/**
 * Helper function iterates an array with recordings and unset those already imported.
 *
 * @param array $recordings
 * @param integer $courseid
 * @param integer $bigbluebuttonbnid
 *
 * @return array
 */
function bigbluebuttonbn_unset_existent_recordings_already_imported($recordings, $courseid, $bigbluebuttonbnid) {
    $recordingsimported = recording::bigbluebuttonbn_get_recordings_imported_array($courseid, $bigbluebuttonbnid, true);
    foreach ($recordings as $key => $recording) {
        if (isset($recordingsimported[$recording['recordID']])) {
            unset($recordings[$key]);
        }
    }
    return $recordings;
}

/**
 * Helper function to count the imported recordings for a recordingid.
 *
 * @param string $recordid
 *
 * @return integer
 */
function bigbluebuttonbn_count_recording_imported_instances($recordid) {
    global $DB;
    $sql = 'SELECT COUNT(DISTINCT id) FROM {bigbluebuttonbn_logs} WHERE log = ? AND meta LIKE ? AND meta LIKE ?';
    return $DB->count_records_sql($sql, array(bbb_constants::BIGBLUEBUTTONBN_LOG_EVENT_IMPORT, '%recordID%', "%{$recordid}%"));
}

/**
 * Helper function returns an array with all the instances of imported recordings for a recordingid.
 *
 * @param string $recordid
 *
 * @return array
 */
function bigbluebuttonbn_get_recording_imported_instances($recordid) {
    global $DB;
    $sql = 'SELECT * FROM {bigbluebuttonbn_logs} WHERE log = ? AND meta LIKE ? AND meta LIKE ?';
    $recordingsimported = $DB->get_records_sql($sql, array(bbb_constants::BIGBLUEBUTTONBN_LOG_EVENT_IMPORT, '%recordID%',
        "%{$recordid}%"));
    return $recordingsimported;
}

/**
 * Helper function to get how much callback events are logged.
 *
 * @param string $recordid
 * @param string $callbacktype
 *
 * @return integer
 */
function bigbluebuttonbn_get_count_callback_event_log($recordid, $callbacktype = 'recording_ready') {
    global $DB;
    $sql = 'SELECT count(DISTINCT id) FROM {bigbluebuttonbn_logs} WHERE log = ? AND meta LIKE ? AND meta LIKE ?';
    // Callback type added on version 2.4, validate recording_ready first or assume it on records with no callback.
    if ($callbacktype == 'recording_ready') {
        $sql .= ' AND (meta LIKE ? OR meta NOT LIKE ? )';
        $count = $DB->count_records_sql($sql, array(bbb_constants::BIGBLUEBUTTON_LOG_EVENT_CALLBACK, '%recordid%', "%$recordid%",
            $callbacktype, 'callback'));
        return $count;
    }
    $sql .= ' AND meta LIKE ?;';
    $count = $DB->count_records_sql($sql, array(bbb_constants::BIGBLUEBUTTON_LOG_EVENT_CALLBACK, '%recordid%', "%$recordid%", "%$callbacktype%"));
    return $count;
}

/**
 * Helper function returns an array with the profiles (with features per profile) for the different types
 * of bigbluebuttonbn instances.
 *
 * @return array
 */
function bigbluebuttonbn_get_instance_type_profiles() {
    $instanceprofiles = array(
        bbb_constants::BIGBLUEBUTTONBN_TYPE_ALL => array('id' => bbb_constants::BIGBLUEBUTTONBN_TYPE_ALL,
            'name' => get_string('instance_type_default', 'bigbluebuttonbn'),
            'features' => array('all')),
        bbb_constants::BIGBLUEBUTTONBN_TYPE_ROOM_ONLY => array('id' => bbb_constants::BIGBLUEBUTTONBN_TYPE_ROOM_ONLY,
            'name' => get_string('instance_type_room_only', 'bigbluebuttonbn'),
            'features' => array('showroom', 'welcomemessage', 'voicebridge', 'waitformoderator', 'userlimit',
                'recording', 'sendnotifications', 'preuploadpresentation', 'permissions', 'schedule', 'groups',
                'modstandardelshdr', 'availabilityconditionsheader', 'tagshdr', 'competenciessection',
                'clienttype', 'completionattendance', 'completionengagement', 'availabilityconditionsheader')),
        bbb_constants::BIGBLUEBUTTONBN_TYPE_RECORDING_ONLY => array('id' => bbb_constants::BIGBLUEBUTTONBN_TYPE_RECORDING_ONLY,
            'name' => get_string('instance_type_recording_only', 'bigbluebuttonbn'),
            'features' => array('showrecordings', 'importrecordings', 'availabilityconditionsheader')),
    );
    return $instanceprofiles;
}

/**
 * Helper function returns an array with enabled features for an specific profile type.
 *
 * @param array $typeprofiles
 * @param string $type
 *
 * @return array
 */
function bigbluebuttonbn_get_enabled_features($typeprofiles, $type = null) {
    $enabledfeatures = array();
    $features = $typeprofiles[bbb_constants::BIGBLUEBUTTONBN_TYPE_ALL]['features'];
    if (!is_null($type) && key_exists($type, $typeprofiles)) {
        $features = $typeprofiles[$type]['features'];
    }
    $enabledfeatures['showroom'] = (in_array('all', $features) || in_array('showroom', $features));
    // Evaluates if recordings are enabled for the Moodle site.
    $enabledfeatures['showrecordings'] = false;
    if (\mod_bigbluebuttonbn\local\config::recordings_enabled()) {
        $enabledfeatures['showrecordings'] = (in_array('all', $features) || in_array('showrecordings', $features));
    }
    $enabledfeatures['importrecordings'] = false;
    if (\mod_bigbluebuttonbn\local\config::importrecordings_enabled()) {
        $enabledfeatures['importrecordings'] = (in_array('all', $features) || in_array('importrecordings', $features));
    }
    // Evaluates if clienttype is enabled for the Moodle site.
    $enabledfeatures['clienttype'] = false;
    if (\mod_bigbluebuttonbn\local\config::clienttype_enabled()) {
        $enabledfeatures['clienttype'] = (in_array('all', $features) || in_array('clienttype', $features));
    }
    return $enabledfeatures;
}

/**
 * Helper function returns an array with the profiles (with features per profile) for the different types
 * of bigbluebuttonbn instances that the user is allowed to create.
 *
 * @param boolean $room
 * @param boolean $recording
 *
 * @return array
 */
function bigbluebuttonbn_get_instance_type_profiles_create_allowed($room, $recording) {
    $profiles = bigbluebuttonbn_get_instance_type_profiles();
    if (!$room) {
        unset($profiles[bbb_constants::BIGBLUEBUTTONBN_TYPE_ROOM_ONLY]);
        unset($profiles[bbb_constants::BIGBLUEBUTTONBN_TYPE_ALL]);
    }
    if (!$recording) {
        unset($profiles[bbb_constants::BIGBLUEBUTTONBN_TYPE_RECORDING_ONLY]);
        unset($profiles[bbb_constants::BIGBLUEBUTTONBN_TYPE_ALL]);
    }
    return $profiles;
}

/**
 * Helper function returns an array with the profiles (with features per profile) for the different types
 * of bigbluebuttonbn instances.
 *
 * @param array $profiles
 *
 * @return array
 */
function bigbluebuttonbn_get_instance_profiles_array($profiles = []) {
    $profilesarray = array();
    foreach ($profiles as $key => $profile) {
        $profilesarray[$profile['id']] = $profile['name'];
    }
    return $profilesarray;
}

/**
 * Helper function returns time in a formatted string.
 *
 * @param integer $time
 *
 * @return string
 */
function bigbluebuttonbn_format_activity_time($time) {
    global $CFG;
    require_once($CFG->dirroot.'/calendar/lib.php');
    $activitytime = '';
    if ($time) {
        $activitytime = calendar_day_representation($time) . ' ' .
        get_string('mod_form_field_notification_msg_at', 'bigbluebuttonbn') . ' ' .
        calendar_time_representation($time);
    }
    return $activitytime;
}

/**
 * Helper function returns array with all the strings to be used in javascript.
 *
 * @return array
 */
function bigbluebuttonbn_get_strings_for_js() {
    $locale = bigbluebuttonbn_get_locale();
    $stringman = get_string_manager();
    $strings = $stringman->load_component_strings('bigbluebuttonbn', $locale);
    return $strings;
}

/**
 * Helper function returns the locale set by moodle.
 *
 * @return string
 */
function bigbluebuttonbn_get_locale() {
    $lang = get_string('locale', 'core_langconfig');
    return substr($lang, 0, strpos($lang, '.'));
}

/**
 * Helper function returns the locale code based on the locale set by moodle.
 *
 * @return string
 */
function bigbluebuttonbn_get_localcode() {
    $locale = bigbluebuttonbn_get_locale();
    return substr($locale, 0, strpos($locale, '_'));
}

/**
 * Helper function returns array with the instance settings used in views.
 *
 * @param string $id
 * @param object $bigbluebuttonbnid
 *
 * @return array
 */
function bigbluebuttonbn_view_validator($id, $bigbluebuttonbnid) {
    if ($id) {
        return bigbluebuttonbn_view_instance_id($id);
    }
    if ($bigbluebuttonbnid) {
        return bigbluebuttonbn_view_instance_bigbluebuttonbn($bigbluebuttonbnid);
    }
}

/**
 * Helper function returns array with the instance settings used in views based on id.
 *
 * @param string $id
 *
 * @return array
 */
function bigbluebuttonbn_view_instance_id($id) {
    global $DB;
    $cm = get_coursemodule_from_id('bigbluebuttonbn', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $bigbluebuttonbn = $DB->get_record('bigbluebuttonbn', array('id' => $cm->instance), '*', MUST_EXIST);
    return array('cm' => $cm, 'course' => $course, 'bigbluebuttonbn' => $bigbluebuttonbn);
}

/**
 * Helper function returns array with the instance settings used in views based on bigbluebuttonbnid.
 *
 * @param object $bigbluebuttonbnid
 *
 * @return array
 */
function bigbluebuttonbn_view_instance_bigbluebuttonbn($bigbluebuttonbnid) {
    global $DB;
    $bigbluebuttonbn = $DB->get_record('bigbluebuttonbn', array('id' => $bigbluebuttonbnid), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $bigbluebuttonbn->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bigbluebuttonbn->id, $course->id, false, MUST_EXIST);
    return array('cm' => $cm, 'course' => $course, 'bigbluebuttonbn' => $bigbluebuttonbn);
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_general(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_general_shown()) {
        $renderer->render_group_header('general');
        $renderer->render_group_element(
            'server_url',
            $renderer->render_group_element_text('server_url', bbb_constants::BIGBLUEBUTTONBN_DEFAULT_SERVER_URL)
        );
        $renderer->render_group_element(
            'shared_secret',
            $renderer->render_group_element_text('shared_secret', bbb_constants::BIGBLUEBUTTONBN_DEFAULT_SHARED_SECRET)
        );
    }
}

/**
 * Helper function renders record settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_record(&$renderer) {
    // Configuration for 'recording' feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_record_meeting_shown()) {
        $renderer->render_group_header('recording');
        $renderer->render_group_element(
            'recording_default',
            $renderer->render_group_element_checkbox('recording_default', 1)
        );
        $renderer->render_group_element(
            'recording_editable',
            $renderer->render_group_element_checkbox('recording_editable', 1)
        );
        $renderer->render_group_element(
            'recording_icons_enabled',
            $renderer->render_group_element_checkbox('recording_icons_enabled', 1)
        );

        // Add recording start to load and allow/hide stop/pause.
        $renderer->render_group_element(
            'recording_all_from_start_default',
            $renderer->render_group_element_checkbox('recording_all_from_start_default', 0)
        );
        $renderer->render_group_element(
            'recording_all_from_start_editable',
            $renderer->render_group_element_checkbox('recording_all_from_start_editable', 0)
        );
        $renderer->render_group_element(
            'recording_hide_button_default',
            $renderer->render_group_element_checkbox('recording_hide_button_default', 0)
        );
        $renderer->render_group_element(
            'recording_hide_button_editable',
            $renderer->render_group_element_checkbox('recording_hide_button_editable', 0)
        );
    }
}

/**
 * Helper function renders import recording settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_importrecordings(&$renderer) {
    // Configuration for 'import recordings' feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_import_recordings_shown()) {
        $renderer->render_group_header('importrecordings');
        $renderer->render_group_element(
            'importrecordings_enabled',
            $renderer->render_group_element_checkbox('importrecordings_enabled', 0)
        );
        $renderer->render_group_element(
            'importrecordings_from_deleted_enabled',
            $renderer->render_group_element_checkbox('importrecordings_from_deleted_enabled', 0)
        );
    }
}

/**
 * Helper function renders show recording settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_showrecordings(&$renderer) {
    // Configuration for 'show recordings' feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_show_recordings_shown()) {
        $renderer->render_group_header('recordings');
        $renderer->render_group_element(
            'recordings_html_default',
            $renderer->render_group_element_checkbox('recordings_html_default', 1)
        );
        $renderer->render_group_element(
            'recordings_html_editable',
            $renderer->render_group_element_checkbox('recordings_html_editable', 0)
        );
        $renderer->render_group_element(
            'recordings_deleted_default',
            $renderer->render_group_element_checkbox('recordings_deleted_default', 1)
        );
        $renderer->render_group_element(
            'recordings_deleted_editable',
            $renderer->render_group_element_checkbox('recordings_deleted_editable', 0)
        );
        $renderer->render_group_element(
            'recordings_imported_default',
            $renderer->render_group_element_checkbox('recordings_imported_default', 0)
        );
        $renderer->render_group_element(
            'recordings_imported_editable',
            $renderer->render_group_element_checkbox('recordings_imported_editable', 1)
        );
        $renderer->render_group_element(
            'recordings_preview_default',
            $renderer->render_group_element_checkbox('recordings_preview_default', 1)
        );
        $renderer->render_group_element(
            'recordings_preview_editable',
            $renderer->render_group_element_checkbox('recordings_preview_editable', 0)
        );
        $renderer->render_group_element(
            'recordings_sortorder',
            $renderer->render_group_element_checkbox('recordings_sortorder', 0)
        );
        $renderer->render_group_element(
            'recordings_validate_url',
            $renderer->render_group_element_checkbox('recordings_validate_url', 1)
        );
    }
}

/**
 * Helper function renders wait for moderator settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_waitmoderator(&$renderer) {
    // Configuration for wait for moderator feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_wait_moderator_shown()) {
        $renderer->render_group_header('waitformoderator');
        $renderer->render_group_element(
            'waitformoderator_default',
            $renderer->render_group_element_checkbox('waitformoderator_default', 0)
        );
        $renderer->render_group_element(
            'waitformoderator_editable',
            $renderer->render_group_element_checkbox('waitformoderator_editable', 1)
        );
        $renderer->render_group_element(
            'waitformoderator_ping_interval',
            $renderer->render_group_element_text('waitformoderator_ping_interval', 10, PARAM_INT)
        );
        $renderer->render_group_element(
            'waitformoderator_cache_ttl',
            $renderer->render_group_element_text('waitformoderator_cache_ttl', 60, PARAM_INT)
        );
    }
}

/**
 * Helper function renders static voice bridge settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_voicebridge(&$renderer) {
    // Configuration for "static voice bridge" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_static_voice_bridge_shown()) {
        $renderer->render_group_header('voicebridge');
        $renderer->render_group_element(
            'voicebridge_editable',
            $renderer->render_group_element_checkbox('voicebridge_editable', 0)
        );
    }
}

/**
 * Helper function renders preuploaded presentation settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_preupload(&$renderer) {
    // Configuration for "preupload presentation" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_preupload_presentation_shown()) {
        // This feature only works if curl is installed.
        $preuploaddescripion = get_string('config_preuploadpresentation_description', 'bigbluebuttonbn');
        if (!extension_loaded('curl')) {
            $preuploaddescripion .= '<div class="form-defaultinfo">';
            $preuploaddescripion .= get_string('config_warning_curl_not_installed', 'bigbluebuttonbn');
            $preuploaddescripion .= '</div><br>';
        }
        $renderer->render_group_header('preuploadpresentation', null, $preuploaddescripion);
        if (extension_loaded('curl')) {
            $renderer->render_group_element(
                'preuploadpresentation_enabled',
                $renderer->render_group_element_checkbox('preuploadpresentation_enabled', 0)
            );
        }
    }
}

/**
 * Helper function renders preuploaded presentation manage file if the feature is enabled.
 * This allow to select a file for use as default in all BBB instances if preuploaded presetantion is enable.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_preupload_manage_default_file(&$renderer) {
    // Configuration for "preupload presentation" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_preupload_presentation_shown()) {
        if (extension_loaded('curl')) {
            // This feature only works if curl is installed.
            $renderer->render_filemanager_default_file_presentation("presentation_default");
        }
    }
}

/**
 * Helper function renders userlimit settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_userlimit(&$renderer) {
    // Configuration for "user limit" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_user_limit_shown()) {
        $renderer->render_group_header('userlimit');
        $renderer->render_group_element(
            'userlimit_default',
            $renderer->render_group_element_text('userlimit_default', 0, PARAM_INT)
        );
        $renderer->render_group_element(
            'userlimit_editable',
            $renderer->render_group_element_checkbox('userlimit_editable', 0)
        );
    }
}

/**
 * Helper function renders duration settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_duration(&$renderer) {
    // Configuration for "scheduled duration" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_scheduled_duration_shown()) {
        $renderer->render_group_header('scheduled');
        $renderer->render_group_element(
            'scheduled_duration_enabled',
            $renderer->render_group_element_checkbox('scheduled_duration_enabled', 1)
        );
        $renderer->render_group_element(
            'scheduled_duration_compensation',
            $renderer->render_group_element_text('scheduled_duration_compensation', 10, PARAM_INT)
        );
        $renderer->render_group_element(
            'scheduled_pre_opening',
            $renderer->render_group_element_text('scheduled_pre_opening', 10, PARAM_INT)
        );
    }
}

/**
 * Helper function renders participant settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_participants(&$renderer) {
    // Configuration for defining the default role/user that will be moderator on new activities.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_moderator_default_shown()) {
        $renderer->render_group_header('participant');
        // UI for 'participants' feature.
        $roles = bigbluebuttonbn_get_roles(null, false);
        $owner = array('0' => get_string('mod_form_field_participant_list_type_owner', 'bigbluebuttonbn'));
        $renderer->render_group_element(
            'participant_moderator_default',
            $renderer->render_group_element_configmultiselect(
                'participant_moderator_default',
                array_keys($owner),
                $owner + $roles // CONTRIB-7966: don't use array_merge here so it does not reindex the array.
            )
        );
    }
}

/**
 * Helper function renders notification settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_notifications(&$renderer) {
    // Configuration for "send notifications" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_send_notifications_shown()) {
        $renderer->render_group_header('sendnotifications');
        $renderer->render_group_element(
            'sendnotifications_enabled',
            $renderer->render_group_element_checkbox('sendnotifications_enabled', 1)
        );
    }
}

/**
 * Helper function renders client type settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_clienttype(&$renderer) {
    // Configuration for "clienttype" feature.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_clienttype_shown()) {
        $renderer->render_group_header('clienttype');
        $renderer->render_group_element(
            'clienttype_editable',
            $renderer->render_group_element_checkbox('clienttype_editable', 0)
        );
        // Web Client default.
        $default = intval((int) \mod_bigbluebuttonbn\local\config::get('clienttype_default'));
        $choices = array(bbb_constants::BIGBLUEBUTTON_CLIENTTYPE_FLASH => get_string('mod_form_block_clienttype_flash', 'bigbluebuttonbn'),
            bbb_constants::BIGBLUEBUTTON_CLIENTTYPE_HTML5 => get_string('mod_form_block_clienttype_html5', 'bigbluebuttonbn'));
        $renderer->render_group_element(
            'clienttype_default',
            $renderer->render_group_element_configselect(
                'clienttype_default',
                $default,
                $choices
            )
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_muteonstart(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_muteonstart_shown()) {
        $renderer->render_group_header('muteonstart');
        $renderer->render_group_element(
            'muteonstart_default',
            $renderer->render_group_element_checkbox('muteonstart_default', 0)
        );
        $renderer->render_group_element(
            'muteonstart_editable',
            $renderer->render_group_element_checkbox('muteonstart_editable', 0)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_locksettings(&$renderer) {
    $renderer->render_group_header('locksettings');
    // Configuration for various lock settings for meetings.
    bigbluebuttonbn_settings_disablecam($renderer);
    bigbluebuttonbn_settings_disablemic($renderer);
    bigbluebuttonbn_settings_disableprivatechat($renderer);
    bigbluebuttonbn_settings_disablepublicchat($renderer);
    bigbluebuttonbn_settings_disablenote($renderer);
    bigbluebuttonbn_settings_hideuserlist($renderer);
    bigbluebuttonbn_settings_lockedlayout($renderer);
    bigbluebuttonbn_settings_lockonjoin($renderer);
    bigbluebuttonbn_settings_lockonjoinconfigurable($renderer);
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_disablecam(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_disablecam_shown()) {
        $renderer->render_group_element(
            'disablecam_default',
            $renderer->render_group_element_checkbox('disablecam_default', 0)
        );
        $renderer->render_group_element(
            'disablecam_editable',
            $renderer->render_group_element_checkbox('disablecam_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_disablemic(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_disablemic_shown()) {
        $renderer->render_group_element(
            'disablemic_default',
            $renderer->render_group_element_checkbox('disablemic_default', 0)
        );
        $renderer->render_group_element(
            'disablecam_editable',
            $renderer->render_group_element_checkbox('disablemic_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_disableprivatechat(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_disableprivatechat_shown()) {
        $renderer->render_group_element(
            'disableprivatechat_default',
            $renderer->render_group_element_checkbox('disableprivatechat_default', 0)
        );
        $renderer->render_group_element(
            'disableprivatechat_editable',
            $renderer->render_group_element_checkbox('disableprivatechat_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_disablepublicchat(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_disablepublicchat_shown()) {
        $renderer->render_group_element(
            'disablepublicchat_default',
            $renderer->render_group_element_checkbox('disablepublicchat_default', 0)
        );
        $renderer->render_group_element(
            'disablepublicchat_editable',
            $renderer->render_group_element_checkbox('disablepublicchat_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_disablenote(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_disablenote_shown()) {
        $renderer->render_group_element(
            'disablenote_default',
            $renderer->render_group_element_checkbox('disablenote_default', 0)
        );
        $renderer->render_group_element(
            'disablenote_editable',
            $renderer->render_group_element_checkbox('disablenote_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_hideuserlist(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_hideuserlist_shown()) {
        $renderer->render_group_element(
            'hideuserlist_default',
            $renderer->render_group_element_checkbox('hideuserlist_default', 0)
        );
        $renderer->render_group_element(
            'hideuserlist_editable',
            $renderer->render_group_element_checkbox('hideuserlist_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_lockedlayout(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_lockedlayout_shown()) {
        $renderer->render_group_element(
            'lockedlayout_default',
            $renderer->render_group_element_checkbox('lockedlayout_default', 0)
        );
        $renderer->render_group_element(
            'lockedlayout_editable',
            $renderer->render_group_element_checkbox('lockedlayout_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_lockonjoin(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_lockonjoin_shown()) {
        $renderer->render_group_element(
            'lockonjoin_default',
            $renderer->render_group_element_checkbox('lockonjoin_default', 0)
        );
        $renderer->render_group_element(
            'lockonjoin_editable',
            $renderer->render_group_element_checkbox('lockonjoin_editable', 1)
        );
    }
}

/**
 * Helper function renders general settings if the feature is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_lockonjoinconfigurable(&$renderer) {
    // Configuration for BigBlueButton.
    if ((boolean) \mod_bigbluebuttonbn\settings\validator::section_lockonjoinconfigurable_shown()) {
        $renderer->render_group_element(
            'lockonjoinconfigurable_default',
            $renderer->render_group_element_checkbox('lockonjoinconfigurable_default', 0)
        );
        $renderer->render_group_element(
            'lockonjoinconfigurable_editable',
            $renderer->render_group_element_checkbox('lockonjoinconfigurable_editable', 1)
        );
    }
}

/**
 * Helper function renders default messages settings.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_default_messages(&$renderer) {
    $renderer->render_group_header('default_messages');
    $renderer->render_group_element(
        'welcome_default',
        $renderer->render_group_element_textarea('welcome_default', '', PARAM_TEXT)
    );
}

/**
 * Helper function renders extended settings if any of the features there is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_extended(&$renderer) {
    // Configuration for extended capabilities.
    if (!(boolean) \mod_bigbluebuttonbn\settings\validator::section_settings_extended_shown()) {
        return;
    }
    $renderer->render_group_header('extended_capabilities');
    // UI for 'notify users when recording ready' feature.
    $renderer->render_group_element(
        'recordingready_enabled',
        $renderer->render_group_element_checkbox('recordingready_enabled', 0)
    );
    // Configuration for extended BN capabilities should go here.
}

/**
 * Helper function renders experimental settings if any of the features there is enabled.
 *
 * @param object $renderer
 *
 * @return void
 */
function bigbluebuttonbn_settings_experimental(&$renderer) {
    // Configuration for experimental features should go here.
    $renderer->render_group_header('experimental_features');
    // UI for 'register meeting events' feature.
    $renderer->render_group_element(
        'meetingevents_enabled',
        $renderer->render_group_element_checkbox('meetingevents_enabled', 0)
    );
}

/**
 * Helper function returns a sha1 encoded string that is unique and will be used as a seed for meetingid.
 *
 * @return string
 */
function bigbluebuttonbn_unique_meetingid_seed() {
    global $DB;
    do {
        $encodedseed = sha1(bigbluebuttonbn_random_password(12));
        $meetingid = (string) $DB->get_field('bigbluebuttonbn', 'meetingid', array('meetingid' => $encodedseed));
    } while ($meetingid == $encodedseed);
    return $encodedseed;
}

/**
 * Helper function renders the link used for recording type in row for the data used by the recording table.
 *
 * @param array $recording
 * @param array $bbbsession
 * @param array $playback
 *
 * @return boolean
 */
function bigbluebuttonbn_include_recording_data_row_type($recording, $bbbsession, $playback) {
    // All types that are not restricted are included.
    if (array_key_exists('restricted', $playback) && strtolower($playback['restricted']) == 'false') {
        return true;
    }
    // All types that are not statistics are included.
    if ($playback['type'] != 'statistics') {
        return true;
    }
    // Exclude imported recordings.
    if (isset($recording['imported'])) {
        return false;
    }
    // Exclude non moderators.
    if (!$bbbsession['administrator'] && !$bbbsession['moderator']) {
        return false;
    }
    return true;
}

/**
 * Renders the general warning message.
 *
 * @param string $message
 * @param string $type
 * @param string $href
 * @param string $text
 * @param string $class
 *
 * @return string
 */
function bigbluebuttonbn_render_warning($message, $type = 'info', $href = '', $text = '', $class = '') {
    global $OUTPUT;
    $output = "\n";
    // Evaluates if config_warning is enabled.
    if (empty($message)) {
        return $output;
    }
    $output .= $OUTPUT->box_start(
        'box boxalignleft adminerror alert alert-' . $type . ' alert-block fade in',
        'bigbluebuttonbn_view_general_warning'
    ) . "\n";
    $output .= '    ' . $message . "\n";
    $output .= '  <div class="singlebutton pull-right">' . "\n";
    if (!empty($href)) {
        $output .= bigbluebuttonbn_render_warning_button($href, $text, $class);
    }
    $output .= '  </div>' . "\n";
    $output .= $OUTPUT->box_end() . "\n";
    return $output;
}

/**
 * Renders the general warning button.
 *
 * @param string $href
 * @param string $text
 * @param string $class
 * @param string $title
 *
 * @return string
 */
function bigbluebuttonbn_render_warning_button($href, $text = '', $class = '', $title = '') {
    if ($text == '') {
        $text = get_string('ok', 'moodle');
    }
    if ($title == '') {
        $title = $text;
    }
    if ($class == '') {
        $class = 'btn btn-secondary';
    }
    $output = '  <form method="post" action="' . $href . '" class="form-inline">' . "\n";
    $output .= '      <button type="submit" class="' . $class . '"' . "\n";
    $output .= '          title="' . $title . '"' . "\n";
    $output .= '          >' . $text . '</button>' . "\n";
    $output .= '  </form>' . "\n";
    return $output;
}

/**
 * Check if a BigBlueButtonBN is available to be used by the current user.
 *
 * @param  stdClass  $bigbluebuttonbn  BigBlueButtonBN instance
 *
 * @return boolean                     status if room available and current user allowed to join
 */
function bigbluebuttonbn_get_availability_status($bigbluebuttonbn) {
    list($roomavailable) = bigbluebuttonbn_room_is_available($bigbluebuttonbn);
    list($usercanjoin) = meeting::bigbluebuttonbn_user_can_join_meeting($bigbluebuttonbn);
    return ($roomavailable && $usercanjoin);
}

/**
 * Helper for evaluating if scheduled activity is avaiable.
 *
 * @param  stdClass  $bigbluebuttonbn  BigBlueButtonBN instance
 *
 * @return array                       status (room available or not and possible warnings)
 */
function bigbluebuttonbn_room_is_available($bigbluebuttonbn) {
    $open = true;
    $closed = false;
    $warnings = array();

    $timenow = time();
    $timeopen = $bigbluebuttonbn->openingtime;
    $timeclose = $bigbluebuttonbn->closingtime;
    if (!empty($timeopen) && $timeopen > $timenow) {
        $open = false;
    }
    if (!empty($timeclose) && $timenow > $timeclose) {
        $closed = true;
    }

    if (!$open || $closed) {
        if (!$open) {
            $warnings['notopenyet'] = userdate($timeopen);
        }
        if ($closed) {
            $warnings['expired'] = userdate($timeclose);
        }
        return array(false, $warnings);
    }

    return array(true, $warnings);
}

/**
 * Helper for getting the owner userid of a bigbluebuttonbn instance.
 *
 * @param  stdClass $bigbluebuttonbn  BigBlueButtonBN instance
 *
 * @return integer ownerid (a valid user id or null if not registered/found)
 */
function bigbluebuttonbn_instance_ownerid($bigbluebuttonbn) {
    global $DB;
    $filters = array('bigbluebuttonbnid' => $bigbluebuttonbn->id, 'log' => 'Add');
    $ownerid = (integer) $DB->get_field('bigbluebuttonbn_logs', 'userid', $filters);
    return $ownerid;
}

/**
 * Helper evaluates if the bigbluebutton server used belongs to blindsidenetworks domain.
 *
 * @return boolean
 */
function bigbluebuttonbn_has_html5_client() {
    $checkurl = \mod_bigbluebuttonbn\local\bigbluebutton::root() . "html5client/check";
    $curlinfo = bigbluebutton::bigbluebuttonbn_wrap_xml_load_file_curl_request($checkurl, 'HEAD');
    return (isset($curlinfo['http_code']) && $curlinfo['http_code'] == 200);
}

/**
 * Return the status of an activity [open|not_started|ended].
 *
 * @param array $bbbsession
 * @return string
 */
function bigbluebuttonbn_view_get_activity_status(&$bbbsession) {
    $now = time();
    if (!empty($bbbsession['bigbluebuttonbn']->openingtime) && $now < $bbbsession['bigbluebuttonbn']->openingtime) {
        // The activity has not been opened.
        return 'not_started';
    }
    if (!empty($bbbsession['bigbluebuttonbn']->closingtime) && $now > $bbbsession['bigbluebuttonbn']->closingtime) {
        // The activity has been closed.
        return 'ended';
    }
    // The activity is open.
    return 'open';
}

/**
 * Set session URLs.
 *
 * @param array $bbbsession
 * @param int $id
 * @return string
 */
function bigbluebuttonbn_view_session_config(&$bbbsession, $id) {
    // Operation URLs.
    $bbbsession['bigbluebuttonbnURL'] = plugin::necurl(
        '/mod/bigbluebuttonbn/view.php',
        ['id' => $bbbsession['cm']->id]
    );
    $bbbsession['logoutURL'] = plugin::necurl(
        '/mod/bigbluebuttonbn/bbb_view.php',
        ['action' => 'logout', 'id' => $id, 'bn' => $bbbsession['bigbluebuttonbn']->id]
    );
    $bbbsession['recordingReadyURL'] = plugin::necurl(
        '/mod/bigbluebuttonbn/bbb_broker.php',
        ['action' => 'recording_ready', 'bigbluebuttonbn' => $bbbsession['bigbluebuttonbn']->id]
    );
    $bbbsession['meetingEventsURL'] = plugin::necurl(
        '/mod/bigbluebuttonbn/bbb_broker.php',
        ['action' => 'meeting_events', 'bigbluebuttonbn' => $bbbsession['bigbluebuttonbn']->id]
    );
    $bbbsession['joinURL'] = plugin::necurl(
        '/mod/bigbluebuttonbn/bbb_view.php',
        ['action' => 'join', 'id' => $id, 'bn' => $bbbsession['bigbluebuttonbn']->id]
    );

    // Check status and set extra values.
    $activitystatus = bigbluebuttonbn_view_get_activity_status($bbbsession); // In locallib.
    if ($activitystatus == 'ended') {
        $bbbsession['presentation'] = bigbluebuttonbn_get_presentation_array(
            $bbbsession['context'],
            $bbbsession['bigbluebuttonbn']->presentation
        );
    } else if ($activitystatus == 'open') {
        $bbbsession['presentation'] = bigbluebuttonbn_get_presentation_array(
            $bbbsession['context'],
            $bbbsession['bigbluebuttonbn']->presentation,
            $bbbsession['bigbluebuttonbn']->id
        );
    }

    return $activitystatus;
}
