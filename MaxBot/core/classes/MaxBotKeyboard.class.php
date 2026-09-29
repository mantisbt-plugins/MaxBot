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
 * Inline keyboard of a message, the neutral form MaxBotApi turns into the markup of MAX.
 *
 * A button is an array of its label and of its action: either
 * array( 'text' => ..., 'callback_data' => ... ) for a button calling the bot
 * back or array( 'text' => ..., 'url' => ... ) for a link.
 */
class MaxBotKeyboard {

        /**
         * Rows of the keyboard, every row is a list of buttons.
         * @var array
         */
        private $rows = array();

        /**
         * Add a row of buttons, every argument is a button of the row.
         *
         * @return MaxBotKeyboard
         */
        public function addRow() {
                # An empty or NULL argument stands for a button left out of the
                # row, a row left without buttons is dropped
                $t_row = array_values( array_filter( func_get_args(), function( $p_button ) {
                        return is_array( $p_button ) && !empty( $p_button );
                } ) );

                if( count( $t_row ) > 0 ) {
                        $this->rows[] = $t_row;
                }

                return $this;
        }

        /**
         * Rows of the keyboard.
         *
         * @return array
         */
        public function getRows() {
                return $this->rows;
        }
}
