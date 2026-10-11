<?php

/*
Plugin Name:    Bulk Import and Shorten
Plugin URI:     https://github.com/vaughany/yourls-bulk-import-and-shorten
Description:    A YOURLS plugin allowing importing of URLs in bulk to be shortened or (optionally) with a custom short URL.
Version:        0.5
Release date:   2026-10-11
Requires:       YOURLS 1.10, PHP 8.1
Author:         Paul Vaughan
Author URI:     https://github.com/vaughany/
*/

/**
 * https://docs.yourls.org/development/plugins.html
 * https://docs.yourls.org/development/form-security.html
 * https://github.com/YOURLS/awesome#plugins
 */

// No direct call.
if ( !defined( 'YOURLS_ABSPATH' ) ) { die(); }

// Text domain for translatable strings.
const VAUGHANY_BIAS_DOMAIN = 'vaughany_bias';

// Maximum number of problem rows listed on the page after an import.
const VAUGHANY_BIAS_MAX_REPORTED = 50;

// MIME types (as detected by PHP, not the browser) that we accept as CSV.
const VAUGHANY_BIAS_MIME_TYPES = [
    'text/csv',
    'text/plain',
    'application/csv',
    'application/vnd.ms-excel',
    'text/x-csv',
    'application/x-csv',
    'text/comma-separated-values',
];

// This version relies on APIs from YOURLS 1.10.
if ( version_compare( YOURLS_VERSION, '1.10', '<' ) ) {
    yourls_add_notice( 'Bulk Import and Shorten requires YOURLS 1.10 or later and has not been loaded.', 'error' );
    return;
}

// Register admin page.
yourls_add_action( 'plugins_loaded', 'vaughany_bias_add_page' );

// Handle import, before any page output.
yourls_add_action( 'load-vaughany_bias', 'vaughany_bias_handle_post' );

function vaughany_bias_add_page(): void {
    yourls_register_plugin_page( 'vaughany_bias', yourls__( 'Bulk Import and Shorten', VAUGHANY_BIAS_DOMAIN ), 'vaughany_bias_display_page' );
}

function vaughany_bias_display_page(): void {
    global $vaughany_bias_report;

    $d = VAUGHANY_BIAS_DOMAIN;

    echo '<h2>' . yourls_esc_html__( 'Bulk Import and Shorten', $d ) . '</h2>' . PHP_EOL;
    echo '<p>' . yourls_esc_html__( 'Import links as long URLs and let YOURLS shorten them for you according to your settings.', $d ) . '</p>' . PHP_EOL;
    echo '<p>' . yourls_esc_html__( 'Upload a .csv file in the following format:', $d ) . '</p>' . PHP_EOL;
    echo '<ul>' . PHP_EOL;
    echo '  <li>' . yourls_esc_html__( 'First column - required: a long URL.', $d ) . '</li>' . PHP_EOL;
    echo '  <li>' . yourls_esc_html__( 'Second column - optional: a short URL of your choosing (otherwise one will be created by YOURLS according to your settings).', $d ) . '</li>' . PHP_EOL;
    echo '  <li>' . yourls_esc_html__( 'Third column - optional: a title of your choosing (otherwise one is made from the URL).', $d ) . '</li>' . PHP_EOL;
    echo '</ul>' . PHP_EOL;
    echo '<p>' . yourls_esc_html__( 'If a long URL already exists, it will only be imported again if YOURLS_UNIQUE_URLS is set to false in your config.', $d ) . '</p>' . PHP_EOL;

    echo '<h3>' . yourls_esc_html__( 'Import', $d ) . '</h3>' . PHP_EOL;

    $action = yourls_remove_query_arg( [ 'import', 'export', 'nonce', 'action' ] );
    echo '<form action="' . yourls_esc_url( $action ) . '" method="post" accept-charset="utf-8" enctype="multipart/form-data">' . PHP_EOL;
    echo '  ' . yourls_nonce_field( 'vaughany_bias_import', 'nonce', false, false ) . PHP_EOL;
    echo '  <input type="file" name="import" accept=".csv,text/csv" required>' . PHP_EOL;
    echo '  <input type="submit" name="upload" value="' . yourls_esc_attr__( 'Upload', $d ) . '" class="button">' . PHP_EOL;
    echo '</form>' . PHP_EOL;

    if ( !empty( $vaughany_bias_report['problems'] ) ) {
        vaughany_bias_display_problems( $vaughany_bias_report['problems'] );
    }
}

/**
 * Show the rows which were not imported, or not imported as requested.
 *
 * @param array $problems List of ['row' => int, 'url' => string, 'message' => string].
 */
