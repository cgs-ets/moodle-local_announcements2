<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/activities/config.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/service.lib.php');
require_once(__DIR__.'/workflow.lib.php');
require_once(__DIR__.'/risk_versions.lib.php');
require_once($CFG->dirroot . '/local/activities/vendor/autoload.php');

use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\workflow_lib;
use \local_announcements2\lib\risk_versions_lib;
use \moodle_exception;

/**
 * Risks lib
 */
class risks_lib {

    /** Table to store risk assessments. */
    const TABLE_RA_GENS = 'activities_ra_gens';
    const TABLE_RA_GENS_RISKS = 'activities_ra_gens_risks';

    public static function save_ra($data){
        global $DB;

        $data = json_decode(json_encode($data), false);
        $activityid = $data->activityid;
        $riskversion = $data->riskassessment->riskVersion;
        $classifications = $data->riskassessment->selectedClassifications;
        $customRisks = isset($data->customRisks) ? $data->customRisks : [];

        // Validate the data.
        if (empty($activityid) || empty($riskversion) || empty($classifications)) {
            throw new \Exception("Invalid risk assessment data.");
        }

        // Get the activity.
        $activity = new Activity($activityid);
        if (!$activity) {
            throw new \Exception("Activity not found.");
        }

        // Check if user can edit activity.
        if (!utils_lib::has_capability_edit_activity($activityid)) {
            throw new \Exception("You do not have permission to generate a risk assessment for this activity.");
        }

        try {
            // Prepare the additional fields data
            $additionalFields = [
                'activityid' => $activityid,
                'riskversion' => $riskversion,
                'classifications' => json_encode($classifications),
                'timecreated' => time(),
                'reason_for_activity' => isset($data->reasonForActivity) ? $data->reasonForActivity : '',
                'proposed_activities' => isset($data->proposedActivities) ? $data->proposedActivities : '',
                'anticipated_students' => isset($data->anticipatedStudents) ? intval($data->anticipatedStudents) : 0,
                'anticipated_adults' => isset($data->anticipatedAdults) ? intval($data->anticipatedAdults) : 0,
                'supervision_ratio' => isset($data->supervisionRatio) ? $data->supervisionRatio : '',
                'leader' => isset($data->leader) ? $data->leader : '',
                'leader_contact' => isset($data->leaderContact) ? $data->leaderContact : '',
                'second_in_charge' => isset($data->secondInCharge) ? $data->secondInCharge : '',
                'second_in_charge_contact' => isset($data->secondInChargeContact) ? $data->secondInChargeContact : '',
                'location_contact_person' => isset($data->locationContactPerson) ? $data->locationContactPerson : '',
                'location_contact_number' => isset($data->locationContactNumber) ? $data->locationContactNumber : '',
                'site_visit_reviewer' => isset($data->siteVisitReviewer) ? $data->siteVisitReviewer : '',
                'site_visit_date' => isset($data->siteVisitDate) ? $data->siteVisitDate : 0,
                'water_hazards_present' => isset($data->waterHazardsPresent) ? $data->waterHazardsPresent : '',
                'staff_qualifications' => isset($data->staffQualifications) ? $data->staffQualifications : '',
                'duration' => isset($data->duration) ? $data->duration : '',
                'proposed_route' => isset($data->proposedRoute) ? $data->proposedRoute : '',
            ];

            $id = $DB->insert_record(static::TABLE_RA_GENS, $additionalFields);

            // Save custom risks if any
            if (!empty($customRisks)) {
                foreach ($customRisks as $customRisk) {
                    $DB->insert_record(static::TABLE_RA_GENS_RISKS, [
                        'ra_gen_id' => $id,
                        'hazard' => $customRisk->hazard,
                        'riskrating_before' => $customRisk->riskrating_before,
                        'controlmeasures' => $customRisk->controlmeasures,
                        'riskrating_after' => $customRisk->riskrating_after,
                        'responsible_person' => $customRisk->responsible_person,
                        'control_timing' => $customRisk->control_timing,
                        'risk_benefit' => $customRisk->risk_benefit,
                    ]);
                }
            }

            static::generate_pdf($id);

        } catch (\Exception $e) {
            throw new \Exception("Failed to save risk assessment.");
        }


        return ['id' => $id, 'success' => true];
    }

