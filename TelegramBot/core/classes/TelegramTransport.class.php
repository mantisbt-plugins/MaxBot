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

use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;

/**
 * Telegram, spoken to through the Bot API library longman/telegram-bot.
 *
 * The only place of the plugin the library is used in: the rest of the plugin
 * talks to Telegram through the messenger_*() functions.
 */
class TelegramTransport implements TelegramBotTransport {

        # Longest text of a message and of the caption of a file, Bot API limits
        const TEXT_LENGTH_MAX    = 4096;
        const CAPTION_LENGTH_MAX = 1024;
        # Longest text of the pop-up answering the press of a button
        const ALERT_LENGTH_MAX   = 200;
        # Biggest file the Bot API serves to a bot
        const FILE_SIZE_MAX      = 20971520;

        /**
         * Ids of the documents uploaded by this process, keyed by the hash of their
         * name and content: a file sent to many chats is uploaded once.
         * @var array
         */
        private $documents = array();

        /**
         * The library object of the bot, NULL until the first call to Telegram.
         * @var \Longman\TelegramBot\Telegram|null
         */
        private $telegram = NULL;

        public function name() {
                return 'tg';
        }

        public function title() {
                return 'Telegram';
        }

        public function is_enabled() {
                return !is_blank( plugin_config_get( 'api_key' ) ) && !is_blank( plugin_config_get( 'bot_name' ) );
        }

        public function text_length_max() {
                return self::TEXT_LENGTH_MAX;
        }

        public function file_size_max() {
                return self::FILE_SIZE_MAX;
        }

        public function send( $p_chat_id, array $p_message ) {
                $t_data = $this->markup( $p_message );
                $t_text = isset( $p_message['text'] ) ? (string)$p_message['text'] : '';
                $t_ids  = array();

                $t_data['chat_id'] = $p_chat_id;

                # The buttons go with every part of a long text, the way they always did
                do {
                        $t_data['text'] = mb_substr( $t_text, 0, self::TEXT_LENGTH_MAX );
                        $t_text         = mb_substr( $t_text, self::TEXT_LENGTH_MAX );

                        $t_result = $this->request( 'sendMessage', $t_data );

                        if( $t_result !== NULL ) {
                                $t_ids[] = (string)$t_result->getMessageId();
                        }
                } while( mb_strlen( $t_text, 'UTF-8' ) > 0 );

                return $t_ids;
        }

        public function edit( $p_chat_id, $p_message_id, array $p_message ) {
                $t_data = $this->markup( $p_message );

                $t_data['chat_id']    = $p_chat_id;
                $t_data['message_id'] = $p_message_id;

                # a message stays where it is, the answered one is of no use to an edit
                unset( $t_data['reply_to_message_id'] );

                if( !array_key_exists( 'text', $p_message ) ) {
                        return $this->request( 'editMessageReplyMarkup', $t_data ) !== NULL;
                }

                $t_data['text'] = (string)$p_message['text'];

                return $this->request( 'editMessageText', $t_data ) !== NULL;
        }

        public function delete( $p_chat_id, $p_message_id ) {
                # A bot may delete a message for 48 hours only, an older one just stays
                return $this->request( 'deleteMessage', array(
                                                  'chat_id'    => $p_chat_id,
                                                  'message_id' => $p_message_id,
                        ), /* quiet */ TRUE ) !== NULL;
        }

        public function answer_callback( $p_callback_id, $p_text = '' ) {
                $t_data = array( 'callback_query_id' => $p_callback_id );

                if( !is_blank( $p_text ) ) {
                        # A longer text is refused along with the whole answer
                        $t_data['text']       = mb_substr( $p_text, 0, self::ALERT_LENGTH_MAX );
                        $t_data['show_alert'] = TRUE;
                }

                return $this->request( 'answerCallbackQuery', $t_data ) !== NULL;
        }

