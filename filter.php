#!/usr/local/bin/php
<?php

include_once( 'bugmash.config.php' );

$_DB = null;

$mail = file( 'php://stdin', FILE_IGNORE_NEW_LINES );
$mailString = implode( "\n", $mail );
$mailText = implode( '', $mail );

$time = (isset( $_SERVER['REQUEST_TIME'] ) ? $_SERVER['REQUEST_TIME'] : time());
$filename = $time . '.' . sha1( $mailString );

if (isset( $_MY_EXTENSION )) {
    if ((! isset( $_SERVER['EXTENSION'] )) || strcmp( $_SERVER['EXTENSION'], $_MY_EXTENSION ) != 0) {
        // the extension on the incoming email doesn't match the one we have specified. put it aside
        file_put_contents( $_UNFILTERED_DIR . '/' . $filename, $mailString );
        exit( 0 );
    }
}

if ((! isset( $_SERVER['SENDER'] )) || (strpos( $_SERVER['SENDER'], 'bugzilla-daemon@' ) !== 0)) {
    // doesn't look like a bugmail, probably spam but possible bounce notifications. put it aside
    file_put_contents( $_UNFILTERED_DIR . '/' . $filename, $mailString );
    exit( 0 );
}

function fail( $message ) {
    // don't know what to do with this bugmail, so save it for manual review
    global $filename, $mailString, $_DB;
    file_put_contents( dirname( $_SERVER['PHP_SELF'] ) . '/' . $filename, $mailString );
    file_put_contents( dirname( $_SERVER['PHP_SELF'] ) . '/' . $filename . '.err', $message );
    print "$message\n";
    if ($_DB) {
        $_DB->close();
    }
    exit( 0 );
}

function success() {
    global $filename, $mailString, $_DB;
    // uncomment if you want to save successfully processed bugmails. make sure there
    // is an "old" folder in the same directory as this file.
    //file_put_contents( dirname( $_SERVER['PHP_SELF'] ) . '/old/' . $filename, $mailString );
    if ($_DB) {
        $_DB->close();
    }
    exit( 0 );
}

// bugmail, let's process it

// collapse headers that are wrapped to multiple lines
$merged = array();
$body = false;
for ($i = 0; $i < count( $mail ); $i++) {
    $line = $mail[$i];
    if ($body) {
        $merged[] = $line;
        continue;
    } else if (strlen( $line ) == 0) {
        $body = true;
        $merged[] = $line;
        continue;
    }
    if ($line{0} == ' ' || $line{0} == "\t") {
        $merged[ count( $merged ) - 1 ] .= ' ' . ltrim( $line );
    } else {
        $merged[] = $line;
    }
}
$mail = $merged;

$bugIsSecure = false;
$fromDomain = substr( $_SERVER['SENDER'], strpos( $_SERVER['SENDER'], '@' ) + 1 );
$bugzillaHeaders = array();
foreach ($mail as $mailLine) {
    if (strlen( $mailLine ) == 0) {
        $matches = array();
        if (preg_match( '/\[Bug \d+\] \(Secure bug/', $bugzillaHeaders[ 'subject' ], $matches ) == 0) {
            break;
        }
        $bugIsSecure = true;
        // continue processing since there will be another subject header in the body, followed by another blank line
    }
    if (strpos( $mailLine, 'X-Bugzilla-' ) === 0) {
        $header = substr( $mailLine, strlen( 'X-Bugzilla-' ) );
        list( $key, $value ) = explode( ':', $header );
        $bugzillaHeaders[ strtolower( trim( $key ) ) ] = trim( $value );
    } else if (strpos( $mailLine, 'Message-ID:' ) === 0) {
        $matches = array();
        if (preg_match( '/bug-(\d+)-/', $mailLine, $matches ) > 0) {
            $bugzillaHeaders[ 'id' ] = $matches[1];
        }
    } else if (strpos( $mailLine, 'Subject:') === 0) {
        $bugzillaHeaders[ 'subject' ] = trim( substr( $mailLine, 8 ) );
    } else if (strpos( $mailLine, 'Date:' ) === 0) {
        $bugzillaHeaders[ 'date' ] = strtotime( trim( substr( $mailLine, 5 ) ) );
    } else if (strpos( $mailLine, 'Content-Transfer-Encoding: quoted-printable' ) === 0) {
        $mailText = quoted_printable_decode( $mailText );
        $mailString = quoted_printable_decode( $mailString );
    }
}

