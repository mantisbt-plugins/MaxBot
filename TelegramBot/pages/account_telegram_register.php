<?php

# Copyright (c) 2026 Grigoriy Ermolaev (igflocal@gmail.com)
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

form_security_validate( 'account_telegram_register' );

auth_ensure_user_authenticated();

current_user_ensure_unprotected();

if( TELEGRAM_REGISTRATION_LINK == (int) plugin_config_get( 'registration_method' ) ) {
    access_denied();
}

$f_pin_code = gpc_get_int( 'pin_code' );

$t_user_id = auth_get_current_user_id();

if( telegram_user_accounts_complete( $t_user_id ) ) {
    plugin_error( 'ERROR_TG_USER_ALREADY_ASSOCIATED', ERROR );
}

# A 4-digit code survives only a counted number of guesses: without the limit it is
# brute forced within the TTL and somebody else's pending chat gets hijacked
if( telegram_pin_code_attempts_exceeded( $t_user_id ) ) {
    plugin_log_event( 'Registration Error! Too many PIN code attempts by user ' . user_get_username( $t_user_id ) );
    plugin_error( 'ERROR_TG_PIN_CODE_ATTEMPTS', ERROR );
}

# The user types the code the bot has shown him, so the account id never travels
# through the browser and the binding is confirmed by the owner of the chat himself
$t_account = telegram_pin_code_account_get( $f_pin_code );

if( $t_account === NULL ) {
    telegram_pin_code_attempt_failed( $t_user_id );

    # The state row outlives the code, so an expired one still tells which chat asked
    # for the binding: a fresh code goes there at once, no new message to the bot needed
    $t_expired_account = telegram_pin_code_account_get( $f_pin_code, /* expired */ true );

    if( $t_expired_account !== NULL && 0 == telegram_account_user_get( $t_expired_account['transport'], $t_expired_account['account_id'] ) ) {
        user_telegram_signup( $t_expired_account['transport'], $t_expired_account['account_id'] );

        plugin_log_event( 'Expired PIN code entered by user ' . user_get_username( $t_user_id )
                . ', a new one was sent to ' . messenger_address_make( $t_expired_account['transport'], $t_expired_account['account_id'] ) );
        plugin_error( 'ERROR_TG_PIN_CODE_EXPIRED', ERROR );
    }
}

# A code of an already linked chat must not be reused
if( $t_account === NULL || 0 != telegram_account_user_get( $t_account['transport'], $t_account['account_id'] ) ) {
    # The unknown code is already counted above, a known but dead one is counted here
    if( $t_account !== NULL ) {
        telegram_pin_code_attempt_failed( $t_user_id );
    }

    plugin_log_event( 'Registration Error! Invalid PIN code entered by user ' . user_get_username( $t_user_id ) );
    plugin_error( 'ERROR_TG_PIN_CODE_INVALID', ERROR );
}

# One account per messenger: the user already linked to the messenger of the code
# releases that binding first
if( !is_blank( telegram_account_get( $t_user_id, $t_account['transport'] ) ) ) {
    plugin_error( 'ERROR_TG_USER_ALREADY_ASSOCIATED', ERROR );
}

telegram_pin_code_attempts_reset( $t_user_id );

$t_address = messenger_address_make( $t_account['transport'], $t_account['account_id'] );

telegram_account_link( $t_user_id, $t_account['transport'], $t_account['account_id'] );
telegram_registration_complete( $t_account['transport'], $t_account['account_id'] );

plugin_log_event( 'Account ' . $t_address . ' is mapped to mantisbt user ' . user_get_username( $t_user_id ) . ' by PIN code' );

messenger_send( $t_address, array( 'text' => telegram_message_first_text() ) );

form_security_purge( 'account_telegram_register' );

$t_redirect_url = plugin_page( 'account_telegram_prefs_page', TRUE );

layout_page_header( null, $t_redirect_url );

layout_page_begin();

html_operation_successful( $t_redirect_url, plugin_lang_get( 'bot_successfully_attached' ) );

layout_page_end();
