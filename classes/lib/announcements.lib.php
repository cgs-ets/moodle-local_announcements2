<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/announcement.class.php');
require_once(__DIR__.'/service.lib.php');
require_once(__DIR__.'/utils.lib.php');

use \local_announcements2\lib\Announcement;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\utils_lib;
use \moodle_exception;

/**
 * Activity lib
 */
class announcements_lib {

    /** Table to store this persistent model instances. */
    const TABLE = 'ann2_posts';

 


    /**
     * Get and decorate the data.
     * Only staff should be allowed to do this...
     * 
     * @param int $id activity id
     * @return array
     */
    public static function get_announcement($id) {        
        if (!utils_lib::is_user_staff()) {
            throw new \Exception("Permission denied.");
            exit;
        }
        $activity = new Activity($id);
        if ($id != $activity->get('id')) {
            throw new \Exception("Activity not found.");
            exit;
        }
        $exported = $activity->export();
        if((!$exported->usercanedit) && $exported->status < static::ACTIVITY_STATUS_INREVIEW) {
            throw new \Exception("Permission denied.");
            exit;
        }
        return $exported;
    }


    public static function get_audiences() {
        global $DB, $USER;

        // Courses, Groups, Houses, Communities, Years, Campuses, Users
        $audiences = new \stdClass();
        $audiences->courses = [
            'label' => 'Courses',
            'roles' => ['staff', 'students', 'parents'],
            'items' => [],
        ];
        $audiences->groups = [
            'label' => 'Groups',
            'roles' => ['staff', 'students', 'parents'],
            'items' => [],
        ];
        $audiences->houses = [
            'label' => 'Houses',
            'roles' => ['staff', 'students', 'parents'],
            'items' => [],
        ];
        $audiences->communities = [
            'label' => 'Communities',
            'roles' => ['staff', 'students', 'parents'],
            'items' => [],
        ];
        $audiences->years = [
            'label' => 'Years',
            'roles' => ['staff', 'students', 'parents'],
            'items' => [],
        ];
        $audiences->campuses = [
            'label' => 'Campuses',
            'roles' => [],
            'items' => [],
        ];
        $audiences->users = [
            'label' => 'Users',
            'roles' => ['Users', 'Parents'],
            'items' => [],
        ];

        // COURSES
        $audiences->courses['items'] = self::get_course_audiences(['PRI-ACADEMIC', 'SEN-ACADEMIC', 'SEN-COCUR', 'PILOTS', 'PRI-COCURR']);
        
        // HOUSES
        $audiences->houses['items'] = self::get_course_audiences(['SEN-HOUSES']);

        // COMMUNITIES
        $audiences->communities['items'] = self::get_course_audiences(['COMMUNITY', 'STAFF']);

        $combinedcourses = array_merge($audiences->courses['items'], $audiences->houses['items']);

        // GROUPS
        $audiences->groups['items'] = self::get_group_audiences($combinedcourses);

        // clear indexes from children arrays
        foreach ($audiences as &$audience) {
            $audience['items'] = array_values($audience['items'] ?? []);
            foreach ($audience['items'] as &$item) {
                $item['children'] = array_values($item['children'] ?? []);
            }
        }

        // YEARS
        $audiences->years['items'] = self::get_years_audiences();

        // CAMPUSES
        $audiences->campuses['items'] = self::get_campuses_audiences();
        
        return $audiences;
    }

