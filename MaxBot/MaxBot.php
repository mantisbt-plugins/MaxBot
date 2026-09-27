<?php

# Copyright (c) 2024 Grigoriy Ermolaev (igflocal@gmail.com)
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

# Ways to link a MAX account to a MantisBT one, see the 'registration_method' config option.
# The link carries the MAX user id to the registred page, the PIN code goes the other way
# round - the bot shows it in the chat and the user types it in his account preferences, which
# is the only method that works when MantisBT is not reachable from the user's phone.
define( 'MAXBOT_REGISTRATION_LINK', 0 );
define( 'MAXBOT_REGISTRATION_PIN', 1 );
define( 'MAXBOT_REGISTRATION_BOTH', 2 );

# Whether the iCalendar file of a calendar event goes along with the notifications about
# the event, see the 'calendar_ics_mode' config option: never, for everybody unless the
# user turns it off in his preferences, or for nobody unless the user turns it on there.
define( 'MAXBOT_ICS_OFF', 0 );
define( 'MAXBOT_ICS_ON', 1 );
define( 'MAXBOT_ICS_OPT_IN', 2 );

# Where the unlink button was pressed: the account page of the user himself or the
# plugin pages, where it is an administrative action even for one's own binding
define( 'MAXBOT_UNLINK_SOURCE_ACCOUNT', 'account' );
define( 'MAXBOT_UNLINK_SOURCE_ADMIN', 'admin' );

# Seconds a PIN code stays valid
define( 'MAXBOT_PIN_CODE_TTL', 15 * 60 );

# Seconds the registration state is kept: the PIN code inside it expires much earlier,
# but the id of the invitation is still needed to remove that message from the chat when
# the user follows the link later.
define( 'MAXBOT_REGISTRATION_STATE_TTL', 48 * 60 * 60 );

class MaxBotPlugin extends MantisPlugin {

    function register() {

        $this->name        = 'MaxBot';
        $this->description = plugin_lang_get( 'description' );

        $this->version  = '1.0.0-dev';
        $this->requires = array(
                                  'MantisCore' => '2.26.0',
        );

        $this->author  = 'Grigoriy Ermolaev';
        $this->contact = 'igflocal@gmail.com';
        $this->url     = 'https://github.com/brlumen/MaxBot';
        $this->page    = 'config_page';
    }

    function schema() {
        /**
         * Standard table creation options
         * Array key is the ADOdb datadict driver's name
         */
        $t_table_options = array(
                                  'mysql' => 'DEFAULT CHARSET=utf8',
                                  'pgsql' => 'WITHOUT OIDS',
        );

        # Special handling for Oracle (oci8):
        # - Field cannot be null with oci because empty string equals NULL
        # - Oci uses a different date literal syntax
        # - Default BLOBs to empty_blob() function
        if( db_is_oracle() ) {
            $t_notnull      = '';
            $t_blob_default = 'DEFAULT " empty_blob() "';
        } else {
            $t_notnull      = 'NOTNULL';
            $t_blob_default = '';
        }

        return array(
                                  // version 1.0.0 (schema 0)
                                  // The MAX account of a MantisBT user: one per user, and an account
                                  // belongs to one user. The ids of MAX are kept as strings.
                                  array( 'CreateTableSQL', array( plugin_table( 'account' ), "
                                      mantis_user_id    I       UNSIGNED    NOTNULL     PRIMARY,
                                      account_id        C(64)   $t_notnull  DEFAULT \" '' \"",
                                                                                      $t_table_options
                                                            ) ),
                                  // version 1.0.0 (schema 1)
                                  array( 'CreateIndexSQL', array( 'idx_account_id', plugin_table( 'account' ), 'account_id', array( 'UNIQUE' ) ) ),
                                  // version 1.0.0 (schema 2)
                                  // The messages about the issues sent to the chats, a reply to one
                                  // of them becomes a note. MAX names a message by a string ( "mid.…" ).
                                  array( 'CreateTableSQL', array( plugin_table( 'message_link' ), "
                                      id                I       $t_notnull  AUTOINCREMENT   PRIMARY,
                                      bug_id            I       UNSIGNED    $t_notnull,
                                      chat_id           C(64)   $t_notnull  DEFAULT \" '' \",
                                      message_id        C(64)   $t_notnull  DEFAULT \" '' \"",
                                                                                      $t_table_options
                                                            ) ),
                                  // version 1.0.0 (schema 3)
                                  array( 'CreateIndexSQL', array( 'idx_message_link_chat', plugin_table( 'message_link' ), array( 'chat_id', 'message_id' ) ) ),
                                  // version 1.0.0 (schema 4)
                                  // The registrations in progress, one per MAX account: the PIN code
                                  // issued to it and the id of the invitation the bot has sent.
                                  array( 'CreateTableSQL', array( plugin_table( 'registration' ), "
                                      account_id        C(64)   NOTNULL     PRIMARY,
                                      pin_code          I       UNSIGNED    $t_notnull,
                                      timestamp         I       UNSIGNED    $t_notnull DEFAULT '1',
                                      message_id        C(64)   $t_notnull  DEFAULT \" '' \"",
                                                                                      $t_table_options
                                                            ) ),
                                  // version 1.0.0 (schema 5)
                                  // Unique: a code must identify exactly one MAX account
                                  array( 'CreateIndexSQL', array( 'idx_registration_pin_code', plugin_table( 'registration' ), 'pin_code', array( 'UNIQUE' ) ) ),
        );
    }

