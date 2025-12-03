<?php

/**
 * A scheduled task to queue up permission/message emails.
 *
 * @package   local_announcements2
 * @copyright 2024 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_announcements2\task;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/utils.lib.php');
require_once(__DIR__.'/../lib/service.lib.php');
require_once(__DIR__.'/../lib/activities.lib.php');
require_once(__DIR__.'/../lib/activity.class.php');
use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\activity;

class cron_emails_user extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_emails_user', 'local_announcements2');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB, $OUTPUT;



        // Get unprocessed permission send requests (process max 20 at a time).
        $timenow = time();
        $readabletime = date('Y-m-d H:i:s', $timenow);
        $this->log_start("Fetching unprocessed activity emails (process max 20 at a time) now ({$readabletime}).");
        $sql = "SELECT *
                  FROM {" . activities_lib::TABLE_ACTIVITY_EMAILS . "}
                 WHERE status = 0 
              ORDER BY timecreated ASC";
        $emails = $DB->get_records_sql($sql, null, 0, 20);



        // Set the status to 1 (processing).
        $emailactionids = array_column($emails, 'id');
        if (empty($emailactionids)) {
            $this->log_finish("No records found. Exiting.");
            return;
        }
        $this->log(sprintf("Found the following activity_email records %s. Setting to processing.",
            json_encode($emailactionids),
        ), 2);
        list($in, $params) = $DB->get_in_or_equal($emailactionids);
        // $DB->set_field_select(activities_lib::TABLE_ACTIVITY_EMAILS, 'status', 1, "id {$in}", $params);

        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); 

        $this->log("Queueing relevant permissions for sending.", 2);
        foreach ($emails as $email) {

            // Get the scope.
            $scope = json_decode($email->studentsjson);
            if (empty($scope)) {
                $this->log("That's odd, no student scope for this email, skipping.", 2);
                continue;
            }

            // Get the activity.
            $activity = new activity($email->activityid);
            if (empty($activity)) {
                $this->log("That's odd, the activity wasn't found, skipping.", 2);
                continue;
            }
            $activity = $activity->export();

            // If the email is a "permissions" email then it sends in a different way, because it needs to send per student.
            $includes = json_decode($email->includes);
            if (in_array('permissions', $includes) && $activity->permissions) {

                foreach ($scope as $studentun) {
                    // Get parents.
                    $student = \core_user::get_user_by_username($studentun);
                    if (empty($student)) {
                        continue;
                    }
                    $parents = utils_lib::get_user_mentors($student->id);
                    foreach ($parents as $parentun) {
                        $parent = \core_user::get_user_by_username($parentun);
                        if (empty($parent)) {
                            continue;
                        }
                    
                        $data = new \stdClass();
                        $data->activity = $activity;
                        $data->recipientname = "$parent->firstname $parent->lastname";
                        $data->studentname = "$student->firstname $student->lastname";
                        $data->extratext = $email->extratext;
                        $data->includepermissions = true;
                        $data->includedetails = in_array('details', $includes);
                        $body = $OUTPUT->render_from_template('local_announcements2/email_message', $data);
                        $subject = "Permissions required for: $activity->activityname";
                        $result = service_lib::wrap_and_email_to_user($parent, $fromUser, $subject, $body);
                    }   
                }

            } else {

                // Determine the recipients.
                $students = [];
                $parents = [];
                $staff = [];
                $audiences = json_decode($email->audiences);

                if (in_array('students', $audiences)) {
                    // Add students.
                    $students = $scope;
                }

                if (in_array('staff', $audiences)) {
                    // Add staff.
                    $staff = activities_lib::get_all_staff($email->activityid);
                }

                if (in_array('parents', $audiences)) {
                    // Add parents.
                    $userids = utils_lib::get_userids($scope);
                    $parents = utils_lib::get_users_mentors($userids);
                    
                }

                $allusers = array_unique(array_merge($staff, $students, $parents));
                
                foreach ($allusers as $recipientun) {
                    $recipient = \core_user::get_user_by_username($recipientun);
                    if (empty($recipient)) {
                        continue;
                    }

                    $data = new \stdClass();
                    $data->activity = $activity;
                    $data->recipientname = "$recipient->firstname $recipient->lastname";
                    $data->extratext = $email->extratext;
                    $data->includepermissions = false;
                    $data->includedetails = in_array('details', $includes);
                    $body = $OUTPUT->render_from_template('local_announcements2/email_message', $data);
                    $subject = $activity->activityname;
                    $result = service_lib::wrap_and_email_to_user($recipient, $fromUser, $subject, $body);
                }
                
            }
            $email->status = 2;
            $DB->update_record(activities_lib::TABLE_ACTIVITY_EMAILS, $email);
            $this->log("Activity email $email->id processing complete.", 2);
        }

        $this->log_finish("Finished queuing permissions.");
    }

}