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

/**
 * The MAX messenger, spoken to through its Bot API over plain HTTP.
 *
 * The dialogs speak one neutral language: a message is an array of its 'text',
 * the 'format' of the text ( 'html' for the dialog cards, plain when missing ),
 * its 'reply_markup' ( a MaxBotKeyboard ) and the 'reply_to_message_id' it
 * answers, an incoming update is a MaxBotUpdate. This class turns them into
 * the requests of the Bot API and back.
 *
 * A chat of MAX is addressed by the user id of its member, not by the id of the
 * dialog: a message goes to POST /messages?user_id=, and a message is edited or
 * removed by its id alone, which is unique across the chats. The ids of the
 * messages are strings ( "mid.…" ).
 *
 * Nothing of this class talks to MAX while the token is not set: every call
 * sending or polling checks is_enabled() first.
 */
class MaxBotApi {

        const API_URL = 'https://platform-api2.max.ru';

        # Longest text of a message, Bot API limit
        const TEXT_LENGTH_MAX = 4000;
        # Biggest file MAX takes, nothing smaller is documented for the downloads
        const FILE_SIZE_MAX   = 4294967296;

        # Limits of an inline keyboard
        const BUTTON_TEXT_LENGTH_MAX = 128;
        const ROW_BUTTONS_MAX        = 7;
        # A row holding a link button takes three buttons at most
        const ROW_LINK_BUTTONS_MAX   = 3;
        const ROWS_MAX               = 30;
        const BUTTONS_MAX            = 210;

        # Longest wait of a long polling request, Bot API limit
        const POLL_TIMEOUT_MAX = 90;

        # Error of a message sent right after the upload of its file: the file is
        # still being processed and the same token is to be sent again a bit later
        const ERROR_ATTACHMENT_NOT_READY = 'attachment.not.ready';

        /**
         * The updates the bot handles, asked for by the long polling and by the
         * webhook subscription alike.
         * @var array
         */
        private static $update_types = array( 'message_created', 'message_callback', 'bot_started' );

        /**
         * Seconds waited between the attempts to send a file still being processed.
         * @var array
         */
        private static $attachment_retry_delays = array( 1, 2, 4, 8 );

        /**
         * Tokens of the files uploaded by this process, keyed by the hash of their
         * name and content: a file sent to many chats is uploaded once.
         * @var array
         */
        private $documents = array();

        /**
         * HTTP client, NULL until the first call to MAX.
         * @var \GuzzleHttp\Client|null
         */
        private $client = NULL;

        /**
         * Token the client was made with: a token changed on the settings page
         * within the same request gets a new client.
         * @var string
         */
        private $client_token = '';

        /**
         * Logger of the exchange, FALSE when the debug of the connection is off,
         * NULL until asked for.
         * @var MaxBotFileLogger|false|null
         */
        private $logger = NULL;

        /**
         * Text of the last failure of a request, for the callers throwing it on.
         * @var string
         */
        private $last_error = '';

        /**
         * Whether the token of the bot is set.
         *
         * @return boolean
         */
        public function is_enabled() {
                return !is_blank( (string)plugin_config_get( 'api_key' ) );
        }

        /**
         * Send a message to a chat. A text longer than a message of MAX goes as
         * several messages.
         *
         * @param string $p_chat_id Chat: the user id of MAX.
         * @param array  $p_message The message.
         * @return array Ids of the messages sent, empty when nothing went through.
         */
        public function send( $p_chat_id, array $p_message ) {
                if( !$this->is_enabled() ) {
                        return array();
                }

                $t_body = $this->body( $p_message );
                $t_text = isset( $p_message['text'] ) ? (string)$p_message['text'] : '';
                $t_ids  = array();

                # The buttons go with every part of a long text
                do {
                        $t_part = $this->text_head( $t_text, $p_message );
                        $t_text = mb_substr( $t_text, mb_strlen( $t_part, 'UTF-8' ) );

                        $t_body['text'] = $t_part === '' ? NULL : $t_part;

                        $t_mid = $this->message_post( $p_chat_id, $t_body );

                        if( $t_mid !== NULL ) {
                                $t_ids[] = $t_mid;
                        }
                } while( mb_strlen( $t_text, 'UTF-8' ) > 0 );

                return $t_ids;
        }

        /**
         * The longest head of a text that fits in a message of MAX.
         *
         * MAX counts the limit on the source of an HTML text, tags included, and
         * shows a tag or an entity cut in half as it is, a tag left open spreads
         * over the rest of the text. The cut of an HTML text therefore drops the
         * entity or the tag it falls in and a bold label left open, the only tag
         * the cards use.
         *
         * @param string $p_text    Text to cut.
         * @param array  $p_message The message the text belongs to.
         * @return string
         */
        private function text_head( $p_text, array $p_message ) {
                $t_head = mb_substr( $p_text, 0, self::TEXT_LENGTH_MAX );

                if( !isset( $p_message['format'] ) || $p_message['format'] != 'html' || $t_head === $p_text ) {
                        return $t_head;
                }

                # a bare ampersand is always escaped, so a trailing one starts a cut entity
                $t_cut = preg_replace( '/(&[^;\s]*|<[^>]*)$/u', '', $t_head );

                $t_open  = mb_strrpos( $t_cut, '<b>', 0, 'UTF-8' );
                $t_close = mb_strrpos( $t_cut, '</b>', 0, 'UTF-8' );

                if( $t_open !== FALSE && ( $t_close === FALSE || $t_close < $t_open ) ) {
                        $t_cut = mb_substr( $t_cut, 0, $t_open );
                }

                # an empty head would never let a split text end
                return $t_cut === '' ? $t_head : $t_cut;
        }