    /**
     * Generate a risk assessment.
     *
     * @param object $data
     * @return array
     */
    public static function generate_pdf($id) {
        global $DB;
        
        $ra_gen = $DB->get_record(static::TABLE_RA_GENS, ['id' => $id]);
        if (!$ra_gen) {
            throw new \Exception("Risk assessment generation not found.");
        }
        $ra_gen->classifications = json_decode($ra_gen->classifications);
        // Generate the PDF based on the risk assessment JSON.
        $pdf = static::generate_pdf_from_ra($ra_gen);

        return $pdf;
    }


    public static function preview_ra($id) {
        global $DB;
        
        $ra_gen = $DB->get_record(static::TABLE_RA_GENS, ['id' => $id]);
        if (!$ra_gen) {
            throw new \Exception("Risk assessment generation not found.");
        }
        $ra_gen->classifications = json_decode($ra_gen->classifications);
        $custom_risks = array_values($DB->get_records(static::TABLE_RA_GENS_RISKS, ['ra_gen_id' => $ra_gen->id]));
        $ra_gen->custom_risks = $custom_risks;
        list($activity, $classifications) = static::prepare_ra_data($ra_gen);
        $htmlContent = static::generate_html($activity, $classifications);

        return $htmlContent;
    }


    public static function generate_preview($data){
        global $DB;

        $data = json_decode(json_encode($data), false);
        $activityid = $data->activityid;
        $riskversion = $data->riskassessment->riskVersion;
        $classifications = $data->riskassessment->selectedClassifications;
        $customRisks = isset($data->customRisks) ? $data->customRisks : [];

        // Validate the data.
        if (empty($activityid) || empty($riskversion) || empty($classifications)) {
            throw new \Exception("Invalid risk assessment data.");
        }

        // Get the activity.
        $activity = new Activity($activityid);
        if (!$activity) {
            throw new \Exception("Activity not found.");
        }

        // Check if user can edit activity.
        if (!utils_lib::has_capability_edit_activity($activityid)) {
            throw new \Exception("You do not have permission to generate a risk assessment for this activity.");
        }

        
            // Prepare the additional fields data
            $data = [
                'activityid' => $activityid,
                'riskversion' => $riskversion,
                'classifications' => $classifications,
                'custom_risks' => $customRisks,
                'timecreated' => time(),
                'reason_for_activity' => isset($data->reasonForActivity) ? $data->reasonForActivity : '',
                'proposed_activities' => isset($data->proposedActivities) ? $data->proposedActivities : '',
                'anticipated_students' => isset($data->anticipatedStudents) ? intval($data->anticipatedStudents) : 0,
                'anticipated_adults' => isset($data->anticipatedAdults) ? intval($data->anticipatedAdults) : 0,
                'supervision_ratio' => isset($data->supervisionRatio) ? $data->supervisionRatio : '',
                'leader' => isset($data->leader) ? $data->leader : '',
                'leader_contact' => isset($data->leaderContact) ? $data->leaderContact : '',
                'second_in_charge' => isset($data->secondInCharge) ? $data->secondInCharge : '',
                'second_in_charge_contact' => isset($data->secondInChargeContact) ? $data->secondInChargeContact : '',
                'location_contact_person' => isset($data->locationContactPerson) ? $data->locationContactPerson : '',
                'location_contact_number' => isset($data->locationContactNumber) ? $data->locationContactNumber : '',
                'site_visit_reviewer' => isset($data->siteVisitReviewer) ? $data->siteVisitReviewer : '',
                'site_visit_date' => isset($data->siteVisitDate) ? $data->siteVisitDate : 0,
                'water_hazards_present' => isset($data->waterHazardsPresent) ? $data->waterHazardsPresent : '',
                'staff_qualifications' => isset($data->staffQualifications) ? $data->staffQualifications : '',
                'duration' => isset($data->duration) ? $data->duration : '',
                'proposed_route' => isset($data->proposedRoute) ? $data->proposedRoute : '',
            ];

            try {
                list($activity, $classifications) = static::prepare_ra_data((object) $data);
                $htmlContent = static::generate_html($activity, $classifications);
            } catch (\Exception $e) {
                throw new \Exception("Failed to generate preview.");
            }

            return $htmlContent;
    }



