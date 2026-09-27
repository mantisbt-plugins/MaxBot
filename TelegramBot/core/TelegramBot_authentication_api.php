<?php

# Copyright (c) 2018 Grigoriy Ermolaev (igflocal@gmail.com)
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

/**
 * Hide the E_DEPRECATED notices raised inside api/vendor/.
 *
 * The pinned longman/telegram-bot cannot be upgraded past the PHP baseline of
 * the plugin, and on a modern PHP the core error handler would print its
 * notices inline on every page that talks to Telegram. Notices of the plugin
 * itself still reach the core handler untouched.
 *
 * @return void
 */
function telegram_vendor_deprecations_suppress() {
	static $s_installed = false;

	if( $s_installed ) {
		return;
	}
	$s_installed = true;

	$t_vendor_dir = realpath( dirname( __FILE__ ) . '/../api/vendor' );
	$t_previous   = null;

	$t_previous = set_error_handler( function( $p_type, $p_error, $p_file, $p_line ) use ( &$t_previous, $t_vendor_dir ) {
		if( ( $p_type & ( E_DEPRECATED | E_USER_DEPRECATED ) ) && strpos( $p_file, $t_vendor_dir ) === 0 ) {
			return true;
		}
		if( $t_previous === null ) {
			return false;
		}
		return call_user_func( $t_previous, $p_type, $p_error, $p_file, $p_line );
	} );
}

/**
 * Check that a messenger account is linked to an enabled MantisBT user and log that
 * user in; an account linked to nobody gets an invitation to link one instead.
 *
 * @param string      $p_transport  Name of the transport the update came from.
 * @param string      $p_account_id Account of the messenger.
 * @param string|null $p_lang_code  Language code the messenger gives for the account.
 * @return boolean
 */
function auth_ensure_telegram_user_authenticated( $p_transport, $p_account_id, $p_lang_code = null ) {

    $t_address = messenger_address_make( $p_transport, $p_account_id );

    plugin_log_event( 'Account ' . $t_address . ' request language: "' . $p_lang_code . '"' );

    $t_mantis_user_id = telegram_account_user_get( $p_transport, $p_account_id );

    if( $t_mantis_user_id == 0 ) {
        lang_push( telegram_lang_map_auto( $p_lang_code ) );
        # a failure of the invitation is logged by the transport
        user_telegram_signup( $p_transport, $p_account_id );
        plugin_log_event( 'Authorization Error! Account ' . $t_address . ' is not mapped to any mantisbt user. As a response, an authorization invitation was sent.' );
        return false;
    } else if( !user_exists( $t_mantis_user_id ) || !user_is_enabled( $t_mantis_user_id ) ) {
        # user_exists() comes first: user_is_enabled() halts on a user that is gone.
        # For the same reason the name for the log is taken from user_get_name(),
        # which answers with the placeholder of a deleted user instead of halting
        lang_push( telegram_lang_map_auto( $p_lang_code ) );
        user_telegram_signup( $p_transport, $p_account_id );
        plugin_log_event( 'Authorization Error! User ' . user_get_name( $t_mantis_user_id ) . ' (id#' . $t_mantis_user_id . ') is disabled or deleted. As a response, an authorization invitation was sent.' );
        return false;
    } else {
        # The account may get disabled between the check above and the login
        if( !auth_attempt_script_login( user_get_username( $t_mantis_user_id ) ) ) {
            plugin_log_event( 'Authorization Error! Script login failed for user ' . user_get_username( $t_mantis_user_id ) . '.' );
            return false;
        }
        plugin_log_event( 'Authorization success! Account ' . $t_address . ' logged in as user: ' . user_get_username( $t_mantis_user_id ) );

        lang_push( telegram_lang_get_default( $p_lang_code ) );
        return true;
    }
}

/**
 * Invite the owner of a messenger account which is not linked to a MantisBT account
 * to link one.
 *
 * @param string $p_transport  Name of the transport.
 * @param string $p_account_id Account of the messenger.
 * @return boolean Whether the invitation went through.
 */
function user_telegram_signup( $p_transport, $p_account_id ) {

    //We correctly form the url, depending on which method of receiving updates from the telegram server is selected.
    $t_url = telegram_mantis_url_get();

    $t_registration_method = (int) plugin_config_get( 'registration_method' );

    # The state row is needed in every method: it holds the id of the invitation
    $t_pin_code = telegram_pin_code_get( $p_transport, $p_account_id );

    # Only one invitation stays in the chat, the previous one is of no use anymore
    telegram_registration_message_remove( $p_transport, $p_account_id );

    $data_signup = array();

    # The link binds the account with one tap, but only works when MantisBT is reachable
    # from the phone; the PIN code is typed by the user in his account preferences instead
    if( $t_registration_method != TELEGRAM_REGISTRATION_PIN ) {
        $t_signup_keyboard = new TelegramBotKeyboard();
        $t_signup_keyboard->addRow( [
                              'text' => plugin_lang_get( 'registration_button_text' ),
                              'url'  => $t_url . plugin_page( 'registred', TRUE )
                                          . '&transport=' . urlencode( $p_transport )
                                          . '&account_id=' . urlencode( $p_account_id )
        ] );

        $data_signup['reply_markup'] = $t_signup_keyboard;
    }

    if( $t_registration_method == TELEGRAM_REGISTRATION_LINK ) {
        $data_signup['text'] = sprintf(
                                                            plugin_lang_get( 'registration_message_text' ),
                                                            config_get( 'window_title' ),
                                                            $t_url
                                      );
    } else {
        $t_lang_key = $t_registration_method == TELEGRAM_REGISTRATION_PIN
                              ? 'registration_message_pin_text'
                              : 'registration_message_both_text';

        $data_signup['text'] = sprintf(
                                                            plugin_lang_get( $t_lang_key ),
                                                            config_get( 'window_title' ),
                                                            $t_url . plugin_page( 'account_telegram_register_page', TRUE ),
                                                            $t_pin_code
                                      );
    }

    $t_message_ids = messenger_send( messenger_address_make( $p_transport, $p_account_id ), $data_signup );

    if( empty( $t_message_ids ) ) {
        return false;
    }

    telegram_registration_message_id_set( $p_transport, $p_account_id, reset( $t_message_ids ) );

    return true;
}