        /**
         * Replace the text and the buttons of a message sent by the bot. A message
         * without a 'text' key only gets its buttons replaced; the chat is not
         * needed, the id of a message is unique across the chats.
         *
         * An edit replaces the attachments of the message as a whole, the keyboard
         * being one of them, and keeps them when none are given. The current message
         * is therefore read when the text is to be kept or the keyboard removed, and
         * the files it carries are sent back along with the new keyboard.
         *
         * @param string $p_chat_id    Chat of the message.
         * @param string $p_message_id Message to edit.
         * @param array  $p_message    New content of the message.
         * @return boolean
         */
        public function edit( $p_chat_id, $p_message_id, array $p_message ) {
                if( !$this->is_enabled() ) {
                        return FALSE;
                }

                $t_keyboard = $this->keyboard_attachment( $p_message );
                $t_has_text = array_key_exists( 'text', $p_message );

                $t_body = array();

                if( $t_has_text ) {
                        # an edit cannot be split, the rest of a longer text is cut off
                        $t_body['text'] = $this->text_head( (string)$p_message['text'], $p_message );

                        if( isset( $p_message['format'] ) ) {
                                $t_body['format'] = (string)$p_message['format'];
                        }
                }

                if( $t_has_text && $t_keyboard !== NULL ) {
                        $t_body['attachments'] = array( $t_keyboard );
                } else {
                        $t_current = $this->request( 'GET', '/messages/' . rawurlencode( (string)$p_message_id ) );

                        if( $t_current === NULL && !$t_has_text ) {
                                return FALSE;
                        }

                        $t_current_body = $t_current !== NULL && isset( $t_current['body'] ) && is_array( $t_current['body'] ) ? $t_current['body'] : array();

                        if( !$t_has_text ) {
                                $t_body['text'] = isset( $t_current_body['text'] ) ? $t_current_body['text'] : NULL;
                        }

                        $t_attachments = $this->attachments_kept( $t_current_body );

                        if( $t_keyboard !== NULL ) {
                                $t_attachments[] = $t_keyboard;
                        }

                        # an empty list removes the keyboard, a missing one would keep it
                        $t_body['attachments'] = $t_attachments;
                }

                return $this->request( 'PUT', '/messages', array(
                                          'query' => array( 'message_id' => (string)$p_message_id ),
                                          'json'  => $t_body,
                        ) ) !== NULL;
        }

        /**
         * Remove a message from the chat, best effort. In a dialog the bot removes
         * its own messages only: the messages of the user answering the questions
         * of a dialog stay in the chat, which is no error.
         *
         * @param string $p_chat_id    Chat of the message.
         * @param string $p_message_id Message to remove.
         * @return boolean
         */
        public function delete( $p_chat_id, $p_message_id ) {
                if( !$this->is_enabled() || is_blank( (string)$p_message_id ) ) {
                        return FALSE;
                }

                return $this->request( 'DELETE', '/messages', array(
                                          'query' => array( 'message_id' => (string)$p_message_id ),
                        ), /* quiet */ TRUE ) !== NULL;
        }

        /**
         * Acknowledge the press of a button. MAX has no pop-up alert: the text goes
         * as a notification of the press. Whether a press must be answered at all
         * is not documented, so a press without a text is answered too, quietly.
         *
         * @param string $p_callback_id Id of the press, MaxBotUpdate::$callback_id.
         * @param string $p_text        Text shown to the user, empty for none.
         * @return boolean
         */
        public function answer_callback( $p_callback_id, $p_text = '' ) {
                if( !$this->is_enabled() || is_blank( (string)$p_callback_id ) ) {
                        return FALSE;
                }

                $t_quiet = is_blank( $p_text );
                $t_body  = $t_quiet ? new stdClass() : array( 'notification' => mb_substr( $p_text, 0, self::TEXT_LENGTH_MAX ) );

                return $this->request( 'POST', '/answers', array(
                                          'query' => array( 'callback_id' => (string)$p_callback_id ),
                                          'json'  => $t_body,
                        ), $t_quiet ) !== NULL;
        }

        /**
         * Send a file along with a message. The file is uploaded first and sent then
         * by its token, in the message carrying the text; a file still being
         * processed is sent again after a pause. A text longer than a message goes
         * as messages of its own and the file as a reply to them.
         *
         * @param string $p_chat_id   Chat: the user id of MAX.
         * @param string $p_file_name Name of the file shown in the chat.
         * @param string $p_content   Content of the file.
         * @param array  $p_message   The message going with the file, may be empty.
         * @return array Ids of the messages sent, empty when the file did not go through.
         */
        public function send_document( $p_chat_id, $p_file_name, $p_content, array $p_message ) {
                if( !$this->is_enabled() ) {
                        return array();
                }

                $t_text = isset( $p_message['text'] ) ? (string)$p_message['text'] : '';
                $t_ids  = array();

                if( mb_strlen( $t_text, 'UTF-8' ) > self::TEXT_LENGTH_MAX ) {
                        $t_ids  = $this->send( $p_chat_id, $p_message );
                        $t_body = empty( $t_ids ) ? array() : array( 'link' => array( 'type' => 'reply', 'mid' => end( $t_ids ) ) );
                } else {
                        $t_body = $this->body( $p_message );

                        if( $t_text !== '' ) {
                                $t_body['text'] = $t_text;
                        }
                }

                $t_key = sha1( $p_file_name . "\0" . $p_content );

                if( !isset( $this->documents[$t_key] ) ) {
                        $t_token = $this->upload( $p_file_name, $p_content );

                        if( $t_token === NULL ) {
                                return $t_ids;
                        }

                        $this->documents[$t_key] = $t_token;
                }

                $t_attachments = array( array( 'type' => 'file', 'payload' => array( 'token' => $this->documents[$t_key] ) ) );

                if( isset( $t_body['attachments'] ) ) {
                        $t_attachments = array_merge( $t_attachments, $t_body['attachments'] );
                }

                $t_body['attachments'] = $t_attachments;

                foreach( array_merge( array( 0 ), self::$attachment_retry_delays ) as $t_attempt => $t_delay ) {
                        sleep( $t_delay );

                        $t_error_code = '';
                        $t_mid        = $this->message_post( $p_chat_id, $t_body, /* quiet */ TRUE, $t_error_code );

                        if( $t_mid !== NULL ) {
                                $t_ids[] = $t_mid;
                                return $t_ids;
                        }

                        if( $t_error_code !== self::ERROR_ATTACHMENT_NOT_READY ) {
                                break;
                        }
                }

                plugin_log_event( 'ERROR! MAX file ' . $p_file_name . ' was not sent: ' . $this->last_error );

                return $t_ids;
        }

