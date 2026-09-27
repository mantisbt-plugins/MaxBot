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

layout_page_header( plugin_lang_get( 'name_plugin_description_page' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_plugin_page.php' );
telegrambot_print_menu_config( 'config_page' );
?>

<div class="col-md-12 col-xs-12">
    <div class="space-10"></div>
    
    <div class="well">
        <p><i class="fa fa-info-circle"></i>
            <?php echo plugin_lang_get( 'help_registration_bot_header' ) ?>
        </p>
        <p><?php echo plugin_lang_get( 'help_registration_bot_message' ) ?></p>
    </div>
    
    <div class="form-container">
        <form action="<?php echo plugin_page( 'config' ) ?>" method="post" enctype="multipart/form-data"> 
            <?php echo form_security_field( 'config' ) ?>
            
            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-cubes"></i>
                        <?php echo plugin_lang_get( 'credential_config_title' ) ?>
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
                                        <span class="required">*</span><?php echo ' ' . plugin_lang_get( 'config_api_key' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'config_api_key_notice' ) ?></span>
                                    </th>
                                    <td class="center" colspan="1">
                                        <textarea name="api_key" id="api_key" class="form-control" rows="1" required><?php echo string_textarea( plugin_config_get( 'api_key' ) ) ?></textarea>
                                    </td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'config_bot_name' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'config_bot_name_notice' ) ?></span>
                                    </th>
                                    <td class="left" colspan="1"><?php echo telegram_bot_chat_link_html() ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-10"></div>

            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-cubes"></i>
                        <?php echo plugin_lang_get( 'registration_config_title' ) ?>
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
                                        <?php echo plugin_lang_get( 'registration_method' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'registration_method_notice' ) ?></span>
                                    </th>
                                    <td class="left" colspan="1">
                                        <?php $t_registration_method = (int)plugin_config_get( 'registration_method' ); ?>
                                        <label><input type="radio" class="ace" name="registration_method" value="<?php echo TELEGRAM_REGISTRATION_LINK ?>" <?php echo( TELEGRAM_REGISTRATION_LINK == $t_registration_method ) ? 'checked="checked" ' : '' ?>/>
                                            <span class="lbl padding-6"><?php echo plugin_lang_get( 'registration_method_link' ) ?></span></label>
                                        <label><input type="radio" class="ace" name="registration_method" value="<?php echo TELEGRAM_REGISTRATION_PIN ?>" <?php echo( TELEGRAM_REGISTRATION_PIN == $t_registration_method ) ? 'checked="checked" ' : '' ?>/>
                                            <span class="lbl padding-6"><?php echo plugin_lang_get( 'registration_method_pin' ) ?></span></label>
                                        <label><input type="radio" class="ace" name="registration_method" value="<?php echo TELEGRAM_REGISTRATION_BOTH ?>" <?php echo( TELEGRAM_REGISTRATION_BOTH == $t_registration_method ) ? 'checked="checked" ' : '' ?>/>
                                            <span class="lbl padding-6"><?php echo plugin_lang_get( 'registration_method_both' ) ?></span></label>
                                    </td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'pin_code_attempts_max' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'pin_code_attempts_max_notice' ) ?></span>
                                    </th>
                                    <td class="center" colspan="1">
                                        <input type="number" name="pin_code_attempts_max" id="pin_code_attempts_max" class="form-control" min="1" value="<?php echo (int)plugin_config_get( 'pin_code_attempts_max' ) ?>">
                                    </td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'pin_code_attempts_window' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'pin_code_attempts_window_notice' ) ?></span>
                                    </th>
                                    <td class="center" colspan="1">
                                        <input type="number" name="pin_code_attempts_window" id="pin_code_attempts_window" class="form-control" min="1" value="<?php echo (int)plugin_config_get( 'pin_code_attempts_window' ) ?>">
                                    </td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'admin_unlink_notify' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'admin_unlink_notify_notice' ) ?></span>
                                    </th>
                                    <td class="left" colspan="1">
                                        <label><input type="radio" class="ace" name="admin_unlink_notify" value="1" <?php echo( ON == (int)plugin_config_get( 'admin_unlink_notify' ) ) ? 'checked="checked" ' : '' ?>/>
                                            <span class="lbl padding-6"><?php echo lang_get( 'yes' ) ?></span></label>
                                        <label><input type="radio" class="ace" name="admin_unlink_notify" value="0" <?php echo( OFF == (int)plugin_config_get( 'admin_unlink_notify' ) ) ? 'checked="checked" ' : '' ?>/>
                                            <span class="lbl padding-6"><?php echo lang_get( 'no' ) ?></span></label>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-10"></div>

            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-cubes"></i>
                        <?php echo plugin_lang_get( 'connection_config_title' ) ?>
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
					<?php echo plugin_lang_get( 'time_out_server_response_header' ) ?>
                                    </th>
                                    <td class="center" colspan="1"> 
                                        <input type="number" name="time_out_server_response" id="proxy_address" class="form-control" min="0" value="<?php echo plugin_config_get( 'time_out_server_response' ) ?>">
                                    </td>
                                </tr>
				
				<?php
