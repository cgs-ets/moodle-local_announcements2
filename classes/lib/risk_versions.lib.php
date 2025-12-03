<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/activities/config.php');
require_once(__DIR__.'/utils.lib.php');
require_once(__DIR__.'/service.lib.php');
require_once(__DIR__.'/workflow.lib.php');
require_once(__DIR__.'/risks.lib.php');

use \local_announcements2\lib\utils_lib;
use \local_announcements2\lib\service_lib;
use \local_announcements2\lib\workflow_lib;
use \local_announcements2\lib\risks_lib;
use \moodle_exception;
use Caxy\HtmlDiff\HtmlDiff;

/**
 * Risk Versions lib - Handles version control for risks and classifications
 */
class risk_versions_lib {

    /** Table to store risk versions. */
    const TABLE_RISK_VERSIONS = 'activities_risk_versions';
    
    /** Table to store risks. */
    const TABLE_RISKS = 'activities_risks';
    
    /** Table to store classifications. */
    const TABLE_CLASSIFICATIONS = 'activities_classifications';
    
    /** Table to store risk classification sets. */
    const TABLE_RISK_CLASSIFICATION_SETS = 'activities_risk_classification_sets';
    
    /** Table to store risk classification set members. */
    const TABLE_RISK_CLASSIFICATION_SET_MEMBERS = 'activities_risk_classification_set_members';

    /** Table to store classifications contexts relationships. */
    const TABLE_CLASSIFICATIONS_CONTEXTS = 'activities_classifications_contexts';

    public static function check_risk_settings_access() {
        global $USER;
        if (   $USER->username != '43563' // Michael
            && $USER->username != 'admin' // Local admin
            && $USER->username != '57056' // Erum
            && $USER->username != '61460' // Kristen
            && $USER->username != '67769' // Anna
        ) {
            throw new \Exception("Permission denied.");
        }
        //if (! has_capability('moodle/site:config', \context_user::instance($USER->id))) {
        //    throw new \Exception("Permission denied.");
        //}
    }

    /**
     * Get the current published version.
     *
     * @return int|null
     */
    public static function get_published_version() {
        global $DB;
        
        $record = $DB->get_record(static::TABLE_RISK_VERSIONS, ['is_published' => 1]);
        return $record ? $record->version : null;
    }

    /**
     * Get the current draft version (next version number).
     *
     * @return int
     */
    public static function get_draft_version() {
        global $DB;
        
        $maxversion = $DB->get_field_sql("SELECT MAX(version) FROM {" . static::TABLE_RISK_VERSIONS . "}");
        return ($maxversion ? $maxversion + 1 : 1);
    }

    /**
     * Get all versions with their status.
     *
     * @return array
     */
    public static function get_versions() {
        global $DB;
        
        $records = array_values($DB->get_records(static::TABLE_RISK_VERSIONS, [], 'version DESC'));

        if (empty($records)) {
            return [];
        }

        foreach ($records as &$record) {
            $record->has_been_used = static::has_been_used($record->version) ? 1 : 0;
        }

        service_lib::cast_fields($records, [
            'version' => 'int',
            'is_published' => 'int',
            'timepublished' => 'int',
            'timecreated' => 'int',
            'has_been_used' => 'int',
        ]);
        
        return $records;
    }

    /**
     * Get version details including counts of risks and classifications.
     *
     * @param int $version
     * @return object
     */
    public static function get_version_details($version) {
        global $DB;
        
        $version_record = $DB->get_record(static::TABLE_RISK_VERSIONS, ['version' => $version]);
        if (!$version_record) {
            throw new \Exception("Version not found.");
        }
        
        $risk_count = $DB->count_records(static::TABLE_RISKS, ['version' => $version]);
        $classification_count = $DB->count_records(static::TABLE_CLASSIFICATIONS, ['version' => $version]);
        
        $version_record->risk_count = $risk_count;
        $version_record->classification_count = $classification_count;
        
        return $version_record;
    }

