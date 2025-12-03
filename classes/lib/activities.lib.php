<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/activities/config.php');
require_once(__DIR__.'/activity.class.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/service.lib.php');
require_once(__DIR__.'/workflow.lib.php');
require_once(__DIR__.'/recurrence.lib.php');
require_once($CFG->dirroot . '/local/activities/vendor/autoload.php');

use \local_announcements2\lib\Activity;
use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\workflow_lib;
use \local_announcements2\lib\recurrence_lib;
use \moodle_exception;
use FineDiff\Diff;

/**
 * Activity lib
 */
class activities_lib {

    const ACTIVITY_STATUS_AUTOSAVE = 0;
    const ACTIVITY_STATUS_DRAFT = 1;
    const ACTIVITY_STATUS_INREVIEW = 2;
    const ACTIVITY_STATUS_APPROVED = 3;
    const ACTIVITY_STATUS_CANCELLED = 4;

    /** Table to store this persistent model instances. */
    const TABLE = 'activities';
    const TABLE_ACTIVITY_STUDENTS  = 'activities_students';
    const TABLE_ACTIVITY_APPROVALS  = 'activities_approvals';
    const TABLE_ACTIVITY_COMMENTS = 'activities_comments';
    const TABLE_ACTIVITY_EMAILS = 'activities_emails';
    const TABLE_ACTIVITY_PERMISSIONS = 'activities_permissions';
    const TABLE_ACTIVITY_STAFF = 'activities_staff';
    const TABLE_ACTIVITY_ACKNOWLEDGEMENTS = 'activities_acknowledgements';

    public static function is_activity($activitytype) {
        return (
            $activitytype == 'excursion' || 
            $activitytype == 'incursion' ||
            $activitytype == 'commercial'
        );
    }


