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
 * A file attached to an incoming message, as the transport describes it: the
 * dialogs check it against the upload rules and hand it back to the transport
 * to be downloaded, see messenger_file_fetch().
 */
class TelegramBotFile {

        /**
         * Name of the transport the file is kept by.
         * @var string
         */
        public $transport = '';

        /**
         * What the transport needs to download the file: a file id, a url.
         * @var string
         */
        public $ref = '';

        /**
         * Name of the file, empty when the messenger gives none (a photo).
         * @var string
         */
        public $name = '';

        /**
         * Size of the file in bytes, 0 when unknown.
         * @var integer
         */
        public $size = 0;

        /**
         * Kind of the file: 'photo', 'video' or 'document'.
         * @var string
         */
        public $kind = '';

        /**
         * The file as an array to be kept in a draft.
         *
         * @return array
         */
        public function to_array() {
                return array(
                                          'transport' => $this->transport,
                                          'ref'       => $this->ref,
                                          'name'      => $this->name,
                                          'size'      => $this->size,
                                          'kind'      => $this->kind,
                );
        }

        /**
         * The file kept in a draft by to_array().
         *
         * A draft of the versions before the transports keeps the Telegram file id
         * under 'file_id'.
         *
         * @param array $p_data The file as an array.
         * @return TelegramBotFile|null NULL when the array holds no file.
         */
        public static function from_array( $p_data ) {
                if( !is_array( $p_data ) ) {
                        return NULL;
                }

                $t_file = new TelegramBotFile();

                if( isset( $p_data['file_id'] ) ) {
                        $t_file->transport = MESSENGER_TRANSPORT_DEFAULT;
                        $t_file->ref       = (string)$p_data['file_id'];
                        $t_file->name      = isset( $p_data['file_name'] ) ? (string)$p_data['file_name'] : '';
                        $t_file->size      = isset( $p_data['file_size'] ) ? (int)$p_data['file_size'] : 0;
                } else {
                        foreach( array( 'transport', 'ref', 'name', 'kind' ) as $t_key ) {
                                $t_file->$t_key = isset( $p_data[$t_key] ) ? (string)$p_data[$t_key] : '';
                        }

                        $t_file->size = isset( $p_data['size'] ) ? (int)$p_data['size'] : 0;
                }

                return is_blank( $t_file->ref ) ? NULL : $t_file;
        }
}
