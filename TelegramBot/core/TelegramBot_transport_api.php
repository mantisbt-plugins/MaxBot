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

/**
 * The messengers the bot talks through, see TelegramBotTransport.
 *
 * A chat is named by an address "<transport>:<chat id>", for instance "tg:123",
 * so that a message id or a chat id stored by the dialogs keeps the messenger it
 * belongs to. An address without the prefix is a Telegram chat: that is what the
 * plugin stored before there was a second messenger.
 */

# Separator of the transport name and the chat id within an address
define( 'MESSENGER_ADDRESS_SEPARATOR', ':' );
# Transport of an address stored without the prefix
define( 'MESSENGER_TRANSPORT_DEFAULT', 'tg' );

/**
 * Every transport known to the plugin, keyed by its name.
 *
 * The one place a new messenger is registered at.
 *
 * @return array TelegramBotTransport objects.
 */
function messenger_transports() {
    static $s_transports = NULL;

    if( $s_transports === NULL ) {
        $s_transports = array();

        foreach( array( new TelegramTransport(), new MaxTransport() ) as $t_transport ) {
            $s_transports[$t_transport->name()] = $t_transport;
        }
    }

    return $s_transports;
}

/**
 * The transport of the given name.
 *
 * @param string $p_name Name of the transport.
 * @return TelegramBotTransport|null NULL for an unknown name.
 */
function messenger_transport( $p_name ) {
    $t_transports = messenger_transports();

    return isset( $t_transports[$p_name] ) ? $t_transports[$p_name] : NULL;
}

/**
 * The transport of the given name when it is switched on.
 *
 * The messages, the buttons and the files go through this one: a transport
 * switched off is not talked to at all, a chat of it left in a draft or a link
 * included. messenger_transport() still gives it to the settings, which set it up.
 *
 * @param string $p_name Name of the transport.
 * @return TelegramBotTransport|null NULL for an unknown or switched off transport.
 */
function messenger_transport_active( $p_name ) {
    $t_transport = messenger_transport( $p_name );

    return $t_transport !== NULL && $t_transport->is_enabled() ? $t_transport : NULL;
}

/**
 * Build the address of a chat.
 *
 * @param string $p_transport Name of the transport.
 * @param string $p_chat_id   Chat of the transport.
 * @return string
 */
function messenger_address_make( $p_transport, $p_chat_id ) {
    return $p_transport . MESSENGER_ADDRESS_SEPARATOR . $p_chat_id;
}

/**
 * Split an address into the transport and the chat.
 *
 * @param string $p_address Address of a chat, a bare chat id is a Telegram one.
 * @return array array( transport name, chat id ).
 */
function messenger_address_parse( $p_address ) {
    $t_address = (string)$p_address;
    $t_pos     = strpos( $t_address, MESSENGER_ADDRESS_SEPARATOR );

    if( $t_pos === FALSE ) {
        return array( MESSENGER_TRANSPORT_DEFAULT, $t_address );
    }

    return array( substr( $t_address, 0, $t_pos ), substr( $t_address, $t_pos + 1 ) );
}

/**
 * Whether two addresses name the same chat, an address stored without the
 * prefix of its transport included.
 *
 * @param string $p_address_1 Address of a chat.
 * @param string $p_address_2 Address of a chat.
 * @return boolean
 */
function messenger_address_same( $p_address_1, $p_address_2 ) {
    return messenger_address_parse( $p_address_1 ) === messenger_address_parse( $p_address_2 );
}

/**
 * The transport an address belongs to.
 *
 * @param string $p_address Address of a chat.
 * @return TelegramBotTransport|null NULL for an unknown transport.
 */
function messenger_address_transport( $p_address ) {
    list( $t_transport ) = messenger_address_parse( $p_address );

    return messenger_transport( $t_transport );
}

/**
 * Send a message to a chat.
 *
 * @param string $p_address Address of the chat.
 * @param array  $p_message The message: 'text', 'reply_markup', 'reply_to_message_id'.
 * @return array Ids of the messages sent, empty when nothing went through.
 */
function messenger_send( $p_address, array $p_message ) {
    list( $t_name, $t_chat_id ) = messenger_address_parse( $p_address );
    $t_transport = messenger_transport_active( $t_name );

    if( $t_transport === NULL ) {
        plugin_log_event( 'ERROR! Unknown or switched off messenger of the address ' . $p_address );
        return array();
    }

    return $t_transport->send( $t_chat_id, $p_message );
}

/**
 * Replace the content of a message the bot has sent. A message without a 'text'
 * key only gets its buttons replaced.
 *
 * @param string $p_address    Address of the chat.
 * @param string $p_message_id Message to edit.
 * @param array  $p_message    New content of the message.
 * @return boolean
 */
function messenger_edit( $p_address, $p_message_id, array $p_message ) {
    list( $t_name, $t_chat_id ) = messenger_address_parse( $p_address );
    $t_transport = messenger_transport_active( $t_name );

    return $t_transport !== NULL && $t_transport->edit( $t_chat_id, $p_message_id, $p_message );
}