        /**
         * Download a file of an incoming message into a private directory under
         * the download path of the plugin. A file of MAX is downloaded straight from the url of its
         * attachment; a photo or a video has no name, one is made up out of its type.
         *
         * @param MaxBotFile $p_file File to download.
         * @return array 'tmp_name' => path of the downloaded file, 'name' => name of the file.
         * @throws Exception With a message fit for the user when the file cannot be had.
         */
        public function download( MaxBotFile $p_file ) {
                if( !$this->is_enabled() || is_blank( $p_file->ref ) ) {
                        throw new Exception( plugin_lang_get( 'file_download_failed' ) );
                }

                $t_path = '';

                try {
                        # A directory of its own, readable by the owner only and removed
                        # by maxbot_file_download_remove() once the core has stored the file
                        $t_path = maxbot_file_download_dir_create() . '/max_' . bin2hex( random_bytes( 8 ) );

                        $this->debug( 'GET ' . $p_file->ref . ' -> ' . $t_path );

                        $t_response = $this->client()->request( 'GET', $p_file->ref, array( 'sink' => $t_path ) );
                        $t_status   = $t_response->getStatusCode();
                } catch( Exception $t_error ) {
                        $t_status = 0;
                        $this->last_error = $t_error->getMessage();
                }

                $this->debug( 'Response ' . $t_status . ' of the download of ' . $p_file->ref );

                if( $t_status != 200 ) {
                        if( $t_path !== '' ) {
                                @unlink( $t_path );
                        }

                        plugin_log_event( 'ERROR! MAX file download failed: ' . ( $t_status == 0 ? $this->last_error : 'HTTP ' . $t_status ) );

                        throw new Exception( plugin_lang_get( 'file_download_failed' ) );
                }

                @chmod( $t_path, 0600 );

                $t_name = $p_file->name;

                if( is_blank( $t_name ) ) {
                        $t_name = $p_file->kind . '_' . date( 'Ymd_His' ) . '.' . $this->extension_guess( $p_file, $t_response->getHeaderLine( 'Content-Type' ) );
                }

                return array(
                                          'tmp_name' => $t_path,
                                          'name'     => $t_name,
                );
        }

        /**
         * Updates carried by the body of a request of the webhook, a single one.
         *
         * @param string $p_raw Body of the request.
         * @return array MaxBotUpdate objects, empty for a body carrying none.
         */
        public function updates_parse( $p_raw ) {
                $t_update = json_decode( (string)$p_raw, TRUE, 512, JSON_BIGINT_AS_STRING );

                $this->debug( 'Webhook update: ' . (string)$p_raw );

                if( !is_array( $t_update ) ) {
                        return array();
                }

                $t_result = $this->update_map( $t_update );

                return $t_result === NULL ? array() : array( $t_result );
        }

        /**
         * Wait for the updates the long polling way.
         *
         * The marker of the answer is passed as it is to the next call, which
         * confirms the updates before it; it is always passed, the updates asked for
         * without one are described differently by the sources of the Bot API.
         *
         * @param string  $p_marker      Marker of the next expected update, as the
         *                               previous call gave it back; confirms the ones before.
         * @param integer $p_timeout     Seconds MAX holds the connection, 0 for none.
         * @param string  $p_next_marker Out: marker to ask for on the next call.
         * @return array MaxBotUpdate objects.
         * @throws Exception When MAX refuses the request.
         */
        public function updates_poll( $p_marker, $p_timeout, &$p_next_marker ) {
                if( !$this->is_enabled() ) {
                        throw new Exception( 'The token of the bot is not set' );
                }

                $t_query = array(
                                          'timeout' => min( max( 0, (int)$p_timeout ), self::POLL_TIMEOUT_MAX ),
                                          'types'   => implode( ',', self::$update_types ),
                );

                if( !is_blank( (string)$p_marker ) ) {
                        $t_query['marker'] = (string)$p_marker;
                }

                $t_result = $this->request( 'GET', '/updates', array( 'query' => $t_query ), /* quiet */ TRUE );

                if( $t_result === NULL ) {
                        throw new Exception( $this->last_error );
                }

                $p_next_marker = isset( $t_result['marker'] ) && !is_blank( (string)$t_result['marker'] ) ? (string)$t_result['marker'] : (string)$p_marker;

                $t_results = array();

                if( isset( $t_result['updates'] ) && is_array( $t_result['updates'] ) ) {
                        foreach( $t_result['updates'] as $t_update ) {
                                $t_mapped = is_array( $t_update ) ? $this->update_map( $t_update ) : NULL;

                                if( $t_mapped !== NULL ) {
                                        $t_results[] = $t_mapped;
                                }
                        }
                }

                return $t_results;
        }

