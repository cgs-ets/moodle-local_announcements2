<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/conflicts.lib.php');

use \local_announcements2\lib\conflicts_lib;

/**
 * Conflicts API trait
 */
trait conflicts_api {

    /**
     * Check for conflicts.
     *
     * @return array
     */
    static public function check_conflicts() {
        $timestart = required_param('timestart', PARAM_RAW);
        $timeend = required_param('timeend', PARAM_RAW);
        $activityid = optional_param('activityid', 0, PARAM_INT);
        $type = optional_param('type', 'activity', PARAM_RAW);
        return conflicts_lib::check_conflicts($activityid, $timestart, $timeend, true, $type);
    }

    /**
     * Check for conflicts.
     *
     * @return array
     */
    static public function check_conflicts_html() {
        $timestart = required_param('timestart', PARAM_RAW);
        $timeend = required_param('timeend', PARAM_RAW);
        $activityid = optional_param('activityid', 0, PARAM_INT);
        $type = optional_param('type', 'activity', PARAM_RAW);
        $conflicts = conflicts_lib::check_conflicts($activityid, $timestart, $timeend, true, $type);
        $html = conflicts_lib::generate_conflicts_html($conflicts);
        return (object) ['html' => $html, 'conflicts' => $conflicts];
    }

    


}
