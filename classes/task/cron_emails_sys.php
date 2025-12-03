<?php
/**
 *
 * @package   local_announcements2
 * @copyright 2024 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_announcements2\task;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/service.lib.php');
use \local_announcements2\lib\service_lib;

class cron_emails_sys extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_emails_sys', 'local_announcements2');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB;

        $this->log_start("Fetching emails from queue");
        $emails = $DB->get_records('activities_sys_emails', ["timesent" => 0]);
        foreach ($emails as $email) {
            $data = json_decode($email->data);
            list($user, $from, $subject, $messagetext, $messagehtml, $attachments) = $data;
            $this->log("Sending email '$subject' to '$user->email'");
            $now = time();
            $DB->execute("UPDATE {activities_sys_emails} SET timesent = $now WHERE id = $email->id");
            $result = service_lib::real_email_to_user($user, $from, $subject, $messagetext, $messagehtml, $attachments);
        }
        $this->log_finish("Finished sending emails.");
    }

}