/**
 * Remove a message from a chat, best effort.
 *
 * @param string $p_address    Address of the chat.
 * @param string $p_message_id Message to remove.
 * @return boolean
 */
function messenger_delete( $p_address, $p_message_id ) {
    list( $t_name, $t_chat_id ) = messenger_address_parse( $p_address );
    $t_transport = messenger_transport_active( $t_name );

    return $t_transport !== NULL && $t_transport->delete( $t_chat_id, $p_message_id );
}

/**
 * Acknowledge the press of a button.
 *
 * @param string $p_transport   Name of the transport the press came from.
 * @param string $p_callback_id Id of the press.
 * @param string $p_text        Text shown to the user, empty for none.
 * @return boolean
 */
function messenger_answer_callback( $p_transport, $p_callback_id, $p_text = '' ) {
    $t_transport = messenger_transport_active( $p_transport );

    return $t_transport !== NULL && $t_transport->answer_callback( $p_callback_id, $p_text );
}

/**
 * Download a file of an incoming message, checked against the upload rules of
 * MantisBT before and, for a file known by name only after the download, after
 * the download.
 *
 * @param TelegramBotFile $p_file  File to download.
 * @param string          $p_error Out: text telling the user why the file was refused.
 * @return array|null The file in the shape of an upload of a form, the way the
 *                    commands of the core take it; NULL when the file is refused.
 */
function messenger_file_fetch( TelegramBotFile $p_file, &$p_error ) {
    $p_error = telegram_file_check( $p_file->name, $p_file->size, $p_file->transport );

    if( $p_error != '' ) {
        return NULL;
    }

    $t_transport = messenger_transport_active( $p_file->transport );

    if( $t_transport === NULL ) {
        $p_error = plugin_lang_get( 'error_content_type' );
        return NULL;
    }

    try {
        $t_download = $t_transport->download( $p_file );
    } catch( Exception $t_error ) {
        $p_error = $t_error->getMessage();
        return NULL;
    }

    if( is_blank( $p_file->name ) ) {
        $p_error = telegram_file_check( $t_download['name'], 0, $p_file->transport );

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
 * @param string $p_address   Address of the chat.
 * @param string $p_file_name Name of the file shown in the chat.
 * @param string $p_content   Content of the file.
 * @param array  $p_message   The message going with the file, may be empty.
 * @return array Ids of the messages sent, empty when the file did not go through.
 */
function messenger_send_document( $p_address, $p_file_name, $p_content, array $p_message = array() ) {
    list( $t_name, $t_chat_id ) = messenger_address_parse( $p_address );
    $t_transport = messenger_transport_active( $t_name );

    if( $t_transport === NULL ) {
        return array();
    }

    return $t_transport->send_document( $t_chat_id, $p_file_name, $p_content, $p_message );
}

/**
 * Whether the bot sends the notifications at all: the switch of the administrator
 * and at least one messenger to send them through.
 *
 * @return boolean
 */
function messenger_notifications_enabled() {
    if( ON != plugin_config_get( 'enable_telegram_message_notification' ) ) {
        return false;
    }

    foreach( messenger_transports() as $t_transport ) {
        if( $t_transport->is_enabled() ) {
            return true;
        }
    }

    return false;
}

/**
 * The private chats of a MantisBT user in the messengers the bot talks through.
 *
 * @param integer $p_user_id MantisBT user id.
 * @return array Addresses, empty when the user has no account in an enabled messenger.
 */
function messenger_user_addresses( $p_user_id ) {
    $t_addresses = array();

    foreach( telegram_accounts_get( $p_user_id ) as $t_name => $t_account_id ) {
        if( messenger_transport_active( $t_name ) !== NULL ) {
            $t_addresses[] = messenger_address_make( $t_name, $t_account_id );
        }
    }

    return $t_addresses;
}

/**
 * Send a message to every messenger account of a MantisBT user.
 *
 * A message about an issue is remembered, so that a reply to it from the chat
 * becomes a note of the issue.
 *
 * @param integer $p_user_id MantisBT user id.
 * @param array   $p_message The message, see messenger_send().
 * @param integer $p_bug_id  Issue the message is about, 0 for none.
 * @return boolean Whether the message reached at least one chat.
 */
function messenger_send_to_user( $p_user_id, array $p_message, $p_bug_id = 0 ) {
    $t_sent = false;

    foreach( messenger_user_addresses( $p_user_id ) as $t_address ) {
        $t_message_ids = messenger_send( $t_address, $p_message );

        if( $p_bug_id > 0 ) {
            foreach( $t_message_ids as $t_message_id ) {
                telegram_message_realatationship_add( $p_bug_id, $t_address, $t_message_id );
            }
        }

        $t_sent = $t_sent || !empty( $t_message_ids );
    }

    return $t_sent;
}