function checkForField( $key ) {
    global $bugzillaHeaders;
    $key = strtolower( $key );
    return isset( $bugzillaHeaders[ $key ] );
}

function getField( $key ) {
    global $bugzillaHeaders;
    $key = strtolower( $key );
    if (! isset( $bugzillaHeaders[ $key ] )) {
        fail( "No field $key" );
    }
    return $bugzillaHeaders[ $key ];
}

function normalizeReason( $reason, $watchReason ) {
    // take the highest priority reason
    if (stripos( $reason, 'AssignedTo' ) !== FALSE) {
        return 'AssignedTo';
    } else if (stripos( $reason, 'Reporter' ) !== FALSE) {
        return 'Reporter';
    } else if (stripos( $reason, 'CC' ) !== FALSE) {
        return 'CC';
    } else if (stripos( $reason, 'Voter' ) !== FALSE) {
        return 'Voter';
    } else if (stripos( $reason, 'None' ) !== FALSE) {
        if (strlen( $watchReason ) > 0) {
            return 'Watch';
        }
        fail( "Empty watch reason with reason $reason" );
    } else {
        fail( "Unknown reason $reason" );
    }
}

function normalizeFieldList( $fieldString ) {
    $words = array_filter( explode( ' ', $fieldString ) );
    $fields = array();
    $currentField = '';
    for ($i = 0; $i < count( $words ); $i++) {
        $word = $words[ $i ];
        if ($word == 'Attachment' /* Created|#abcdef */) {
            if ($i + 1 >= count( $words )) {
                fail( 'Unrecognized field list (1): ' . print_r( $words, true ) );
            }
            $word .= ' ' . $words[ ++$i ];
            if ($words[ $i ] == 'Created') {
                // ignore "Attachment Created" in the field list since it doesn't have
                // a corresponding entry in the field table
                continue;
            } else {
                /* Flags|is|mime */
                if ($i + 1 >= count( $words )) {
                    fail( 'Unrecognized field list (2): ' . print_r( $words, true ) );
                }
                $word .= ' ' . $words[ ++$i ];
                if ($words[ $i ] == 'is' /* obsolete */ || $words[ $i ] == 'mime' /* type */) {
                    if ($i + 1 >= count( $words )) {
                        fail( 'Unrecognized field list (3): ' . print_r( $words, true ) );
                    }
                    $word .= ' ' . $words[ ++$i ];
                }
            }
        } else if ($word == 'Depends' /* On */
            || $word == 'Target' /* Milestone */
            || $word == 'Ever' /* Confirmed */
            || $word == 'Crash' /* Signature */
            || $word == 'See' /* Also */
            || $word == 'Last' /* Resolved */)
        {
            if ($i + 1 >= count( $words )) {
                fail( 'Unrecognized field list (4): ' . print_r( $words, true ) );
            }
            $word .= ' ' . $words[ ++$i ];
        } else if ($word == 'Status') {
            if ($i + 1 < count( $words ) && $words[ $i + 1 ] == 'Whiteboard') {
                $word .= ' '. $words[ ++$i ];
            }
        } else if ($word == 'Comment') {
            if ($i + 3 < count( $words) && $words[ $i + 2 ] == 'is' && $words[ $i + 3 ] == 'private') {
                $word .= ' ' . $words[ ++$i ] . ' ' . $words[ ++$i ] . ' ' . $words[ ++$i ];
            }
        }
        $fields[] = $word;
    }
    return $fields;
}

