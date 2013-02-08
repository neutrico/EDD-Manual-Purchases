<?php
/*
Plugin Name: Easy Digital Downloads - Manual Purchases
Plugin URI: http://easydigitaldownloads.com/extension/manual-purchases/
Description: Provides an admin interface for manually creating purchase orders in Easy Digital Downloads
Version: 1.1.4
Author: Pippin Williamson
Author URI:  http://pippinsplugins.com
Contributors: mordauk
*/

class EDD_Manual_Purchases {

	private static $instance;

	/**
	 * Get active object instance
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {

		if ( ! self::$instance )
			self::$instance = new EDD_Manual_Purchases();

		return self::$instance;
	}

	/**
	 * Class constructor.  Includes constants, includes and init method.
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		define( 'EDD_MP_STORE_API_URL', 'http://easydigitaldownloads.com' );
		define( 'EDD_MP_PRODUCT_NAME', 'Manual Purchases' );
		define( 'EDD_MP_VERSION', '1.1.4' );

		if( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
			// load our custom updater
			include( dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php' );
		}

		$this->init();

	}


	/**
	 * Run action and filter hooks.
	 *
	 * @since 1.0
	 *
	 * @access private
	 * @return void
	 */
	private function init() {

		if( ! function_exists( 'edd_price' ) )
			return; // EDD not present

		global $edd_options;

		// internationalization
		add_action( 'init', array( $this, 'textdomain' ) );

		// add a crreate payment button to the top of the Payments History page
		add_action( 'edd_payments_page_top' , array( $this, 'create_payment_button' ) );

		// register the Create Payment submenu
		add_action( 'admin_menu', array( $this, 'submenu' ) );

		// check for download price variations via ajax
		add_action( 'wp_ajax_edd_mp_check_for_variations', array( $this, 'check_for_variations' ) );

		// process payment creation
		add_action( 'edd_create_payment', array( $this, 'create_payment' ) );

		// show payment created notice
		add_action( 'admin_notices', array( $this, 'payment_created_notice' ), 1 );

		// register our license key settings
		add_filter( 'edd_settings_misc', array( $this, 'license_settings' ), 1 );

		// activate license key on settings save
		add_action( 'admin_init', array( $this, 'activate_license' ) );

		// auto updater

		// retrieve our license key from the DB
		$edd_mp_license_key = isset( $edd_options['edd_mp_license_key'] ) ? trim( $edd_options['edd_mp_license_key'] ) : '';

		// setup the updater
		$edd_updater = new EDD_SL_Plugin_Updater( EDD_MP_STORE_API_URL, __FILE__, array(
				'version' 	=> EDD_MP_VERSION, 		// current version number
				'license' 	=> $edd_mp_license_key, // license key (used get_option above to retrieve from DB)
				'item_name' => EDD_MP_PRODUCT_NAME, // name of this plugin
				'author' 	=> 'Pippin Williamson'  // author of this plugin
			)
		);

	}

	public static function textdomain() {

		// Set filter for plugin's languages directory
		$lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
		$lang_dir = apply_filters( 'edd_manual_purchases_lang_directory', $lang_dir );

		// Load the translations
		load_plugin_textdomain( 'edd-manual-purchases', false, $lang_dir );

	}

	public static function create_payment_button() {

		?>
		<p id="edd_create_payment_go">
			<a href="<?php echo add_query_arg( 'page', 'edd-manual-purchase', admin_url( 'options.php' ) ); ?>" class="button-secondary"><?php _e('Create Payment', 'edd-manual-purchases'); ?></a>
		</p>
		<?php
	}

	public static function submenu() {
		global $edd_create_payment_page;
		$edd_create_payment_page = add_submenu_page( 'options.php', __('Create Payment', 'edd-manual-purchases'), __('Create Payment', 'edd-manual-purchases'), 'manage_options', 'edd-manual-purchase', array( __CLASS__, 'payment_creation_form' ) );
	}

