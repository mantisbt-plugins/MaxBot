<?php
# Copyright (c) 2024 Grigoriy Ermolaev (igflocal@gmail.com)
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

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

/**
 * Print the row telling when the long polling script of a messenger last ran.
 *
 * The script stamps every start, so a missing or stale value means it is not
 * scheduled - the schedule itself cannot be read from the web.
 *
 * @param integer $p_last_run Time of the last start, 0 for never.
 * @return void
 */
function telegram_monitor_last_run_print( $p_last_run ) {
        echo '<tr>';
        echo '<td class="category" width="50%">';
        echo plugin_lang_get( 'get_updates_last_run' );
        echo '</td>';
        echo '<td colspan="2">';

        if( $p_last_run == 0 ) {
                echo '<span class="red">' . plugin_lang_get( 'get_updates_last_run_never' ) . '</span>';
        } else {
                echo date( config_get( 'normal_date_format' ), $p_last_run );

                if( time() - $p_last_run > 300 ) {
                        echo '<br><span class="small red">' . plugin_lang_get( 'get_updates_last_run_stale' ) . '</span>';
                }
        }

        echo '</td>';
        echo '</tr>';
}

layout_page_header( plugin_lang_get( 'name_plugin_description_page' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_plugin_page.php' );
telegrambot_print_menu_config( 'monitor_page' );
?>

<div class="col-md-12 col-xs-12">
            <div class="space-10"></div>
                <div class="well">
                <p><i class="fa fa-info-circle"></i>
                    <?php echo plugin_lang_get( 'monitor_page_info_receiving_updates_text' ) ?>
                </p>
            </div>
    
            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-telegram"></i>
                        <?php echo string_display_line( messenger_transport( 'tg' )->title() ) ?>
                    </h4>
                </div>

                <div class="widget-body">
                    <div class="widget-main no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-condensed table-hover">
                                <colgroup>
                                    <col style="width:25%" />
                                </colgroup>


                                <?php
                                $t_bot_name = plugin_config_get( 'bot_name' );
                                $t_api_key  = plugin_config_get( 'api_key' );

                                if( $t_bot_name && $t_api_key ) {

                                    try {
                                        $t_result      = messenger_transport( 'tg' )->webhook_info();
                                        $t_webhook_url = $t_result['url'];
                                        $t_pending_update_count = $t_result['pending_update_count'];
                                        $t_last_error_date = $t_result['last_error_date'];
                                        $t_last_error_message = $t_result['last_error_message'];
                                        $t_is_self_signed_cert = $t_result['has_custom_certificate'];
                                    } catch( Exception $t_errors ) {
                                        # network errors (no route to api.telegram.org, timeout, proxy)
                                        $t_webhook_url = $t_errors->getMessage();
                                    }

                                        echo '<tr>';
                                        echo '<td class="category" width="50%">';
                                        echo plugin_lang_get( 'current_config' );
                                        echo '</td>';
                                        echo '<td colspan="2">';
                                        echo is_blank( $t_webhook_url ) ? plugin_lang_get( 'monitor_page_url_not_set' ) : string_display_line( $t_webhook_url );
                                        echo '</td>';
                                        echo '</tr>';
                                        
                                    if( isset( $t_result ) ) {
                                        if(!is_blank( $t_webhook_url )){
                                                echo '<tr>';
                                                echo '<td class="category" width="50%">';
                                                echo plugin_lang_get( 'monitor_page_is_self_signed_cert' );
                                                echo '</td>';
                                                echo '<td colspan="2">';
                                                echo $t_is_self_signed_cert == true ? lang_get('yes') : lang_get('no');
                                                echo '</td>';
                                                echo '</tr>';
                                        
                                                echo '<tr>';
                                                echo '<td class="category" width="50%">';
                                                echo plugin_lang_get( 'monitor_page_last_error_date' );
                                                echo '</td>';
                                                echo '<td colspan="2">';
                                                echo $t_last_error_date !== null ? date( config_get_global( 'normal_date_format' ), $t_last_error_date ) : '';
                                                echo '</td>';
                                                echo '</tr>';
                                                
                                                echo '<tr>';
                                                echo '<td class="category" width="50%">';
                                                echo plugin_lang_get( 'monitor_page_last_error_message' );
                                                echo '</td>';
                                                echo '<td colspan="2">';
                                                echo $t_last_error_message !== null ? string_display_line( $t_last_error_message ) : '';
                                                echo '</td>';
                                                echo '</tr>';
                                        }

                                        echo '<tr>';
                                        echo '<td class="category" width="50%">';
                                        echo plugin_lang_get( 'monitor_page_pending_update_count' );
                                        echo '</td>';
                                        echo '<td colspan="2">';
                                        echo (int)$t_pending_update_count;
                                        echo '</td>';
                                        echo '</tr>';

                                    }

                                    if( (int)plugin_config_get( 'reinstall_webhook' ) == OFF ) {
                                        telegram_monitor_last_run_print( (int)plugin_config_get( 'get_updates_last_run' ) );
                                    }
                                }
                                ?>

                            </table>
                        </div>
                    </div>
                </div>
            </div>
</div>

<?php
# Nothing of MAX is shown, nor asked, while it is switched off
$t_max = ON == (int)plugin_config_get( 'max_enabled' ) ? messenger_transport( 'max' ) : NULL;

if( $t_max !== NULL ) {
?>
<div class="col-md-12 col-xs-12">
            <div class="space-10"></div>
            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-comments"></i>
                        <?php echo string_display_line( $t_max->title() ) ?>
                    </h4>
                </div>

                <div class="widget-body">
                    <div class="widget-main no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-condensed table-hover">
                                <colgroup>
                                    <col style="width:25%" />
                                </colgroup>
                                <?php
                                if( !$t_max->is_enabled() ) {
                                        telegram_config_row_print( $t_max->title(), plugin_lang_get( 'max_monitor_not_configured' ) );
                                } else {
                                        telegram_config_row_print( plugin_lang_get( 'max_bot_name' ), telegram_bot_chat_link_html( 'max' ) );
                                        telegram_max_subscription_rows_print( $t_max );

                                        if( plugin_config_get( 'max_update_method' ) == 'script' ) {
                                                telegram_monitor_last_run_print( (int)plugin_config_get( 'max_get_updates_last_run' ) );
                                        }
                                }
                                ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
</div>
<?php
}
?>

<div class="col-md-12 col-xs-12">
    <div class="space-10"></div>
            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-users"></i>
                        <?php echo plugin_lang_get( 'user_info' ) ?>
                    </h4>
                </div>

                <div class="widget-body">
                    <div class="widget-main no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-condensed table-hover">
                                <colgroup>
                                    <col style="width:25%" />
                                </colgroup>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'account_telegram_prefs_associated_users_head' ) ?>
                                    </th>
                                    <td class="left" colspan="1">

                                        <?php
                                        $t_accounts = telegram_accounts_all_get();

                                        if( empty( $t_accounts ) ) {
                                            echo plugin_lang_get( 'monitor_page_no_associated_users' );
                                        } else {
                                            ?>
                                            <table class="table table-condensed">
                                                <tr>
                                                    <th><?php echo lang_get( 'username' ) ?></th>
                                                    <th><?php echo plugin_lang_get( 'monitor_page_transport' ) ?></th>
                                                    <th><?php echo plugin_lang_get( 'monitor_page_telegram_user_id' ) ?></th>
                                                    <th><?php echo plugin_lang_get( 'monitor_page_telegram_username' ) ?></th>
                                                    <th></th>
                                                </tr>
                                                <?php foreach( $t_accounts as $t_account ) {
                                                    $t_user_id   = $t_account['mantis_user_id'];
                                                    $t_transport = messenger_transport( $t_account['transport'] );

                                                    # The login is not stored anywhere: usernames change on the
                                                    # side of the messenger, so it is asked live and an unreachable
                                                    # API only costs the column, not the page
                                                    $t_telegram_login = $t_transport !== NULL && $t_transport->is_enabled()
                                                            ? $t_transport->account_name( $t_account['account_id'] )
                                                            : '';
                                                    ?>
                                                    <tr>
                                                        <td><?php echo string_display_line( user_get_field( $t_user_id, 'username' ) ) ?></td>
                                                        <td><?php echo string_display_line( $t_transport === NULL ? $t_account['transport'] : $t_transport->title() ) ?></td>
                                                        <td><?php echo string_display_line( $t_account['account_id'] ) ?></td>
                                                        <td><?php echo is_blank( $t_telegram_login ) ? '&#8212;' : string_display_line( $t_telegram_login ) ?></td>
                                                        <td>
                                                            <form method="post" action="<?php echo plugin_page( 'user_unlink' ) ?>">
                                                                <?php echo form_security_field( 'telegram_user_unlink' ) ?>
                                                                <input type="hidden" name="user_id" value="<?php echo $t_user_id ?>" />
                                                                <input type="hidden" name="transport" value="<?php echo string_attribute( $t_account['transport'] ) ?>" />
                                                                <input type="hidden" name="source" value="<?php echo TELEGRAM_UNLINK_SOURCE_ADMIN ?>" />
                                                                <input type="submit" class="btn btn-sm btn-primary btn-white btn-round"
                                                                       value="<?php echo plugin_lang_get( 'user_unlink_button' ) ?>" />
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            </table>
                                            <?php
                                        }
                                        ?>

                                    </td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'monitor_page_pin_locks_head' ) ?>
                                    </th>
                                    <td class="left" colspan="1">

                                        <?php
                                        $t_attempts_max    = max( 1, (int)plugin_config_get( 'pin_code_attempts_max' ) );
                                        $t_attempts_window = telegram_pin_code_attempts_window_get();
                                        $t_lock_rows       = array();

                                        foreach( telegram_pin_code_attempts_all_get() as $t_lock_user_id => $t_lock ) {
                                            $t_window_until = $t_lock['started'] + $t_attempts_window;

                                            # An expired window restricts nothing anymore and needs no reset
                                            if( $t_window_until < db_now() ) {
                                                continue;
                                            }

                                            $t_lock['until']              = $t_window_until;
                                            $t_lock_rows[$t_lock_user_id] = $t_lock;
                                        }

                                        if( empty( $t_lock_rows ) ) {
                                            echo plugin_lang_get( 'monitor_page_pin_locks_none' );
                                        } else {
                                            ?>
                                            <table class="table table-condensed">
                                                <tr>
                                                    <th><?php echo lang_get( 'username' ) ?></th>
                                                    <th><?php echo plugin_lang_get( 'monitor_page_pin_locks_state' ) ?></th>
                                                    <th></th>
                                                </tr>
                                                <?php foreach( $t_lock_rows as $t_lock_user_id => $t_lock ) { ?>
                                                    <tr>
                                                        <td><?php echo user_exists( $t_lock_user_id )
                                                                ? string_display_line( user_get_name( $t_lock_user_id ) )
                                                                : '#' . $t_lock_user_id ?></td>
                                                        <td>
                                                            <?php
                                                            $t_until_text = date( config_get( 'normal_date_format' ), $t_lock['until'] );

                                                            if( $t_lock['count'] >= $t_attempts_max ) {
                                                                echo '<span class="red">' . sprintf( plugin_lang_get( 'monitor_page_pin_lock_locked' ), $t_until_text ) . '</span>';
                                                            } else {
                                                                echo sprintf( plugin_lang_get( 'monitor_page_pin_lock_counting' ), $t_lock['count'], $t_attempts_max, $t_until_text );
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            # Nothing to lift while the limit is not reached: the window
                                                            # expires by itself and the count only documents the guessing
                                                            if( $t_lock['count'] >= $t_attempts_max ) {
                                                            ?>
                                                            <form method="post" action="<?php echo plugin_page( 'pin_lock_reset' ) ?>">
                                                                <?php echo form_security_field( 'plugin_TelegramBot_pin_lock_reset' ) ?>
                                                                <input type="hidden" name="user_id" value="<?php echo $t_lock_user_id ?>" />
                                                                <input type="submit" class="btn btn-sm btn-primary btn-white btn-round"
                                                                       value="<?php echo plugin_lang_get( 'pin_lock_reset_button' ) ?>" />
                                                            </form>
                                                            <?php } ?>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            </table>
                                            <?php
                                        }
                                        ?>

                                    </td>
                                </tr>

                            </table>
                        </div>
                    </div>
                </div>
            </div>
</div>

<?php
layout_page_end();

