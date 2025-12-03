<?php

namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/risks.lib.php');
require_once(__DIR__.'/../lib/risk_versions.lib.php');

use \local_announcements2\lib\risks_lib;
use \local_announcements2\lib\risk_versions_lib;

/**
 * Risks API trait
 */
trait risks_api {


    /**
     * Get published risk version and classifications.
     *
     * @return array
     */
    static public function get_ra_classifications() {
        $activityid = required_param('id', PARAM_INT);
        $version = risk_versions_lib::get_published_version();
        $classifications = risks_lib::get_classifications_preselected($version, $activityid);
        return ['version' => $version, 'classifications' => $classifications];
    }

    /**
     * Save a risk assessment.
     *
     * @return object
     */
    static public function save_ra($args) {
        return risks_lib::save_ra((object) $args);
    }

    /**
     * Preview a risk assessment.
     *
     * @return object
     */
    static public function preview_ra() {
        $id = required_param('id', PARAM_INT);
        return risks_lib::preview_ra($id);
    }

    /**
     * Generate a preview for a risk assessment.
     * @param array $args
     * @return string
     */
    static public function generate_preview($args) {
        return risks_lib::generate_preview((object) $args);
    }

    /**
     * Generate a PDF for a risk assessment.
     *
     * @return object
     */
    static public function generate_pdf() {
        $id = required_param('id', PARAM_INT);
        return risks_lib::generate_pdf($id);
    }

    /**
     * Get a list of risk assessment generations for an activity.
     *
     * @return array
     */
    static public function get_ra_generations() {
        $activityid = required_param('id', PARAM_INT);
        return risks_lib::get_ra_generations($activityid);
    }

    /**
     * Get a single risk assessment by ID.
     *
     * @return object
     */
    static public function get_risk_assessment() {
        $id = required_param('id', PARAM_INT);
        return risks_lib::get_risk_assessment($id);
    }


    /**
     * Get the last risk assessment generation for an activity.
     *
     * @return object
     */
    static public function get_last_ra_gen() {
        $activityid = required_param('activityid', PARAM_INT);
        return risks_lib::get_last_ra_gen($activityid);
    }

    /**
     * Delete a risk assessment generation.
     *
     * @return object
     */
    static public function delete_ra_generation() {
        $id = required_param('id', PARAM_INT);
        return risks_lib::delete_ra_generation($id);
    }

    /**
     * Approve a risk assessment generation.
     *
     * @return object
     */
    static public function approve_ra_generation() {
        $id = required_param('id', PARAM_INT);
        $approved = required_param('approved', PARAM_INT);
        return risks_lib::approve_ra_generation($id, $approved);
    }

    /**
     * Get risks for a specific classification.
     *
     * @return array
     */
    static public function get_risks_for_classification() {
        $classification_id = required_param('classification_id', PARAM_INT);
        $version = required_param('version', PARAM_INT);
        $context = required_param('context', PARAM_RAW);
        $context = explode(',', $context);
        $context = array_map('intval', $context);
        return risks_lib::get_risks_for_classification($classification_id, $version, $context);
    }
}
