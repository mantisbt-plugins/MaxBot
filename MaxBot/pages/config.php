<?php
# Copyright (c) 2026 Grigoriy Ermolaev (igflocal@gmail.com)
# MaxBot for MantisBT is free software:
# you can redistribute it and/or modify it under the terms of the GNU
# General Public License as published by the Free Software Foundation,
# either version 2 of the License, or (at your option) any later version.
#
# MaxBot plugin for MantisBT is distributed in the hope
# that it will be useful, but WITHOUT ANY WARRANTY; without even the
# implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# See the GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MaxBot plugin for MantisBT.
# If not, see <http://www.gnu.org/licenses/>.

use Mantis\Exceptions\ClientException;

form_security_validate( 'plugin_MaxBot_config' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

$f_api_key                      = trim( gpc_get_string( 'api_key' ) );
$f_update_method                = gpc_get_string   ( 'update_method', plugin_config_get( 'update_method' ) );
$f_registration_method          = gpc_get_int      ( 'registration_method', plugin_config_get( 'registration_method' ) );
$f_pin_code_attempts_max        = gpc_get_int      ( 'pin_code_attempts_max', plugin_config_get( 'pin_code_attempts_max' ) );
$f_pin_code_attempts_window     = gpc_get_int      ( 'pin_code_attempts_window', plugin_config_get( 'pin_code_attempts_window' ) );
$f_admin_unlink_notify          = gpc_get_bool     ( 'admin_unlink_notify' );
$f_proxy_address                = gpc_get_string   ( 'proxy_address', '' );
$f_time_out_server_response     = gpc_get_int      ( 'time_out_server_response' );
# the fields of the long polling are only rendered in Script mode: fall back to the
# stored values for the ones the form did not carry
$f_get_updates_timeout          = gpc_get_int      ( 'get_updates_timeout', plugin_config_get( 'get_updates_timeout' ) );
$f_get_updates_run_time         = gpc_get_int      ( 'get_updates_run_time', plugin_config_get( 'get_updates_run_time' ) );
$f_debug_connection_log_path    = gpc_get_string   ( 'debug_connection_log_path', '' );
$f_debug_connection_enabled     = gpc_get_bool     ( 'debug_connection_enabled', FALSE );
$f_cli_g_path                   = gpc_get_string   ( 'cli_g_path', plugin_config_get( 'cli_g_path' ) );

if( !in_array( $f_registration_method, array( MAXBOT_REGISTRATION_LINK, MAXBOT_REGISTRATION_PIN, MAXBOT_REGISTRATION_BOTH ), true ) ) {
	$f_registration_method = MAXBOT_REGISTRATION_LINK;
}

if( plugin_config_get( 'registration_method' ) != $f_registration_method ) {
	plugin_config_set( 'registration_method', $f_registration_method );
}

# Zero or a negative value would turn the brute force protection off entirely
$f_pin_code_attempts_max    = max( 1, $f_pin_code_attempts_max );
$f_pin_code_attempts_window = max( 1, $f_pin_code_attempts_window );

if( plugin_config_get( 'pin_code_attempts_max' ) != $f_pin_code_attempts_max ) {
	plugin_config_set( 'pin_code_attempts_max', $f_pin_code_attempts_max );
}

if( plugin_config_get( 'pin_code_attempts_window' ) != $f_pin_code_attempts_window ) {
	plugin_config_set( 'pin_code_attempts_window', $f_pin_code_attempts_window );
}

if( plugin_config_get( 'admin_unlink_notify' ) != $f_admin_unlink_notify ) {
	# ON/OFF, not a PHP boolean: plugin_config_set() would store false as an empty string
	plugin_config_set( 'admin_unlink_notify', $f_admin_unlink_notify ? ON : OFF );
}

if( plugin_config_get( 'proxy_address' ) != $f_proxy_address ) {
	plugin_config_set( 'proxy_address', $f_proxy_address );
}

if( plugin_config_get( 'cli_g_path' ) != $f_cli_g_path ) {
	plugin_config_set( 'cli_g_path', $f_cli_g_path );
}

if( plugin_config_get( 'time_out_server_response' ) != $f_time_out_server_response ) {
	plugin_config_set( 'time_out_server_response', $f_time_out_server_response );
}

# MAX refuses a longer wait; the script itself keeps it below the timeout of the server
$f_get_updates_timeout  = min( max( 0, $f_get_updates_timeout ), MaxBotApi::POLL_TIMEOUT_MAX );
$f_get_updates_run_time = max( 0, $f_get_updates_run_time );

if( plugin_config_get( 'get_updates_timeout' ) != $f_get_updates_timeout ) {
	plugin_config_set( 'get_updates_timeout', $f_get_updates_timeout );
}

if( plugin_config_get( 'get_updates_run_time' ) != $f_get_updates_run_time ) {
	plugin_config_set( 'get_updates_run_time', $f_get_updates_run_time );
}

if( $f_debug_connection_enabled == ON ) {
	$t_log_handle = @fopen( $f_debug_connection_log_path, 'a' );
	if( $t_log_handle !== false ) {
		fclose( $t_log_handle );
		plugin_config_set( 'debug_connection_enabled', $f_debug_connection_enabled ? ON : OFF );
		plugin_config_set( 'debug_connection_log_path', $f_debug_connection_log_path );
	} else {
		plugin_config_set( 'debug_connection_enabled', OFF );
		plugin_config_set( 'debug_connection_log_path', $f_debug_connection_log_path );
		throw new ClientException( 'Cannot access write file.', ERROR_FILE_INVALID_UPLOAD_PATH );
	}
} else {
	plugin_config_set( 'debug_connection_enabled', OFF );
	plugin_config_set( 'debug_connection_log_path', $f_debug_connection_log_path );
}

/**
 * Call a method of the MAX client, turning a failure into a line for the
 * administrator.
 *
 * @param MaxBotApi $p_max    The MAX client.
 * @param string    $p_method Name of the method.
 * @param array     $p_args   Its arguments.
 * @param array     $p_notes  Out: the lines, a failure is added to them.
 * @return mixed What the method returned, FALSE when it failed.
 */
function maxbot_config_api_call( MaxBotApi $p_max, $p_method, array $p_args, array &$p_notes ) {
        try {
                $t_result = call_user_func_array( array( $p_max, $p_method ), $p_args );
        } catch( Exception $t_error ) {
                # the network fails the same way the API does: no route, timeout, proxy
                $p_notes[] = plugin_lang_get( 'api_response' ) . string_display_line( $t_error->getMessage() );
                return FALSE;
        }

        if( $t_result === FALSE || $t_result === NULL ) {
                $p_notes[] = plugin_lang_get( 'api_response' ) . plugin_lang_get( 'request_failed' );
                return FALSE;
        }

        return $t_result;
}

/**
 * Store the token and the way the updates are received and bring the webhook
 * subscription of the bot in line with them.
 *
 * A token removed or replaced drops the subscription made with the old one first,
 * while it is still in force: an abandoned subscription is retried by MAX for hours.
 *
 * @param string  $p_api_key       Token of the bot.
 * @param string  $p_update_method 'webhook' or 'script'.
 * @param boolean $p_failed        Out: set when something went wrong.
 * @return array Lines telling the administrator what was done.
 */
function maxbot_config_bot_apply( $p_api_key, $p_update_method, &$p_failed ) {
        $t_notes = array();
        $t_max   = maxbot_api();

        if( !in_array( $p_update_method, array( 'webhook', 'script' ), true ) ) {
                $p_update_method = 'webhook';
        }

        $t_api_key_changed = plugin_config_get( 'api_key' ) != $p_api_key;

        if( $t_api_key_changed && $t_max->is_enabled() && plugin_config_get( 'update_method' ) != 'script' ) {
                if( maxbot_config_api_call( $t_max, 'webhook_delete', array(), $t_notes ) ) {
                        $t_notes[] = plugin_lang_get( 'webhook_deleted' );
                } else {
                        $p_failed = true;
                }
        }

        if( $t_api_key_changed ) {
                plugin_config_set( 'api_key', $p_api_key );
                # the name belongs to the bot of the old token
                plugin_config_set( 'bot_name', '' );
        }

        if( plugin_config_get( 'update_method' ) != $p_update_method ) {
                plugin_config_set( 'update_method', $p_update_method );
        }

        if( !$t_max->is_enabled() ) {
                $t_notes[] = plugin_lang_get( 'api_key_missing' );
                return $t_notes;
        }

        # The name of the bot makes the deep link to its chat; asking for it also
        # tells a wrong token right away
        if( $t_api_key_changed || is_blank( plugin_config_get( 'bot_name' ) ) ) {
                $t_me = maxbot_config_api_call( $t_max, 'me', array(), $t_notes );

                if( is_array( $t_me ) && !is_blank( (string)$t_me['username'] ) ) {
                        plugin_config_set( 'bot_name', (string)$t_me['username'] );
                } else {
                        $p_failed  = true;
                        $t_notes[] = plugin_lang_get( 'bot_name_failed' );
                }
        }

        # The webhook and the long polling exclude each other: MAX gives nothing
        # to the long polling while a subscription exists
        if( $p_update_method == 'webhook' ) {
                $t_url = maxbot_webhook_url_get();

                if( !maxbot_webhook_url_is_valid( $t_url ) ) {
                        $p_failed  = true;
                        $t_notes[] = plugin_lang_get( 'webhook_url_invalid' );
                } else if( maxbot_config_api_call( $t_max, 'webhook_set', array( $t_url ), $t_notes ) ) {
                        $t_notes[] = plugin_lang_get( 'webhook_set' );
                } else {
                        $p_failed = true;
                }
        } else if( maxbot_config_api_call( $t_max, 'webhook_delete', array(), $t_notes ) ) {
                $t_notes[] = plugin_lang_get( 'webhook_deleted' );
        } else {
                $p_failed = true;
        }

        return $t_notes;
}

$t_failed = false;
$t_notes  = maxbot_config_bot_apply( $f_api_key, $f_update_method, $t_failed );

form_security_purge( 'plugin_MaxBot_config' );

$t_redirect_url = plugin_page( 'config_page', true );
layout_page_header();
layout_page_begin();

if( $t_failed ) {
        html_operation_failure( $t_redirect_url, implode( '<br>', $t_notes ) );
} else {
        html_operation_successful( $t_redirect_url, implode( '<br>', $t_notes ) );
}
layout_page_end();