        /**
         * Subscribe the bot to the updates delivered to the given url. A new secret
         * is made for the subscription and kept in webhook_secret; the previous
         * one comes back when the subscription is refused.
         *
         * @param string $p_url Url MAX posts the updates to, https on the port 443.
         * @return boolean
         */
        public function webhook_set( $p_url ) {
                $t_secret_previous = (string)plugin_config_get( 'webhook_secret' );
                $t_secret          = bin2hex( random_bytes( 16 ) );

                # kept before the subscription: the first update may come before the answer
                plugin_config_set( 'webhook_secret', $t_secret );

                $t_result = $this->request( 'POST', '/subscriptions', array(
                                          'json' => array(
                                                                    'url'          => (string)$p_url,
                                                                    'update_types' => self::$update_types,
                                                                    'secret'       => $t_secret,
                                          ),
                        ) );

                if( $t_result === NULL ) {
                        plugin_config_set( 'webhook_secret', $t_secret_previous );
                        return FALSE;
                }

                return TRUE;
        }

        /**
         * Remove every subscription of the bot, which lets the updates be polled.
         *
         * @return boolean Whether no subscription is left.
         */
        public function webhook_delete() {
                $t_result = $this->request( 'GET', '/subscriptions' );

                if( $t_result === NULL ) {
                        return FALSE;
                }

                $t_ok = TRUE;

                foreach( $this->subscriptions( $t_result ) as $t_subscription ) {
                        $t_ok = $this->request( 'DELETE', '/subscriptions', array(
                                                  'query' => array( 'url' => (string)$t_subscription['url'] ),
                                ) ) !== NULL && $t_ok;
                }

                return $t_ok;
        }

        /**
         * The subscription of the bot.
         *
         * @return array|null array( 'url' => string, 'time' => Unix time in seconds
         *                    the subscription was made at ), array() when there is none,
         *                    NULL when MAX cannot be asked.
         */
        public function webhook_info() {
                $t_result = $this->request( 'GET', '/subscriptions' );

                if( $t_result === NULL ) {
                        return NULL;
                }

                $t_subscriptions = $this->subscriptions( $t_result );

                if( empty( $t_subscriptions ) ) {
                        return array();
                }

                $t_subscription = reset( $t_subscriptions );

                return array(
                                          'url'  => (string)$t_subscription['url'],
                                          # MAX counts the time in milliseconds
                                          'time' => isset( $t_subscription['time'] ) ? (int)floor( (float)$t_subscription['time'] / 1000 ) : 0,
                );
        }

        /**
         * The bot itself.
         *
         * @return array|null array( 'user_id', 'username', 'first_name' ), NULL when
         *                    MAX cannot be asked or refuses the token.
         */
        public function me() {
                $t_result = $this->request( 'GET', '/me' );

                if( $t_result === NULL || !isset( $t_result['user_id'] ) ) {
                        return NULL;
                }

                return array(
                                          'user_id'    => (string)$t_result['user_id'],
                                          'username'   => isset( $t_result['username'] ) ? (string)$t_result['username'] : '',
                                          'first_name' => isset( $t_result['first_name'] ) ? (string)$t_result['first_name'] : '',
                );
        }

        /**
         * Whether a request of the webhook carries the secret of the subscription.
         *
         * @param string $p_header_value Value of the X-Max-Bot-Api-Secret header.
         * @return boolean
         */
        public function secret_is_valid( $p_header_value ) {
                $t_secret = (string)plugin_config_get( 'webhook_secret' );

                return !is_blank( $t_secret ) && hash_equals( $t_secret, (string)$p_header_value );
        }

