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
 * The facade the dialogs talk to MAX through, see MaxBotApi.
 *
 * A chat is named by the user id of its member in MAX: the bot talks to a user
 * by his id, and a message is edited or removed by its id alone, which is unique
 * across the chats. Both ids are strings.
 */

/**
 * The client of the MAX Bot API, one per process.
 *
 * @return MaxBotApi
 */
function maxbot_api() {
    static $s_api = NULL;

    if( $s_api === NULL ) {
        $s_api = new MaxBotApi();
    }

    return $s_api;
}

/**
 * Send a message to a chat.
 *
 * @param string $p_chat_id Chat: the user id of MAX.
 * @param array  $p_message The message: 'text', 'format', 'reply_markup', 'reply_to_message_id'.
 * @return array Ids of the messages sent, empty when nothing went through.
 */
function maxbot_send( $p_chat_id, array $p_message ) {
    return maxbot_api()->send( (string)$p_chat_id, $p_message );
}

/**
 * Replace the content of a message the bot has sent. A message without a 'text'
 * key only gets its buttons replaced.
 *
 * @param string $p_chat_id    Chat of the message.
 * @param string $p_message_id Message to edit.
 * @param array  $p_message    New content of the message.
 * @return boolean
 */
function maxbot_edit( $p_chat_id, $p_message_id, array $p_message ) {
    return maxbot_api()->edit( (string)$p_chat_id, $p_message_id, $p_message );
}

/**
 * Remove a message from a chat, best effort: in a dialog MAX lets the bot remove
 * its own messages only.
 *
 * @param string $p_chat_id    Chat of the message.
 * @param string $p_message_id Message to remove.
 * @return boolean
 */
function maxbot_delete( $p_chat_id, $p_message_id ) {
    return maxbot_api()->delete( (string)$p_chat_id, $p_message_id );
}

/**
 * Acknowledge the press of a button.
 *
 * @param string $p_callback_id Id of the press.
 * @param string $p_text        Text shown to the user as a notification, empty for none.
 * @return boolean
 */
function maxbot_answer_callback( $p_callback_id, $p_text = '' ) {
    return maxbot_api()->answer_callback( $p_callback_id, $p_text );
}

/**
 * Download a file of an incoming message, checked against the upload rules of
 * MantisBT before and, for a file known by name only after the download, after
 * the download.
 *
 * @param MaxBotFile $p_file  File to download.
 * @param string          $p_error Out: text telling the user why the file was refused.
 * @return array|null The file in the shape of an upload of a form, the way the
 *                    commands of the core take it; NULL when the file is refused.
 */
function maxbot_file_fetch( MaxBotFile $p_file, &$p_error ) {
    $p_error = maxbot_file_check( $p_file->name, $p_file->size );

    if( $p_error != '' ) {
        return NULL;
    }

    try {
        $t_download = maxbot_api()->download( $p_file );
    } catch( Exception $t_error ) {
        $p_error = $t_error->getMessage();
        return NULL;
    }

    if( is_blank( $p_file->name ) ) {
        $p_error = maxbot_file_check( $t_download['name'], 0 );

        if( $p_error != '' ) {
            @unlink( $t_download['tmp_name'] );
            return NULL;
        }
    }

    return array(
                              'browser_upload' => array( 0 => FALSE ),
                              'tmp_name'       => array( 0 => $t_download['tmp_name'] ),
                              'name'           => array( 0 => $t_download['name'] ),
    );
}

/**
 * Send a file to a chat along with a message.
 *
 * @param string $p_chat_id   Chat: the user id of MAX.
 * @param string $p_file_name Name of the file shown in the chat.
 * @param string $p_content   Content of the file.
 * @param array  $p_message   The message going with the file, may be empty.
 * @return array Ids of the messages sent, empty when the file did not go through.
 */
function maxbot_send_document( $p_chat_id, $p_file_name, $p_content, array $p_message = array() ) {
    return maxbot_api()->send_document( (string)$p_chat_id, $p_file_name, $p_content, $p_message );
}

/**
 * Whether the bot sends the notifications at all: the switch of the administrator
 * and a token to send them with.
 *
 * @return boolean
 */
function maxbot_notifications_enabled() {
    return ON == plugin_config_get( 'enable_notification' ) && maxbot_api()->is_enabled();
}

/**
 * The chat of a MantisBT user with the bot.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return array The chat id, empty when the user has not linked his MAX account.
 */
function maxbot_user_chats( $p_user_id ) {
    $t_account_id = maxbot_account_get( $p_user_id );

    return is_blank( $t_account_id ) ? array() : array( $t_account_id );
}

/**
 * Send a message to the chat of a MantisBT user.
 *
 * A message about an issue is remembered, so that a reply to it from the chat
 * becomes a note of the issue.
 *
 * @param integer $p_user_id MantisBT user id.
 * @param array   $p_message The message, see maxbot_send().
 * @param integer $p_bug_id  Issue the message is about, 0 for none.
 * @return boolean Whether the message reached the chat.
 */
function maxbot_send_to_user( $p_user_id, array $p_message, $p_bug_id = 0 ) {
    $t_sent = false;

    foreach( maxbot_user_chats( $p_user_id ) as $t_chat_id ) {
        $t_message_ids = maxbot_send( $t_chat_id, $p_message );

        if( $p_bug_id > 0 ) {
            foreach( $t_message_ids as $t_message_id ) {
                maxbot_message_link_add( $p_bug_id, $t_chat_id, $t_message_id );
            }
        }

        $t_sent = $t_sent || !empty( $t_message_ids );
    }

    return $t_sent;
}