        /**
         * The text is the caption of the document, so the file and the text are a
         * single message of the chat. A caption holds a quarter of a text message
         * though: a longer text goes as a message of its own and the document as a
         * reply to it, which keeps the two together on the screen.
         */
        public function send_document( $p_chat_id, $p_file_name, $p_content, array $p_message ) {
                $t_text = isset( $p_message['text'] ) ? (string)$p_message['text'] : '';
                $t_ids  = array();

                if( mb_strlen( $t_text, 'UTF-8' ) > self::CAPTION_LENGTH_MAX ) {
                        $t_ids = $this->send( $p_chat_id, $p_message );

                        $t_data = empty( $t_ids ) ? array() : array( 'reply_to_message_id' => end( $t_ids ) );
                } else {
                        $t_data = $this->markup( $p_message );

                        if( $t_text !== '' ) {
                                $t_data['caption'] = $t_text;
                        }
                }

                $t_data['chat_id'] = $p_chat_id;

                $t_key = sha1( $p_file_name . "\0" . $p_content );

                if( isset( $this->documents[$t_key] ) ) {
                        $t_data['document'] = $this->documents[$t_key];
                } else {
                        # Uploaded from memory: the multipart part is named after the uri
                        # metadata of the stream, which is where the file name goes
                        $t_resource = fopen( 'php://temp', 'r+' );
                        fwrite( $t_resource, $p_content );
                        rewind( $t_resource );

                        $t_data['document'] = new \GuzzleHttp\Psr7\Stream( $t_resource, array( 'metadata' => array( 'uri' => $p_file_name ) ) );
                }

                $t_result = $this->request( 'sendDocument', $t_data );

                if( $t_result === NULL ) {
                        return array();
                }

                if( $t_result->getDocument() !== NULL ) {
                        $this->documents[$t_key] = $t_result->getDocument()->getFileId();
                }

                $t_ids[] = (string)$t_result->getMessageId();

                return $t_ids;
        }

        public function account_name( $p_account_id ) {
                $t_chat = $this->request( 'getChat', array( 'chat_id' => $p_account_id ), /* quiet */ TRUE );

                if( $t_chat === NULL ) {
                        return '';
                }

                if( !is_blank( (string)$t_chat->getUsername() ) ) {
                        return '@' . $t_chat->getUsername();
                }

                # A telegram account may have no username at all, the visible name is
                # the next best thing
                return trim( $t_chat->getFirstName() . ' ' . $t_chat->getLastName() );
        }

        /**
         * The file is asked for in two steps: its path on the servers of Telegram
         * first, the content then. A photo has no name of its own, the path is the
         * name then.
         */
        public function download( TelegramBotFile $p_file ) {
                $this->session();

                $t_response = Request::getFile( array( 'file_id' => $p_file->ref ) );

                if( !$t_response->isOk() ) {
                        throw new Exception( (string)$t_response->getDescription() );
                }

                $t_file = $t_response->getResult();

                # the library throws on a failure, the path is the one it writes to
                Request::downloadFile( $t_file );

                return array(
                                          'tmp_name' => plugin_config_get( 'download_path' ) . $t_file->getFilePath(),
                                          'name'     => is_blank( $p_file->name ) ? $t_file->getFilePath() : $p_file->name,
                );
        }

        public function updates_parse( $p_raw ) {
                $t_post = json_decode( (string)$p_raw, TRUE );

                if( !is_array( $t_post ) ) {
                        return array();
                }

                return array( $this->update_map( new Update( $t_post ) ) );
        }

        /**
         * Request::getUpdates() is called directly instead of Telegram::handleGetUpdates():
         * without a database the latter does not know last_update_id, so it polls from
         * offset 0 and then spends another full timeout on a confirming request before
         * returning. Telegram confirms the updates by the offset of the next call.
         */
        public function updates_poll( $p_offset, $p_timeout, &$p_next_offset ) {
                $this->session();

                $t_response = Request::getUpdates( array(
                                          'offset'  => (int)$p_offset,
                                          'timeout' => $p_timeout > 0 ? (int)$p_timeout : NULL,
                ) );

                if( !$t_response->isOk() ) {
                        throw new Exception( (string)$t_response->getDescription() );
                }

                $t_updates     = $t_response->getResult();
                $p_next_offset = (int)$p_offset;
                $t_results     = array();

                foreach( $t_updates as $t_update ) {
                        $p_next_offset = $t_update->getUpdateId() + 1;
                        $t_results[]   = $this->update_map( $t_update );
                }

                return $t_results;
        }

        /**
         * Point the webhook of the bot at the given url.
         *
         * @param string $p_url         Url Telegram posts the updates to.
         * @param string $p_certificate Self-signed certificate of the server, empty for none.
         * @return string Answer of Telegram.
         * @throws Exception When Telegram cannot be reached.
         */
        public function webhook_set( $p_url, $p_certificate = '' ) {
                $this->session();

                $t_data = array( 'url' => $p_url );

                if( !is_blank( $p_certificate ) ) {
                        # the handle must stay referenced until setWebhook(): closing it deletes
                        # the file; Request turns a local path in 'certificate' into a multipart
                        # upload by itself
                        $t_cert_file = tmpfile();
                        fwrite( $t_cert_file, $p_certificate );

                        $t_data['certificate'] = stream_get_meta_data( $t_cert_file )['uri'];
                }

                return Request::setWebhook( $t_data )->getDescription();
        }

