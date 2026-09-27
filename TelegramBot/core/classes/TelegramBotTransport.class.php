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
 * A messenger the bot talks through.
 *
 * The dialogs speak one neutral language: a message is an array of its 'text',
 * its 'reply_markup' ( a TelegramBotKeyboard ) and the 'reply_to_message_id' it
 * answers, an incoming update is a TelegramBotUpdate. The transport turns them
 * into the requests of its messenger and back. The chat ids and the message ids
 * a transport hands out are strings, the dialogs only store and compare them.
 *
 * The transports are listed by messenger_transports(), a new messenger is a new
 * class implementing this interface registered there.
 */
interface TelegramBotTransport {

        /**
         * Short name of the transport, the prefix of its addresses: 'tg'.
         *
         * @return string
         */
        public function name();

        /**
         * Name of the messenger as the users know it.
         *
         * @return string
         */
        public function title();

        /**
         * Whether the transport is configured and switched on.
         *
         * @return boolean
         */
        public function is_enabled();

        /**
         * Longest text of a single message, a longer one is split by send().
         *
         * @return integer
         */
        public function text_length_max();

        /**
         * Biggest file in bytes the bot is able to download from the messenger.
         *
         * @return integer
         */
        public function file_size_max();

        /**
         * Send a message to a chat. A text longer than text_length_max() goes
         * as several messages.
         *
         * @param string $p_chat_id Chat of the transport.
         * @param array  $p_message The message.
         * @return array Ids of the messages sent, empty when nothing went through.
         */
        public function send( $p_chat_id, array $p_message );

        /**
         * Replace the text and the buttons of a message sent by the bot. A message
         * without a 'text' key only gets its buttons replaced.
         *
         * @param string $p_chat_id    Chat of the transport.
         * @param string $p_message_id Message to edit.
         * @param array  $p_message    New content of the message.
         * @return boolean
         */
        public function edit( $p_chat_id, $p_message_id, array $p_message );

        /**
         * Remove a message from the chat, best effort.
         *
         * @param string $p_chat_id    Chat of the transport.
         * @param string $p_message_id Message to remove.
         * @return boolean
         */
        public function delete( $p_chat_id, $p_message_id );

        /**
         * Acknowledge the press of a button, optionally telling the user something.
         *
         * @param string $p_callback_id Id of the press, TelegramBotUpdate::$callback_id.
         * @param string $p_text        Text shown to the user, empty for none.
         * @return boolean
         */
        public function answer_callback( $p_callback_id, $p_text = '' );

        /**
         * Send a file along with a message: the text of the message is its caption
         * where the messenger allows it.
         *
         * @param string $p_chat_id   Chat of the transport.
         * @param string $p_file_name Name of the file shown in the chat.
         * @param string $p_content   Content of the file.
         * @param array  $p_message   The message going with the file, may be empty.
         * @return array Ids of the messages sent, empty when the file did not go through.
         */
        public function send_document( $p_chat_id, $p_file_name, $p_content, array $p_message );

        /**
         * Name of the account in the messenger, for the pages of MantisBT.
         *
         * @param string $p_account_id Account of the transport.
         * @return string Empty when the name is unknown or the messenger is unreachable.
         */
        public function account_name( $p_account_id );

        /**
         * Download a file of an incoming message into the download path of the
         * plugin.
         *
         * @param TelegramBotFile $p_file File to download.
         * @return array 'tmp_name' => path of the downloaded file, 'name' => name of
         *               the file, made up by the transport when the file has none.
         * @throws Exception With a message fit for the user when the file cannot be had.
         */
        public function download( TelegramBotFile $p_file );

        /**
         * Updates carried by the body of a request of the webhook.
         *
         * @param string $p_raw Body of the request.
         * @return array TelegramBotUpdate objects, empty for a body carrying none.
         */
        public function updates_parse( $p_raw );

        /**
         * Wait for the updates the long polling way.
         *
         * @param mixed   $p_offset      Position of the next expected update, as the
         *                               previous call gave it back; confirms the ones before.
         * @param integer $p_timeout     Seconds the messenger holds the connection, 0 for none.
         * @param mixed   $p_next_offset Out: position to ask for on the next call.
         * @return array TelegramBotUpdate objects.
         * @throws Exception When the messenger refuses the request.
         */
        public function updates_poll( $p_offset, $p_timeout, &$p_next_offset );
}
