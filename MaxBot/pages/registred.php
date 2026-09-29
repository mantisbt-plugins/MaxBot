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

auth_ensure_user_authenticated();

# A protected account (a shared or anonymous one) must not get a chat of its own
current_user_ensure_unprotected();

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
$f_token        = gpc_get_string( 'token', '' );
$f_is_confirmed = gpc_get_bool( '_confirmed', FALSE );

# The value travels through the browser and ends up in the database and in a chat id
if( !preg_match( '/^[0-9A-Za-z_.-]{1,64}$/', $f_account_id ) ) {
    error_parameters( 'account_id' );
    trigger_error( ERROR_GPC_VAR_NOT_FOUND, ERROR );
}

# The account id is public, the one-time token issued with the invitation is not:
# only the owner of the chat has the link, so nobody else can get his chat bound to the
# account that opens this page
$t_account_name = maxbot_registration_link_token_check( $f_account_id, $f_token );

if( $t_account_name === false ) {
    plugin_log_event( 'Registration Error! Invalid or expired link token for account ' . $f_account_id . ', opened by user ' . user_get_username( auth_get_current_user_id() ) );
    plugin_error( 'ERROR_REGISTRATION_LINK_INVALID', ERROR );
}

# A refusal kills the link: it may have come from somebody else, and a link left
# alive could still be confirmed by a careless second click
if( '0' === gpc_get_string( '_confirmed', '' ) && 'POST' == $_SERVER['REQUEST_METHOD'] ) {
    maxbot_registration_link_token_burn( $f_account_id );

    plugin_log_event( 'Binding of account ' . $f_account_id . ' is declined by mantisbt user ' . user_get_username( auth_get_current_user_id() ) );

    layout_page_header( plugin_lang_get( 'account_register_page_header' ) );
    layout_page_begin( 'account_page' );

    html_operation_warning( helper_mantis_url( config_get( 'default_home_page' ) ), plugin_lang_get( 'user_relationship_declined' ) );

    layout_page_end();

    return;
}

# Neither the account nor the chat is relinked silently. Checked before the question,
# asking to confirm a binding that is going to be refused would only mislead
maxbot_account_link_ensure_allowed( auth_get_current_user_id(), $f_account_id );

# Both sides of the binding are shown apart and the warning stands out: the page is
# the last chance to notice a link sent by somebody else
$t_max_account  = is_blank( $t_account_name ) ? '' : '<strong>' . string_html_specialchars( $t_account_name ) . '</strong><br>';
$t_max_account .= '<span class="grey">ID ' . string_html_specialchars( $f_account_id ) . '</span>';

maxbot_registration_ensure_confirmed(
                          '<h4 class="bold">' . plugin_lang_get( 'user_relationship_confirm_title' ) . '</h4>'
                          . '<table class="table table-bordered table-condensed" style="width: auto; margin: 10px auto;">'
                          . '<tr><th class="category">' . plugin_lang_get( 'user_relationship_confirm_max' ) . '</th>'
                          . '<td class="left">' . $t_max_account . '</td></tr>'
                          . '<tr><th class="category">' . plugin_lang_get( 'user_relationship_confirm_mantis' ) . '</th>'
                          . '<td class="left"><strong>' . string_html_specialchars( user_get_name( auth_get_current_user_id() ) ) . '</strong></td></tr>'
                          . '</table>'
                          . '<p>' . plugin_lang_get( 'user_relationship_confirm_effect' ) . '</p>'
                          . '<p class="red bold">' . plugin_lang_get( 'user_relationship_confirm_warning' ) . '</p>'
);

if( $f_is_confirmed ) {
    # The token is printed by the confirmation form: a cross-site request has no way
    # to obtain it, so a forged confirmation cannot bind a foreign chat to the session
    form_security_validate( 'plugin_MaxBot_registred' );

    if( 'POST' != $_SERVER['REQUEST_METHOD'] ) {
        access_denied();
    }

    # One use only, whatever comes next: a link that has been confirmed once must not
    # work again, even when the binding is refused below
    maxbot_registration_link_token_burn( $f_account_id );
}

layout_page_header_begin();
layout_page_header_end();
layout_page_begin( 'account_page' );

if( $f_is_confirmed ) {

    form_security_purge( 'plugin_MaxBot_registred' );

    $t_current_user_id = auth_get_current_user_id();

    maxbot_account_link( $t_current_user_id, $f_account_id );

    plugin_log_event( 'Account ' . $f_account_id . ' is mapped to mantisbt user ' . user_get_username( $t_current_user_id ) . ' by link' );

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