        /**
         * Remove the webhook of the bot, which lets the updates be polled.
         *
         * @return string Answer of Telegram.
         * @throws Exception When Telegram cannot be reached.
         */
        public function webhook_delete() {
                $this->session();

                return Request::deleteWebhook()->getDescription();
        }

        /**
         * State of the webhook of the bot.
         *
         * @return array 'url', 'pending_update_count', 'last_error_date',
         *               'last_error_message', 'has_custom_certificate'.
         * @throws Exception When Telegram cannot be reached.
         */
        public function webhook_info() {
                $this->session();

                $t_response = Request::getWebhookInfo();

                if( !$t_response->isOk() ) {
                        throw new Exception( $t_response->getDescription() );
                }

                $t_info = $t_response->getResult();

                return array(
                                          'url'                    => $t_info->getUrl(),
                                          'pending_update_count'   => $t_info->getPendingUpdateCount(),
                                          'last_error_date'        => $t_info->getLastErrorDate(),
                                          'last_error_message'     => $t_info->getLastErrorMessage(),
                                          'has_custom_certificate' => $t_info->getHasCustomCertificate(),
                );
        }

        /**
         * The neutral update out of an update of the Bot API.
         *
         * An edited message is taken as a new one, the way the library hands the
         * content of an update out. A command sent as a reply is the text of a
         * reply, not a command.
         *
         * @param Update $p_update Update of the Bot API.
         * @return TelegramBotUpdate
         */
        private function update_map( Update $p_update ) {
                $t_update            = new TelegramBotUpdate();
                $t_update->transport = $this->name();

                $t_content = $p_update->getUpdateContent();

                if( $t_content === NULL ) {
                        return $t_update;
                }

                # the entities answer NULL for a field they do not carry
                $t_from = $t_content->getFrom();

                if( $t_from !== NULL ) {
                        $t_update->account_id = (string)$t_from->getId();
                        $t_update->lang       = $t_from->getLanguageCode();
                }

                if( $t_content instanceof CallbackQuery ) {
                        $t_update->kind          = TelegramBotUpdate::KIND_CALLBACK;
                        $t_update->callback_id   = (string)$t_content->getId();
                        $t_update->callback_data = (string)$t_content->getData();

                        if( $t_content->getMessage() !== NULL ) {
                                $t_update->message = $this->message_map( $t_content->getMessage() );
                        }

                        return $t_update;
                }

                if( !( $t_content instanceof Message ) ) {
                        return $t_update;
                }

                $t_update->message = $this->message_map( $t_content );

                switch( $t_content->getType() ) {
                        case 'command':
                                if( $t_update->message->reply_to === NULL ) {
                                        $t_update->kind            = TelegramBotUpdate::KIND_COMMAND;
                                        $t_update->command         = (string)$t_content->getCommand();
                                        $t_update->command_payload = trim( (string)$t_content->getText( TRUE ) );
                                } else {
                                        $t_update->kind = TelegramBotUpdate::KIND_MESSAGE;
                                }
                                break;

                        case 'text':
                        case 'photo':
                        case 'video':
                        case 'document':
                                $t_update->kind = TelegramBotUpdate::KIND_MESSAGE;
                                break;
                }

                return $t_update;
        }

        /**
         * The neutral message out of a message of the Bot API.
         *
         * @param Message $p_message    Message of the Bot API.
         * @param boolean $p_with_reply False to leave out the message replied to.
         * @return TelegramBotMessage
         */
        private function message_map( Message $p_message, $p_with_reply = TRUE ) {
                $t_message             = new TelegramBotMessage();
                $t_message->chat_id    = messenger_address_make( $this->name(), $p_message->getChat()->getId() );
                $t_message->message_id = (string)$p_message->getMessageId();

                $t_text = $p_message->getText();

                if( is_blank( $t_text ) ) {
                        $t_text = $p_message->getCaption();
                }

                $t_message->text = (string)$t_text;

                switch( $p_message->getType() ) {
                        case 'video':
                                $t_file = $p_message->getVideo();
                                break;

                        case 'photo':
                                # the sizes of a photo go from the smallest to the biggest
                                $t_sizes = $p_message->getPhoto();
                                $t_file  = end( $t_sizes );
                                break;

                        case 'document':
                                $t_file = $p_message->getDocument();
                                break;

                        default:
                                $t_file = NULL;
                }

                if( $t_file ) {
                        $t_message->file            = new TelegramBotFile();
                        $t_message->file->transport = $this->name();
                        $t_message->file->ref       = (string)$t_file->getFileId();
                        $t_message->file->name      = (string)$t_file->getFileName();
                        $t_message->file->size      = (int)$t_file->getFileSize();
                        $t_message->file->kind      = $p_message->getType();
                }

                if( $p_with_reply && $p_message->getReplyToMessage() !== NULL ) {
                        $t_message->reply_to = $this->message_map( $p_message->getReplyToMessage(), FALSE );
                }

                return $t_message;
        }