    /**
     * Delete a version and all its data.
     *
     * @param int $version
     * @return array
     */
    public static function delete_version($version) {
        global $DB;
        
        $version_record = $DB->get_record(static::TABLE_RISK_VERSIONS, ['version' => $version]);
        if (!$version_record) {
            throw new \Exception("Version not found.");
        }
        
        if ($version_record->is_published) {
            throw new \Exception("Cannot delete published version.");
        }

        // If the version is used by any activities, throw an error.
        $used = static::has_been_used($version);
        if ($used) {
            throw new \Exception("Cannot delete version as it is used by existing activities to generate a risk assessment.");
        }
        
        // Delete all data for this version
        $DB->delete_records(static::TABLE_RISKS, ['version' => $version]);
        $DB->delete_records(static::TABLE_CLASSIFICATIONS, ['version' => $version]);
        $DB->delete_records(static::TABLE_RISK_VERSIONS, ['version' => $version]);
        
        return ['success' => true];
    }

    /**
     * Check if there are any changes in the current draft compared to published version.
     *
     * @return bool
     */
    public static function has_draft_changes() {
        global $DB;
        
        $published_version = self::get_published_version();
        $draft_version = self::get_draft_version() - 1;
        
        if (!$published_version || !$draft_version) {
            return false;
        }
        
        // Compare risks
        $published_risks = $DB->get_records(static::TABLE_RISKS, ['version' => $published_version]);
        $draft_risks = $DB->get_records(static::TABLE_RISKS, ['version' => $draft_version]);
        
        if (count($published_risks) !== count($draft_risks)) {
            return true;
        }
        
        // Compare classifications
        $published_classifications = $DB->get_records(static::TABLE_CLASSIFICATIONS, ['version' => $published_version]);
        $draft_classifications = $DB->get_records(static::TABLE_CLASSIFICATIONS, ['version' => $draft_version]);
        
        if (count($published_classifications) !== count($draft_classifications)) {
            return true;
        }
        
        // TODO: Add more detailed comparison logic if needed
        
        return false;
    }

    /**
     * Get the latest version (either published or draft).
     * This is what should be loaded by default.
     *
     * @return int
     */
    public static function get_latest_version() {
        global $DB;
        
        $maxversion = $DB->get_field_sql("SELECT MAX(version) FROM {" . static::TABLE_RISK_VERSIONS . "}");
        return (int) $maxversion;
    }

    /**
     * Check if a version is published.
     *
     * @param int $version
     * @return bool
     */
    public static function is_version_published($version) {
        global $DB;
        
        $record = $DB->get_record(static::TABLE_RISK_VERSIONS, ['version' => $version]);
        return $record ? (bool)$record->is_published : false;
    }

    /**
     * Get the working version.
     *
     * @param int $version
     * @return bool
     */
    public static function has_been_used($version) {
        global $DB;
        $used = $DB->record_exists('activities_ra_gens', ['riskversion' => $version]);
        return $used;
    }

    /**
     * Ensure we're working on a draft version.
     * If the current working version is published, create a new draft.
     *
     * @return int The version to work on
     */
    public static function ensure_draft_version($version) {
        // If this version is published, create a new draft version.
        if (self::is_version_published($version)) {
            return self::create_draft_version($version);
        }
        
        // Otherwise, return the version.
        return $version;
    }

    /**
     * Create initial version (version 1).
     *
     * @return int
     */
    private static function create_initial_version() {
        global $DB;
        
        $version = 1;
        
        // Create version record
        $DB->insert_record(static::TABLE_RISK_VERSIONS, [
            'version' => $version,
            'is_published' => 0,
            'published_by' => null,
            'timepublished' => 0,
            'timecreated' => time(),
            'description' => 'Initial version'
        ]);
        
        return $version;
    }

    /**
     * Create initial version (version 1).
     *
     * @return int
     */
    public static function create_version_entry($version) {
        global $DB;
        $DB->insert_record(static::TABLE_RISK_VERSIONS, [
            'version' => $version,
            'is_published' => 0,
            'published_by' => null,
            'timepublished' => 0,
            'timecreated' => time(),
            'description' => 'Draft version'
        ]);
    }