    /**
     * Generate the PDF based on the risk assessment JSON.
     *
     * @param object $ra_gen
     * @return string
     */
    public static function generate_pdf_from_ra($ra_gen) {
        global $DB, $USER;

        $custom_risks = array_values($DB->get_records(static::TABLE_RA_GENS_RISKS, ['ra_gen_id' => $ra_gen->id]));
        $ra_gen->custom_risks = $custom_risks;

        list($activity, $classifications) = static::prepare_ra_data($ra_gen);
        $htmlContent = static::generate_html($activity, $classifications);

        // Normalize the text.
        $htmlContent = utils_lib::normalize_text($htmlContent);

        //$exportdir = str_replace('\\\\', '\\', $CFG->dataroot) . '\local_announcements2\exports\\';
        //$htmlfile = $exportdir . $ra_gen->id . ".html";
        //$htmlFile = 'html_risk_assessment.html';
        //file_put_contents($htmlFile, $htmlContent);

        // Create Dompdf instance
        $dompdf = new \Dompdf\Dompdf();
        
        // Set options
        $dompdf->setOptions(new \Dompdf\Options([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'Arial',
            'dpi' => 96,
            'margin_top' => 30,
            'margin_right' => 30,
            'margin_bottom' => 30,
            'margin_left' => 30,
        ]));
        
        // Load HTML content
        $dompdf->loadHtml($htmlContent);
        
        // Set paper size and orientation
        $dompdf->setPaper('A4', 'landscape');
        
        // Render the PDF
        $dompdf->render();
        
        // Save PDF file
        $filename = 'pdf_risk_assessment_'. $activity->id .'_'. $ra_gen->id .'_'. date('Y-m-d-H-i-s', time()) . '_' . $USER->id . '.pdf';
        $fileinfo = [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_announcements2',
            'filearea' => 'ra_generations',
            'itemid' => $ra_gen->id,
            'filepath' => '/',
            'filename' => $filename,
        ];
        //file_put_contents($pdfFile, $dompdf->output());
        $fs = get_file_storage();
        $fs->create_file_from_string(
            $fileinfo,
            $dompdf->output()
        );
        
        // Clean up
        unset($dompdf);
    }


    private static function prepare_ra_data($ra_gen) {
        global $DB;

        $activity = new Activity($ra_gen->activityid);
        if (!$activity) {
            throw new \Exception("Activity not found.");
        }
        $activity = $activity->export();

        // Append additional fields to activity.
        $activity = (object) array_merge((array) $activity, (array) $ra_gen);
        $activity->site_visit_date = $activity->site_visit_date ? date('Y-m-d', $activity->site_visit_date) : '';

        $selected_classification_ids = $ra_gen->classifications;

        // Get the includes for the selected classifications.
        $includes = risk_versions_lib::get_includes_for_classifications($selected_classification_ids, $ra_gen->riskversion);

        // Get the risks for the classifications.
        $risks = static::get_risks_for_classifications($selected_classification_ids, $ra_gen->riskversion, $includes);
        
        // Extract the classification records from the risks.
        $classifications_in_risks = array_column($risks, 'classifications');
        
        // Group risks by classification. 
        $risks_by_classification = [];
        $risks_processed = [];
        foreach ($risks as $risk) {
            // Skip risks that do not have hazard text.
            if (empty($risk->hazard)) {
                continue;
            }

            foreach ($risk->classifications as $classification) {

                // RISKS DO NOT APPEAR IN CONTEXTS!
                if ($classification->type === 'context') {
                    continue;
                }

                // If the risk has a qualifying set, and the qualifying set is not in the selected classifications, skip it.
                if (isset($risk->qualifying_set) && !in_array($classification->id, $risk->qualifying_set)) {
                    continue;
                }

                // Only add the risk if it hasn't already been added.
                if (in_array($risk->id, $risks_processed)) {
                    break;
                }

                $risks_by_classification[$classification->id][] = $risk;

                // Keep track of risks that have been added to an array because I only want to add each risk once.
                $risks_processed[] = $risk->id;
            }
        }

        // This causes the classifications to be sorted by the sortorder, because get_classifications returns them in the order of the sortorder.
        $all_classifications = risk_versions_lib::get_classifications($ra_gen->riskversion);
        $used_classifications = array_filter($all_classifications, function($classification) use ($risks_by_classification) {
            return isset($risks_by_classification[$classification->id]);
        });
        
        foreach ($used_classifications as $classification) {
            $classification->risks = $risks_by_classification[$classification->id];
            $classification->risks_count = count($classification->risks);
            $classification->risks_count_string = $classification->risks_count . ' ' . ($classification->risks_count === 1 ? 'risk' : 'risks');
        }

        // Add custom risks to the classifications.
        if (!empty($ra_gen->custom_risks)) {
            $used_classifications[] = (object) [
                'name' => 'Additional Risks',
                'risks' => $ra_gen->custom_risks,
                'risks_count' => count($ra_gen->custom_risks),
                'risks_count_string' => count($ra_gen->custom_risks) . ' ' . (count($ra_gen->custom_risks) === 1 ? 'risk' : 'risks'),
            ];
        }

        return [$activity, $used_classifications];

    }

