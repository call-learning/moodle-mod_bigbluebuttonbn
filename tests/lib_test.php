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
 * BBB Library tests class.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2018 - present, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Laurent David (laurent@call-learning.fr)
 */

defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->dirroot . '/mod/bigbluebuttonbn/lib.php');

/**
 * BBB Library tests class.
 *
 * @package   mod_bigbluebuttonbn
 * @copyright 2018 - present, Blindside Networks Inc
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Laurent David (laurent@call-learning.fr)
 */
class mod_bigbluebuttonbn_lib_testcase extends advanced_testcase {

    public $bbactivity = null;
    public $bbactivitycm = null;
    public $course = null;
    public $bbformdata = null;

    function setUp() {
        parent::setUp();
        set_config('enablecompletion', true);// Enable completion

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => true]);
        $this->bbactivity = $this->getDataGenerator()->create_module(
                'bigbluebuttonbn',
                array('course' => $this->course->id),
                ['visible'=>true]
        );
        list($course, $this->bbactivitycm) = get_course_and_cm_from_instance($this->bbactivity->id, 'bigbluebuttonbn');
        $this->bbformdata = (object) array(
                'type' => $this->bbactivity->type,
                'name' => $this->bbactivity->name,
                'showdescription' => '0',
                'welcome' => '',
                'voicebridge' => 0,
                'userlimit' => 0,
                'record' => 1,
                'recordallfromstart' => 0,
                'recordhidebutton' => '0',
                'muteonstart' => 0,
                'recordings_html' => 1,
                'recordings_deleted' => 1,
                'recordings_preview' => 1,
                'mform_isexpanded_id_permissions' => 1,
                'participants' => '[{"selectiontype":"all","selectionid":"all","role":"viewer"}]',
                'openingtime' => 0,
                'closingtime' => 0,
                'visible' => 1,
                'visibleoncoursepage' => 1,
                'cmidnumber' => '',
                'groupmode' => '0',
                'groupingid' => '0',
                'availabilityconditionsjson' => '{"op":"&","c":[],"showc":[]}',
                'tags' =>
                        array(),
                'course' => $this->bbactivity->course,
                'coursemodule' => $this->bbactivitycm->id,
                'section' => 0,
                'module' => $this->bbactivity->cmid,
                'modulename' => 'bigbluebuttonbn',
                'instance' => $this->bbactivity->id,
                'add' => '',
                'update' => 301,
                'return' => 1,
                'sr' => 0,
                'competencies' =>
                        array(),
                'competency_rule' => '0',
                'submitbutton' => 'Save and display',
                'completion' => 0,
                'completionview' => 0,
                'completionexpected' => 0,
                'completiongradeitemnumber' => null,
                'conditiongradegroup' =>
                        array(),
                'conditionfieldgroup' =>
                        array(),
                'intro' => '',
                'introformat' => '1',
                'timemodified' => time(),
                'wait' => 0,
                'recordings_imported' => 0,
                'id' => $this->bbactivity->id,
                'presentation' => '',
                'meetingid' => '6f1625737af37880e490dbe4d1fc293ba6f85235',
        );

    }

    function test_bigbluebuttonbn_supports() {
        $this->resetAfterTest();
        $this->assertTrue(bigbluebuttonbn_supports(FEATURE_IDNUMBER));
        $this->assertTrue(bigbluebuttonbn_supports(FEATURE_MOD_INTRO));
        $this->assertFalse(bigbluebuttonbn_supports(FEATURE_GRADE_HAS_GRADE));
    }

    function test_bigbluebuttonbn_get_completion_state() {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $result = bigbluebuttonbn_get_completion_state($this->course, $this->bbactivitycm, $user->id, COMPLETION_AND);
        $this->assertEquals(COMPLETION_AND, $result);
    }

    function test_bigbluebuttonbn_add_instance() {
        $this->resetAfterTest();
        $id = bigbluebuttonbn_add_instance($this->bbformdata);
        $this->assertNotNull($id);
    }

    function test_bigbluebuttonbn_update_instance() {
        $this->resetAfterTest();
        $result = bigbluebuttonbn_update_instance($this->bbformdata);
        $this->assertTrue($result);
    }

    function test_bigbluebuttonbn_delete_instance() {
        $this->resetAfterTest();
        $result = bigbluebuttonbn_delete_instance($this->bbactivity->id);
        $this->assertTrue($result);
    }

    function test_bigbluebuttonbn_delete_instance_log() {
        global $DB;
        $this->resetAfterTest();
        bigbluebuttonbn_delete_instance_log($this->bbactivity);
        $this->assertTrue($DB->record_exists('bigbluebuttonbn_logs', array('bigbluebuttonbnid' => $this->bbactivity->id,
                'log' => BIGBLUEBUTTONBN_LOG_EVENT_DELETE)));
    }

    function test_bigbluebuttonbn_user_outline() {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $result = bigbluebuttonbn_user_outline($this->course, $user, null, $this->bbactivity);
        $this->assertEquals('', $result);

        // Now create a couple of logs
        $overrides = array('meetingid' => $this->bbactivity->meetingid);
        $meta = '{"origin":0}';
        bigbluebuttonbn_log($this->bbactivity, BIGBLUEBUTTONBN_LOG_EVENT_JOIN, $overrides, $meta);
        bigbluebuttonbn_log($this->bbactivity, BIGBLUEBUTTONBN_LOG_EVENT_PLAYED, $overrides);
        $result = bigbluebuttonbn_user_outline($this->course, $user, null, $this->bbactivity);
        $this->assertRegExp('/.* has joined the session for 2 times/', $result);
    }

    function test_bigbluebuttonbn_user_complete() {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $overrides = array('meetingid' => $this->bbactivity->meetingid);
        $meta = '{"origin":0}';
        bigbluebuttonbn_log($this->bbactivity, BIGBLUEBUTTONBN_LOG_EVENT_JOIN, $overrides, $meta);
        bigbluebuttonbn_log($this->bbactivity, BIGBLUEBUTTONBN_LOG_EVENT_PLAYED, $overrides);
        $result = bigbluebuttonbn_user_complete($this->course, $user, $this->bbactivity);
        $this->assertEquals(2, $result);
    }

    function test_bigbluebuttonbn_get_extra_capabilities() {
        $this->resetAfterTest();
        $this->assertEquals(array('moodle/site:accessallgroups'), bigbluebuttonbn_get_extra_capabilities());
    }

    function test_bigbluebuttonbn_reset_course_items() {
        global $CFG;
        $this->resetAfterTest();
        $CFG->bigbluebuttonbn_recordings_enabled = false;
        $results = bigbluebuttonbn_reset_course_items();
        $this->assertEquals(array("events" => 0, "tags" => 0, "logs" => 0), $results);
        $CFG->bigbluebuttonbn_recordings_enabled = true;
        $results = bigbluebuttonbn_reset_course_items();
        $this->assertEquals(array("events" => 0, "tags" => 0, "logs" => 0, "recordings" => 0), $results);
    }

    function test_bigbluebuttonbn_reset_course_form_definition() {
        global $CFG, $PAGE;
        $PAGE->set_course($this->course);
        $this->setAdminUser();
        $this->resetAfterTest();
        include_once($CFG->dirroot.'/mod/bigbluebuttonbn/mod_form.php');
        $data =  new stdClass();
        $data->instance = $this->bbactivity;
        $data->id = $this->bbactivity->id;
        $data->course = $this->bbactivity->course;

        $form = new mod_bigbluebuttonbn_mod_form($data, 1, $this->bbactivitycm, $this->course);
        $refclass = new ReflectionClass("mod_bigbluebuttonbn_mod_form");
        $formprop =$refclass->getProperty('_form');
        $formprop->setAccessible(true);


        /** @var $mform MoodleQuickForm quickform object definition */
        $mform = $formprop->getValue($form);
        bigbluebuttonbn_reset_course_form_definition($mform);
        $this->assertNotNull($mform->getElement('bigbluebuttonbnheader'));
    }

    function test_bigbluebuttonbn_reset_course_form_defaults() {
        global $CFG;
        $this->resetAfterTest();
        $results = bigbluebuttonbn_reset_course_form_defaults($this->course);
        $this->assertEquals(array (
                'reset_bigbluebuttonbn_events' => 0,
                'reset_bigbluebuttonbn_tags' => 0,
                'reset_bigbluebuttonbn_logs' => 0,
                'reset_bigbluebuttonbn_recordings' => 0,
        ), $results);
    }

    function test_bigbluebuttonbn_reset_userdata() {
        global $CFG;
        $this->resetAfterTest();
        $data =  new stdClass();
        $data->courseid = $this->course->id;
        $data->reset_bigbluebuttonbn_tags = true;
        $data->reset_bigbluebuttonbn_tags = true;
        $data->course = $this->bbactivity->course;
        $results = bigbluebuttonbn_reset_userdata($data);
        $this->assertEquals(array (
                'component' => 'BigBlueButtonBN',
                'item' => 'Deleted tags',
                'error' => false,
        ), $results[0]);
    }

    function test_bigbluebuttonbn_reset_getstatus() {
        $this->resetAfterTest();
        $result = bigbluebuttonbn_reset_getstatus('events');
        $this->assertEquals(array(
                'component' => 'BigBlueButtonBN',
                'item' => 'Deleted events',
                'error' => false,
        ), $result);
    }

    function test_bigbluebuttonbn_reset_events() {
        global $DB;
        $this->resetAfterTest();
        $otherbbactity = $this->getDataGenerator()->create_module(
                'bigbluebuttonbn',
                array('course' => $this->course->id),
                ['visible'=>true]
        );
        $this->assertEquals(2, $DB->count_records(
                'event',
                array('modulename' => 'bigbluebuttonbn', 'courseid' => $this->course->id)));
        bigbluebuttonbn_reset_events($this->course->id);
        $this->assertEquals(0, $DB->count_records(
                'event',
                array('modulename' => 'bigbluebuttonbn', 'courseid' => $this->course->id)));
    }

    function test_bigbluebuttonbn_reset_tags() {
        $this->resetAfterTest();
        bigbluebuttonbn_reset_tags($this->course->id);
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_reset_logs() {
        $this->resetAfterTest();
        bigbluebuttonbn_reset_logs($this->course->id);
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_reset_recordings() {
        $this->resetAfterTest();
        bigbluebuttonbn_reset_recordings($this->course->id);
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_get_view_actions() {
        $this->resetAfterTest();
        $this->assertEquals(array('view', 'view all'), bigbluebuttonbn_get_view_actions());
    }

    function test_bigbluebuttonbn_get_post_actions() {
        $this->resetAfterTest();
        $this->assertEquals(array('update', 'add', 'delete'), bigbluebuttonbn_get_post_actions());
    }

    function test_bigbluebuttonbn_print_overview() {
        $this->resetAfterTest();
        $htmlarray = [];
        bigbluebuttonbn_print_overview(array($this->course), $htmlarray);
        $this->assertEquals([], $htmlarray);
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_print_overview_element() {
        $this->resetAfterTest();
        $str = bigbluebuttonbn_print_overview_element($this->bbactivity, time());
        $this->assertEquals("", $str);
    }

    function test_bigbluebuttonbn_get_coursemodule_info() {
        $this->resetAfterTest();
        $info = bigbluebuttonbn_get_coursemodule_info($this->bbactivitycm);
        $this->assertEquals($info->name, $this->bbactivity->name);
    }

    function test_mod_bigbluebuttonbn_get_completion_active_rule_descriptions() {
        $this->resetAfterTest();
        $cminfo = $this->bbactivitycm;
        /** @var $cminfo cm_info */
        $cmrecord = $cminfo->get_course_module_record();
        $cmrecord->customcompletionrules['completionrules'] =
                array('completionattendance' => 50, 'completionengagementdesc' => true);
        update_module($cmrecord);
        // Retrieve new info
        list($course, $cminfo) = get_course_and_cm_from_instance($this->bbactivity->id, 'bigbluebuttonbn');
        $descriptions = mod_bigbluebuttonbn_get_completion_active_rule_descriptions($cminfo);
        $this->assertEquals($descriptions, $this->bbactivity->name);
    }

    function test_bigbluebuttonbn_process_pre_save() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_process_pre_save_instance() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_process_pre_save_checkboxes() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_process_pre_save_common() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_process_post_save() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_process_post_save_notification() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_process_post_save_event() {
        $this->resetAfterTest();
        $this->setAdminUser();
        $eventsink = $this->redirectEvents();
        $this->bbformdata->openingtime = time();
        bigbluebuttonbn_process_post_save_event($this->bbformdata);
        $this->assertNotEmpty($eventsink->get_events());
    }

    function test_bigbluebuttonbn_process_post_save_completion() {
        $this->resetAfterTest();
        $this->setAdminUser();
        $eventsink = $this->redirectEvents();
        $this->bbformdata->completionexpected = 1;
        bigbluebuttonbn_process_post_save_completion($this->bbformdata);
        $this->assertNotEmpty($eventsink->get_events());
    }

    function test_bigbluebuttonbn_get_media_file() {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $formdata = $this->bbformdata;
        $mediafilepath = bigbluebuttonbn_get_media_file($formdata);
        $this->assertEmpty($mediafilepath);

        // From test_delete_original_file_from_draft (lib/test/filelib_test.php)
        // Create a bbb private file.
        $bbbfilerecord = new stdClass;
        $bbbfilerecord->contextid = context_module::instance($formdata->coursemodule)->id;
        $bbbfilerecord->component = 'mod_bigbluebuttonbn';
        $bbbfilerecord->filearea = 'presentation';
        $bbbfilerecord->itemid = 0;
        $bbbfilerecord->filepath = '/';
        $bbbfilerecord->filename = 'bbfile.pptx';
        $bbbfilerecord->source = 'test';
        $fs = get_file_storage();
        $bbbfile = $fs->create_file_from_string($bbbfilerecord, 'Presentation file content');
        file_prepare_draft_area($formdata->presentation,
                context_module::instance($formdata->coursemodule)->id,
                'mod_bigbluebuttonbn',
                'presentation', 0);

        $mediafilepath = bigbluebuttonbn_get_media_file($formdata);
        $this->assertEquals('/bbfile.pptx', $mediafilepath);
    }

    function test_bigbluebuttonbn_pluginfile() {
        $this->resetAfterTest();
        $this->assertTrue(bigbluebuttonbn_pluginfile_valid(context_module::instance($this->bbactivitycm->id),
                'presentationdefault'));
        $this->assertFalse(bigbluebuttonbn_pluginfile_valid(context_module::instance($this->bbactivitycm->id),
                'presentationdefaultaaa'));
    }

    function test_bigbluebuttonbn_pluginfile_valid() {
        $this->resetAfterTest();
        $this->assertFalse(bigbluebuttonbn_pluginfile_valid(context_course::instance($this->course->id), 'presentation'));
        $this->assertTrue(bigbluebuttonbn_pluginfile_valid(context_system::instance(), 'presentation'));
        $this->assertFalse(bigbluebuttonbn_pluginfile_valid(context_system::instance(), 'otherfilearea'));
    }

    function test_bigbluebuttonbn_pluginfile_file() {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'editingteacher');
        // From test_delete_original_file_from_draft (lib/test/filelib_test.php)
        // Create a bbb private file.
        $formdata = $this->bbformdata;
        $context = context_module::instance($formdata->coursemodule);

        $bbbfilerecord = new stdClass;
        $bbbfilerecord->contextid = $context->id;
        $bbbfilerecord->component = 'mod_bigbluebuttonbn';
        $bbbfilerecord->filearea = 'presentation';
        $bbbfilerecord->itemid = 0;
        $bbbfilerecord->filepath = '/';
        $bbbfilerecord->filename = 'bbfile.pptx';
        $bbbfilerecord->source = 'test';
        $fs = get_file_storage();
        $bbbfile = $fs->create_file_from_string($bbbfilerecord, 'Presentation file content');
        file_prepare_draft_area($formdata->presentation,
                context_module::instance($formdata->coursemodule)->id,
                'mod_bigbluebuttonbn',
                'presentation', 0);

        $mediafile = bigbluebuttonbn_pluginfile_file($this->course, $this->bbactivitycm, $context,'presentation','/bbfile.pptx');

    }

    function test_bigbluebuttonbn_default_presentation_get_file() {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'editingteacher');
        // From test_delete_original_file_from_draft (lib/test/filelib_test.php)
        // Create a bbb private file.
        $formdata = $this->bbformdata;
        $context = context_module::instance($formdata->coursemodule);

        $bbbfilerecord = new stdClass;
        $bbbfilerecord->contextid = $context->id;
        $bbbfilerecord->component = 'mod_bigbluebuttonbn';
        $bbbfilerecord->filearea = 'presentation';
        $bbbfilerecord->itemid = 0;
        $bbbfilerecord->filepath = '/';
        $bbbfilerecord->filename = 'bbfile.pptx';
        $bbbfilerecord->source = 'test';
        $fs = get_file_storage();
        $bbbfile = $fs->create_file_from_string($bbbfilerecord, 'Presentation file content');
        file_prepare_draft_area($formdata->presentation,
                context_module::instance($formdata->coursemodule)->id,
                'mod_bigbluebuttonbn',
                'presentation', 0);

        $mediafile = bigbluebuttonbn_default_presentation_get_file($this->course, $this->bbactivitycm, $context,'presentation','/bbfile.pptx');
    }

    function test_bigbluebuttonbn_pluginfile_filename() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_get_file_areas() {
        $this->resetAfterTest();
        $this->assertEquals(array(
                'presentation' => 'Presentation content',
                'presentationdefault' => 'Presentation default content',
        ), bigbluebuttonbn_get_file_areas());
    }

    function test_bigbluebuttonbn_view() {
        $this->resetAfterTest();
        bigbluebuttonbn_view($this->bbactivity, $this->course, $this->bbactivitycm, context_module::instance($bbcm->id));
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_check_updates_since() {
        $this->resetAfterTest();
        $result = bigbluebuttonbn_check_updates_since($this->bbactivitycm, 0);
        $this->assertEquals(
                '{"configuration":{"updated":false},"contentfiles":{"updated":false},"introfiles":{"updated":false},"completion":{"updated":false}}',
                json_encode($result)
        );
    }

    function test_mod_bigbluebuttonbn_get_fontawesome_icon_map() {
        $this->resetAfterTest();
        $this->assertEquals(array('update', 'add', 'delete'), mod_bigbluebuttonbn_get_fontawesome_icon_map());
    }

    function test_mod_bigbluebuttonbn_core_calendar_provide_event_action() {
        $this->fail('Test feature not yet completed...');
    }

    function test_bigbluebuttonbn_log() {
        global $DB;
        $this->resetAfterTest();
        bigbluebuttonbn_log($this->bbactivity, BIGBLUEBUTTONBN_LOG_EVENT_PLAYED);
        $this->assertTrue($DB->record_exists('bigbluebuttonbn_logs', array('bigbluebuttonbnid' => $this->bbactivity->id)));
    }

    function test_bigbluebuttonbn_extend_settings_navigation() {
        global $PAGE, $CFG;
        $this->resetAfterTest();
        $CFG->bigbluebuttonbn_meetingevents_enabled = true;
        $PAGE->set_cm($this->bbactivitycm);
        $PAGE->set_context(context_module::instance($this->bbactivitycm->id));
        $PAGE->set_url('/mod/bigbluebuttonbn/view.php', ['id' => $this->bbactivitycm->id]);

        $settingnav = $PAGE->settingsnav;
        $node = navigation_node::create('testnavigationnode');
        bigbluebuttonbn_extend_settings_navigation($settingnav, $node);
        $this->fail('Test feature not yet completed...');
    }
}