    /**
     * Create a new draft version by copying the current working version.
     *
     * @return int The new version number
     */
    public static function create_draft_version($version) {
        global $DB;
        
        $latest_version = self::get_latest_version();
        $new_version = $latest_version + 1;

        // Copy risks from current version and track ID mappings
        $risks = $DB->get_records(static::TABLE_RISKS, ['version' => $version]);
        $risk_id_mapping = []; // old_id => new_id
        
        foreach ($risks as $risk) {
            $old_risk_id = $risk->id;
            unset($risk->id);
            $risk->version = $new_version;
            $new_risk_id = $DB->insert_record(static::TABLE_RISKS, $risk);
            $risk_id_mapping[$old_risk_id] = $new_risk_id;
        }
        
        // Copy classifications from current version and track ID mappings
        $classifications = $DB->get_records(static::TABLE_CLASSIFICATIONS, ['version' => $version]);
        $classification_id_mapping = []; // old_id => new_id
        
        foreach ($classifications as $classification) {
            $old_classification_id = $classification->id;
            unset($classification->id);
            $classification->version = $new_version;
            $new_classification_id = $DB->insert_record(static::TABLE_CLASSIFICATIONS, $classification);
            $classification_id_mapping[$old_classification_id] = $new_classification_id;
        }

        // Copy risk classification sets with updated IDs
        $risk_classification_sets = $DB->get_records(static::TABLE_RISK_CLASSIFICATION_SETS, ['version' => $version]);
        $set_id_mapping = []; // old_set_id => new_set_id
        
        foreach ($risk_classification_sets as $rcs) {
            $old_set_id = $rcs->id;
            unset($rcs->id);
            $rcs->version = $new_version;
            
            // Update riskid to reference the newly copied risk
            if (isset($risk_id_mapping[$rcs->riskid])) {
                $rcs->riskid = $risk_id_mapping[$rcs->riskid];
            }
            
            $new_set_id = $DB->insert_record(static::TABLE_RISK_CLASSIFICATION_SETS, $rcs);
            $set_id_mapping[$old_set_id] = $new_set_id;
        }

        // Copy risk classification set members with updated IDs
        $risk_classification_set_members = $DB->get_records(static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS, ['version' => $version]);
        foreach ($risk_classification_set_members as $rcsm) {
            unset($rcsm->id);
            $rcsm->version = $new_version;
            
            // Update set_id to reference the newly copied set
            if (isset($set_id_mapping[$rcsm->set_id])) {
                $rcsm->set_id = $set_id_mapping[$rcsm->set_id];
            }
            
            // Update classificationid to reference the newly copied classification
            if (isset($classification_id_mapping[$rcsm->classificationid])) {
                $rcsm->classificationid = $classification_id_mapping[$rcsm->classificationid];
            }
            
            $DB->insert_record(static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS, $rcsm);
        }

        // Copy classification-context relationships with updated IDs
        /*$classification_contexts = $DB->get_records(static::TABLE_CLASSIFICATIONS_CONTEXTS, ['version' => $version]);
        foreach ($classification_contexts as $cc) {
            unset($cc->id);
            $cc->version = $new_version;

            // Update contextid and classificationid to reference the newly copied context and classification
            $cc->contextid = $classification_id_mapping[$cc->contextid];
            $cc->classificationid = $classification_id_mapping[$cc->classificationid];
            
            $DB->insert_record(static::TABLE_CLASSIFICATIONS_CONTEXTS, $cc);
        }*/

        // Create version record
        $DB->insert_record(static::TABLE_RISK_VERSIONS, [
            'version' => $new_version,
            'is_published' => 0,
            'published_by' => null,
            'timepublished' => 0,
            'timecreated' => time(),
            'description' => 'Draft version'
        ]);
        
        return $new_version;
    }

    /**
     * Publish the current working version.
     *
     * @param string $description Optional description of the version
     * @return array
     */
    public static function publish_version($version) {
        global $DB, $USER;
        
        // Check if version exists
        $version_record = $DB->get_record(static::TABLE_RISK_VERSIONS, ['version' => $version]);
        if (!$version_record) {
            throw new \Exception("Version not found.");
        }
        
        // Unpublish any currently published version
        $DB->set_field(static::TABLE_RISK_VERSIONS, 'is_published', 0, ['is_published' => 1]);

        $version_record->is_published = 1;
        $version_record->published_by = $USER->username;
        $version_record->timepublished = time();
        $version_record->description = 'Published version';
        $DB->update_record(static::TABLE_RISK_VERSIONS, $version_record);
        
        return ['success' => true, 'version' => $version];
    }


