<?php

/**
 * A scheduled task for creating classes for rollmarking.
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

class cron_create_classes_old extends \core\task\scheduled_task {

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
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_create_classes_old', 'local_announcements2');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB, $CFG;

        // Find activities that need roll marking.
        $now = time();
        $plusdays = strtotime('+7 day', $now);
        $readablenow= date('Y-m-d H:i:s', $now);
        $readableplusdays= date('Y-m-d H:i:s', $plusdays);
        $this->log_start("Fetching approved activities within the next week (between {$readablenow} and {$readableplusdays}).");



        // Get activities that need roll marking.
        $activities = activities_lib::get_for_roll_creation($now, $plusdays);
        $activities = array_map(function($activity) {
            return $activity->export();
        }, $activities);



        // Get assessments that need roll marking.
        $rawassessments = assessments_lib::get_for_roll_creation($now, $plusdays);
        $assessments = [];
        foreach ($rawassessments as $assessment) {
            $assessments[] = (object) [
                'id' => $assessment->id,
                'activityname' => $assessment->name,
                'timestart' => $assessment->timestart,
                'timeend' => $assessment->timeend,
                'campus' => 'senior',
                'staffincharge' => $assessment->staffinchargejson ? $assessment->staffincharge : $assessment->creator,
            ];
        }


        try {

            $config = get_config('local_announcements2');
            if (empty($config->dbhost ?? '') || empty($config->dbuser ?? '') || empty($config->dbpass ?? '') || empty($config->dbname ?? '')) {
                $this->log("No config found for local_announcements2");
                return;
            }
            $this->externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            $this->externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');

            // Get term info.
            $currentterminfo = $this->externalDB->get_records_sql($config->getterminfosql);
            $this->currentterminfo =  array_pop($currentterminfo);

            if ($CFG->wwwroot != 'https://connect.cgs.act.edu.au') {
                $this->prefix = 'XUAT_';
            }

            // Create class rolls for activities.
            foreach ($activities as $activity) {
                $attending = activities_lib::get_all_attending($activity->id);
                if (empty($attending)) {
                    $this->log("Skipping class roll for activity because it has no students in it: " . $activity->id);
                    continue;
                }

                $success = $this->create_class_roll($activity, $attending);

                // Mark as processed.
                if ($success) {
                    $DB->execute("UPDATE {activities} SET classrollprocessed = 1 WHERE id = $activity->id");
                }
            }


            // Create class rolls for assessments.
            foreach ($assessments as $assessment) {
                $attending = assessments_lib::get_assessment_students($assessment->id);
                $attending = array_values(array_column($attending, 'un'));

                if (empty($attending)) {
                    $this->log("Skipping class roll for assessment because it has no students in it: " . $assessment->id);
                    continue;
                }

                $success = $this->create_class_roll($assessment, $attending);

                // Mark as processed.
                if ($success) {
                    $DB->execute("UPDATE {activities_assessments} SET classrollprocessed = 1 WHERE id = $assessment->id");
                }
            }

            // Process deleted activities.
            $this->process_deleted_activities();

        } catch (Exception $ex) {
            // Error.
        }

        $this->log_finish("Finished creating class roll");

    }


    private function create_class_roll($activity, $attending) {
        global $DB;

        $config = get_config('local_announcements2');
        $this->log("Creating class roll for activity " . $activity->id);
        $activitystart = date('Y-m-d H:i', $activity->timestart);
        $activityend = date('Y-m-d H:i', $activity->timeend);

        // If this activity is multiple days, break it into days and create a class for each day so that roll marking works.
        // Convert start and end times to DateTime objects
        $startDateTime = new \DateTime($activitystart);
        $endDateTime = new \DateTime($activityend);

        // Create the result array
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
                if ($currentDate == $startDateTime) {
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

        // For each day of this event, create a class.
        foreach ($result as $day) {
            $activitystart = $day['start'];
            $activityend = $day['end'];
            // Convert start time to DateTime object
            $startDateTime = new \DateTime($activitystart);
            // Format the month and day as MMDD
            $monthDay = $startDateTime->format('md');
            $classcode = $this->prefix . $activity->id . '_' . $monthDay;

            // 1. Create the class.
            $this->log("Creating the class " . $classcode . ", with staff in charge " .  $activity->staffincharge . ", start time " .  $activitystart, 2 );
            $sql = $config->createclasssql . ' :fileyear, :filesemester, :classcampus, :classcode, :description, :staffid, :leavingdate, :returningdate';
            
            // Keep within schedule limits.
            $activitystarthour = date('H', $activity->timestart);
            if ($activitystarthour < 6) {
                $activitystart = date('Y-m-d 06:i', $activity->timestart);
            }
            if ($activitystarthour > 18 ) {
                $activitystart = date('Y-m-d 18:i', $activity->timestart);
            }

            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
                'description' => $activity->activityname,
                'staffid' => $activity->staffincharge,
                'leavingdate' => $activitystart,
                'returningdate' => $activityend,
            );
            //var_export([$sql, $params]); exit;
            $seqnums = $this->externalDB->get_records_sql($sql, $params); // Returns staffscheduleseq, subjectclassesseq.
            //var_export($seqnums); exit;
            $seqnums =  array_pop($seqnums);
            $this->log("The sequence nums (staffscheduleseq, subjectclassesseq): " . json_encode($seqnums), 2);

            if (empty($seqnums) || empty($seqnums->staffscheduleseq) || empty($seqnums->subjectclassesseq)) {
                $this->log("No sequence nums found for activity " . $activity->id . ", skipping class creation.", 2);
                return false;
            }

            // 2. Insert the extra staff.
            $extrastaff = $DB->get_records('activities_staff', array('activityid' => $activity->id));
            foreach ($extrastaff as $e) {
                $this->log("Inserting extra class teacher: " . $e->username, 2);
                $sql = $config->insertclassstaffsql . ' :fileyear, :filesemester, :classcampus, :classcode, :staffid';
                $params = array(
                    'fileyear' => $this->currentterminfo->fileyear,
                    'filesemester' => $this->currentterminfo->filesemester,
                    'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                    'classcode' => $classcode,
                    'staffid' => $e->username,
                );
                $this->externalDB->execute($sql, $params);
            }

            // 3. Insert the attending students.
            foreach ($attending as $student) {
                $this->log("Inserting class student: {$student}.", 2);
                $sql = $config->insertclassstudentsql . ' :staffscheduleseq, :fileyear, :filesemester, :classcampus, :classcode, :studentid, :subjectclassesseq';
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
            $sql = $config->deleteclassstudentssql . ' :fileyear, :filesemester, :classcampus, :classcode, :studentscsv';
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $activity->campus == 'primary' ? 'PRI' : 'SEN',
                'classcode' => $classcode,
                'studentscsv' => implode(',', $attending),
            );
            $this->externalDB->execute($sql, $params);
        }
        $this->log("Finished creating class roll for activity " . $activity->id);
        return true;
    }


    private function process_deleted_activities() {
        global $DB;

        $config = get_config('local_announcements2');

        // Look for activities that have been deleted in the past 24 hours and remove the class roll.
        $activities = $DB->get_records_sql('SELECT * FROM {activities} WHERE deleted = 1 AND timemodified > ?', array(time() - 86400));
        foreach ($activities as $activity) {
            $this->log("Processing deleted activity: " . $activity->id);
            $activity = $activity->export();
            $startDateTime = new \DateTime($activity->timestart);
            $monthDay = $startDateTime->format('md');
            $classcode = $this->prefix . $activity->id . '_' . $monthDay;
            $sql = $config->deleteclassstudentssql . ' :fileyear, :filesemester, :classcampus, :classcode, :studentscsv';
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $activity->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
                'studentscsv' => '', // Empty to remove all students from the class.
            );
            $this->externalDB->execute($sql, $params);
        }
        
        // Look for assessments that have been deleted in the past 24 hours and remove the class roll.
        $rawassessments = $DB->get_records_sql('SELECT * FROM {activities_assessments} WHERE deleted = 1 AND timemodified > ?', array(time() - 86400));
        foreach ($rawassessments as $assessment) {
            $assessment = (object) [
                'id' => $assessment->id,
                'activityname' => $assessment->name,
                'timestart' => $assessment->timestart,
                'timeend' => $assessment->timeend,
                'campus' => 'senior',
            ];
            $startDateTime = new \DateTime($assessment->timestart);
            $monthDay = $startDateTime->format('md');
            $classcode = $this->prefix . $assessment->id . '_' . $monthDay;
            $sql = $config->deleteclassstudentssql . ' :fileyear, :filesemester, :classcampus, :classcode, :studentscsv';
            $params = array(
                'fileyear' => $this->currentterminfo->fileyear,
                'filesemester' => $this->currentterminfo->filesemester,
                'classcampus' => $assessment->campus == 'senior' ? 'SEN' : 'PRI',
                'classcode' => $classcode,
                'studentscsv' => '', // Empty to remove all students from the class.
            );
            $this->externalDB->execute($sql, $params);
        }




    }

}