function parseChangeTable( $fields, $rows ) {
    // get widths to avoid dying on new/old values with pipe characters
    $columns = explode( '|', $rows[0] );
    $widths = array_map( "strlen", $columns );
    $oldval = '';
    $newval = '';
    $ixField = 0;
    for ($i = 1; $i < count( $rows ); $i++) {
        $col1 = trim( substr( $rows[$i], 0, $widths[0] ) );
        $col2 = substr( $rows[$i], $widths[0] + 1, $widths[1] );
        $col3 = substr( $rows[$i], $widths[0] + 1 + $widths[1] + 1 );
        if (strlen( $col1 ) == 0 || $ixField >= count( $fields )) {
            $oldval .= $col2;
            $newval .= $col3;
            continue;
        }
        $matchedStart = false;
        if (strpos( $fields[ $ixField ], $col1 ) === 0) {
            $matchedStart = true;
            if ($ixField > 0) {
                $oldvals[] = trim( $oldval );
                $newvals[] = trim( $newval );
            }
            $oldval = $col2;
            $newval = $col3;
        }
        if (strpos( $fields[ $ixField ], $col1 ) === strlen( $fields[ $ixField ] ) - strlen( $col1 )) {
            if (! $matchedStart) {
                $oldval .= $col2;
                $newval .= $col3;
            }
            $ixField++;
        }
    }
    $oldvals[] = trim( $oldval );
    $newvals[] = trim( $newval );
    if ($ixField != count( $fields )) {
        fail( 'Unable to parse change table; using field list: ' . print_r( $fields, true ) . ' and data ' . print_r( $rows, true ) );
    } else if (count( $fields ) != count( $oldvals )) {
        fail( 'Value lists are not as long as field lists; using field list: ' . print_r( $fields, true ) . ' and data ' . print_r( $rows, true ) );
    }
    return array( $fields, $oldvals, $newvals );
}

function insertChanges( $bug, $date, $reason, &$fields, &$oldvals, &$newvals ) {
    $stmt = prepare( 'INSERT INTO changes (bug, stamp, reason, field, oldval, newval) VALUES (?, ?, ?, ?, ?, ?)' );
    for ($i = 0; $i < count( $fields ); $i++) {
        $stmt->bind_param( 'isssss', $bug, $date, $reason, $fields[$i], $oldvals[$i], $newvals[$i] );
        $stmt->execute();
        if ($stmt->affected_rows != 1) {
            fail( 'Unable to insert field change into DB: ' . $stmt->error );
        }
    }
}

function saveChanges( $bug, $date, $reason, &$mailString ) {
    $ret = 0;
    $fields = normalizeFieldList( getField( 'changed-fields' ) );
    if (count( $fields ) == 0) {
        return $ret;
    }

    $matches = array();
    $matchCount = preg_match_all( "/\n( *What *\|Removed *\|Added\n-*\n.*?)\n\n/s", $mailString, $matches, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE );
    if ($matchCount == 0) {
        fail( 'No change table' );
    }
    $tableRows = $matches[1][0][0];
    $ret = max( $ret, $matches[0][0][1] + strlen( $matches[0][0][0] ) );
    for ($i = 1; $i < $matchCount; $i++) {
        // append subsequent tables without header row
        $tableRows .= substr( $matches[1][$i][0], strpos( $matches[1][$i][0], "\n" ) );
        $ret = max( $ret, $matches[0][$i][1] + strlen( $matches[0][$i][0] ) );
    }
    list( $fields, $oldvals, $newvals ) = parseChangeTable( $fields, explode( "\n", $tableRows ) );
    insertChanges( $bug, $date, $reason, $fields, $oldvals, $newvals );

    return $ret;
}

function saveComments( $bug, $date, $reason, &$mailString ) {
    $matches = array();
    $matchCount = preg_match_all( "/- Comment #(\d+) from ([^<]*) <[^\n]* ---\n(.*)\n\n--/sU", $mailString, $matches, PREG_PATTERN_ORDER );
    $stmt = prepare( 'INSERT INTO comments (bug, stamp, reason, commentnum, author, comment) VALUES (?, ?, ?, ?, ?, ?)' );
    for ($i = 0; $i < $matchCount; $i++) {
        $commentNum = $matches[1][$i];
        $author = $matches[2][$i];
        $comment = $matches[3][$i];
        $stmt->bind_param( 'ississ', $bug, $date, $reason, $commentNum, $author, $comment );
        $stmt->execute();
        if ($stmt->affected_rows != 1) {
            fail( 'Unable to insert new comment into DB: ' . $stmt->error );
        }
    }
}