function vaughany_bias_display_problems( array $problems ): void {
    $d = VAUGHANY_BIAS_DOMAIN;

    echo '<h3>' . yourls_esc_html__( 'Rows needing attention', $d ) . '</h3>' . PHP_EOL;
    echo '<table class="tblSorter">' . PHP_EOL;
    echo '  <thead><tr><th>' . yourls_esc_html__( 'Row', $d ) . '</th><th>' . yourls_esc_html__( 'URL', $d ) . '</th><th>' . yourls_esc_html__( 'Problem', $d ) . '</th></tr></thead>' . PHP_EOL;
    echo '  <tbody>' . PHP_EOL;
    foreach ( array_slice( $problems, 0, VAUGHANY_BIAS_MAX_REPORTED ) as $problem ) {
        echo '    <tr><td>' . (int) $problem['row'] . '</td><td>' . yourls_esc_html( $problem['url'] ) . '</td><td>' . yourls_esc_html( $problem['message'] ) . '</td></tr>' . PHP_EOL;
    }
    echo '  </tbody>' . PHP_EOL;
    echo '</table>' . PHP_EOL;

    if ( count( $problems ) > VAUGHANY_BIAS_MAX_REPORTED ) {
        echo '<p>' . yourls_esc_html( sprintf( yourls__( '...and %d more.', $d ), count( $problems ) - VAUGHANY_BIAS_MAX_REPORTED ) ) . '</p>' . PHP_EOL;
    }
}

function vaughany_bias_handle_post(): void {
    global $vaughany_bias_report;

    $d = VAUGHANY_BIAS_DOMAIN;

    // PHP silently drops uploads when they are disabled, so warn before the user even picks a file.
    if ( !filter_var( ini_get( 'file_uploads' ), FILTER_VALIDATE_BOOLEAN ) ) {
        yourls_add_notice( yourls__( 'File uploads are disabled on this server (file_uploads is off in the PHP settings), so no file can be imported. Ask your administrator or host to enable file uploads.', $d ), 'error' );
        return;
    }

    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
        return;
    }

    if ( empty( $_FILES['import'] ) ) {
        // If $_POST is empty too, PHP dropped the whole request because it was larger than post_max_size.
        if ( empty( $_POST ) && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) {
            yourls_add_notice( sprintf( yourls__( 'No file received: the upload is larger than the server allows (post_max_size is %s). Upload a smaller file, or increase the limit in the PHP settings.', $d ), ini_get( 'post_max_size' ) ), 'error' );
        } else {
            yourls_add_notice( yourls__( 'No file received. Please select a .csv file and try again.', $d ), 'error' );
        }
        return;
    }

    // Dies with a 403 if the nonce is missing or invalid.
    yourls_verify_nonce( 'vaughany_bias_import' );

    $report = vaughany_bias_import_urls( $_FILES['import'] );

    if ( $report === null ) {
        // The file was rejected; a notice has already been added.
        return;
    }

    $vaughany_bias_report = $report;

    $message = sprintf( yourls__( '%d URLs imported.', $d ), $report['imported'] );
    if ( $report['failed'] > 0 ) {
        $message .= ' ' . sprintf( yourls__( '%d rows not imported.', $d ), $report['failed'] );
    }
    if ( $report['substituted'] > 0 ) {
        $message .= ' ' . sprintf( yourls__( '%d requested short URLs were unavailable, so YOURLS created one instead.', $d ), $report['substituted'] );
    }

    yourls_add_notice( $message, $report['imported'] > 0 ? 'success' : 'error' );
}

/**
 * Import the URLs.
 *
 * @param array $file Uploaded file to be imported, from $_FILES.
 * @return array|null Import report, or null if the file was rejected.
 */
