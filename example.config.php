<?php

// copy this file to bugmash.config.php and fill in with
// appropriate values. See README for details

// MySQL access info
$_MYSQL_HOST = 'bugdb.example.com';
$_MYSQL_USER = 'bugdb_user';
$_MYSQL_PASS = 'bugdb_pass'
$_MYSQL_DB = 'bugmash';

// My bugmail address
$_ME = 'bugmail@bugdb.example.com';

// The extension on the final email address that is receiving the email. You can always leave this unset to disable extension checking.
// $_MY_EXTENSION = 'bugmash';

// directory where emails that are not bugzilla emails will be dropped
$_UNFILTERED_DIR = $_SERVER['HOME'] . '/Maildir/new';

// bugzilla installation URL base
$_BASE_URL = 'https://bugzilla.mozilla.org';

?>
