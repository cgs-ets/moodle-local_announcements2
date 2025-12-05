<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/announcement.class.php');
require_once(__DIR__.'/../lib/announcements.lib.php');
require_once(__DIR__.'/../lib/service.lib.php');

use \local_announcements2\lib\Announcement;
use \local_announcements2\lib\announcements_lib;
use \local_announcements2\lib\service_lib;

/**
 * Announcement API trait
 */
trait announcements_api {

    /**
     * Get send as options.
     *
     * @return array
     */
    static public function get_sendas_options() {
        return announcements_lib::get_sendas_options();
    }

    /**
     * Get audiences.
     *
     * @return array
     */
    static public function get_audiences() {
        return announcements_lib::get_audiences();
    }

}