    # Latched decision of upgrade(): the schema config grows as the steps run,
    # so whether this request is an install or an upgrade is decided once,
    # on the first call
    private $backup_confirmed = null;

    # Called by plugin_upgrade() before every schema step. A schema upgrade is
    # one-way: rolling the plugin files back does not roll the tables back, so
    # before the first step runs the administrator must confirm that a database
    # backup has been made. Modeled on helper_ensure_confirmed(): the form
    # re-posts the same upgrade request with _confirmed=1 (the form security
    # token is only purged after plugin_upgrade() finishes), so on confirm this
    # method is entered again and falls through. The checkbox is enforced
    # server-side; the CSS gate on the button is a courtesy (the CSP forbids
    # inline JS but allows inline styles). A fresh install (schema -1) has no
    # data to lose and CLI runs have no one to ask.
    function upgrade( $p_schema ) {
        if( $this->backup_confirmed === null ) {
            $this->backup_confirmed = php_sapi_name() == 'cli'
                    || (int)plugin_config_get( 'schema', -1 ) < 0
                    || ( gpc_get_bool( '_confirmed' ) && gpc_get_bool( 'backup_confirmed' ) );
        }
        if( $this->backup_confirmed ) {
            return true;
        }

        layout_page_header();
        layout_page_begin();

        echo '<div class="col-md-12 col-xs-12">';
        echo '<div class="space-10"></div>';
        echo '<div class="alert alert-warning center">';
        echo '<p class="bigger-110"><strong>' . plugin_lang_get( 'upgrade_backup_warning' ) . '</strong></p>';
        echo '<p>' . plugin_lang_get( 'upgrade_backup_explanation' ) . '</p>';
        echo '<div class="space-10"></div>';

        echo '<style>'
                . '#backup_confirmed:not(:checked) ~ input[type="submit"] { pointer-events: none; opacity: .45; }'
                . '</style>';

        echo '<form method="post" class="center" action="">' . "\n";
        # CSRF protection not required here - user needs to confirm action
        # before the form is accepted.
        $t_post = $_POST;
        $t_get  = $_GET;
        unset( $t_post['_confirmed'], $t_post['backup_confirmed'],
                $t_get['_confirmed'], $t_get['backup_confirmed'] );
        print_hidden_inputs( $t_post );
        print_hidden_inputs( $t_get );

        echo '<input type="hidden" name="_confirmed" value="1" />', "\n";
        echo '<input type="checkbox" id="backup_confirmed" name="backup_confirmed" value="1" /> ';
        echo '<label for="backup_confirmed" class="bold">' . plugin_lang_get( 'upgrade_backup_checkbox' ) . '</label>';
        echo '<div class="space-10"></div>';
        echo '<input type="submit" class="btn btn-primary btn-white btn-round" value="' . plugin_lang_get( 'upgrade_confirm_button' ) . '" />';
        echo "\n</form>\n";

        echo '<div class="space-10"></div>';
        echo '</div></div>';

        layout_page_end();
        exit;
    }

