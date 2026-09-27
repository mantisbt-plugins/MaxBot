<?php

# Copyright (c) 2024 Grigoriy Ermolaev (igflocal@gmail.com)
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
 * Every binding of a MantisBT user to a MAX account.
 *
 * @return array Rows 'mantis_user_id', 'account_id', ordered by user.
 */
function telegram_accounts_all_get() {
    $t_account_table = plugin_table( 'account' );

    db_param_push();

    $t_query  = "SELECT mantis_user_id, account_id
			FROM $t_account_table
			ORDER BY mantis_user_id";
    $t_result = db_query( $t_query );

    $t_rows = array();
    while( $t_row = db_fetch_array( $t_result ) ) {
        $t_rows[] = array(
                                  'mantis_user_id' => (int)$t_row['mantis_user_id'],
                                  'account_id'     => (string)$t_row['account_id'],
        );
    }

    return $t_rows;
}

/**
 * The MAX account a MantisBT user is linked to.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return string Account id, empty when the user is not linked.
 */
function telegram_account_get( $p_user_id ) {
    $t_account_table = plugin_table( 'account' );

    db_param_push();

    $t_query  = "SELECT account_id
			FROM $t_account_table
			WHERE mantis_user_id=" . db_param();
    $t_result = db_query( $t_query, array( (int)$p_user_id ) );

    $t_row = db_fetch_array( $t_result );

    return $t_row === false ? '' : (string)$t_row['account_id'];
}

/**
 * The MantisBT user a MAX account is linked to.
 *
 * @param string $p_account_id Account of MAX, the user id.
 * @return integer MantisBT user id, 0 when the account is not linked.
 */
function telegram_account_user_get( $p_account_id ) {
    $t_account_table = plugin_table( 'account' );

    db_param_push();

    $t_query  = "SELECT mantis_user_id
			FROM $t_account_table
			WHERE account_id=" . db_param();
    $t_result = db_query( $t_query, array( (string)$p_account_id ) );

    $t_row = db_fetch_array( $t_result );

    return $t_row === false ? 0 : (int)$t_row['mantis_user_id'];
}

/**
 * Link a MAX account to a MantisBT user. A user has one account and an account
 * belongs to one user, so the bindings standing in the way are replaced.
 *
 * @param integer $p_user_id    MantisBT user id.
 * @param string  $p_account_id Account of MAX, the user id.
 * @return void
 */
