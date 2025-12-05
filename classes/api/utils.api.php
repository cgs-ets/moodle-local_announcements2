<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/utils.lib.php');

use \local_announcements2\lib\utils_lib;

/**
 * Utils API trait
 */
trait utils_api {

    /**
     * Search users.
     *
     * @return array
     */
    static public function search_users() {
        $query = required_param('query', PARAM_RAW);
        return utils_lib::search_users($query);
    }

}
