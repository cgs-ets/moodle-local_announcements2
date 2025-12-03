<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/activities.lib.php');

use \local_announcements2\lib\activities_lib;

class conflicts_lib {

    public static function check_conflicts($activityid, $timestart, $timeend, $unix = false, $type = "activity") {
        global $DB;

        if (!$unix) {
            $timestart = strtotime($timestart);
            $timeend = strtotime($timeend);
        }

        // If timestart or timeend is NaN, return an empty array.
        if ($timestart == 'NaN' || $timeend == 'NaN') {
            return [];
        }

        return static::check_conflicts_for_single($activityid, $timestart, $timeend, $type);
    }

    public static function check_conflicts_for_single($activityid, $timestart, $timeend, $type = "activity") {
        global $DB;
        $conflicts = array();

        //if ($type == "activity") {
            // Find approved activitys that intersect with this start and end time.
            $sql = "SELECT * 
                    FROM {activities}
                    WHERE deleted = 0 
                    AND status > 1
                    AND (
                        (timestart > ? AND timestart < ?) OR 
                        (timeend > ? AND timeend < ?) OR 
                        (timestart <= ? AND timeend >= ?) OR  
                        (timestart >= ? AND timeend <= ?)
                    )
            ";
            $rawactivityconflicts = $DB->get_records_sql($sql, [$timestart, $timeend, $timestart, $timeend, $timestart, $timeend, $timestart, $timeend]);
            foreach ($rawactivityconflicts as $activity) {
                // Dont clash with self or another activity in the same series.
                if ($activity->id == $activityid && $type == "activity") {
                    continue;
                }
                $owner = json_decode($activity->staffinchargejson);
                $areas = json_decode($activity->areasjson);
                $conflicts[] =  (object) [
                    'activityid' => $activity->id,
                    'activityname' => $activity->activityname,
                    'location' => $activity->location,
                    'activitytype' => $activity->activitytype,
                    'timestart' => date('g:ia', $activity->timestart),
                    'datestart' => date('j M Y', $activity->timestart),
                    'timeend' => date('g:ia', $activity->timeend),
                    'dateend' => date('j M Y', $activity->timeend),
                    'areas' => $areas,
                    'owner' => $owner,
                ];
            }
        //}

        //if ($type == "assessment") {
            // Find conflicts with assessments.
            $sql = "SELECT * 
                    FROM {activities_assessments}
                    WHERE deleted = 0
                    AND (
                        (timestart > ? AND timestart < ?) OR 
                        (timeend > ? AND timeend < ?) OR 
                        (timestart <= ? AND timeend >= ?) OR  
                        (timestart >= ? AND timeend <= ?)
                    )
            ";
            $rawassessmentconflicts = $DB->get_records_sql($sql, [$timestart, $timeend, $timestart, $timeend, $timestart, $timeend, $timestart, $timeend]);
            foreach ($rawassessmentconflicts as $assessment) {
                // Dont clash with self or another activity in the same series.
                if ($assessment->id == $activityid && $type == "assessment") {
                    continue;
                }
                $owner = utils_lib::user_stub($assessment->creator);
                if ($assessment->staffincharge) {
                    $owner = utils_lib::user_stub($assessment->staffincharge);
                }
                $course = $DB->get_record('course', array('id' => $assessment->courseid));
                $conflicts[] =  (object) [
                    'assessmentid' => $assessment->id,
                    'name' => $assessment->name,
                    'course' => $course->fullname ?? "",
                    'url' => $assessment->url,
                    'activitytype' => 'assessment',
                    'timestart' => date('g:ia', $assessment->timestart),
                    'datestart' => date('j M Y', $assessment->timestart),
                    'timeend' => date('g:ia', $assessment->timeend),
                    'dateend' => date('j M Y', $assessment->timeend),
                    'owner' => $owner,
                ];
            }
        //}

        return $conflicts;
    }

    public static function check_conflicts_for_activity($id) {
        global $DB;

        $activity = activities_lib::get_activity($id);
        if (empty($activity)) {
            return [];
        }

        $conflicts = static::check_conflicts_for_single($id, $activity->timestart, $activity->timeend, "activity");
        static::sync_conflicts($id, $conflicts);
        $html = static::generate_conflicts_html($conflicts, true, static::export_activity($activity));
 
        return ['html' => $html, 'conflicts' => $conflicts];

    }