    /**
     * Get the risks for the risk version that match the classifications
     *
     * @param array $selected
     * @param int $riskversion
     * @return array
     */
    private static function get_risks_for_classifications($selected, $riskversion, $includes = []) {
        global $DB;
        // First get all the risks for this version.
        $risks = risk_versions_lib::get_risks_with_classifications($riskversion);
        $standard_classifications = risk_versions_lib::get_classifications($riskversion, true);
        $standard_classification_ids = array_column($standard_classifications, 'id');


        // Then filter the risks to only include those that match the classifications.
        $risks = array_filter($risks, function($risk) use ($selected, $standard_classification_ids, $includes) {
            if (empty($risk->classification_sets)) {
                // No classification sets were defined for this risk.
                return false;
            }


            // Check if ANY of the classification sets match the selected classifications or includes.
            foreach ($risk->classification_sets as $classification_set) {

                // Exclude standard classifications from the check as they are "selected" by default.
                $classification_set_non_standard = array_diff($classification_set, $standard_classification_ids);

                
                if (empty($classification_set_non_standard)) {
                    // If nothing left to select, this is a standard classification.
                    return true;
                }

                // Check if all of the risk's classifications in this set are in the selected classifications.
                // Get the classifications in common.
                $classifications_in_common = array_intersect($classification_set_non_standard, $selected);

                // Check if the number of classifications in common is the same as the number of classifications in the risk set.
                // Example 1: 
                // - The risk has set ["K-2", "Walk"], and the selected are ["K-2", "Walk", "Playground"].
                //   - The number of common classifications is 2, and the number of classifications in the risk set is 2.
                //   - So the risk should be kept.
                // Example 2: 
                // - The risk has set ["K-2", "Walk", "Pool"], and the selected are ["K-2", "Walk", "Playground"].
                //   - The number of common classifications is 2, and the number of classifications in the risk set is 3.
                //   - So this set doesn't match, but we continue checking other sets.
                
                if (count($classifications_in_common) === count($classification_set_non_standard)) {
                    $risk->qualifying_set = $classification_set;
                    return true; // This set matches, so include the risk
                }

                // Check if any of the includes match the classification set.
                foreach ($includes as $include) {
                    if (count(array_intersect($classification_set, $include)) === count($classification_set)) {
                        $risk->qualifying_set = $classification_set;
                        return true; // This set matches, so include the risk
                    }
                }


            }
            
            return false; // No sets matched
        });


        /*if ($risk->hazard === 'Medical Issues - Illness - existing medical condition requiring assistance while on the excursion - including Asthma and Anaphylaxis') {
            var_export($selected);
            var_export($classifications_in_common);
            var_export($classification_set_non_standard);
            exit;
        }*/

        return $risks;
    }


    private static function generate_html($activity, $classifications) {
        global $OUTPUT;

        $ristmatriximage = __DIR__ . '/../../images/risk-matrix-test.jpg';
        $ristmatriximagedata = file_get_contents($ristmatriximage);
        $ristmatriximagebase64 = 'data:image/jpg;base64,' . base64_encode($ristmatriximagedata);

        
        $headerimage = __DIR__ . '/../../images/header.jpg';
        $headerimagedata = file_get_contents($headerimage);
        $headerimagebase64 = 'data:image/jpg;base64,' . base64_encode($headerimagedata);

        
        $data = [
            'activity' => $activity,
            'classifications' => array_values($classifications),
            'risk_matrix_image' => $ristmatriximagebase64,
            'header_image' => $headerimagebase64,
        ];
        return $OUTPUT->render_from_template('local_announcements2/risk_assessment', $data);
    }


