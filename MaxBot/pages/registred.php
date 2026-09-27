<?php

# Copyright (c) 2018 Grigoriy Ermolaev (igflocal@gmail.com)
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

auth_ensure_user_authenticated();

# Links sent before the method was switched must not keep working: with PIN codes
# the binding is confirmed from the chat side only. An old invitation stays in the
# chat forever, so the dead end is explained instead of a bare "access denied"
if( MAXBOT_REGISTRATION_PIN == (int) plugin_config_get( 'registration_method' ) ) {
    layout_page_header( plugin_lang_get( 'account_register_page_header' ) );
    layout_page_begin( 'account_page' );

    echo '<div class="col-md-12 col-xs-12">';
    echo '<div class="space-10"></div>';
    echo '<div class="alert alert-warning center">';
    echo '<p class="bigger-110">' . plugin_lang_get( 'registration_link_disabled' ) . '</p>';
    echo '<p class="bigger-110"><a href="' . plugin_page( 'account_register_page' ) . '">'
    . plugin_lang_get( 'account_register_page_header' ) . '</a></p>';
    echo '</div></div>';

    layout_page_end();

    return;
}

$f_account_id   = gpc_get_string( 'account_id', '' );
$f_is_confirmed = gpc_get_bool( '_confirmed', FALSE );

# The value travels through the browser and ends up in the database and in a chat id
if( !preg_match( '/^[0-9A-Za-z_.-]{1,64}$/', $f_account_id ) ) {
    error_parameters( 'account_id' );
    trigger_error( ERROR_GPC_VAR_NOT_FOUND, ERROR );
}

maxbot_registration_ensure_confirmed( plugin_lang_get( 'user_relationship_question' ) );

# The account id travels through the browser, so a chat already bound to somebody
# else must not be relinked: its owner would end up working in the bot on behalf of the
# account that opened this page. The chat is released by /stop sent from that chat.
if( $f_is_confirmed ) {
    # The token is printed by the confirmation form: a cross-site request has no way
    # to obtain it, so a forged confirmation cannot bind a foreign chat to the session
    form_security_validate( 'plugin_MaxBot_registred' );

    $t_associated_user_id = maxbot_account_user_get( $f_account_id );

    if( $t_associated_user_id != 0 && $t_associated_user_id != auth_get_current_user_id() ) {
        plugin_log_event( 'Registration Error! Account ' . $f_account_id . ' is already mapped to mantisbt user ' . user_get_username( $t_associated_user_id ) );
        plugin_error( 'ERROR_USER_ALREADY_ASSOCIATED', ERROR );
    }
}

layout_page_header_begin();
layout_page_header_end();
layout_page_begin( 'account_page' );

if( $f_is_confirmed ) {

    form_security_purge( 'plugin_MaxBot_registred' );

    $t_current_user_id = auth_get_current_user_id();

    maxbot_account_link( $t_current_user_id, $f_account_id );

    # The accounts are linked: the invitation leaves the chat and the PIN code is dropped
    maxbot_registration_complete( $f_account_id );

    maxbot_send( $f_account_id, array( 'text' => maxbot_message_first_text() ) );

    # Back to the chat with the bot
    $t_bot_chat     = maxbot_bot_chat_get();
    $t_redirect_url = $t_bot_chat === NULL ? '' : $t_bot_chat['url'];
    echo '<div class="col-md-12 col-xs-12">';
    echo '<div class="space-10"></div>';
    echo '<div class="alert alert-success center">';
    echo '<p class="bigger-110">';
    echo "\n" . plugin_lang_get( 'bot_successfully_attached' ) . "\n";
    echo '</p>';
    echo '<p class="bigger-110">';
    echo "\n" . plugin_lang_get( 'info_to_redirect_bot_page' ) . "\n";
    echo '</p>';

    echo '</div></div>';

    if( !is_blank( $t_redirect_url ) ) {
        echo "\t" . '<meta http-equiv="Refresh" content="' . (int)current_user_get_pref( 'redirect_delay' ) . '; URL=' . string_attribute( $t_redirect_url ) . '" />' . "\n";
    }
}

layout_page_end();
