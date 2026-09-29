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

if( !defined( 'MAXBOT_UPDATE_PROCESS_INC_ALLOW' ) ) {
	return;
}

# The updates are processed out of $t_results, a list of MaxBotUpdate built by
# MaxBotApi out of the webhook request or the long polling answer.
#
# An update is processed on its own: a failure of one of them is reported to its
# sender and the batch goes on. The fatal errors of the core are signalled with
# trigger_error() and would stop the process, so they are turned into exceptions
# for the time of the loop.
global $g_maxbot_previous_error_handler;

$g_maxbot_previous_error_handler = set_error_handler( 'maxbot_update_error_handler' );

try {

    foreach ( $t_results as $t_update ) {

        try {

            if( is_blank( (string)$t_update->account_id ) ) {
                //An update carrying nothing to act on is dropped, the batch goes on
                plugin_log_event( 'ERROR! Bad request. The update carries no content.' );
                continue;
            }

            //We check the binding of the MAX account to the current user account mantisbt and if it is not linked,
            //then we issue an invitation to bind and skip processing the current update.
            if( !maxbot_auth_ensure_user_authenticated( $t_update->account_id, $t_update->lang ) ) {
                continue;
            }

            $t_message = $t_update->message;

            switch( $t_update->kind ) {
            //COMMAND
                case MaxBotUpdate::KIND_COMMAND:
                    $t_data = maxbot_command_run( $t_update );
                    maxbot_send( $t_data['chat_id'], $t_data );
                    break;

            //MESSAGE
                case MaxBotUpdate::KIND_MESSAGE:

                    if( $t_message->reply_to === NULL ) {
            //NEW MESSAGE
                        if( $t_message->file !== NULL ) {
                            #A photo has no name at this point, its name checks run on the
                            #file path when the draft or the note picks the file up
                            $t_error_text = maxbot_file_check( $t_message->file->name, $t_message->file->size );

                            if( $t_error_text != '' ) {
                                $t_data = [
                                                          'text'                => $t_error_text,
                                                          'reply_to_message_id' => $t_message->message_id
                                ];
                                maxbot_send( $t_message->chat_id, $t_data );
                                break;
                            }
                        }

                        $t_user_id = auth_get_current_user_id();

                        # The caption of a file is not an answer to a question of a dialog
                        $t_answer_text = $t_message->file === NULL ? $t_message->text : '';

                        # A pending text question of the status change dialog takes the
                        # message first; the card ids are read before the answer is
                        # processed, the submit step drops them along with the draft
                        $t_status_card_chat_id    = plugin_config_get( 'status_change_draft_chat_id', NULL, FALSE, $t_user_id );
                        $t_status_card_message_id = plugin_config_get( 'status_change_draft_message_id', NULL, FALSE, $t_user_id );

                        $t_status_answer = maxbot_status_change_text_answer( $t_answer_text );

                        if( $t_status_answer !== NULL ) {
                            maxbot_delete( $t_message->chat_id, $t_message->message_id );
                            maxbot_edit( $t_status_card_chat_id, $t_status_card_message_id, $t_status_answer );
                            break;
                        }

                        # A pending text question of the calendar event wizard takes the
                        # message next; only one wizard is alive at a time, so the issue
                        # draft below never competes with it for the same message
                        $t_event_card_chat_id    = plugin_config_get( 'event_draft_chat_id', NULL, FALSE, $t_user_id );
                        $t_event_card_message_id = plugin_config_get( 'event_draft_message_id', NULL, FALSE, $t_user_id );

                        $t_event_answer = maxbot_event_draft_text_answer( $t_answer_text );

                        if( $t_event_answer !== NULL ) {
                            maxbot_delete( $t_message->chat_id, $t_message->message_id );
                            maxbot_edit( $t_event_card_chat_id, $t_event_card_message_id, $t_event_answer );
                            break;
                        }

                        $t_bug_data_draft_raw = plugin_config_get( 'bug_data_draft', '', FALSE, $t_user_id );

                        //Create a new draft of the issue
                        if( is_blank( $t_bug_data_draft_raw ) ) {
                            $t_sendMessage_data = maxbot_action_select( $t_message->chat_id, $t_message->message_id );
                            maxbot_send( $t_sendMessage_data['chat_id'], $t_sendMessage_data );
                            break;
                        }
                        //Otherwise, continue to enter data into the current draft
                        $t_bug_data_draft_current_field_to_save = plugin_config_get( 'bug_data_draft_current_field_to_save', '', FALSE, $t_user_id );

                        $t_bug_data_draft = json_decode( $t_bug_data_draft_raw, TRUE );

                        $t_custom_field_id = maxbot_custom_field_pending_id( $t_bug_data_draft_current_field_to_save );
                        $t_error_text      = '';
                        $t_answer_taken    = FALSE;

                        if( $t_custom_field_id > 0 ) {
                            //A rejected value leaves the state of the field untouched, so the same question is asked again
                            maxbot_custom_field_text_set(
                                                      $t_bug_data_draft,
                                                      $t_custom_field_id,
                                                      $t_answer_text,
                                                      $t_error_text
                                    );

                            $t_answer_taken = TRUE;
                        } else if( !is_blank( $t_answer_text ) ) {
                            $t_step = maxbot_draft_text_step_pending( $t_bug_data_draft, $t_bug_data_draft_current_field_to_save );

                            if( $t_step !== NULL ) {
                                $t_bug_data_draft[$t_step] = $t_answer_text;
                                plugin_config_set( 'bug_data_draft', json_encode( $t_bug_data_draft ), $t_user_id );

                                $t_answer_taken = TRUE;
                            }
                        }

                        maxbot_delete( $t_message->chat_id, $t_message->message_id );

                        //A message answering no question of the wizard leaves the draft card as it is
                        if( !$t_answer_taken ) {
                            break;
                        }

                        $t_next = maxbot_draft_ask_next_step( $t_bug_data_draft );

                        if( $t_next['state'] == MAXBOT_DRAFT_NEXT_SUBMIT ) {
                            $t_data_send = maxbot_draft_submit( $t_bug_data_draft );
                        } else {
                            $t_inline_keyboard = $t_next['keyboard'];

                            if( $t_inline_keyboard === NULL ) {
                                $t_inline_keyboard = new MaxBotKeyboard();
                            }

                            maxbot_keyboard_draft_buttons_add( $t_inline_keyboard, $t_bug_data_draft );

                            $t_data_send = [
                                'chat_id'    => plugin_config_get( 'bug_data_draft_chat_id', NULL, FALSE, $t_user_id ),
                                'message_id' => plugin_config_get( 'bug_data_draft_message_id', NULL, FALSE, $t_user_id ),
                            ] + maxbot_card_message( maxbot_draft_card_compose( $t_bug_data_draft, $t_next['suffix'], $t_error_text ), $t_inline_keyboard );
                        }

                        maxbot_edit( $t_data_send['chat_id'], $t_data_send['message_id'], $t_data_send );
                        break;
            //END NEW MESSAGE
                    }

            //REPLY TO MESSAGE
                    $t_bug_id = maxbot_message_link_bug_get( $t_message->reply_to->chat_id, $t_message->reply_to->message_id );

                    $t_data = maxbot_add_comment( array( 'set_bug' => $t_bug_id ), $t_message );

                    $t_data['reply_to_message_id'] = $t_message->message_id;

                    maxbot_send( $t_message->chat_id, $t_data );
                    break;
            //END REPLY TO MESSAGE

            //CALLBACK
                case MaxBotUpdate::KIND_CALLBACK:

                    $t_data = json_decode( $t_update->callback_data, TRUE );

                    //The placeholder buttons (the header of the calendar, the days of the week)
                    //carry no action, such a press is only acknowledged to drop the spinner.
                    //A button of a message gone from the chat has no card to redraw either.
                    if( !is_array( $t_data ) || $t_message === NULL ) {
                        maxbot_answer_callback( $t_update->callback_id );
                        break;
                    }

                    $t_command = array_keys( $t_data );

                    //The card the button sits on is the message every branch below redraws
                    $t_card_chat_id    = $t_message->chat_id;
                    $t_card_message_id = $t_message->message_id;

                    switch( $t_command[0] ) {
                        case MaxBotActions::REPORT_BUG_TAG:
                            $t_data = maxbot_bug_report( $t_data[MaxBotActions::REPORT_BUG_TAG], $t_message );
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, $t_data );
                            break;

                        case MaxBotActions::ADD_COMMENT_TAG:
                            //The note is taken out of the message the menu replied to
                            $t_data = maxbot_add_comment( $t_data[MaxBotActions::ADD_COMMENT_TAG], $t_message->reply_to );
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, $t_data );
                            break;

                        case MaxBotActions::UPDATE_BUG_TAG:
                            $t_data = maxbot_update_bug( $t_data[MaxBotActions::UPDATE_BUG_TAG] );
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, $t_data );
                            break;

                        case MaxBotActions::CHANGE_STATUS_TAG:
                            $t_data = maxbot_change_status( $t_data[MaxBotActions::CHANGE_STATUS_TAG], $t_message );
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, $t_data );
                            break;

                        case MaxBotActions::CREATE_EVENT_TAG:
                            $t_data = maxbot_event_report( $t_data[MaxBotActions::CREATE_EVENT_TAG], $t_message );
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, $t_data );
                            break;

                        case MaxBotActions::EVENT_REPLY_TAG:
                            //Only the buttons are redrawn: the message may be the caption of the .ics file
                            $t_keyboard = maxbot_calendar_reply( $t_data[MaxBotActions::EVENT_REPLY_TAG] );

                            if( $t_keyboard !== NULL ) {
                                maxbot_edit( $t_card_chat_id, $t_card_message_id, array( 'reply_markup' => $t_keyboard ) );
                            }
                            break;

                        case MaxBotActions::STOP_EVENT_TAG:
                            //The draft is dropped by the card driving it only: a press on another
                            //message would take away the draft being filled in somewhere else
                            if( maxbot_event_draft_belongs_to_message( $t_card_message_id, $t_card_chat_id ) ) {
                                maxbot_event_draft_clear();
                            } else {
                                maxbot_callback_alert_set( plugin_lang_get( 'event_draft_other_message' ) );
                            }
                            //And next, change the action selection keyboard
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, maxbot_action_select( $t_card_chat_id, $t_card_message_id ) );
                            break;

                        case MaxBotActions::STOP_CHANGE_STATUS_TAG:
                            maxbot_status_change_draft_clear();
                            //And next, change the action selection keyboard
                        case MaxBotActions::STOP_REPORT_ISSUE_TAG:
                            //The draft is dropped by the card driving it only: a press on another
                            //message would take away the draft being filled in somewhere else
                            if( maxbot_draft_belongs_to_message( $t_card_message_id, $t_card_chat_id ) ) {
                                maxbot_draft_clear();
                            } else if( $t_command[0] == MaxBotActions::STOP_REPORT_ISSUE_TAG ) {
                                //Cancelling a status change touches no draft of its own, only the
                                //user asking for the draft to be removed is told about the refusal
                                maxbot_callback_alert_set( plugin_lang_get( 'draft_other_message' ) );
                            }
                            //And next, change the action selection keyboard
                        case MaxBotActions::ACTION_SELECT_TAG:
                            maxbot_edit( $t_card_chat_id, $t_card_message_id, maxbot_action_select( $t_card_chat_id, $t_card_message_id ) );
                            break;
                    }

                    //A callback query can only be answered once, so it is done after the whole callback is processed
                    maxbot_answer_callback( $t_update->callback_id, maxbot_callback_alert_get() );

                    break;
            //END CALLBACK

                default:
                    plugin_log_event( 'ERROR! Bad request. The content of the update is not implemented.' );

                    $t_chat_id = $t_message === NULL ? (string)$t_update->account_id : $t_message->chat_id;

                    maxbot_send( $t_chat_id, array( 'text' => plugin_lang_get( 'error_content_type' ) ) );
            }

        } catch( Throwable $t_exception ) {
            //The update is given up on, the sender is told about it and the rest of the batch is processed
            plugin_log_event( 'ERROR! ' . get_class( $t_exception ) . ': ' . $t_exception->getMessage()
                    . ' in ' . $t_exception->getFile() . ':' . $t_exception->getLine() );

            maxbot_update_error_notify( $t_update, $t_exception );

            continue;
        }
    }

} finally {
    //The handler belongs to the loop only, the caller keeps the one it had
    restore_error_handler();
}