    public static function get_classifications_preselected($version, $activityid) {
        global $DB;
        $classifications = risk_versions_lib::get_classifications($version);
        $activity = new Activity($activityid);
        $activity = $activity->export();

        // Do not show excursion or incursion. These are not selectable.
        $classifications = array_map(function($classification) {
            if ($classification->name === 'Excursion' || 
                $classification->name === 'Incursion' || 
                $classification->name === 'Commercial'
            ) {
                $classification->hidden = true;
            }
            return $classification;
        }, $classifications);

        // Pre-select the classifications for the activity type.
        if ($activity->activitytype === 'excursion') {
            // Search for the classification with the name "Excursion" and set the "preselected" property to true.
            $classificationix = array_search('Excursion', array_column($classifications, 'name'));
            $classifications[$classificationix]->preselected = true;
        } else if ($activity->activitytype === 'incursion') {
            // Search for the classification with the name "Incursion" and set the "preselected" property to true.
            $classificationix = array_search('Incursion', array_column($classifications, 'name'));
            $classifications[$classificationix]->preselected = true;
        } else if ($activity->activitytype === 'commercial') {
            // Search for the classification with the name "Commercial" and set the "preselected" property to true.
            $classificationix = array_search('Commercial', array_column($classifications, 'name'));
            $classifications[$classificationix]->preselected = true;
        }

        // Get the latest ra generation for the activity to prefill the selections based on that.
        $previous_selections = [];
        $ra_generation = $DB->get_records(static::TABLE_RA_GENS, ['activityid' => $activityid, 'deleted' => 0], 'timecreated DESC', '*', 0, 1);
        if ($ra_generation) {
            $ra_generation = reset($ra_generation);
            $previous_selections = json_decode($ra_generation->classifications);
        }

        foreach ($classifications as &$classification) {
            // Pre-select the standard classifications.
            if ($classification->isstandard) {
                $classification->preselected = true;
            }
            // Pre-select the classifications that were selected in the previous ra generation.
            if (in_array($classification->id, $previous_selections)) {
                $classification->preselected = true;
            }
        }

        return $classifications;
    }

    /**
     * Add risk counts to classifications
     *
     * @param array $classifications
     * @param int $version
     * @return array
     */
    /*private static function add_risk_counts_to_classifications($classifications, $version, $context) {
        // Get all risks for this version
        $all_risks = risk_versions_lib::get_risks_with_classifications($version);
        
        // Count risks for each classification
        foreach ($classifications as &$classification) {
            $risk_count = 0;
            
            // Only count risks for hazard classifications (not contexts)
            if ($classification->type === 'hazard') {
                foreach ($all_risks as $risk) {
                    // Check if this risk is associated with this classification
                    if (in_array($classification->id, $risk->classification_ids)) {
                        $risk_count++;
                    }
                }
            }
            
            $classification->risks_count = $risk_count;
            $classification->risks_count_string = $risk_count . ' ' . ($risk_count === 1 ? 'risk' : 'risks');
        }
        
        return $classifications;
    }*/

    /**
     * Get risks for a specific classification
     *
     * @param int $classification_id
     * @param int $version
     * @param array $contexts
     * @return array
     */
    public static function get_risks_for_classification($classification_id, $version, $context = []) {
        // Get all risks for this version
        $all_risks = risk_versions_lib::get_risks_with_classifications($version);
        
        // Filter risks that are associated with this classification
        $classification_risks = array_filter($all_risks, function($risk) use ($classification_id, $context) {
            return in_array($classification_id, $risk->classification_ids) && static::isContextSelected($risk, $context, $classification_id);
        });
        
        return array_values($classification_risks);
    }

    private static function isContextSelected($risk, $context, $subject_classification_id) {
        if (empty($context)) {
            // No contexts were selected.
            return false;
        }

        if (empty($risk->classification_sets)) {
            // No classification sets were defined for this risk.
            return false;
        }
        
        // Check if ANY of the classification sets match the selected contexts
        foreach ($risk->classification_sets as $classification_set) {
            // Remove the subject classification from the classification set.
            $classification_set = array_diff($classification_set, [$subject_classification_id]);
            
            // Check if all of the risk's classifications in this set are in the selected contexts
            $classifications_in_common = array_intersect($classification_set, $context);
            
            // Check if the number of classifications in common is the same as the number of classifications in the risk set
            if (count($classifications_in_common) === count($classification_set)) {
                return true; // This set matches, so include the risk
            }
        }
        
        return false; // No sets matched
    }

