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
 * Logger of the connection debug, writing the exchange with MAX into a plain file.
 */
class TelegramBotFileLogger {

        /**
         * File the lines are appended to.
         *
         * @var string
         */
        private $log_path;

        /**
         * Whether a failed write has been reported to the log of MantisBT already:
         * an unwritable debug file is worth a single complaint, not one per request.
         *
         * @var boolean
         */
        private $write_failure_logged = FALSE;

        /**
         * @param string $p_log_path File the lines are appended to.
         */
        public function __construct( $p_log_path ) {
                $this->log_path = $p_log_path;
        }

        /**
         * Append a line to the log file.
         *
         * @param string $p_line The line.
         * @return void
         */
        public function write( $p_line ) {

                if( is_blank( $this->log_path ) ) {
                        return;
                }

                $t_line = sprintf( '[%s] %s' . PHP_EOL, date( 'Y-m-d H:i:s' ), (string)$p_line );

                if( @file_put_contents( $this->log_path, $t_line, FILE_APPEND | LOCK_EX ) !== FALSE ) {
                        return;
                }

                if( !$this->write_failure_logged ) {
                        $this->write_failure_logged = TRUE;

                        plugin_log_event( 'ERROR! The debug of the connection is not written to "' . $this->log_path . '"' );
                }
        }
}