	public static function payment_creation_form() {
		?>
		<div class="wrap">
			<h2><?php _e('Create New Payment', 'edd-manual-purchases'); ?></h2>
			<script type="text/javascript">
				jQuery(document).ready(function($) {
					// clone a download row
					$('#edd_mp_create_payment').on('click', '.edd-mp-add-download', function() {
						var row = $(this).closest('tr').clone();

						var count = $('tr.edd-mp-download-wrap').size();

						$('select.edd-mp-download-select', row).prop('name', 'downloads[' + count + '][id]');

						$('select.edd-mp-price-select', row).remove();

						if( ! $('.edd-mp-remove', row).length )
							$('.edd-mp-downloads', row).append('<a href="#" class="edd-mp-remove">Remove</a>');

						row.insertAfter( '#edd-mp-table-body tr.edd-mp-download-wrap:last' );
						return false;
					});
					// remove a download row
					$('#edd_mp_create_payment').on('click', '.edd-mp-remove', function() {
						$(this).closest('tr').remove();
						return false;
					});
					// check for variable prices
					$('#edd_mp_create_payment').on('change', '.edd-mp-download-select', function() {
						var $this = $(this);
						var selected_download = $('option:selected', this).val();
						if( parseInt( selected_download ) != 0) {
							var edd_mp_nonce = $('#edd_create_payment_nonce').val();
							var data = {
								action: 'edd_mp_check_for_variations',
								download_id: selected_download,
								key: $('tr.edd-mp-download-wrap').size() - 1,
								nonce: edd_mp_nonce
							}
							$this.parent().find('img').show();
							$.post(ajaxurl, data, function(response) {
								$this.next('select').remove();
								$this.after( response );
								$this.parent().find('img').hide();
							});
						} else {
							$this.next('select').remove();
							$this.parent().find('img').hide();
						}
					});
				});
			</script>
			<form id="edd_mp_create_payment" method="post">
				<table class="form-table">
					<tbody id="edd-mp-table-body">
						<tr class="form-field edd-mp-download-wrap">
							<th scope="row" valign="top">
								<label><?php echo edd_get_label_singular(); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<select name="downloads[0][id]" class="edd-mp-download-select">
									<?php
									$args = array(
										'post_type' => 'download',
										'posts_per_page' => -1,
									);
									$downloads = get_posts( apply_filters( 'edd_mp_downloads_query', $args ) );
									if( $downloads ) {
										echo '<option value="0">' . sprintf( __('Choose %s', 'edd-manual-purchases'), esc_html( edd_get_label_plural() ) ) . '</option>';
										foreach( $downloads as $download ) {
											echo '<option value="' . $download->ID . '">' . esc_html( get_the_title( $download->ID ) ) . '</option>';
										}
									} else {
										echo '<option value="0">' . sprintf( __('No %s created yet', 'edd-manual-purchases'), edd_get_label_plural() ) . '</option>';
									}
									?>
								</select>
								<a href="#" class="edd-mp-add-download"><?php _e('Add another', 'edd-manual-purchases' ); ?></a>
								<img src="<?php echo admin_url('/images/wpspin_light.gif'); ?>" class="waiting edd_mp_loading" style="display:none;"/>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-user"><?php _e('Buyer', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-user">
								<input type="text" class="small-text" id="edd-mp-user" name="user" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the user ID or email of the buyer', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row" valign="top">
								<label for="edd-mp-amount"><?php _e('Amount', 'edd-manual-purchases'); ?></label>
							</th>
							<td class="edd-mp-downloads">
								<input type="text" class="small-text" id="edd-mp-amount" name="amount" style="width: 180px;"/>
								<div class="description"><?php _e('Enter the total purchase amount, or leave blank to auto calculate price based on the selected items above. Use 0.00 for 0.', 'edd-manual-purchases'); ?></div>
							</td>
						</tr>
					</tbody>
				</table>
				<?php wp_nonce_field( 'edd_create_payment_nonce', 'edd_create_payment_nonce' ); ?>
				<input type="hidden" name="edd-gateway" value="manual_purchases"/>
				<input type="hidden" name="edd-action" value="create_payment" />
				<?php submit_button(__('Create Payment', 'edd-manual-purchases') ); ?>
			</form>
		</div>
		<?php
	}

