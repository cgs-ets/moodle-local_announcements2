<?php

define('NO_DEBUG_DISPLAY', true);
define('AJAX_SCRIPT', true);

// Include required files and classes.
require(__DIR__.'/../../config.php');
require(__DIR__.'/classes/lib/service.lib.php');

use \local_announcements2\lib\service_lib;

// Check session.
require_login();
require_sesskey();
if (isguestuser()) { exit; }

$upload = optional_param('upload', 0, PARAM_INT);
$remove = optional_param('remove', 0, PARAM_INT);
$fileid = optional_param('fileid', '', PARAM_RAW);
$view = optional_param('view', 0, PARAM_INT);


require_once($CFG->libdir.'/filelib.php');

$dataroot = str_replace('\\\\', '/', $CFG->dataroot);
$dataroot = str_replace('\\', '/', $dataroot);
$tempdir = $dataroot . '/temp/local_announcements2/';

if ($view) {
    // Serve temporary file for viewing (e.g., images in editor)
    $filepath = $tempdir . $fileid;
    if (file_exists($filepath) && is_file($filepath)) {
        $mimetype = mime_content_type($filepath);
        header('Content-Type: ' . $mimetype);
        header('Content-Length: ' . filesize($filepath));
        // Add cache headers for better performance
        header('Cache-Control: private, max-age=3600');
        readfile($filepath);
        exit;
    } else {
        http_response_code(404);
        exit;
    }
} else if ($upload) {
    try {
        // Handle the file upload.
        $file = $_FILES['file'];

        $path = $file['tmp_name']; // temporary upload path of the file.
        $name = date('Y-m-d-H-i-s', time()) . '|' . $USER->id . '|' . $file['name']; // desired name of the file.
        $name = str_replace(' ', '-', $name); // Replaces all spaces with hyphens.
        $name = preg_replace('/[^A-Za-z0-9\-\.\|]/', '', $name); // Removes special chars, leaving only letters numbers, dash, dot and pipe.
        $name = str_replace('|', '__', $name); // Replaces all double star with double underscore.

        // Check for the temp dir before moving forward.
        $temprootdir = $dataroot . '/temp/';
        if (!is_dir($temprootdir)) {
            if (!mkdir($temprootdir)) {
                service_lib::returnJsonHttpResponse(false, array(
                    'error' => 'Failed to create dir: ' . $temprootdir,
                ));
            }
        }
        if (!is_dir($tempdir)) {
            if (!mkdir($tempdir)) {
                service_lib::returnJsonHttpResponse(false, array(
                    'error' => 'Failed to create dir: ' . $tempdir,
                ));
            }
        }

        // Move to temp dir.
        $result = move_uploaded_file($path, $tempdir . basename($name));

        // return new file name.
        if ($result) {
            service_lib::returnJsonHttpResponse(true, array(
                'name' => $name,
                'file' => $file,
            ));
        }
    } catch (Exception $e) {
        service_lib::returnJsonHttpResponse(false, array(
            'error' => $e->getMessage(),
        ));
    }

} else if ($remove) {
    $result = unlink($tempdir . $fileid);
    service_lib::returnJsonHttpResponse(true, array(
        'result' => $result,
    ));
}

exit;