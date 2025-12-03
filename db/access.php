<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/announcements2:post' => array (
        'captype'       => 'write',
        'contextlevel'  => CONTEXT_COURSE,
        'archetypes'    => array (
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW, // Managers at the category level can also post
            'coursecreator'  => CAP_ALLOW,
        )
    ),
    'local/announcements2:administer' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(), //no roles are be given this capability by default
    ],
);
