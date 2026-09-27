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
 * A message of a chat, as the dialogs see it.
 */
class MaxBotMessage {

        /**
         * Chat of the message: the user id of MAX the bot talks to.
         * @var string
         */
        public $chat_id = '';

        /**
         * Id of the message, unique across the chats.
         * @var string
         */
        public $message_id = '';

        /**
         * Text of the message, the caption of a file.
         * @var string
         */
        public $text = '';

        /**
         * File attached to the message.
         * @var MaxBotFile|null
         */
        public $file = NULL;

        /**
         * The message this one replies to. For the message carrying the buttons
         * of a dialog it is the message of the user the dialog was started from.
         * @var MaxBotMessage|null
         */
        public $reply_to = NULL;
}