    /**
     * Create or update a risk classification.
     *
     * @param object $data
     * @return array
     */
    public static function save_classification($data) {
        global $DB;

        $data = (object) $data;

        // If editing icon, update the icon only.
        if ($data->editingIcon) {
            $DB->update_record(static::TABLE_CLASSIFICATIONS, ['id' => $data->id, 'icon' => $data->icon]);
            return ['success' => true];
        }

        // Cannot edit a version that has been used.
        if (static::has_been_used($data->version)) {
            throw new \Exception("Cannot update classification as it has been used in an activity. Fork and make changes to the draft version instead.");
        }
        
        // Check if name already exists in this version (excluding current record if updating)
        $existing = $DB->get_record(static::TABLE_CLASSIFICATIONS, ['name' => $data->name, 'version' => $data->version]);
        if ($existing && (!isset($data->id) || $existing->id != $data->id)) {
            throw new \Exception("A classification with this name already exists in this version.");
        }

        //$contexts = $data->contexts;
        //unset($data->contexts);
        //$includes = $data->includes;
        //unset($data->includes);

        if (isset($data->id) && $data->id) {
            // Update existing
            $DB->update_record(static::TABLE_CLASSIFICATIONS, ['id' => $data->id, 'name' => $data->name, 'description' => $data->description, 'isstandard' => $data->isstandard, 'type' => $data->type]);
            $id = $data->id;
        } else {
            // Set sortorder for new records.
            if (!isset($data->sortorder)) {
                $maxsort = $DB->get_field_sql("SELECT MAX(sortorder) FROM {" . static::TABLE_CLASSIFICATIONS . "} WHERE version = ?", [$data->version]);
                $data->sortorder = ($maxsort ? $maxsort + 1 : 1);
            }
            // Create new.
            $id = $DB->insert_record(static::TABLE_CLASSIFICATIONS, $data);
        }


        // Update contexts
        /*if (isset($contexts)) {
            $DB->delete_records(static::TABLE_CLASSIFICATIONS_CONTEXTS, ['classificationid' => $id, 'version' => $data->version]);
            foreach ($contexts as $contextid) {
                $DB->insert_record(static::TABLE_CLASSIFICATIONS_CONTEXTS, ['classificationid' => $id, 'contextid' => $contextid, 'version' => $data->version]);
            }
        }*/
        
        return ['id' => $id, 'success' => true];
    }


    /**
     * Get all risk classifications.
     *
     * @param int $version Optional version to get classifications for
     * @return array
     */
    public static function get_classifications($version = null, $standard_only = false) {
        global $DB;
        
        // If no version specified, check URL parameter or get the latest version
        if ($version === null) {
            $version = risk_versions_lib::get_latest_version();
        }
        
        $where = ['version' => $version];
        if ($standard_only) {
            $where['isstandard'] = 1;
        }

        $records = $DB->get_records(static::TABLE_CLASSIFICATIONS, $where, 'sortorder ASC, name ASC');
        service_lib::cast_fields($records, [
            'sortorder' => 'int',
            'id' => 'int',
            'version' => 'int',
            'isstandard' => 'int',
        ]);

        foreach ($records as &$record) {

            // Determine the contexts for this classification.
            $contexts = [];
            $contexts_unique = [];
            if ($record->type == 'hazard') {
                // Get the sets where this classification is a member.
                $sql = "SELECT set_id FROM {" . static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS . "} WHERE classificationid = ? AND version = ?";
                $sets = $DB->get_fieldset_sql($sql, [$record->id, $version]);

                foreach ($sets as $set) {
                    // Get the classifications from this set, excluding this classification.
                    $sql = "SELECT classificationid 
                            FROM {" . static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS . "} 
                            WHERE set_id = ? 
                            AND classificationid != ? 
                            AND version = ?";
                    $contextids = $DB->get_fieldset_sql($sql, [$set, $record->id, $version]);
                    if (!in_array(implode('|', $contextids), $contexts_unique)) {
                        $contexts_unique[] = implode('|', $contextids);
                        $contextids = array_map('intval', $contextids);
                        $contexts[] = $contextids;
                    }
                }
                $record->contexts = $contexts;
            } else {
                $record->contexts = [];
            }


            // Get contexts and order them by sortorder
            /*$sql = "SELECT cc.contextid
                    FROM {" . static::TABLE_CLASSIFICATIONS_CONTEXTS . "} cc 
                    LEFT JOIN {" . static::TABLE_CLASSIFICATIONS . "} c ON cc.contextid = c.id 
                    WHERE cc.classificationid = ? 
                    AND cc.version = ? 
                    ORDER BY c.sortorder ASC";
            $contexts = $DB->get_fieldset_sql($sql, [$record->id, $version]);
            $record->contexts = array_map('intval', $contexts);*/
        }


        return array_values($records);
    }


