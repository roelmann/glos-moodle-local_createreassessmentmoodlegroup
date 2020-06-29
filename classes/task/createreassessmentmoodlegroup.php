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
 * A scheduled task for scripted database integrations - category creation.
 *
 * @package    local_createcohorttables - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_createreassessmentmoodlegroup\task;
use stdClass;
use coursecat;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');
require_once($CFG->dirroot.'/group/lib.php');
require_once($CFG->dirroot.'/cohort/lib.php');
/**
 * A scheduled task creatubg reassessment groups in moodle.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class createreassessmentmoodlegroup extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_createreassessmentmoodlegroup');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;
        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();

        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');

        // Tables to change for cohorts in integrations table.
        $usrenrolgrouptab = get_string('usr_data_student_assessment', 'local_createreassessmentmoodlegroup');
        $grouptab = get_string('groupreassessmenttable', 'local_createreassessmentmoodlegroup');

        // SB Code Specific to plugin needs changing.
        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$usrenrolgrouptab) {
            echo 'Levels Table not defined.<br>';
            return 0;
        } else {
            echo 'Levels Table: ' . $usrenrolgrouptab . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$grouptab) {
            echo 'Categories Table not defined.<br>';
            return 0;
        } else {
            echo 'Categories Table: ' . $grouptab . '<br>';
        }

        // SB end of checks for custom plugin.

        // DB check.
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        // Array initialised for group data.
        $groups = array();
        // Read data from group reassessment table in integrations database.
        $sql = "SELECT * FROM " . $grouptab;
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $groups[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external catlevel table, ' . $grouptab . '<br>';
            return 4;
        }
        // Code for creating the groups in moodle database
        // Object created and required values stored in object and passed through to moodle core function for groups
        // which reguires this to be an object with correct names.
        $groupobj = new stdClass();
        foreach ($groups as $group) {
            // Splits the group name.
            $groupname = explode('_', $group['group_name']);
            // Gets course values where course idnumber matches the first part of group name.
            $cl = strlen($group['group_name']);
            $coursename = substr($group['group_name'], 0, $cl-6);
            echo $coursename . "\n";
            $sql4 = "SELECT * FROM {course} WHERE idnumber = '" . $coursename ."'";
            $courseid = $DB->get_records_sql($sql4);
            foreach ($courseid as $c) {
                // Checks course id is not empty.
                if (!(empty($c))) {
                    echo 'CourseID : ' . "\n";
                    print_r($c);
                    $sql3 = "SELECT * FROM {groups} WHERE courseid = " .
                        $c->id . " AND name LIKE '%" . $group['group_name'] . "%'";
                    $existingmdlgroup = $DB->get_records_sql($sql3);
                    if (empty($existingmdlgroup)) {
                        $groupobj->name = $group['group_name'];
                        $groupobj->idnumber = $group['group_name'];
                        $groupobj->courseid = $c->id;
                        groups_create_group($groupobj);
                        echo "Group created: " .$group['group_name']. "\n";
                    }

                    $groupid = $DB->get_records_sql("select * from {groups} WHERE idnumber = '" . $group['group_name'] ."' ORDER BY id DESC LIMIT 1");
                    foreach ($groupid as $g) {
                        $restricted = new stdClass();
                        $sqlrestrictcheck = "SELECT * FROM {course_modules} WHERE idnumber = '" . $group['group_name'] ."'";
                        if (!empty($DB->get_records_sql($sqlrestrictcheck))){
                            $sqlrestrict = "SELECT * FROM {course_modules}
                                WHERE course = " . $c->id . " AND
                                idnumber = '" . $group['group_name'] ."'";
                            $restricted = $DB->get_records_sql($sqlrestrict);
                            foreach ($restricted as $r) {

                                if(!empty($r)) {
                                    echo 'RESTRICTED';
                                    echo $r->idnumber;

                                    // Function to grant permission to group to access assessment.
                                    $this->grantPermission($c->id, $r->section, $r->id, $g->id);
                                } else {
                                    echo "Assessment module code does not exist";
                                }
                            }
                        }
                    }
                }
                rebuild_course_cache($c->id, true);
            }
        }
    }

    /**
     * giving permits to a group for a particular section of a course
     * @param $course course that contains the section to restrict access
     * @param $sectionid id of the section to restrict access
     * @param $groupid id of the group will have access
     */
    function grantPermission($course, $sectionid, $moduleid, $groupid ){

        global $DB;

        $restriction = '{"op":"&","c":[{"type":"group","id":'. $groupid .'}],"showc":[true]}';

        $course_module = new stdClass();
        $course_module->id = $moduleid;
        $course_module->course = $course;
        $course_module->section = $sectionid;
        $course_module->availability = $restriction;

        $res = $DB->update_record('course_modules', $course_module);

        if ($res) {
            rebuild_course_cache($course, true);
        }
    return $res;
    }
}
