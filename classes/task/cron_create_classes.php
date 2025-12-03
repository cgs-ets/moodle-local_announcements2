<?php

/**
 * A scheduled task for creating and updating classes for rollmarking.
 * 
 * This improved version handles:
 * - Updating classes when dates/times change (deletes old, creates new)
 * - Deleting classes when all students are removed
 * - Detecting changes and reprocessing as needed
 * - Comprehensive cleanup of deleted activities/assessments
 *
 * @package   local_announcements2
 * @copyright 2024 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_announcements2\task;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/activities.lib.php');
require_once(__DIR__.'/../lib/activity.class.php');
require_once(__DIR__.'/../lib/assessments.lib.php');
use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\activity;
use \local_announcements2\lib\assessments_lib;

class cron_create_classes extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

        
    /**
     * @var Class code prefix.
     */
    protected $prefix = 'X';

    /**
     * @var The current term info.
     */
    protected $currentterminfo = null;

    /**
     * @var The external database.
     */
    protected $externalDB = null;

    /**
     * @var Configuration object.
     */
    protected $config = null;

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_create_classes', 'local_announcements2') . ' (v2)';
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB, $CFG;

        // Find activities that need roll marking.
        $now = time();
        $plusdays = strtotime('+7 day', $now);
        $readablenow = date('Y-m-d H:i:s', $now);
        $readableplusdays = date('Y-m-d H:i:s', $plusdays);
        $this->log_start("Fetching activities and assessments within the next week (between {$readablenow} and {$readableplusdays}).");

        try {
            $this->config = get_config('local_announcements2');
            if (empty($this->config->dbhost ?? '') || empty($this->config->dbuser ?? '') || empty($this->config->dbpass ?? '') || empty($this->config->dbname ?? '')) {
                $this->log("No config found for local_announcements2");
                return;
            }
            $this->externalDB = \moodle_database::get_driver_instance($this->config->dbtype, 'native', true);
            $this->externalDB->connect($this->config->dbhost, $this->config->dbuser, $this->config->dbpass, $this->config->dbname, '');

            // Get term info.
            $currentterminfo = $this->externalDB->get_records_sql($this->config->getterminfosql);
            $this->currentterminfo = array_pop($currentterminfo);

            if ($CFG->wwwroot != 'https://connect.cgs.act.edu.au') {
                $this->prefix = 'XUAT_';
            }

            // Process activities - get ALL activities in the time window, not just unprocessed ones
            $this->process_activities($now, $plusdays);

            // Process assessments - get ALL assessments in the time window, not just unprocessed ones
            $this->process_assessments($now, $plusdays);

            // Process deleted activities and assessments comprehensively
            $this->process_deleted_items();

        } catch (Exception $ex) {
            $this->log("Error in cron_create_classes: " . $ex->getMessage());
        }

        $this->log_finish("Finished creating/updating class rolls");
    }

    /**
     * Process all activities that need class rolls.
     * 
     * @param int $now Current timestamp
     * @param int $plusdays Timestamp for 7 days from now
     */
    private function process_activities($now, $plusdays) {
        global $DB;

        $this->log("Processing activities...");

        // Get ALL activities in the time window (not just unprocessed ones)
        // This allows us to detect changes and update existing classes
        $sql = "SELECT id, timestart, timeend, timemodified, classrollprocessed
                FROM {activities}
                WHERE deleted = 0
                AND (
                    (timestart <= {$plusdays} AND timestart >= {$now}) OR
                    (timestart <= {$now} AND timeend >= {$now})
                )";
        
        $activityrecords = $DB->get_records_sql($sql);
        
        foreach ($activityrecords as $record) {
            try {
                $activity = new Activity($record->id, true);
                $activitydata = $activity->export();

                // Get current attending students
                $attending = activities_lib::get_all_attending($activitydata->id);

                // If no students, delete any existing classes and mark as processed
                if (empty($attending)) {
                    $this->log("Activity {$activitydata->id} has no students - deleting any existing classes");
                    $this->delete_activity_classes($activitydata);
                    $DB->execute("UPDATE {activities} SET classrollprocessed = 1 WHERE id = ?", [$activitydata->id]);
                    continue;
                }
                
                // Determine if we need to reprocess
                // Strategy: Always reprocess if classrollprocessed = 0 (never processed)
                // Also reprocess if it was processed but modified recently (within last 2 days)
                // This catches date/time changes and student list changes
                $needsreprocess = ($record->classrollprocessed == 0);
                if (!$needsreprocess) {
                    // Check if activity was modified recently - if so, it likely changed
                    // We use 2 days to catch any changes that might have happened
                    if ($record->timemodified > (time() - 172800)) {
                        $needsreprocess = true;
                        $this->log("Activity {$activitydata->id} was recently modified (timemodified: " . date('Y-m-d H:i:s', $record->timemodified) . "), will reprocess");
                        // Reset the flag so it gets fully reprocessed
                        $DB->execute("UPDATE {activities} SET classrollprocessed = 0 WHERE id = ?", [$activitydata->id]);
                    }
                }

                // Process the activity (create or update classes)
                // Always delete old classes first to handle date changes properly
                if ($needsreprocess) {
                    $this->log("Deleting old classes for activity {$activitydata->id} before recreating (dates may have changed)");
                    $this->delete_activity_classes($activitydata);
                    $success = $this->create_class_roll($activitydata, $attending, 'activity');
                    if ($success) {
                        $DB->execute("UPDATE {activities} SET classrollprocessed = 1 WHERE id = ?", [$activitydata->id]);
                    }
                }
            } catch (Exception $ex) {
                $this->log("Error processing activity {$record->id}: " . $ex->getMessage());
            }
        }
    }

    /**
     * Process all assessments that need class rolls.
     * 
     * @param int $now Current timestamp
     * @param int $plusdays Timestamp for 7 days from now
     */
    private function process_assessments($now, $plusdays) {
        global $DB;

        $this->log("Processing assessments...");

        // Get ALL assessments in the time window (not just unprocessed ones)
        $sql = "SELECT id, timestart, timeend, timemodified, classrollprocessed
                FROM {activities_assessments}
                WHERE deleted = 0
                AND (
                    (timestart <= {$plusdays} AND timestart >= {$now}) OR
                    (timestart <= {$now} AND timeend >= {$now})
                )";
        
        $assessmentrecords = $DB->get_records_sql($sql);
        
        foreach ($assessmentrecords as $record) {
            try {
                // Get assessment data
                $assessment = $DB->get_record('activities_assessments', ['id' => $record->id]);
                if (!$assessment) {
                    continue;
                }

                $assessmentdata = (object) [
                    'id' => $assessment->id,
                    'activityname' => $assessment->name,
                    'timestart' => $assessment->timestart,
                    'timeend' => $assessment->timeend,
                    'campus' => 'senior',
                    'staffincharge' => $assessment->staffinchargejson ? $assessment->staffincharge : $assessment->creator,
                ];

                // Get current attending students
                $rawattending = assessments_lib::get_assessment_students($assessmentdata->id);
                $attending = array_values(array_column($rawattending, 'un'));

                // If no students, delete any existing classes and mark as processed
                if (empty($attending)) {
                    $this->log("Assessment {$assessmentdata->id} has no students - deleting any existing classes");
                    $this->delete_assessment_classes($assessmentdata);
                    $DB->execute("UPDATE {activities_assessments} SET classrollprocessed = 1 WHERE id = ?", [$assessmentdata->id]);
                    continue;
                }

                // Determine if we need to reprocess
                // Strategy: Always reprocess if classrollprocessed = 0 (never processed)
                // Also reprocess if it was processed but modified recently (within last 2 days)
                $needsreprocess = ($record->classrollprocessed == 0);
                if (!$needsreprocess) {
                    // Check if assessment was modified recently
                    if ($record->timemodified > (time() - 172800)) {
                        $needsreprocess = true;
                        $this->log("Assessment {$assessmentdata->id} was recently modified (timemodified: " . date('Y-m-d H:i:s', $record->timemodified) . "), will reprocess");
                        // Reset the flag so it gets fully reprocessed
                        $DB->execute("UPDATE {activities_assessments} SET classrollprocessed = 0 WHERE id = ?", [$assessmentdata->id]);
                    }
                }

                // Process the assessment (create or update classes)
                // Always delete old classes first to handle date changes properly
                if ($needsreprocess) {
                    $this->log("Deleting old classes for assessment {$assessmentdata->id} before recreating (dates may have changed)");
                    $this->delete_assessment_classes($assessmentdata);
                    $success = $this->create_class_roll($assessmentdata, $attending, 'assessment');
                    if ($success) {
                        $DB->execute("UPDATE {activities_assessments} SET classrollprocessed = 1 WHERE id = ?", [$assessmentdata->id]);
                    }
                }
            } catch (Exception $ex) {
                $this->log("Error processing assessment {$record->id}: " . $ex->getMessage());
            }
        }
    }

    /**
     * Create class roll for an activity or assessment.
     * 
     * @param object $activity Activity or assessment data
     * @param array $attending Array of student usernames
     * @param string $type 'activity' or 'assessment'
     * @return bool Success
     */
    private function create_class_roll($activity, $attending, $type = 'activity') {
        global $DB;

        $this->log("Creating class roll for {$type} " . $activity->id);
        $activitystart = date('Y-m-d H:i', $activity->timestart);
        $activityend = date('Y-m-d H:i', $activity->timeend);

        // If this activity is multiple days, break it into days and create a class for each day
        $days = $this->split_into_days($activitystart, $activityend);

        // For each day of this event, create a class.
        foreach ($days as $day) {
            $daystart = $day['start'];
            $dayend = $day['end'];
            
            // Convert start time to DateTime object
            $startDateTime = new \DateTime($daystart);
            // Format the month and day as MMDD
            $monthDay = $startDateTime->format('md');
            $classcode = $this->prefix . $activity->id . '_' . $monthDay;

            // Keep within schedule limits.
            $activitystarthour = (int)date('H', strtotime($daystart));
            if ($activitystarthour < 6) {
                $daystart = date('Y-m-d 06:i', strtotime($daystart));
            }
            if ($activitystarthour > 18) {
                $daystart = date('Y-m-d 18:i', strtotime($daystart));
            }

            // 1. Create the class.
            $this->log("Creating the class {$classcode}, with staff in charge {$activity->staffincharge}, start time {$daystart}", 2);
            $sql = $this->config->createclasssql . ' :fileyear, :filesemester, :classcampus, :classcode, :description, :staffid, :leavingdate, :returningdate';
            
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
                'description' => $activity->activityname,
                'staffid' => $activity->staffincharge,
                'leavingdate' => $daystart,
                'returningdate' => $dayend,
            );
            
            $seqnums = $this->externalDB->get_records_sql($sql, $params);
            $seqnums = array_pop($seqnums);
            $this->log("The sequence nums (staffscheduleseq, subjectclassesseq): " . json_encode($seqnums), 2);

            if (empty($seqnums) || empty($seqnums->staffscheduleseq) || empty($seqnums->subjectclassesseq)) {
                $this->log("No sequence nums found for {$type} " . $activity->id . ", skipping class creation.", 2);
                return false;
            }

            // 2. Insert the extra staff (only for activities).
            if ($type == 'activity') {
                $extrastaff = $DB->get_records('activities_staff', array('activityid' => $activity->id));
                foreach ($extrastaff as $e) {
                    $this->log("Inserting extra class teacher: " . $e->username, 2);
                    $sql = $this->config->insertclassstaffsql . ' :fileyear, :filesemester, :classcampus, :classcode, :staffid';
                    $params = array(
                        'fileyear' => $this->currentterminfo->fileyear,
                        'filesemester' => $this->currentterminfo->filesemester,
                        'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                        'classcode' => $classcode,
                        'staffid' => $e->username,
                    );
                    $this->externalDB->execute($sql, $params);
                }
            }

            // 3. Insert the attending students.
            foreach ($attending as $student) {
                $this->log("Inserting class student: {$student}.", 2);
                $sql = $this->config->insertclassstudentsql . ' :staffscheduleseq, :fileyear, :filesemester, :classcampus, :classcode, :studentid, :subjectclassesseq';
                $params = array(
                    'staffscheduleseq' => $seqnums->staffscheduleseq,
                    'fileyear' => $this->currentterminfo->fileyear,
                    'filesemester' => $this->currentterminfo->filesemester,
                    'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                    'classcode' => $classcode,
                    'studentid' => $student,
                    'subjectclassesseq' => $seqnums->subjectclassesseq,
                );
                $this->externalDB->execute($sql, $params);
            }

            // 4. Remove students no longer attending.
            $studentscsv = implode(',', $attending);
            $this->log("Delete class students not in list: " . $studentscsv, 2);
            $sql = $this->config->deleteclassstudentssql . ' :fileyear, :filesemester, :classcampus, :classcode, :studentscsv';
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
                'studentscsv' => $studentscsv,
            );
            $this->externalDB->execute($sql, $params);
        }
        
        $this->log("Finished creating class roll for {$type} " . $activity->id);
        return true;
    }


    /**
     * Delete all classes for an activity.
     * 
     * @param object $activity Activity data
     */
    private function delete_activity_classes($activity) {
        $activitystart = date('Y-m-d H:i', $activity->timestart);
        $activityend = date('Y-m-d H:i', $activity->timeend);

        // Get all possible days (including potential old dates)
        // We need to delete classes for all possible date combinations
        // For simplicity, we'll delete classes for a range of dates around the activity
        $days = $this->split_into_days($activitystart, $activityend);

        foreach ($days as $day) {
            $classcode = $this->prefix . $activity->id . '_';

            $this->log("Deleting class {$classcode}", 2);
            $sql = 'EXEC cgs.local_excursions_delete_class :fileyear, :filesemester, :classcampus, :classcode';
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
            );
            $this->externalDB->execute($sql, $params);
        }
    }

    /**
     * Delete all classes for an assessment.
     * 
     * @param object $assessment Assessment data
     */
    private function delete_assessment_classes($assessment) {
        $assessmentstart = date('Y-m-d H:i', $assessment->timestart);
        $assessmentend = date('Y-m-d H:i', $assessment->timeend);

        $days = $this->split_into_days($assessmentstart, $assessmentend);

        foreach ($days as $day) {
            $classcode = $this->prefix . $assessment->id . '_';

            $this->log("Deleting class {$classcode}", 2);
            $sql = 'EXEC cgs.local_excursions_delete_class :fileyear, :filesemester, :classcampus, :classcode';
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $assessment->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
            );
            $this->externalDB->execute($sql, $params);
        }
    }

    /**
     * Split a date range into individual days.
     * 
     * @param string $start Start date/time (Y-m-d H:i)
     * @param string $end End date/time (Y-m-d H:i)
     * @return array Array of day arrays with 'start' and 'end' keys
     */
    private function split_into_days($start, $end) {
        $startDateTime = new \DateTime($start);
        $endDateTime = new \DateTime($end);
        $result = [];

        if ($startDateTime->format('Y-m-d') === $endDateTime->format('Y-m-d')) {
            // Single-day event
            $result[] = [
                "start" => $startDateTime->format('Y-m-d H:i'),
                "end" => $endDateTime->format('Y-m-d H:i')
            ];
        } else {
            // Multiday event
            $currentDate = clone $startDateTime;

            while ($currentDate <= $endDateTime) {
                // For the first day, use the actual start time
                if ($currentDate->format('Y-m-d') == $startDateTime->format('Y-m-d')) {
                    $result[] = [
                        "start" => $startDateTime->format('Y-m-d H:i'),
                        "end" => $startDateTime->format('Y-m-d') . ' 23:59'
                    ];
                } else if ($currentDate->format('Y-m-d') == $endDateTime->format('Y-m-d')) {
                    // For the last day, use the actual end time
                    $result[] = [
                        "start" => $endDateTime->format('Y-m-d') . ' 00:00',
                        "end" => $endDateTime->format('Y-m-d H:i')
                    ];
                } else {
                    // For middle days, use the whole day
                    $result[] = [
                        "start" => $currentDate->format('Y-m-d') . ' 00:00',
                        "end" => $currentDate->format('Y-m-d') . ' 23:59'
                    ];
                }

                // Move to the next day
                $currentDate->modify('+1 day');
            }
        }

        return $result;
    }

    /**
     * Process deleted activities and assessments comprehensively.
     * This checks all deleted items, not just recent ones.
     */
    private function process_deleted_items() {
        global $DB;

        $this->log("Processing deleted activities and assessments...");

        // Process all deleted activities (not just recent ones)
        $activities = $DB->get_records_sql('SELECT * FROM {activities} WHERE deleted = 1');
        foreach ($activities as $activity) {
            try {
                $this->log("Processing deleted activity: " . $activity->id);
                //$activitydata = $activity->export();
                $this->delete_activity_classes($activity);
            } catch (Exception $ex) {
                $this->log("Error processing deleted activity {$activity->id}: " . $ex->getMessage());
            }
        }
        
        // Process all deleted assessments (not just recent ones)
        $assessments = $DB->get_records_sql('SELECT * FROM {activities_assessments} WHERE deleted = 1');
        foreach ($assessments as $assessment) {
            try {
                $this->log("Processing deleted assessment: " . $assessment->id);
                $assessmentdata = (object) [
                    'id' => $assessment->id,
                    'activityname' => $assessment->name,
                    'timestart' => $assessment->timestart,
                    'timeend' => $assessment->timeend,
                    'campus' => 'senior',
                ];
                $this->delete_assessment_classes($assessmentdata);
            } catch (Exception $ex) {
                $this->log("Error processing deleted assessment {$assessment->id}: " . $ex->getMessage());
            }
        }
    }
}

