<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/assessments.lib.php');

use \local_announcements2\lib\assessments_lib;

/**
 * Conflicts API trait
 */
trait assessments_api {

    /**
     * Get courses
     *
     * @return array
     */
    static public function get_courses() {
        return assessments_lib::get_courses();
    }

    /**
     * Get course categories
     *
     * @return array
     */
    static public function get_course_cats() {
        return assessments_lib::get_course_cats();
    }

    /**
     * Get modules
     *
     * @return array
     */
    static public function get_modules() {
        $courseid = required_param('courseid', PARAM_INT);
        return assessments_lib::get_modules($courseid);
    }

     /**
     * Get save an assessment
     *
     * @return array
     */
    static public function post_assessment($args) {
        return assessments_lib::save_from_data( (object) $args);
    }

    /**
     * Get assessment
     *
     * @return array
     */
    static public function get_assessment() {
        $id = required_param('id', PARAM_INT);
        return assessments_lib::get($id);
    }

    static public function get_assessments() {
        $type = required_param('type', PARAM_RAW);
        $month = optional_param('month', '', PARAM_ALPHANUMEXT);
        $year = optional_param('year', '', PARAM_ALPHANUMEXT);
        $term = optional_param('term', '', PARAM_ALPHANUMEXT);
        return assessments_lib::get_cal([
            'type' => $type,
            'month' => $month,
            'year' => $year,
            'term' => $term,
        ]);
    }

    static public function delete_assessment($args) {
        $data = (object) $args;
        return assessments_lib::delete($data->id);
    }

    
    /**
     * Get a activity's student list.
     *
     * @return array
     */
    static public function get_assessment_students() {
        $id = required_param('id', PARAM_INT);
        return assessments_lib::get_assessment_students($id);
    }


}
