<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/activities.lib.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/activity.class.php');

use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\Activity;

class recurrence_lib {

    public static function get_series($activityid) {
        global $DB;
        $format = "D, j M Y g:ia";
        $occurrences = $DB->get_records('activities_occurrences', array('activityid' => $activityid));
        $occurrencesOut = (object) array(
            'dates' => [],
            'datesReadable' => []
        );
        foreach ($occurrences as $occurrence) {
            // Add the dates
            $occurrencesOut->dates[] = ['start' => $occurrence->timestart, 'end' => $occurrence->timeend];
            $occurrencesOut->datesReadable[] = [
                'start' => date($format, $occurrence->timestart),
                'end' => date($format, $occurrence->timeend),
                'id' => $occurrence->id
            ];
        }
        return $occurrencesOut;
    }


    public static function delete_or_detach_occurrence($type, $activityid, $occurrenceid) {
        global $DB;

        if (empty($activityid) || empty($occurrenceid) || empty($type)) {
            throw new \Exception("Invalid activity or occurrence id.");
        }

        if ($type == 'detach') {
            // get the activity
            $activity = new Activity($activityid);
            $oldactivityid = $activity->get('id');

            // get the occurrence
            $occurrence = $DB->get_record('activities_occurrences', array('id' => $occurrenceid));

            // remove the id and save to create a new activity
            $activity->set('id', null);
            $activity->set('timestart', $occurrence->timestart);
            $activity->set('timeend', $occurrence->timeend);
            $activity->set('recurring', false);
            $activity->set('recurrence', '{}');
            $activity->set('timesynclive', 0);
            $activity->set('timesyncplanning', 0);
            $activity->set('absencesprocessed', 0);
            $activity->set('classrollprocessed', 0);
            
            $activity->save();

            // Copy the staff.
            $staffs = array_values($DB->get_records('activities_staff', array('activityid' => $activityid)));
            if (!empty($staffs)) {
                // Replace the old activity id with the new one.
                foreach ($staffs as $staff) {
                    $staff->activityid = $activity->get('id');
                }
                $DB->insert_records('activities_staff', $staffs);
            }

            // Copy the students.
            $students = array_values($DB->get_records('activities_students', array('activityid' => $activityid)));
            if (!empty($students)) {
                // Replace the old activity id with the new one.
                foreach ($students as $student) {
                    $student->activityid = $activity->get('id');
                }
                $DB->insert_records('activities_students', $students);
            }

            //Copy the approvals.
            $approvals = array_values($DB->get_records('activities_approvals', array('activityid' => $activityid)));
            if (!empty($approvals)) {
                // Replace the old activity id with the new one.
                foreach ($approvals as $approval) {
                    $approval->activityid = $activity->get('id');
                }
                $DB->insert_records('activities_approvals', $approvals);
            }

            // Copy the permissions.
            $permissions = array_values($DB->get_records('activities_permissions', array('activityid' => $activityid)));
            if (!empty($permissions)) {
                // Replace the old activity id with the new one.
                foreach ($permissions as $permission) {
                    $permission->activityid = $activity->get('id');
                }
                $DB->insert_records('activities_permissions', $permissions);
            }
                
            // Copy the risk assessments
            $ragens = $DB->get_records('activities_ra_gens', array('activityid' => $activityid));
            $mappedragens = array();
            if ($ragens) {
                foreach ($ragens as $ragen) {
                    $ragen->activityid = $activity->get('id');
                    $newnewragenid = $DB->insert_record('activities_ra_gens', $ragen);
                    $mappedragens[$ragen->id] = $newnewragenid;
                    // Copy the custom risks
                    $customrisks = $DB->get_records('activities_ra_gens_risks', array('ra_gen_id' => $ragen->id));
                    if ($customrisks) {
                        foreach ($customrisks as $customrisk) {
                            $customrisk->ra_gen_id = $newnewragenid;
                            $DB->insert_record('activities_ra_gens_risks', $customrisk); 
                        }
                    }
                }
            }

            // Copy the files...
            ob_start();
            try {
                // Get the file storage instance
                $fs = get_file_storage();

                // Get all files from the original activity
                $attachments = $fs->get_area_files(
                    1, 
                    'local_announcements2', 
                    'attachments', 
                    $oldactivityid,
                    'id',
                    false
                );

                // Get all RAs from the original activity
                $riskassessments = $fs->get_area_files(
                    1, 
                    'local_announcements2', 
                    'riskassessment', 
                    $oldactivityid,
                    'id',
                    false
                );

                $files = array_merge($attachments, $riskassessments);

                // Copy each file to the new activity
                foreach ($files as $file) {
                    $newfile = array(
                        'contextid' => $file->get_contextid(),
                        'component' => $file->get_component(),
                        'filearea'  => $file->get_filearea(),
                        'itemid'    => $activity->get('id'),
                        'filepath'  => $file->get_filepath(),
                        'filename'  => $file->get_filename(),
                    );
                    $fs->create_file_from_storedfile($newfile, $file);
                }

                // Get all RA generations from the original activity
                $ragens = array();
                $ragenids = array_keys($mappedragens);
                foreach ($ragenids as $ragenid) {
                    $singlegen = $fs->get_area_files(
                        1, 
                        'local_announcements2', 
                        'ra_generations', 
                        $ragenid,
                        'id',
                        false
                    );
                    $ragens[$ragenid] = array_pop($singlegen);
                }

                foreach ($ragenids as $ragenid) {
                    $ragennewid = $mappedragens[$ragenid];
                    $ragen = $ragens[$ragenid];
                    $newfile = array(
                        'contextid' => $ragen->get_contextid(),
                        'component' => $ragen->get_component(),
                        'filearea'  => $ragen->get_filearea(),
                        'itemid'    => $ragennewid,
                        'filepath'  => $ragen->get_filepath(),
                        'filename'  => $ragen->get_filename(),
                    );
                    $fs->create_file_from_storedfile($newfile, $ragen);
                }

                ob_end_clean();
            } catch (Exception $e) {
                // Ensure buffer is cleaned even if an error occurs
                ob_end_clean();
                throw $e; // Re-throw the exception
            }

            
        }

        // Delete the old occurrence
        $DB->delete_records('activities_occurrences', array('id' => $occurrenceid));

        if ($type == 'detach') {
            return (object) ['new_activity_id' => $activity->get('id'), 'series' => self::get_series($activityid)];
        } else {
            return (object) ['new_activity_id' => 0, 'series' => self::get_series($activityid)];
        }
    }

