<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/workflow.lib.php');
require_once(__DIR__.'/../lib/activities.lib.php');

use \local_announcements2\lib\workflow_lib;
use \local_announcements2\lib\activities_lib;

/**
 * Activity API trait
 */
trait workflow_api {

    /**
     * Get workflow info for activity.
     *
     * @return array results.
     */
    static public function get_workflow() {
        $id = required_param('id', PARAM_INT);
        return workflow_lib::get_workflow($id);
    }  
    
    /**
     * Get draft workflow based on campus.
     *
     * @return array results.
     */
    static public function get_draft_workflow() {
        $campus = required_param('campus', PARAM_TEXT);
        $activitytype = required_param('activitytype', PARAM_TEXT);
        $assessmentid = optional_param('assessmentid', 0, PARAM_INT);
        $isovernight = optional_param('isovernight', false, PARAM_BOOL);
        $staffincharge = optional_param('staffincharge', null, PARAM_TEXT);
        return workflow_lib::get_draft_workflow($activitytype, $campus, $assessmentid, $isovernight, $staffincharge);
    } 

    /**
     * Get workflow info for activity.
     *
     * @return array results.
     */
    static public function get_calendar_status() {
        $id = required_param('id', PARAM_INT);
        return workflow_lib::get_calendar_status($id);
    }  
    
    
    /**
    * Tick or untick an approval row.
    */
   static public function save_approval($args) { 
       ['activityid' => $activityid, 'approvalid' => $approvalid, 'status' => $status] = $args;
       return workflow_lib::save_approval($activityid, $approvalid, $status);
   }
       
   /**
    * Skip/unskip.
    */
    static public function skip_approval($args) { 
        ['activityid' => $activityid, 'approvalid' => $approvalid, 'skip' => $skip] = $args;
        return workflow_lib::save_skip($activityid, $approvalid, $skip);
    }

   /**
    * Select an approver.
    */
    static public function nominate_approver($args) { 
        ['activityid' => $activityid, 'approvalid' => $approvalid, 'nominated' => $nominated] = $args;
        return workflow_lib::nominate_approver($activityid, $approvalid, $nominated);
    }

    /**
    * Tick or untick approval.
    */
    static public function approve_cal_entry($args) { 
        ['activityid' => $activityid, 'approved' => $approved] = $args;
        return workflow_lib::approve_cal_entry($activityid, $approved);
    }
 
        /**
    * Tick or untick pushpublic.
    */
    static public function make_public_now($args) { 
        ['activityid' => $activityid, 'pushpublic' => $pushpublic] = $args;
        return workflow_lib::make_public_now($activityid, $pushpublic);
    }
    
}
