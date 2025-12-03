<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/activities.lib.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/activity.class.php');

use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\activity;
use \local_announcements2\lib\utils_lib;

/**
 * Activity lib
 */
class workflow_lib  {
    
    const APPROVAL_STATUS_UNAPPROVED = 0;
    const APPROVAL_STATUS_APPROVED = 1;
    const APPROVAL_STATUS_REJECTED = 2;

    private static function get_approvers_from_proc($approvaltype, $username = '9999999') {
        global $CFG, $USER;
        // Load approvers from SQL.
        $approvers = [];
        // Prepare the SQL query based on the database type
        if ($CFG->dbtype === 'mysqli' || $CFG->dbtype === 'mariadb') {
            // For MySQL, use the `CALL` syntax with the username parameter
            $sql = "CALL " . static::WORKFLOW[$approvaltype]['fromsqlproc'] . " (?)";
        } else {
            // For SQL Server, use the `EXEC` syntax with the username parameter
            $sql = "EXEC " . static::WORKFLOW[$approvaltype]['fromsqlproc'] . " @username = ?";
        }
        
        $config = get_config('local_announcements2');
        if (empty($config->dbhost ?? '') || empty($config->dbuser ?? '') || empty($config->dbname ?? '')) {
            return [];
        }
        try {
            $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            @$externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');
            $rows = $externalDB->get_records_sql($sql, array($username));
            //$rows = $externalDB->get_records_sql($sql, array('9999999')); // Cannot filter out the current user because the UI fails when the assigned HoD comes into the activity to approve.
            if (empty($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                $username = $row->staffid;
                $approvers[$username] = array(
                    'username' => $username,
                    'contacts' => null,
                );
            }
        } catch (\Exception $e) {
            return null;
        }

        return $approvers;
    }

    private static function get_approval_clone($name, $sequence, $activityid, $staffincharge = '9999999') {
        global $CFG, $USER;
        // Approval stub.
        $approval = new \stdClass();
        $approval->activityid = $activityid;
        $approval->username = ''; // The person that eventually approves it.
        $approval->timemodified = time();
        $approval->type = $name;
        $approval->sequence = $sequence;
        $approval->description = static::WORKFLOW[$approval->type]['name'];
        
        if (isset(static::WORKFLOW[$approval->type]['fromsqlproc'])) {
            $approval->approvers = static::get_approvers_from_proc($approval->type, $staffincharge);
        } else {
            // Get approves from config.
            $approval->approvers = array_filter(
                static::WORKFLOW[$approval->type]['approvers'], 
                function($item) { return !isset($item['silent']) || !$item['silent']; }
            );
        }

        return $approval;
    }

    private static function get_approval_stubs($activityid, $activitytype, $campus, $assessmentid, $isovernight, $staffincharge) {
        $approvals = array();

        //if ($activitytype == 'incursion' && $assessmentid) {
        //    return $approvals;
        //} else 
        if ($activitytype == 'commercial') {
            // commercial_ra - 1st approver.
            $approvals[] =  static::get_approval_clone('commercial_ra', 1, $activityid);

            // commercial_admin - 2nd approver.
            $approvals[] =  static::get_approval_clone('commercial_admin', 2, $activityid);

            // commercial_final - 3rd approver.
            $approvals[] =  static::get_approval_clone('commercial_final', 3, $activityid);
        } else  {
            switch ($campus) {
                case 'senior': {
                    $i = 0;
                    // Senior School.
                    $approvals[] = static::get_approval_clone('senior_hod', ++$i, $activityid, $staffincharge);

                    // Admin.
                    $approvals[] = static::get_approval_clone('senior_admin', ++$i, $activityid);

                    // Head of Senior or Director.
                    $approvals[] = static::get_approval_clone('senior_hoss', ++$i, $activityid);

                    // When ready to cutover, move this to bottom of the list and uncomment the if statement.
                    if ($isovernight) {
                        // RA.
                        $approvals[] = static::get_approval_clone('senior_ra', ++$i, $activityid);
                    }

                    break;
                }
                case 'primary': {
                    // Primary School - 1st approver.
                    $approvals[] = static::get_approval_clone('primary_ra', 1, $activityid);

                    // Primary School - 2nd approver.
                    $approvals[] = static::get_approval_clone('primary_admin', 2, $activityid);

                    // Primary School - 3rd approver.
                    $approvals[] = static::get_approval_clone('primary_hops', 3, $activityid);
                    break;
                }
                case 'whole': {    
                    // Whole School - 1st approver.
                    $approvals[] = static::get_approval_clone('whole_ra', 1, $activityid);

                    // Whole School - 2nd approver.
                    $approvals[] = static::get_approval_clone('whole_admin', 2, $activityid);

                    // Whole School - 3rd approver.
                    $approvals[] = static::get_approval_clone('whole_final', 3, $activityid);
                    break;
                }
            }
        }

        // Remove nulls, which could come from workflows where no approvers were listed of found in the sql.
        return array_values(array_filter($approvals, fn($item) => !is_null($item->approvers)));
    }

    public static function generate_approvals($originalactivity, $newactivity) {
        global $DB, $USER;

        // Check if changed fields cause an approval state to be invalidated.
        $fieldschanged = activities_lib::get_changed_fields($originalactivity, $newactivity);
        $fieldschangedkeys = array_keys($fieldschanged);
        foreach (static::WORKFLOW as $type => $conf) {
            if (array_intersect($fieldschangedkeys, $conf['invalidated_on_edit'])) {
                $sql = "UPDATE mdl_activities_approvals
                           SET invalidated = 1
                         WHERE activityid = ?
                           AND type = ?
                           AND status > 0";
                $DB->execute($sql, array(
                    $newactivity->get('id'),
                    $type,
                ));
            }
        }

        // Is activity linked to an assessment?
        $assessmentid = $DB->get_field('activities_assessments', 'id', array('activityid' => $newactivity->get('id')));

        // Determine overnight based on whether timestart and timeend are different days.
        $isovernight = false;
        if ($newactivity->get('timestart') > 0 && $newactivity->get('timeend') > 0) {
            $isovernight = date('Ymd', $newactivity->get('timestart')) != date('Ymd', $newactivity->get('timeend'));
        }
        $approvals = static::get_approval_stubs($newactivity->get('id'), $newactivity->get('activitytype'), $newactivity->get('campus'), $assessmentid ? $assessmentid : 0, $isovernight, $newactivity->get('staffincharge'));

        // Invalidate approvals that should not be there.
        $approvaltypes = array_column($approvals, 'type');
        $sql = "UPDATE mdl_activities_approvals
                   SET invalidated = 1
                 WHERE activityid = ?
                   AND invalidated = 0
                   AND type NOT IN ('" . implode("','", $approvaltypes) . "')";
        $params = array($newactivity->get('id'));
        $DB->execute($sql, $params);

        // Insert the approval if it doesn't already exist.
        $progressed = false;
        foreach ($approvals as $approval) {
            $exists = $DB->record_exists('activities_approvals', array(
                'activityid' => $newactivity->get('id'), 
                'type' => $approval->type, 
                'invalidated' => 0
            ));
            if (!$exists) {
                $DB->insert_record('activities_approvals', $approval);
                $progressed = true; // If inserting new approvals, it is because this is just going into review, or campus has changed.
            }
        }

        //Update activity status based on current state of approvals.
        return static::check_status($newactivity->get('id'), $fieldschanged, $progressed);

    }

    public static function get_approval($activityid, $approvalid) {
        global $DB;
        
        $sql = "SELECT *
                  FROM mdl_activities_approvals
                 WHERE activityid = ?
                   AND id = ? 
                   AND invalidated = 0
              ORDER BY sequence ASC";
        $params = array($activityid, $approvalid);

        $records = $DB->get_records_sql($sql, $params);
        $approvals = array();
        foreach ($records as $record) {
            $record->statushelper = static::approval_helper($record->status);
            $approvals[] = $record;
        }

        return $approvals;
    }

    public static function get_approvals($activityid) {
        global $DB;
        
        $sql = "SELECT *
                  FROM mdl_activities_approvals
                 WHERE activityid = ?
                   AND invalidated = 0
              ORDER BY sequence ASC";
        $params = array($activityid);

        $records = $DB->get_records_sql($sql, $params);
        $approvals = array();
        foreach ($records as $record) {
            $record->statushelper = static::approval_helper($record->status);
            $approvals[] = $record;
        }

        return $approvals;
    }

    public static function get_unactioned_approvals($activityid) {
        global $DB;
        
        $sql = "SELECT *
                  FROM mdl_activities_approvals
                 WHERE activityid = ?
                   AND invalidated = 0
                   AND skip = 0
                   AND status != 1
              ORDER BY sequence ASC";
        $params = array($activityid);

        $records = $DB->get_records_sql($sql, $params);
        $approvals = array();
        foreach ($records as $record) {
            $record->statushelper = static::approval_helper($record->status);
            $approvals[] = $record;
        }

        return array_values($approvals);
    }


    public static function is_approver_of_activity($activityid) {
        global $USER, $DB;

        $approvertypes = static::get_approver_types();
        if ($approvertypes) {
            // The user is potentially an approver. Check if approver for this activity.
            list($insql, $inparams) = $DB->get_in_or_equal($approvertypes);
            $sql = "SELECT * 
                      FROM mdl_activities_approvals
                     WHERE type $insql
                       AND activityid = ?";
            $inparams = array_merge($inparams, array($activityid));
            $approvals = $DB->get_records_sql($sql, $inparams);
            if ($approvals) {
                return true;
            }
        }
        
        return false;
    }

    public static function is_cal_reviewer() {
        global $USER;

        $config = get_config('local_announcements2');
        if (!empty($config->eventreviewers)) {
            $reviewers = array_map(function ($r) {return trim($r);}, explode(",", $config->eventreviewers));
            if (in_array($USER->username, $reviewers)) {
                return true;
            }
        }

        return false;
    }

    /*
    * Save approval
    */
    public static function approve_cal_entry($activityid, $approved) {
        global $DB;

        // Check if user is allowed to do this.
        if (!static::is_cal_reviewer()) {
            return null;
        }
        $status = $approved ? activities_lib::ACTIVITY_STATUS_APPROVED : activities_lib::ACTIVITY_STATUS_INREVIEW;

        $activity = new activity($activityid);
        $activity->set('status', $status);
        $activity->save();

        return $status;
    }
    
    /*
    * Make public
    */
    public static function make_public_now($activityid, $pushpublic) {
        global $DB;

        // Check if user is allowed to do this.
        if (!static::is_cal_reviewer()) {
            return null;
        }
        $pushpublic = $pushpublic ? 1 : 0;

        $activity = new activity($activityid);
        $activity->set('pushpublic', $pushpublic);
        $activity->save();

        return $pushpublic;
    }


    /*
    * Save approval
    */
    public static function save_approval($activityid, $approvalid, $checked) {
        global $DB, $USER;

        // Check if user is allowed to do this.
        $isapprover = static::is_approver_of_activity($activityid);
        if (!$isapprover) {
            return null;
        }

        //$userapprovertypes = static::get_approver_types($USER->username);

        // Update the approval status.
        //list($insql, $inparams) = $DB->get_in_or_equal($userapprovertypes);
        $sql = "UPDATE mdl_activities_approvals
                   SET status = ?, username = ?, timemodified = ?
                 WHERE id = ?
                   AND activityid = ?
                   AND invalidated = 0";
                   //AND type $insql";
        $params = array($checked, $USER->username, time(), $approvalid, $activityid);
        //$params = array_merge($params, $inparams);
        $DB->execute($sql, $params);

        // Check for approval finalisation and return new status.
        $newstatusinfo = static::check_status($activityid, null, true);

        return $newstatusinfo;
    }

    /*
    * Save skip
    */
    public static function save_skip($activityid, $approvalid, $skip) {
        global $DB, $USER;

        // Check if user is allowed to do this.
        $isapprover = static::is_approver_of_activity($activityid);
        if (!$isapprover) {
            return null;
        }

        // Update the approval status.
        $sql = "UPDATE mdl_activities_approvals
                   SET skip = ?, username = ?, timemodified = ?
                 WHERE id = ?
                   AND activityid = ?
                   AND invalidated = 0";
        $params = array($skip, $USER->username, time(), $approvalid, $activityid);
        $DB->execute($sql, $params);

        // Check for approval finalisation and return new status.
        $newstatusinfo = static::check_status($activityid, null, true);

        return $newstatusinfo;
    }

    /*
    * Nominate Approver
    */
    public static function nominate_approver($activityid, $approvalid, $nominated) {
        global $DB, $USER;

        // Check if user is allowed to do this.
        $isapprover = static::is_approver_of_activity($activityid);
        if (!$isapprover && !utils_lib::has_capability_edit_activity($activityid)) {
            return static::check_status($activityid, null, false, [], false);
        }

        $activity = new Activity($activityid);
        // Update the approval.
        $sql = "UPDATE mdl_activities_approvals
                   SET nominated = ?, timemodified = ?
                 WHERE id = ?
                   AND activityid = ?
                   AND invalidated = 0";
        $params = array($nominated, time(), $approvalid, $activityid);
        $DB->execute($sql, $params);

        // Send the notification.
        $approvals = static::get_approval($activityid, $approvalid);
        foreach ($approvals as $approval) {
            if (isset(static::WORKFLOW[$approval->type]['fromsqlproc']) && static::WORKFLOW[$approval->type]['fromsqlproc']) {
                static::send_next_approval_email($activityid, static::WORKFLOW[$approval->type]['name'], $nominated, null, [$USER->email]);
            } else {
                $approver = static::WORKFLOW[$approval->type]['approvers'][$nominated];
                if ($approver['contacts']) {
                    foreach ($approver['contacts'] as $email) {
                        static::send_next_approval_email($activityid, static::WORKFLOW[$approval->type]['name'], $nominated, $email, [$USER->email]);
                    }
                } else {
                    static::send_next_approval_email($activityid, static::WORKFLOW[$approval->type]['name'], $nominated, null, [$USER->email]);
                }
            }
            
        }

        // Return updated status and workflow.
        return static::check_status($activityid, null, false, [], false);

    }

    
    /*
    * Check status
    */
    public static function check_status($activityid, $fieldschanged = null, $progressed = false, $bccemails = [], $sendemails = true) {
        global $DB, $PAGE, $OUTPUT;

        $activity = new Activity($activityid);
        // Check if draft.
        // Check if the activity is actually an activity and not a calendar entry, as people often change it.
        if ( $activity->get('status') == activities_lib::ACTIVITY_STATUS_DRAFT ||
             !activities_lib::is_activity($activity->get('activitytype')) 
        ) {
            // Activity is a draft! It has no workflow yet.
            return (object) array(
                'status' => $activity->get('status'),
                'workflow' => [],
            );
        }

        // Check for remaining approvals and set activity status based on findings.
        $remainingapprovals = static::get_unactioned_approvals($activityid);

        // transition - if this activity was created BEFORE November 3, 2025 4:01:01 AM, ignore senior_hod approval.
        $createdbefore3Nov2025 = $activity->get('timecreated') < 1762102861;
        if ($createdbefore3Nov2025) {
            $hod = array_search('senior_hod', array_column($remainingapprovals, 'type'));
            if ($hod !== false) {
                unset($remainingapprovals[$hod]);
            }
        }

        $oldstatus = activities_lib::status_helper($activity->get('status'));
        $status = activities_lib::ACTIVITY_STATUS_INREVIEW;
        if (empty($remainingapprovals)) {
            // Approved!
            $status = activities_lib::ACTIVITY_STATUS_APPROVED;
        }
        $activity->set('status', $status);
        $activity->save();

        $newstatus = activities_lib::status_helper($status);

        if ($sendemails) {
            // Send emails depending on status change.
            // Approver needs to be notified when:
            // When workflow has progressed, or moving in-review from another status.
            if ($newstatus->inreview && ($oldstatus->status != $newstatus->status || $progressed)) {
                static::notify_next_approver($activityid, $bccemails);
            }

            // Creator needs to be notified whenever there is a status change.
            // Going from draft to in-review, approved back to in-review.
            if ($oldstatus->status != $newstatus->status && !$newstatus->isapproved) {
                static::send_activity_status_email($activityid, $oldstatus, $newstatus);
            }

            // Send workflow progressed email.
            if ($oldstatus->inreview && $newstatus->inreview && $progressed) {
                static::send_workflow_email($activityid);
            }

            // Send approved status email.
            if ($oldstatus->inreview && $newstatus->isapproved) {
                static::send_approved_emails($activityid);
            }

            // If changes after already approved, send email to relevant staff.
            if ($fieldschanged) {
                if ($oldstatus->isapproved && $newstatus->isapproved) {
                    static::send_datachanged_emails($activityid, $fieldschanged);
                }
            }
        }

        $workflow = static::get_workflow($activityid);

        if ($activity->get('status') == activities_lib::ACTIVITY_STATUS_INREVIEW) {
            // Determine current step name. Find the first unapproved step.
            $stepname = '';
            foreach ($workflow as $approval) {
                if ($approval->status == 0) {
                    $stepname = $approval->description;
                    break;
                }
            }
            // Now is a great time to save current step name, displayed on event modal.
            $activity->set('stepname', $stepname);
            $activity->save();
        }
        

        return (object) array(
            'status' => $status,
            'workflow' => $workflow,
        );

    }

    public static function get_prerequisites($activityid, $type) {
        global $DB;

        $prerequisites = static::WORKFLOW[$type]['prerequisites'];
        if ($prerequisites) {
            // Check for any yet to be approved.
            list($insql, $inparams) = $DB->get_in_or_equal($prerequisites);
            $sql = "SELECT *
                      FROM mdl_activities_approvals
                     WHERE activityid = ?
                       AND invalidated = 0
                       AND skip = 0
                       AND type $insql
                       AND status != 1";
            $params = array_merge(array($activityid), $inparams);
            $records = $DB->get_records_sql($sql, $params);
            return $records;
        }
        return null;

    }

    protected static function send_activity_status_email($activityid, $oldstatus, $newstatus) {
        global $USER, $OUTPUT;

        $activity = new Activity($activityid);
        $exported = $activity->export();

        $toUser = \core_user::get_user_by_username($exported->creator);
        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array(); //array("lms.archive@cgs.act.edu.au"); 

        $data = array(
            'activity' => $exported,
            'oldstatus' => $oldstatus,
            'newstatus' => $newstatus,
        );

        $subject = "Activity status update: " . $exported->activityname;
        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_status_html', $data);
        $result = service_lib::wrap_and_email_to_user($toUser, $fromUser, $subject, $messageHtml); 

    }

    protected static function send_workflow_email($activityid) {
        global $USER, $OUTPUT;

        $activity = new Activity($activityid);
        $activity = $activity->export();

        $toUser = \core_user::get_user_by_username($activity->creator);
        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array(); //$fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); 

        $subject = "Activity workflow update: " . $activity->activityname;
        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_workflow_html', ['activity' => $activity]);
        $result = service_lib::wrap_and_email_to_user($toUser, $fromUser, $subject, $messageHtml); 

    }

    public static function filter_approvals_with_prerequisites($approvals) {
        foreach ($approvals as $i => $approval) {
            // Exlude if waiting for a prerequisite.
            $prerequisites = static::get_prerequisites($approval->activityid, $approval->type);
            if ($prerequisites) {
                unset($approvals[$i]);
            }
        }
        return $approvals;
    }

    protected static function notify_next_approver($activityid, $bccemails = []) {
        // Get the next approval step.
        $approvals = static::get_unactioned_approvals($activityid);
        $approvals = static::filter_approvals_with_prerequisites($approvals); 
        foreach ($approvals as $nextapproval) {
            $isSelectable = isset(static::WORKFLOW[$nextapproval->type]['selectable']) && static::WORKFLOW[$nextapproval->type]['selectable'];
            if ($isSelectable) {
                if ($nextapproval->nominated) {
                    // If an approver has already been nominated, send them the email, otherwise that will need to happen first.
                    $approver = null;
                    if (isset(static::WORKFLOW[$nextapproval->type]['approvers'][$nextapproval->nominated])) {
                        $approver = static::WORKFLOW[$nextapproval->type]['approvers'][$nextapproval->nominated];
                    }
                    if ($approver && $approver['contacts']) {
                        foreach ($approver['contacts'] as $email) {
                            static::send_next_approval_email($activityid, static::WORKFLOW[$nextapproval->type]['name'], $nextapproval->nominated, $email, $bccemails);
                        }
                    } else {
                        static::send_next_approval_email($activityid, static::WORKFLOW[$nextapproval->type]['name'], $nextapproval->nominated, null, $bccemails);
                    }
                }
            } else {
                $approvers = static::WORKFLOW[$nextapproval->type]['approvers'];
                foreach($approvers as $approver) {
                    // Skip if approver does not want this notification.
                    if ((isset($approver['silent']) && $approver['silent']) || (isset($approver['notifications']) && !in_array('approvalrequired', $approver['notifications']))) {
                        continue;
                    }
                    if ($approver['contacts']) {
                        foreach ($approver['contacts'] as $email) {
                            static::send_next_approval_email($activityid, static::WORKFLOW[$nextapproval->type]['name'], $approver['username'], $email, $bccemails);
                        }
                    } else {
                        static::send_next_approval_email($activityid, static::WORKFLOW[$nextapproval->type]['name'], $approver['username'], null, $bccemails);
                    }
                }
            }
        }
    }


    protected static function send_next_approval_email($activityid, $step = '', $recipient = '', $email = null, $bccemails = []) {
        global $USER, $OUTPUT;

        $toUser = \core_user::get_user_by_username($recipient);
        if (empty($toUser)) {
            return;
        }
        if ($email) {
            // Override the email address.
            $toUser->email = $email;
        }
        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); //$fromUser->bccaddress = array();
        $fromUser->bccaddress = array_merge($fromUser->bccaddress, $bccemails);

        $activity = new Activity($activityid);
        $activity = $activity->export();

        $subject = "Activity approval required [" . $step . "]: " . $activity->activityname;
        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_approval_html', ['activity' => $activity]);


        // Locate the ra and additional files in the Moodle file storage
        $attachments = array();
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_announcements2', 'ra', $activity->id, "filename", false);
        foreach ($files as $file) {
            // Copy attachment file to a temporary directory and get the file path.
            $filename = clean_filename($file->get_filename());
            $attachments[$filename] = $file->copy_content_to_temp();
        }
        $files = $fs->get_area_files($context->id, 'local_announcements2', 'attachments', $activity->id, "filename", false);
        foreach ($files as $file) {
            $filename = clean_filename($file->get_filename());
            $attachments[$filename] = $file->copy_content_to_temp();
        }

        $result = service_lib::wrap_and_email_to_user($toUser, $fromUser, $subject, $messageHtml, $attachments);

        // Remove an attachment file if any.
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    unlink($attachment);
                }
            }
        }

    }




    protected static function send_approved_emails($activityid) {
        global $PAGE, $OUTPUT;

        $activity = new Activity($activityid);

        $recipients = array();

        // Send to all approvers.
        $approvals = static::get_approvals($activityid);
        foreach ($approvals as $nextapproval) {
            // Get the approvers for this approval step.
            $approvers = workflow_lib::WORKFLOW[$nextapproval->type]['approvers'];
            foreach($approvers as $approver) {
                // Skip if approver does not want this notification.
                if (isset($approver['notifications']) && !in_array('activityapproved', $approver['notifications'])) {
                    continue;
                }
                // Skip if this step is selectable and approver is not the nominated one.
                $isSelectable = isset(static::WORKFLOW[$nextapproval->type]['selectable']) && static::WORKFLOW[$nextapproval->type]['selectable'];
                if ($isSelectable && $nextapproval->nominated !=$approver['username'] ) {
                    continue;
                }
                $usercontext = \core_user::get_user_by_username($approver['username']);
                $exported = $activity->export($usercontext);
                if ($approver['contacts']) {
                    foreach ($approver['contacts'] as $email) {
                        // Export each time as user context is needed to determine creator etc.
                        static::send_approved_email($exported, $approver['username'], $email);
                        $recipients[] = $approver['username'];
                    }
                } else {
                    if ( ! in_array($approver['username'], $recipients)) {
                        static::send_approved_email($exported, $approver['username']);
                        $recipients[] = $approver['username'];
                    }
                }
            }
        }

        // Send to staff in charge, planning staff and accompanying staff.
        $allstaff = activities_lib::get_all_staff($activityid);
        foreach ($allstaff as $staffun) {
            if ( ! in_array($staffun, $recipients)) {
                $usercontext = \core_user::get_user_by_username($staffun);
                $exported = $activity->export($usercontext);
                static::send_approved_email($exported, $staffun);
                $recipients[] = $staffun;
            }
        }

       
        // Send to activity creator.
        if ( ! in_array($activity->get('creator'), $recipients)) {
            $usercontext = \core_user::get_user_by_username($activity->get('creator'));
            $exported = $activity->export($usercontext);
            static::send_approved_email($exported, $exported->creator);
            $recipients[] = $exported->creator;
        }


    }

    protected static function send_approved_email($activity, $recipient, $email = '') {
        global $USER, $OUTPUT;

        $toUser = \core_user::get_user_by_username($recipient);
        if ($email) {
            // Override the email address.
            $toUser->email = $email;
        }

        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array(); //$fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); 

        $data = array(
            'activity' => $activity,
        );

        $subject = "Activity approved: " . $activity->activityname;
        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_approved_html', $data);
        $result = service_lib::wrap_and_email_to_user($toUser, $fromUser, $subject, $messageHtml); 
    }


    
    protected static function send_datachanged_emails($activityid, $fieldschanged) {
        global $PAGE;

        $activity = new Activity($activityid);
        $exported = $activity->export();

        $recipients = array();

        // Send to all approvers.
        $approvals = static::get_approvals($activityid);
        foreach ($approvals as $nextapproval) {
            // Get the approvers for this approval step.
            $approvers = workflow_lib::WORKFLOW[$nextapproval->type]['approvers'];
            foreach($approvers as $approver) {
                // Skip if approver does not want this notification.
                if (isset($approver['notifications']) && !in_array('activitychanged', $approver['notifications'])) {
                    continue;
                }
                // Skip if this step is selectable and approver is not the nominated one.
                $isSelectable = isset(static::WORKFLOW[$nextapproval->type]['selectable']) && static::WORKFLOW[$nextapproval->type]['selectable'];
                if ($isSelectable && $nextapproval->nominated !=$approver['username'] ) {
                    continue;
                }
                $usercontext = \core_user::get_user_by_username($approver['username']);
                $exported = $activity->export($usercontext);
                $exported->fieldschanged = array_values($fieldschanged); // Inject fields changed for emails.
                $exported->fieldschangedstring = json_encode($fieldschanged); // Inject fields changed for emails.
                if ($approver['contacts']) {
                    foreach ($approver['contacts'] as $email) {
                        // Export each time as user context is needed to determine creator etc.
                        static::send_datachanged_email($exported, $approver['username'], $email);
                        $recipients[] = $approver['username'];
                    }
                } else {
                    if ( ! in_array($approver['username'], $recipients)) {
                        static::send_datachanged_email($exported, $approver['username']);
                        $recipients[] = $approver['username'];
                    }
                }
            }
        }

        // Send to staff in charge, planning staff and accompanying staff.
        $allstaff = activities_lib::get_all_staff($activityid);
        foreach ($allstaff as $staffun) {
            if ( ! in_array($staffun, $recipients)) {
                $usercontext = \core_user::get_user_by_username($staffun);
                $exported = $activity->export($usercontext);
                $exported->fieldschanged = array_values($fieldschanged); // Inject fields changed for emails.
                $exported->fieldschangedstring = json_encode($fieldschanged); // Inject fields changed for emails.
                static::send_datachanged_email($exported, $staffun);
                $recipients[] = $staffun;
            }
        }

        // Send to activity creator.
        if ( ! in_array($activity->get('creator'), $recipients)) {
            $usercontext = \core_user::get_user_by_username($activity->get('creator'));
            $exported = $activity->export($usercontext);
            $exported->fieldschanged = array_values($fieldschanged); // Inject fields changed for emails.
            $exported->fieldschangedstring = json_encode($fieldschanged); // Inject fields changed for emails.
            static::send_datachanged_email($exported, $exported->creator);
            $recipients[] = $exported->creator;
        }
    }

    protected static function send_datachanged_email($activity, $recipient, $email = '') {
        global $USER, $OUTPUT;

        $toUser = \core_user::get_user_by_username($recipient);
        if ($email) {
            // Override the email address.
            $toUser->email = $email;
        }

        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array(); //$fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); 

        $subject = "Activity information changed: " . $activity->activityname;
        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_datachanged_html', ['activity' => $activity]);
        $result = service_lib::wrap_and_email_to_user($toUser, $fromUser, $subject, $messageHtml); 
    }


    public static function approval_helper($status) {
        $approvalhelper = new \stdClass();
        $approvalhelper->isactioned = ($status != static::APPROVAL_STATUS_UNAPPROVED);
        $approvalhelper->isapproved = ($status == static::APPROVAL_STATUS_APPROVED);
        $approvalhelper->isrejected = ($status == static::APPROVAL_STATUS_REJECTED);
        return $approvalhelper;
    }

    public static function get_approver_types($username = null) {
        global $USER;

        if (empty($username)) {
            $username = $USER->username;
        }

        $types = array();
        foreach (static::WORKFLOW as $code => $type) {
            foreach ($type['approvers'] as $approver) {
                if ($approver['username'] == $username) {
                    $types[] = $code;
                }
            }
            if (isset($type['fromsqlproc']) && $type['fromsqlproc']) {
                // Run the SQL proc to get the approvers.
                $approvers = static::get_approvers_from_proc($code);
                if (is_array($approvers)) {
                    foreach ($approvers as $approver) {
                        if ($approver['username'] == $username) {
                            $types[] = $code;
                            break;
                        }
                    }
                }
            }
        }

        return $types;
    }

    public static function get_workflow($activityid) {
        $activity = new Activity($activityid);
        $exported = $activity->export();

        // Get approvals.
        $userapprovertypes = static::get_approver_types();
        $approvals = static::get_approvals($activityid);
        $i = 0;
        foreach ($approvals as $ix => $approval) {
            $approval->selectable = false;
            $approval->isapprover = static::is_approver_of_activity($activityid);

            // Check if last approval.
            if(++$i === count($approvals)) {
                $approval->last = true;
            }
            
            // Approvers and editors (planners etc) can see the approvers in the workflow.
            if ($approval->isapprover || $exported->usercanedit) {
                // Get step approvers.
                if (isset(static::WORKFLOW[$approval->type]['fromsqlproc'])) {
                    $approvers = static::get_approvers_from_proc($approval->type, $exported->staffincharge);
                } else {
                    $approvers = static::WORKFLOW[$approval->type]['approvers'];
                }

                // Remove silent approvers.
                $approval->approvers = array_filter(
                    $approvers, 
                    function($item) { return !isset($item['silent']) || !$item['silent']; }
                );

                // Pull in the approvers name.
                foreach ($approval->approvers as &$approver) {
                    $user = \core_user::get_user_by_username($approver['username']);
                    $approver['fullname'] = fullname($user);
                }
            }

            // Check if ready to approve.
            if ($approval->isapprover) {
                // Check if skippable.
                if (isset(static::WORKFLOW[$approval->type]['canskip'])) {
                    $approval->canskip = true;
                }
                // Can this user approve this step?
                $approval->canapprove = false;
                $prerequisites = static::get_prerequisites($activityid, $approval->type);
                if (in_array($approval->type, $userapprovertypes)) {
                    // No unactioned prerequisites found, user can approver this.
                    if (empty($prerequisites)) {
                        $approval->canapprove = true;
                        if ($approval->status == 0) {
                            $approval->iswaitingforyou = true;
                        }
                    }
                }

                // Can this user select someone in this step?
                if (isset(static::WORKFLOW[$approval->type]['selectable']) && static::WORKFLOW[$approval->type]['selectable']) {
                    if (empty($prerequisites)) {
                        $approval->selectable = true;
                        foreach ($approval->approvers as &$approver) {
                            $approver['isselected'] = ($approver['username'] == $approval->nominated);
                        }
                    }
                }
            }

            
            // Ok, we just checked if the approval is selectable for a approvers. 
            // Check if selectable for a planner.
            if( $exported->usercanedit &&
                isset(static::WORKFLOW[$approval->type]['selectable']) &&
                static::WORKFLOW[$approval->type]['selectable'] &&
                isset(static::WORKFLOW[$approval->type]['selectablebywho']) &&
                static::WORKFLOW[$approval->type]['selectablebywho'] == 'planner') {
                $approval->selectable = true;
                $approval->selectablebywho = 'planner';
            }

            // If the first step is a selectable... then the planner must select. This is the case whem a Sen HoD or HoSH submit an activity 
            // because the first step is skipped for them, and the next step (admin approval) is a selectable.
            if ($ix == 0 && isset(static::WORKFLOW[$approval->type]['selectable']) && static::WORKFLOW[$approval->type]['selectable']) {
                $approval->selectable = true;
                $approval->selectablebywho = 'planner';
            }
        }

        return $approvals;
    }


    public static function get_draft_workflow($activitytype, $campus, $assessmentid, $isovernight, $staffincharge) {
        $approvals = static::get_approval_stubs(0, $activitytype, $campus, $assessmentid, $isovernight, $staffincharge);

        // Pull in approver fullnames.
        foreach ($approvals as $approval) {
            foreach ($approval->approvers as &$approver) {
                $user = \core_user::get_user_by_username($approver['username']);
                $approver['fullname'] = fullname($user);
            }
        }

        return $approvals;
    }


    public static function get_calendar_status($activityid) {
        global $DB;
        
        $sql = "SELECT *
                  FROM mdl_activities_cal_sync
                 WHERE activityid = ?
              ORDER BY timesynced ASC";
        $params = array($activityid);

        $records = $DB->get_records_sql($sql, $params);

        $syncs = array();
        foreach ($records as $record) {
            $syncs[] = $record;
        }

        return $syncs;


    }













    









}