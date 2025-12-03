<?php

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_announcements2', get_string('pluginname', 'local_announcements2'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading('local_announcements2_appearance', get_string('settingsappearance', 'local_announcements2'), ''));

    // Custom tool name.
    $name = 'local_announcements2/toolname';
    $visiblename = get_string('toolname', 'local_announcements2');
    $description = get_string('toolname_desc', 'local_announcements2');
    $setting = new admin_setting_configtext($name, $visiblename, $description, 'Announcements');
    $settings->add($setting);

    // Logo.
    $name = 'local_announcements2/logo';
    $visiblename = get_string('logo', 'local_announcements2');
    $description = get_string('logo_desc', 'local_announcements2');
    $setting = new admin_setting_configtext($name, $visiblename, $description, null);
    $settings->add($setting);

    // Email header image url.
    $name = 'local_announcements2/emaillogo';
    $visiblename = get_string('emaillogo', 'local_announcements2');
    $description = get_string('emaillogo_desc', 'local_announcements2');
    $setting = new admin_setting_configtext($name, $visiblename, $description, null);
    $settings->add($setting);

    // Favicon.
    $name = 'local_announcements2/favicon';
    $visiblename = get_string('favicon', 'local_announcements2');
    $description = get_string('favicon_desc', 'local_announcements2');
    $setting = new admin_setting_configtext($name, $visiblename, $description, null);
    $settings->add($setting);

    // External DB connections
    $settings->add(new admin_setting_heading(
        'local_announcements2_exdbheader', 
        get_string('settingsheaderdb', 'local_announcements2'), 
        ''
    ));
	$options = array('', "mariadb", "mysqli", "oci", "pdo", "pgsql", "sqlite3", "sqlsrv");
    $options = array_combine($options, $options);
    $settings->add(new admin_setting_configselect(
        'local_announcements2/dbtype', 
        get_string('dbtype', 'local_announcements2'), 
        get_string('dbtype_desc', 'local_announcements2'), 
        '', 
        $options
    ));
    $settings->add(new admin_setting_configtext('local_announcements2/dbhost', get_string('dbhost', 'local_announcements2'), get_string('dbhost_desc', 'local_announcements2'), 'localhost'));
    $settings->add(new admin_setting_configtext('local_announcements2/dbuser', get_string('dbuser', 'local_announcements2'), '', ''));
    $settings->add(new admin_setting_configpasswordunmask('local_announcements2/dbpass', get_string('dbpass', 'local_announcements2'), '', ''));
    $settings->add(new admin_setting_configtext('local_announcements2/dbname', get_string('dbname', 'local_announcements2'), '', ''));

    $settings->add(new admin_setting_configtext('local_announcements2/usertaglistssql', get_string('usertaglistssql', 'local_announcements2'), '', ''));
    $settings->add(new admin_setting_configtext('local_announcements2/publictaglistssql', get_string('publictaglistssql', 'local_announcements2'), '', ''));
    $settings->add(new admin_setting_configtext('local_announcements2/taglistuserssql', get_string('taglistuserssql', 'local_announcements2'), '', ''));
    
}
