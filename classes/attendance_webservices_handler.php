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
 *
 * @package    local_attendance
 * @copyright  2015 Caio Bressan Doneda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../../config.php');
require_once(dirname(__FILE__).'/../locallib.php');
require_once(dirname(__FILE__).'/structure.php');
require_once(dirname(__FILE__).'/../../../lib/sessionlib.php');
require_once(dirname(__FILE__).'/../../../lib/datalib.php');

class attendance_handler {
    /**
     * For this user, this method searches in all the courses that this user has permission to take attendance,
     * looking for today sessions and returns the courses with the sessions.
     */
    public static function get_courses_with_today_sessions($userid) {
        global $USER;

        if ($userid != $USER->id) {
            throw new moodle_exception('nopermissions');
        }

        $usercourses = enrol_get_users_courses($userid);
        $attendanceinstance = get_all_instances_in_courses('attendance', $usercourses);

        $coursessessions = array();

        foreach ($attendanceinstance as $attendance) {
            $context = context_course::instance($attendance->course);
            if (has_capability('mod/attendance:takeattendances', $context, $userid)) {
                $course = $usercourses[$attendance->course];
                $course->attendance_instance = array();

                $att = new stdClass();
                $att->id = $attendance->id;
                $att->course = $attendance->course;
                $att->name = $attendance->name;
                $att->grade = $attendance->grade;

                $cm = new stdClass();
                $cm->id = $attendance->coursemodule;

                $att = new mod_attendance_structure($att, $cm, $course, $context);
                $course->attendance_instance[$att->id] = array();
                $course->attendance_instance[$att->id]['name'] = $att->name;
                $todaysessions = $att->get_today_sessions();

                if (!empty($todaysessions)) {
                    $course->attendance_instance[$att->id]['today_sessions'] = $todaysessions;
                    $coursessessions[$course->id] = $course;
                }
            }
        }

        return self::prepare_data($coursessessions);
    }

    private static function prepare_data($coursessessions) {
        $courses = array();

        foreach ($coursessessions as $c) {
            $courses[$c->id] = new stdClass();
            $courses[$c->id]->shortname = $c->shortname;
            $courses[$c->id]->fullname = $c->fullname;
            $courses[$c->id]->attendance_instances = $c->attendance_instance;
        }

        return $courses;
    }

    /*
     ** For this session, returns all the necessary data to take an attendance
     */
    public static function get_session($sessionid) {
        global $DB, $USER;

        $session = $DB->get_record('attendance_sessions', array('id' => $sessionid));
        $session->courseid = $DB->get_field('attendance', 'course', array('id' => $session->attendanceid));
        $session->statuses = attendance_get_statuses($session->attendanceid, true, $session->statusset);
        $coursecontext = context_course::instance($session->courseid);
        
        if (!has_capability('mod/attendance:takeattendances', $coursecontext, $USER->id)) {
            throw new moodle_exception('nopermissions');
        }

        $session->users = get_enrolled_users($coursecontext, 'mod/attendance:canbelisted', 0, 'u.id, u.firstname, u.lastname');
        $session->attendance_log = array();

        if ($attendancelog = $DB->get_records('attendance_log', array('sessionid' => $sessionid),
                                              '', 'studentid, statusid, remarks, id')) {
            $session->attendance_log = $attendancelog;
        }

        $fieldid = get_config('attendance', 'rfidfield');

        foreach ($session->users as $user) {
            $session->users[$user->id]->rfid = $DB->get_field('user_info_data', 'data', array('fieldid' => $fieldid,
                                                              'userid' => $user->id));
        }

        return $session;
    }

    public static function update_user_status($sessionid, $studentid, $takenbyid, $statusid, $statusset) {
        global $DB, $USER;
        
        if ($takenbyid != $USER->id) {
            throw new moodle_exception('nopermissions');
        }

        $record = new stdClass();
        $record->statusset = $statusset;
        $record->sessionid = $sessionid;
        $record->timetaken = time();
        $record->takenby = $takenbyid;
        $record->statusid = $statusid;
        $record->studentid = $studentid;

        if ($attendancesession = $DB->get_record('attendance_sessions', array('id' => $sessionid))) {
            $attendancesession->lasttaken = time();
            $attendancesession->lasttakenby = $takenbyid;
            $attendancesession->timemodified = time();

            $DB->update_record('attendance_sessions', $attendancesession);
        }

        if ($attendancelog = $DB->get_record('attendance_log', array('sessionid' => $sessionid, 'studentid' => $studentid))) {
            $record->id = $attendancelog->id;
            $DB->update_record('attendance_log', $record);
        } else {
            $DB->insert_record('attendance_log', $record);
        }

        return "200";
    }

    public static function associate_rfid_value($studentid, $rfid) {
        global $DB;

        $fieldid = get_config('attendance', 'rfidfield');

        $record = new stdClass();
        $record->userid = $studentid;
        $record->fieldid = $fieldid;
        $record->data = $rfid;
        $record->dataformat = 0;

        $sql = "SELECT uid.id
                  FROM {user_info_data} uid
                 WHERE uid.fieldid = :fieldid AND uid.data LIKE '" . $rfid ."'";

        if (!$DB->record_exists_sql($sql, array('fieldid' => $fieldid))) {
            if ($DB->record_exists('user_info_data', array('userid' => $studentid, 'fieldid' => $fieldid))) {
                return "This user alread have a RFID associated";
            }

            $DB->insert_record('user_info_data', $record);

            return "successful association";
        } else {
            return "RFID already used";
        }
    }
}