    function init() {
        require_once 'api/vendor/autoload.php';
        require_once 'core/MaxBot_bug_api.php';
        require_once 'core/MaxBot_authentication_api.php';
        require_once 'core/MaxBot_chat_api.php';
        require_once 'core/MaxBot_user_api.php';
        require_once 'core/MaxBot_helper_api.php';
        require_once 'core/MaxBot_keyboard_api.php';
        require_once 'core/MaxBot_message_api.php';
        require_once 'core/MaxBot_message_format_api.php';
        require_once 'core/MaxBot_menu_api.php';
        require_once 'core/MaxBot_InlineKeyboardCalendar_api.php';
        require_once 'core/classes/MaxBotActions.class.php';
        require_once 'core/classes/MaxBotKeyboard.class.php';
        require_once 'core/classes/MaxBotFile.class.php';
        require_once 'core/classes/MaxBotMessage.class.php';
        require_once 'core/classes/MaxBotUpdate.class.php';
        require_once 'core/classes/MaxBotApi.class.php';
        require_once 'core/classes/MaxBotFileLogger.class.php';
        require_once 'core/MaxBot_custom_field_api.php';
        require_once 'core/MaxBot_broadcast_api.php';
        require_once 'core/MaxBot_calendar_api.php';

        global $g_maxbot_skip_sending_bugnote, $g_maxbot_callback_alert;
        $g_maxbot_skip_sending_bugnote = FALSE;
        $g_maxbot_callback_alert       = '';
    }

