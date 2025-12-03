<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/activities.lib.php');
require_once(__DIR__.'/workflow.lib.php');

use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\workflow_lib;

use \stdClass;

class utils_lib {

    /**
     * Create a user stub object from a username.
     *
     * @param string $username
     * @return object
     */
    public static function user_stub($username) {
        $mdluser = \core_user::get_user_by_username($username);
        if (empty($mdluser)) {
            return null;
        }
        $user = new \stdClass();
        $user->un = $mdluser->username;
        $user->fn = $mdluser->firstname;
        $user->ln = $mdluser->lastname;

        return $user;
    }

    public static function student_stub($username) {
        $mdluser = \core_user::get_user_by_username($username);
        if (empty($mdluser)) {
            return null;
        }
        $user = new \stdClass();
        $user->un = $mdluser->username;
        $user->fn = $mdluser->firstname;
        $user->ln = $mdluser->lastname;
        
        // load custom fields
        profile_load_custom_fields($mdluser);
        $user->year = isset($mdluser->profile['Year']) ? $mdluser->profile['Year'] : '';

        return $user;
    }

    /**
     * Search staff.
     *
     * @param string $query
     * @return array results
     */
    public static function search_staff($query) {
        global $DB;

        $sql = "SELECT DISTINCT u.*, d.*
                FROM mdl_user u
                JOIN mdl_user_info_data d ON u.id = d.userid
                JOIN mdl_user_info_field f ON d.fieldid = f.id
                WHERE f.shortname = 'CampusRoles'
                AND d.data LIKE '%:Staff%'
                AND u.suspended = 0
                AND (
                    LOWER(REPLACE(u.firstname, '''', '')) LIKE ?
                    OR LOWER(REPLACE(u.lastname, '''', '')) LIKE ?
                    OR LOWER(REPLACE(u.username, '''', '')) LIKE ?
                )";

        // remove apostrophes
        $likesearch = "%" . strtolower(str_replace("'", "", $query)) . "%";
        $data = $DB->get_records_sql($sql, [$likesearch, $likesearch, $likesearch]);
        
        $first10Elements = array_slice($data, 0, 10);

        $staff = [];
        foreach ($first10Elements as $row) {
            $staff[] = static::user_stub($row->username);
        }
        return $staff;
    }

    /**
     * Search students.
     *
     * @param string $query
     * @return array results
     */
    public static function search_students($query) {
        global $DB;

        $sql = "SELECT DISTINCT u.username
        FROM {user} u, {user_info_field} f, {user_info_data} d
        WHERE u.id = d.userid 
		AND d.fieldid = f.id
		AND f.shortname = 'CampusRoles'
		AND d.data LIKE '%:Student%'
        AND u.suspended = 0        
        AND (
            LOWER(REPLACE(u.firstname, '''', '')) LIKE ?
            OR LOWER(REPLACE(u.lastname, '''', '')) LIKE ?
            OR LOWER(REPLACE(u.username, '''', '')) LIKE ?
            OR LOWER(REPLACE(CONCAT(u.firstname, u.lastname), '''', '')) LIKE ?
        )";

        $likesearch = "%" . strtolower(str_replace("'", "", $query)) . "%";
        $data = $DB->get_records_sql($sql, [$likesearch, $likesearch, $likesearch, $likesearch]);

        $first20Elements = array_slice($data, 0, 20);

        $students = [];
        foreach ($first20Elements as $row) {
            $students[] = static::student_stub($row->username);
        }
        return $students;
    }

    /**
     * Get users courses.
     *
     * @return array results
     */
    public static function get_users_courses($user = null) {
        global $DB, $USER;

        if (!$user) {
            $user = $USER;
        }

        $out = array();

        // First process courses that the user is enrolled in.
        $courses = enrol_get_users_courses($user->id, true, 'enddate');
        $timenow = time();
        foreach ($courses as $course) {
            // Remove ended courses.
            if ($course->enddate && ($timenow > $course->enddate)) {
                continue;
            }
            $out[] = array(
                'id' => $course->id,
                'fullname' => $course->fullname,
            );
        }

        // Next process all other courses.
        $courses = get_courses();
        foreach ($courses as $course) {
            // Skip course if already in list.
            if (in_array($course->id, array_column($out, 'id'))) {
                continue;
            }
            // Remove ended courses.
            if ($course->enddate && ($timenow > $course->enddate)) {
                continue;
            }

            // Get the course category and skip if not a part of Primary or Senior.
            $allowed = false;
            $allowedcats = array(2, 3); // ids of allowed categories. Child categories are also allowed.
            $sql = "SELECT path FROM {course_categories} WHERE id = {$course->category}";
            $catpath = $DB->get_field_sql($sql, null);
            foreach ($allowedcats as $allowedcat) {
                if(preg_match('/\/' . $allowedcat . '(\/|$)/', $catpath)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                continue;
            }

            $out[] = array(
                'id' => $course->id,
                'fullname' => $course->fullname,
            );
        }

        // Sort by course name.
        usort($out, function($a, $b) {
            return $a['fullname'] <=> $b['fullname'];
        });

        return $out;
    }

    public static function get_students_from_courses($courseids) {
        global $DB;

        list($insql, $inparams) = $DB->get_in_or_equal($courseids);
        $sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname
                  FROM {user} u, {user_enrolments} ue, {enrol} e, {course} c, {role_assignments} ra, {context} cn, {role} r
                 WHERE c.id $insql
                   AND e.courseid = c.id
                   AND ue.enrolid = e.id
                   AND cn.instanceid = c.id
                   AND cn.contextlevel = 50
                   AND u.id = ue.userid
                   AND ra.contextid =  cn.id
                   AND ra.userid = ue.userid
                   AND r.id = ra.roleid
                   AND r.shortname = 'student'";
        $records = $DB->get_records_sql($sql, $inparams);
        $students = array();

        foreach($records as $rec) {
            $student = static::student_stub($rec->username);
            if (!$student) {
                continue;
            }
            $student->permission = -1;
            $student->parents = [];
            $students[] = $student;
        }

        return $students;
    }

    /**
     * Get users groups.
     *
     * @return array results
     */
    public static function get_users_groups($user = null) {
        global $DB, $USER;

        if (!$user) {
            $user = $USER;
        }

        $out = array();

        $courses = static::get_users_courses($user);
        foreach ($courses as $course) {
            // Get the groups in this course.
            $groups = $DB->get_records('groups', array('courseid' => $course['id']));
            foreach ($groups as $group) {
                $out[] = array(
                    'id' => $group->id,
                    'fullname' => $course['fullname'] . ' > ' . $group->name,
                );
            }
        }
        
        // Sort by course name.
        usort($out, function($a, $b) {
            return $a['fullname'] <=> $b['fullname'];
        });
        
        return $out;
    }

    public static function get_students_from_groups($groupids) {
        global $DB;

        list($insql, $inparams) = $DB->get_in_or_equal($groupids);
        $sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname 
                FROM mdl_groups g, mdl_groups_members m, mdl_user u, mdl_user_enrolments ue, mdl_enrol e, mdl_role_assignments ra, mdl_context cn, mdl_role r
                WHERE g.id $insql
                AND m.groupid = g.id
                AND u.id = m.userid
                AND e.courseid = g.courseid
                AND ue.enrolid = e.id
                AND cn.instanceid = g.courseid
                AND cn.contextlevel = 50
                AND u.id = ue.userid
                AND ra.contextid =  cn.id
                AND ra.userid = ue.userid
                AND ra.userid = m.userid
                AND r.id = ra.roleid
                AND r.shortname = 'student'";
        $records = $DB->get_records_sql($sql, $inparams);

        $students = array();
        foreach($records as $r) {
            $student = static::student_stub($r->username);
            if (!$student) {
                continue;
            }
            $students[] = $student;
        }

        return $students;
    }

    public static function get_students_from_taglist($taglistid) {
        global $DB;

        try {

            $config = get_config('local_announcements2');
            $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');

            $sql = $config->taglistuserssql . ' :taglistseq';

            $taglistusers = array();

            $params = array(
                'taglistseq' => $taglistid,
            );
            $rows = $externalDB->get_records_sql($sql, $params);
            $taglistusers = array_unique(array_column($rows, 'id'));

            // Join on mdl_users to ensure they exist as students.
            list($insql, $params) = $DB->get_in_or_equal($taglistusers);
            $sql = "SELECT u.username
                      FROM {user} u
                INNER JOIN {user_info_data} ud ON ud.userid = u.id
                INNER JOIN {user_info_field} uf ON uf.id = ud.fieldid
                     WHERE u.username $insql
                       AND uf.shortname = 'CampusRoles'
                       AND LOWER(ud.data) LIKE '%student%'
                       AND u.suspended = 0
                       AND u.deleted = 0";
            $usernames = $DB->get_records_sql($sql, $params);

            // Convert usernames to students.
            $students = array();
            foreach($usernames as $r) {
                $student = static::student_stub($r->username);
                if (!$student) {
                    continue;
                }
                $students[] = $student;
            }

            return $students;
        } catch (Exception $ex) {
            // Error.
        }

    }
    

    /**
     * Check if the current user has the capability to create an activity.
     *
     * @param int $activityid
     * @return boolean
     */
    public static function has_capability_create_activity() {
        global $USER, $DB;

        //Any staff memeber
        
        return true;
    }

    /**
     * Check if the current user has the capability to edit a given team.
     *
     * @param int $activityid
     * @return boolean
     */
    public static function has_capability_edit_activity($activityid) {
        $activity = activities_lib::get_activity($activityid);
        if ($activity->usercanedit) {
            return true;
        }
        return false;
    }


    /**
     * Get the user's campus roles.
     *
     * @return string
     */
    public static function get_cal_roles($username) {
        return array(
            workflow_lib::is_cal_reviewer() ? "cal_reviewer" : ''
        );
    }

    
    /*public static function renderTemplate($template, $data = []) {
        global $CFG;
        
        // Extract data variables for use in the template
        extract($data);
        
        // Start output buffering
        ob_start();
    
        // Include the template file
        include $template;
    
        // Get the buffered content
        return ob_get_clean();
    }*/



    // This function is only called in generate_permissions and cron_emails_user, when permissions are needed.
    // We need to ensure that mentors does not include parents that are not allowed to provide permissions.
    public static function get_user_mentors($userid) {
        global $DB;

        $mentors = array();
        $mentorssql = "SELECT u.username
                         FROM {role_assignments} ra, {context} c, {user} u
                        WHERE c.instanceid = :menteeid
                          AND c.contextlevel = :contextlevel
                          AND ra.contextid = c.id
                          AND u.id = ra.userid";
        $mentorsparams = array(
            'menteeid' => $userid,
            'contextlevel' => CONTEXT_USER
        );

        if ($mentors = $DB->get_records_sql($mentorssql, $mentorsparams)) {
            $mentors = array_column($mentors, 'username');
        }

        $disallowedparents = static::get_disallowed_parents($userid);
        $mentors = array_diff($mentors, $disallowedparents);
        
        return $mentors;
    }


    public static function get_disallowed_parents($userid, $multiple = 0) {
        global $DB, $CFG;

        $disallowedparents = array();

        $config = get_config('local_announcements2');
        if (empty($config->dbtype)) {
            return $disallowedparents;
        }
        $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
        $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');

        // Get any blanked out users.
        if (!empty($config->getdisalloweduserssql)) {
            $results = $externalDB->get_records_sql($config->getdisalloweduserssql);
            $disallowedparents = array_column($results, 'userid');
        }

        // Check liveswith flag.
        if ($multiple) {
            foreach ($userid as $id) {
                $user = \core_user::get_user($id);
                if (empty($user)) {
                    continue;
                }
                $liveswithsql = "SELECT * FROM cgs.UVW_Mentors WHERE StudentID = ? AND LivesWithFlag = 0";
                $liveswithresults = $externalDB->get_records_sql($liveswithsql, array($user->username));
                $doesnotlivewithparents = array_column($liveswithresults, 'observerid');
                $disallowedparents = array_merge($disallowedparents, $doesnotlivewithparents);
            }
        } else {
            $user = \core_user::get_user($userid);
            if (empty($user)) {
                return array();
            }
            $liveswithsql = "SELECT * FROM cgs.UVW_Mentors WHERE StudentID = ? AND LivesWithFlag = 0";
            $liveswithresults = $externalDB->get_records_sql($liveswithsql, array($user->username));
            $doesnotlivewithparents = array_column($liveswithresults, 'observerid');
            $disallowedparents = array_merge($disallowedparents, $doesnotlivewithparents);
        }

        return $disallowedparents;
    }

    public static function get_user_mentees($userid, $checkliveswith = false) {
        global $DB;

        // Get mentees for user.
        $mentees = array();
        $menteessql = "SELECT u.username
                         FROM {role_assignments} ra, {context} c, {user} u
                        WHERE ra.userid = :mentorid
                          AND ra.contextid = c.id
                          AND c.instanceid = u.id
                          AND c.contextlevel = :contextlevel";     
        $menteesparams = array(
            'mentorid' => $userid,
            'contextlevel' => CONTEXT_USER
        );
        if ($mentees = $DB->get_records_sql($menteessql, $menteesparams)) {
            $mentees = array_column($mentees, 'username');
        }

        if ($checkliveswith) {
            $config = get_config('local_announcements2');
            if (empty($config->dbtype)) {
                return $mentees;
            }
            foreach ($mentees as $i => $mentee) {
                $parent = \core_user::get_user($userid);
                $liveswithsql = "SELECT * FROM cgs.UVW_Mentors WHERE ObserverID = ? AND StudentID = ? AND LivesWithFlag = 1";
                $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
                $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');
                $liveswithresults = $externalDB->get_records_sql($liveswithsql, array($parent->username, $mentee));
                if (empty($liveswithresults)) {
                    unset($mentees[$i]);
                }
            }
        }

        return $mentees;
    }


    public static function get_users_mentors($userids) {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids);

        $mentors = array();
        $mentorssql = "SELECT u.username
                         FROM {role_assignments} ra, {context} c, {user} u
                        WHERE c.instanceid $insql
                          AND c.contextlevel = 30
                          AND ra.contextid = c.id
                          AND u.id = ra.userid";
        if ($mentors = $DB->get_records_sql($mentorssql, $inparams)) {
            $mentors = array_column($mentors, 'username');
        }

        // Remove disallowed parents
        $disallowedparents = static::get_disallowed_parents($userids, 1);
        $mentors = array_diff($mentors, $disallowedparents);

        return $mentors;
    }
    

    public static function get_userids($usernames) {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($usernames);

        $sql = "SELECT id
                FROM {user}
                WHERE username $insql";
                
        return array_values(array_column($DB->get_records_sql($sql, $inparams), 'id'));
    }

    public static function require_staff() {
        global $USER;
        
        profile_load_custom_fields($USER);
        $campusroles = strtolower($USER->profile['CampusRoles']);
        if (strpos($campusroles, 'staff') !== false) {
            return true;
        }

        throw new \required_capability_exception(\context_system::instance(), 'local/activities:manage', 'nopermissions', '');
        exit;
    }


    public static function is_user_staff() {
        global $USER;
        
        profile_load_custom_fields($USER);
        $campusroles = strtolower($USER->profile['CampusRoles']);
        if (strpos($campusroles, 'staff') !== false) {
            return true;
        }

        return false;
    }

    public static function is_user_parent() {
        global $USER;
        
        profile_load_custom_fields($USER);
        $campusroles = strtolower($USER->profile['CampusRoles']);
        if (strpos($campusroles, 'parent') !== false) {
            return true;
        }

        return false;
    }

    public static function is_user_approver() {
        global $USER;

        $approvertypes = workflow_lib::get_approver_types($USER->username);
        if ($approvertypes) {
            return true;
        }
        
        return false;
    }

    public static function get_user_taglists() {
        global $USER;

        try {

            $config = get_config('local_announcements2');
            if (empty($config->usertaglistssql)) {
                return [];
            }
            $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');

            $sql = $config->usertaglistssql . ' :username';
            $params = array(
                'username' => $USER->username
            );

            $results = $externalDB->get_records_sql($sql, $params);

            $taglists = [];
            foreach ($results as $row) {
                $taglists[] = (object) [
                    "id" => $row->taglistsseq,
                    "name" => $row->description,
                ];
            }

            return $taglists;

        } catch (Exception $ex) {
            // Error.
        }
    }

    public static function get_public_taglists() {
        global $USER;

        try {

            $config = get_config('local_announcements2');
            if (empty($config->publictaglistssql)) {
                return [];
            }
            $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');

            $sql = $config->publictaglistssql;

            $results = $externalDB->get_records_sql($sql);

            $taglists = [];
            foreach ($results as $row) {
                $taglists[] = (object) [
                    "id" => $row->taglistsseq,
                    "name" => $row->description,
                ];
            }

            return $taglists;

        } catch (Exception $ex) {
            // Error.
        }
    }

    public static function search($text) {
        global $DB;

        $sql = "SELECT id, status
                  FROM mdl_activities
                 WHERE deleted = 0
                   AND (LOWER(activityname) LIKE ? OR staffincharge LIKE ? OR creator LIKE ?)
                ORDER BY timestart ASC";
        $params = array();
        $params[] = '%'.strtolower($text).'%';
        $params[] = '%'.strtolower($text).'%';
        $params[] = '%'.strtolower($text).'%';
        //echo "<pre>"; var_export($sql); var_export($params); exit;

        $records = $DB->get_records_sql($sql, $params);
        $activities = array();
        foreach ($records as $record) {
            $activity = new Activity($record->id);
            $exported = $activity->export();
            if((!$exported->usercanedit) && $exported->status < static::ACTIVITY_STATUS_INREVIEW) {
                continue;
            }
            $activities[] = $exported;
        }

        return $activities;
    }


    public static function search_public($text) {
        global $DB;

        $sql = "SELECT id, status
                  FROM mdl_activities
                 WHERE deleted = 0
                   AND displaypublic = 1
                   AND (status = " . activities_lib::ACTIVITY_STATUS_APPROVED . " OR (status = " . activities_lib::ACTIVITY_STATUS_INREVIEW . " AND pushpublic = 1))
                   AND (LOWER(activityname) LIKE ? OR staffincharge LIKE ? OR creator LIKE ?)
                ORDER BY timestart ASC";
        $params = array();
        $params[] = '%'.strtolower($text).'%';
        $params[] = '%'.strtolower($text).'%';
        $params[] = '%'.strtolower($text).'%';
        //echo "<pre>"; var_export($sql); var_export($params); exit;

        $records = $DB->get_records_sql($sql, $params);
        $activities = array();
        foreach ($records as $record) {
            $activity = new Activity($record->id);
            $exported = $activity->export_minimal();
            $activities[] = $exported;
        }

        return $activities;
    }

    public static function normalize_text($text) {
        // First, ensure UTF-8
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Fix Windows-1252 / smart quotes / dashes / ellipsis
        $map = [
            "\xE2\x80\x98" => "'",   // left single quote
            "\xE2\x80\x99" => "'",   // right single quote / apostrophe
            "\xE2\x80\x9C" => '"',   // left double quote
            "\xE2\x80\x9D" => '"',   // right double quote
            "\xE2\x80\x93" => "-",   // en dash
            "\xE2\x80\x94" => "-",   // em dash
            "\xE2\x80\xA6" => "...", // ellipsis
            "\xC2\xA0"     => " ",   // non-breaking space
            "\xE2\x80\x95" => "-",   // horizontal bar
        ];

        $text = strtr($text, $map);

        // Optionally strip out control characters
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

        return $text;
    }

        

}