#!/usr/bin/php -q
<?php
# Copyright (c) 2026 Grigoriy Ermolaev (igflocal@gmail.com)
# MaxBot for MantisBT is free software: 
# you can redistribute it and/or modify it under the terms of the GNU
# General Public License as published by the Free Software Foundation, 
# either version 2 of the License, or (at your option) any later version.
#
# MaxBot plugin for for MantisBT is distributed in the hope 
# that it will be useful, but WITHOUT ANY WARRANTY; without even the 
# implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
# See the GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Customer management plugin for MantisBT.  
# If not, see <http://www.gnu.org/licenses/>.

/**
 * Cron Script to get updates from MAX by long polling, the alternative to the webhook.
 */

/**
 * Global Bypass http headers
 */
global $g_bypass_headers;
$g_bypass_headers = 1;

require_once( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/core.php' );

if( php_sapi_name() != 'cli' ) {
	echo "get_updates.php is not allowed to run through the webserver.\n";
	exit( 1 );
}

$t_plugin = plugin_get( 'MaxBot' );

if( plugin_needs_upgrade( $t_plugin ) ) {
	error_parameters( 'MaxBot' );
	trigger_error( ERROR_PLUGIN_UPGRADE_NEEDED, ERROR );
}

if( !plugin_is_loaded( 'MaxBot' ) ) {
	error_parameters( 'MaxBot' );
	trigger_error( ERROR_PLUGIN_NOT_LOADED, ERROR );
}

plugin_push_current( 'MaxBot' );

$t_max = maxbot_api();

# The script stays in crontab whatever the settings are: a bot without a token or
# one receiving its updates by the webhook is not polled
if( !$t_max->is_enabled() ) {
	echo "The token of the bot is not set, exiting.\n";
	exit( 0 );
}

if( plugin_config_get( 'update_method' ) != 'script' ) {
	echo "The updates are received by the webhook, exiting.\n";
	exit( 0 );
}

$t_lock_path = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'mantis_maxbot_' . md5( __FILE__ ) . '.lock';

if( file_exists( $t_lock_path ) && ( is_link( $t_lock_path ) || !is_file( $t_lock_path ) ) ) {
	plugin_log_event( 'MAX get updates refused: lock path ' . $t_lock_path . ' is not a regular file.' );
	echo "Lock path $t_lock_path is not a regular file, exiting.\n";
	exit( 1 );
}

$t_lock_handle = @fopen( $t_lock_path, 'c' );

if( $t_lock_handle === false ) {
	# The file of this user always opens - it is created 0600 below, so a failure
	# means a foreign one, and the sticky bit on temp keeps it there until its owner
	# or root removes it
	plugin_log_event( 'MAX get updates refused: lock file ' . $t_lock_path . ' is not writable, probably created by another user.' );
	echo "Lock file $t_lock_path is not writable, exiting.\n";
	exit( 1 );
}

$t_lock_stat = fstat( $t_lock_handle );
if( function_exists( 'posix_geteuid' ) && $t_lock_stat['uid'] !== posix_geteuid() ) {
	plugin_log_event( 'MAX get updates refused: lock file ' . $t_lock_path . ' is owned by another user.' );
	echo "Lock file $t_lock_path is owned by another user, exiting.\n";
	exit( 1 );
}

@chmod( $t_lock_path, 0600 );

# Two polls of one bot at a time would take the updates from each other
if( !flock( $t_lock_handle, LOCK_EX | LOCK_NB ) ) {
	plugin_log_event( 'MAX get updates skipped: another instance is already running.' );
	echo "Another instance is already running, exiting.\n";
	exit( 0 );
}

plugin_config_set( 'get_updates_last_run', time() );

$t_timeout  = min( (int)plugin_config_get( 'get_updates_timeout' ), MaxBotApi::POLL_TIMEOUT_MAX );
$t_run_time = (int)plugin_config_get( 'get_updates_run_time' );

# The connection is held by MAX for the whole timeout, the client must wait longer
$t_response_timeout = (int)plugin_config_get( 'time_out_server_response' );
if( $t_timeout > 0 && $t_timeout >= $t_response_timeout ) {
	$t_timeout = max( 0, $t_response_timeout - 5 );
}

define( 'MAXBOT_UPDATE_PROCESS_INC_ALLOW', true );

$t_marker     = (string)plugin_config_get( 'get_updates_marker' );
$t_started_at = time();

do {
	# The last poll of the run is shortened to the time left instead of being skipped: skipping it
	# leaves the bot deaf until the next scheduled run, which delays the answer by up to a timeout.
	$t_poll_timeout = $t_timeout;
	if( $t_run_time > 0 ) {
		$t_poll_timeout = max( 0, min( $t_timeout, $t_run_time - ( time() - $t_started_at ) ) );
	}

	echo "Get updates...\n";

	try {
		$t_results = $t_max->updates_poll( $t_marker, $t_poll_timeout, $t_marker );
	} catch( Exception $t_error ) {
		plugin_log_event( 'MAX get updates failed: ' . $t_error->getMessage() );
		echo "Get updates failed: " . $t_error->getMessage() . "\n";
		exit( 1 );
	}

	echo "Received " . count( $t_results ) . " updates.\n";

	if( count( $t_results ) > 0 ) {
		plugin_log_event( sprintf( 'Received %d MAX updates, next marker %s.', count( $t_results ), $t_marker ) );

		echo "Start process updates...\n\n";
		include( dirname( dirname( __FILE__ )) . '/pages/update_process_inc.php' );
	}

	# Stored after processing: a run that dies earlier makes MAX give the updates again.
	# The marker moves on an empty answer too, it is stored whenever it changes.
	if( $t_marker !== (string)plugin_config_get( 'get_updates_marker' ) ) {
		plugin_config_set( 'get_updates_marker', $t_marker );
	}

	# Keep polling while there is time left in this run.
	$t_elapsed = time() - $t_started_at;
} while( $t_run_time > 0 && $t_elapsed < $t_run_time );

echo "\nDone.\n\n";

flock( $t_lock_handle, LOCK_UN );
fclose( $t_lock_handle );

exit( 0 );
