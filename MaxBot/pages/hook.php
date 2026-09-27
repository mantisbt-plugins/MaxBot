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
 * Webhook of the MAX bot: MAX posts a single update per request and proves the
 * request with the secret of the subscription in the X-Max-Bot-Api-Secret header.
 */

$t_max = maxbot_api();

# Without a token the updates still coming are dropped: an answer other than 200
# only makes MAX send them again for hours
if( !$t_max->is_enabled() ) {
    plugin_log_event( 'MAX update dropped: the token of the bot is not set.' );
    exit();
}

$t_secret = isset( $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ) ? (string)$_SERVER['HTTP_X_MAX_BOT_API_SECRET'] : '';

if( !$t_max->secret_is_valid( $t_secret ) ) {
    plugin_log_event( 'ERROR! Wrong secret of the MAX webhook.' );
    http_response_code( 403 );
    exit();
}

$t_results = $t_max->updates_parse( file_get_contents( 'php://input' ) );

# MAX takes an answer later than 30 seconds for a failure and sends the update
# again: the answer goes out first where the server allows it, the update is
# processed after it
ignore_user_abort( true );

if( function_exists( 'fastcgi_finish_request' ) ) {
    fastcgi_finish_request();
}

define( 'MAXBOT_UPDATE_PROCESS_INC_ALLOW', true );
include( dirname( __FILE__ ) . '/update_process_inc.php' );