function saveDependencyChanges( $bug, $date, $reason, &$mailString ) {
    $matches = array();
    if (preg_match( '/Bug (\d+) depends on bug (\d+), which changed state./', $mailString, $matches ) == 0) {
        return false;
    }
    if (strcmp( $bug, $matches[1] ) != 0) {
        fail( 'Dependency email did not match bug number' );
    }
    $dependentBug = $matches[2];

    // in this case we don't know the list of field names ahead of time, just that it will be one or more Status
    // and Resolution fields. Since these won't line wrap, we can just do a simpler version of parseChangeTable
    $matches = array();
    $matchCount = preg_match_all( "/\n *What *\|Old Value *\|New Value\n-*\n(.*?)\n\n/s", $mailString, $matches, PREG_PATTERN_ORDER );
    if ($matchCount != 1) {
        fail( 'Found ' . $matchCount . ' change tables in a dependency change email' );
    }
    $tableRows = explode( "\n", $matches[1][0] );
    $fields = array();
    $oldvals = array();
    $newvals = array();
    foreach ($tableRows AS $row) {
        list( $field, $oldval, $newval ) = explode( '|', $row );
        $fields[] = 'depbug-' . $dependentBug . '-' . trim( $field );
        $oldvals[] = trim( $oldval );
        $newvals[] = trim( $newval );
    }
    insertChanges( $bug, $date, $reason, $fields, $oldvals, $newvals );

    return true;
}

function prepare( $query ) {
    global $_DB, $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB;
    if (is_null( $_DB )) {
        $_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
        if (mysqli_connect_errno()) {
            fail( 'Error connecting to db: ' . mysqli_connect_error() );
        }
    }
    $stmt = $_DB->prepare( $query );
    if ($_DB->errno) {
        fail( 'Error preparing statement: ' . $_DB->error );
    }
    return $stmt;
}

function updateMetadata( $date ) {
    global $bugIsSecure;
    $matches = array();
    if (preg_match( '/\[Bug (\d+)\] (.*)( : \[Attachment.*)?$/sU', getField( 'subject' ), $matches ) > 0) {
        $stmt = prepare( 'INSERT INTO metadata (bug, stamp, title, secure) VALUES (?, ?, ?, ?) '
                       . 'ON DUPLICATE KEY UPDATE stamp=VALUES(stamp), title=VALUES(title), secure=VALUES(secure)' );
        $stmt->bind_param( 'issi', $matches[1], $date, $matches[2], $bugIsSecure );
        $stmt->execute();
    }
}

$bug = getField( 'id' );
$type = getField( 'type' );
$date = date( 'Y-m-d H:i:s', getField( 'date' ) );

updateMetadata( $date );