	public static function check_for_variations() {

		if( isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'edd_create_payment_nonce') ) {

			$download_id = $_POST['download_id'];

			if(edd_has_variable_prices($download_id)) {

				$prices = get_post_meta($download_id, 'edd_variable_prices', true);
				$response = '';
				if( $prices ) {
					$response = '<select name="downloads[' . $_POST['key'] . '][options][price_id]" class="edd-mp-price-select">';
						foreach( $prices as $key => $price ) {
							$response .= '<option value="' . $key . '">' . $price['name']  . '</option>';
						}
					$response .= '</select>';
				}
				echo $response;
			}
			die();
		}
	}

	public static function create_payment( $data ) {

		if( wp_verify_nonce( $data['edd_create_payment_nonce'], 'edd_create_payment_nonce' ) ) {

			global $edd_options;

			//echo '<pre>'; print_r( $data ); echo '</pre>'; exit;

			$user = strip_tags( trim( $data['user'] ) );

			if( is_numeric( $user ) )
				$user = get_userdata( $user );
			elseif ( is_email( $user ) )
				$user = get_user_by( 'email', $user );
			elseif ( is_string( $user ) )
				$user = get_user_by( 'login', $user );
			else
				return; // no user assigned

			$user_id 	= $user ? $user->ID : 0;
			$email 		= $user ? $user->user_email : strip_tags( trim( $data['user'] ) );
			$user_first	= $user ? $user->first_name : '';
			$user_last	= $user ? $user->last_name : '';


			$user_info = array(
				'id' 			=> $user_id,
				'email' 		=> $email,
				'first_name'	=> $user_first,
				'last_name'		=> $user_last,
				'discount'		=> 'none'
			);

			$price = ( isset( $data['amount'] ) && $data['amount'] !== false ) ? strip_tags( trim( $data['amount'] ) ) : false;

			$cart_details = array();

			$total = 0;
			foreach( $data['downloads'] as $key => $download ) {

				// calculate total purchase cost

				if( isset( $download['options'] ) ) {

					$prices 	= get_post_meta( $download['id'], 'edd_variable_prices', true );
					$key 		= $download['options']['price_id'];
					$item_price = $prices[$key]['amount'];

				} else {
					$item_price = edd_get_download_price( $download['id'] );
				}

				$cart_details[$key] = array(
					'name' 			=> get_the_title( $download['id'] ),
					'id' 			=> $download['id'],
					'item_number' 	=> $key,
					'price' 		=> $price ? 0 : $item_price,
					'quantity' 		=> 1,
				);
				$total += $item_price;

			}

			// assign total to the price given, if any
			if( $price ) {
				$total = $price;
			}

			$purchase_data 		= array(
				'price' 		=> number_format( (float) $total, 2 ),
				'date' 			=> date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
				'purchase_key'	=> strtolower( md5( uniqid() ) ), // random key
				'user_email'	=> $email,
				'user_info' 	=> $user_info,
				'currency'		=> $edd_options['currency'],
				'downloads'		=> $data['downloads'],
				'cart_details' 	=> $cart_details,
				'status'		=> 'pending' // start with pending so we can call the update function, which logs all stats
			);

			$payment_id = edd_insert_payment( $purchase_data );

			// increase stats and log earnings
			edd_update_payment_status( $payment_id, 'complete' ) ;

			wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-payment-history&edd-message=payment_created' ) ); exit;

		}
	}

	public static function payment_created_notice() {
		if( isset($_GET['edd-message'] ) && $_GET['edd-message'] == 'payment_created' && current_user_can( 'view_shop_reports' ) ) {
			add_settings_error( 'edd-notices', 'edd-payment-created', __('The payment has been created.', 'edd-manual-purchases'), 'updated' );
		}
	}

	public static function license_settings( $settings ) {
		$license_settings = array(
			array(
				'id' => 'edd_mp_license_header',
				'name' => '<strong>' . __('Manual Purchases', 'edd-manual-purchases') . '</strong>',
				'desc' => '',
				'type' => 'header',
				'size' => 'regular'
			),
			array(
				'id' => 'edd_mp_license_key',
				'name' => __('License Key', 'edd-manual-purchases'),
				'desc' => __('Enter your license for Manual Purchasing to receive automatic upgrades', 'edd-manual-purchases'),
				'type' => 'text',
				'size' => 'regular'
			)
		);

		return array_merge( $settings, $license_settings );
	}

	public static function activate_license() {
		global $edd_options;
		if( ! isset( $_POST['edd_settings_misc'] ) )
			return;
		if( ! isset( $_POST['edd_settings_misc']['edd_mp_license_key'] ) )
			return;

		if( get_option( 'edd_mp_license_active' ) == 'valid' )
			return;

		$license = sanitize_text_field( $_POST['edd_settings_misc']['edd_mp_license_key'] );

		// data to send in our API request
		$api_params = array(
			'edd_action'=> 'activate_license',
			'license' 	=> $license,
			'item_name' => urlencode( EDD_MP_PRODUCT_NAME ) // the name of our product in EDD
		);

		// Call the custom API.
		$response = wp_remote_get( add_query_arg( $api_params, EDD_MP_STORE_API_URL ), array( 'timeout' => 15, 'body' => $api_params, 'sslverify' => false ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		update_option( 'edd_mp_license_active', $license_data->license );

	}

}

$GLOBALS['edd_manual_purchases'] = new EDD_Manual_Purchases();