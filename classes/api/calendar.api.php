<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/calendar.lib.php');

use \local_announcements2\lib\calendar_lib;

/**
 * Calendar API trait
 */
trait calendar_api {

    static public function get_cal() {
        $type = required_param('type', PARAM_RAW);
        $month = optional_param('month', '', PARAM_ALPHANUMEXT);
        $year = optional_param('year', '', PARAM_ALPHANUMEXT);
        $term = optional_param('term', '', PARAM_ALPHANUMEXT);
        $show_past = optional_param('show_past', false, PARAM_BOOL);
        return calendar_lib::get([
            'type' => $type,
            'month' => $month,
            'year' => $year,
            'term' => $term,
            'show_past' => $show_past,
        ]);
    }

    static public function get_public_calendar() {
        $type = required_param('type', PARAM_RAW);
        if ($type != 'full' && $type != 'list') { // Only full and list are supported for public calendar
            return [];
        }
        $month = optional_param('month', '', PARAM_ALPHANUMEXT);
        $year = optional_param('year', '', PARAM_ALPHANUMEXT);
        $term = optional_param('term', '', PARAM_ALPHANUMEXT);
        $show_past = optional_param('show_past', false, PARAM_BOOL);
        $events = calendar_lib::get([
            'type' => $type,
            'month' => $month,
            'year' => $year,
            'term' => $term,
            'show_past' => $show_past,
            'access' => 'public',
        ]);
        return $events;
    }

}