//				$t_curl_version = curl_version();
//				$t_curl_version = '7.19.7';
				if( function_exists("curl_version") && curl_version()['version'] >= '7.21.7' ) { ?>
					<tr>
	                                    <th class="category" width="5%">
						<?php echo plugin_lang_get( 'proxy_address_header' ) ?>
                                                <br><span class="small"><?php echo 'Current curl version='. curl_version()['version']  ?></span>
	                                    </th>
	                                    <td class="center" colspan="1"> 
	                                        <textarea name="proxy_address" id="proxy_address" class="form-control" rows="1" placeholder="login:password@address:port"><?php echo string_textarea( plugin_config_get( 'proxy_address' ) ) ?></textarea>
	                                    </td>
	                                </tr>
				<?php				
				} else { ?>
						<tr>
						    <th class="category" width="5%">
							<?php echo plugin_lang_get( 'proxy_address_header' ) ?>
						    </th>
						    <td class="center" colspan="1"> 
							<textarea name="proxy_address" id="proxy_address" class="form-control" rows="1" disabled="true"><?php echo plugin_lang_get( 'ERROR_CURL_VERSION' ) ?></textarea>
						    </td>
						</tr>
					<?php
				} ?>
				
				<tr>
                                    <th class="category" width="5%">
					<?php echo plugin_lang_get( 'debug_connection_log_path_title' ) ?>
                                    </th>
                                    <td class="center" colspan="1"> 
                                        <textarea name="debug_connection_log_path" id="debug_connection_log_path" class="form-control" rows="1"><?php echo string_textarea( plugin_config_get( 'debug_connection_log_path' ) ) ?></textarea>
                                    </td>
                                </tr>
								
				<tr>
                                    <th class="category" width="5%">
					<?php echo plugin_lang_get( 'debug_connection_enabled' ) ?>
                                    </th>
                                    <td> 
                                        <label class="inline">
					    <input type="checkbox" class="ace" id="debug_connection_enabled" name="debug_connection_enabled" <?php check_checked( (int)plugin_config_get( 'debug_connection_enabled' ), ON ); ?> />
					    <span class="lbl"></span>
					</label>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-10"></div>
            
            <div class="widget-box widget-color-blue2">
                <div class="widget-header widget-header-small">
                    <h4 class="widget-title lighter">
                        <i class="ace-icon fa fa-cubes"></i>
                        <?php echo plugin_lang_get( 'config_get_update_title' ) ?>
                    </h4>
                </div>
                
                <div class="widget-body">
                    <div class="widget-main no-padding">
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-condensed table-hover">
                                <colgroup>
                                    <col style="width:25%" />
                                </colgroup>
                                
                                <?php $t_script = plugin_config_get( 'update_method' ) == 'script'; ?>
                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'update_method' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'update_method_notice' ) ?></span>
                                    </th>
                                    <td class="left" colspan="1">
                                        <label><input type="radio" class="ace" name="update_method" value="webhook" <?php echo $t_script ? '' : 'checked="checked" ' ?>/>
                                            <span class="lbl padding-6"><?php echo 'Webhook' ?></span></label>
                                        <label><input type="radio" class="ace" name="update_method" value="script" <?php echo $t_script ? 'checked="checked" ' : '' ?>/>
                                            <span class="lbl padding-6"><?php echo 'Script' ?></span></label>
                                    </td>
                                </tr>

                                <?php
                                if( !$t_script ) {
                                    $t_webhook_url = telegram_webhook_url_get();
                                ?>
                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'max_webhook_url' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'max_webhook_requirements' ) ?></span>
                                    </th>
                                    <td class="left" colspan="1">
                                        <?php
                                        echo string_display_line( $t_webhook_url );

                                        if( !telegram_webhook_url_is_valid( $t_webhook_url ) ) {
                                            echo '<br><span class="small red">' . plugin_lang_get( 'max_webhook_url_invalid' ) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php } else { ?>
                                <?php telegram_config_cli_path_row_print() ?>
                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'path_to_crontab_script' ) ?>
                                        <br><span class="small"><?php echo plugin_lang_get( 'path_to_crontab_script_notice' ) ?></span>
                                    </th>
                                    <td class="left" colspan="1"><code><?php echo string_display_line( '* * * * * php ' . dirname( __FILE__, 2 ) . '/scripts/get_updates.php' ) ?></code></td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'get_updates_timeout_header' ) ?>
                                    </th>
                                    <td class="center" colspan="1">
                                        <input type="number" name="get_updates_timeout" id="get_updates_timeout" class="form-control" min="0" max="<?php echo MaxBotApi::POLL_TIMEOUT_MAX ?>" value="<?php echo (int)plugin_config_get( 'get_updates_timeout' ) ?>">
                                    </td>
                                </tr>

                                <tr>
                                    <th class="category" width="5%">
                                        <?php echo plugin_lang_get( 'get_updates_run_time_header' ) ?>
                                    </th>
                                    <td class="center" colspan="1">
                                        <input type="number" name="get_updates_run_time" id="get_updates_run_time" class="form-control" min="0" value="<?php echo (int)plugin_config_get( 'get_updates_run_time' ) ?>">
                                    </td>
                                </tr>
                                <?php
                                }

                                # What MAX itself knows, asked live: only with a token to ask it by
                                if( messenger_api()->is_enabled() ) {
                                    telegram_subscription_rows_print( messenger_api() );
                                }
                                ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-10"></div>

            <div class="widget-box widget-color-blue2">
                    <div class="widget-toolbox center clearfix">
                        <input type="submit" class="btn btn-primary btn-white btn-round" value="<?php echo lang_get('change_configuration') ?>" />
                    </div>

            </div>
            </form>
        </div>
    </div>

<?php
layout_page_end();