    /**
     * COURSES
     * Get all courses the user is enrolled in and has local/announcements:post capability 
     * Look for courses under the following cats: 
     * PRI-ACADEMIC, SEN-ACADEMIC, SEN-COCUR, PILOTS, PRI-COCURR
     * If user is Admin, get all courses
     */
    public static function get_course_audiences($categories) {
        global $DB, $USER;

        $courses = [];

        [$insql, $inparams] = $DB->get_in_or_equal($categories);

        $sql = "SELECT mc.*
                FROM mdl_course mc
                JOIN mdl_course_categories c2
                    ON mc.category = c2.id
                JOIN mdl_course_categories c1
                    ON c2.path LIKE CONCAT('%/', c1.id, '%')
                WHERE c1.idnumber $insql
                ORDER BY mc.fullname ASC";
        $params = $inparams;

        if (!has_capability('moodle/site:config', \context_system::instance())) {
            $sql = "SELECT mc.*
                FROM mdl_course mc
                JOIN mdl_course_categories c2
                    ON mc.category = c2.id
                JOIN mdl_course_categories c1
                    ON c2.path LIKE CONCAT('%/', c1.id, '%')
                JOIN (
                    SELECT e.courseid
                    FROM mdl_enrol e
                    JOIN mdl_user_enrolments ue
                        ON ue.enrolid = e.id
                    WHERE ue.userid = ?
                ) enrolled
                    ON enrolled.courseid = mc.id
                WHERE c1.idnumber $insql
                ORDER BY mc.fullname ASC";
            $params = array_merge($inparams, [$USER->id]);
        }

        $courses = $DB->get_records_sql($sql, $params);

        // Check capabilities for each course
        foreach ($courses as $course) {
            if (has_capability('local/announcements2:post', \context_course::instance($course->id))
                || has_capability('local/announcements2:administer', \context_system::instance())
                || has_capability('local/announcements:post', \context_course::instance($course->id)) // backwards compatibility
                || has_capability('moodle/site:config', \context_system::instance()) // Moodle admins
            ) {
                $courses[$course->id] = [
                    'id' => $course->id,
                    'label' => $course->fullname,
                ];
            }
        }

        return $courses;
    }

    
/**
         * GROUPS
         * Get all groups for the courses we already have.
         */
    public static function get_group_audiences($courses) {
        global $DB, $USER;

        $groupsaudience = [];

        if (count($courses) > 0) {
            $courseids = array_column($courses, 'id');
            [$insql, $inparams] = $DB->get_in_or_equal($courseids);
            
            $sql = "SELECT g.*, c.fullname as coursename
                    FROM mdl_groups g
                    JOIN mdl_course c
                        ON g.courseid = c.id
                    WHERE g.courseid $insql";
            $groups = $DB->get_records_sql($sql, $inparams);

            // Organise groups by course
            $groupsByCourse = [];
            foreach ($groups as $group) {
                $groupsByCourse[$group->courseid][] = $group;
            }
            
            foreach ($groupsByCourse as $courseid => $groups) {
                $groupsaudience[$courseid] = [
                    'id' => $courseid,
                    'label' => $groups[0]->coursename,
                    'children' => array_map(function($group) {
                        return [
                            'id' => $group->id,
                            'label' => $group->name,
                        ];
                    }, $groups),
                ];
            }
        }
        return $groupsaudience;
    }

    /* Hard coded */
    public static function get_years_audiences() {

        $years = [];
        $years[] = [
            'id' => '23340',
            'label' => 'Pre-School',
        ];
        $years[] = [
            'id' => '23346',
            'label' => 'Pre-Kindergarten',
        ];
        $years[] = [
            'id' => '23345',
            'label' => 'Kindergarten',
        ];
        $years[] = [
            'id' => '23348',
            'label' => 'Year 1',
        ];
        $years[] = [
            'id' => '23343',
            'label' => 'Year 2',
        ];
        $years[] = [
            'id' => '23347',
            'label' => 'Year 3',
        ];
        $years[] = [
            'id' => '23341',
            'label' => 'Year 4',
        ];
        $years[] = [
            'id' => '23342',
            'label' => 'Year 5',
        ];
        $years[] = [
            'id' => '23344',
            'label' => 'Year 6',
        ];
        $years[] = [
            'id' => '5591',
            'label' => 'Year 7',
        ];
        $years[] = [
            'id' => '38460',
            'label' => 'Year 8',
        ];
        $years[] = [
            'id' => '5587',
            'label' => 'Year 9',
        ];
        $years[] = [
            'id' => '5588',
            'label' => 'Year 10',
        ];
        $years[] = [
            'id' => '5590',
            'label' => 'Year 11',
        ];
        $years[] = [
            'id' => '5592',
            'label' => 'Year 12',
        ];

        return $years;
    }


