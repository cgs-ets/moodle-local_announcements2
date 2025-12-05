<?php

namespace local_announcements2\lib;

defined('MOODLE_INTERNAL') || die();

use \stdClass;

class utils_lib {

    const PARENT_FUNCTIONS  = [
    ];


    /**
     * Create a user stub object from a username.
     *
     * @param string $username
     * @return object
     */
    public static function user_stub($username) {
        $mdluser = \core_user::get_user_by_username($username);
        if (empty($mdluser)) {
            return null;
        }
        $user = new \stdClass();
        $user->un = $mdluser->username;
        $user->fn = $mdluser->firstname;
        $user->ln = $mdluser->lastname;

        return $user;
    }

    /*
    * returnJsonHttpResponse
    * @param $success: Boolean
    * @param $data: Object or Array
    */
    public static function returnJsonHttpResponse($success, $data)
    {
        // remove any string that could create an invalid JSON 
        // such as PHP Notice, Warning, logs...
        ob_clean();

        // this will clean up any previously added headers, to start clean
        header_remove(); 

        // Set the content type to JSON and charset 
        // (charset can be set to something else)
        header("Content-type: application/json; charset=utf-8");

        // Set your HTTP response code, 2xx = SUCCESS, 
        // anything else will be error, refer to HTTP documentation
        if ($success) {
            http_response_code(200);
        } else {
            http_response_code(500);
        }
        
        // encode your PHP Object or Array into a JSON string.
        // stdClass or array
        echo json_encode($data);

        // making sure nothing is added
        exit();
    }

    /**
     * Call an module's service function.
     *
     * @param string $function name of external function
     * @param array $args parameters to pass to function
     * @param string $format json or raw
     * @return array error and data
     */
    public static function call_service_function($function, $args, $format = 'json') {
        $response = array();
    
        try {
            $function = static::service_function_info($function);

            // White list parent functions. For all other purposes, this is staff system.
            if (!in_array($function->methodname, static::PARENT_FUNCTIONS)) {
                utils_lib::require_staff();
            }
    
            require_once($function->classpath);
            $result = call_user_func($function->nameclass .'::'.$function->methodname, $args);
            
            if ($format == 'raw') {
                $response = $result;
            }
            
            if ($format == 'json') {
                $response['error'] = false;
                $response['data'] = $result;
            }
    
        } catch (\Exception $e) {       
            $exception = get_exception_info($e);
            // Remove class name prefix (e.g. "Exception - ") if present
            $exception->message = preg_replace('/^[^–-]+[-–]\s*/', '', $exception->message);
            unset($exception->a);
            $exception->backtrace = format_backtrace($exception->backtrace, true);
            if (!debugging('', DEBUG_DEVELOPER)) {
                unset($exception->debuginfo);
                unset($exception->backtrace);
            }
            $response['error'] = true;
            $response['exception'] = $exception;
        }
    
        return $response;
    }
    
    /**
     * Returns detailed function information
     *
     * @param string $function name of external function
     * @param int $strictness IGNORE_MISSING means compatible mode, false returned if record not found, debug message if more found;
     *                        MUST_EXIST means throw exception if no record or multiple records found
     * @return \stdClass details or false if not found or exception thrown
     */
    public static function service_function_info($function, $strictness=MUST_EXIST) {
        global $CFG;
    
        $namespacefunc = explode("-", $function); // E.g. local_example, test_service
        $pathclass = explode("_", $namespacefunc[0]); // E.g. local, example
    
        $function = new \stdClass();
        $function->namespace = $namespacefunc[0];
        $function->classname = 'API';
        $function->nameclass = $function->namespace.'\\'.$function->classname;
        $function->methodname = $namespacefunc[1];
        $function->classpath = $CFG->dirroot.'/local/'.$pathclass[1].'/classes/api/api.php';
    
        if (!file_exists($function->classpath)) {
            throw new \coding_exception('Cannot find file with service function implementation '.$function->classpath);
        }
    
        require_once($function->classpath);
    
        if (!method_exists($function->nameclass, $function->methodname)) {
            throw new \coding_exception('Missing implementation method of '.$function->nameclass.'::'.$function->methodname);
        }
    
        return $function;
    }

