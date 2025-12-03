<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/activities.lib.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/calendar.lib.php');

use \local_announcements2\lib\activities_lib;
use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\calendar_lib;
use DateTime;

class assessments_lib {

    public static function get($id) {
        global $DB, $USER;

        $assessment = $DB->get_record('activities_assessments', array('id' => $id));
        if (!$assessment) {
            throw new \Exception("Assessment not found.");
        }

        $assessment->usercanedit = false;
        if (static::can_user_edit($assessment)) {
            $assessment->usercanedit = true;
        }

        if ($assessment->activityid) {
            $assessment->activityname = $DB->get_field('activities', 'activityname', array('id' => $assessment->activityid));
        }

        $assessment->creatordata = utils_lib::user_stub($assessment->creator);

        if ($assessment->staffincharge) {
            $assessment->staffincharge = utils_lib::user_stub($assessment->staffincharge);
        } else {
            $assessment->staffincharge = utils_lib::user_stub($assessment->creator);
        }

        $assessment->studentlist = self::get_assessment_students($assessment->id);

        return $assessment;
    }

    public static function get_course_cats() {
        global $DB;

        // Get courses under Senior Academic
        $categories = array();
        $cat = $DB->get_record('course_categories', array('idnumber' => 'SEN-ACADEMIC'));
        if ($cat) {
            $cat = \core_course_category::get($cat->id);
            $cats = $cat->get_children();
            foreach($cats as $cat) {
                $categories[] = array(
                    'value' => $cat->id,
                    'label' => $cat->name
                );
            }
        }

        return $categories;
    }

    public static function get_courses() {
        global $DB;

        // Get courses under Senior Academic
        $courses = array();
        $cat = $DB->get_record('course_categories', array('idnumber' => 'SEN-ACADEMIC'));
        if ($cat) {
            $cat = \core_course_category::get($cat->id);
            $coursesinfo = $cat->get_courses(['recursive'=>true]);
            foreach($coursesinfo as $courseinfo) {
                $courses[] = array(
                    'value' => $courseinfo->id,
                    'label' => $courseinfo->fullname
                );
            }
        }

        // Get courses under 2025
        $cat = $DB->get_record('course_categories', array('idnumber' => '2025'));
        if ($cat) {
            $cat = \core_course_category::get($cat->id);
            $coursesinfo = $cat->get_courses(['recursive'=>true]);
            foreach($coursesinfo as $courseinfo) {
                $courses[] = array(
                    'value' => $courseinfo->id,
                    'label' => $courseinfo->fullname
                );
            }
        }

        if ($courses) {
            usort($courses, function($a, $b) {
                return strcmp($a['label'], $b['label']);
            });
        }

        return $courses;
    }


    public static function get_modules($courseid) {
        global $DB;
        if (!$courseid) {
            return array();
        }

        $modules = array();

        $course = $DB->get_record('course', array('id' => $courseid));
        $modinfo = @get_fast_modinfo($course);

        //var_export($modinfo); exit;
        foreach ($modinfo->cms as $cm) {
            $modules[] = array(
                'value' => $cm->id,
                'label' => $cm->name,
                'url' => !empty($cm->url) ? $cm->url->out(false) : '',
            );
        }
        //var_export($modules); exit;
        return $modules;
    }


    public static function save_from_data($data) {
        global $DB, $USER;

        $data->activityrequired = $data->activityrequired ? 1 : 0;
        $data->rollrequired = $data->rollrequired ? 1 : 0;
        $data->timemodified = time();
        $data->timestart = intval($data->timestart / 60) * 60; // Remove seconds.
        $data->timeend = intval($data->timeend / 60) * 60; // Remove seconds.
        $data->staffinchargejson = json_encode($data->staffincharge);
        $data->staffincharge = $data->staffincharge['un'] ?? $USER->username;

        if ($data->id) {
            $DB->update_record('activities_assessments', (object) $data);
        } else {
            $data->creator = $USER->username;
            $data->timecreated = time();
            $data->id = $DB->insert_record('activities_assessments', (object) $data);
        }

        // Sync the student list.
        $studentusernames = array_map(function($u) {
            return $u['un'];
        }, $data->studentlist);
        static::sync_students_from_data($data->id, $studentusernames);

        return array(
            'id' => $data->id,
        );
    }

