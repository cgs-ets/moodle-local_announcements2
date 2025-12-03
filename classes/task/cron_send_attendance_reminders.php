<?php

/**
 * A scheduled task for notifications.
 *
 * @package   local_announcements2
 * @copyright 2024 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_announcements2\task;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/workflow.lib.php');
require_once(__DIR__.'/../lib/service.lib.php');
require_once(__DIR__.'/../lib/activities.lib.php');
require_once(__DIR__.'/../lib/activity.class.php');
use \local_announcements2\lib\workflow_lib;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\activity;

class cron_send_attendance_reminders extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_send_attendance_reminders', 'local_announcements2');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB, $PAGE;

        $this->log_start("Fetching activities.");
        $activities = activities_lib::get_for_attendance_reminders();

        foreach ($activities as $activity) {
            // Export the activity.
            $data = $activity->export();

            // Mark as processed.
            $DB->execute("UPDATE {activities} SET remindersprocessed = 1 WHERE id = $data->id");

            // Add staff in charge to list of recipients.
            $recipients = array();
            $recipients[$data->staffincharge] = \core_user::get_user_by_username($data->staffincharge);

            // Send the reminders.
            foreach ($recipients as $recipient) {
                $this->log("Sending reminder for activity " . $data->id . " to " . $recipient->username);
                $this->send_reminder($data, $recipient);
            }

            try {
                foreach ($recipients as $recipient) {
                    $this->log("Sending reminder for activity " . $data->id . " to " . $recipient->username);
                    $this->send_reminder($data, $recipient);
                }
            } catch (Exception $ex) {
                // Error.
            }
                
            
            // Mark as processed.
            $DB->execute("UPDATE {activities} SET remindersprocessed = 1 WHERE id = $data->id");
            $this->log("Finished sending reminders for activity " . $data->id);
        }

        $this->log_finish("Finished sending reminders.");
    }

    protected function send_reminder($activity, $recipient) {
        global $OUTPUT;

        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_attendance_reminder_html', $activity);
        $subject = "Confirm student attendance for: " . $activity->activityname;
        $toUser = $this->minimise_recipient_record($recipient);
        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); 
        $result = service_lib::wrap_and_real_email_to_user($toUser, $fromUser, $subject, $messageHtml);
        return true;
    }

    /**
     * Removes properties from user record that are not necessary for sending post notifications.
     *
     */
    protected function minimise_recipient_record($user) {
        // Make sure we do not store info there we do not actually
        // need in mail generation code or messaging.
        unset($user->institution);
        unset($user->department);
        unset($user->address);
        unset($user->city);
        unset($user->url);
        unset($user->currentlogin);
        unset($user->description);
        unset($user->descriptionformat);
        unset($user->icq);
        unset($user->skype);
        unset($user->yahoo);
        unset($user->aim);
        unset($user->msn);
        unset($user->phone1);
        unset($user->phone2);
        unset($user->country);
        unset($user->firstaccess);
        unset($user->lastaccess);
        unset($user->lastlogin);
        unset($user->lastip);

        return $user;
    }

}