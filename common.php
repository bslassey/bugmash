<?php

if (get_magic_quotes_gpc()) {
    foreach ($_POST AS $key => $value) {
        $_POST[$key] = stripslashes($value);
    }
    foreach ($_GET AS $key => $value) {
        $_GET[$key] = stripslashes($value);
    }
    foreach ($_COOKIE AS $key => $value) {
        $_COOKIE[$key] = stripslashes($value);
    }
}

function fail( $message ) {
    header( 'HTTP/500 Error!' );
    print $message;
    exit( 0 );
}

$BUGMASH_DIR = $_SERVER['DOCUMENT_ROOT'] . '/../mailfilters/' . $_SERVER['SERVER_NAME'] . '/bugmash';
include_once( $BUGMASH_DIR . '/bugmash.config.php' );

?>