    function config() {
        return array(
                                  # token of the MAX bot
                                  'api_key'                                     => '',
                                  # nickname of the bot, taken from GET /me when the token is saved
                                  'bot_name'                                    => '',
                                  # how the updates are received: 'webhook' or 'script', the two
                                  # exclude each other - MAX gives nothing to the long polling while
                                  # a webhook subscription exists
                                  'update_method'                               => 'webhook',
                                  # secret of the webhook subscription, made by MaxBotApi::webhook_set()
                                  'webhook_secret'                              => '',
                                  # how a MAX account is linked to a MantisBT one:
                                  # MAXBOT_REGISTRATION_LINK / _PIN / _BOTH
                                  'registration_method'                         => MAXBOT_REGISTRATION_LINK,
                                  # whether the chat is told about an unlink done by an administrator
                                  'admin_unlink_notify'                         => ON,
                                  'download_path'                               => '/tmp/',
                                  'proxy_address'                               => '',
                                  'time_out_server_response'                    => 30,
                                  'debug_connection_log_path'                   => '/tmp/MaxBot_debug.log',
                                  'debug_connection_enabled'                    => OFF,
                                  # long polling: seconds MAX holds the connection while there are no
                                  # updates, 90 at most and less than time_out_server_response
                                  'get_updates_timeout'                         => 30,
                                  # long polling: seconds a single run of get_updates.php works (0 - poll once and exit)
                                  'get_updates_run_time'                        => 55,
                                  # long polling: timestamp of the last get_updates.php start, set by the script itself
                                  'get_updates_last_run'                        => 0,
                                  # long polling: marker of the next expected update as MAX gave it,
                                  # kept by the script between runs
                                  'get_updates_marker'                          => '',
                                  # per-user state of the issue wizard; the *_chat_id keys of the
                                  # dialogs hold the chat of the card, the user id of MAX
                                  'bug_data_draft'                              => '',
                                  'bug_data_draft_chat_id'                      => '',
                                  'bug_data_draft_message_id'                   => '',
                                  'bug_data_draft_current_field_to_save'        => '',
                                  # master switch of the Calendar integration, folded into
                                  # maxbot_calendar_available(): off, the plugin behaves as if
                                  # Calendar were not installed
                                  'calendar_integration_enabled'                => OFF,
                                  # whether the .ics file goes along with the event notifications:
                                  # MAXBOT_ICS_OFF / _ON / _OPT_IN. The personal choice is the
                                  # per-user 'calendar_ics_attach' option, whose default is derived
                                  # from the mode, see maxbot_calendar_ics_wanted()
                                  'calendar_ics_mode'                           => MAXBOT_ICS_OFF,
                                  # who is told about a calendar event, per action: the author of
                                  # the event, its members, and the user who acts - the counterpart
                                  # of notify_flags below for the events, in the shape of the matrix
                                  # of the Calendar plugin itself. Overridden per project on the
                                  # notifications page, see maxbot_calendar_notify_flags()
                                  'calendar_notify_flags'                       => array(
                                                            'created'        => array( 'author' => ON, 'members' => ON, 'actor' => OFF ),
                                                            'updated'        => array( 'author' => ON, 'members' => ON, 'actor' => OFF ),
                                                            'deleted'        => array( 'author' => ON, 'members' => ON, 'actor' => OFF ),
                                                            # the user joining or leaving is told on their own,
                                                            # these rows name the others told about it
                                                            'member_added'   => array( 'author' => OFF, 'members' => OFF, 'actor' => OFF ),
                                                            'member_removed' => array( 'author' => OFF, 'members' => OFF, 'actor' => OFF ),
                                                            'rsvp'           => array( 'author' => ON, 'members' => OFF, 'actor' => OFF ),
                                  ),
                                  # whether the reminders of Calendar are repeated in MAX at
                                  # all; the reminders have no matrix row, their recipients are
                                  # chosen by Calendar, so this is the only global switch of them
                                  'calendar_reminders_enabled'                  => ON,
                                  # per-user switches of the calendar event notifications, the
                                  # counterpart of message_on_* below
                                  'message_on_event_created'           => ON,
                                  'message_on_event_updated'           => ON,
                                  'message_on_event_deleted'           => ON,
                                  'message_on_event_reminder'          => ON,
                                  # per-user state of the calendar event wizard, see MaxBot_calendar_api.php
                                  'event_draft'                                 => '',
                                  'event_draft_chat_id'                         => '',
                                  'event_draft_message_id'                      => '',
                                  'event_draft_current_field'                   => '',
                                  # per-user "count:window_start" of wrong PIN code guesses
                                  'pin_code_attempts'                           => '',
                                  # wrong PIN code guesses allowed within one lockout window:
                                  # a 4-digit code is only a secret while the guesses are counted
                                  'pin_code_attempts_max'                       => 5,
                                  # minutes the lockout window lasts, counted from the first wrong guess
                                  'pin_code_attempts_window'                    => 15,
                                  'cli_g_path'                                  => '',
                                  'broadcast_enabled'                           => OFF,
                                  'broadcast_send_threshold'                    => ADMINISTRATOR,
                                  # per-user broadcast permissions: array( user_id => array( project_id, ... ) )
                                  'broadcast_grants'                            => array(),
                                  /**
                                   * The following two config options allow you to control who should get email
                                   * notifications on different actions/statuses.  The first option
                                   * (default_notify_flags) sets the default values for different user
                                   * categories.  The user categories are:
                                   *
                                   *      'reporter': the reporter of the bug
                                   *       'handler': the handler of the bug
                                   *       'monitor': users who are monitoring a bug
                                   *      'bugnotes': users who have added a bugnote to the bug
                                   *      'category': category owners
                                   *      'explicit': users who are explicitly specified by the code based on the
                                   *                  action (e.g. user added to monitor list).
                                   * 'threshold_max': all users with access <= max
                                   * 'threshold_min': ..and with access >= min
                                   *
                                   * The second config option (notify_flags) sets overrides for specific
                                   * actions/statuses. If a user category is not listed for an action, the
                                   * default from the config option above is used.  The possible actions are:
                                   *
                                   *             'new': a new bug has been added
                                   *           'owner': a bug has been assigned to a new owner
                                   *        'reopened': a bug has been reopened
                                   *         'deleted': a bug has been deleted
                                   *         'updated': a bug has been updated
                                   *         'bugnote': a bugnote has been added to a bug
                                   *         'sponsor': sponsorship has changed on this bug
                                   *        'relation': a relationship has changed on this bug
                                   *         'monitor': an issue is monitored.
                                   *        '<status>': eg: 'resolved', 'closed', 'feedback', 'acknowledged', etc.
                                   *                     this list corresponds to $g_status_enum_string
                                   *
                                   * If you wanted to have all developers get notified of new bugs you might add
                                   * the following lines to your config file:
                                   *
                                   * $g_notify_flags['new']['threshold_min'] = DEVELOPER;
                                   * $g_notify_flags['new']['threshold_max'] = DEVELOPER;
                                   *
                                   * You might want to do something similar so all managers are notified when a
                                   * bug is closed.  If you did not want reporters to be notified when a bug is
                                   * closed (only when it is resolved) you would use:
                                   *
                                   * $g_notify_flags['closed']['reporter'] = OFF;
                                   *
                                   * @global array $g_default_notify_flags
                                   */
                                  'default_notify_flags'                      => array(
                                                            'reporter'      => ON,
                                                            'handler'       => ON,
                                                            'monitor'       => ON,
                                                            'bugnotes'      => ON,
                                                            'category'      => ON,
                                                            'explicit'      => ON,
                                                            'threshold_min' => NOBODY,
                                                            'threshold_max' => NOBODY
                                  ),
                                  /**
                                   * We don't need to send these notifications on new bugs
                                   * (see above for info on this config option)
                                   * @todo (though I'm not sure they need to be turned off anymore
                                   *      - there just won't be anyone in those categories)
                                   *      I guess it serves as an example and a placeholder for this
                                   *      config option
                                   * @see $g_default_notify_flags
                                   * @global array $g_notify_flags
                                   */
                                  'notify_flags'                              => array(
                                                            'new'     => array(
                                                                                      'bugnotes' => OFF,
                                                                                      'monitor'  => OFF
                                                            ),
                                                            'monitor' => array(
                                                                                      'reporter'      => OFF,
                                                                                      'handler'       => OFF,
                                                                                      'monitor'       => OFF,
                                                                                      'bugnotes'      => OFF,
                                                                                      'explicit'      => ON,
                                                                                      'threshold_min' => NOBODY,
                                                                                      'threshold_max' => NOBODY
                                                            )
                                  ),
                                  /**
                                   * Whether user's should receive emails for their own actions
                                   * @global integer $g_email_receive_own
                                   */
                                  'message_receive_own'              => OFF,
                                  //
                                  'message_on_new'                   => ON,
                                  'message_on_assigned'              => ON,
                                  'message_on_feedback'              => ON,
                                  'message_on_resolved'              => ON,
                                  'message_on_closed'                => ON,
                                  'message_on_reopened'              => ON,
                                  'message_on_bugnote'               => ON,
                                  'message_on_status'                => OFF,
                                  'message_on_priority'              => OFF,
                                  //
                                  'message_on_priority_min_severity' => 0,
                                  'message_on_status_min_severity'   => 0,
                                  'message_on_bugnote_min_severity'  => 0,
                                  'message_on_reopened_min_severity' => 0,
                                  'message_on_closed_min_severity'   => 0,
                                  'message_on_resolved_min_severity' => 0,
                                  'message_on_feedback_min_severity' => 0,
                                  'message_on_assigned_min_severity' => 0,
                                  'message_on_new_min_severity'      => 0,
                                  //
                                  'message_bugnote_limit'            => 0,
                                  /**
                                   * Allow the notifications in MAX.
                                   * Set to ON to enable the notifications, OFF to disable them. Note that
                                   * disabling the notifications has no effect on the messages generated as part
                                   * of the user signup process. When set to OFF, the password reset feature
                                   * is disabled. Additionally, notifications of administrators updating
                                   * accounts are not sent to users.
                                   * @global integer $g_enable_email_notification
                                   */
                                  'enable_notification'      => ON,
                                  //
                                  'message_separator1'               => str_pad( '', 27, '=' ),
                                  'message_separator2'               => str_pad( '', 55, '-' ),
                                  'message_padding_length'           => 13,
                                  /**
                                   * When enabled, the email notifications will send the full issue with
                                   * a hint about the change type at the top, rather than using dedicated
                                   * notifications that are focused on what changed.  This change can be
                                   * overridden in the database per user.
                                   *
                                   * @global integer $g_email_notifications_verbose
                                   */
                                  'message_notifications_verbose'    => OFF,
                                  'message_included_all_bugnote_is'  => OFF,
        );
    }