        /**
         * The neutral update out of an update of the Bot API.
         *
         * @param array $p_update Update of the Bot API.
         * @return MaxBotUpdate|null NULL for an update the bot does not handle.
         */
        private function update_map( array $p_update ) {
                $t_type = isset( $p_update['update_type'] ) ? (string)$p_update['update_type'] : '';

                $t_update       = new MaxBotUpdate();
                $t_update->lang = isset( $p_update['user_locale'] ) ? (string)$p_update['user_locale'] : NULL;

                switch( $t_type ) {
                        case 'bot_started':
                                # The start of the dialog, from a deep link or not, is the /start
                                # command
                                $t_user_id = $this->user_id( isset( $p_update['user'] ) ? $p_update['user'] : NULL );

                                if( $t_user_id === '' ) {
                                        return NULL;
                                }

                                $t_update->account_id          = $t_user_id;
                                $t_update->account_name        = $this->user_name( $p_update['user'] );
                                $t_update->kind                = MaxBotUpdate::KIND_COMMAND;
                                $t_update->command             = 'start';
                                $t_update->command_payload     = isset( $p_update['payload'] ) ? trim( (string)$p_update['payload'] ) : '';
                                $t_update->message             = new MaxBotMessage();
                                $t_update->message->chat_id    = $t_user_id;

                                return $t_update;

                        case 'message_callback':
                                $t_callback = isset( $p_update['callback'] ) && is_array( $p_update['callback'] ) ? $p_update['callback'] : array();
                                $t_user_id  = $this->user_id( isset( $t_callback['user'] ) ? $t_callback['user'] : NULL );

                                if( $t_user_id === '' || !$this->message_is_private( isset( $p_update['message'] ) ? $p_update['message'] : NULL ) ) {
                                        return NULL;
                                }

                                $t_update->account_id    = $t_user_id;
                                $t_update->account_name  = $this->user_name( $t_callback['user'] );
                                $t_update->kind          = MaxBotUpdate::KIND_CALLBACK;
                                $t_update->callback_id   = isset( $t_callback['callback_id'] ) ? (string)$t_callback['callback_id'] : '';
                                $t_update->callback_data = isset( $t_callback['payload'] ) ? (string)$t_callback['payload'] : '';

                                # NULL when the message with the buttons is gone
                                if( isset( $p_update['message'] ) && is_array( $p_update['message'] ) ) {
                                        $t_update->message = $this->message_map( $p_update['message'], $t_user_id );
                                }

                                return $t_update;

                        case 'message_created':
                                $t_message = isset( $p_update['message'] ) && is_array( $p_update['message'] ) ? $p_update['message'] : array();
                                $t_user_id = $this->user_id( isset( $t_message['sender'] ) ? $t_message['sender'] : NULL );

                                # Only the dialog with the bot is served: the menus, the drafts
                                # and the lists of issues are personal, a message of a group
                                # chat the bot is a member of is no request to it
                                if( $t_user_id === '' || !$this->message_is_private( $t_message ) ) {
                                        return NULL;
                                }

                                $t_update->account_id   = $t_user_id;
                                $t_update->account_name = $this->user_name( $t_message['sender'] );
                                $t_update->message      = $this->message_map( $t_message, $t_user_id );

                                $t_content = $t_update->message;

                                if( $t_content->reply_to === NULL && $t_content->file === NULL
                                                && preg_match( '/^\/([A-Za-z0-9_]+)(?:@\S*)?(?:\s+(.*))?$/s', trim( $t_content->text ), $t_matches ) ) {
                                        $t_update->kind            = MaxBotUpdate::KIND_COMMAND;
                                        $t_update->command         = $t_matches[1];
                                        $t_update->command_payload = isset( $t_matches[2] ) ? trim( $t_matches[2] ) : '';
                                } else if( !is_blank( $t_content->text ) || $t_content->file !== NULL ) {
                                        $t_update->kind = MaxBotUpdate::KIND_MESSAGE;
                                }

                                # anything else ( a sticker, a contact, a location ) stays unsupported
                                return $t_update;
                }

                return NULL;
        }

        /**
         * The neutral message out of a message of the Bot API.
         *
         * Whatever the sender, the chat is the dialog with the user the update is
         * about: the bot talks to the user by his id.
         *
         * @param array  $p_message Message of the Bot API.
         * @param string $p_user_id User the dialog is with.
         * @return MaxBotMessage
         */
        private function message_map( array $p_message, $p_user_id ) {
                $t_address = (string)$p_user_id;
                $t_body    = isset( $p_message['body'] ) && is_array( $p_message['body'] ) ? $p_message['body'] : array();
                $t_link    = isset( $p_message['link'] ) && is_array( $p_message['link'] ) ? $p_message['link'] : NULL;
                $t_linked  = $t_link !== NULL && isset( $t_link['message'] ) && is_array( $t_link['message'] ) ? $t_link['message'] : NULL;
                $t_type    = $t_link !== NULL && isset( $t_link['type'] ) ? (string)$t_link['type'] : '';

                $t_result = $this->body_map( $t_body, $t_address );

                if( $t_linked === NULL ) {
                        return $t_result;
                }

                if( $t_type == 'reply' ) {
                        $t_result->reply_to = $this->body_map( $t_linked, $t_address );
                } else if( $t_type == 'forward' ) {
                        # A forwarded message has its content in the link, the body is empty
                        $t_forwarded = $this->body_map( $t_linked, $t_address );

                        if( is_blank( $t_result->text ) ) {
                                $t_result->text = $t_forwarded->text;
                        }

                        if( $t_result->file === NULL ) {
                                $t_result->file = $t_forwarded->file;
                        }
                }

                return $t_result;
        }

        /**
         * The neutral message out of the body of a message of the Bot API.
         *
         * @param array  $p_body    Body of the message: mid, text, attachments.
         * @param string $p_address Chat: the user id of MAX.
         * @return MaxBotMessage
         */
        private function body_map( array $p_body, $p_address ) {
                $t_message             = new MaxBotMessage();
                $t_message->chat_id    = $p_address;
                $t_message->message_id = isset( $p_body['mid'] ) ? (string)$p_body['mid'] : '';
                $t_message->text       = isset( $p_body['text'] ) ? (string)$p_body['text'] : '';

                $t_attachments = isset( $p_body['attachments'] ) && is_array( $p_body['attachments'] ) ? $p_body['attachments'] : array();
                $t_kinds       = array( 'file' => 'document', 'image' => 'photo', 'video' => 'video' );

                foreach( $t_attachments as $t_attachment ) {
                        $t_type = is_array( $t_attachment ) && isset( $t_attachment['type'] ) ? (string)$t_attachment['type'] : '';

                        if( !isset( $t_kinds[$t_type] ) || !isset( $t_attachment['payload']['url'] ) ) {
                                continue;
                        }

                        $t_message->file       = new MaxBotFile();
                        $t_message->file->ref  = (string)$t_attachment['payload']['url'];
                        $t_message->file->name = isset( $t_attachment['filename'] ) ? (string)$t_attachment['filename'] : '';
                        $t_message->file->size = isset( $t_attachment['size'] ) ? (int)$t_attachment['size'] : 0;
                        $t_message->file->kind = $t_kinds[$t_type];
                        break;
                }

                return $t_message;
        }

        /**
         * Id of a user of the Bot API.
         *
         * @param mixed $p_user User object of the Bot API.
         * @return string Empty when there is none.
         */
        private function user_id( $p_user ) {
                return is_array( $p_user ) && isset( $p_user['user_id'] ) ? (string)$p_user['user_id'] : '';
        }

