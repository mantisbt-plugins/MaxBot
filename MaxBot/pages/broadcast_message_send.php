<?php
# Copyright (c) 2023 Grigoriy Ermolaev (igflocal@gmail.com)
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


form_security_validate( 'plugin_MaxBot_broadcast_message_send' );

auth_ensure_user_authenticated();

if( !maxbot_broadcast_can_send( auth_get_current_user_id() ) ) {
	access_denied();
}

$f_project_list = gpc_get_int_array( 'projects', array() );

# Every selected project must be granted to the sender.
$t_allowed_project_ids = maxbot_broadcast_allowed_project_ids( auth_get_current_user_id() );
foreach( $f_project_list as $t_project_id ) {
	if( !in_array( (int)$t_project_id, $t_allowed_project_ids ) ) {
		access_denied();
	}
}
$f_message      = trim( gpc_get_string( 'message', '' ) );
$f_files        = gpc_get_file( 'ufile', array() );

# Keep only successfully uploaded files.
$t_files = array();
if( is_array( $f_files ) && isset( $f_files['name'] ) ) {
	foreach( helper_array_transpose( $f_files ) as $t_file ) {
		if( isset( $t_file['error'] ) && $t_file['error'] == UPLOAD_ERR_OK && !is_blank( $t_file['name'] ) ) {
			$t_files[] = $t_file;
		}
	}
}

if( empty( $f_project_list ) ) {
	error_parameters( plugin_lang_get( 'broadcast_projects' ) );
	trigger_error( ERROR_EMPTY_FIELD, ERROR );
}

if( is_blank( $f_message ) && empty( $t_files ) ) {
	error_parameters( plugin_lang_get( 'broadcast_message' ) );
	trigger_error( ERROR_EMPTY_FIELD, ERROR );
}

# Collect recipients from the selected projects, deduplicated by MantisBT user id.
# project_get_all_user_rows() returns enabled users only.
$t_mantis_user_list = array();
foreach( $f_project_list as $t_project_id ) {
	foreach( project_get_all_user_rows( $t_project_id ) as $t_user_row ) {
		$t_mantis_user_list[(int)$t_user_row['id']] = $t_user_row;
	}
}

# The files go to the chats under their original names, the content is read once
foreach( $t_files as $t_key => $t_file ) {
	$t_content = is_uploaded_file( $t_file['tmp_name'] ) ? file_get_contents( $t_file['tmp_name'] ) : false;
	if( $t_content === false ) {
		trigger_error( ERROR_FILE_INVALID_UPLOAD_PATH, ERROR );
	}
	$t_files[$t_key]['name']    = basename( $t_file['name'] );
	$t_files[$t_key]['content'] = $t_content;
}

$t_count_linked   = 0;
$t_count_sent     = 0;
$t_count_no_link  = 0;

$t_sender_name = user_get_name( auth_get_current_user_id() );

foreach( $t_mantis_user_list as $t_user ) {
	$t_addresses = maxbot_user_chats( $t_user['id'] );
	if( empty( $t_addresses ) ) {
		$t_count_no_link++;
		continue;
	}
	$t_count_linked++;

	$t_user_ok = true;

	# Compose the message with a broadcast header in the recipient's language.
	lang_push( user_pref_get_language( $t_user['id'] ) );
	$t_text = sprintf( plugin_lang_get( 'broadcast_header' ), $t_sender_name );
	lang_pop();
	if( !is_blank( $f_message ) ) {
		$t_text .= "\n\n" . $f_message;
	}

	# A file is uploaded to MAX once and the same upload goes to the next recipients
	foreach( $t_addresses as $t_address ) {
		$t_user_ok = !empty( maxbot_send( $t_address, array( 'text' => $t_text ) ) ) && $t_user_ok;

		foreach( $t_files as $t_file ) {
			if( empty( maxbot_send_document( $t_address, $t_file['name'], $t_file['content'] ) ) ) {
				$t_user_ok = false;
				plugin_log_event( 'ERROR! Broadcast file ' . $t_file['name'] . ' did not reach ' . $t_address );
			}
		}
	}

	if( $t_user_ok ) {
		$t_count_sent++;
	}
}


plugin_log_event( sprintf( 'Broadcast message sent by user %d: %d of %d linked users, %d users without MAX link',
	auth_get_current_user_id(), $t_count_sent, $t_count_linked, $t_count_no_link ) );

form_security_purge( 'plugin_MaxBot_broadcast_message_send' );

$t_redirect_url = plugin_page( 'broadcast_message_page', true );
layout_page_header();
layout_page_begin();

html_operation_successful( $t_redirect_url,
	sprintf( plugin_lang_get( 'broadcast_sent' ), $t_count_sent, $t_count_linked, $t_count_no_link ) );

layout_page_end();