    public static function get_ra_generations($activityid) {
        global $DB, $CFG;

        $ra_generations = $DB->get_records(static::TABLE_RA_GENS, ['activityid' => $activityid, 'deleted' => 0], 'timecreated DESC');
        foreach ($ra_generations as $ra_generation) {
            // Classifications
            $classification_ids = json_decode($ra_generation->classifications);
            [$insql, $inparams] = $DB->get_in_or_equal($classification_ids);
            $sql = "SELECT * FROM {activities_classifications} WHERE id $insql";
            $ra_generation->classifications = array_values($DB->get_records_sql($sql, $inparams));

            // Custom risks
            $custom_risks = $DB->get_records(static::TABLE_RA_GENS_RISKS, ['ra_gen_id' => $ra_generation->id]);
            $ra_generation->custom_risks = array_values($custom_risks);

            // Map additional fields to frontend field names
            $ra_generation->reasonForActivity = $ra_generation->reason_for_activity ?? '';
            $ra_generation->proposedActivities = $ra_generation->proposed_activities ?? '';
            $ra_generation->anticipatedStudents = $ra_generation->anticipated_students ?? 0;
            $ra_generation->anticipatedAdults = $ra_generation->anticipated_adults ?? 0;
            $ra_generation->supervisionRatio = $ra_generation->supervision_ratio ?? '';
            $ra_generation->leader = $ra_generation->leader ?? '';
            $ra_generation->leaderContact = $ra_generation->leader_contact ?? '';
            $ra_generation->secondInCharge = $ra_generation->second_in_charge ?? '';
            $ra_generation->secondInChargeContact = $ra_generation->second_in_charge_contact ?? '';
            $ra_generation->locationContactPerson = $ra_generation->location_contact_person ?? '';
            $ra_generation->locationContactNumber = $ra_generation->location_contact_number ?? '';
            $ra_generation->siteVisitReviewer = $ra_generation->site_visit_reviewer ?? '';
            $ra_generation->siteVisitDate = $ra_generation->site_visit_date ? date('Y-m-d', $ra_generation->site_visit_date) : '';
            $ra_generation->waterHazardsPresent = $ra_generation->water_hazards_present ?? '';
            $ra_generation->staffQualifications = $ra_generation->staff_qualifications ?? '';
            $ra_generation->duration = $ra_generation->duration ?? '';
            $ra_generation->proposedRoute = $ra_generation->proposed_route ?? '';

            // Download url
            $fs = get_file_storage();
            $files = $fs->get_area_files(1, 'local_announcements2', 'ra_generations', $ra_generation->id, "filename", false);
            if ($files) {
                foreach ($files as $file) {
                    $ra_generation->download_url = $CFG->wwwroot . '/pluginfile.php/1/local_announcements2/ra_generations/' . $ra_generation->id . '/' . $file->get_filename();
                    break;
                }
            }
        }
        return array_values($ra_generations);
    }