    public function hooks() {
        $t_hooks = array(
                                  'EVENT_REPORT_BUG'      => 'message_bug_added',
                                  'EVENT_BUGNOTE_ADD'     => 'message_bugnote_add',
                                  'EVENT_UPDATE_BUG_DATA' => 'message_skip_sending',
                                  'EVENT_UPDATE_BUG'      => 'message_update_bug',
                                  'EVENT_MENU_ACCOUNT'    => 'maxbot_account_page_menu',
                                  'EVENT_MANAGE_USER_DELETE' => 'maxbot_user_deleted',
                                  'EVENT_MENU_MAIN_FRONT' => 'menu_main_front',
        );

        # The EVENT_CALENDAR_EVENT_* events belong to the Calendar plugin, and
        # hooking an event nobody has declared raises a warning. The order the
        # plugins are initialized in is not defined, so Calendar may still be
        # waiting for its turn while this runs and its events may not be declared
        # yet; the plugins are all registered before any of them is initialized,
        # though, so the presence of Calendar itself is a reliable test.
        # The events are declared here as well for the case this plugin comes
        # first: event_declare() keeps the declaration made first and the type
        # below is the one Calendar declares, so the declarations cannot disagree.
        # Without Calendar nothing ever signals the events and the callbacks
        # simply never run, which is why no dependency on Calendar is needed.
        if( plugin_is_registered( 'Calendar' ) ) {
            event_declare( 'EVENT_CALENDAR_EVENT_CREATED', EVENT_TYPE_EXECUTE );
            event_declare( 'EVENT_CALENDAR_EVENT_UPDATED', EVENT_TYPE_EXECUTE );
            event_declare( 'EVENT_CALENDAR_EVENT_DELETED', EVENT_TYPE_EXECUTE );
            event_declare( 'EVENT_CALENDAR_EVENT_REMINDER', EVENT_TYPE_EXECUTE );
            event_declare( 'EVENT_CALENDAR_EVENT_MEMBER_ADDED', EVENT_TYPE_EXECUTE );
            event_declare( 'EVENT_CALENDAR_EVENT_MEMBER_REMOVED', EVENT_TYPE_EXECUTE );
            event_declare( 'EVENT_CALENDAR_EVENT_RSVP', EVENT_TYPE_EXECUTE );

            $t_hooks['EVENT_CALENDAR_EVENT_CREATED']        = 'maxbot_calendar_event_created';
            $t_hooks['EVENT_CALENDAR_EVENT_UPDATED']        = 'maxbot_calendar_event_updated';
            $t_hooks['EVENT_CALENDAR_EVENT_DELETED']        = 'maxbot_calendar_event_deleted';
            $t_hooks['EVENT_CALENDAR_EVENT_REMINDER']       = 'maxbot_calendar_event_reminder';
            $t_hooks['EVENT_CALENDAR_EVENT_MEMBER_ADDED']   = 'maxbot_calendar_event_member_added';
            $t_hooks['EVENT_CALENDAR_EVENT_MEMBER_REMOVED'] = 'maxbot_calendar_event_member_removed';
            $t_hooks['EVENT_CALENDAR_EVENT_RSVP']           = 'maxbot_calendar_event_rsvp';
        }

        return $t_hooks;
    }
    