    /**
     * Get a single risk classification by ID.
     *
     * @param int $id
     * @param int $version Optional version to get classification for
     * @return object
     */
    public static function get_classification($id, $version = null) {
        global $DB;

        // If no version specified, get the latest version
        if ($version === null) {
            require_once(__DIR__.'/risk_versions.lib.php');
            $version = \local_announcements2\lib\risk_versions_lib::get_latest_version();
        }
        
        return $DB->get_record(static::TABLE_CLASSIFICATIONS, ['id' => $id, 'version' => $version]);
    }

    

    /**
     * Delete a risk classification.
     *
     * @param int $id
     * @return array
     */
    public static function delete_classification($id) {
        global $DB;
        
        // Get the risk
        $data = $DB->get_record(static::TABLE_CLASSIFICATIONS, ['id' => $id]);

        // Cannot delete a version that has been used.
        if (static::has_been_used($data->version)) {
            throw new \Exception("Cannot delete classification as it has been used in an activity. Fork and make changes to the draft version instead.");
        }
        
        // Check if classification is used by any risks in this version
        $used = $DB->record_exists(static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS, ['classificationid' => $id, 'version' => $data->version]);
        if ($used) {
            throw new \Exception("Cannot delete classification as it is used by existing risks in this version.");
        }
        
        $DB->delete_records(static::TABLE_CLASSIFICATIONS, ['id' => $id, 'version' => $data->version]);
        return ['success' => true];
    }

    /**
     * Get all risks.
     *
     * @param int $version Optional version to get risks for
     * @return array
     */
    public static function get_risks($version = null) {
        global $DB;
        
        // If no version specified, check URL parameter or get the latest version
        if ($version === null) {
            $version = risk_versions_lib::get_latest_version();
        }

        $risks = $DB->get_records(static::TABLE_RISKS, ['version' => $version]);
        foreach ($risks as &$risk) {
            // First get all classification sets for this risk
            $sets_sql = "SELECT id, set_order 
                        FROM {" . static::TABLE_RISK_CLASSIFICATION_SETS . "} 
                        WHERE riskid = ? AND version = ? 
                        ORDER BY set_order ASC";
            $sets = $DB->get_records_sql($sets_sql, [$risk->id, $version]);
            
            // Initialize classification sets array
            $classification_sets = [];
            $classification_sets_sort = [];
            
            foreach ($sets as $set) {
                // Get all classifications for this specific set
                $members_sql = "SELECT m.classificationid, c.type, c.sortorder, c.name
                               FROM {" . static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS . "} m
                               LEFT JOIN {" . static::TABLE_CLASSIFICATIONS . "} c ON m.classificationid = c.id
                               WHERE m.set_id = ? 
                               AND m.version = ? 
                               ORDER BY c.sortorder ASC";
                $members = $DB->get_records_sql($members_sql, [$set->id, $version]);
                
                // Extract classification IDs and convert to integers
                $classification_ids = array_map('intval', array_column($members, 'classificationid'));

                // Add to classification sets (indexed by set_order - 1 for 0-based array)
                $classification_sets[$set->set_order - 1] = $classification_ids;

                // Add the sorting set...
                $classification_sets_sort[$set->id] = array_values($members);
            }
            
            // Ensure we have a properly indexed array (fill any gaps with empty arrays)
            $final_sets = [];
            for ($i = 0; $i < count($classification_sets); $i++) {
                $final_sets[$i] = $classification_sets[$i] ?? [];
            }
            
            $risk->classification_sets = $final_sets;
            $risk->classification_sets_sort = array_values($classification_sets_sort);

            // Get the sort value based on the first hazard in the first set.
            $risk->sort = 99999;
            if (isset($risk->classification_sets_sort[0])) {
                foreach ($risk->classification_sets_sort[0] as $member) {
                    if ($member->type == 'hazard') {
                        $risk->sort = $member->sortorder;
                        break;
                    }
                }
            }
            
            // Keep track of classification_ids as flattened array
            $risk->classification_ids = array_merge(...$risk->classification_sets);
        }
    
        service_lib::cast_fields($risks, [
            'isstandard' => 'int',
            'riskrating_before' => 'int',
            'riskrating_after' => 'int',
            'version' => 'int'
        ]);

        // Sort risks by sort order.
        usort($risks, function($a, $b) {
            return $a->sort - $b->sort;
        });

        return array_values($risks);
    }