    /**
     * Get a single risk assessment by ID.
     *
     * @param int $id
     * @return object
     */
    public static function get_risk_assessment($id) {
        global $DB, $CFG;

        $ra_generation = $DB->get_record(static::TABLE_RA_GENS, ['id' => $id]);
        if (!$ra_generation) {
            return null;
        }

        // Classifications
        $classification_ids = json_decode($ra_generation->classifications);
        [$insql, $inparams] = $DB->get_in_or_equal($classification_ids);
        $sql = "SELECT * FROM {activities_classifications} WHERE id $insql";
        $ra_generation->classifications = array_values($DB->get_records_sql($sql, $inparams));

        // Custom risks
        $custom_risks = $DB->get_records(static::TABLE_RA_GENS_RISKS, ['ra_gen_id' => $ra_generation->id]);
        $ra_generation->custom_risks = array_values($custom_risks);

        // Map additional fields to frontend field names
        $ra_generation->reasonForActivity = $ra_generation->reason_for_activity ?? '';
        $ra_generation->proposedActivities = $ra_generation->proposed_activities ?? '';
        $ra_generation->anticipatedStudents = $ra_generation->anticipated_students ?? 0;
        $ra_generation->anticipatedAdults = $ra_generation->anticipated_adults ?? 0;
        $ra_generation->supervisionRatio = $ra_generation->supervision_ratio ?? '';
        $ra_generation->leader = $ra_generation->leader ?? '';
        $ra_generation->leaderContact = $ra_generation->leader_contact ?? '';
        $ra_generation->secondInCharge = $ra_generation->second_in_charge ?? '';
        $ra_generation->secondInChargeContact = $ra_generation->second_in_charge_contact ?? '';
        $ra_generation->locationContactPerson = $ra_generation->location_contact_person ?? '';
        $ra_generation->locationContactNumber = $ra_generation->location_contact_number ?? '';
        $ra_generation->siteVisitReviewer = $ra_generation->site_visit_reviewer ?? '';
        $ra_generation->siteVisitDate = $ra_generation->site_visit_date ? date('Y-m-d', $ra_generation->site_visit_date) : '';
        $ra_generation->waterHazardsPresent = $ra_generation->water_hazards_present ?? '';
        $ra_generation->staffQualifications = $ra_generation->staff_qualifications ?? '';
        $ra_generation->duration = $ra_generation->duration ?? '';
        $ra_generation->proposedRoute = $ra_generation->proposed_route ?? '';
        
        // Download url
        $fs = get_file_storage();
        $files = $fs->get_area_files(1, 'local_announcements2', 'ra_generations', $ra_generation->id, "filename", false);
        if ($files) {
            foreach ($files as $file) {
                $ra_generation->download_url = $CFG->wwwroot . '/pluginfile.php/1/local_announcements2/ra_generations/' . $ra_generation->id . '/' . $file->get_filename();
                break;
            }
        }

        return $ra_generation;
    }

    /**
     * Get the last risk assessment generation for an activity.
     *
     * @param int $activityid
     * @return object
     */
    public static function get_last_ra_gen($activityid) {
        global $DB;

        // Get the latest ra generation for the activity.
        $ra_generation = $DB->get_records(static::TABLE_RA_GENS, ['activityid' => $activityid, 'deleted' => 0], 'timecreated DESC', '*', 0, 1);
        if (!$ra_generation) {
            return null;
        }
        $ra_generation = reset($ra_generation);

        // Get the custom risks for this generation.
        $custom_risks = $DB->get_records(static::TABLE_RA_GENS_RISKS, ['ra_gen_id' => $ra_generation->id]);
        $ra_generation->custom_risks = array_values($custom_risks);

        return  $ra_generation;
    }


    /**
     * Delete a risk assessment generation.
     *
     * @param int $id
     * @return object
     */
    public static function delete_ra_generation($id) {
        global $DB;

        // Get the RA generation.
        $ra_generation = $DB->get_record(static::TABLE_RA_GENS, ['id' => $id]);
        if (!$ra_generation) {
            return false;
        }

        // Check if the user has the capability to delete RA generations.
        if (!utils_lib::has_capability_edit_activity($ra_generation->activityid)) {
            return false;
        }

        // Delete the RA generation.
        $ra_generation->deleted = 1;
        return $DB->update_record(static::TABLE_RA_GENS, $ra_generation);
    }


    /**
     * Approve a risk assessment generation.
     *
     * @param int $id
     * @return object
     */
    public static function approve_ra_generation($id, $approved) {
        global $DB;

        // Get the RA generation.
        $ra_generation = $DB->get_record(static::TABLE_RA_GENS, ['id' => $id]);
        if (!$ra_generation) {
            return false;
        }

        // Check if the user is an approver.
        if (!utils_lib::is_user_approver()) {
            return false;
        }

        if ($approved) {
            // Remove approval from all RA generations for this activity.
            $sql = "UPDATE {activities_ra_gens} SET approved = 0 WHERE activityid = :activityid";
            $DB->execute($sql, ['activityid' => $ra_generation->activityid]);

            // Approve the RA generation.
            $ra_generation->approved = 1;
            return $DB->update_record(static::TABLE_RA_GENS, $ra_generation);
        } else {
            // Remove approval from the RA generation.
            $ra_generation->approved = 0;
            return $DB->update_record(static::TABLE_RA_GENS, $ra_generation);
        }
       
    }
}