<?php

namespace local_announcements2;

defined('MOODLE_INTERNAL') || die();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__.'/announcements.api.php');

class API {
    use \local_announcements2\api\announcements_api;
}