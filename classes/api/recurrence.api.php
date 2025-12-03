<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/recurrence.lib.php');

use \local_announcements2\lib\recurrence_lib;

/**
 * Activity API trait
 */
trait recurrence_api {

    /**
     * Get workflow info for activity.
     *
     * @return array results.
     */
    static public function expand_dates() {
        $recurrence = required_param('recurrence', PARAM_RAW);
        $recurrence = json_decode($recurrence);
        $timestart = required_param('timestart', PARAM_INT);
        $timeend = required_param('timeend', PARAM_INT);
        return recurrence_lib::expand_dates($recurrence, $timestart, $timeend);
    } 

    /** 
     * Get series of dates for activity.
     *
     * @return array results.
     */
    static public function get_series() {
        $activityid = required_param('activityid', PARAM_INT);
        return recurrence_lib::get_series($activityid);
    }


    /**
     * Delete or detach occurrence.
     *
     * @return array results.
     */
    static public function delete_or_detach_occurrence() {
        $type = required_param('type', PARAM_RAW);
        $activityid = required_param('activityid', PARAM_INT);
        $occurrenceid = required_param('occurrenceid', PARAM_INT);
        return recurrence_lib::delete_or_detach_occurrence($type, $activityid, $occurrenceid);
    }
}