function vaughany_bias_import_urls( array $file ): ?array {
    $d = VAUGHANY_BIAS_DOMAIN;

    // Check the upload error code first: when an upload fails, tmp_name is empty and is_uploaded_file() can't say why.
    $error = is_int( $file['error'] ?? null ) ? $file['error'] : UPLOAD_ERR_NO_FILE;
    if ( $error !== UPLOAD_ERR_OK ) {
        yourls_add_notice( vaughany_bias_upload_error_message( $error ), 'error' );
        return null;
    }

    if ( !is_string( $file['tmp_name'] ?? null ) || !is_uploaded_file( $file['tmp_name'] ) ) {
        yourls_add_notice( yourls__( 'The file did not upload correctly.', $d ), 'error' );
        return null;
    }

    // Browsers report all sorts of MIME types for .csv files, so check the extension and the actual content instead.
    if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'csv' ) {
        yourls_add_notice( yourls__( 'Not a .csv file.', $d ), 'error' );
        return null;
    }

    if ( class_exists( 'finfo' ) ) {
        $mime = ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
        if ( !in_array( $mime, VAUGHANY_BIAS_MIME_TYPES, true ) ) {
            yourls_add_notice( yourls__( 'The file does not look like a .csv file.', $d ), 'error' );
            return null;
        }
    }

    $fh = fopen( $file['tmp_name'], 'r' );
    if ( !$fh ) {
        yourls_add_notice( yourls__( 'The uploaded file could not be opened.', $d ), 'error' );
        return null;
    }

    $report = [ 'imported' => 0, 'failed' => 0, 'substituted' => 0, 'problems' => [] ];
    $row    = 0;

    // Skip the flood check for each link: the admin is already logged in, and the check would otherwise stop
    // the import on public installs and send a fresh login cookie header for every row on private ones.
    yourls_add_filter( 'shunt_check_IP_flood', 'vaughany_bias_skip_flood_check' );

    // Get each line in turn as an array, comma-separated.
    while ( ( $csv = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
        $row++;

        // Remove the byte order mark that some spreadsheet software adds.
        if ( $row === 1 && isset( $csv[0] ) ) {
            $csv[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $csv[0] );
        }

        // Skip blank lines.
        $url = trim( (string) ( $csv[0] ?? '' ) );
        if ( $url === '' ) {
            continue;
        }

        $keyword = '';
        if ( !empty( $csv[1] ) ) {
            $original  = trim( $csv[1] );
            $requested = yourls_sanitize_keyword( $original, true );

            // If the requested keyword has no allowed characters or is not free, let YOURLS create one.
            if ( $requested === '' ) {
                $report['substituted']++;
                $report['problems'][] = [
                    'row'     => $row,
                    'url'     => $url,
                    'message' => sprintf( yourls__( 'Short URL "%s" has no allowed characters, so YOURLS will create one.', $d ), $original ),
                ];
            } elseif ( !yourls_keyword_is_free( $requested ) ) {
                $report['substituted']++;
                $report['problems'][] = [
                    'row'     => $row,
                    'url'     => $url,
                    'message' => sprintf( yourls__( 'Short URL "%s" is not available, so YOURLS will create one.', $d ), $requested ),
                ];
            } else {
                $keyword = $requested;
                if ( $requested !== $original ) {
                    $report['problems'][] = [
                        'row'     => $row,
                        'url'     => $url,
                        'message' => sprintf( yourls__( 'Short URL "%1$s" changed to "%2$s" to fit the allowed characters.', $d ), $original, $requested ),
                    ];
                }
            }
        }

        if ( !empty( $csv[2] ) && trim( $csv[2] ) !== '' ) {
            $title = trim( $csv[2] );
        } else {
            $title = vaughany_bias_create_title_from_url( $url );
        }

        // Add a new link (passing the keyword) and get the result.
        $result = yourls_add_new_link( $url, $keyword, $title );

        if ( ( $result['status'] ?? '' ) === 'success' ) {
            $report['imported']++;
        } else {
            $report['failed']++;
            $report['problems'][] = [
                'row'     => $row,
                'url'     => $url,
                'message' => strip_tags( (string) ( $result['message'] ?? yourls__( 'Unknown error.', $d ) ) ),
            ];
        }
    }

    fclose( $fh );
    yourls_remove_filter( 'shunt_check_IP_flood', 'vaughany_bias_skip_flood_check' );

    return $report;
}

/**
 * Explain a PHP upload error.
 *
 * @param int $error One of the UPLOAD_ERR_* constants.
 * @return string
 */
function vaughany_bias_upload_error_message( int $error ): string {
    $d = VAUGHANY_BIAS_DOMAIN;

    return match ( $error ) {
        UPLOAD_ERR_INI_SIZE   => sprintf( yourls__( 'The file is larger than the server allows (upload_max_filesize is %s).', $d ), ini_get( 'upload_max_filesize' ) ),
        UPLOAD_ERR_FORM_SIZE  => yourls__( 'The file is larger than the upload form allows.', $d ),
        UPLOAD_ERR_PARTIAL    => yourls__( 'The file was only partially uploaded. Please try again.', $d ),
        UPLOAD_ERR_NO_FILE    => yourls__( 'Please select a .csv file first.', $d ),
        UPLOAD_ERR_NO_TMP_DIR => yourls__( 'The server has no temporary folder for uploads. Contact your administrator.', $d ),
        UPLOAD_ERR_CANT_WRITE => yourls__( 'The server could not save the uploaded file. Contact your administrator.', $d ),
        UPLOAD_ERR_EXTENSION  => yourls__( 'A PHP extension stopped the upload. Contact your administrator.', $d ),
        default               => sprintf( yourls__( 'The upload failed (error code %d).', $d ), $error ),
    };
}

/**
 * Filter callback to pass the flood check during an import.
 */
function vaughany_bias_skip_flood_check(): bool {
    return true;
}

/**
 * Create a title from the URL, so YOURLS does not fetch one from the URL itself, slowing down the import.
 *
 * @param string $url
 * @return string
 */
function vaughany_bias_create_title_from_url( string $url ): string {
    return yourls_sanitize_title( str_replace( [ 'http://', 'https://', '/' ], '', $url ) );
}