        /**
         * Name of a user of the Bot API as MAX shows it: first and last name
         * followed by @username.
         *
         * @param mixed $p_user User object of the Bot API.
         * @return string Empty when there is none.
         */
        private function user_name( $p_user ) {
                if( !is_array( $p_user ) ) {
                        return '';
                }

                $t_name = trim( ( isset( $p_user['first_name'] ) ? (string)$p_user['first_name'] : '' )
                                . ' ' . ( isset( $p_user['last_name'] ) ? (string)$p_user['last_name'] : '' ) );

                if( $t_name === '' && isset( $p_user['name'] ) ) {
                        $t_name = trim( (string)$p_user['name'] );
                }

                if( !empty( $p_user['username'] ) ) {
                        $t_name = trim( $t_name . ' @' . (string)$p_user['username'] );
                }

                return $t_name;
        }

        /**
         * Whether a message of the Bot API belongs to the dialog of a user with the
         * bot. A message naming no chat type is taken as one of the dialog.
         *
         * @param mixed $p_message Message object of the Bot API, NULL when missing.
         * @return boolean
         */
        private function message_is_private( $p_message ) {
                if( !is_array( $p_message ) || !isset( $p_message['recipient']['chat_type'] ) ) {
                        return TRUE;
                }

                return (string)$p_message['recipient']['chat_type'] === 'dialog';
        }

        /**
         * The body of a new message taken from a neutral message: the buttons, the
         * message answered and the format of the text; the text is set by the caller.
         *
         * @param array $p_message The message.
         * @return array
         */
        private function body( array $p_message ) {
                $t_body     = array();
                $t_keyboard = $this->keyboard_attachment( $p_message );

                if( $t_keyboard !== NULL ) {
                        $t_body['attachments'] = array( $t_keyboard );
                }

                if( isset( $p_message['reply_to_message_id'] ) && !is_blank( (string)$p_message['reply_to_message_id'] ) ) {
                        $t_body['link'] = array( 'type' => 'reply', 'mid' => (string)$p_message['reply_to_message_id'] );
                }

                if( isset( $p_message['format'] ) ) {
                        $t_body['format'] = (string)$p_message['format'];
                }

                return $t_body;
        }

        /**
         * The inline keyboard of a neutral message as an attachment of MAX.
         *
         * The rows too long for MAX are wrapped, the rows and the buttons beyond the
         * limits of a keyboard are left out, a text too long is cut.
         *
         * @param array $p_message The message.
         * @return array|null NULL when the message has no buttons.
         */
        private function keyboard_attachment( array $p_message ) {
                if( !isset( $p_message['reply_markup'] ) || !( $p_message['reply_markup'] instanceof MaxBotKeyboard ) ) {
                        return NULL;
                }

                $t_rows  = array();
                $t_count = 0;

                foreach( $p_message['reply_markup']->getRows() as $t_row ) {
                        $t_buttons  = array();
                        $t_has_link = FALSE;

                        foreach( $t_row as $t_button ) {
                                $t_mapped = $this->button_map( $t_button );

                                if( $t_mapped !== NULL ) {
                                        $t_buttons[] = $t_mapped;
                                        $t_has_link  = $t_has_link || $t_mapped['type'] == 'link';
                                }
                        }

                        $t_width = $t_has_link ? self::ROW_LINK_BUTTONS_MAX : self::ROW_BUTTONS_MAX;

                        foreach( array_chunk( $t_buttons, $t_width ) as $t_chunk ) {
                                if( count( $t_rows ) >= self::ROWS_MAX || $t_count + count( $t_chunk ) > self::BUTTONS_MAX ) {
                                        plugin_log_event( 'ERROR! MAX keyboard is too big, the buttons beyond the limits are left out.' );
                                        break 2;
                                }

                                $t_rows[] = $t_chunk;
                                $t_count += count( $t_chunk );
                        }
                }

                if( empty( $t_rows ) ) {
                        return NULL;
                }

                return array(
                                          'type'    => 'inline_keyboard',
                                          'payload' => array( 'buttons' => $t_rows ),
                );
        }

        /**
         * A button of MAX out of a button of MaxBotKeyboard.
         *
         * @param mixed $p_button Button: 'text' and 'callback_data' or 'url'.
         * @return array|null NULL for a button MAX has no counterpart of.
         */
        private function button_map( $p_button ) {
                if( !is_array( $p_button ) || !isset( $p_button['text'] ) ) {
                        return NULL;
                }

                $t_text = mb_substr( (string)$p_button['text'], 0, self::BUTTON_TEXT_LENGTH_MAX );

                if( isset( $p_button['url'] ) ) {
                        return array( 'type' => 'link', 'text' => $t_text, 'url' => (string)$p_button['url'] );
                }

                if( isset( $p_button['callback_data'] ) ) {
                        return array( 'type' => 'callback', 'text' => $t_text, 'payload' => (string)$p_button['callback_data'] );
                }

                return NULL;
        }

        /**
         * The attachments of a message to keep through an edit: the uploaded media,
         * sent back by their tokens. The keyboard is replaced, and the preview of a
         * link (share) is built by MAX out of the text again: sent back by its token
         * it fails the whole edit.
         *
         * @param array $p_body Body of the message as MAX gave it.
         * @return array
         */
        private function attachments_kept( array $p_body ) {
                $t_kept = array();

                if( !isset( $p_body['attachments'] ) || !is_array( $p_body['attachments'] ) ) {
                        return $t_kept;
                }

                foreach( $p_body['attachments'] as $t_attachment ) {
                        if( !is_array( $t_attachment ) || !isset( $t_attachment['type'] )
                                        || !in_array( $t_attachment['type'], array( 'file', 'image', 'video', 'audio' ), TRUE )
                                        || !isset( $t_attachment['payload']['token'] ) ) {
                                continue;
                        }

                        $t_kept[] = array(
                                                  'type'    => (string)$t_attachment['type'],
                                                  'payload' => array( 'token' => (string)$t_attachment['payload']['token'] ),
                        );
                }

                return $t_kept;
        }