    public static function generate_conflicts_html($conflicts, $withActions = false, $activityContext = null) {
        $html = '';
        // Generate the html.
        if (count($conflicts)) {
            if ($activityContext) {
                $activityContext = (object) $activityContext;
                $owner = json_decode($activityContext->staffincharge);
                $avatar = '<div><img class="rounded-full" height="18" src="/local/activities/avatar.php?username=' . $owner->un . '"> <span>' . "$owner->fn $owner->ln" . '</span></div>';
                $areas = "<ul>" . implode("", array_map(function($area) { return "<li>" . $area . "</li>"; }, $activityContext->areas)) . "</ul>";
                $timestart = '<div>' . $activityContext->timestartReadable . '</div><div><small>' . date('j M Y', $activityContext->timestart) . '</small></div>';
                $timeend = '<div>' . $activityContext->timeendReadable . '</div><div><small>' . date('j M Y', $activityContext->timeend) . '</small></div>';
                $html .= '<div class="table-heading"><b class="table-heading-label">activity summary</b></div>';
                $html .= "<table><tr> <th>Title</th> <th>Date</th> <th>Location</th> <th>Areas</th> <th>Owner</th> </tr>";
                $actionshtml = '';
                if ($withActions) {
                    $editurl = new \moodle_url("/local/activities/$activityContext->id");
                    $actionshtml .= '<td><div class="actions">';
                    $actionshtml .= '<a class="btn btn-secondary" target="_blank" href="' . $editurl->out(false) . '">Edit</a><br><br>';
                    $actionshtml .= "</div></td>";
                }
                $html .= "<tr><td>$activityContext->activityname</td>";
                $html .= "<td><div style=\"display:flex;gap:20px;\"><div>$timestart</div><div>$timeend</div></div></td>";
                $html .= "<td>$activityContext->location</td>";
                $html .= "<td>$areas</td><td>$avatar</td>$actionshtml</tr>";
                $html .= "</table><br>";
                $html .= '<div class="table-heading"><b class="table-heading-label">Conflicting activitys</b></div>';
            }
            $html .= "<table> <tr> <th>Title</th> <th>Dates</th> <th>Location</th> <th>Areas</th> <th>Owner</th> </tr>";
            foreach($conflicts as $conflict) {
                $html .= '<tr data-activityid="' . $conflict->activityid . '">';
                $html .= "<td>$conflict->activityname</td>";
                $html .= "<td><div style=\"display:flex;gap:20px;\"><div>$conflict->timestart</div><div>$conflict->timeend</div></div></td>";
                $html .= "<td>$conflict->location</td>";
                $html .= "<td>$conflict->areas</td>";
                $html .= "<td>$conflict->owner</td>";
                if ($withActions) {
                    $editurl = new \moodle_url("/local/activities/$activityContext->id");
                    $html .= '<td><div class="actions">';
                    $html .= '<a class="btn btn-secondary" target="_blank" href="' . $editurl->out(false) . '">Edit</a><br><br>';
                    $html .= '<div>
                                <input type="checkbox" id="ignore' . $conflict->conflictid . '" data-conflictid="' . $conflict->conflictid . '" name="status" value="1"' . ($conflict->status == 1 ? 'checked="true"' : '') . '>
                                <label for="ignore' . $conflict->conflictid . '">Ignore conflict</label>
                              </div>';
                    $html .= "</div></td>";
                }
                $html .= "</tr>";
            }
            $html .= '</table>';
        }
        return $html;
    }

    public static function sync_conflicts($activityid, &$conflicts) {
        global $DB;

        // Sync found conflicts to db.
        // Get existing conflicts for this activity.
        $existingConflicts = $DB->get_records_sql("
            SELECT * FROM {activities_conflicts}
            WHERE activityid1 = ?
            OR activityid2 = ?
        ", [$activityid, $activityid]);

        // Process the newly found conflicts.
        foreach($conflicts as &$conflict) {
            // Check if a record already exists for this conflict.
            foreach ($existingConflicts as $i => $existing) {
                if ($existing->activityid1 == $conflict->activityid || $existing->activityid2 == $conflict->activityid) {
                    // This conflict already exists in the db.
                    $conflict->conflictid = $existing->id;
                    $conflict->status = $existing->status;
                    unset($existingConflicts[$i]);
                }
            }
        }

        // Insert the new conflicts.
        $createConflicts = [];
        foreach($conflicts as &$conflict) {
            if (empty($conflict->conflictid)) {
                // Make sure this conflict does not exist.
                $sql = "SELECT id 
                FROM {activities_conflicts} 
                WHERE activityid1 = ? AND activityid2 = ? 
                OR activityid2 = ? AND activityid1 = ?";
                $exists = $DB->get_records_sql($sql, [$activityid, $conflict->activityid, $activityid, $conflict->activityid]);
                if (empty($exists)) {
                    $createConflicts[] = [
                        'activityid1' => $activityid,
                        'activityid2' => $conflict->activityid,
                        'activity2istype' => $conflict->activitytype,
                        'status' => 0,
                    ];
                }
            }
        }
        $DB->insert_records('activities_conflicts', $createConflicts);

        // Delete db conflicts that are no longer conflicts.
        foreach ($existingConflicts as $remaining) {
            $DB->execute("DELETE FROM {activities_conflicts} WHERE id = ?", [$remaining->id]);
        }
    }

    public static function set_conflict_status($id, $status) {
        global $DB;

        $theConflict = $DB->get_record('activities_conflicts', array('id' => $id));
        if (empty($theConflict)) {
            return;
        }

        $theConflict->status = $status;
        $DB->update_record('activity_conflicts', $theConflict);
    }


 
}