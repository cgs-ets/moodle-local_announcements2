<?php

namespace local_announcements2;

defined('MOODLE_INTERNAL') || die();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__.'/activities.api.php');
require_once(__DIR__.'/conflicts.api.php');
require_once(__DIR__.'/utils.api.php');
require_once(__DIR__.'/workflow.api.php');
require_once(__DIR__.'/calendar.api.php');
require_once(__DIR__.'/assessments.api.php');
require_once(__DIR__.'/recurrence.api.php');
require_once(__DIR__.'/risks.api.php');
require_once(__DIR__.'/risk_versions.api.php');

class API {
    use \local_announcements2\api\activities_api;
    use \local_announcements2\api\conflicts_api;
    use \local_announcements2\api\utils_api;
    use \local_announcements2\api\workflow_api;
    use \local_announcements2\api\calendar_api;
    use \local_announcements2\api\assessments_api;
    use \local_announcements2\api\recurrence_api;
    use \local_announcements2\api\risks_api;
    use \local_announcements2\api\risk_versions_api;
}