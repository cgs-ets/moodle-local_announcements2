<?php


namespace local_announcements2\api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../lib/utils.lib.php');

use \local_announcements2\lib\utils_lib;

/**
 * Generic API trait
 */
trait utils_api {

    /**
     * Search for a staff member in the user index.
     *
     * @return array results.
     */
    static public function search_staff() {
        $query = required_param('query', PARAM_ALPHANUMEXT);
        return utils_lib::search_staff($query);
    }

    /**
     * Search for a student in the user index.
     *
     * @return array results.
     */
    static public function search_students() {
        $query = required_param('query', PARAM_ALPHANUMEXT);
        return utils_lib::search_students($query);
    }

    /**
     * Search categories.
     *
     * @return array results.
     */
    static public function search_categories() {
        $text = required_param('text', PARAM_ALPHANUMEXT);
        return utils_lib::search_categories($text);
    }

    /**
     * Get course list for user.
     *
     * @return array.
     */
    static public function get_users_courses() {
        return utils_lib::get_users_courses();
    }

    /**
     * Get groups list for user.
     *
     * @return array.
     */
    static public function get_users_groups() {
        return utils_lib::get_users_groups();
    }

    /**
     * Get current authenticated user's children info.
     *
     * @return array.
     */
    static public function get_users_children() {
        return utils_lib::get_users_children();
    }

    /**
     * Check for existing session.
     * 
     * @throws require_login_exception
     * @return void.
     */
    static public function check_login() {
        if (!isloggedin()) {
            throw new \require_login_exception('Login required.');
        }
    }

    /**
     * Get current authenticated user's activities.
     *
     * @return array results.
     */
    static public function get_courses_students() {
        $ids = required_param('ids', PARAM_RAW);
        return utils_lib::get_students_from_courses(explode(',', $ids));
    }

    /**
     * Get current authenticated user's activities.
     *
     * @return array results.
     */
    static public function get_group_students() {
        $ids = required_param('ids', PARAM_RAW);
        return utils_lib::get_students_from_groups(explode(',', $ids));
    }

    /**
     * Get groups list for user.
     *
     * @return array.
     */
    static public function get_user_taglists() {
        return utils_lib::get_user_taglists();
    }

    /**
     * Get public taglists.
     *
     * @return array.
     */ 
    static public function get_public_taglists() {
        return utils_lib::get_public_taglists();
    }

    /**
     * Get students from taglist.
     *
     * @return array.
     */
    static public function get_taglist_students() {
        $id = required_param('id', PARAM_INT);
        return utils_lib::get_students_from_taglist($id);
    }

    /**
     * Search
     *
     * @return array.
     */
    static public function search() {
        $query = required_param('query', PARAM_RAW);
        return utils_lib::search($query);
    }

    /**
     * Search public
     *
     * @return array.
     */
    static public function search_public() {
        $query = required_param('query', PARAM_RAW);
        return utils_lib::search_public($query);
    }
}
