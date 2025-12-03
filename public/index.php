<?php
    require(__DIR__.'/../../../config.php');

    // If url is /local/announcements2/public, then we do not require login.
    if (!str_contains($_SERVER['REQUEST_URI'], '/local/announcements2/public') || isloggedin()) {
        require_login();
    }

    require_once __DIR__ . '/../bootstrap.php';
    require_once(__DIR__.'/../classes/lib/service.lib.php');
    require_once(__DIR__.'/../classes/lib/utils.lib.php');

    $annconfig = get_config('local_announcements2');
    $config = new \stdClass();
    $config->version = $annconfig->version;
    $config->sesskey = sesskey();
    $config->wwwroot = $CFG->wwwroot;
    $config->sitename = $SITE->fullname;
    $config->toolname = \local_announcements2\lib\service_lib::get_toolname();
    $config->headerbg = $annconfig->headerbg;
    $config->headerfg = $annconfig->headerfg;
    $config->headerlogourl = $annconfig->headerlogourl;
    $config->loginUrl = (new moodle_url('/login/index.php'))->out();
    $config->logoutUrl = '';
    $config->favicon = get_favicon('src/assets/favicon.ico');
    $config->logo = get_logo('src/assets/logo.png');

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