    /**
     * Get and decorate the data.
     * Only staff should be allowed to do this...
     * 
     * @param int $id activity id
     * @return array
     */
    public static function get_activity($id) {        
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


    /**
     * Get and decorate the data.
     *
     * @param int $id activity id
     * @return array
     */
    public static function get_activity_with_permission($id) {
        global $USER;

        $activity = new Activity($id);
        if ($id != $activity->get('id')) {
            throw new \Exception("Activity not found.");
            exit;
        }
        $exported = $activity->export();
        $permissions = static::get_parent_permissions($id, $USER->username);

        foreach ($permissions as &$permission) {
            $permission->student = utils_lib::user_stub($permission->studentusername);
        }

        // Sort the permissions array by studentusername
        usort($permissions, function($a, $b) {
            return strcmp($a->studentusername, $b->studentusername);
        });

        return [
            'id' => $exported->id,
            'activityname' => $exported->activityname,
            'timestart' => $exported->timestart,
            'timeend' => $exported->timeend,
            'location' => $exported->location,
            'transport' => $exported->transport,
            'cost' => $exported->cost,
            'staffinchargejson' => $exported->staffinchargejson,
            'description' => $exported->description,
            'permissionsdueby' => $exported->permissionsdueby,
            'stupermissions' => array_values($permissions),
            'permissionshelper' => static::permissions_helper($activity),
            'status' => $exported->status,
            'stepname' => $exported->stepname,
            'recurring' => $exported->recurring,
            'occurrences' => recurrence_lib::get_series($id),
        ];
    }

    /**
     * Get an activity for public calendar preview page
     *
     * @param int $id activity id
     * @return array
     */
    public static function get_public_activity($id) {
        global $USER;
        $exported = null;
        $activity = new Activity($id);

        if ($activity->get('displaypublic') && 
            ($activity->get('status') == static::ACTIVITY_STATUS_APPROVED || 
             ($activity->get('status') == static::ACTIVITY_STATUS_INREVIEW && $activity->get('pushpublic'))
            )
        ) {
            $exported = $activity->export_minimal();
        }

        return $exported;
    }
    

    /**
     * Get and decorate the data.
     *
     * @param array $rec activity record
     * @return array
     */
    public static function minimise_record($rec) {
        return [
            'id' => $rec->id,
            'activityname' => $rec->activityname,
            'timestart' => $rec->timestart,
            'timeend' => $rec->timeend,
            'location' => $rec->location,
            'transport' => $rec->transport,
            'cost' => $rec->cost,
            'staffinchargejson' => $rec->staffinchargejson,
            'description' => $rec->description,
            'statushelper' => $rec->statushelper,
            'status' => $rec->status,
            'recurring' => $rec->recurring,
            'occurrenceid' => isset($rec->occurrenceid) ? $rec->occurrenceid : 0,
        ];
    }



    /**
     * Insert/update activity from submitted form data.
     *
     * @param array $data
     * @return array
     */
    public static function save_from_data($data) {
        global $USER, $DB;

        $originalactivity = $activity = null;
        $newstatusinfo = (object) array('status' => -1, 'workflow' => []);

        try {

            // Check if data came through with some valid attributes
            if (!isset($data->id))  {
                throw new \Exception("Submitted data is malformed.");
            }

            // UPDATE
            if ($data->id > 0) {
                if (!activity::exists($data->id)) {
                    return;
                }
                if (!utils_lib::has_capability_edit_activity($data->id)) {
                    throw new \Exception("Permission denied.");
                    exit;
                }
                $originalactivity = new Activity($data->id);
                $activity = new Activity($data->id);
                if (static::is_activity($data->activitytype)) {
                    $activity->set('status', max(static::ACTIVITY_STATUS_DRAFT, $activity->get('status')));
                } else {
                    // If this is a calendar entry or assessment, there is no draft state. From the moment it's saved, it's 2 (in review) or 3 (approved)
                    $activity->set('status', max(static::ACTIVITY_STATUS_INREVIEW, $activity->get('status')));
                }
            }  
            // CREATE
            else {
                // Can this user create an activity? Must be a Moodle Admin or Planning staff.
                if (!utils_lib::has_capability_create_activity()) {
                    throw new \Exception("Permission denied.");
                    exit;
                }

                // Create a new activity with data that doesn't change on update.
                $activity = new Activity();
                $activity->set('creator', $USER->username);
                if (static::is_activity($data->activitytype)) {
                    $activity->set('status', static::ACTIVITY_STATUS_DRAFT);
                } else {
                    // If this is a calendar entry or assessment, there is no draft state. From the moment it's saved, it's 2 (in review) or 3 (approved)
                    $activity->set('status', static::ACTIVITY_STATUS_INREVIEW);
                }
                // Generate an idnumber
                $slug = strtolower(trim(preg_replace('/[\s-]+/', '-', preg_replace('/[^A-Za-z0-9-]+/', '-', preg_replace('/[&]/', 'and', preg_replace('/[\']/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $data->activityname))))), '-'));
                do {
                    $random = substr(str_shuffle(MD5(microtime())), 0, 10);
                    $idnumber = $slug.'-'.$random;
                    $exists = $DB->record_exists('activities', ['idnumber' => $idnumber]);
                } while ($exists);
                $activity->set('idnumber', $slug.'-'.$random);
                $activity->save();
            }

            /*var_export($data->timestart); 
            var_export($originalactivity->get("timestart")); 
            var_export($activity->get("timestart")); 
            exit;*/

            // Save data.
            $timeend = $data->timeend;
            if (!$data->isallday) {
                $timeend = intval($data->timeend / 60) * 60;
            }
            $newstatusinfo->status = $activity->get('status');
            $activity->set('activityname', $data->activityname);
            $activity->set('campus', $data->campus);
            $activity->set('activitytype', $data->activitytype);
            $activity->set('location', $data->location);
            $activity->set('timestart', intval($data->timestart / 60) * 60); // Remove seconds.
            $activity->set('timeend', $timeend); // Remove seconds.
            $activity->set('description', $data->description);
            $activity->set('transport', $data->transport);
            $activity->set('cost', $data->cost);
            $activity->set('permissions', $data->permissions);
            $activity->set('permissionslimit', $data->permissionslimit);
            $activity->set('permissionsdueby', $data->permissionsdueby);
            $activity->set('otherparticipants', $data->otherparticipants);
            $activity->set('colourcategory', $data->colourcategory);
            $activity->set('displaypublic', $data->displaypublic ? 1 : 0);
            $activity->set('isallday', $data->isallday ? 1 : 0);
            $activity->set('studentlistjson', $data->studentlistjson);

            // Set absences flag back to 0 so that absences are cleaned in case of student list change.
            $activity->set('absencesprocessed', 0);
            $activity->set('classrollprocessed', 0);

            // Set stepname.
            if (static::is_activity($data->activitytype)) {
                // Set stepname to empty, we'll figure that out when generating approvals.
                $activity->set('stepname', '');
            } else {
                $activity->set('stepname', 'Calendar Approval');
            }
            $activity->save();

            // Default staff in charge.
            if (empty($data->staffincharge)) {
                $activity->set('staffincharge', $USER->username);
                $activity->set('staffinchargejson', json_encode(utils_lib::user_stub($USER->username)));
            } else {
                $staffincharge = (object) array_pop($data->staffincharge);
                // If staffincharge is corrupt in some way, set it to the user.
                if (!isset($staffincharge->un) || empty(\core_user::get_user_by_username($staffincharge->un))) {
                    $activity->set('staffincharge', $USER->username);
                    $activity->set('staffinchargejson', json_encode(utils_lib::user_stub($USER->username)));
                } else {
                    $activity->set('staffincharge', $staffincharge->un);
                    $activity->set('staffinchargejson', $data->staffinchargejson);
                }
            }

            $activity->set('planningstaffjson', $data->planningstaffjson);
            $activity->set('accompanyingstaffjson', $data->accompanyingstaffjson);
            if ($data->secondinchargejson == '""') {
                $data->secondinchargejson = null;
            }
            $activity->set('secondinchargejson', $data->secondinchargejson);
            $activity->save();

            // If categoriesjson is empty, set a default value based on campus.
            $categoriesjson = json_decode($data->categoriesjson);
            if (empty($categoriesjson)) {
                switch ($data->campus) {
                    case 'senior':
                        $campusword = 'Senior School';
                        break;
                    case 'primary':
                        $campusword = 'Primary School';
                        break;
                    default:
                        $campusword = 'Whole School';
                        break;
                }
                $data->categoriesjson = json_encode([$campusword]);
            }
            
            $activity->set('categoriesjson', $data->categoriesjson);
            $areas = json_decode($data->categoriesjson);
            $areas = array_map(function($cat) {
                $split = explode('/', $cat);
                return [end($split)];
            }, $areas);
            $areas = call_user_func_array('array_merge', $areas);
            $areas = array_values(array_unique($areas));
            $activity->set('areasjson', json_encode($areas));
            if (!count($areas) || in_array('CGS Board', $areas)) {
                $activity->set('displaypublic', 0);
            }
            $activity->save();

            // Save RA.
            static::process_files(explode(",", $data->riskassessment), 'riskassessment', $activity->get('id'));
            $riskassessmentck = static::generate_files_changekey('riskassessment', $activity->get('id'));
            $activity->set('riskassessment', $riskassessmentck);

            // Save attachments.
            static::process_files(explode(",", $data->attachments), 'attachments', $activity->get('id'));
            $additionalfilesck = static::generate_files_changekey('attachments', $activity->get('id'));
            $activity->set('attachments', $additionalfilesck);
            $activity->save();

            // Sync the staff lists.
            static::sync_staff_from_data($activity->get('id'), 'planning', $data->planningstaff);
            static::sync_staff_from_data($activity->get('id'), 'accompany', $data->accompanyingstaff);

            // Sync the student list.
            $studentusernames = array_map(function($u) {
                $u = (object) $u;
                return $u->un;
            }, $data->studentlist);
            static::sync_students_from_data($activity->get('id'), $studentusernames);

            // Save recurring settings.
            $newdates = null;
            if ($data->recurringAcceptChanges) {
                $activity->set('recurring', $data->recurring ? 1 : 0);
                $activity->set('recurrence', $data->recurring ? json_encode($data->recurrence) : '');
                $activity->save();
                // If recurring, create the whole series of activities.
                if ($data->recurring) {
                    $newdates = static::create_recurring_activities($activity->get('id'));
                    if ($newdates) {
                        $activity->set('timestart', $newdates->timestart);
                        $activity->set('timeend', $newdates->timeend);
                        $activity->save();
                        $originalactivity->set('timestart', $newdates->timestart);
                        $originalactivity->set('timeend', $newdates->timeend);
                    }
                } else {
                    static::delete_recurring_activities($activity->get('id'));
                }
            }

            // Generate parent permissions based on student list.
            static::generate_permissions($data->id);

            // If saving after already in review or approved, determine the approvers based on campus.
            if ($originalactivity && 
                ($data->status == static::ACTIVITY_STATUS_INREVIEW || $data->status == static::ACTIVITY_STATUS_APPROVED) &&
                static::is_activity($data->activitytype)
            ) {
                $newstatusinfo = workflow_lib::generate_approvals($originalactivity, $activity);
            }

            // Finally, if assessmentid is included in data, update the assessment record with the activityid.
            if ($data->assessmentid) {
                $sql = "UPDATE mdl_activities_assessments
                        SET activityid = ?
                        WHERE id = ?";
                $params = array($activity->get('id'), $data->assessmentid);
                $DB->execute($sql, $params);
            }

        } catch (\Exception $e) {
            // Log and rethrow. 
            // https://stackoverflow.com/questions/5551668/what-are-the-best-practices-for-catching-and-re-throwing-exceptions
            throw $e;
        }

        return array(
            'id' => $activity->get('id'),
            'status' => $newstatusinfo->status,
            'workflow' => $newstatusinfo->workflow,
            'newdates' => $newdates,
        );
    }

    public static function create_recurring_activities($activityid) {
        global $DB;

        $activity = new Activity($activityid);
        $recurring = $activity->get('recurring');
        $recurrence = $activity->get('recurrence');
        // If no recurrence, or no recurrence master id, or the recurrence master id is not the same as the activity id, do nothing.
        if (!$recurring || empty($recurrence)) {
            return;
        }
        $recurrence = json_decode($recurrence);

        // Delete the existing occurrences.
        $DB->delete_records('activities_occurrences', array('activityid' => $activityid));

        // Get the dates for the series of activities.
        $recurrences = json_decode(json_encode(recurrence_lib::expand_dates($recurrence, $activity->get('timestart'), $activity->get('timeend'))));
        if (empty($recurrences->dates)) {
            return;
        }
        $dates = $recurrences->dates;

        if (empty($dates)) {
            return;
        }

        // Sort dates by start date.
        usort($dates, function($a, $b) {
            return $a->start - $b->start;
        });

        // If the first occurrence is a different date to the master activity, we need to update the master activity.
        $newdates = null;
        if ($dates[0]->start != $activity->get('timestart') || $dates[0]->end != $activity->get('timeend')) {
            $newdates = (object) array(
                'timestart' => $dates[0]->start,
                'timeend' => $dates[0]->end,
            );
        }
        
        foreach ($dates as $occurrence) {
            $record = new \stdClass();
            $record->activityid = $activityid;
            $record->timestart = $occurrence->start;
            $record->timeend = $occurrence->end;
            $DB->insert_record('activities_occurrences', $record);
        }

        return $newdates;
    }

    public static function delete_recurring_activities($activityid) {
        global $DB;
        $DB->delete_records('activities_occurrences', array('activityid' => $activityid));
    }



    /**
     * Update team staff.
     *
     * @param int $activityid
     * @param string $type coach|assistant
     * @param array $newstaff array of user stub objects
     * @return void
     */
    public static function sync_staff_from_data($activityid, $type, $newstaff) {
        global $DB;

        // Copy usernames into keys.
        $usernames = array_column($newstaff, "un");
        $newstaff = array_combine($usernames, $newstaff);

        // Load existing usernames
        $existingstaffrecs = static::get_staff($activityid, $type, $fields = '*');
        $existingstaff = array_column($existingstaffrecs, "username");
        $existingstaff = array_combine($existingstaff, $existingstaff);
        
        // Skip over existing staff.
        foreach ($existingstaff as $un) {
            if (array_key_exists($un, $newstaff)) {
                unset($newstaff[$un]);
                unset($existingstaff[$un]);
            }
        }

        // Process inserted staff.
        if (count($newstaff)) {
            $newstaffdata = array_map(function($staff) use ($activityid, $type) {
                $staff = (object) $staff;
                $rec = new \stdClass();
                $rec->activityid = $activityid;
                $rec->username = $staff->un;
                $rec->usertype = $type;
                return $rec;
            }, $newstaff);
            $DB->insert_records('activities_staff', $newstaffdata);
        }

        // Process remove staff.
        if (count($existingstaff)) {
            list($insql, $inparams) = $DB->get_in_or_equal($existingstaff);
            $params = array_merge([$activityid, $type], $inparams);
            $sql = "DELETE FROM {activities_staff} 
            WHERE activityid = ? 
            AND usertype = ? 
            AND username $insql";
            $DB->execute($sql, $params);
        }
    }

    /**
     * Update activity students.
     *
     * @param int $activityid
     * @param array $newstudents
     * @return void
     */   
    public static function sync_students_from_data($activityid, $newstudents) {
        global $DB;

        // Copy usernames into keys.
        $newstudents = array_combine($newstudents, $newstudents);

        // Load existing students.
        $existingstudentrecs = static::get_students($activityid);
        $existingstudents = array_column($existingstudentrecs, 'username');
        $existingstudents = array_combine($existingstudents, $existingstudents);

        // Skip over existing students.
        foreach ($existingstudents as $existingun) {
            if (in_array($existingun, $newstudents)) {
                unset($newstudents[$existingun]);
                unset($existingstudents[$existingun]);
            }
        }

        // Process inserted students.
        if (count($newstudents)) {
            $newstudentdata = array_map(function($username) use ($activityid) {
                $rec = new \stdClass();
                $rec->activityid = $activityid;
                $rec->username = $username;
                return $rec;
            }, $newstudents);
            $DB->insert_records('activities_students', $newstudentdata);
        }

        // Process removed students.
        if (count($existingstudents)) {
            list($insql, $inparams) = $DB->get_in_or_equal($existingstudents);
            $params = array_merge([$activityid], $inparams);
            $sql = "DELETE FROM {activities_students} 
            WHERE activityid = ? 
            AND username $insql";
            $DB->execute($sql, $params);
        }
    }

    /**
     * Get staff data for a given team.
     *
     * @param int $activityid
     * @param string $usertype
     * @param string $fields
     * @return array
     */
    public static function get_all_staff($activityid, $usertype = "*", $fields = "*") {
        global $DB;
        $extras = static::get_staff($activityid, $usertype, $fields);
        $activity = new Activity($activityid);
        return array_merge([$activity->get('staffincharge')], array_column($extras, 'username'));
    }

    /**
     * Get staff data for a given team.
     *
     * @param int $activityid
     * @param string $usertype
     * @param string $fields
     * @return array
     */
    public static function get_staff($activityid, $usertype = "*", $fields = "*") {
        global $DB;
        $conds = array('activityid' => $activityid);
        if ($usertype != "*") {
            $conds['usertype'] = $usertype;
        }
        return $DB->get_records('activities_staff', $conds, '', $fields);
    }
    
    /**
     * Get student data from a list of teams.
     *
     * @param array $activityid
     * @return array
     */
    public static function get_students($activityid) {
        global $DB;
        $conds = array('activityid' => $activityid);
        return $DB->get_records('activities_students', $conds);
    }



    
    


     /**
     * Update status and trigger effects.
     *
     * @param int $activityid 
     * @param int $status the new status.
     * @return
     */
    public static function update_status($activityid, $status) {
        global $DB;

        if (!activity::exists($activityid)) {
            return;
        }
        if (!utils_lib::has_capability_edit_activity($activityid)) {
            throw new \Exception("Permission denied.");
            exit;
        }

        $originalactivity = new Activity($activityid);
        $activity = new Activity($activityid);
        $activity->set('status', $status);
        $activity->save();

        // If going to draft, remove any existing approvals.
        if ($status <= static::ACTIVITY_STATUS_DRAFT) {
            $sql = "UPDATE mdl_activities_approvals
                    SET invalidated = 1
                    WHERE activityid = ?
                    AND invalidated = 0";
            $params = array($activityid);
            $DB->execute($sql, $params);

            // Reset public now.
            $activity->set('pushpublic', 0);
            $activity->save();
        }

        // If sending for review, determine the approvers.
        $newstatusinfo = (object) array('status' => $status, 'workflow' => []);
        if ($status == static::ACTIVITY_STATUS_INREVIEW) {
            $newstatusinfo = workflow_lib::generate_approvals($originalactivity, $activity);
        }

        return array(
            'id' => $activityid,
            'status' => $newstatusinfo->status,
            'workflow' => $newstatusinfo->workflow,
        );
    }



    

    
    


































    // TODO: This is slow. Iterating through every student/mentor. Need to do in bulk.
    private static function generate_permissions($activityid) {
        global $DB, $USER;

        $activity = new static($activityid);
        if (empty($activity)) {
            return;
        }

        // Generate permissions for saved students.
        $students = static::get_activities_students($activityid);
        foreach ($students as $student) {
            // Find the student's mentors.
            $user = \core_user::get_user_by_username($student->username);
            if (empty($user)) {
                continue;
            }
            $mentors = utils_lib::get_user_mentors($user->id);
            foreach ($mentors as $mentor) {
                // Only insert this if it doesn't exist.
                $exists = $DB->record_exists(activities_lib::TABLE_ACTIVITY_PERMISSIONS, array(
                    'activityid' => $activityid,
                    'studentusername' => $student->username,
                    'parentusername' => $mentor,
                ));

                if (!$exists) {
                    // Create a permissions record for each mentor.
                    $permission = new \stdClass();
                    $permission->activityid = $activityid;
                    $permission->studentusername = $student->username;
                    $permission->parentusername = $mentor;
                    $permission->queueforsending = 0;
                    $permission->queuesendid = 0;
                    $permission->response = 0;
                    $permission->timecreated = time();
                    $DB->insert_record(activities_lib::TABLE_ACTIVITY_PERMISSIONS, $permission);
                }
            }
        }
    }

    public static function get_changed_fields($originalactivity, $newactivity) {
        $changed = array();

        $originalvars = (array) $originalactivity->get_data();
        $newvars = (array) $newactivity->get_data();

        unset($originalvars['absencesprocessed']);
        unset($newvars['absencesprocessed']);
        unset($originalvars['remindersprocessed']);
        unset($newvars['remindersprocessed']);
        unset($originalvars['classrollprocessed']);
        unset($newvars['classrollprocessed']);

        foreach ($originalvars as $key => $val) {
            if ($val != $newvars[$key]) {
                $label = $key;
                if ($key == "permissionslimit") {
                    $label = 'Permissions limit';
                }
                if ($key == "permissionsdueby") {
                    $label = 'Permission due by date';
                }
                if (empty($label)) {
                    $label = $key;
                }
                $changed[$key] = array(
                    'field' => $key,
                    'label' => $label,
                    'originalval' => $val,
                    'newval' => $newvars[$key],
                );
            }
        }

        //unset unnecessary fields
        unset($changed['usermodified']);
        unset($changed['timemodified']);

        return $changed;
    }

    public static function search($text) {
        global $DB;

        $sql = "SELECT id, status
                  FROM {" . static::TABLE . "}
                 WHERE deleted = 0
                   AND (activityname LIKE ? OR staffincharge LIKE ? OR creator LIKE ?)";
        $params = array();
        $params[] = '%'.$text.'%';
        $params[] = '%'.$text.'%';
        $params[] = '%'.$text.'%';
        //echo "<pre>"; var_export($sql); var_export($params); exit;

        $records = $DB->get_records_sql($sql, $params);
        $activities = array();
        foreach ($records as $record) {
            $activity = new Activity($record->id);
            $exported = $activity->export();
            if((!$exported->usercanedit) && $exported->status < static::ACTIVITY_STATUS_INREVIEW) {
                continue;
            }
            $activities[] = $exported;
        }

        return $activities;
    }


    /**
     * Staff can see all events. When they view an event, the editability is based on who they are and their involvement.
     *
     * @param array $args
     * @return array
     */
    public static function get_for_staff_calendar($args) {
        global $DB;

        utils_lib::require_staff();

        $start = strtotime($args->scope->start . " 00:00:00");
        $end = strtotime($args->scope->end . " 00:00:00");
        $end += 86400; //add a day

        $statussql = "AND status >= " . static::ACTIVITY_STATUS_INREVIEW;
        if (workflow_lib::is_cal_reviewer()) {
            $statussql = "";
        }

        $sql = "SELECT id 
                FROM mdl_activities
                WHERE deleted = 0
                AND recurring = 0
                $statussql
                AND (
                    (timestart >= ? AND timestart <= ?) OR 
                    (timeend >= ? AND timeend <= ?) OR
                    (timestart < ? AND timeend > ?)
                )
                ORDER BY timestart ASC";
        $params = [$start, $end, $start, $end, $start, $end];
        $records = $DB->get_records_sql($sql, $params);
        $activities = array();

        // Add non-recurring activities
        foreach ($records as $record) {
            $activity = new Activity($record->id);
            $activities[] = $activity->export_minimal();
        }

        // Query for occurrences of recurring activities (recurring = 1)
        $sql = "SELECT ao.id, ao.activityid, ao.timestart, ao.timeend
                FROM mdl_activities a
                JOIN mdl_activities_occurrences ao ON ao.activityid = a.id
                WHERE a.deleted = 0
                AND a.recurring = 1
                $statussql
                AND (
                    (ao.timestart >= ? AND ao.timestart <= ?) OR 
                    (ao.timeend >= ? AND ao.timeend <= ?) OR
                    (ao.timestart < ? AND ao.timeend > ?)
                )";

        $occurrences = $DB->get_records_sql($sql, $params);

        // Add occurrences as virtual activities with modified times
        foreach ($occurrences as $occurrence) {
            $activity = new Activity($occurrence->activityid);
            $minimal = $activity->export_minimal();
            
            // Override the timestamps with the occurrence's times
            $minimal->timestart = $occurrence->timestart;
            $minimal->timeend = $occurrence->timeend;
            
            $minimal->is_occurrence = true;
            $minimal->occurrenceid = $occurrence->id; // reference to parent
            
            $activities[] = $minimal;
        }

        // Sort all activities by start time
        usort($activities, function($a, $b) {
            return $a->timestart - $b->timestart;
        });

        return $activities;
    }

     /**
     * Public events!
     *
     * @param array $args
     * @return array
     */
    public static function get_for_public_calendar($args) {
        global $DB;

        $start = strtotime($args->scope->start . " 00:00:00");
        $end = strtotime($args->scope->end . " 00:00:00");
        $end += 86400; //add a day

        $status_condition = "(status = " . static::ACTIVITY_STATUS_APPROVED . " OR (status = " . static::ACTIVITY_STATUS_INREVIEW . " AND pushpublic = 1))";

        // Get non-recurring public activities
        $sql = "SELECT id 
                FROM mdl_activities
                WHERE deleted = 0
                AND recurring = 0
                AND displaypublic = 1
                AND $status_condition
                AND (
                    (timestart >= ? AND timestart <= ?) OR 
                    (timeend >= ? AND timeend <= ?) OR
                    (timestart < ? AND timeend > ?)
                )
                ORDER BY timestart ASC";
        $params = [$start, $end, $start, $end, $start, $end];
        $records = $DB->get_records_sql($sql, $params);
        $activities = array();

        // Add non-recurring activities
        foreach ($records as $record) {
            $activity = new Activity($record->id);
            $activities[] = $activity->export_minimal();
        }

        // Get occurrences of recurring public activities
        $sql = "SELECT ao.id, ao.activityid, ao.timestart, ao.timeend
                FROM mdl_activities a
                JOIN mdl_activities_occurrences ao ON ao.activityid = a.id
                WHERE a.deleted = 0
                AND a.recurring = 1
                AND a.displaypublic = 1
                AND $status_condition
                AND (
                    (ao.timestart >= ? AND ao.timestart <= ?) OR 
                    (ao.timeend >= ? AND ao.timeend <= ?) OR
                    (ao.timestart < ? AND ao.timeend > ?)
                )";

        $occurrences = $DB->get_records_sql($sql, $params);

        // Add occurrences as virtual activities with modified times
        foreach ($occurrences as $occurrence) {
        $activity = new Activity($occurrence->activityid);
        $minimal = $activity->export_minimal();

        // Override the timestamps with the occurrence's times
        $minimal->timestart = $occurrence->timestart;
        $minimal->timeend = $occurrence->timeend;

        $minimal->is_occurrence = true;
        $minimal->occurrenceid = $occurrence->id;

        $activities[] = $minimal;
        }

        // Sort all activities by start time
        usort($activities, function($a, $b) {
        return $a->timestart - $b->timestart;
        });

        return $activities;
    }


    /*
    * get_involvement
    */
    public static function get_by_involvement() {
        global $DB, $USER;

        // We need to find events where this user is:
        // Student participant
        // Parent of participating student
        // Staff member in charge
        // Planner
        // Accompanying

        $involvement = array(
            'student' => array(
                'heading' => "",
                'events' => array(),
            ),
            'parent' => array(
                'heading' => "Your children's activities",
                'events' => array(),
            ),
            'staff' => array(
                'heading' => "Staff member in charge",
                'events' => array(),
            ),
            'planner' => array(
                'heading' => "Planner",
                'events' => array(),
            ),
            'accompanying' => array(
                'heading' => "Accompanying staff",
                'events' => array(),
            ),
            'approver' => array(
                'heading' => "Approver",
                'events' => array(),
            ),
        );

        // Student participant
        $involvement['student']['events'] = static::get_for_student($USER->username, 'future');

        // Parent of participating student
        $involvement['parent']['events'] = static::get_for_parent($USER->username, 'future');

        // Staff member in charge
        $involvement['staff']['events'] = static::get_for_owner($USER->username, 'future');

        // Planner
        $involvement['planner']['events'] = static::get_for_plannner($USER->username, 'future');

        // Accompanying
        $involvement['accompanying']['events'] = static::get_for_accompanying($USER->username, 'future');

        // Approver
        $involvement['approver']['events'] = static::get_for_specific_approver($USER->username, 'future');

        return $involvement;
    }


    /*
    * get_history
    */
    public static function get_history() {
        global $DB, $USER;

        // We need to find events where this user is:
        // Student participant
        // Parent of participating student
        // Staff member in charge
        // Planner
        // Accompanying

        $involvement = array(
            'student' => array(
                'heading' => "",
                'events' => array(),
            ),
            'parent' => array(
                'heading' => "Your children's activities",
                'events' => array(),
            ),
            'staff' => array(
                'heading' => "Staff member in charge",
                'events' => array(),
            ),
            'planner' => array(
                'heading' => "Planner",
                'events' => array(),
            ),
            'accompanying' => array(
                'heading' => "Accompanying staff",
                'events' => array(),
            ),
        );

        // Student participant
        $involvement['student']['events'] = static::get_for_student($USER->username, 'past');

        // Parent of participating student
        $involvement['parent']['events'] = static::get_for_parent($USER->username, 'past');

        // Staff member in charge
        $involvement['staff']['events'] = static::get_for_owner($USER->username, 'past');

        // Planner
        $involvement['planner']['events'] = static::get_for_plannner($USER->username, 'past');

        // Accompanying
        $involvement['accompanying']['events'] = static::get_for_accompanying($USER->username, 'past');

        return $involvement;
    }

    /*public static function get_by_ids($ids, $status = null, $orderby = null, $period = null, $exported = true, $getall = true, $page = 1) { // Period is null, "past" or "future"
        global $DB, $CFG;

        $perpage = 8;
        $offset = ($page - 1) * $perpage;

        $activities = array();

        if ($ids) {
            $activityids = array_unique($ids);
            list($insql, $inparams) = $DB->get_in_or_equal($activityids);
            $sql = "SELECT *
                    FROM {" . static::TABLE . "}
                    WHERE id $insql
                    AND deleted = 0";

            if ($status) {
                $sql .= " AND status = {$status} ";
            }

            if ($period && $period == 'past') {
                $sql .= " AND timeend < " . time();
            }

            if ($period && $period == 'future') {
                $sql .= " AND timeend >= " . time();
            }

            if (empty($orderby)) {
                $orderby = 'timestart ASC';
            }
            $sql .= " ORDER BY " . $orderby;

            if (!$getall) {
                if ($CFG->dbtype === 'mysqli' || $CFG->dbtype === 'mariadb') {
                    $sql .= " LIMIT $perpage OFFSET $offset";
                } else {
                    $sql .= " OFFSET $offset ROWS FETCH NEXT $perpage ROWS ONLY";
                }
            }

            $records = $DB->get_records_sql($sql, $inparams);
            $activities = array();
            foreach ($records as $record) {
                $activity = new Activity($record->id);
                if ($exported) {
                    $activities[] = $activity->export_minimal();
                } else {
                    $activities[] = $activity;
                }
            }
        }

        return $activities;
    }*/

    public static function get_by_ids($ids, $status = null, $period = null, $exported = true) {
        global $DB, $CFG;
    
        $activities = array();
    
        if ($ids) {
            $activityids = array_unique($ids);
            list($insql, $inparams) = $DB->get_in_or_equal($activityids);
            
            // Base WHERE conditions - now properly qualified with table alias
            $where = "WHERE a.id $insql AND a.deleted = 0";
            
            if ($status) {
                $where .= " AND a.status = {$status}";
            }
    
            // Time conditions for non-recurring activities
            $timeCondition = "";
            if ($period == 'past') {
                $timeCondition = " AND a.timeend < " . time();
            } elseif ($period == 'future') {
                $timeCondition = " AND a.timeend >= " . time();
            }
    
            // Get non-recurring activities
            $sql = "SELECT *
                    FROM {" . static::TABLE . "} a
                    $where
                    AND a.recurring = 0
                    $timeCondition";
    
            $records = $DB->get_records_sql($sql, $inparams);
            foreach ($records as $record) {
                $activity = new Activity($record->id);
                if ($exported) {
                    $activities[] = $activity->export_minimal();
                } else {
                    $activities[] = $activity;
                }
            }
    
            // Get occurrences of recurring activities
            $sql = "SELECT ao.id as occurrenceid, ao.timestart as occurrence_start, ao.timeend as occurrence_end, a.*
                    FROM {" . static::TABLE . "} a
                    JOIN mdl_activities_occurrences ao ON ao.activityid = a.id
                    $where
                    AND a.recurring = 1";
    
            // Add time conditions for occurrences
            if ($period == 'past') {
                $sql .= " AND ao.timeend < " . time();
            } elseif ($period == 'future') {
                $sql .= " AND ao.timeend >= " . time();
            }
    
            $occurrences = $DB->get_records_sql($sql, $inparams);
            foreach ($occurrences as $occurrence) {
                $activity = new Activity($occurrence->id);
                if ($exported) {
                    $minimal = $activity->export_minimal();
                    // Override timestamps with occurrence times
                    $minimal->timestart = $occurrence->occurrence_start;
                    $minimal->timeend = $occurrence->occurrence_end;
                    $minimal->is_occurrence = true;
                    $minimal->occurrenceid = $occurrence->occurrenceid;
                    $activities[] = $minimal;
                } else {
                    // For non-exported objects, we need to modify the timestamps
                    $activity->set('timestart', $occurrence->occurrence_start);
                    $activity->set('timeend', $occurrence->occurrence_end);
                    $activity->set('is_occurrence', true);
                    $activity->set('occurrenceid', $occurrence->occurrenceid);
                    $activities[] = $activity;
                }
            }
    
            if ($period == 'past') {
                // Past events: most recent first (descending)
                usort($activities, function($a, $b) use ($exported) {
                    $aTime = $exported ? $a->timestart : $a->get('timestart');
                    $bTime = $exported ? $b->timestart : $b->get('timestart');
                    return $bTime - $aTime; // Note: reversed for descending order
                });
            } else {
                // Future events: chronological order (ascending)
                usort($activities, function($a, $b) use ($exported) {
                    $aTime = $exported ? $a->timestart : $a->get('timestart');
                    $bTime = $exported ? $b->timestart : $b->get('timestart');
                    return $aTime - $bTime;
                });
            }
            
        }
    
        return $activities;
    }

    public static function get_for_student($username, $period = null) {
        global $DB;

        $activities = array();

        $sql = "SELECT id, activityid
                  FROM {" . static::TABLE_ACTIVITY_STUDENTS . "} 
                 WHERE username = ?";
        $ids = $DB->get_records_sql($sql, array($username));

        $activities = static::get_by_ids(array_column($ids, 'activityid'), static::ACTIVITY_STATUS_APPROVED, $period); // Approved and future only.
        foreach ($activities as $i => $activity) {
            if ($activity->permissions) {
                $attending = static::get_all_attending($activity->id);
                if (!in_array($username, $attending)) {
                    unset($activities[$i]);
                    continue;
                }
            }
            $activities[$i] = static::minimise_record($activity);
        }

        return array_filter(array_values($activities));
    }

    public static function get_for_parent($username, $period = null) {
        global $DB;

        // Can't rely on this anymore, because parents without liveswith do not go into the permission table.
        //$sql = "SELECT activityid
        //          FROM {" . static::TABLE_ACTIVITY_PERMISSIONS . "} 
        //         WHERE parentusername = ?";
        //$ids = $DB->get_fieldset_sql($sql, array($username));

        $user = \core_user::get_user_by_username($username);
        $mentees = utils_lib::get_user_mentees($user->id);

        if (empty($mentees)) {
            return array();
        }

        list($insql, $inparams) = $DB->get_in_or_equal($mentees);
        $sql = "SELECT DISTINCT activityid
                  FROM {" . static::TABLE_ACTIVITY_STUDENTS . "} 
                 WHERE username $insql";
        $ids = $DB->get_fieldset_sql($sql, $inparams);
        $raw = static::get_by_ids($ids, static::ACTIVITY_STATUS_APPROVED, $period, false); // Approved and future only.

        $activities = array();
        foreach ($raw as $i => $activity) {
            // Export the activity.
            $exported = $activity->export_minimal();

            // Look through the other activities to see if this is the same activity.
            $recurring = false;
            foreach ($activities as $j => $otheractivity) {
                if ($otheractivity['recurring'] == '1' && $otheractivity['id'] == $activity->get('id')) {
                    $recurring = true;
                    break;
                }
            }

            // Add it to the list.
            $activities[$i] = static::minimise_record($exported);

            // Skip if it's recurring or doesn't have permissions.
            if ($recurring || !$activity->get('permissions')) {
                continue;
            }
            
            // Get the permissions for this parent.
            $permissions = static::get_parent_permissions($activity->get('id'), $username);
            foreach ($permissions as &$permission) {
                $permission->student = utils_lib::user_stub($permission->studentusername);
            }
            $activities[$i]['stupermissions'] = array_values($permissions);
            $activities[$i]['permissionshelper'] = static::permissions_helper($activity);
         
        }

        return $activities;
    }

    public static function get_for_owner($username, $period = null) {
        global $DB;

        $activities = array();

        $sql = "SELECT id
                  FROM {" . static::TABLE . "} 
                 WHERE staffincharge = ?";
        $ids = $DB->get_fieldset_sql($sql, array($username));

        $activities = static::get_by_ids($ids, null, $period); // All statuses and future only.

        return $activities;
    }

    public static function get_for_plannner($username, $period = null) {
        global $DB;

        // Get creator and planners.
        $activities = array();

        $sql = "SELECT activityid
                FROM {" . static::TABLE_ACTIVITY_STAFF. "} 
                WHERE username = ? 
                AND usertype = 'planning'";
        $plannerids = $DB->get_fieldset_sql($sql, array($username));

        $activities = static::get_by_ids($plannerids, null, $period); // All statuses and future only.

        return $activities;
    }

    public static function get_for_accompanying($username, $period = null) {
        global $DB;

        $activities = array();

        $sql = "SELECT activityid
                FROM {" . static::TABLE_ACTIVITY_STAFF. "} 
                WHERE username = ? 
                AND usertype = 'accompany'";
        $ids = $DB->get_fieldset_sql($sql, array($username));

        $activities = static::get_by_ids($ids, null, $period); // All statuses and future only.

        return $activities;
    }

    public static function get_for_specific_approver($username, $period = null) {
        global $DB;

        $activities = array();

        $sql = "SELECT id, activityid, type
                    FROM mdl_activities_approvals
                    WHERE nominated = ?
                    AND invalidated = 0
                    AND skip = 0
                    AND status = 0";
        $approvals = $DB->get_records_sql($sql, array($username));
        $approvals = workflow_lib::filter_approvals_with_prerequisites($approvals);
        $activities = static::get_by_ids(array_column($approvals, 'activityid'), null, $period); // All statuses and future only.

        return $activities;
    }


    public static function get_for_approver($username, $period = null) {
        global $DB;

        $activities = array();

        $approvertypes = workflow_lib::get_approver_types($username);
        
        if ($approvertypes) {
            // The user has approver types. Check if any activities need this approval.
            list($insql, $inparams) = $DB->get_in_or_equal($approvertypes);
            $sql = "SELECT id, activityid, type
                      FROM mdl_activities_approvals
                     WHERE type $insql
                       AND invalidated = 0
                       AND skip = 0
                       AND status = 0";
            $approvals = $DB->get_records_sql($sql, $inparams);
            $approvals = workflow_lib::filter_approvals_with_prerequisites($approvals);
            $activities = static::get_by_ids(array_column($approvals, 'activityid'), null, $period); // All statuses and future only.
        }

        return $activities;
    }


    public static function get_for_cal_sync() {
        global $DB;

        // Get non-recurring activities
        $sql = "SELECT *
                FROM {activities}
                WHERE timesynclive < timemodified
                AND recurring = 0";
        $records = $DB->get_records_sql($sql);
        $activities = array();
        
        // Process non-recurring activities
        foreach ($records as $record) {
            $activity = new Activity($record->id, true);
            $activities[] = $activity->export_minimal();
        }

        // Get occurrences of recurring activities
        $sql = "SELECT ao.id, ao.timestart, ao.timeend, a.id as activityid
                FROM {" . static::TABLE . "} a
                JOIN mdl_activities_occurrences ao ON ao.activityid = a.id
                WHERE a.timesynclive < a.timemodified
                AND a.recurring = 1";

        $occurrences = $DB->get_records_sql($sql);

        // Process recurring activity occurrences
        foreach ($occurrences as $occurrence) {
            $activity = new Activity($occurrence->activityid, true);
            $minimal = $activity->export_minimal();
            // Update timestamps to the occurrence's times
            $minimal->timestart = $occurrence->timestart;
            $minimal->timeend = $occurrence->timeend;
            $minimal->is_occurrence = true;
            $minimal->occurrenceid = $occurrence->id;
            $activities[] = $minimal;
        }
        
        return $activities;
    }



    public static function get_for_absences($now, $startlimit, $endlimit) {
        global $DB;

        // Activies must:
        // - be approved.
        // - be unprocessed since the last change.
        // - start within the next two weeks ($startlimit) OR
        // - currently running OR
        // - ended within the past 7 days ($endlimit) 
        // - Suspected race condition - some times sync does not go through when someone makes an edit... 
        //   To be sure, pick up activities that were edited in the last 120 minutes.

        $now = time();
        $minus120minutes = $now - (120 * 60); // 120 minutes * 60 seconds

        // Get non-recurring activities
        $sql = "SELECT id
                FROM {" . static::TABLE . "}
                WHERE (absencesprocessed = 0 OR timemodified >= {$minus120minutes})
                AND recurring = 0
                AND (
                    (timestart <= {$startlimit} AND timestart >= {$now}) OR
                    (timestart <= {$now} AND timeend >= {$now}) OR
                    (timeend >= {$endlimit} AND timeend <= {$now})
                )";

        $records = $DB->get_records_sql($sql, null);
        $activities = array();
        
        // Process non-recurring activities
        foreach ($records as $record) {
            $activities[] = new Activity($record->id, true);
        }

        // Get occurrences of recurring activities
        $sql = "SELECT ao.id, ao.timestart, ao.timeend, a.id as activityid
                FROM {" . static::TABLE . "} a
                JOIN mdl_activities_occurrences ao ON ao.activityid = a.id
                WHERE (a.absencesprocessed = 0 OR a.timemodified >= {$minus120minutes})
                AND a.recurring = 1
                AND (
                    (ao.timestart <= {$startlimit} AND ao.timestart >= {$now}) OR
                    (ao.timestart <= {$now} AND ao.timeend >= {$now}) OR
                    (ao.timeend >= {$endlimit} AND ao.timeend <= {$now})
                )";

        $occurrences = $DB->get_records_sql($sql);

        // Process recurring activity occurrences
        foreach ($occurrences as $occurrence) {
            $activity = new Activity($occurrence->activityid, true);
            // Update timestamps to the occurrence's times
            $activity->set('timestart', $occurrence->timestart);
            $activity->set('timeend', $occurrence->timeend);
            $activity->set('is_occurrence', true);
            $activity->set('occurrenceid', $occurrence->id);
            $activities[] = $activity;
        }
        
        return $activities;
    }

    /**
     * Get approved activities with student lists for sync verification.
     *
     * @param int $date Unix timestamp for the date to check
     * @return array Array of activities with student sync status
     */
    public static function get_for_sync_verification($date) {
        global $DB, $CFG;

        // Confirm this is a staff member!
        if (!utils_lib::is_user_staff()) {
            throw new \Exception('Permission denied.');
        }
        
        $config = get_config('local_announcements2');
        if (empty($config->dbhost ?? '') || empty($config->dbuser ?? '') || empty($config->dbpass ?? '') || empty($config->dbname ?? '')) {
            throw new \Exception('Missing database configuration for sync verification.');
        }

        try {
            $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');
        } catch (Exception $e) {
            throw new \Exception('Failed to connect to external database for sync verification.');
        }

        // Get approved activities that have students and are on the specified date
        $startofday = strtotime('midnight', $date);
        $endofday = strtotime('tomorrow', $startofday) - 1;
        
        $sql = "SELECT DISTINCT a.id
                FROM {" . static::TABLE . "} a
                JOIN {activities_students} ast ON a.id = ast.activityid
                WHERE a.status = :status 
                AND a.recurring = 0
                AND a.timestart >= :startofday 
                AND a.timestart <= :endofday";

        $activities = $DB->get_records_sql($sql, array(
            'status' => static::ACTIVITY_STATUS_APPROVED,
            'startofday' => $startofday,
            'endofday' => $endofday
        ));

        $activities = static::get_by_ids(array_column($activities, 'id'), static::ACTIVITY_STATUS_APPROVED, null, true);

        $result = array();
        $appendix = ($CFG->wwwroot != 'https://connect.cgs.act.edu.au') ? '#ID-UAT-' : '#ID-';

        foreach ($activities as $activity) {
            // Get students for this activity
            $students = static::get_all_attending($activity->id);
            
            if (empty($students)) {
                continue;
            }

            $activitystart = date('Y-m-d H:i', $activity->timestart);
            $activityend = date('Y-m-d H:i', $activity->timeend);
            
            $studentSyncStatus = array();
            
            foreach ($students as $student) {
                // Check if absence exists for this student and activity
                $sql = $config->checkabsencesql . ' :username, :leavingdate, :returningdate, :comment';
                $params = array(
                    'username' => $student,
                    'leavingdate' => $activitystart,
                    'returningdate' => $activityend,
                    'comment' => $appendix . $activity->id,
                );
                
                $absenceexists = false;
                $absenceexists = $externalDB->get_field_sql($sql, $params);

                $student = utils_lib::user_stub($student);
                $studentSyncStatus[] = array(
                    'un' => $student->un,
                    'fn' => $student->fn,
                    'ln' => $student->ln,
                    'synced' => !empty($absenceexists)
                );
            }

            $activity->students = $studentSyncStatus;
            $result[] = $activity;
        }

        return $result;
    }

    public static function get_for_roll_creation($now, $startlimit) {
        global $DB;
    
        // Activies must:
        // - be approved.
        // - be unprocessed since the last change.
        // - start within the next x days ($startlimit) OR
        // - currently running

        // Get non-recurring activities
        $sql = "SELECT id
                FROM {" . static::TABLE . "}
                WHERE classrollprocessed = 0
                AND recurring = 0
                AND (
                    (timestart <= {$startlimit} AND timestart >= {$now}) OR
                    (timestart <= {$now} AND timeend >= {$now})
                )";
    
        $records = $DB->get_records_sql($sql);
        $activities = array();
        
        // Process non-recurring activities
        foreach ($records as $record) {
            $activities[] = new Activity($record->id, true);
        }
    
        // Get occurrences of recurring activities
        $sql = "SELECT ao.id, ao.timestart, ao.timeend, a.id as activityid
                FROM {" . static::TABLE . "} a
                JOIN mdl_activities_occurrences ao ON ao.activityid = a.id
                WHERE a.classrollprocessed = 0
                AND a.recurring = 1
                AND (
                    (ao.timestart <= {$startlimit} AND ao.timestart >= {$now}) OR
                    (ao.timestart <= {$now} AND ao.timeend >= {$now})
                )";
    
        $occurrences = $DB->get_records_sql($sql);
    
        // Process recurring activity occurrences
        foreach ($occurrences as $occurrence) {
            $activity = new Activity($occurrence->activityid, true);
            // Update timestamps to the occurrence's times
            $activity->set('timestart', $occurrence->timestart);
            $activity->set('timeend', $occurrence->timeend);
            $activity->set('is_occurrence', true);
            $activity->set('occurrenceid', $occurrence->id);
            $activities[] = $activity;
        }
        
        return $activities;
    }


    public static function get_for_attendance_reminders() {
        global $DB;

        $now = time();
        $sql = "SELECT id
                  FROM {" . static::TABLE . "}
                 WHERE remindersprocessed = 0
                   AND deleted = 0
                   AND timeend <= {$now}
                   AND status = " . static::ACTIVITY_STATUS_APPROVED;
        $records = $DB->get_records_sql($sql, null);
        $activities = array();
        foreach ($records as $record) {            
            $activities[] = new Activity($record->id);
        }
        
        return $activities;
    }


    public static function get_for_approval_reminders($rangestart, $rangeend) {
        global $DB;

        // Activies must:
        // - be unapproved.
        // - starting in x days ($rangestart)
        $sql = "SELECT id
                  FROM {" . static::TABLE . "}
                 WHERE timestart >= {$rangestart} AND timestart <= {$rangeend}
                   AND (
                    status = " . static::ACTIVITY_STATUS_DRAFT ." OR 
                    status = " . static::ACTIVITY_STATUS_INREVIEW . "
                   )
                   AND deleted = 0";
        $records = $DB->get_records_sql($sql, null);
        $activities = array();
        foreach ($records as $record) {
            $activities[] = new Activity($record->id);
        }
        
        return $activities;
    }


    /**
    * Gets all of the activity students.
    *
    * @param int $postid.
    * @return array.
    */
    public static function get_activities_students($activityid, $status = null) {
        global $DB;
        
        $sql = "SELECT s.*
                  FROM {" . static::TABLE_ACTIVITY_STUDENTS . "} s
                INNER JOIN {" . static::TABLE . "} a ON a.id = s.activityid
                 WHERE s.activityid = ?
        ";

        if ($status) {
            $sql .= " AND a.status = {$status}";
        }

        $params = array($activityid);
        $students = $DB->get_records_sql($sql, $params);
        return $students;
    }

    
    /*
    * Add a comment to an activity.
    */
    public static function post_comment($activityid, $comment) {
        global $USER, $DB;

        if (!activity::exists($activityid)) {
            return 0;
        }

        // Save the comment.
        $record = new \stdClass();
        $record->username = $USER->username;
        $record->activityid = $activityid;
        $record->comment = $comment;
        $record->timecreated = time();
        $record->id = $DB->insert_record(static::TABLE_ACTIVITY_COMMENTS, $record);

        static::send_comment_emails($record);

        return $record->id;
    }

    /*
    * Delete a comment
    */
    public static function delete_comment($commentid) {
        global $USER, $DB;

        $DB->delete_records(static::TABLE_ACTIVITY_COMMENTS, array(
            'id' => $commentid,
            'username' => $USER->username,
        ));

        return 1;
    }

    /*
    * Get comments for an activity.
    */
    public static function get_comments($activityid) {
        global $USER, $DB, $PAGE, $OUTPUT;

        if (!activity::exists($activityid)) {
            return 0;
        }
        if (!utils_lib::has_capability_edit_activity($activityid)) {
            throw new \Exception("Permission denied.");
            exit;
        }

        $sql = "SELECT *
                  FROM {" . static::TABLE_ACTIVITY_COMMENTS . "}
                 WHERE activityid = ?
              ORDER BY timecreated DESC";
        $params = array($activityid);
        $records = $DB->get_records_sql($sql, $params);
        $comments = array();
        foreach ($records as $record) {
            $comment = new \stdClass();
            $comment->id = $record->id;
            $comment->activityid = $record->activityid;
            $comment->username = $record->username;
            $comment->comment = $record->comment;
            $comment->timecreated = $record->timecreated;
            $comment->readabletime = date('g:ia, j M', $record->timecreated);
            $user = \core_user::get_user_by_username($record->username);
            $comment->userfullname = fullname($user);
            $comment->isauthor = ($comment->username == $USER->username);
            $comments[] = $comment;
        }

        return $comments;
    }

    /*
    * Send permissions
    */
    public static function add_activity_email($data) {
        global $USER, $DB, $CFG, $PAGE;

        // Get the activity.
        $activity = new activity($data->activityid);
        if (empty($activity)) {
            throw new \Exception("Activity not found.");
            exit;
        }

        $activity = $activity->export();

        // Make sure this user is one of the activity planneres.
        if (!$activity->usercanedit) {
            throw new \Exception("Permission denied.");
            exit;
        }

        // Get the activity students.
        $students = static::get_activities_students($data->activityid);
        $students = array_values(array_column($students, 'username'));
        $studentsjson = json_encode($students);
        if (isset($data->scope) && $data->scope) {
            $studentsjson = json_encode(array_column($data->scope, 'un'));
        }

        // Queue an email.
        $rec = new \stdClass();
        $rec->activityid = $data->activityid;
        $rec->username = $USER->username;
        $rec->studentsjson = $studentsjson;
        $rec->audiences = json_encode($data->audiences);
        $rec->extratext = $data->extratext;
        $rec->includes = json_encode($data->includes);
        $rec->timecreated = time();

        $emaildata = new \stdClass();
        $emaildata->activity = $activity;
        $emaildata->extratext = $data->extratext;
        $emaildata->includepermissions = $activity->permissions && in_array('permissions', $data->includes);
        $emaildata->includedetails = in_array('details', $data->includes);

        $output = $PAGE->get_renderer('core');
        $rec->rendered = $output->render_from_template('local_announcements2/history_email_html', $emaildata);

        $DB->insert_record(static::TABLE_ACTIVITY_EMAILS, $rec);
    }


    /*
    * Get emails for an activity.
    */
    public static function get_emails($activityid) {
        global $USER, $DB, $PAGE, $OUTPUT;

        if (!activity::exists($activityid)) {
            return 0;
        }
        if (!utils_lib::has_capability_edit_activity($activityid)) {
            throw new \Exception("Permission denied.");
            exit;
        }

        $sql = "SELECT *
                  FROM {" . static::TABLE_ACTIVITY_EMAILS . "}
                 WHERE activityid = ?
              ORDER BY timecreated DESC";
        $params = array($activityid);
        $emails = $DB->get_records_sql($sql, $params);
        foreach ($emails as $email) {
            $email->sender = utils_lib::user_stub($email->username);
            $email->audiences = json_decode($email->audiences);
            $email->students = json_decode($email->studentsjson);
            $email->students = array_map(function($un) {
                return utils_lib::user_stub($un);
            }, $email->students);
        }

        return array_values($emails);
    }


    /*
    * Emails the comment to all parties involved. Comments are sent to:
    * - Next approver in line
    * - Approvers that have already actioned approval
    * - Activity creator
    * - Comment poster
    * - Staff in charge
    */
    protected static function send_comment_emails($comment) {
        global $USER;

        $activity = new Activity($comment->activityid);
        $activity = $activity->export();

        $recipients = array();

        // Send the comment to the next approver in line.
        $approvals = workflow_lib::get_unactioned_approvals($comment->activityid);
        foreach ($approvals as $nextapproval) {
            $approvers = workflow_lib::WORKFLOW[$nextapproval->type]['approvers'];
            foreach($approvers as $approver) {
                // Skip if approver does not want this notification.
                if (isset($approver['notifications']) && !in_array('newcomment', $approver['notifications'])) {
                    continue;
                }
                // Check email contacts.
                if ($approver['contacts']) {
                    foreach ($approver['contacts'] as $email) {
                        static::send_comment_email($activity, $comment, $approver['username'], $email);
                        $recipients[] = $approver['username'];
                    }
                } else {
                    if ( ! in_array($approver['username'], $recipients)) {
                        static::send_comment_email($activity, $comment, $approver['username']);
                        $recipients[] = $approver['username'];
                    }
                }
            }
            // Break after sending to next approver in line. Comment is not sent to approvers down stream.
            break;
        }

        // Send comment to approvers that have already actioned an approval for this activity.
        $approvals = workflow_lib::get_approvals($comment->activityid);
        foreach ($approvals as $approval) {
            if ( ! in_array($approval->username, $recipients)) {

                // Skip if approver does not want this notification.
                $config = workflow_lib::WORKFLOW[$approval->type]['approvers'];
                if (isset($config[$approval->username]) && 
                    isset($config[$approval->username]['notifications']) && 
                    !in_array('newcomment', $config[$approval->username]['notifications'])) {
                        continue;
                }
            
                static::send_comment_email($activity, $comment, $approval->username);
                $recipients[] = $approval->username;
            }
        }

        // Send comment to activity creator.
        if ( ! in_array($activity->creator, $recipients)) {
            static::send_comment_email($activity, $comment, $activity->creator);
            $recipients[] = $activity->creator;
        }

        // Send comment to the comment poster if they are not one of the above.
        if ( ! in_array($USER->username, $recipients)) {
            static::send_comment_email($activity, $comment, $USER->username);
            $recipients[] = $USER->username;
        }

        // Send to staff in charge.
        if ( ! in_array($activity->staffincharge, $recipients)) {
            static::send_comment_email($activity, $comment, $activity->staffincharge);
            $recipients[] = $activity->staffincharge;
        }

    }

    protected static function send_comment_email($activity, $comment, $recipient, $email = null) {
        global $USER, $PAGE;

        $toUser = \core_user::get_user_by_username($recipient);
        if ($email) {
            // Override the email address.
            $toUser->email = $email;
        }

        $data = array(
            'user' => $USER,
            'activity' => $activity,
            'comment' => $comment,
        );

        $subject = "Comment re: " . $activity->activityname;
        $output = $PAGE->get_renderer('core');
        $messageHtml = $output->render_from_template('local_announcements2/email_comment_html', $data);
        
        $result = service_lib::email_to_user($toUser, $USER, $subject, '', $messageHtml, '', '', true);
    }

    /*
    * A "no" response means the student is not attending, even if another parent response "yes"
    */
    public static function get_all_attending($activityid) {
        global $USER, $DB;

        $attending = array();

        $activity = new Activity($activityid);
        if ($activity->get('permissions')) {
            $sql = "SELECT DISTINCT p.studentusername
                      FROM mdl_activities_permissions p
                INNER JOIN mdl_activities_students s ON p.studentusername = s.username
                INNER JOIN mdl_activities a on a.id = p.activityid
                     WHERE p.activityid = ?
                       AND p.response = 1
                       AND a.deleted = 0
                       AND p.studentusername NOT IN ( 
                           SELECT studentusername
                             FROM mdl_activities_permissions
                            WHERE activityid = ?
                              AND response = 2
                       )
                       AND a.status = " . static::ACTIVITY_STATUS_APPROVED;

            $params = array($activityid, $activityid);
            $attending = $DB->get_records_sql($sql, $params);
            $attending = array_values(array_column($attending, 'studentusername'));
        } else {
            $attending = static::get_activities_students($activityid, static::ACTIVITY_STATUS_APPROVED);
            $attending = array_values(array_column($attending, 'username'));
        }

        return $attending;
    }

    public static function get_parent_permissions($activityid, $parentusername) {
        global $DB;

        $sql = "SELECT DISTINCT p.*
                  FROM {" . static::TABLE_ACTIVITY_PERMISSIONS . "} p
            INNER JOIN {" . static::TABLE_ACTIVITY_STUDENTS . "} s ON p.studentusername = s.username
                 WHERE p.activityid = ?
                   AND p.parentusername = ?
              ORDER BY p.timecreated DESC";
        $params = array($activityid, $parentusername);
        $permissions = $DB->get_records_sql($sql, $params);

        // Do not include permissions for students that do not live with their parent.
        $parent = \core_user::get_user_by_username($parentusername);
        $mentees = utils_lib::get_user_mentees($parent->id, true);
        foreach ($permissions as $i => $permission) {
            if ( ! in_array($permission->studentusername, $mentees)) {
                unset($permissions[$i]);
            }
        }

        return $permissions;
    }


    public static function get_students_by_response($activityid, $response) {
        global $DB;

        $sql = "SELECT DISTINCT p.studentusername
                  FROM {" . static::TABLE_ACTIVITY_PERMISSIONS . "} p
            INNER JOIN {" . static::TABLE_ACTIVITY_STUDENTS . "} s 
                ON p.studentusername = s.username 
                AND p.activityid = s.activityid
                 WHERE p.activityid = ?
                   AND p.response = ?";
        $params = array($activityid, $response);
        $permissions = $DB->get_records_sql($sql, $params);

        return $permissions;
    }

    /*
    * Save permission
    */
    public static function submit_permission($permissionid, $response) {
        global $DB, $USER;

        $activityid = $DB->get_field(static::TABLE_ACTIVITY_PERMISSIONS, 'activityid', array('id' => $permissionid));
        $activity = new Activity($activityid);
        
        // Check if past permissions dueby or limit.
        $permissionshelper = static::permissions_helper($activity);
        if ($permissionshelper->activitystarted || $permissionshelper->ispastdueby || $permissionshelper->ispastlimit) {
            return;
        }

        // Update the permission response.
        $sql = "UPDATE {" . static::TABLE_ACTIVITY_PERMISSIONS . "}
                   SET response = ?, timeresponded = ?
                 WHERE id = ?
                   AND parentusername = ?";
        $params = array($response, time(), $permissionid, $USER->username);
        $DB->execute($sql, $params);

        // Reset absences processed as attendance may have changed due to permission given.
        $activity->set('absencesprocessed', 0);
        $activity->set('classrollprocessed', 0);
        $activity->update();

        // If it is a yes, sent an email to the student to tell them their parent indicated that they will be attending.
        if ($response === 1) {
            static::send_attending_email($permissionid);
        }

        return $response;
    }


    public static function send_attending_email($permissionid) {
        global $DB, $OUTPUT;

        // Get the permission.
        $permission = $DB->get_record(static::TABLE_ACTIVITY_PERMISSIONS, array('id' => $permissionid));
        if (empty($permission)) {
            return;
        }

        // Get the email users.
        $toUser = \core_user::get_user_by_username($permission->studentusername);
        $fromUser = \core_user::get_noreply_user();
        $fromUser->bccaddress = array("lms.archive@cgs.act.edu.au"); 

        // Get the activity for the permission.
        $activity = new Activity($permission->activityid);
        if (empty($activity)) {
            return;
        }
        $activity = $activity->export();

        // Add additional data for template.
        $parentuser = \core_user::get_user_by_username($permission->parentusername);
        $activity->parentname = fullname($parentuser);
        $activity->studentname = fullname($toUser);


        $messageHtml = $OUTPUT->render_from_template('local_announcements2/email_attending_html', $activity);
        $subject = "Attending activity: " . $activity->activityname;

        $result = service_lib::wrap_and_email_to_user($toUser, $fromUser, $subject, $messageHtml);        
    }



    public static function soft_delete($activityid) {
        global $DB;

        if (!activity::exists($activityid)) {
            return;
        }
        if (!utils_lib::has_capability_edit_activity($activityid)) {
            throw new \Exception("Permission denied.");
            exit;
        }

        $originalactivity = new Activity($activityid);
        $activity = new Activity($activityid);
        $activity->soft_delete();

       return 1;
    }

    
    public static function status_helper($status) {
        $statushelper = new \stdClass();
        $statushelper->status = $status;
        $statushelper->isautosave = ($status == static::ACTIVITY_STATUS_AUTOSAVE);
        $statushelper->isdraft = ($status == static::ACTIVITY_STATUS_DRAFT);
        $statushelper->isdraftorautosave = ($status == static::ACTIVITY_STATUS_DRAFT || $status == static::ACTIVITY_STATUS_AUTOSAVE);
        $statushelper->inreview = ($status == static::ACTIVITY_STATUS_INREVIEW);
        $statushelper->isapproved = ($status == static::ACTIVITY_STATUS_APPROVED);
        $statushelper->iscancelled = ($status == static::ACTIVITY_STATUS_CANCELLED);
        $statushelper->cansavedraft = $statushelper->isautosave || $statushelper->isdraft || $statushelper->iscancelled;

        switch ($status) {
            case static::ACTIVITY_STATUS_AUTOSAVE:
                $statushelper->statusname = 'Autosave';
                break;
            case static::ACTIVITY_STATUS_DRAFT:
                $statushelper->statusname = 'Draft';
                break;
            case static::ACTIVITY_STATUS_INREVIEW:
                $statushelper->statusname = 'In Review';
                break;
            case static::ACTIVITY_STATUS_APPROVED:
                $statushelper->statusname = 'Approved';
                break;
            case static::ACTIVITY_STATUS_CANCELLED:
                $statushelper->statusname = 'Cancelled';
                break;
            default:
                $statushelper->statusname = 'Unknown';
                break;
        }
        
   
        return $statushelper;
    }

    public static function permissions_helper($activity) {
        global $DB;

        $dueby = $activity->get('permissionsdueby');
        $limit = $activity->get('permissionslimit');

        $permissionshelper = new \stdClass();
        $permissionshelper->ispastdueby = false;
        if ($dueby) {
            $permissionshelper->ispastdueby = (time() >= $dueby);
        }
        
        // Get number of approved permissions.
        $permissionshelper->ispastlimit = false;
        if ($limit > 0) {
            $countyes = count(static::get_students_by_response($activity->get('id'), 1));
            $permissionshelper->ispastlimit = ($countyes >= $limit);
        }

        // Check if activity is started.
        $permissionshelper->activitystarted = false;
        if ($activity->get('recurring') == 0) {
            if (time() >= $activity->get('timestart')) {
                $permissionshelper->activitystarted = true;
            }
        } else {
            // Need to check if any of the occurrences are still in the future.
            $sql = "SELECT id
                    FROM mdl_activities_occurrences
                    WHERE activityid = ?
                    AND (timestart >= ? OR timeend > ?)";
            $params = array($activity->get('id'), time(), time());
            $occurrences = $DB->get_records_sql($sql, $params);
            if (count($occurrences) == 0) {
                $permissionshelper->activitystarted = true;
            }
        }

        return $permissionshelper;
    }














    private static function generate_files_changekey($filearea, $activityid) {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_announcements2', $filearea, $activityid, "filename", false);
        $changekey = '';
        foreach ($files as $file) {
            $changekey .= $file->get_contenthash();
        }
        return sha1($changekey);
    }

    private static function process_files($files, $filearea, $activityid) {
        if (empty($files)) {
            return [];
        }
        $add = array();
        $delete = array();
        foreach($files as $instruct) {
            $instruct = explode("::", $instruct);
            if (count($instruct) < 2) {
                continue;
            }
            switch ($instruct[0]) {
                case "NEW":
                    $add[] = $instruct[1];
                    break;
                case "REMOVED":
                    $delete[] = $instruct[1];
                    break;
            }
        }

        static::delete_files($delete);
        static::store_files($add, $filearea, $activityid);
    }

    private static function delete_files($fileids) {
        if (empty($fileids)) {
            return [];
        }

        $fs = get_file_storage();
        foreach($fileids as $fileid) {
            $file = $fs->get_file_by_id($fileid);
            if ($file) {
                $file->delete();
            }
        }
    }

    private static function store_files($filenames, $filearea, $activityid) {
        global $USER, $CFG, $DB;

        if (empty($filenames)) {
            return [];
        }

        $success = array();
        $error = array();
        $dataroot = str_replace('\\\\', '/', $CFG->dataroot);
        $dataroot = str_replace('\\', '/', $dataroot);
        $tempdir = $dataroot . '/temp/local_announcements2/';

        
        $fs = get_file_storage();
        $fsfd = new \file_system_filedir();
        //$fs = new \file_storage();

        // Store temp files to a permanent file area.
        foreach($filenames as $filename) {
            if ( ! file_exists($tempdir . $filename)) {
                $error[$filename] = 'File not found';
                continue;
            }
            try {
                // Start a new file record.
                $newrecord = new \stdClass();
                // Move the temp file into moodledata.
                list($newrecord->contenthash, $newrecord->filesize, $newfile) = $fsfd->add_file_from_path($tempdir . $filename);
                
                // Remove the temp file.
                unlink($tempdir . $filename);

                // Clean filename.
                $cleanfilename = preg_replace("/^(\d+)\.(\d+)\./", '', $filename);            

                // Complete the record.
                $newrecord->contextid = 1;
                $newrecord->component = 'local_announcements2';
                $newrecord->filearea  = $filearea;
                $newrecord->itemid    = $activityid;
                $newrecord->filepath  = '/';
                $newrecord->filename  = $filename;
                $newrecord->timecreated  = time();
                $newrecord->timemodified = time();
                $newrecord->userid      = $USER->id;
                $newrecord->source      = $filename;
                $newrecord->author      = fullname($USER);
                $newrecord->license     = $CFG->sitedefaultlicense;
                $newrecord->status      = 0;
                $newrecord->sortorder   = 0;
                $newrecord->mimetype    = $fs->get_file_system()->mimetype_from_hash($newrecord->contenthash, $newrecord->filename);
                $newrecord->pathnamehash = $fs->get_pathname_hash($newrecord->contextid, $newrecord->component, $newrecord->filearea, $newrecord->itemid, $newrecord->filepath, $newrecord->filename);
                $newrecord->id = $DB->insert_record('files', $newrecord);
                $success[$filename] = $newrecord->id;
            } catch (Exception $ex) {
                $error[$filename] = $ex->getMessage();
            }
        }

        return [$success, $error];
    }



    public static function can_user_view_activity($activityid) {
        global $DB, $USER;

        if (utils_lib::is_user_staff()) {
            // User a staff - If activity status is in review or approved, they can view.
            $sql = "SELECT id
                    FROM  {" . static::TABLE . "} 
                    WHERE id = ?
                    AND deleted = 0
                    AND (status = " . static::ACTIVITY_STATUS_INREVIEW . ' OR status = ' . static::ACTIVITY_STATUS_APPROVED . ')';        
            return $DB->record_exists_sql($sql, [$activityid]);

        } else if (utils_lib::is_user_parent()) {
            // User is a parent - Check if they have a child in the activity.
            $sql = "SELECT activityid
                    FROM {" . static::TABLE . "} a 
                    INNER JOIN {" . static::TABLE_ACTIVITY_PERMISSIONS . "} ap ON a.id = ap.activityid
                    WHERE a.id = ?
                    AND ap.parentusername = ?
                    AND (a.status = " . static::ACTIVITY_STATUS_APPROVED . ")";
            return $DB->record_exists_sql($sql, [$activityid, $USER->username]);

        }

        return false;
    }
   

    public static function duplicate_activity($activityid, $options) {
        global $DB;

        $activity = new Activity($activityid);
        $activity->load_studentsdata();
        $data = $activity->export();
        $data->id = 0;
        $data->staffincharge = [$data->staffincharge];
        $data->riskassessment = '';
        $data->attachments = '';
        $data->planningstaff = json_decode($data->planningstaffjson);
        $data->accompanyingstaff = json_decode($data->accompanyingstaffjson);
        $data->studentlist = [];
        if ($options['studentlist']) {
            $data->studentlist = json_decode($activity->get('studentsdata'));
        }
        $data->recurringAcceptChanges = true;

        $result = (object) static::save_from_data($data);

        // Copy files...
        $fs = get_file_storage();

        if ($options['riskassessment'] && $files = $fs->get_area_files(1, 'local_announcements2', 'riskassessment', $activityid, "filename", true)) {
            foreach ($files as $file) {
                $newrecord = new \stdClass();
                $newrecord->itemid = $result->id;
                $fs->create_file_from_storedfile($newrecord, $file);
            }
        }

        if ($options['riskassessment'] && $files = $fs->get_area_files(1, 'local_excursions', 'ra', $data->oldexcursionid, "filename", true)) {
            foreach ($files as $file) {
                $newrecord = new \stdClass();
                $newrecord->itemid = $result->id;
                $newrecord->component = 'local_announcements2';
                $newrecord->filearea = 'riskassessment';
                $fs->create_file_from_storedfile($newrecord, $file);
            }
        }

        if ($options['attachments'] && $files = $fs->get_area_files(1, 'local_announcements2', 'attachments', $activityid, "filename", true)) {
            foreach ($files as $file) {
                $newrecord = new \stdClass();
                $newrecord->itemid = $result->id;
                $fs->create_file_from_storedfile($newrecord, $file);
            }
        }

        if ($options['attachments'] && $files = $fs->get_area_files(1, 'local_excursions', 'attachments', $data->oldexcursionid, "filename", true)) {
            foreach ($files as $file) {
                $newrecord = new \stdClass();
                $newrecord->itemid = $result->id;
                $newrecord->component = 'local_announcements2';
                $fs->create_file_from_storedfile($newrecord, $file);
            }
        }

        return $result->id;
    }



    


    public static function diff_versions_html($a1, $a2) {
        global $OUTPUT;
        
        $a1 = new Activity($a1);
        $a1 = $a1->export();
        $a2 = new Activity($a2);
        $a2 = $a2->export();

        $fromhtml = $OUTPUT->render_from_template('local_announcements2/email_activity_details', ['activity' => $a1]);
        $tohtml = $OUTPUT->render_from_template('local_announcements2/email_activity_details', ['activity' => $a2]);

        $diff = new \FineDiff\Diff();
        return $diff->render($fromhtml, $tohtml);
    }


    public static function get_acknowledgers($activityid) {
        global $DB;

        $sql = "SELECT username
                FROM {" . static::TABLE_ACTIVITY_ACKNOWLEDGEMENTS . "}
                WHERE activityid = ?
                AND acknowledge = 1";
        $params = array($activityid);
        $staff = $DB->get_records_sql($sql, $params);
        $staff = array_map(function($item) {
            return utils_lib::user_stub($item->username);
        }, $staff);
        return array_values($staff);
    }

    public static function has_user_acknowledged($activityid) {
        global $DB, $USER;

        $sql = "SELECT id
                FROM {" . static::TABLE_ACTIVITY_ACKNOWLEDGEMENTS . "}
                WHERE activityid = ?
                AND username = ?
                AND acknowledge = 1";
        $params = array($activityid, $USER->username);
        return $DB->record_exists_sql($sql, $params);
    }


    public static function acknowledge_activity($activityid, $acknowledge) {
        global $DB, $USER;
        $activity = new Activity($activityid);
        $activity = $activity->export();

        if (!$activity->isacknowledger) {
            return false;
        }

        // Delete existing
        $DB->delete_records(static::TABLE_ACTIVITY_ACKNOWLEDGEMENTS, [
            'activityid' => $activityid,
            'username' => $USER->username,
        ]);

        $DB->insert_record(static::TABLE_ACTIVITY_ACKNOWLEDGEMENTS, [
            'activityid' => $activityid,
            'username' => $USER->username,
            'acknowledge' => $acknowledge,
        ]);

        return $acknowledge;
    }

}