<?php
defined('MOODLE_INTERNAL') || die();
$tasks = array(
    array(
        'classname' => 'local_announcements2\task\cron_task_digests',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '15', // Default 3pm.
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*'
    ),
    array(
        'classname' => 'local_announcements2\task\cron_task_notifications',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*'
    )
);