    /**
    * Helper function to get avatar src for user.
    *
    * @param string $username
    * @param string $size: f1 for small, f2 for even smaller, f3 for large.
    * @param bool|int: $includetoken: whether to tokenise or not.
    * @return string $url
    */
    public static function get_avatar_url($username, $size = "f2", $includetoken = false) {
        if (empty($username)) {
            return;
        }
        $user = \core_user::get_user_by_username($username);
        if (empty($user)) {
            return;
        }
        if (!$user->picture) {
            return;
        }
        $usercontext = \context_user::instance($user->id, IGNORE_MISSING);
        $url = \moodle_url::make_pluginfile_url($usercontext->id, 'user', 'icon', null, '/', $size, false, $includetoken);
        $url->param('rev', $user->picture);
        return $url;
    }

    /**
     * Get the app title from config or default to Announcements.
     *
     * @return string
     */
    public static function get_toolname() {
        $config = get_config('local_announcements2');
        if (isset($config->toolname) && !empty($config->toolname)) {
            return $config->toolname;
        } else {
            return 'Announcements';
        }
    }

    /**
     * Get the user's campus roles.
     *
     * @return string
     */
    public static function get_user_roles($username) {
        // if user not logged in, then return empty array.
        if (!isloggedin()) {
            return [];
        }
        $user = \core_user::get_user_by_username($username);
        profile_load_custom_fields($user);
        $campusroles = explode(',', strtolower($user->profile['CampusRoles']));
        foreach ($campusroles as $role) {
            if (strpos($role, "staff") !== false) {
                $campusroles[] = "staff";
                continue;
            }
            if (strpos($role, "parents") !== false) {
                $campusroles[] = "parents";
                continue;
            }
            if (strpos($role, "students") !== false) {
                $campusroles[] = "students";
                continue;
            }
        }
        $uniqueRoles = array_unique($campusroles);
        $trimmedRoles = array_map('trim', $uniqueRoles);
        return array_values($trimmedRoles);
    }


 
    public static function cast_fields(&$records, $fieldsToCast) {
        foreach ($records as &$record) {
            foreach ($fieldsToCast as $field => $type) {
                if (property_exists($record, $field)) {
                    settype($record->$field, $type);
                }
            }
        }
    }

    public static function require_staff() {
        global $USER;
        
        profile_load_custom_fields($USER);
        $campusroles = strtolower($USER->profile['CampusRoles']);
        if (strpos($campusroles, 'staff') !== false) {
            return true;
        }

        throw new \required_capability_exception(\context_system::instance(), 'local/activities:manage', 'nopermissions', '');
        exit;
    }


    public static function is_user_staff() {
        global $USER;
        
        profile_load_custom_fields($USER);
        $campusroles = strtolower($USER->profile['CampusRoles']);
        if (strpos($campusroles, 'staff') !== false) {
            return true;
        }

        return false;
    }

    public static function is_user_parent() {
        global $USER;
        
        profile_load_custom_fields($USER);
        $campusroles = strtolower($USER->profile['CampusRoles']);
        if (strpos($campusroles, 'parent') !== false) {
            return true;
        }

        return false;
    }


    /**
     * Search users.
     *
     * @param string $query
     * @return array results
     */
    public static function search_users($query) {
        global $DB;

        $sql = "SELECT DISTINCT u.*, d.*
                FROM mdl_user u
                JOIN mdl_user_info_data d ON u.id = d.userid
                WHERE u.suspended = 0
                AND (
                    LOWER(REPLACE(u.firstname, '''', '')) LIKE ?
                    OR LOWER(REPLACE(u.lastname, '''', '')) LIKE ?
                    OR LOWER(REPLACE(u.username, '''', '')) LIKE ?
                )";

        // remove apostrophes
        $likesearch = "%" . strtolower(str_replace("'", "", $query)) . "%";
        $data = $DB->get_records_sql($sql, [$likesearch, $likesearch, $likesearch]);
        
        $first10Elements = array_slice($data, 0, 10);

        $users = [];
        foreach ($first10Elements as $row) {
            $users[] = static::user_stub($row->username);
        }
        return $users;
    }

    


    

}