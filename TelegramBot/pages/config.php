<?php
# Copyright (c) 2023 Grigoriy Ermolaev (igflocal@gmail.com)
# TelegramBot for MantisBT is free software: 
# you can redistribute it and/or modify it under the terms of the GNU
# General Public License as published by the Free Software Foundation, 
# either version 2 of the License, or (at your option) any later version.
#
# TelegramBot plugin for for MantisBT is distributed in the hope 
# that it will be useful, but WITHOUT ANY WARRANTY; without even the 
# implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
# See the GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Customer management plugin for MantisBT.  
# If not, see <http://www.gnu.org/licenses/>.

use Mantis\Exceptions\ClientException;

form_security_validate( 'config' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

$f_bot_name			= gpc_get_string   ( 'bot_username' );
$f_api_key			= gpc_get_string   ( 'api_key' );
$f_reinstall_webhook            = gpc_get_bool     ( 'reinstall_webhook' );
$f_registration_method          = gpc_get_int      ( 'registration_method', plugin_config_get( 'registration_method' ) );
$f_pin_code_attempts_max        = gpc_get_int      ( 'pin_code_attempts_max', plugin_config_get( 'pin_code_attempts_max' ) );
$f_pin_code_attempts_window     = gpc_get_int      ( 'pin_code_attempts_window', plugin_config_get( 'pin_code_attempts_window' ) );
$f_admin_unlink_notify          = gpc_get_bool     ( 'admin_unlink_notify' );
# the use_cert radio is only rendered in Webhook mode: fall back to the stored
# value so that saving the form in Script mode does not wipe the certificate
$f_use_cert                     = gpc_get_bool     ( 'use_cert', plugin_config_get( 'use_cert' ) == ON );
$f_bot_cert_file                = gpc_get_file     ( 'bot_cert_file', null );
$f_proxy_address		= gpc_get_string   ( 'proxy_address', '' );
$f_time_out_server_response	= gpc_get_int      ( 'time_out_server_response' );
$f_get_updates_timeout		= gpc_get_int      ( 'get_updates_timeout', plugin_config_get( 'get_updates_timeout' ) );
$f_get_updates_run_time		= gpc_get_int      ( 'get_updates_run_time', plugin_config_get( 'get_updates_run_time' ) );
$f_debug_connection_log_path    = gpc_get_string   ( 'debug_connection_log_path', '' );
$f_debug_connection_enabled	= gpc_get_bool     ( 'debug_connection_enabled', FALSE );
$f_cli_g_path                   = gpc_get_string   ( 'cli_g_path', plugin_config_get( 'cli_g_path' ) );

# The settings of MAX beyond its switch are drawn only while the stored switch is on:
# fall back to the stored values for the ones the form did not carry
$t_max_was_enabled              = ON == (int)plugin_config_get( 'max_enabled' );
$f_max_enabled                  = gpc_get_bool     ( 'max_enabled', $t_max_was_enabled );
$f_max_api_key                  = trim( gpc_get_string( 'max_api_key', plugin_config_get( 'max_api_key' ) ) );
$f_max_update_method            = gpc_get_string   ( 'max_update_method', plugin_config_get( 'max_update_method' ) );
$f_max_get_updates_timeout      = gpc_get_int      ( 'max_get_updates_timeout', plugin_config_get( 'max_get_updates_timeout' ) );
$f_max_get_updates_run_time     = gpc_get_int      ( 'max_get_updates_run_time', plugin_config_get( 'max_get_updates_run_time' ) );

$t_cert_uploaded = $f_bot_cert_file !== null && $f_bot_cert_file['error'] !== UPLOAD_ERR_NO_FILE;

if( $t_cert_uploaded ) {
        $t_tmp_file = $f_bot_cert_file['tmp_name'];

	file_ensure_uploaded( $f_bot_cert_file );

	$t_file_name = $f_bot_cert_file['name'];

	if(
                strcasecmp( pathinfo( $t_file_name, PATHINFO_EXTENSION ), 'crt' ) != 0
                && strcasecmp( pathinfo( $t_file_name, PATHINFO_EXTENSION ), 'pem' ) != 0
                && strcasecmp( pathinfo( $t_file_name, PATHINFO_EXTENSION ), 'cer' ) != 0
        ) {
		throw new ClientException(
			sprintf( "File '%s' type not allowed", $t_file_name ),
			ERROR_FILE_NOT_ALLOWED
		);
	}

	$t_file_size = filesize( $t_tmp_file );
	if( 0 == $t_file_size ) {
		throw new ClientException(
			sprintf( "File '%s' not uploaded", $t_file_name ),
			ERROR_FILE_NO_UPLOAD_FAILURE );
	}

	$t_max_file_size = (int)min( ini_get_number( 'upload_max_filesize' ), ini_get_number( 'post_max_size' ), config_get( 'max_file_size' ) );
	if( $t_file_size > $t_max_file_size ) {
		throw new ClientException(
			sprintf( "File '%s' too big", $t_file_name ),
			ERROR_FILE_TOO_BIG );
	}

        # store the raw file content: plugin_config_set() does its own escaping,
        # db_prepare_binary_string() would corrupt the value on pgsql/mssql
        $t_content = file_get_contents( $t_tmp_file );
        if( $t_content === false ) {
		throw new ClientException(
			sprintf( "File '%s' not uploaded", $t_file_name ),
			ERROR_FILE_NO_UPLOAD_FAILURE );
        }

        plugin_config_set( 'bot_cert', $t_content );
        plugin_config_set( 'use_cert', ON );

        unlink($t_tmp_file);
} else if( $f_use_cert ) {
        # validate before any config writes to avoid the inconsistent
        # "use_cert is ON but no certificate is stored" state
        if( plugin_config_get( 'bot_cert' ) == '' ) {
                error_parameters( plugin_lang_get( 'bot_cert' ) );
                plugin_error( 'ERROR_CERT_FILE_NOT_FOUND', ERROR );
        }

        if( plugin_config_get( 'use_cert' ) != ON ) {
                plugin_config_set( 'use_cert', ON );
        }
} else {
        plugin_config_delete( 'bot_cert' );
        plugin_config_delete( 'use_cert' );
}

if( plugin_config_get( 'bot_name' ) != $f_bot_name ) {
	plugin_config_set( 'bot_name', $f_bot_name );
}

if( plugin_config_get( 'api_key' ) != $f_api_key ) {
	plugin_config_set( 'api_key', $f_api_key );
}

if( plugin_config_get( 'reinstall_webhook' ) != $f_reinstall_webhook ) {
	# ON/OFF, not a PHP boolean: plugin_config_set() would store false as an empty string
	plugin_config_set( 'reinstall_webhook', $f_reinstall_webhook ? ON : OFF );
}

if( !in_array( $f_registration_method, array( TELEGRAM_REGISTRATION_LINK, TELEGRAM_REGISTRATION_PIN, TELEGRAM_REGISTRATION_BOTH ), true ) ) {
	$f_registration_method = TELEGRAM_REGISTRATION_LINK;
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
 * Store the settings of MAX and bring its subscription in line with them.
 *
 * Nothing of MAX runs while it stays switched off. Switching it off drops the
 * webhook first, while the token to do it is still in force; a MAX switched on
 * just now has no settings on the form yet, they come with the next save.
 *
 * @param boolean $p_was_enabled   Stored switch, the one the form was drawn by.
 * @param boolean $p_enabled       Switch from the form.
 * @param string  $p_api_key       Token of the bot.
 * @param string  $p_update_method 'webhook' or 'script'.
 * @param integer $p_timeout       Seconds MAX holds a long polling request.
 * @param integer $p_run_time      Seconds a run of the long polling script lasts.
 * @param boolean $p_failed        Out: set when something went wrong.
 * @return array Lines telling the administrator what was done.
 */
function telegram_config_max_apply( $p_was_enabled, $p_enabled, $p_api_key, $p_update_method, $p_timeout, $p_run_time, &$p_failed ) {
        $t_notes = array();
        $t_max   = messenger_transport( 'max' );

        if( $p_was_enabled && !$p_enabled && $t_max !== NULL && $t_max->is_enabled()
                && plugin_config_get( 'max_update_method' ) != 'script' ) {
                # an abandoned subscription would be retried by MAX for hours
                if( telegram_config_max_call( $t_max, 'webhook_delete', array(), $t_notes ) ) {
                        $t_notes[] = plugin_lang_get( 'max_webhook_deleted' );
                } else {
                        $p_failed = true;
                }
        }

        if( $p_was_enabled != $p_enabled ) {
                # ON/OFF, not a PHP boolean: plugin_config_set() would store false as an empty string
                plugin_config_set( 'max_enabled', $p_enabled ? ON : OFF );
        }

        if( !$p_was_enabled || !$p_enabled ) {
                return $t_notes;
        }

        if( $t_max === NULL ) {
                $p_failed  = true;
                $t_notes[] = plugin_lang_get( 'max_transport_missing' );
                return $t_notes;
        }

        $t_api_key_changed = plugin_config_get( 'max_api_key' ) != $p_api_key;

        if( $t_api_key_changed ) {
                plugin_config_set( 'max_api_key', $p_api_key );
                # the name belongs to the bot of the old token
                plugin_config_set( 'max_bot_name', '' );
        }

        if( !in_array( $p_update_method, array( 'webhook', 'script' ), true ) ) {
                $p_update_method = 'webhook';
        }

        if( plugin_config_get( 'max_update_method' ) != $p_update_method ) {
                plugin_config_set( 'max_update_method', $p_update_method );
        }

        # MAX refuses a longer wait; the script itself keeps it below the timeout of the server
        $p_timeout = min( max( 0, $p_timeout ), MaxTransport::POLL_TIMEOUT_MAX );

        if( plugin_config_get( 'max_get_updates_timeout' ) != $p_timeout ) {
                plugin_config_set( 'max_get_updates_timeout', $p_timeout );
        }

        $p_run_time = max( 0, $p_run_time );

        if( plugin_config_get( 'max_get_updates_run_time' ) != $p_run_time ) {
                plugin_config_set( 'max_get_updates_run_time', $p_run_time );
        }

        if( !$t_max->is_enabled() ) {
                $t_notes[] = plugin_lang_get( 'max_api_key_missing' );
                return $t_notes;
        }

        # The name of the bot makes the deep link to its chat; asking for it also
        # tells a wrong token right away
        if( $t_api_key_changed || is_blank( plugin_config_get( 'max_bot_name' ) ) ) {
                $t_me = telegram_config_max_call( $t_max, 'me', array(), $t_notes );

                if( is_array( $t_me ) && !is_blank( (string)$t_me['username'] ) ) {
                        plugin_config_set( 'max_bot_name', (string)$t_me['username'] );
                } else {
                        $p_failed  = true;
                        $t_notes[] = plugin_lang_get( 'max_me_failed' );
                }
        }

        # The webhook and the long polling exclude each other, as with Telegram
        if( $p_update_method == 'webhook' ) {
                $t_url = telegram_max_webhook_url_get();

                if( !telegram_max_webhook_url_is_valid( $t_url ) ) {
                        $p_failed  = true;
                        $t_notes[] = plugin_lang_get( 'max_webhook_url_invalid' );
                } else if( telegram_config_max_call( $t_max, 'webhook_set', array( $t_url ), $t_notes ) ) {
                        $t_notes[] = plugin_lang_get( 'max_webhook_set' );
                } else {
                        $p_failed = true;
                }
        } else if( telegram_config_max_call( $t_max, 'webhook_delete', array(), $t_notes ) ) {
                $t_notes[] = plugin_lang_get( 'max_webhook_deleted' );
        } else {
                $p_failed = true;
        }

        return $t_notes;
}

/**
 * Call a method of the MAX transport, turning a failure into a line for the
 * administrator.
 *
 * @param TelegramBotTransport $p_max    The MAX transport.
 * @param string               $p_method Name of the method.
 * @param array                $p_args   Its arguments.
 * @param array                $p_notes  Out: the lines, a failure is added to them.
 * @return mixed What the method returned, FALSE when it failed.
 */
function telegram_config_max_call( TelegramBotTransport $p_max, $p_method, array $p_args, array &$p_notes ) {
        try {
                $t_result = call_user_func_array( array( $p_max, $p_method ), $p_args );
        } catch( Exception $t_error ) {
                # the network fails the same way the API does: no route, timeout, proxy
                $p_notes[] = plugin_lang_get( 'response_from_max' ) . string_display_line( $t_error->getMessage() );
                return FALSE;
        }

        if( $t_result === FALSE || $t_result === NULL ) {
                $p_notes[] = plugin_lang_get( 'response_from_max' ) . plugin_lang_get( 'max_request_failed' );
                return FALSE;
        }

        return $t_result;
}

$t_failed = false;
$t_notes  = telegram_config_max_apply( $t_max_was_enabled, $f_max_enabled, $f_max_api_key, $f_max_update_method,
        $f_max_get_updates_timeout, $f_max_get_updates_run_time, $t_failed );

form_security_purge( 'config' );

$t_redirect_url = plugin_page( 'config_page', true );
layout_page_header();
layout_page_begin();

# The webhook belongs to Telegram alone, the other messengers keep their own
$t_telegram = messenger_transport( 'tg' );

try {
        if( $f_reinstall_webhook == ON ) {
                $t_url         = config_get_global( 'path' ) . plugin_page( 'hook', TRUE ) . '&token=' . plugin_config_get( 'api_key' );
                $t_certificate = plugin_config_get( 'use_cert' ) == ON ? plugin_config_get( 'bot_cert' ) : '';

                $t_answer = $t_telegram->webhook_set( $t_url, $t_certificate );
        } else {
                $t_answer = $t_telegram->webhook_delete();
        }

        array_unshift( $t_notes, plugin_lang_get( 'response_from_telegram' ) . $t_answer );
} catch( Exception $t_errors ) {
        # the library and the network fail alike: no route to the API, timeout, proxy
        $t_failed = true;
        array_unshift( $t_notes, plugin_lang_get( 'response_from_telegram' ) . $t_errors->getMessage() );
}

if( $t_failed ) {
        html_operation_failure( $t_redirect_url, implode( '<br>', $t_notes ) );
} else {
        html_operation_successful( $t_redirect_url, implode( '<br>', $t_notes ) );
}
layout_page_end();
