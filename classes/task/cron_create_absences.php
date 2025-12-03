<?php

/**
 * Cron task to create absences in Synergetic.
 *
 * @package   local_announcements2
 * @copyright 2024 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_announcements2\task;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/activities.lib.php');
require_once(__DIR__.'/../lib/activity.class.php');
use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\activity;


class cron_create_absences extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    
    /**
     * @var The current term info.
     */
    protected $appendix = '#ID-';

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('cron_create_absences', 'local_announcements2');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB, $CFG;
        // Find activities that need to be synced.
        $now = time();
        $plus14days = strtotime('+14 day', $now);
        $minus7days = strtotime('-7 day', $now);
        $readableplus14days= date('Y-m-d H:i:s', $plus14days);
        $readableminus7days = date('Y-m-d H:i:s', $minus7days);
        // Look ahead 2 weeks to find activities starting, look back 1 week to find activities ended
        $this->log_start("Fetching approved activities starting before {$readableplus14days} and finishing after {$readableminus7days}.");
        $activities = activities_lib::get_for_absences($now, $plus14days, $minus7days);
        try {

            $config = get_config('local_announcements2');
            if (empty($config->dbhost ?? '') || empty($config->dbuser ?? '') || empty($config->dbpass ?? '') || empty($config->dbname ?? '')) {
                return;
            }
            $externalDB = \moodle_database::get_driver_instance($config->dbtype, 'native', true);
            $externalDB->connect($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, '');

            if ($CFG->wwwroot != 'https://connect.cgs.act.edu.au') {
                $this->appendix = '#ID-UAT-';
            }

            foreach ($activities as $activity) {
                $this->log("Creating absences for activity " . $activity->get('id'));
                $activitystart = date('Y-m-d H:i', $activity->get('timestart'));
                $activityend = date('Y-m-d H:i', $activity->get('timeend'));

                // TODO: If activity time has changed since last time absences were synced we need to wipe all absences before starting the process below.
                // 1. Look for an absence record with this activity id.
                // 2. Compare the dates.
                // 3. If necessary, wipe all the absences.

                // Get list of attending students.
                $attending = activities_lib::get_all_attending($activity->get('id'));
                foreach ($attending as $student) {

                    // Sanity check whether absence already exists for student.
                    $sql = $config->checkabsencesql . ' :username, :leavingdate, :returningdate, :comment';
                    $params = array(
                        'username' => $student,
                        'leavingdate' => $activitystart,
                        'returningdate' => $activityend,
                        'comment' => $this->appendix . $activity->get('id'),
                    );
                    $absenceevents = $externalDB->get_field_sql($sql, $params);
                    if ($absenceevents) {
                        $this->log("Student is already absent during this time. Student: {$student}. Leaving date: {$activitystart}. Returning date: {$activityend}.", 2);
                        continue;
                    }

                    // Sanity check if created from old system.
                    if ($activity->get('oldexcursionid')) {
                        $params = array(
                            'username' => $student,
                            'leavingdate' => $activitystart,
                            'returningdate' => $activityend,
                            'comment' => $this->appendix . $activity->get('oldexcursionid'),
                        );
                        $absenceevents = $externalDB->get_field_sql($sql, $params);
                        if ($absenceevents) {
                            $this->log("Student is already absent during this time. Student: {$student}. Leaving date: {$activitystart}. Returning date: {$activityend}.", 2);
                            continue;
                        }
                    }

                    // Insert new absence.
                    $this->log("Creating absence. Student: {$student}. Leaving date: {$activitystart}. Returning date: {$activityend}.", 2);
                    $sql = $config->createabsencesql . ' :username, :leavingdate, :returningdate, :staffincharge, :comment';
                    $params = array(
                        'username' => $student,
                        'leavingdate' => $activitystart,
                        'returningdate' => $activityend,
                        'staffincharge' => $activity->get('staffincharge'),
                        'comment' => $activity->get('activityname') . ' ' . $this->appendix . $activity->get('id'),
                    );
                    $externalDB->execute($sql, $params);
                }

                // Delete absences for students no longer attending event.
                //if (empty($attending)) {
                //    $this->log("No students attending activity " . $activity->get('id') . ". Skipping deletion of absences just incase something is amiss.", 2);
                //} else {
                    $studentscsv = implode(',', $attending);
                    $this->log("Delete absences for students not in the following list: " . $studentscsv, 2);
                    $sql = $config->deleteabsencessql . ' :leavingdate, :returningdate, :comment, :studentscsv';
                    $params = array(
                        'leavingdate' => $activitystart,
                        'returningdate' => $activityend,
                        'comment' => $this->appendix . $activity->get('id'),
                        'studentscsv' => implode(',', $attending),
                    );
                    $externalDB->execute($sql, $params);
                //}
                
                
                // Mark as processed.
                // We can't mark recurring entries as processed. This will prevent future occurrences from being synced.
                // This will naturally create overhead, because absences will keep syncing for this activity throught the window.
                // We need to add an absences processed against the occurrence.
                // The other issue, if we save this for an occurrence, it ends up overwriting the timestart and timeend to the last occurrence dates, because they have been replaced out.
                if (!$activity->get('recurring')) {
                    $activity->set('absencesprocessed', 1);
                    $activity->save();
                }
                $this->log("Finished creating absences for activity " . $activity->get('id'));
            }


            // Loop through all the activities again searching for any orphaned occurrences in Synergetic…
            $checked_activities = array();
            foreach ($activities as $activity) {
                if (  in_array($activity->get('id'), $checked_activities)  ) {
                    continue;
                }
                $this->log("Checking for orphaned occurrences for activity " . $activity->get('id'));
                $checked_activities[] = $activity->get('id');
                $occurrences = $DB->get_records('activities_occurrences', array('activityid' => $activity->get('id')));

                if (empty($occurrences)) {
                    $this->log("No occurrences found for activity " . $activity->get('id'));
                    continue;
                }

                // Remove seconds (hangover from previous code that would add default seconds into timestamps)
                foreach ($occurrences as &$occurrence) {
                    $occurrence->timestart = intval($occurrence->timestart / 60) * 60;
                    $occurrence->timeend = intval($occurrence->timeend / 60) * 60;
                }

                // Create readable versions.
                $occurrencesreadable = array_map(function ($occurrence) {
                    return [
                        'timestart' => date('Y-m-d H:i', $occurrence->timestart),
                        'timeend' => date('Y-m-d H:i', $occurrence->timeend),
                    ];
                }, $occurrences);

                // Add in the activity start and end time as an occurrnece to make sure we don't miss any absences.
                $occurrences[] = (object) [
                    'timestart' => intval($activity->get('timestart') / 60) * 60,
                    'timeend' => intval($activity->get('timeend') / 60) * 60,
                ];
                $occurrencesreadable[] = [
                    'timestart' => date('Y-m-d H:i', $activity->get('timestart')),
                    'timeend' => date('Y-m-d H:i', $activity->get('timeend')),
                ];

                // Now find absences based upon the activity id.
                $sql = $config->findabsencessql . ' :comment';
                $params = array(
                    'comment' => $this->appendix . $activity->get('id'),
                );
                $absences = $externalDB->get_records_sql($sql, $params);
                //var_export($absences);

                $deleteOuts = array();
                $deleteIns = array();
                foreach ($absences as $absence) {
                    // Check if this absence is related to a real occurrence.
                    if ($absence->schoolinoutstatus == 'OUT') {
                        $start = strtotime($absence->eventdatetime);
                        if (!in_array($start, array_column($occurrences, 'timestart'))) {
                            $this->log($absence->id . " is marked OUT for this activity on: " . date('Y-m-d H:i', $start) . ", but this activty only starts on these dates: " . implode(', ', array_column($occurrencesreadable, 'timestart')));
                            $deleteOuts[] = $absence->eventdatetime;
                        }
                    } else if ($absence->schoolinoutstatus == 'IN') {
                        $end = strtotime($absence->eventdatetime);
                        if (!in_array($end, array_column($occurrences, 'timeend'))) {
                            $this->log($absence->id . " is marked IN for this activity on: " . date('Y-m-d H:i', $end) . ", but this activty only ends on these dates: " . implode(', ', array_column($occurrencesreadable, 'timeend')));
                            $deleteIns[] = $absence->eventdatetime;
                        }
                    }
                }

                $deleteOuts = array_unique($deleteOuts);
                $deleteIns = array_unique($deleteIns);

                if (count($deleteOuts) > 0) {
                    $this->log("Orphaned occurrences found for activity " . $activity->get('id') . ". Start times that don't exist: " . implode(', ', $deleteOuts));
                    // Delete orphaned occurrences.
                    $sql = $config->deleteorphanedsql . ' :inout, :dates, :comment';
                    $params = array(
                        'inout' => 'OUT',
                        'dates' => implode(',', $deleteOuts),
                        'comment' => $this->appendix . $activity->get('id'),
                    );
                    $externalDB->execute($sql, $params);
                }

                if (count($deleteIns) > 0) {
                    $this->log("Orphaned occurrences found for activity " . $activity->get('id') . ". Return times that don't exist: " . implode(', ', $deleteIns));
                    // Delete orphaned occurrences.
                    $sql = $config->deleteorphanedsql . ' :inout, :dates, :comment';
                    $params = array(
                        'inout' => 'IN',
                        'dates' => implode(',', $deleteIns),
                        'comment' => $this->appendix . $activity->get('id'),
                    );
                    $externalDB->execute($sql, $params);
                }
            }


        } catch (Exception $ex) {
            // Error.
        }

        $this->log_finish("Finished creating absences");

    }

}