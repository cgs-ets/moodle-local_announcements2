<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/activities.lib.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/activity.class.php');
require_once($CFG->libdir.'/filelib.php');

use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\Activity;

class generator_lib {

    const BASEURL = '/local/activities/generator.php';

    public static function make($activityid, $document) {
        global $USER, $DB, $CFG, $PAGE;

        $output = $PAGE->get_renderer('core');

        $exportdir = str_replace('\\\\', '\\', $CFG->dataroot) . '\local_activities_docs\\';

        // Load the activity.
        $activity = new Activity($activityid);
        $activity = $activity->export();

        if ($document == 'chargesheet') {
            $exportdir = str_replace('\\\\', '\\', $CFG->dataroot) . '\local_activities_docs\\';

            // Check for the export dir before moving forward.
            if (!is_dir($exportdir)) {
                if (!mkdir($exportdir)) {
                    return array('code' => 'failed', 'data' => 'Failed to create export dir: ' . $exportdir);
                }
            }

            // Get the students.
            $attending = activities_lib::get_all_attending($activityid);
            //var_export($attending); exit;
            $students = array();
            foreach ($attending as $username) {
                $user = \core_user::get_user_by_username($username);
                // Add the student to the list.
                $row = array(
                    'StudentID' => $username,
                    'StudentName' => fullname($user),
                    'DebtorID' => '',
                    'FeeCode' => '',
                    'TransactionDate' => date('d/m/Y', $activity->timeend),
                    'TransactionAmount' => $activity->cost,
                    'TransactionDescription' => $activity->activityname,
                );
                $students[] = $row;
            }

            // Create the csv file.
            $filename = 'activity_chargesheet_' . date('Y-m-d-His', time()) . '_' . $activityid . '.csv';
            $path = $exportdir . $filename;

            $fp = fopen($path, 'w');

            // Populate the header fields.
            $header = array(
                'StudentID',
                'StudentName',
                'DebtorID',
                'FeeCode',
                'TransactionDate',
                'TransactionAmount',
                'TransactionDescription',
            );
            fputcsv($fp, $header);

            // Populate the students.
            foreach ($students as $fields) {
                fputcsv($fp, $fields);
            }

            fclose($fp);

            // Send the file with force download, and don't die so that we can perform cleanup.
            send_file($path, $filename, 10, 0, false, true, 'text/csv', true); //Lifetime is 10 to prevent caching.

            // Delete the zip from the exports folder.
            unlink( $path );
        }

        // Nothing left to do.
        die;

    }

}