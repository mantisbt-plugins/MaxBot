<?php
# Copyright (c) 2019 Grigoriy Ermolaev (igflocal@gmail.com)
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


$f_token = gpc_get_string( 'token', '' );

$t_api_key = (string)plugin_config_get( 'api_key' );

# hash_equals: a strict constant-time comparison, the loose one is open to type
# juggling and leaks the position of the first wrong byte through the timing
if( is_blank( $f_token ) || is_blank( $t_api_key ) || !hash_equals( $t_api_key, $f_token ) ) {
    plugin_log_event( 'ERROR! Wrong api key.' );
    exit();
}

# The webhook of Telegram, the token in the url proves the request comes from it.
# A messenger of its own posts to a page of its own and hands the body to its
# transport the same way.
$t_results = messenger_transport( 'tg' )->updates_parse( file_get_contents( 'php://input' ) );

define( 'UPDATE_PROCESS_INC_ALLOW', true );
include( dirname( __FILE__ ) . '/update_process_inc.php' );