    public function errors() {
        return array(
                                  'ERROR_PIN_CODE_INVALID'        => plugin_lang_get( 'ERROR_PIN_CODE_INVALID' ),
                                  'ERROR_PIN_CODE_ATTEMPTS'       => plugin_lang_get( 'ERROR_PIN_CODE_ATTEMPTS' ),
                                  'ERROR_PIN_CODE_EXPIRED'        => plugin_lang_get( 'ERROR_PIN_CODE_EXPIRED' ),
                                  'ERROR_PIN_CODE_GENERATE'       => plugin_lang_get( 'ERROR_PIN_CODE_GENERATE' ),
                                  'ERROR_USER_ALREADY_ASSOCIATED' => plugin_lang_get( 'ERROR_USER_ALREADY_ASSOCIATED' ),
        );
    }

    /**
     * Notify the circle of a calendar event about its creation.
     *
     * EVENT_CALENDAR_EVENT_CREATED is declared as EVENT_TYPE_EXECUTE and signalled
     * with a single parameter, so the callback receives the name of the event and
     * the identifier of the calendar event created.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the created calendar event.
     * @return void
     */
    function maxbot_calendar_event_created( $p_type_event, $p_event_id ) {
        plugin_log_event( sprintf( 'Calendar event #%d created', $p_event_id ) );
        maxbot_calendar_message_event( $p_event_id, 'created' );
    }

    /**
     * Notify the circle of a calendar event about a change of it.
     *
     * EVENT_CALENDAR_EVENT_UPDATED is declared as EVENT_TYPE_EXECUTE and signalled
     * with a single parameter, so the callback receives the name of the event and
     * the identifier of the calendar event changed.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the changed calendar event.
     * @return void
     */
    function maxbot_calendar_event_updated( $p_type_event, $p_event_id ) {
        plugin_log_event( sprintf( 'Calendar event #%d updated', $p_event_id ) );
        maxbot_calendar_message_event( $p_event_id, 'updated' );
    }

    /**
     * Notify the circle of a calendar event about its deletion.
     *
     * EVENT_CALENDAR_EVENT_DELETED is signalled before the rows of the event are
     * removed and only when the whole event goes, so the callback still finds
     * the event and its members.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the calendar event being deleted.
     * @return void
     */
    function maxbot_calendar_event_deleted( $p_type_event, $p_event_id ) {
        plugin_log_event( sprintf( 'Calendar event #%d deleted', $p_event_id ) );
        maxbot_calendar_message_event( $p_event_id, 'deleted' );
    }

    /**
     * Remind one user about an occurrence of a calendar event coming up.
     *
     * EVENT_CALENDAR_EVENT_REMINDER is signalled by the reminder dispatcher of
     * Calendar once per due reminder, that is once per occurrence, recipient and
     * offset, and only for the users who may hear about the event and have not
     * opted out of the reminders, so nothing is filtered here.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the calendar event.
     * @param integer $p_occurrence Timestamp the occurrence starts at.
     * @param integer $p_user_id    Recipient of the reminder.
     * @param integer $p_offset     Seconds before the start the reminder was asked for.
     * @return void
     */
    function maxbot_calendar_event_reminder( $p_type_event, $p_event_id, $p_occurrence, $p_user_id, $p_offset ) {
        maxbot_calendar_message_reminder( $p_event_id, $p_occurrence, $p_user_id, $p_offset );
    }