    /**
     * Update assessment students.
     *
     * @param int $assessmentid
     * @param array $studentusernames
     * @return void
     */   
    public static function sync_students_from_data($assessmentid, $studentusernames) {
        global $DB;

        // Copy usernames into keys.
        $newstudents = array_combine($studentusernames, $studentusernames);

        // Load existing students.
        $existingstudentrecs = static::get_assessment_students($assessmentid);
        $existingstudents = array_column($existingstudentrecs, 'un');
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
            $newstudentdata = array_map(function($username) use ($assessmentid) {
                $rec = new \stdClass();
                $rec->assessmentid = $assessmentid;
                $rec->username = $username;
                return $rec;
            }, $newstudents);
            $DB->insert_records('activities_assessments_students', $newstudentdata);
        }

        // Process removed students.
        if (count($existingstudents)) {
            list($insql, $inparams) = $DB->get_in_or_equal($existingstudents);
            $params = array_merge([$assessmentid], $inparams);
            $sql = "DELETE FROM {activities_assessments_students} 
            WHERE assessmentid = ? 
            AND username $insql";
            $DB->execute($sql, $params);
        }
    }



    public static function get_cal( $args ) {
		switch ($args['type']) {
            case 'list':
                return self::getList($args);
            case 'table':
                return self::getTable($args);
            default:
                return self::getFull($args);
        }
	}


    /**
     * Get the assessments for the calendar.
     *
     * @param array $args
     * @return array
     */
    public static function get_assessments($args) {
        global $DB, $USER;

        utils_lib::require_staff();

        $start = strtotime($args->scope->start . " 00:00:00");
        $end = strtotime($args->scope->end . " 00:00:00");
        $end += 86400; //add a day


        $sql = "SELECT *
                FROM mdl_activities_assessments
                WHERE deleted = 0
                AND (
                    (timestart >= ? AND timestart <= ?) OR 
                    (timeend >= ? AND timeend <= ?) OR
                    (timestart < ? AND timeend > ?)
                )
                ORDER BY timestart ASC";
        $records = $DB->get_records_sql($sql, [$start, $end, $start, $end, $start, $end]);
        $assessments = array();
        foreach ($records as $record) {
            $record->creatorid = $record->creator;
            $record->creator = utils_lib::user_stub($record->creator);
            $record->creatorsortname = $record->creator->ln . ', ' . $record->creator->fn;
            $record->course = $DB->get_record('course', array('id' => $record->courseid));
            if ($record->course) {
                $record->coursefullname = $record->course->fullname;
            }
            //$record->module = $DB->get_record('course_modules', array('id' => $record->cmid));
            //$record->modulename = $record->module->name;
            $record->usercanedit = false;
            if (static::can_user_edit($record)) {
                $record->usercanedit = true;
            }
            $assessments[] = $record;            
        }

        return $assessments;
    }

    public static function get_assessment_students($id) {
        global $DB;

        $students = $DB->get_records('activities_assessments_students', array('assessmentid' => $id));
        return array_values(array_map(function($student) {
            return utils_lib::user_stub($student->username);
        }, $students));
    }


    public static function delete($id) {
        global $DB, $USER;

		$assessment = $DB->get_record('activities_assessments', array('id' => $id));
        if (!$assessment) {
            throw new \Exception("Assessment not found.");
        }

        $usercanedit = false;
        if (static::can_user_edit($assessment)) {
            $usercanedit = true;
        }

		if (!$usercanedit) {
            throw new \Exception("Permission denied.");
		}

        $assessment->deleted = 1;
        $assessment->timemodified = time();
        $DB->update_record('activities_assessments', $assessment);
        return 1;
    }


    public static function getList( $args ) {
		$events_array = array();
		$events_array['days'] = array();
		$events_array['days']['current'] = array();
		$events_array['days']['upcoming'] = array();

		//default args
        $year_now = $year = date('Y');
		$long_events = true;
		$type = 'list';
		
		// Get year if provided
		if( ! empty($args['year']) && is_numeric($year) ) {
			$year = $args['year'];
		}

		$term_map = array(
			1 => array(
				'start' => $year . '-01-01',
				'end' => $year . '-04-13',
			),
			2 => array(
				'start' => $year . '-04-14',
				'end' => $year . '-06-29',
			),
			3 => array(
				'start' => $year . '-06-30',
				'end' => $year . '-09-28',
			),
			4 => array(
				'start' => $year . '-09-29',
				'end' => $year . '-12-31',
			),
		);

		// Default term
		$term_now = $term = 1;
		if ( date($year . '-m-d') > $term_map[2]['start'] ) {
			$term_now = $term = 2;
		}
		if ( date($year . '-m-d') > $term_map[3]['start'] ) {
			$term_now = $term = 3;
		}
		if ( date($year . '-m-d') > $term_map[4]['start'] ) {
			$term_now = $term = 4;
		}

		// Get term if provided
		if( ! empty( $args['term'] ) ) {
			if ( $args['term'] >= 1 && $args['term'] <= 4 ) {
				$term = $args['term'];
			}
		}

		// Get long events if provided.
		if( ! empty($args['long_events']) ) {
			$long_events = $args['long_events'];
		}

		// Get categories if provided
		$categories = array();
		if( ! empty( $args['categories'] ) ) {
			$categories = $args['categories'];
		}

		//query the database for events in this time span
		$scope_datetime_start = new DateTime($term_map[$term]['start']);
		$scope_datetime_end = new DateTime($term_map[$term]['end']);

		$term_last = $term-1;
		$term_next = $term+1;
		$year_last = $year; 
		$year_next = $year;
		
		if ( $term == 1 ) { 
		   $term_last = 4;
		   $year_last = $year - 1;
		} elseif ( $term == 4 ){
			$term_next = 1;
			$year_next = $year + 1; 
		}

        $previous = array('type'=>$args['type'], 'tm'=>$term_last, 'yr'=>$year_last);
		$next = array('type'=>$args['type'], 'tm'=>$term_next, 'yr'=>$year_next);

		$events_array['pagination'] = array( 'previous' => $previous, 'next' => $next);
		$events_array['type'] = $type;
		$events_array['term'] = $term;
		$events_array['term_last'] = $term_last;
		$events_array['term_next'] = $term_next;
		$events_array['year'] = $year;
		$events_array['year_last'] = $year_last;
		$events_array['year_next'] = $year_next;
		$events_array['curr_period'] = $year_now . $term_now;
        $events_array['days'] = array(
            'current' => array(),
            'upcoming' => array(),
        );

		$events_args = array (
			'scope' => array( 
				'start' => $scope_datetime_start->format('Y-m-d'), 
				'end' => $scope_datetime_end->format('Y-m-d')
			),
			'categories' => $categories
		);
        
        //var_export($events_args); exit;
		$events = assessments_lib::get_assessments(json_decode(json_encode($events_args, JSON_FORCE_OBJECT)));

		if (empty($events)) {
			return $events_array;
		}

		
        //go through the events and put them into a daily array
        $events_dates = array();
        foreach($events as $event){
            $event_start_date = $event->timestart;
            $event_eventful_date = date('Y-m-d', $event_start_date);

            $in_scope = strtotime($event_eventful_date) >= strtotime($scope_datetime_start->format('Y-m-d'));
            
            $past = $event->timeend < strtotime('today midnight') ? true : false;
            //if ($past) { 
            //    continue; 
            //}

            $currently_on = (!$past) && ($event->timestart < time()) ? true : false;
            if( $currently_on ) {
                $events_dates['current'][$event_eventful_date][] = $event;
            } else {
                if ($in_scope) {
                    $events_dates['upcoming'][$event_eventful_date][] = $event;
                }
            }

            //if long events requested, add event to other dates too
            if( (!$currently_on) && $long_events && date('Y-m-d', $event->timeend) != date('Y-m-d', $event->timestart) ) {
                $tomorrow = $event_start_date + 86400;
                while( $tomorrow <= $event->timeend && $tomorrow <= strtotime($scope_datetime_end->format('Y-m-d h:i:s')) ){
                    $event_eventful_date = date('Y-m-d', $tomorrow);
                    $in_scope = strtotime($event_eventful_date) >= strtotime($scope_datetime_start->format('Y-m-d'));
                    if ($in_scope) {
                        $events_dates['upcoming'][$event_eventful_date][] = $event;
                    }
                    $tomorrow = $tomorrow + 86400;
                }
            }
        }


		foreach($events_dates as $period_key => $days) {
			foreach($events_dates[$period_key] as $day_key => $events) {
				$events_array['days'][$period_key][$day_key]['date_key'] = $day_key;
				$events_array['days'][$period_key][$day_key]['date'] = strtotime($day_key);
				$events_array['days'][$period_key][$day_key]['events_count'] = count($events);
				$events_array['days'][$period_key][$day_key]['events'] = $events;
			}
            $events_array['days'][$period_key] = array_values($events_array['days'][$period_key]);
		}

		return $events_array;
	}






    public static function getFull( $args ) {
		$calendar_array = array();
		$calendar_array['cells'] = array();
		$type = 'full';

		$month = "";
		$year = "";
		// Get month if provided
		if( ! empty($args['month']) && ! empty($args['year']) ){
			$month = $args['month']; 
			$year = $args['year'];
		}
		// Set default month and year if they were not provided
		if( !(is_numeric($month) && $month <= 12 && $month > 0) )   {
			$month = date('m'); 
		}
		if( !( is_numeric($year) ) ){
			$year = date('Y');
		} 

		// Get categories if provided
		$categories = array();
		if( ! empty( $args['categories'] ) ) {
			$categories = $args['categories'];
		}

		$limit = false;
		if( ! empty( $args['events_per_day'] ) ) {
			$limit = $args['events_per_day']; //limit arg will be used per day and not for events search
		}
		
        // First day of the week.
	   	$start_of_week = 1;
		
		// Get the first day of the month 
		$month_start = mktime(0,0,0,$month, 1, $year);
		$calendar_array['month_start'] = $month_start;
		
		// Get friendly month name 		
		$month_name = date('M',$month_start);
		// Figure out which day of the week the month starts on. 
		$month_start_day = date('D', $month_start);
	  
	  	switch($month_start_day){ 
			case "Sun": $offset = 0; break; 
			case "Mon": $offset = 1; break; 
			case "Tue": $offset = 2; break; 
			case "Wed": $offset = 3; break; 
			case "Thu": $offset = 4; break; 
			case "Fri": $offset = 5; break; 
			case "Sat": $offset = 6; break;
		}       
		//We need to go back to the WP defined day when the week started, in case the event day is near the end
		$offset -= $start_of_week;
		if($offset<0)
			$offset += 7;
		
		// determine how many days are in the last month.
		$month_last = $month-1;
		$month_next = $month+1;
		$year_last = $year; 
		$year_next = $year;
		
		if($month == 1) { 
		   $month_last = 12;
		   $year_last = $year -1;
		}elseif($month == 12){
			$month_next = 1;
			$year_next = $year + 1; 
		}
		$calendar_array['month_next'] = $month_next;
		$calendar_array['month_last'] = $month_last;
		$calendar_array['year_last'] = $year_last;
		$calendar_array['year_next'] = $year_next;

		$calendar_array['type'] = $type;
		
		$num_days_last = calendar_lib::days_in_month($month_last, $year_last);
		 
		// determine how many days are in the current month. 
		$num_days_current = calendar_lib::days_in_month($month, $year);
		// Build an array for the current days in the month.
		for($i = 1; $i <= $num_days_current; $i++){ 
		   $num_days_array[] = mktime(0,0,0,$month, $i, $year); 
		}
		// Build an array for the number of days in last month.
		for($i = 1; $i <= $num_days_last; $i++){ 
		    $num_days_last_array[] = mktime(0,0,0,$month_last, $i, $year_last); 
		}
		// If the $offset from the starting day of the 
		// week happens to be Sunday, $offset would be 0, 
		// so don't need an offset correction. 
		if($offset > 0){ 
		    $offset_correction = array_slice($num_days_last_array, -$offset, $offset); 
		    $new_count = array_merge($offset_correction, $num_days_array); 
		    $offset_count = count($offset_correction); 
		} else { // The else statement is to prevent building the $offset array. 
		    $offset_count = 0; 
		    $new_count = $num_days_array;
		}
		// count how many days we have with the two 
		// previous arrays merged together 
		$current_num = count($new_count); 
	
		// Since we will have 5 HTML table rows (TR) 
		// with 7 table data entries (TD) 
		// we need to fill in 35 TDs 
		// so, we will have to figure out 
		// how many days to appened to the end 
		// of the final array to make it 35 days. 	
		$num_weeks = 5; 
		if($current_num > 35){ 
			$num_weeks = 6;
		}
		$outset = ($num_weeks * 7) - $current_num;
		// Outset Correction 
		for($i = 1; $i <= $outset; $i++){ 
		   $new_count[] = mktime(0,0,0,$month_next, $i, $year_next);  
		}
		// Now let's "chunk" the $all_days array 
		// into weeks. Each week has 7 days 
		// so we will array_chunk it into 7 days. 
		$weeks = array_chunk($new_count, 7);    

		$previous = array('type'=>$args['type'], 'mo'=>$month_last, 'yr'=>$year_last);
		$next = array('type'=>$args['type'], 'mo'=>$month_next, 'yr'=>$year_next);
		
	 	$weekdays = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');
 		
		for( $n = 0; $n < $start_of_week; $n++ ) {   
			$last_day = array_shift($weekdays);     
			$weekdays[]= $last_day;
		}
	   
		$calendar_array['pagination'] = array( 'previous'=>$previous, 'next'=>$next);
		$calendar_array['row_headers'] = $weekdays;
		
		// Now we break each key of the array  
		// into a week and create a new table row for each 
		// week with the days of that week in the table data 
	  
		$i = 0;
		$current_date = date('Y-m-d');
		$week_count = 0;
		foreach ( $weeks as $week ) {
			foreach ( $week as $d ) {
				$date = date('Y-m-d', $d);
				$calendar_array['cells'][$date] = array('date'=>$d, 'events'=>array(), 'events_count'=>0); //set it up so we have the exact array of dates to be filled
                $calendar_array['cells'][$date]['type'] = '';
                if ($i < $offset_count) { //if it is PREVIOUS month
					$calendar_array['cells'][$date]['type'] = 'pre';
				}
				if (($i >= $offset_count) && ($i < ($num_weeks * 7) - $outset)) { // if it is THIS month
					if ( $current_date == $date ){	
						$calendar_array['cells'][$date]['type'] = 'today';
					}
				} elseif (($outset > 0)) { //if it is NEXT month
					if (($i >= ($num_weeks * 7) - $outset)) {
						$calendar_array['cells'][$date]['type'] = 'post';
					}
				}
				$i ++;
			}
			$week_count++;
		}
		
		//query the database for events in this time span with $offset days before and $outset days after this month to account for these cells in the calendar
		$scope_datetime_start = new DateTime("{$year}-{$month}-1");
		$scope_datetime_end = new DateTime($scope_datetime_start->format('Y-m-t'));
		$scope_datetime_start->modify("-$offset days");
		$scope_datetime_end->modify("+$outset days");
		
		$events_args = array (
			'scope' => array( 
				'start' => $scope_datetime_start->format('Y-m-d'), 
				'end' => $scope_datetime_end->format('Y-m-d')
			),
			'categories' => $categories
		);

        //var_export($events_args); exit;
		//$events = activities_lib::get_for_staff_calendar(json_decode(json_encode($events_args, JSON_FORCE_OBJECT)));
        $events = assessments_lib::get_assessments(json_decode(json_encode($events_args, JSON_FORCE_OBJECT)));
			
		$eventful_days= array();
		$eventful_days_count = array();
		if($events) {
			//Go through the events and slot them into the right d-m index
			foreach($events as $event) {
				$event_start_ts = $event->timestart;
				$event_end_ts = $event->timeend;
				$event_end_ts = $event_end_ts > $scope_datetime_end->format('U') ? $scope_datetime_end->format('U') : $event_end_ts;

				while( $event_start_ts <= $event_end_ts ) { //we loop until the last day of our time-range, not the end date of the event, which could be in a year
					//Ensure date is within event dates and also within the limits of events to show per day, if so add to eventful days array
					$event_eventful_date = date('Y-m-d', $event_start_ts);
					if( empty($eventful_days_count[$event_eventful_date]) || !$limit || $eventful_days_count[$event_eventful_date] < $limit ){
						//now we know this is an event that'll be used, convert it to an object
						if( empty($eventful_days[$event_eventful_date]) || !is_array($eventful_days[$event_eventful_date]) ) $eventful_days[$event_eventful_date] = array();
						//add event to array with a corresponding timestamp for sorting of times including long and all-day events
						$event_ts_marker = $event_start_ts;
                        //$event_ts_marker = (int) strtotime($event_eventful_date.' '.$event->start_time);
						while( !empty($eventful_days[$event_eventful_date][$event_ts_marker]) ){
							$event_ts_marker++; //add a second
						}
						$eventful_days[$event_eventful_date][$event_ts_marker] = $event;
					}
					//count events for that day
					$eventful_days_count[$event_eventful_date] = empty($eventful_days_count[$event_eventful_date]) ? 1 : $eventful_days_count[$event_eventful_date]+1;
					$event_start_ts += (86400); //add a day
				}
			}
		}
		foreach($eventful_days as $day_key => $events) {
			if( array_key_exists($day_key, $calendar_array['cells']) ){
				$calendar_array['cells'][$day_key]['events_count'] = $eventful_days_count[$day_key];
				$calendar_array['cells'][$day_key]['events'] = $events;
			}
		}

		$calendar_array['cal_count'] = count($calendar_array['cells']);
		$calendar_array['col_max'] = count($calendar_array['row_headers']);

		return $calendar_array;
	}





    public static function getTable( $args ) {
        $events_array = array();
		$events_array['data'] = array();
		$type = 'table';
        $year_now = $year = date('Y');

        if (isset($args['access']) && $args['access'] == 'public') {
            return $events_array;
        }

		$year = "";
		if( ! empty($args['year']) ){
			$year = $args['year'];
		}
		// Set default month and year if they were not provided
		if( !( is_numeric($year) ) ){
			$year = date('Y');
		}

		$term_map = self::get_term_map( $year );

		// Default term
		$term_now = $term = 1;
		if ( date($year . '-m-d') > $term_map[2]['start'] ) {
			$term_now = $term = 2;
		}
		if ( date($year . '-m-d') > $term_map[3]['start'] ) {
			$term_now = $term = 3;
		}
		if ( date($year . '-m-d') > $term_map[4]['start'] ) {
			$term_now = $term = 4;
		}

		// Get term if provided
		if( ! empty( $args['term'] ) ) {
			if ( $args['term'] >= 1 && $args['term'] <= 4 ) {
				$term = $args['term'];
			}
		}

        //query the database for events in this time span
		$scope_datetime_start = new DateTime($term_map[$term]['start']);
		$scope_datetime_end = new DateTime($term_map[$term]['end']);

		$term_last = $term-1;
		$term_next = $term+1;
		$year_last = $year; 
		$year_next = $year;
		
		if ( $term == 1 ) { 
		   $term_last = 4;
		   $year_last = $year - 1;
		} elseif ( $term == 4 ){
			$term_next = 1;
			$year_next = $year + 1; 
		}

        $previous = array('type'=>$args['type'], 'tm'=>$term_last, 'yr'=>$year_last);
		$next = array('type'=>$args['type'], 'tm'=>$term_next, 'yr'=>$year_next);

		$events_array['pagination'] = array( 'previous' => $previous, 'next' => $next);
		$events_array['type'] = $type;
		$events_array['term'] = $term;
		$events_array['term_last'] = $term_last;
		$events_array['term_next'] = $term_next;
		$events_array['year'] = $year;
		$events_array['year_last'] = $year_last;
		$events_array['year_next'] = $year_next;
		$events_array['curr_period'] = $year_now . $term_now;

		//$scope_datetime_start = new DateTime("{$year}-01-01");
		//$scope_datetime_end = new DateTime("{$year}-12-31");
		$events_args = array (
			'scope' => array( 
				'start' => $scope_datetime_start->format('Y-m-d'), 
				'end' => $scope_datetime_end->format('Y-m-d')
			),
		);

        $events = assessments_lib::get_assessments(json_decode(json_encode($events_args, JSON_FORCE_OBJECT)));
		$events_array['data'] = $events;
		return $events_array;
	}


    public static function get_term_map( $year ) {
        return array(
            1 => array(
                'start' => $year . '-01-01',
                'end' => $year . '-04-13',
            ),
            2 => array(
                'start' => $year . '-04-14',
                'end' => $year . '-06-29',
            ),
            3 => array(
                'start' => $year . '-06-30',
                'end' => $year . '-09-28',
            ),
            4 => array(
                'start' => $year . '-09-29',
                'end' => $year . '-12-31',
            ),
        );
    }



    public static function get_for_roll_creation($now, $startlimit) {
        global $DB;

        $sql = "SELECT *
                FROM {activities_assessments}
                WHERE classrollprocessed = 0
                AND (
                    (timestart <= {$startlimit} AND timestart >= {$now}) OR
                    (timestart <= {$now} AND timeend >= {$now})
                )";
    
        return array_values($DB->get_records_sql($sql));
    }


	public static function can_user_edit($assessment) {
        global $DB, $USER;

		if ($assessment->creator == $USER->username || 
			has_capability('moodle/site:config', \context_user::instance($USER->id)) ||
			$USER->username == '73445' || // B Robins
			$USER->username == '21213' || // G Maltby
			$USER->username == '68429' // A Hall
		) {
			return true;
		}

	    return false;
    }


}