    /**
     * Get all risks.
     *
     * @param int $version Optional version to get risks for
     * @return array
     */
    public static function get_risks_with_classifications($version = null) {
        global $DB;

        // If no version specified, get the latest version
        if ($version === null) {
            $version = risk_versions_lib::get_latest_version();
        }

        $risks = self::get_risks($version);
        

        foreach ($risks as &$risk) {
            $risk->classifications = static::get_classifications_by_ids($risk->classification_ids, $version);
        }

        return $risks;
    }


    public static function get_classifications_by_ids($classification_ids, $version) {
        global $DB;

        if (empty($classification_ids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($classification_ids);
        $sql = "SELECT * FROM {" . static::TABLE_CLASSIFICATIONS . "} WHERE id $insql AND version = ?";
        $classifications = $DB->get_records_sql($sql, array_merge($inparams, [$version]));
        return array_values($classifications);
    }

    /**
     * Create or update a risk.
     *
     * @param object $data
     * @return array
     */
    public static function save_risk($data) {
        global $DB;
        
        $data = (object) $data;
        $classification_sets = isset($data->classification_sets) ? $data->classification_sets : [[]];
        unset($data->classification_sets);
   
        if (isset($data->id) && $data->id) {
            // Get the risk
            $risk = $DB->get_record(static::TABLE_RISKS, ['id' => $data->id]);

            // Cannot update a risk that has been used.
            if (static::has_been_used($risk->version)) {
                throw new \Exception("Cannot update risk as it has been used in an activity. Fork and make changes to the draft version instead.");
            }

            // Update existing
            $DB->update_record(static::TABLE_RISKS, $data);
            $id = $data->id;
        } else {
            // Create new
            $id = $DB->insert_record(static::TABLE_RISKS, $data);
        }
        
        // Update classification sets
        static::update_risk_classification_sets($id, $classification_sets, $data->version);
        
        return ['id' => $id, 'success' => true];
    }

    /**
     * Delete a risk.
     *
     * @param int $id
     * @return array
     */
    public static function delete_risk($id) {
        global $DB;
        
        // Get the risk
        $risk = $DB->get_record(static::TABLE_RISKS, ['id' => $id]);

        // Cannot delete a risk that has been used.
        if (static::has_been_used($risk->version)) {
            throw new \Exception("Cannot delete risk as it has been used in an activity. Fork and make changes to the draft version instead.");
        }
        
        // Delete risk classification sets first (this will cascade to members)
        $memberssql = "SELECT * FROM {" . static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS . "} WHERE set_id IN (SELECT id FROM {" . static::TABLE_RISK_CLASSIFICATION_SETS . "} WHERE riskid = ?)";
        $members = $DB->get_records_sql($memberssql, [$id]);
        foreach ($members as $member) {
            $DB->delete_records(static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS, ['id' => $member->id]);
        }
        $DB->delete_records(static::TABLE_RISK_CLASSIFICATION_SETS, ['riskid' => $id]);
        
        // Delete the risk
        $DB->delete_records(static::TABLE_RISKS, ['id' => $id]);
        
        return ['success' => true];
    }

    /**
     * Update risk classification sets for a risk.
     *
     * @param int $riskid
     * @param array $classification_sets Array of classification sets, each set is an array of classification IDs
     * @param int $version
     */
    private static function update_risk_classification_sets($riskid, $classification_sets, $version) {
        global $DB;

        // Get the risk
        $risk = $DB->get_record(static::TABLE_RISKS, ['id' => $riskid]);

        // Cannot update risk classifications that have been used.
        if (static::has_been_used($risk->version)) {
            throw new \Exception("Cannot update risk classifications as it has been used in an activity. Fork and make changes to the draft version instead.");
        }
        
        // Delete existing classification sets for this version
        $memberssql = "SELECT * FROM {" . static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS . "} WHERE set_id IN (SELECT id FROM {" . static::TABLE_RISK_CLASSIFICATION_SETS . "} WHERE riskid = ?)";
        $members = $DB->get_records_sql($memberssql, [$riskid]);
        foreach ($members as $member) {
            $DB->delete_records(static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS, ['id' => $member->id]);
        }
        $DB->delete_records(static::TABLE_RISK_CLASSIFICATION_SETS, ['riskid' => $riskid, 'version' => $version]);
        
        // Add new classification sets
        foreach ($classification_sets as $set_index => $classification_ids) {
            if (empty($classification_ids)) {
                continue; // Skip empty sets
            }
            
            // Create the classification set
            $set_id = $DB->insert_record(static::TABLE_RISK_CLASSIFICATION_SETS, [
                'riskid' => $riskid,
                'set_order' => $set_index + 1,
                'version' => $version
            ]);
            
            // Add members to the set
            foreach ($classification_ids as $classification_id) {
                $DB->insert_record(static::TABLE_RISK_CLASSIFICATION_SET_MEMBERS, [
                    'set_id' => $set_id,
                    'classificationid' => $classification_id,
                    'version' => $version
                ]);
            }
        }
    }



    /**
     * Update classification sort order.
     *
     * @param array $sortorder Array of classification IDs in new order
     * @return array
     */
    public static function update_classification_sort($sortorder) {
        global $DB;
        
        foreach ($sortorder as $index => $classificationid) {
            $DB->update_record(static::TABLE_CLASSIFICATIONS, [
                'id' => $classificationid,
                'sortorder' => $index + 1
            ]);
        }
        
        return ['success' => true];
    }

    /**
     * Search classifications by name.
     *
     * @param string $query
     * @param int $version
     * @return array
     */
    public static function search_classifications($query, $version) {
        global $DB;
        
        $sql = "SELECT * FROM {" . static::TABLE_CLASSIFICATIONS . "} 
                WHERE name LIKE ? AND version = ?
                ORDER BY sortorder ASC, name ASC";
        
        $records = $DB->get_records_sql($sql, ['%' . $query . '%', $version]);
        service_lib::cast_fields($records, [
            'id' => 'int',
            'version' => 'int',
            'sortorder' => 'int',
        ]);
        return array_values($records);
    }



    public static function diff_versions_html($version1, $version2) {
        global $DB;
        
        // Get all risks for both versions
        $risks1 = static::get_risks($version1);
        $risks2 = static::get_risks($version2);
        
        // Get all classifications for both versions
        $classifications1 = static::get_classifications($version1);
        $classifications2 = static::get_classifications($version2);
        
        // Create HTML documents for comparison
        $html1 = static::create_version_html($risks1, $classifications1, $version1);
        $html2 = static::create_version_html($risks2, $classifications2, $version2);
        
        // Use FineDiff to generate the diff
        //$diff = new \FineDiff\Diff();
        //$diff_html = $diff->render($html2, $html1);
        $diff = new HtmlDiff($html2, $html1);
        $diff_html = $diff->build();
        $diff_html = html_entity_decode($diff_html);
        
        return $diff_html;
    }
    
    private static function create_version_html($risks, $classifications, $version) {
        $html = "<div class='version-comparison'>";
        
        // Classifications section
        $html .= "<h3><strong>Classifications</strong></h3>";
        $html .= "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        $html .= "<tr><th>Name</th><th>Type</th><th>Description</th><th>Standard</th><th>Contexts</th></tr>";
        
        foreach ($classifications as $classification) {
            $context_names = [];
            foreach ($classification->contexts as $context_id) {
                $context = array_filter($classifications, function($c) use ($context_id) {
                    return $c->id === $context_id;
                });
                if (!empty($context)) {
                    $context_names[] = reset($context)->name;
                }
            }
            
            // Add row markers that FineDiff can work with
            $html .= "<!--ROW_START--><tr>";
            $html .= "<td>" . htmlspecialchars($classification->name) . "</td>";
            $html .= "<td>" . htmlspecialchars($classification->type) . "</td>";
            $html .= "<td>" . ($classification->isstandard ? 'Yes' : 'No') . "</td>";
            $html .= "<td>" . htmlspecialchars(implode(', ', $context_names)) . "</td>";
            $html .= "</tr><!--ROW_END-->";
        }
        $html .= "</table>";
        
        // Risks section
        $html .= "<h3><strong>Risks</strong></h3>";
        $html .= "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        $html .= "<tr><th>Hazard</th><th>Risk Rating (Before)</th><th>Control Measures</th><th>Risk Rating (After)</th><th>Responsible Person</th><th>Control Timing</th><th>Risk/Benefit</th><th>Classifications</th></tr>";
        
        foreach ($risks as $risk) {
            $classification_names = [];
            foreach ($risk->classification_sets as $set) {
                $set_names = [];
                foreach ($set as $classification_id) {
                    $classification = array_filter($classifications, function($c) use ($classification_id) {
                        return $c->id === $classification_id;
                    });
                    if (!empty($classification)) {
                        $set_names[] = reset($classification)->name;
                    }
                }
                if (!empty($set_names)) {
                    $classification_names[] = implode(' + ', $set_names);
                }
            }
            
            // Add row markers that FineDiff can work with
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($risk->hazard) . "</td>";
            $html .= "<td>" . $risk->riskrating_before . "</td>";
            $html .= "<td>" . htmlspecialchars($risk->controlmeasures) . "</td>";
            $html .= "<td>" . $risk->riskrating_after . "</td>";
            $html .= "<td>" . htmlspecialchars($risk->responsible_person) . "</td>";
            $html .= "<td>" . htmlspecialchars($risk->control_timing) . "</td>";
            $html .= "<td>" . htmlspecialchars($risk->risk_benefit) . "</td>";
            $html .= "<td>" . htmlspecialchars(implode(' | ', $classification_names)) . "</td>";
            $html .= "</tr>" . $risk->id . time() . "";
        }
        $html .= "</table>";
        
        $html .= "</div>";
        
        return $html;
    }


    public static function get_includes_for_classifications($classification_ids, $version) {
        global $DB;
        // Get the classifications.
        $classifications = self::get_classifications_by_ids($classification_ids, $version);

        // Expand the includes.
        $includes = [];
        foreach ($classifications as $classification) {
            if (empty($classification->includes)) {
                continue;
            }

            $targets = array_map('trim', explode('##', $classification->includes));

            foreach ($targets as $target) {
                if (empty($target)) {
                    continue;
                }

                /// I might need to add a qualifying set here...
                $classification_set = self::get_classifications_from_names(array_map('trim', explode('||', $target)), $version);
                $includes[] = array_column($classification_set, 'id');
            }
        }
        return $includes;
    }

    public static function get_classifications_from_names($names, $version) {
        global $DB;
        if (empty($names)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($names);
        $sql = "SELECT * FROM {" . static::TABLE_CLASSIFICATIONS . "} WHERE name $insql AND version = ?";
        $classifications = $DB->get_records_sql($sql, array_merge($inparams, [$version]));
        return $classifications;
    }

} 