    /* Hard coded */
    public static function get_campuses_audiences() {

        $campuses = [];

        // Whole School
        $campuses[] = [
            'id' => 'whole-school',
            'label' => 'Whole School',
            'children' => [
                [
                    'id' => '8160',
                    'label' => 'Staff',
                ],
                [
                    'id' => '8159',
                    'label' => 'Casuals and contractors',
                ],
                [
                    'id' => '999991',
                    'label' => 'Parents',
                ],
                [
                    'id' => '61404',
                    'label' => 'Future Parents',
                ],
                [
                    'id' => '999992',
                    'label' => 'Students',
                ],
            ],
        ];

        // Senior School
        $campuses[] = [
            'id' => 'senior',
            'label' => 'Senior School',
            'children' => [
                [
                    'id' => '5879',
                    'label' => 'Staff',
                ],
                [
                    'id' => '8150',
                    'label' => 'Casuals and contractors',
                ],
                [
                    'id' => '999993',
                    'label' => 'Parents',
                ],
                [
                    'id' => '61528',
                    'label' => 'Future Parents',
                ],
                [
                    'id' => '999994',
                    'label' => 'Students',
                ],
            ],
        ];


        // Primary School
        $campuses[] = [
            'id' => 'primary',
            'label' => 'Primary School',
            'children' => [
                [
                    'id' => '5877',
                    'label' => 'Staff',
                ],
                [
                    'id' => '8138',
                    'label' => 'Casuals and contractors',
                ],
                [
                    'id' => '999995',
                    'label' => 'Parents',
                ],
                [
                    'id' => '61752',
                    'label' => 'Future Parents',
                ],
                [
                    'id' => '999996',
                    'label' => 'Students',
                ],
            ],
        ];

        // Primary School (Red Hill only)
        $campuses[] = [
            'id' => 'primary-redhill',
            'label' => 'Primary School (Red Hill only)',
            'children' => [
                [
                    'id' => '8142',
                    'label' => 'Staff',
                ],
                [
                    'id' => '8139',
                    'label' => 'Casuals and contractors',
                ],
                [
                    'id' => '10030',
                    'label' => 'Parents',
                ],
                [
                    'id' => '61753',
                    'label' => 'Future Parents',
                ],
                [
                    'id' => '8173',
                    'label' => 'Students',
                ],
            ],
        ];
        // Primary School (Campbell only)
        $campuses[] = [
            'id' => 'primary-campbell',
            'label' => 'Primary School (Campbell only)',
            'children' => [
                [
                    'id' => '8143',
                    'label' => 'Staff',
                ],
                [
                    'id' => '8140',
                    'label' => 'Casuals and contractors',
                ],
                [
                    'id' => '10031',
                    'label' => 'Parents',
                ],
                [
                    'id' => '63610',
                    'label' => 'Future Parents',
                ],
                [
                    'id' => '8174',
                    'label' => 'Students',
                ],
            ], 
        ];

        // CGS Care
        $campuses[] = [
            'id' => 'cgs-care',
            'label' => 'CGS Care',
            'children' => [
                [
                    'id' => '8141',
                    'label' => 'Staff',
                ],
            ],
        ];

       
        return $campuses;
    }


    public static function get_preview_users($audiences) {
        global $DB, $USER;

    }







    public static function get_sendas_options() {
        global $DB, $USER;

        $sql = "SELECT * FROM {ann2_impersonators} WHERE authorusername = :authorusername";
        $params = [
            'authorusername' => $USER->username,
        ];
        $impersonators = $DB->get_records_sql($sql, $params);
        $options = [];
        foreach ($impersonators as $impersonator) {
            $options[] = utils_lib::user_stub($impersonator->impersonateuser);
        }
        return $options;
    }

}