if (strpos( $mailText, 'This email would have contained sensitive information' ) !== FALSE) {
    // you haven't set a PGP/GPG key and this is for a secure bug, so there's no data in it.
    $reason = normalizeReason( getField( 'reason' ), getField( 'watch-reason' ) );
    $fields = array( 'Unknown' );
    $oldvals = array( 'Unknown' );
    $newvals = array( 'Unknown' );
    insertChanges( $bug, $date, $reason, $fields, $oldvals, $newvals );
    success();
} else if ($type == 'request') {
    $matches = array();

    if (preg_match( '/\[Attachment (\d+)\]/', $mailText, $matches ) == 0) {
        fail( 'No attachment id' );
    }
    $attachment = $matches[1];

    if (preg_match( "/Attachment $attachment: (.*)/", $mailString, $matches ) == 0) {
        fail( 'No attachment title' );
    }
    $title = $matches[1];

    $subject = getField( 'subject' );
    if (! checkForField( 'flag-requestee' )) {
        if (strpos( $subject, ' canceled: [Bug' ) !== FALSE) {
            $granted = 0;
            $cancelled = 1;
        } else if (strpos( $subject, ' not granted: [Bug' ) !== FALSE) {
            $granted = 0;
            $cancelled = 0;
        } else if (strpos( $subject, ' granted: [Bug' ) !== FALSE) {
            $granted = 1;
            $cancelled = 0;
        } else {
            fail( 'Unknown review response type' );
        }

        $flag = substr( $subject, 0, strpos( $subject, ' ' ) );
        if ($cancelled) {
            $stmt = prepare( 'UPDATE requests SET cancelled=? WHERE attachment=? AND flag=?' );
            $stmt->bind_param( 'iis', $cancelled, $attachment, $flag );
            $stmt->execute();
            // this may cancel something we don't have a record of; if so, ignore
            success();
        } else {
            if (preg_match( "/\n(.*) <(.*)> has (?:not )?granted/", $mailString, $matches ) == 0) {
                fail( 'Unable to determine author of review' );
            }
            $author = $matches[1];
            $authorEmail = $matches[2];
            $comment = '';
            if (preg_match( "/Additional Comments from.*\n--*-\n\n(.*)/s", $mailString, $matches ) > 0) {
                $comment = $matches[1];
            }
            $stmt = prepare( 'INSERT INTO reviews (bug, stamp, attachment, title, flag, author, authoremail, granted, comment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)' );
            $stmt->bind_param( 'isissssis', $bug, $date, $attachment, $title, $flag, $author, $authorEmail, $granted, $comment );
        }
    } else {
        $requestee = getField( 'flag-requestee' );
        if ($requestee != $_ME) {
            fail( 'Requestee is not me' );
        }
        $flag = substr( $subject, 0, strpos( $subject, ' ' ) );
        if (strpos( $subject, "$flag requested" ) !== 0) {
            fail( 'Unknown request type' );
        }

        $stmt = prepare( 'INSERT INTO requests (bug, stamp, attachment, title, flag) VALUES (?, ?, ?, ?, ?)' );
        $stmt->bind_param( 'isiss', $bug, $date, $attachment, $title, $flag );
    }
    $stmt->execute();
    if ($stmt->affected_rows != 1) {
        fail( 'Unable to insert request into DB: ' . $stmt->error );
    }
    success();
} else if ($type == 'new') {
    $reason = normalizeReason( getField( 'reason' ), getField( 'watch-reason' ) );
    $matches = array();
    if (preg_match( "/Summary: (.*) Classification:/", $mailText, $matches ) == 0) {
        fail( 'No summary for new bug' );
    }
    $title = trim( $matches[1] );
    $author = getField( 'who' );
    $matches = array();
    if (preg_match( "/Bug #: .*?\n\n\n(.*\n\n)?-- \n/s", $mailString, $matches ) == 0) {
        fail( 'No description' );
    }
    $desc = trim( $matches[1] );

    $extracted = saveChanges( $bug, $date, $reason, $desc );
    $desc = trim( substr( $desc, $extracted ) );

    $stmt = prepare( 'INSERT INTO newbugs (bug, stamp, reason, title, author, description) VALUES (?, ?, ?, ?, ?, ?)' );
    $stmt->bind_param( 'isssss', $bug, $date, $reason, $title, $author, $desc );
    $stmt->execute();
    if ($stmt->affected_rows != 1) {
        fail( 'Unable to insert new bug into DB: ' . $stmt->error );
    }
    success();
} else if ($type == 'changed') {
    $reason = normalizeReason( getField( 'reason' ), getField( 'watch-reason' ) );

    if (saveChanges( $bug, $date, $reason, $mailString ) == 0) {
        saveDependencyChanges( $bug, $date, $reason, $mailString );
    }
    saveComments( $bug, $date, $reason, $mailString );

    success();
} else {
    fail( 'Unknown type' );
}

print "Fall through\n";
exit( 0 );

/*
X-Bugzilla-Assigned-To
X-Bugzilla-Changed-Fields
X-Bugzilla-Classification
X-Bugzilla-Component
X-Bugzilla-Flag-Requestee
X-Bugzilla-Keywords
X-Bugzilla-Priority
X-Bugzilla-Product
X-Bugzilla-Reason
X-Bugzilla-Severity
X-Bugzilla-Status
X-Bugzilla-Target-Milestone
X-Bugzilla-Type
X-Bugzilla-URL
X-Bugzilla-Watch-Reason
X-Bugzilla-Who
*/

?>