    public static function expand_dates($recurrence, $timestart, $timeend) {
        $dates = array();
        $datesReadable = array();
        $format = "D, j M Y g:ia";
        //$format = "Y-m-d H:i:s";
        //var_export([$recurrence, $timestart, $timeend]); exit;
        
        if ($recurrence->pattern == 'Daily') {

            // If daily is selected but event goes for longer than a day, that's an issue.
            $daystart = date('d', $timestart);
            $dayend = date('d', $timeend);
            if ($daystart !== $dayend) {
                return false;
            }

            // Add dates until we've reached the end.
            $finished = false;
            while (!$finished) {
                if ($recurrence->dailyPattern == 'Every weekday') { // Every weekday
                    if (date('N', $timestart) < 6) {
                        $dates[] = array('start' => $timestart,'end' => $timeend);
                        $datesReadable[] = array('start' => date($format, $timestart),'end' => date($format, $timeend));
                    }
                    //Add a day
                    $timestart += 60*60*24;
                    $timeend += 60*60*24;
                } elseif ($recurrence->dailyPattern == 'Every') { // Every x days
                    $dates[] = array('start' => $timestart,'end' => $timeend);
                    $datesReadable[] = array('start' => date($format, $timestart),'end' => date($format, $timeend));
                    //Add x days
                    $timestart += 60*60*24*$recurrence->dailyInterval;
                    $timeend += 60*60*24*$recurrence->dailyInterval;
                }
                //Check if we've reached the end (by date or after x occurrences)
                if ($recurrence->range == 'End by' && $timestart > $recurrence->endBy ||
                    $recurrence->range == 'End after' && count($dates) >= $recurrence->endAfter) {
                    $finished = true;
                }
                // Max 52 occurrences
                if (count($dates) >= 52) {
                    $finished = true;
                }
            }

        } else if ($recurrence->pattern == 'Weekly') {
            // If week is selected but event goes for longer than a week, that's an issue.
            if ($timeend-$timestart > 604800) { // seconds in a week.
                return false;
            }

            // Get the initial day of week for the start date
            $initialDayOfWeek = date('l', $timestart);
            $initialTime = date('H:i:s', $timestart);
            $initialDuration = $timeend - $timestart;

            // Convert weeklyDays to numeric values (0=Sunday, 6=Saturday)
            $dayNumbers = [];
            foreach ($recurrence->weeklyDays as $day) {
                $dayNumbers[] = date('w', strtotime($day));
            }
            sort($dayNumbers);

            $currentWeek = 0;
            $occurrences = 0;
            $finished = false;

            while (!$finished) {
                // Only process days in the current week if it's a multiple of weeklyInterval
                if ($currentWeek % $recurrence->weeklyInterval === 0) {
                    // For each selected day in the week
                    foreach ($dayNumbers as $dayNum) {
                        // Skip days that are before the initial day in the first week
                        if ($currentWeek === 0 && $dayNum < date('w', $timestart)) {
                            continue;
                        }
                        
                        // Calculate the timestamp for this occurrence
                        $dayOffset = $dayNum - date('w', $timestart) + ($currentWeek * 7);
                        $newStart = strtotime("+$dayOffset days", $timestart);
                        $newEnd = $newStart + $initialDuration;
                        
                        // Check if we've passed the end date (if using "End by")
                        if ($recurrence->range == 'End by' && $newStart > $recurrence->endBy) {
                            $finished = true;
                            break;
                        }
                        
                        // Add the dates
                        $dates[] = ['start' => $newStart, 'end' => $newEnd];
                        $datesReadable[] = [
                            'start' => date($format, $newStart),
                            'end' => date($format, $newEnd)
                        ];
                        
                        // Check if we've reached the max occurrences (if using "End after")
                        $occurrences++;
                        if ($recurrence->range == 'End after' && $occurrences >= $recurrence->endAfter) {
                            $finished = true;
                            break;
                        }
                    }
                }
                
                $currentWeek++;
                
                // Additional stop condition if neither end condition is set
                if (!isset($recurrence->range) && $currentWeek > 100) {
                    $finished = true; // Prevent infinite loop
                }
                // Max 30 occurrences
                if (count($dates) >= 30) {
                    $finished = true;
                }
            }

        } else if ($recurrence->pattern == 'Monthly') {
            $initialTime = date('H:i:s', $timestart);
            $initialDuration = $timeend - $timestart;
            
            $currentDate = new \DateTime();
            $currentDate->setTimestamp($timestart);
            
            $occurrences = 0;
            $finished = false;
            $intervalMonths = $recurrence->monthlyInterval ?? 1;
            $monthsAdded = 0;
            
            while (!$finished) {
                $newDate = clone $currentDate;
                
                // Add the interval months (only after first iteration)
                if ($monthsAdded > 0) {
                    $newDate->add(new \DateInterval("P{$intervalMonths}M"));
                }
                $monthsAdded += $intervalMonths;
                
                if ($recurrence->monthlyPattern == 'Day') {
                    // Pattern: "Day X of every Y months"
                    $day = $recurrence->monthlyDay;
                    
                    // Set to the specified day of month
                    $daysInMonth = $newDate->format('t');
                    $day = min($day, $daysInMonth); // Don't exceed month's days
                    
                    $newDate->setDate(
                        $newDate->format('Y'),
                        $newDate->format('m'),
                        $day
                    );
                } else if ($recurrence->monthlyPattern == 'The') {
                    // Pattern: "The X Y of every Z months"
                    $nth = $recurrence->monthlyNth;
                    $weekday = $recurrence->monthlyNthDay;
                    
                    // Reset to first day of month
                    $newDate->setDate(
                        $newDate->format('Y'),
                        $newDate->format('m'),
                        1
                    );
                    
                    // Find the requested weekday
                    if ($nth == 'Last') {
                        // Move to last day of month
                        $newDate->setDate(
                            $newDate->format('Y'),
                            $newDate->format('m'),
                            $newDate->format('t')
                        );
                        
                        // Then find the previous $weekday
                        while ($newDate->format('l') != $weekday) {
                            $newDate->modify('-1 day');
                        }
                    } else {
                        // Convert nth to number (1-4)
                        $nthMap = ['First' => 1, 'Second' => 2, 'Third' => 3, 'Fourth' => 4];
                        $nthNum = $nthMap[$nth];
                        
                        $found = 0;
                        $currentMonth = $newDate->format('m');
                        
                        // Iterate through days until we find the nth weekday
                        while ($newDate->format('m') == $currentMonth && $found < $nthNum) {
                            if ($newDate->format('l') == $weekday) {
                                $found++;
                                if ($found == $nthNum) break;
                            }
                            $newDate->modify('+1 day');
                        }
                    }
                }
                
                // Set the time to match original time
                $newDate->setTime(
                    date('H', $timestart),
                    date('i', $timestart),
                    date('s', $timestart)
                );
                
                $newStart = $newDate->getTimestamp();
                $newEnd = $newStart + $initialDuration;
                
                // Check if we've passed the end date (if using "End by")
                if ($recurrence->range == 'End by' && $newStart > $recurrence->endBy) {
                    $finished = true;
                    break;
                }
                
                // Add the dates
                $dates[] = ['start' => $newStart, 'end' => $newEnd];
                $datesReadable[] = [
                    'start' => date($format, $newStart),
                    'end' => date($format, $newEnd)
                ];
                
                // Check if we've reached the max occurrences (if using "End after")
                $occurrences++;
                if ($recurrence->range == 'End after' && $occurrences >= $recurrence->endAfter) {
                    $finished = true;
                    break;
                }
                
                // Update current date for next iteration
                $currentDate = $newDate;

                // Max 30 occurrences
                if (count($dates) >= 30) {
                    $finished = true;
                }
            }



        } else if ($recurrence->pattern == 'Yearly') {
            $initialTime = date('H:i:s', $timestart);
            $initialDuration = $timeend - $timestart;
            
            $currentDate = new \DateTime();
            $currentDate->setTimestamp($timestart);
            
            $occurrences = 0;
            $finished = false;
            $intervalYears = $recurrence->yearlyInterval ?? 1;
            $yearsAdded = 0;
            
            while (!$finished) {
                $newDate = clone $currentDate;
                
                // Add the interval years (only after first iteration)
                if ($yearsAdded > 0) {
                    $newDate->add(new \DateInterval("P{$intervalYears}Y"));
                }
                $yearsAdded += $intervalYears;
                
                if ($recurrence->yearlyPattern == 'On') {
                    // Pattern: "On [Month] [Day] every [X] years"
                    $month = $recurrence->yearlyMonth;
                    $day = $recurrence->yearlyMonthDay;
                    
                    // Set to the specified month and day
                    $monthNum = date('m', strtotime($month));
                    $newDate->setDate(
                        $newDate->format('Y'),
                        $monthNum,
                        min($day, $newDate->format('t')) // Don't exceed month's days
                    );
                } else if ($recurrence->yearlyPattern == 'On the') {
                    // Pattern: "On the [Nth] [Weekday] of [Month] every [X] years"
                    $nth = $recurrence->yearlyNth;
                    $weekday = $recurrence->yearlyNthDay;
                    $month = $recurrence->yearlyNthMonth;
                    
                    // Set to first day of the specified month
                    $monthNum = date('m', strtotime($month));
                    $newDate->setDate(
                        $newDate->format('Y'),
                        $monthNum,
                        1
                    );
                    
                    // Find the requested weekday
                    if ($nth == 'Last') {
                        // Move to last day of month
                        $newDate->setDate(
                            $newDate->format('Y'),
                            $monthNum,
                            $newDate->format('t')
                        );
                        
                        // Then find the previous $weekday
                        while ($newDate->format('l') != $weekday) {
                            $newDate->modify('-1 day');
                        }
                    } else {
                        // Convert nth to number (1-4)
                        $nthMap = ['First' => 1, 'Second' => 2, 'Third' => 3, 'Fourth' => 4];
                        $nthNum = $nthMap[$nth];
                        
                        $found = 0;
                        $currentMonth = $newDate->format('m');
                        
                        // Iterate through days until we find the nth weekday
                        while ($newDate->format('m') == $currentMonth && $found < $nthNum) {
                            if ($newDate->format('l') == $weekday) {
                                $found++;
                                if ($found == $nthNum) break;
                            }
                            $newDate->modify('+1 day');
                        }
                    }
                }
                
                // Set the time to match original time
                $newDate->setTime(
                    date('H', $timestart),
                    date('i', $timestart),
                    date('s', $timestart)
                );
                
                $newStart = $newDate->getTimestamp();
                $newEnd = $newStart + $initialDuration;
                
                // Check if we've passed the end date (if using "End by")
                if ($recurrence->range == 'End by' && $newStart > $recurrence->endBy) {
                    $finished = true;
                    break;
                }
                
                // Add the dates only if they're in the future (or equal to start date)
                if ($newStart >= $timestart) {
                    $dates[] = ['start' => $newStart, 'end' => $newEnd];
                    $datesReadable[] = [
                        'start' => date($format, $newStart),
                        'end' => date($format, $newEnd)
                    ];
                    
                    // Check if we've reached the max occurrences (if using "End after")
                    $occurrences++;
                    if ($recurrence->range == 'End after' && $occurrences >= $recurrence->endAfter) {
                        $finished = true;
                        break;
                    }
                }
                
                // Update current date for next iteration
                $currentDate = $newDate;

                // Max 30 occurrences
                if (count($dates) >= 30) {
                    $finished = true;
                }
            }
            



        } else if ($recurrence->pattern == 'Custom') {
            $dates = [];
            $datesReadable = [];
            
            // Get the time components from the original timestamps
            $startTime = date('H:i:s', $timestart);
            $duration = $timeend - $timestart;
            
            foreach ($recurrence->customDates as $dateString) {
                // Create DateTime object from the custom date string
                $date = new \DateTime($dateString);
                
                // Set the time to match the original event time
                $date->setTime(
                    date('H', $timestart),
                    date('i', $timestart),
                    date('s', $timestart)
                );
                
                $newStart = $date->getTimestamp();
                $newEnd = $newStart + $duration;
                
                // Only add dates that are in the future (or equal to start date)
                if ($newStart >= $timestart) {
                    $dates[] = ['start' => $newStart, 'end' => $newEnd];
                    $datesReadable[] = [
                        'start' => date($format, $newStart),
                        'end' => date($format, $newEnd)
                    ];
                }
            }
            
            // Sort the dates in chronological order
            usort($dates, function($a, $b) {
                return $a['start'] - $b['start'];
            });
            
            usort($datesReadable, function($a, $b) {
                return strtotime($a['start']) - strtotime($b['start']);
            });
        }

        return ['dates'=> $dates, 'datesReadable'=> $datesReadable];
    }




  

}