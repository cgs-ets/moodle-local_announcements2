<?php
// <url>/local/announcements2/avatar.php?username=admin
require(__DIR__.'/../../config.php');
require_once(__DIR__.'/classes/lib/service.lib.php');

use \local_announcements2\lib\service_lib;

require_login();
if (isguestuser()) { exit; }

$username = required_param('username', PARAM_ALPHANUMEXT);
$size = optional_param('size', 'f2', PARAM_ALPHANUMEXT);
$tokenise = optional_param('tokenise', 0, PARAM_INT);

$url = service_lib::get_avatar_url($username, $size, $tokenise);
if (empty($url)) {
    $url = new \moodle_url('\local\announcements2\images\user.png');
}
header('Location: ' . $url);
exit;