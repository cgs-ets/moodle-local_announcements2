<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/announcement.class.php');
require_once(__DIR__.'/service.lib.php');
require_once(__DIR__.'/utils.lib.php');

use \local_announcements2\lib\Announcement;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\utils_lib;
use \moodle_exception;

/**
 * Activity lib
 */
class announcements_lib {

    /** Table to store this persistent model instances. */
    const TABLE = 'ann2_posts';

 


    /**
     * Get and decorate the data.
     * Only staff should be allowed to do this...
     * 
     * @param int $id activity id
     * @return array
     */
    public static function get_announcement($id) {        
        if (!utils_lib::is_user_staff()) {
            throw new \Exception("Permission denied.");
            exit;
        }
        $activity = new Activity($id);
        if ($id != $activity->get('id')) {
            throw new \Exception("Activity not found.");
            exit;
        }
        $exported = $activity->export();
        if((!$exported->usercanedit) && $exported->status < static::ACTIVITY_STATUS_INREVIEW) {
            throw new \Exception("Permission denied.");
            exit;
        }
        return $exported;
    }


    public static function get_sendas_options() {
        global $DB, $USER;

        $sql = "SELECT * FROM {ann2_impersonators} WHERE authorusername = :authorusername";
        $params = [
            'authorusername' => $USER->username,
        ];
        $impersonators = $DB->get_records_sql($sql, $params);
        $options = [];
        foreach ($impersonators as $impersonator) {
            $options[] = utils_lib::user_stub($impersonator->impersonateuser);
        }
        return $options;
    }

}