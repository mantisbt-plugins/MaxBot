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
 * An incoming update of MAX: a message, a command or the press of a
 * button, see MaxBotApi::updates_parse().
 */
class MaxBotUpdate {

        # A message of the user: a text, a file, a reply to a message of the bot
        const KIND_MESSAGE     = 'message';
        # A command of the bot: /start, /stop, ...
        const KIND_COMMAND     = 'command';
        # The press of an inline button
        const KIND_CALLBACK    = 'callback';
        # Anything the bot does not handle
        const KIND_UNSUPPORTED = 'unsupported';

        /**
         * One of the KIND_* constants.
         * @var string
         */
        public $kind = self::KIND_UNSUPPORTED;

        /**
         * Account of MAX the update came from, empty when unknown.
         * @var string
         */
        public $account_id = '';

        /**
         * Language code of the account, NULL when MAX gives none.
         * @var string|null
         */
        public $lang = NULL;

        /**
         * The message; for a press of a button the message carrying the button.
         * @var MaxBotMessage|null
         */
        public $message = NULL;

        /**
         * Id of the press of a button, answered by maxbot_answer_callback().
         * @var string
         */
        public $callback_id = '';

        /**
         * Data of the button pressed: JSON of MaxBotActions or a placeholder.
         * @var string
         */
        public $callback_data = '';

        /**
         * Name of the command without the slash.
         * @var string
         */
        public $command = '';

        /**
         * What follows the command, the payload of a deep link.
         * @var string
         */
        public $command_payload = '';
}