        /**
         * The subscriptions listed by an answer of GET /subscriptions.
         *
         * @param array $p_result Answer of MAX.
         * @return array Subscriptions having an url.
         */
        private function subscriptions( array $p_result ) {
                $t_list = array();

                if( isset( $p_result['subscriptions'] ) && is_array( $p_result['subscriptions'] ) ) {
                        foreach( $p_result['subscriptions'] as $t_subscription ) {
                                if( is_array( $t_subscription ) && !is_blank( isset( $t_subscription['url'] ) ? (string)$t_subscription['url'] : '' ) ) {
                                        $t_list[] = $t_subscription;
                                }
                        }
                }

                return $t_list;
        }

        /**
         * Send a message to the dialog with a user.
         *
         * @param string  $p_user_id    User the dialog is with.
         * @param array   $p_body       Body of the message.
         * @param boolean $p_quiet      True for a failure not to be logged.
         * @param string  $p_error_code Out: code of the error MAX answered with.
         * @return string|null Id of the message, NULL when it did not go through.
         */
        private function message_post( $p_user_id, array $p_body, $p_quiet = FALSE, &$p_error_code = NULL ) {
                $t_result = $this->request( 'POST', '/messages', array(
                                          'query' => array( 'user_id' => (string)$p_user_id ),
                                          'json'  => $p_body,
                        ), $p_quiet, $p_error_code );

                if( $t_result === NULL ) {
                        return NULL;
                }

                return isset( $t_result['message']['body']['mid'] ) ? (string)$t_result['message']['body']['mid'] : NULL;
        }

        /**
         * Upload a file to MAX: an upload url is asked for and the file is posted
         * there.
         *
         * @param string $p_file_name Name of the file.
         * @param string $p_content   Content of the file.
         * @return string|null Token of the file to attach it by, NULL on a failure.
         */
        private function upload( $p_file_name, $p_content ) {
                $t_slot = $this->request( 'POST', '/uploads', array( 'query' => array( 'type' => 'file' ) ) );

                if( $t_slot === NULL || !isset( $t_slot['url'] ) || is_blank( (string)$t_slot['url'] ) ) {
                        return NULL;
                }

                $t_result = $this->request( 'POST', (string)$t_slot['url'], array(
                                          'multipart' => array(
                                                                    array(
                                                                                              'name'     => 'data',
                                                                                              'contents' => $p_content,
                                                                                              'filename' => $p_file_name,
                                                                    ),
                                          ),
                        ) );

                # A file has its token in the answer of the upload, a video or an audio
                # has it in the answer of the upload url already
                if( $t_result !== NULL && isset( $t_result['token'] ) && !is_blank( (string)$t_result['token'] ) ) {
                        return (string)$t_result['token'];
                }

                if( isset( $t_slot['token'] ) && !is_blank( (string)$t_slot['token'] ) ) {
                        return (string)$t_slot['token'];
                }

                plugin_log_event( 'ERROR! MAX upload of ' . $p_file_name . ' gave no token.' );

                return NULL;
        }

