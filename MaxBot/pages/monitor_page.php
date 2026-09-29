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

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

/**
 * Print the row telling when the long polling script last ran.
 *
 * The script stamps every start, so a missing or stale value means it is not
 * scheduled - the schedule itself cannot be read from the web.
 *
 * @param integer $p_last_run Time of the last start, 0 for never.
 * @return void
 */
function maxbot_monitor_last_run_print( $p_last_run ) {
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
maxbot_print_menu_config( 'monitor_page' );
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
                        <i class="ace-icon fa fa-comments"></i>
                        <?php echo 'MAX' ?>
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
                                $t_max = maxbot_api();

                                if( !$t_max->is_enabled() ) {
                                        maxbot_config_row_print( 'MAX', plugin_lang_get( 'monitor_not_configured' ) );
                                } else {
                                        maxbot_config_row_print( plugin_lang_get( 'config_bot_name' ), maxbot_bot_chat_link_html() );
                                        maxbot_subscription_rows_print( $t_max );

                                        if( plugin_config_get( 'update_method' ) == 'script' ) {
                                                maxbot_monitor_last_run_print( (int)plugin_config_get( 'get_updates_last_run' ) );
                                        }
                                }
                                ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
</div>

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
                                        <?php echo plugin_lang_get( 'account_prefs_associated_users_head' ) ?>
                                    </th>
                                    <td class="left" colspan="1">

                                        <?php
                                        $t_accounts = maxbot_accounts_all_get();

                                        if( empty( $t_accounts ) ) {
                                            echo plugin_lang_get( 'monitor_page_no_associated_users' );
                                        } else {
                                            ?>
                                            <table class="table table-condensed">
                                                <tr>
                                                    <th><?php echo lang_get( 'username' ) ?></th>
                                                    <th><?php echo plugin_lang_get( 'account_id' ) ?></th>
                                                    <th></th>
                                                </tr>
                                                <?php foreach( $t_accounts as $t_account ) {
                                                    $t_user_id = $t_account['mantis_user_id'];
                                                    ?>
                                                    <tr>
                                                        <td><?php echo string_display_line( user_get_field( $t_user_id, 'username' ) ) ?></td>
                                                        <td><?php echo string_display_line( $t_account['account_id'] ) ?></td>
                                                        <td>
                                                            <form method="post" action="<?php echo plugin_page( 'user_unlink' ) ?>">
                                                                <?php echo form_security_field( 'plugin_MaxBot_user_unlink' ) ?>
                                                                <input type="hidden" name="user_id" value="<?php echo $t_user_id ?>" />
                                                                <input type="hidden" name="source" value="<?php echo MAXBOT_UNLINK_SOURCE_ADMIN ?>" />
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
                                        $t_attempts_window = maxbot_pin_code_attempts_window_get();
                                        $t_lock_rows       = array();

                                        foreach( maxbot_pin_code_attempts_all_get() as $t_lock_user_id => $t_lock ) {
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
                                                                <?php echo form_security_field( 'plugin_MaxBot_pin_lock_reset' ) ?>
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