function telegram_account_link( $p_user_id, $p_account_id ) {
    $t_account_table = plugin_table( 'account' );

    db_param_push();

    $t_query = "DELETE FROM $t_account_table
			WHERE account_id=" . db_param() . ' OR mantis_user_id=' . db_param();
    db_query( $t_query, array( (string)$p_account_id, (int)$p_user_id ) );

    db_param_push();

    $t_query = "INSERT INTO $t_account_table
                                                ( mantis_user_id, account_id )
                                              VALUES
                                                ( " . db_param() . ',' . db_param() . ')';
    db_query( $t_query, array( (int)$p_user_id, (string)$p_account_id ) );
}

# Deep link base of a chat with a bot in MAX, followed by the name of the bot
define( 'TELEGRAM_BOT_CHAT_URL', 'https://max.ru/' );

/**
 * The chat with the bot, for the links of the pages of MantisBT.
 *
 * @return array|null array( 'name' => name of the bot as shown, 'url' => link opening
 *                    the chat ); NULL when the name of the bot is not known.
 */
function telegram_bot_chat_get() {
    # the name is taken from the API when the token is saved
    $t_bot_name = (string)plugin_config_get( 'bot_name', '' );

    if( is_blank( $t_bot_name ) ) {
        return NULL;
    }

    return array( 'name' => $t_bot_name, 'url' => TELEGRAM_BOT_CHAT_URL . rawurlencode( $t_bot_name ) );
}

/**
 * Html of the link opening the chat with the bot.
 *
 * @return string A dash when the name of the bot is not known.
 */
function telegram_bot_chat_link_html() {
    $t_chat = telegram_bot_chat_get();

    if( $t_chat === NULL ) {
        return '&#8212;';
    }

    return '<a href="' . string_attribute( $t_chat['url'] ) . '" target="_blank">' . string_display_line( $t_chat['name'] ) . '</a>';
}

/**
 * Whether a MantisBT user is linked to a MAX account.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return boolean
 */
function user_is_associated_with_telegram( $p_user_id ) {
    return !is_blank( telegram_account_get( $p_user_id ) );
}

/**
 * Release the binding between a MantisBT user and his MAX account, the way the
 * /stop command does it - but without access to the chat, so that it also works for a
 * lost account, for an administrator and for a user being deleted.
 *
 * Notification preferences are kept: they are of use again once the user comes back.
 *
 * @param integer $p_user_id A valid user identifier.
 * @param boolean $p_notify  Whether to tell the chat that it is unsubscribed.
 * @return string The account unlinked, empty when the user was not linked.
 */
function telegram_bot_user_unlink( $p_user_id, $p_notify = true ) {

    $t_account_id = telegram_account_get( $p_user_id );

    if( is_blank( $t_account_id ) ) {
        return '';
    }

    $t_account_table = plugin_table( 'account' );

    telegram_message_link_delete( $t_account_id );

    db_param_push();
    db_query( "DELETE FROM $t_account_table WHERE account_id=" . db_param(), array( $t_account_id ) );

    telegram_registration_complete( $t_account_id );

    plugin_log_event( 'Account ' . $t_account_id . ' is unlinked from mantisbt user ' . user_get_username( $p_user_id ) );

    if( $p_notify ) {
        messenger_send( $t_account_id, array( 'text' => plugin_lang_get( 'end_message' ) ) );
    }

    # the draft of an unfinished issue belongs to the user, not to the chat
    telegram_draft_clear( $p_user_id );

    # the draft of an unfinished calendar event belongs to the user as well
    telegram_event_draft_clear( $p_user_id );

    # and so does the dialog changing the status of an issue
    telegram_status_change_draft_clear( $p_user_id );

    return $t_account_id;
}

/**
 * Delete every plugin configuration option belonging to a user, called when the user
 * account itself goes away: the core removes profiles, preferences and access levels,
 * but plugin options in the config table are left behind.
 *
 * @param integer $p_user_id A valid user identifier.
 * @return void
 */
function telegram_user_config_delete_all( $p_user_id ) {

    $t_basename = plugin_get_current();

    # An empty basename would turn the pattern into "plugin_%", deleting the options
    # of every plugin - the caller is out of the plugin context and has nothing to do here
    if( is_blank( $t_basename ) ) {
        return;
    }

    $t_config_table = db_get_table( 'config' );

    db_param_push();

    $t_query = "DELETE FROM $t_config_table
			WHERE user_id=" . db_param() . '
			AND config_id LIKE ' . db_param();
    db_query( $t_query, array( (int) $p_user_id, 'plugin_' . $t_basename . '_%' ) );
}

/**
 * Return the state of the registration a MAX account has started: the PIN
 * code issued to it and the id of the invitation the bot has sent.
 *
 * @param string $p_account_id Account of MAX, the user id.
 * @return array|false Database row, false when no registration is in progress.
 */
function telegram_registration_state_get( $p_account_id ) {

    $t_registration_table = plugin_table( 'registration' );

    db_param_push();

    $t_query  = "SELECT pin_code, timestamp, message_id
			FROM $t_registration_table
			WHERE account_id=" . db_param();
    $t_result = db_query( $t_query, array( (string)$p_account_id ) );

    return db_fetch_array( $t_result );
}

/**
 * Return the PIN code the owner of a MAX account has to enter in his MantisBT
 * account preferences. A code issued earlier and still valid is reused, so that every
 * message the bot sends to an unregistred user shows the same code.
 *
 * The state row is created even when the code is not going to be shown: it also
 * keeps the id of the invitation, which is needed to remove that message later.
 *
 * @param string $p_account_id Account of MAX, the user id.
 * @return integer PIN code.
 */
function telegram_pin_code_get( $p_account_id ) {

    $t_registration_table = plugin_table( 'registration' );

    telegram_registration_states_clear_expired();

    $t_state = telegram_registration_state_get( $p_account_id );

    if( $t_state !== false && $t_state['timestamp'] >= db_now() - TELEGRAM_PIN_CODE_TTL ) {
        return (int) $t_state['pin_code'];
    }

    $t_pin_code = telegram_pin_code_free_get();

    db_param_push();

    if( $t_state === false ) {
        $t_query = "INSERT INTO $t_registration_table
                                                ( account_id, pin_code, timestamp, message_id )
                                              VALUES
                                                ( " . db_param() . ',' . db_param() . ',' . db_param() . ',' . db_param() . ')';
        db_query( $t_query, array( (string)$p_account_id, $t_pin_code, db_now(), '' ) );
    } else {
        # The invitation is still in the chat, only the code has expired
        $t_query = "UPDATE $t_registration_table
			SET pin_code=" . db_param() . ', timestamp=' . db_param() . '
			WHERE account_id=' . db_param();
        db_query( $t_query, array( $t_pin_code, db_now(), (string)$p_account_id ) );
    }

    return $t_pin_code;
}

/**
 * Pick a PIN code no other registration is using.
 *
 * @return integer PIN code.
 */
function telegram_pin_code_free_get() {

    $t_registration_table = plugin_table( 'registration' );

    db_param_push();

    $t_query  = "SELECT pin_code FROM $t_registration_table";
    $t_result = db_query( $t_query );

    $t_codes_in_use = array();
    while( $t_row = db_fetch_array( $t_result ) ) {
        $t_codes_in_use[(int) $t_row['pin_code']] = true;
    }

    # 9000 possible codes against the few registrations running at the same time:
    # a free code is found on the first attempts, the limit only guards the loop.
    # random_int(): the code is a secret, so a CSPRNG - mt_rand() is predictable
    for( $i = 0; $i < 100; $i++ ) {
        $t_candidate = random_int( 1000, 9999 );
        if( !isset( $t_codes_in_use[$t_candidate] ) ) {
            return $t_candidate;
        }
    }

    plugin_error( 'ERROR_PIN_CODE_GENERATE', ERROR );
}

/**
 * Seconds the PIN code lockout window lasts, from the settings of the plugin.
 *
 * @return integer
 */
function telegram_pin_code_attempts_window_get() {

    return 60 * max( 1, (int) plugin_config_get( 'pin_code_attempts_window' ) );
}

/**
 * Return true when the user has spent every PIN code guess of the current window.
 *
 * A 4-digit code holds no more than 9000 values, so it is only a secret while the
 * guesses are counted: the state is "count:window_start" per MantisBT user, the
 * limit and the window come from the settings of the plugin.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return boolean
 */
function telegram_pin_code_attempts_exceeded( $p_user_id ) {

    $t_state = plugin_config_get( 'pin_code_attempts', '', FALSE, (int) $p_user_id );

    if( is_blank( $t_state ) ) {
        return false;
    }

    list( $t_count, $t_started ) = array_pad( explode( ':', $t_state ), 2, 0 );

    if( (int) $t_started < db_now() - telegram_pin_code_attempts_window_get() ) {
        return false;
    }

    return (int) $t_count >= max( 1, (int) plugin_config_get( 'pin_code_attempts_max' ) );
}

/**
 * Count a wrong PIN code guess. An expired window starts over.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return void
 */
function telegram_pin_code_attempt_failed( $p_user_id ) {

    $t_state   = plugin_config_get( 'pin_code_attempts', '', FALSE, (int) $p_user_id );
    $t_count   = 0;
    $t_started = db_now();

    if( !is_blank( $t_state ) ) {
        list( $t_old_count, $t_old_started ) = array_pad( explode( ':', $t_state ), 2, 0 );

        if( (int) $t_old_started >= db_now() - telegram_pin_code_attempts_window_get() ) {
            $t_count   = (int) $t_old_count;
            $t_started = (int) $t_old_started;
        }
    }

    plugin_config_set( 'pin_code_attempts', ( $t_count + 1 ) . ':' . $t_started, (int) $p_user_id );
}

/**
 * Return the PIN code guess counters of every user, keyed by user id.
 *
 * The rows are read straight from the config table: the core has no way to list
 * the users a plugin option is set for. Stale windows are included, filtering is
 * up to the caller.
 *
 * @return array array( user_id => array( 'count' => int, 'started' => int ) )
 */
function telegram_pin_code_attempts_all_get() {

    $t_basename = plugin_get_current();

    if( is_blank( $t_basename ) ) {
        return array();
    }

    $t_config_table = db_get_table( 'config' );

    db_param_push();

    $t_query  = "SELECT user_id, value FROM $t_config_table
			WHERE config_id=" . db_param() . ' AND user_id<>0';
    $t_result = db_query( $t_query, array( 'plugin_' . $t_basename . '_pin_code_attempts' ) );

    $t_rows = array();
    while( $t_row = db_fetch_array( $t_result ) ) {
        list( $t_count, $t_started ) = array_pad( explode( ':', (string) $t_row['value'] ), 2, 0 );

        $t_rows[(int) $t_row['user_id']] = array(
                                  'count'   => (int) $t_count,
                                  'started' => (int) $t_started,
        );
    }

    return $t_rows;
}

/**
 * Forget the guesses counted for the user, called when a code is accepted.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return void
 */
function telegram_pin_code_attempts_reset( $p_user_id ) {

    plugin_config_delete( 'pin_code_attempts', (int) $p_user_id );
}

/**
 * Return the MAX account a PIN code was issued to.
 *
 * @param integer $p_pin_code PIN code entered by the user.
 * @param boolean $p_expired  True to look among the expired codes: the state row
 *                            outlives the code itself, so the chat is still known
 *                            and a fresh code can be sent to it.
 * @return string|null The account id, NULL if the code is unknown (or, for
 *                     $p_expired, still valid).
 */
function telegram_pin_code_account_get( $p_pin_code, $p_expired = false ) {

    $t_registration_table = plugin_table( 'registration' );

    if( !$p_expired ) {
        telegram_registration_states_clear_expired();
    }

    db_param_push();

    $t_query  = "SELECT account_id
			FROM $t_registration_table
			WHERE pin_code=" . db_param() . ' AND timestamp' . ( $p_expired ? '<' : '>=' ) . db_param();
    $t_result = db_query( $t_query, array( (int)$p_pin_code, db_now() - TELEGRAM_PIN_CODE_TTL ) );

    $t_row = db_fetch_array( $t_result );
    if( $t_row === false ) {
        return NULL;
    }

    return (string)$t_row['account_id'];
}

/**
 * Remember the invitation the bot has just sent, so that it can be removed from the
 * chat once the accounts are linked.
 *
 * @param string $p_account_id Account of MAX, the user id.
 * @param string $p_message_id Id of the message sent to the chat, empty for none.
 * @return void
 */
function telegram_registration_message_id_set( $p_account_id, $p_message_id ) {

    $t_registration_table = plugin_table( 'registration' );

    db_param_push();

    $t_query = "UPDATE $t_registration_table
			SET message_id=" . db_param() . '
			WHERE account_id=' . db_param();
    db_query( $t_query, array( (string)$p_message_id, (string)$p_account_id ) );
}

/**
 * Remove the invitation from the chat, leaving the registration state in place.
 * Called before a new invitation is sent, so that only one of them is on screen.
 *
 * @param string $p_account_id Account of MAX, the user id.
 * @return void
 */
function telegram_registration_message_remove( $p_account_id ) {

    $t_state = telegram_registration_state_get( $p_account_id );

    if( $t_state === false || is_blank( (string)$t_state['message_id'] ) ) {
        return;
    }

    # Best effort: a message the bot cannot remove any more just stays
    messenger_delete( $p_account_id, $t_state['message_id'] );

    telegram_registration_message_id_set( $p_account_id, '' );
}

/**
 * Finish the registration: the invitation is removed from the chat and the state,
 * including the PIN code, is dropped. An unused code must not survive the binding -
 * anybody who saw it would relink the chat to his own MantisBT account.
 *
 * @param string $p_account_id Account of MAX, the user id.
 * @return void
 */
function telegram_registration_complete( $p_account_id ) {

    $t_registration_table = plugin_table( 'registration' );

    telegram_registration_message_remove( $p_account_id );

    db_param_push();

    $t_query = "DELETE FROM $t_registration_table
			WHERE account_id=" . db_param();
    db_query( $t_query, array( (string)$p_account_id ) );
}

/**
 * Delete the registrations nobody has finished within TELEGRAM_REGISTRATION_STATE_TTL.
 * The PIN code inside them expires much earlier, this only collects the rows.
 *
 * @return void
 */
function telegram_registration_states_clear_expired() {

    $t_registration_table = plugin_table( 'registration' );

    db_param_push();

    $t_query = "DELETE FROM $t_registration_table
			WHERE timestamp<" . db_param();
    db_query( $t_query, array( db_now() - TELEGRAM_REGISTRATION_STATE_TTL ) );
}