    /**
     * Notify about a user added to the members of an existing calendar event.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the calendar event.
     * @param integer $p_user_id    User added.
     * @param integer $p_actor_id   User who added them.
     * @return void
     */
    function maxbot_calendar_event_member_added( $p_type_event, $p_event_id, $p_user_id, $p_actor_id ) {
        plugin_log_event( sprintf( 'Calendar event #%d, member @U%d added', $p_event_id, $p_user_id ) );
        maxbot_calendar_message_member( $p_event_id, $p_user_id, 'member_added', $p_actor_id );
    }

    /**
     * Notify about a user removed from the members of an existing calendar event.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the calendar event.
     * @param integer $p_user_id    User removed.
     * @param integer $p_actor_id   User who removed them.
     * @return void
     */
    function maxbot_calendar_event_member_removed( $p_type_event, $p_event_id, $p_user_id, $p_actor_id ) {
        plugin_log_event( sprintf( 'Calendar event #%d, member @U%d removed', $p_event_id, $p_user_id ) );
        maxbot_calendar_message_member( $p_event_id, $p_user_id, 'member_removed', $p_actor_id );
    }

    /**
     * Notify about a member replying whether they will take part in a calendar event.
     *
     * EVENT_CALENDAR_EVENT_RSVP is signalled for a changed reply only, on every
     * path recording one - the pages of Calendar and the buttons of this bot.
     *
     * @param string  $p_type_event Name of the signalled event.
     * @param integer $p_event_id   Identifier of the calendar event.
     * @param integer $p_user_id    Member who replied.
     * @param integer $p_status     The reply, one of the CALENDAR_RSVP_* constants.
     * @return void
     */
    function maxbot_calendar_event_rsvp( $p_type_event, $p_event_id, $p_user_id, $p_status ) {
        plugin_log_event( sprintf( 'Calendar event #%d, reply of @U%d: %d', $p_event_id, $p_user_id, $p_status ) );
        maxbot_calendar_message_rsvp( $p_event_id, $p_user_id, $p_status );
    }

    function message_bug_added( $p_type_event, $p_issue, $p_issue_id ) {
        plugin_log_event( sprintf( 'Issue #%d reported', $p_issue_id ) );
        maxbot_message_generic( $p_issue_id, 'new', 'message_notification_title_for_action_bug_submitted' );
    }

    function message_bugnote_add( $p_type_event, $p_bug_id, $p_bugnote_id, $files ) {
        global $g_maxbot_skip_sending_bugnote;

        if( $g_maxbot_skip_sending_bugnote == TRUE ) {
            $g_maxbot_skip_sending_bugnote = FALSE;
            return;
        }

        $t_bugnote_text = bugnote_get_text( $p_bugnote_id );

        # Process the mentions that have access to the issue note
        $t_mentioned_user_ids          = mention_get_users( $t_bugnote_text );
        $t_filtered_mentioned_user_ids = access_has_bugnote_level_filter(
                config_get( 'view_bug_threshold' ), $p_bugnote_id, $t_mentioned_user_ids );

        $t_removed_mentions_user_ids = array_diff( $t_mentioned_user_ids, $t_filtered_mentioned_user_ids );

        $t_user_ids_that_got_mention_notifications = maxbot_message_user_mention( $p_bug_id, $t_filtered_mentioned_user_ids, $t_bugnote_text, $t_removed_mentions_user_ids );

        maxbot_message_bugnote_add_generic( $p_bugnote_id, array(), $t_user_ids_that_got_mention_notifications );
    }

    function message_skip_sending( $p_type_event, $p_updated_bug, $p_existing_bug ) {
        global $g_maxbot_skip_sending_bugnote;
        $g_maxbot_skip_sending_bugnote = TRUE;

        return $p_updated_bug;
    }

