<?php
    require(__DIR__.'/../../config.php');
    require_login();
    require_once __DIR__ . '/bootstrap.php';
    require_once(__DIR__.'/classes/lib/service.lib.php');
    require_once(__DIR__.'/classes/lib/utils.lib.php');

    $annconfig = get_config('local_announcements2');
    $config = new \stdClass();
    $config->version = $annconfig->version;
    $config->sesskey = sesskey();
    $config->wwwroot = $CFG->wwwroot;
    $config->sitename = $SITE->fullname;
    $config->toolname = \local_announcements2\lib\service_lib::get_toolname();
    $config->headerbg = $annconfig->headerbg ?? '#0F172A';
    $config->headerfg = $annconfig->headerfg ?? '#ffffff';
    $config->logourl = $annconfig->logo;
    $user = \local_announcements2\lib\utils_lib::user_stub($USER->username);
    $config->user = $user;
    $config->loginUrl = (new moodle_url('/login/index.php'))->out();
    $config->logoutUrl = (new moodle_url('/login/logout.php', ['sesskey' => $config->sesskey]))->out();
    $config->favicon = get_favicon('src/assets/favicon.ico');
    $config->roles = \local_announcements2\lib\service_lib::get_user_roles($USER->username);

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Announcements</title>
        <script>
            window.appdata = {}
            window.appdata.config = <?= json_encode($config) ?>
        </script>
        <link rel="icon" type="image/x-icon" href="<?= $config->favicon ?>" />
        <?= bootstrap('index.html') ?>

        <meta name="theme-color" content="#0F172A">

        <!-- PWA contents -->
        <!-- Generated assets and the following code via npx pwx-asset-generator command in npm run build -->

    </head>
    <body>
        <div id="root"></div>
    </body>
</html>