        /**
         * Extension of a file having no name, out of its type or its url.
         *
         * @param MaxBotFile $p_file         The file.
         * @param string          $p_content_type Content type the file was served with.
         * @return string
         */
        private function extension_guess( MaxBotFile $p_file, $p_content_type ) {
                $t_types = array(
                                          'image/jpeg'      => 'jpg',
                                          'image/png'       => 'png',
                                          'image/gif'       => 'gif',
                                          'image/webp'      => 'webp',
                                          'image/heic'      => 'heic',
                                          'image/bmp'       => 'bmp',
                                          'image/tiff'      => 'tiff',
                                          'video/mp4'       => 'mp4',
                                          'video/quicktime' => 'mov',
                                          'video/webm'      => 'webm',
                );

                $t_type = strtolower( trim( explode( ';', (string)$p_content_type )[0] ) );

                if( isset( $t_types[$t_type] ) ) {
                        return $t_types[$t_type];
                }

                $t_extension = strtolower( pathinfo( (string)parse_url( $p_file->ref, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

                if( preg_match( '/^[a-z0-9]{1,5}$/', $t_extension ) ) {
                        return $t_extension;
                }

                return $p_file->kind == 'video' ? 'mp4' : 'jpg';
        }

        /**
         * Call a method of the Bot API.
         *
         * A method answering with the success flag answers with HTTP 200 on a failure
         * as well, so the flag is checked besides the status.
         *
         * @param string  $p_method     HTTP method.
         * @param string  $p_uri        Path of the method, or a full url.
         * @param array   $p_options    Options of the request: 'query', 'json', 'multipart'.
         * @param boolean $p_quiet      True for a call whose failure is expected and not logged.
         * @param string  $p_error_code Out: code of the error MAX answered with, empty for none.
         * @return array|null Decoded answer, NULL when the call failed.
         */
        private function request( $p_method, $p_uri, array $p_options = array(), $p_quiet = FALSE, &$p_error_code = NULL ) {
                $p_error_code     = '';
                $this->last_error = '';

                $t_query = isset( $p_options['query'] ) ? '?' . http_build_query( $p_options['query'] ) : '';

                if( isset( $p_options['multipart'] ) ) {
                        $t_sent = '[file ' . strlen( (string)$p_options['multipart'][0]['contents'] ) . ' bytes]';
                } else {
                        $t_sent = isset( $p_options['json'] ) ? json_encode( $p_options['json'] ) : '';
                }

                $this->debug( $p_method . ' ' . $p_uri . $t_query . ' ' . $t_sent );

                try {
                        $t_response = $this->client()->request( $p_method, $p_uri, $p_options );
                } catch( Exception $t_error ) {
                        $this->last_error = $t_error->getMessage();
                        $this->debug( 'Failure: ' . $this->last_error );

                        if( !$p_quiet ) {
                                plugin_log_event( 'ERROR! MAX ' . $p_method . ' ' . $p_uri . ' failed: ' . $this->last_error );
                        }

                        return NULL;
                }

                $t_status = $t_response->getStatusCode();
                $t_raw    = (string)$t_response->getBody();

                $this->debug( 'Response ' . $t_status . ': ' . $t_raw );

                $t_result = json_decode( $t_raw, TRUE, 512, JSON_BIGINT_AS_STRING );

                if( $t_status == 200 && is_array( $t_result ) && !( isset( $t_result['success'] ) && $t_result['success'] === FALSE ) ) {
                        return $t_result;
                }

                if( is_array( $t_result ) && isset( $t_result['code'] ) ) {
                        $p_error_code = (string)$t_result['code'];
                }

                $t_message = is_array( $t_result ) && isset( $t_result['message'] ) ? (string)$t_result['message'] : $t_raw;

                $this->last_error = 'HTTP ' . $t_status . ( $p_error_code === '' ? '' : ' ' . $p_error_code ) . ': ' . $t_message;

                if( !$p_quiet ) {
                        plugin_log_event( 'ERROR! MAX ' . $p_method . ' ' . $p_uri . ' failed: ' . $this->last_error );
                }

                return NULL;
        }

        /**
         * The HTTP client talking to MAX, with the proxy and the timeout of the plugin.
         *
         * The token goes in the Authorization header of every request, the uploads
         * and the downloads of the files included.
         *
         * @return \GuzzleHttp\Client
         */
        private function client() {
                $t_token = (string)plugin_config_get( 'api_key' );

                if( $this->client !== NULL && $this->client_token === $t_token ) {
                        return $this->client;
                }

                $t_options = array(
                                          'base_uri'    => self::API_URL,
                                          'timeout'     => (int)plugin_config_get( 'time_out_server_response' ),
                                          'http_errors' => FALSE,
                                          'headers'     => array( 'Authorization' => $t_token ),
                                          'verify'      => $this->ca_bundle(),
                );

                $t_proxy_address = plugin_config_get( 'proxy_address' );

                if( !is_blank( $t_proxy_address ) ) {
                        $t_options['proxy'] = 'socks5://' . $t_proxy_address;
                }

                $this->client       = new \GuzzleHttp\Client( $t_options );
                $this->client_token = $t_token;

                return $this->client;
        }

        /**
         * CA bundle to verify the servers of MAX with.
         *
         * The certificates of MAX are issued by the Russian Trusted Root CA of the
         * Ministry of Digital Development, which the usual CA stores lack. The
         * plugin ships that root and adds it to the CA store of the system, so the
         * other servers the bot talks to keep being verified as before.
         *
         * @return string Path of the bundle.
         */
        private function ca_bundle() {
                $t_root = dirname( dirname( __FILE__ ) ) . '/certs/russian_trusted_root_ca.pem';

                $t_locations = openssl_get_cert_locations();
                $t_system    = NULL;

                foreach( array( ini_get( 'curl.cainfo' ), ini_get( 'openssl.cafile' ), $t_locations['default_cert_file'] ) as $t_file ) {
                        if( !is_blank( $t_file ) && is_readable( $t_file ) ) {
                                $t_system = $t_file;
                                break;
                        }
                }

                if( $t_system === NULL ) {
                        return $t_root;
                }

                $t_content = file_get_contents( $t_system ) . "\n" . file_get_contents( $t_root );
                $t_hash    = md5( $t_content );
                $t_bundle  = sys_get_temp_dir() . '/mantis_maxbot_ca_' . $t_hash . '.pem';

                # The temporary directory is shared: a file planted under the name by
                # another user is not trusted, only an own one holding exactly the
                # expected certificates
                if( is_file( $t_bundle ) && function_exists( 'posix_geteuid' ) && fileowner( $t_bundle ) !== posix_geteuid() ) {
                        return $t_root;
                }

                if( !is_readable( $t_bundle ) || md5_file( $t_bundle ) !== $t_hash ) {
                        # Written aside and renamed, so a parallel request never reads a half of it
                        $t_part = $t_bundle . '.' . getmypid();

                        if( file_put_contents( $t_part, $t_content ) === FALSE || !rename( $t_part, $t_bundle ) ) {
                                @unlink( $t_part );
                                return $t_root;
                        }
                }

                return $t_bundle;
        }

        /**
         * Write a line of the exchange into the debug log of the connection.
         *
         * @param string $p_line The line, the token never goes into it.
         * @return void
         */
        private function debug( $p_line ) {
                if( $this->logger === NULL ) {
                        $this->logger = plugin_config_get( 'debug_connection_enabled' ) == ON
                                        ? new MaxBotFileLogger( plugin_config_get( 'debug_connection_log_path' ) )
                                        : FALSE;
                }

                if( $this->logger !== FALSE ) {
                        $this->logger->write( $p_line );
                }
        }
}