    function message_update_bug( $p_type_event, $p_existing_bug, $p_updated_bug ) {

        # Determine whether the new status will reopen, resolve or close the issue.
        # Note that multiple resolved or closed states can exist and thus we need to
        # look at a range of statuses when performing this check.
        $t_resolved_status = config_get( 'bug_resolved_status_threshold' );
        $t_closed_status   = config_get( 'bug_closed_status_threshold' );
        $t_resolve_issue   = false;
        $t_close_issue     = false;
        $t_reopen_issue    = false;
        if( $p_existing_bug->status < $t_resolved_status &&
                $p_updated_bug->status >= $t_resolved_status &&
                $p_updated_bug->status < $t_closed_status
        ) {
            $t_resolve_issue = true;
        } else if( $p_existing_bug->status < $t_closed_status &&
                $p_updated_bug->status >= $t_closed_status
        ) {
            $t_close_issue = true;
        } else if( $p_existing_bug->status >= $t_resolved_status &&
                $p_updated_bug->status <= config_get( 'bug_reopen_status' )
        ) {
            $t_reopen_issue = true;
        }

        # Send a notification of changes via email.
        if( $t_resolve_issue ) {
            plugin_log_event( sprintf( 'Issue #%d resolved', $p_existing_bug->id ) );
            maxbot_message_generic( $p_existing_bug->id, 'resolved', 'message_notification_title_for_status_bug_resolved' );
            maxbot_message_relationship_child_resolved( $p_existing_bug->id );
        } else if( $t_close_issue ) {
            plugin_log_event( sprintf( 'Issue #%d closed', $p_existing_bug->id ) );
            maxbot_message_generic( $p_existing_bug->id, 'closed', 'message_notification_title_for_status_bug_closed' );
            maxbot_message_relationship_child_closed( $p_existing_bug->id );
        } else if( $t_reopen_issue ) {
            plugin_log_event( sprintf( 'Issue #%d reopened', $p_existing_bug->id ) );
            maxbot_message_generic( $p_existing_bug->id, 'reopened', 'message_notification_title_for_action_bug_reopened' );
        } else if( $p_existing_bug->handler_id != $p_updated_bug->handler_id ) {
            maxbot_message_owner_changed( $p_existing_bug->id, $p_existing_bug->handler_id, $p_updated_bug->handler_id );
        } else if( $p_existing_bug->status != $p_updated_bug->status ) {
            $t_new_status_label = MantisEnum::getLabel( config_get( 'status_enum_string' ), $p_updated_bug->status );
            $t_new_status_label = str_replace( ' ', '_', $t_new_status_label );
            plugin_log_event( sprintf( 'Issue #%d status changed', $p_existing_bug->id ) );
            maxbot_message_generic( $p_existing_bug->id, $t_new_status_label, 'message_notification_title_for_status_bug_' . $t_new_status_label );
        } else {
            plugin_log_event( sprintf( 'Issue #%d updated', $p_existing_bug->id ) );
            maxbot_message_generic( $p_existing_bug->id, 'updated', 'message_notification_title_for_action_bug_updated' );
        }
    }

    /**
     * The core removes profiles, preferences and access levels of a deleted user, but
     * neither the binding of this plugin nor its per user configuration options - the
     * chat would stay in the list of connected users with no account behind it.
     *
     * @param string  $p_type_event Event name.
     * @param integer $p_user_id    Id of the user being deleted.
     * @return void
     */
    function maxbot_user_deleted( $p_type_event, $p_user_id ) {
        # No message to the chat: the account is gone, so an invitation to subscribe
        # again would lead nowhere, and deleting a user must not wait for MAX
        maxbot_user_unlink( $p_user_id, /* notify */ FALSE );
        maxbot_user_config_delete_all( $p_user_id );
    }

    function maxbot_account_page_menu( $p_type_event ) {

        # One <li> per returned link, the core marks the active one by the page name
        $t_items = array(
                                  '<a href=' . plugin_page( 'account_prefs_page' ) . '>' . plugin_lang_get( 'account_prefs_page_header' ) . '</a>',
        );

        # Entering a PIN code only makes sense while the account is not linked yet
        if( MAXBOT_REGISTRATION_LINK != (int)plugin_config_get( 'registration_method' )
                && !maxbot_user_is_linked( auth_get_current_user_id() )
        ) {
            $t_items[] = '<a href=' . plugin_page( 'account_register_page' ) . '>' . plugin_lang_get( 'account_register_page_header' ) . '</a>';
        }

        return $t_items;
    }
    
    function menu_main_front() {
        if( !auth_is_user_authenticated() || !maxbot_broadcast_can_send( auth_get_current_user_id() ) ) {
            return array();
        }

        return array(
                                  array(
                                                            'url'          => plugin_page( 'broadcast_message_page' ),
                                                            'title'        => plugin_lang_get( 'menu_main_broadcast_message_page' ),
                                                            # visibility is already decided by maxbot_broadcast_can_send()
                                                            'access_level' => ANYBODY,
                                                            'icon'         => 'fa-bullhorn'
                                  ),
        );
    }
}
