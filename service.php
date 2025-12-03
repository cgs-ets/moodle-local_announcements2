<?php
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/classes/lib/service.lib.php');

use \local_announcements2\lib\service_lib;

// Check session.
require_login();
require_sesskey();
if (isguestuser()) { exit; }

$context = context_system::instance();
$PAGE->set_context($context);

$cache = optional_param('cache', 0, PARAM_INT);

$url = new \moodle_url($FULLME);
$is_GET = $_SERVER['REQUEST_METHOD'] === 'GET';
$is_POST = $_SERVER['REQUEST_METHOD'] === 'POST';

$methodname = null;
$format = 'json';
$args = [];

if (empty($arguments) && $is_GET) {
    $methodname = required_param('methodname', PARAM_ALPHANUMEXT);
    $format = optional_param('format', 'json', PARAM_ALPHANUMEXT);
    $url->remove_params('methodname', 'sesskey', 'format');
    $args = $url->params();
}

if (empty($arguments) && $is_POST) {
    $arguments = file_get_contents('php://input');
    $request = json_decode($arguments, true);
    if ($request === null) {
        $lasterror = json_last_error_msg();
        throw new coding_exception('Invalid json in request: ' . $lasterror);
    }
    $methodname = clean_param($request['methodname'], PARAM_ALPHANUMEXT);
    $format = isset($request['format']) ? clean_param($request['format'], PARAM_ALPHANUMEXT) : 'json';
    $args = $request['args'];
}

if (empty($methodname)) {
    throw new coding_exception('Invalid methodname in request: ' . $methodname);
}

$haserror = false;
$response = array();
$response = service_lib::call_service_function($methodname, $args, $format);

if ($response['error']) {
    $haserror = true;
}

if (!$haserror && $cache) {
    // 90 days only - based on Moodle point release cadence being every 3 months.
    $lifetime = 60 * 60 * 24 * 90;
    header('Expires: '. gmdate('D, d M Y H:i:s', time() + $lifetime) .' GMT');
    header('Pragma: ');
    header('Cache-Control: public, max-age=' . $lifetime . ', immutable');
    header('Accept-Ranges: none');
}

echo json_encode($response);