        /**
         * Parameters of the Bot API taken from a neutral message: the buttons and
         * the message answered.
         *
         * @param array $p_message The message.
         * @return array
         */
        private function markup( array $p_message ) {
                $t_data = array();

                if( isset( $p_message['reply_markup'] ) && $p_message['reply_markup'] instanceof TelegramBotKeyboard ) {
                        # the library encodes an array parameter into JSON by itself
                        $t_data['reply_markup'] = array( 'inline_keyboard' => $p_message['reply_markup']->getRows() );
                }

                if( isset( $p_message['reply_to_message_id'] ) && !is_blank( (string)$p_message['reply_to_message_id'] ) ) {
                        $t_data['reply_to_message_id'] = $p_message['reply_to_message_id'];
                }

                return $t_data;
        }

        /**
         * Call a method of the Bot API.
         *
         * @param string  $p_action Method of the Bot API.
         * @param array   $p_data   Parameters of the method.
         * @param boolean $p_quiet  True for a call whose failure is expected and not logged.
         * @return mixed Result of the call, NULL when it failed.
         */
        private function request( $p_action, array $p_data, $p_quiet = FALSE ) {
                try {
                        $this->session();

                        $t_response = Request::send( $p_action, $p_data );
                } catch( Exception $t_error ) {
                        if( !$p_quiet ) {
                                plugin_log_event( 'ERROR! Telegram ' . $p_action . ' failed: ' . $t_error->getMessage() );
                        }

                        return NULL;
                }

                if( !$t_response->isOk() ) {
                        # Pressing the very button a card is drawn by leaves the card as it is,
                        # which Telegram reports as an error
                        if( !$p_quiet && strpos( (string)$t_response->getDescription(), 'message is not modified' ) === FALSE ) {
                                plugin_log_event( 'ERROR! Telegram ' . $p_action . ' failed: ' . $t_response->getDescription() );
                        }

                        return NULL;
                }

                # A method returning TRUE only has nothing else to give back
                $t_result = $t_response->getResult();

                return $t_result === NULL ? TRUE : $t_result;
        }

        /**
         * Make the library ready to talk to Telegram.
         *
         * The library is set up on the first call only, not on every request of
         * MantisBT: most pages never talk to Telegram. Its client is a static one,
         * so the setup holds for the rest of the process.
         *
         * @return void
         * @throws Exception When the settings of the bot are refused by the library.
         */
        private function session() {
                if( $this->telegram !== NULL || !$this->is_enabled() ) {
                        return;
                }

                $this->telegram = new \Longman\TelegramBot\Telegram( plugin_config_get( 'api_key' ), plugin_config_get( 'bot_name' ) );

                $t_client_prop = array(
                                          'base_uri' => plugin_config_get( 'api_url' ),
                                          'timeout'  => plugin_config_get( 'time_out_server_response' ),
                );

                $t_proxy_address = plugin_config_get( 'proxy_address' );

                if( !is_blank( $t_proxy_address ) ) {
                        $t_client_prop['proxy'] = 'socks5://' . $t_proxy_address;
                }

                Request::setClient( new \GuzzleHttp\Client( $t_client_prop ) );

                $this->telegram->setDownloadPath( plugin_config_get( 'download_path' ) );
                $this->telegram->useGetUpdatesWithoutDatabase();

                if( plugin_config_get( 'debug_connection_enabled' ) == ON ) {
                        $t_logger = new TelegramBotFileLogger( plugin_config_get( 'debug_connection_log_path' ) );

                        # The same file takes the exchange and the updates: they belong to one
                        # conversation with Telegram and are read together
                        \Longman\TelegramBot\TelegramLog::initialize( $t_logger, $t_logger );

                        # Without this the library keeps the successful requests to itself and
                        # writes the failed ones only, which tells nothing about what was sent
                        \Longman\TelegramBot\TelegramLog::$always_log_request_and_response = TRUE;
                }
        }
}
