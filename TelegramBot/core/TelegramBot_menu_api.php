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

function telegrambot_print_menu_config( $p_page = '' ) {
	$t_pages = array(
				  'config_page',
				  'monitor_page',
                                  'manage_config_message_page',
                                  'broadcast_config_page',
	);

	# the tab belongs to the Calendar plugin: without it nothing reads the settings
	if( plugin_is_registered( 'Calendar' ) ) {
		$t_pages[] = 'calendar_config_page';
	}

	if( access_has_global_level( config_get( 'manage_plugin_threshold' ) ) ) {
		?>
		<div class="col-md-12 col-xs-12">
		    <div class="space-10"></div>
		    <div class="center">
			<div class="btn-toolbar inline">
			    <div class="btn-group">
				<?php
				foreach( $t_pages as $t_page ) {
					$t_active = ( ( $t_page === $p_page ) ? ' active' : '' );
					?>
					<a class="btn btn-sm btn-white btn-primary<?php echo $t_active ?>" href="<?php echo plugin_page( $t_page ) ?>">
					    <?php echo plugin_lang_get( $t_page ) ?>
					</a>

					<?php
				}
				?>

			    </div>
			</div>
		    </div>
		</div>
		<?php
	}
}

/**
 * Print a row of two cells of a settings table.
 *
 * @param string $p_label Label, plain text.
 * @param string $p_value Value, html.
 * @return void
 */
function telegram_config_row_print( $p_label, $p_value ) {
	echo '<tr>';
	echo '<td class="category" width="50%">' . string_display_line( $p_label ) . '</td>';
	echo '<td colspan="2">' . $p_value . '</td>';
	echo '</tr>';
}

/**
 * Print the rows describing the webhook subscription of the MAX bot, asked live.
 *
 * @param MaxBotApi $p_max The MAX client, with the token set.
 * @return void
 */
function telegram_subscription_rows_print( MaxBotApi $p_max ) {
	$t_label = plugin_lang_get( 'max_subscription' );

	try {
		$t_info = $p_max->webhook_info();
	} catch( Exception $t_error ) {
		# the network fails the same way the API does: no route, timeout, proxy
		$t_info = NULL;
	}

	# The long polling gets nothing while a subscription is there, and a webhook
	# without one gets nothing either: both are the state of a deaf bot
	$t_script = plugin_config_get( 'update_method' ) == 'script';

	if( $t_info === NULL ) {
		telegram_config_row_print( $t_label, '<span class="red">' . plugin_lang_get( 'max_subscription_unknown' ) . '</span>' );
		return;
	}

	if( empty( $t_info ) ) {
		telegram_config_row_print( $t_label, $t_script
				? plugin_lang_get( 'max_subscription_none' )
				: '<span class="red">' . plugin_lang_get( 'max_subscription_missing' ) . '</span>' );
		return;
	}

	$t_value = string_display_line( $t_info['url'] );

	if( $t_script ) {
		$t_value .= '<br><span class="small red">' . plugin_lang_get( 'max_subscription_blocks_polling' ) . '</span>';
	}

	telegram_config_row_print( $t_label, $t_value );

	if( (int)$t_info['time'] > 0 ) {
		telegram_config_row_print( plugin_lang_get( 'max_subscription_time' ), date( config_get( 'normal_date_format' ), (int)$t_info['time'] ) );
	}
}

/**
 * Print the row of the address of MantisBT used by the long polling scripts, which
 * run from the command line and have no request to take the address from.
 *
 * @return void
 */
function telegram_config_cli_path_row_print() {
	# $g_defaulted_path is FALSE when $g_path is set in config_inc.php, TRUE when the
	# core derived it from the request headers - in CLI that guess becomes localhost,
	# so it must not be shown as the address the script is going to use.
	global $g_defaulted_path;

	if( $g_defaulted_path ) {
		$t_path_notice      = plugin_lang_get( 'cli_g_path_notice' );
		$t_path_placeholder = '';
	} else {
		$t_path_notice      = plugin_lang_get( 'cli_g_path_global_notice' );
		$t_path_placeholder = config_get_global( 'path' );
	}
	?>
	<tr>
	    <th class="category" width="5%">
		<?php echo ' ' . plugin_lang_get( 'cli_g_path' ) ?>
		<br><span class="small"><?php echo $t_path_notice ?></span>
	    </th>
	    <td class="center" colspan="1">
		<textarea name="cli_g_path" id="cli_g_path" class="form-control" rows="1" placeholder="<?php echo string_attribute( $t_path_placeholder ) ?>"><?php echo string_textarea( plugin_config_get( 'cli_g_path' ) ) ?></textarea>
	    </td>
	</tr>
	<?php
}

/**
 * The url MAX posts the updates of the bot to.
 *
 * @return string
 */
function telegram_webhook_url_get() {
	return config_get_global( 'path' ) . plugin_page( 'hook', TRUE );
}

/**
 * Whether MAX accepts the url for a webhook: HTTPS on the default port only, the
 * port is not to be written in the url.
 *
 * @param string $p_url Url of the webhook.
 * @return boolean
 */
function telegram_webhook_url_is_valid( $p_url ) {
	return 1 === preg_match( '#^https://[^/:?]+/#i', $p_url );
}
