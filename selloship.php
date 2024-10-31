<?php
/*
    Plugin Name: SELLOSHIP
    Description:
    Version: 1.5.16
    Author: SELLOSHIP
    Author URI:
    Copyright: SELLOSHIP
    Text Domain: selloship-woocommerce
    WC requires at least: 3.0.0
    WC tested up to: 3.8.0
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Common Classes.
 */
if (!class_exists("SelloShip_Common")) {
    require_once 'class-selloship-common.php';
}


register_activation_hook(__FILE__, function () {
    $woocommerce_status = SelloShip_Common::woocommerce_active_check(); // True if woocommerce is active.
    if ($woocommerce_status === false) {
        deactivate_plugins(basename(__FILE__));
        wp_die(__("Oops! You tried installing the plugin without activating woocommerce. Please install and activate woocommerce and then try again .", "selloship-woocommerce"), "", array('back_link' => 1));
    }
});

add_action('init', 'selloship_autosync_event_init');
function selloship_autosync_event_init() {
	if (! wp_next_scheduled ( 'selloship_autosync_event' )) {
		wp_schedule_event(time(), 'hourly', 'selloship_autosync_event');
    }
	
	if( isset( $_GET['selloship_sync'] ) ){
		$selloship_vendor_id = get_option('selloship_vendor_id');
		$arr = array('success'=>0,'message'=>'');
		if( empty($_REQUEST['order_id']) ){
			$arr['message'] = 'Order Id missing';
		}else if( empty($_REQUEST['vendor_id']) ){
			$arr['message'] = 'Vendor Id missing';
		}else if( $selloship_vendor_id!=$_REQUEST['vendor_id'] ){
			$arr['message'] = 'Vendor Id is not match';
		}else{
			$order_id = $_REQUEST['order_id'];
			$order = wc_get_order( $order_id );
			if ( !$order ) {
				$arr['message'] = 'Order not exists';
			}else{
				update_post_meta($order_id, '_sello_ship_tracking_url', $_REQUEST['tracking_url']);
				$arr['success'] = 1;
				$arr['message'] = 'Tracking Url Updated Successfully';
			}
		}
		wp_send_json($arr);
	}
}

add_action('selloship_autosync_event', 'selloship_autosync_event_func');
function selloship_autosync_event_func() {
	$selloship_cron_enable = get_option('selloship_cron_enable');
	if( $selloship_cron_enable=='yes' ){
		$args = array(
			'post_type' => 'shop_order',
			'post_status' => 'wc-processing',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_query' => array(
				array(
					'key'     => '_selloship_order_id',
					'compare' => 'NOT EXISTS',
			   ),
			),
		);
		$processing_orders = get_posts( $args );
		foreach( $processing_orders as $order_id ){
			$obj = new SelloShip_Woocommerce_Shipping();
			$obj->send_order_to_selloship_callback_cron($order_id);
		}		
		update_option('selloShip_cron',time());
	}
}

register_uninstall_hook(__FILE__, 'selloship_woocommerce_uninstall');

/**
 * Turn of selloship when uninstalled
 */
function selloship_woocommerce_uninstall()
{
    update_option('selloship_enable', 'no');
}

/**
 * SelloShip root directory path.
 */
if (!defined('SELLOSHIP_PLUGIN_ROOT_DIR')) {
    define('SELLOSHIP_PLUGIN_ROOT_DIR', __DIR__);
}

/**
 * SelloShip root file.
 */
if (!defined('SELLOSHIP_PLUGIN_ROOT_FILE')) {
    define('SELLOSHIP_PLUGIN_ROOT_FILE', __FILE__);
}

/**
 * SelloShip account register api.
 */
if (!defined("SELLOSHIP_WC_ACCOUNT_REGISTER_ENDPOINT")) {
    define("SELLOSHIP_WC_ACCOUNT_REGISTER_ENDPOINT", "https://selloship.com/api/lock_actvs/Vendor_login");
}

/**
 * SelloShip token generate api
 */
if (!defined("SELLOSHIP_WC_ACCOUNT_ACCESS_TOKEN_ENDPOINT")) {
    define("SELLOSHIP_WC_ACCOUNT_ACCESS_TOKEN_ENDPOINT", "https://selloship.com/api/lock_actvs/Generate_vendor_token");
}

/**
 * SelloShip create order api.
 */
if (!defined("SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT"))
{
    define("SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT", "https://selloship.com/web_api/create_order");
}

/**
 * WooCommerce Selloship.
 */
if (!class_exists("SelloShip_Woocommerce_Shipping")) {
    /**
     * Shipping Calculator Class.
     */
    class SelloShip_Woocommerce_Shipping
    {

        /**
         * Constructor
         */
        public function __construct()
        {
            // Handle links on plugin page
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'selloship_plugin_action_links'));
            // Initialize the shipping method
            add_action('woocommerce_shipping_init', array($this, 'selloship_woocommerce_shipping_init'));

            add_action('admin_enqueue_scripts', array($this, 'selloship_add_scripts'));

            add_filter('manage_edit-shop_order_columns', array($this, 'custom_shop_order_column'), 99);
			add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'custom_shop_order_column'), 99);
            add_action('manage_shop_order_posts_custom_column', array($this, 'custom_orders_list_column_content'), 99, 2);
			add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'custom_orders_list_column_content2'), 99, 2);

            add_action('admin_footer', array($this, 'ak_custom_scripts'));

            add_action('wp_ajax_send_order_to_selloship', array($this, 'send_order_to_selloship_callback'));

            add_action('wp_ajax_track_order_with_selloship', array($this, 'track_order_with_selloship_callback'));

            // Add meta box
            add_action('add_meta_boxes', array($this, 'selloship_order_box'));

            add_filter('bulk_actions-edit-shop_order', array($this, 'selloship_bulk_actions_edit_product'), 20, 1);
            add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'selloship_bulk_actions_edit_product'), 20, 1);

            add_filter('handle_bulk_actions-edit-shop_order', array($this, 'selloship_bulk_actions_edit_product_callback'), 10, 3);
            add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'selloship_bulk_actions_edit_product_callback'), 10, 3);

            add_action('admin_notices', array($this, 'selloship_bulk_action_admin_notice'));
        }		
		
        function selloship_order_box()
        {
            add_meta_box(
                'selloship-order-box',
                'Selloship order Id',
                array($this, 'selloship_order_box_callback'),
                'shop_order',
                'normal',
                'default'
            );
        }

        // Callback
        function selloship_order_box_callback($post)
        {
            $sentOrders = get_post_meta($post->ID, '_selloship_order_id', true);
            ?>
            <table width="100%" border="1" cellpadding="5">
                <tr>
                    <th>Name</th>
                    <th>Order Id</th>
                </tr>
                <?php
                if (!empty($sentOrders)) {
                    foreach ($sentOrders as $order) {
                        ?>
                        <tr>
                            <td><?php echo $order['name']; ?></td>
                            <td><?php echo $order['order_id']; ?></td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </table>
            <?php
        }

        // Adding to admin order list bulk dropdown a custom action 'custom_downloads'
        function selloship_bulk_actions_edit_product($actions)
        {
            $selloship_vendor_id = get_option('selloship_vendor_id');
            $isSelloShipEnabled = get_option('selloship_enable', 'no');
            if ($isSelloShipEnabled == 'yes' && $selloship_vendor_id != '') {
                $actions['selloship_orders'] = __('Ship to Selloship', 'woocommerce');
            }
            return $actions;
        }

        // Make the action from selected orders
        function selloship_bulk_actions_edit_product_callback($redirect_to, $action, $post_ids)
        {
            if ($action !== 'selloship_orders')
                return $redirect_to; // Exit

            $processed_ids = array();

            $vendor_id = get_option('selloship_vendor_id');
            $email = get_option('selloship_emailid');

            if (empty($vendor_id) || empty($email) || $vendor_id == '' || $email == '') {
                return $redirect_to = add_query_arg(array(
                    'selloship_orders' => '1',
                    'message' => 'Failed',
                ), $redirect_to);
            }

            $in = "vendor_id=$vendor_id&device_from=3";
            $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_ACCESS_TOKEN_ENDPOINT, $in, md5($vendor_id . $email));

            if (!empty($res)) {
                if ($res['success'] == '1' && isset($res['access_token'])) {
                    $access_token = $res['access_token'];

                    $sentOrders = array();

                    foreach ($post_ids as $post_id) {

                        $isOrderSentToSelloShip = get_post_meta($post_id, '_is_order_sent_to_sello_ship', true);

                        if ($isOrderSentToSelloShip != '') {
                            continue;
                        }

                        $order = wc_get_order($post_id);

                        $payment_method = $order->get_payment_method();

                        if ($payment_method == 'cod') {
                            $payment_method = 3;
                        } else {
                            $payment_method = 4;
                        }

                        $fname = $order->get_shipping_first_name();
						if( empty($fname) ){
							$fname = $order->get_billing_first_name();
						}
						
                        $lname = $order->get_shipping_last_name();
						if( empty($lname) ){
							$lname = $order->get_billing_last_name();
						}
						
                        $address = $order->get_shipping_address_1() . $order->get_shipping_address_2();
						if( empty($address) ){
							$address = $order->get_billing_address_1() . $order->get_billing_address_2();
						}
						
                        $zipcode = $order->get_shipping_postcode();
						if( empty($zipcode) ){
							$zipcode = $order->get_billing_postcode();
						}
						
                        $city = $order->get_shipping_city();
						if( empty($city) ){
							$city = $order->get_billing_city();
						}
						
                        $state = $order->get_shipping_state();
						if( empty($state) ){
							$state = $order->get_billing_state();
						}
						
						$phone = $order->get_billing_phone();
                        $email = $order->get_billing_email();
                        $price = $order->get_total();
                        $landmark = $city;

                        $items = $order->get_items();
                        $total_items = count($items);
                        //echo '<pre>';print_r($items); die;
                        if($total_items <= 1){
                            foreach ($items as $item) {
                                $product = wc_get_product($item->get_product_id());
                                if ($product) {
                                    $sku_orig = $product->get_sku();
                                    $sku = !empty( $product->get_sku() ) ? '( SKU : '.$product->get_sku().' )' : '';
									
									$variant_name = '';
									$variation_id = $item->get_variation_id();
									if( $variation_id!=0 ){
										$variation = wc_get_product($variation_id);
										if( $variation ){
											$attr = $variation->get_variation_attributes();
											$attr_str = array();
											if( !empty($attr) ){
												foreach( $attr as $k=>$v ){
													$attr_str[] = str_replace('attribute_pa_','',$k).':'.$v;
												}
											}
											$variant_name = '[ '.implode(', ',$attr_str).' ]';
										}
									}
								
                                    $name = $product->get_name()." - ".$sku." - ".$variant_name;
									$name = str_replace('&','and',$name);
									$fname = str_replace('&','and',$fname);
									$lname = str_replace('&','and',$lname);
									$address = str_replace('&','and',$address);
									$city = str_replace('&','and',$city);
									$state = str_replace('&','and',$state);
									$landmark = str_replace('&','and',$landmark);
                                    // $price = $product->get_price();
                                    $old_price = $price;
                                    $quantity = $item->get_quantity();
    
                                    $in = "vendor_id=$vendor_id&device_from=3&product_name=$name&price=$price&old_price=$old_price&first_name=$fname&last_name=$lname&mobile_no=$phone&address=$address&state=$state&city=$city&zip_code=$zipcode&landmark=$landmark&payment_method=$payment_method&qty=$quantity&email=$email&custom_order_id=$post_id&sku=$sku_orig";
                                    $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT, $in, $access_token);
                                    if (isset($res['selloship_order_id'])) {
                                        $sentOrders[] = ['name' => $name, 'order_id' => $res['selloship_order_id'], 'url' => $res['selloship_url']];
                                        update_post_meta($post_id, '_is_order_sent_to_sello_ship', $res['selloship_order_id']);
                                    }
                                }
                            }
                        }else{
							
                            $name = "";
							$totalqty = 0;
                        	foreach ($items as $item) {
								$product = wc_get_product($item->get_product_id());
								$variant_name = '';
								$variation_id = $item->get_variation_id();
								if( $variation_id!=0 ){
									$variation = wc_get_product($variation_id);
									if( $variation ){
										$attr = $variation->get_variation_attributes();
										$attr_str = array();
										if( !empty($attr) ){
											foreach( $attr as $k=>$v ){
												$attr_str[] = str_replace('attribute_pa_','',$k).':'.$v;
											}
										}
										$variant_name = '[ '.implode(', ',$attr_str).' ]';
									}
								}
								$sku_orig = $product->get_sku();
								$sku = !empty( $product->get_sku() ) ? '( SKU : '.$product->get_sku().' )' : '';
								$name .= $product->get_name()." - ".$sku." - ".$variant_name." - (QTY ".$item->get_quantity()."),";
								$totalqty += $item->get_quantity();
                        	}
							
							$name = str_replace('&','and',$name);
							$fname = str_replace('&','and',$fname);
							$lname = str_replace('&','and',$lname);
							$address = str_replace('&','and',$address);
							$city = str_replace('&','and',$city);
							$state = str_replace('&','and',$state);
							$landmark = str_replace('&','and',$landmark);
							
                            $quantity = $totalqty;
                            $old_price = $price;

                            $in = "vendor_id=$vendor_id&device_from=3&product_name=$name&price=$price&old_price=$old_price&first_name=$fname&last_name=$lname&mobile_no=$phone&address=$address&state=$state&city=$city&zip_code=$zipcode&landmark=$landmark&payment_method=$payment_method&qty=$quantity&email=$email&custom_order_id=$post_id&sku=$sku_orig";
                            $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT, $in, $access_token);
                            if (isset($res['selloship_order_id'])) {
                                $sentOrders[] = ['name' => $name, 'order_id' => $res['selloship_order_id'], 'url' => $res['selloship_url']];
                                update_post_meta($post_id, '_is_order_sent_to_sello_ship', $res['selloship_order_id']);
                            }
                        }
                        update_post_meta($post_id, '_selloship_order_id', $sentOrders);

                        $processed_ids[] = $post_id;
                    }
                }
            }

            return $redirect_to = add_query_arg(array(
                'selloship_orders' => '1',
                'processed_count' => count($processed_ids),
                'message' => 'Success',
            ), $redirect_to);
        }

        // The results notice from bulk action on orders
        function selloship_bulk_action_admin_notice()
        {
            if (empty($_REQUEST['selloship_orders'])) return; // Exit

            if (!empty($_REQUEST['processed_count'])) {
                $count = intval($_REQUEST['processed_count']);

                printf('<div id="message" class="updated fade"><p>' .
                    _n(
                        'Sent %s Order to selloship.',
                        'Sent %s Orders to selloship.',
                        $count,
                        'selloship_orders'
                    ) . '</p></div>', $count);
            } else {
                printf('<div id="message" class="updated fade"><p>Some Errors Occurred!</p></div>');
            }
        }


        function custom_shop_order_column($columns)
        {
            $reordered_columns = array();

            // Inserting columns to a specific location
            foreach ($columns as $key => $column) {
                $reordered_columns[$key] = $column;
                if ($key == 'order_status') {
                    // Inserting after "Status" column
                    $reordered_columns['custom-column'] = 'Actions';
                }
            }
            return $reordered_columns;
        }

        // Adding custom fields meta data for each new column (example)

        function custom_orders_list_column_content($column, $post_id)
        {
            $isSelloShipEnabled = get_option('selloship_enable', 'no');
            $selloship_vendor_id = get_option('selloship_vendor_id');
            switch ($column) {
                case 'custom-column':
                    if ($isSelloShipEnabled == 'yes' && $selloship_vendor_id != '') {
                        $_sello_ship_tracking_url = get_post_meta($post_id, '_sello_ship_tracking_url', true);
                        $isOrderSentToSelloShip = get_post_meta($post_id, '_is_order_sent_to_sello_ship', true);

                        if (!empty($_sello_ship_tracking_url) || $_sello_ship_tracking_url != ''){
							echo '<a type="button" href="'.$_sello_ship_tracking_url.'" class="button" style="background-color: white; color: green; border: 1px solid green" target="_blank">Track with Selloship</a>';
                        }else if (!empty($isOrderSentToSelloShip) || $isOrderSentToSelloShip != ''){
                            echo '<button type="button" onclick="trackOrder(' . $isOrderSentToSelloShip . ', this)" class="button" style="background-color: white; color: green; border: 1px solid green">Track with Selloship</button>';
                        }else
                            echo '<button type="button" onclick="sendOrder(' . $post_id . ', this)" class="button" style="background-color: white; color: red; border: 1px solid red">Send to Selloship</button>';
                        break;
                    } else {
                        echo 'Selloship is deactivated or not connected!';
                    }
            }
        }
		
		function custom_orders_list_column_content2($column, $post_json)
        {
            $isSelloShipEnabled = get_option('selloship_enable', 'no');
            $selloship_vendor_id = get_option('selloship_vendor_id');
            switch ($column) {
                case 'custom-column':
					$post_json = json_decode($post_json,true);
					$post_id = $post_json['id'];
                    if ($isSelloShipEnabled == 'yes' && $selloship_vendor_id != '') {
                        $isOrderSentToSelloShip = get_post_meta($post_id, '_is_order_sent_to_sello_ship', true);

                        if (!empty($isOrderSentToSelloShip) || $isOrderSentToSelloShip != '')
                            echo '<button type="button" onclick="trackOrder(' . $isOrderSentToSelloShip . ', this)" class="button" style="background-color: white; color: green; border: 1px solid green">Track with Selloship</button>';
                        else
                            echo '<button type="button" onclick="sendOrder(' . $post_id . ', this)" class="button" style="background-color: white; color: red; border: 1px solid red">Send to Selloship</button>';
                        break;
                    } else {
                        echo 'Selloship is deactivated or not connected!';
                    }
            }
        }

        function callAPI($url, $data, $auth = '')
        {
            $postData = array(
                'body' => $data,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => $auth
                )
            );

            $response = wp_remote_retrieve_body(wp_remote_post($url, $postData));

            if(!empty($response)){
                $response = str_replace("\xEF\xBB\xBF",'',$response); 
                return json_decode($response, true);
            }else{
                return array();
            }
        }

		function send_order_to_selloship_callback_cron($postid)
        {
            if (isset($postid) && $postid != '') {
                $vendor_id = get_option('selloship_vendor_id');
                $email = get_option('selloship_emailid');

                if (empty($vendor_id) || empty($email) || $vendor_id == '' || $email == '') {
                    return;
                }

                $in = "vendor_id=$vendor_id&device_from=3";
                $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_ACCESS_TOKEN_ENDPOINT, $in, md5($vendor_id . $email));
                if (!empty($res)) {
					
                    if ($res['success'] == '1' && isset($res['access_token'])) {
                        $access_token = $res['access_token'];

                        $sentOrders = array();

                        $order = wc_get_order($postid);
                        $payment_method = $order->get_payment_method();

                        if ($payment_method == 'cod') {
                            $payment_method = 3;
                        } else {
                            $payment_method = 4;
                        }

                        $fname = $order->get_shipping_first_name();
						if( empty($fname) ){
							$fname = $order->get_billing_first_name();
						}
						
                        $lname = $order->get_shipping_last_name();
						if( empty($lname) ){
							$lname = $order->get_billing_last_name();
						}
						
                        $address = $order->get_shipping_address_1() . $order->get_shipping_address_2();
						if( empty($address) ){
							$address = $order->get_billing_address_1() . $order->get_billing_address_2();
						}
						
                        $zipcode = $order->get_shipping_postcode();
						if( empty($zipcode) ){
							$zipcode = $order->get_billing_postcode();
						}
						
                        $city = $order->get_shipping_city();
						if( empty($city) ){
							$city = $order->get_billing_city();
						}
						
                        $state = $order->get_shipping_state();
						if( empty($state) ){
							$state = $order->get_billing_state();
						}
						
						$phone = $order->get_billing_phone();
                        $email = $order->get_billing_email();
                        $price = $order->get_total();
                        $landmark = $city;

                        $items = $order->get_items();
                        $total_items = count($items);
						
						if($total_items <= 1){
                            foreach ($items as $item) {
                                $product = wc_get_product($item->get_product_id());
                                if ($product) {
                                    //$sku = !empty( $product->get_sku() ) ? '['.$product->get_sku().']' : '';
									$sku_orig = $product->get_sku();
                                    $sku = !empty( $product->get_sku() ) ? '( SKU : '.$product->get_sku().' )' : '';
									
									$variant_name = '';
									$variation_id = $item->get_variation_id();
									if( $variation_id!=0 ){
										$variation = wc_get_product($variation_id);
										if( $variation ){
											$attr = $variation->get_variation_attributes();
											$attr_str = array();
											if( !empty($attr) ){
												foreach( $attr as $k=>$v ){
													$attr_str[] = str_replace('attribute_pa_','',$k).':'.$v;
												}
											}
											$variant_name = '[ '.implode(', ',$attr_str).' ]';
										}
									}
								
                                    $name = $product->get_name()." - ".$sku." - ".$variant_name;
									$name = str_replace('&','and',$name);
									$fname = str_replace('&','and',$fname);
									$lname = str_replace('&','and',$lname);
									$address = str_replace('&','and',$address);
									$city = str_replace('&','and',$city);
									$state = str_replace('&','and',$state);
									$landmark = str_replace('&','and',$landmark);
                                    // $price = $product->get_price();
                                    $old_price = $price;
                                    $quantity = $item->get_quantity();
    
                                    $in = "vendor_id=$vendor_id&device_from=3&product_name=$name&price=$price&old_price=$old_price&first_name=$fname&last_name=$lname&mobile_no=$phone&address=$address&state=$state&city=$city&zip_code=$zipcode&landmark=$landmark&payment_method=$payment_method&qty=$quantity&email=$email&custom_order_id=$postid&sku=$sku_orig";
                                    $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT, $in, $access_token);
                                    if (isset($res['selloship_order_id'])) {
                                        $sentOrders[] = ['name' => $name, 'order_id' => $res['selloship_order_id'], 'url' => $res['selloship_url']];
                                        update_post_meta($postid, '_is_order_sent_to_sello_ship', $res['selloship_order_id']);
                                    }
                                }
                            }
                        }else{
							
                            $name = "";
							$totalqty = 0;
                        	foreach ($items as $item) {
								$product = wc_get_product($item->get_product_id());
								$variant_name = '';
								$variation_id = $item->get_variation_id();
								if( $variation_id!=0 ){
									$variation = wc_get_product($variation_id);
									if( $variation ){
										$attr = $variation->get_variation_attributes();
										$attr_str = array();
										if( !empty($attr) ){
											foreach( $attr as $k=>$v ){
												$attr_str[] = str_replace('attribute_pa_','',$k).':'.$v;
											}
										}
										$variant_name = '[ '.implode(', ',$attr_str).' ]';
									}
								}
								$sku_orig = $product->get_sku();
								$sku = !empty( $product->get_sku() ) ? '( SKU : '.$product->get_sku().' )' : '';
								$name .= $product->get_name()." - ".$sku." - ".$variant_name." - (QTY ".$item->get_quantity()."),";
								$totalqty += $item->get_quantity();
                        	}
							$name = str_replace('&','and',$name);
							$fname = str_replace('&','and',$fname);
							$lname = str_replace('&','and',$lname);
							$address = str_replace('&','and',$address);
							$city = str_replace('&','and',$city);
							$state = str_replace('&','and',$state);
							$landmark = str_replace('&','and',$landmark);
                            $quantity = $totalqty;
                            $old_price = $price;

                            $in = "vendor_id=$vendor_id&device_from=3&product_name=$name&price=$price&old_price=$old_price&first_name=$fname&last_name=$lname&mobile_no=$phone&address=$address&state=$state&city=$city&zip_code=$zipcode&landmark=$landmark&payment_method=$payment_method&qty=$quantity&email=$email&custom_order_id=$postid&sku=$sku_orig";
                            $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT, $in, $access_token);
							
                            if (isset($res['selloship_order_id'])) {
                                $sentOrders[] = ['name' => $name, 'order_id' => $res['selloship_order_id'], 'url' => $res['selloship_url']];
                                update_post_meta($postid, '_is_order_sent_to_sello_ship', $res['selloship_order_id']);
                            }
                        }
                        update_post_meta($postid, '_selloship_order_id', $sentOrders);
                    }
                }
            }
        }
		
        function send_order_to_selloship_callback()
        {
			$postid = sanitize_text_field($_POST['postid']);
            if (isset($postid) && $postid != '') {
                $vendor_id = get_option('selloship_vendor_id');
                $email = get_option('selloship_emailid');

                if (empty($vendor_id) || empty($email) || $vendor_id == '' || $email == '') {
                    echo json_encode(['status' => '0', 'message' => 'Error']);
                    exit;
                }

                $in = "vendor_id=$vendor_id&device_from=3";
                $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_ACCESS_TOKEN_ENDPOINT, $in, md5($vendor_id . $email));

                if (!empty($res)) {
                    if ($res['success'] == '1' && isset($res['access_token'])) {
                        $access_token = $res['access_token'];

                        $sentOrders = array();

                        $order = wc_get_order($postid);
                        $payment_method = $order->get_payment_method();

                        if ($payment_method == 'cod') {
                            $payment_method = 3;
                        } else {
                            $payment_method = 4;
                        }

                        $fname = $order->get_shipping_first_name();
						if( empty($fname) ){
							$fname = $order->get_billing_first_name();
						}
						
                        $lname = $order->get_shipping_last_name();
						if( empty($lname) ){
							$lname = $order->get_billing_last_name();
						}
						
                        $address = $order->get_shipping_address_1() . $order->get_shipping_address_2();
						if( empty($address) ){
							$address = $order->get_billing_address_1() . $order->get_billing_address_2();
						}
						
                        $zipcode = $order->get_shipping_postcode();
						if( empty($zipcode) ){
							$zipcode = $order->get_billing_postcode();
						}
						
                        $city = $order->get_shipping_city();
						if( empty($city) ){
							$city = $order->get_billing_city();
						}
						
                        $state = $order->get_shipping_state();
						if( empty($state) ){
							$state = $order->get_billing_state();
						}
						
						$phone = $order->get_billing_phone();
                        $email = $order->get_billing_email();
                        $price = $order->get_total();
                        $landmark = $city;

                        $items = $order->get_items();
                        $total_items = count($items);
						
						if($total_items <= 1){
                            foreach ($items as $item) {
                                $product = wc_get_product($item->get_product_id());
                                if ($product) {
                                    //$sku = !empty( $product->get_sku() ) ? '['.$product->get_sku().']' : '';
                                    $sku_orig = $product->get_sku();
									$sku = !empty( $product->get_sku() ) ? '( SKU : '.$product->get_sku().' )' : '';
									
									$variant_name = '';
									$variation_id = $item->get_variation_id();
									if( $variation_id!=0 ){
										$variation = wc_get_product($variation_id);
										if( $variation ){
											$attr = $variation->get_variation_attributes();
											$attr_str = array();
											if( !empty($attr) ){
												foreach( $attr as $k=>$v ){
													$attr_str[] = str_replace('attribute_pa_','',$k).':'.$v;
												}
											}
											$variant_name = '[ '.implode(', ',$attr_str).' ]';
										}
									}
								
                                    $name = $product->get_name()." - ".$sku." - ".$variant_name;
									$name = str_replace('&','and',$name);
									$fname = str_replace('&','and',$fname);
									$lname = str_replace('&','and',$lname);
									$address = str_replace('&','and',$address);
									$city = str_replace('&','and',$city);
									$state = str_replace('&','and',$state);
									$landmark = str_replace('&','and',$landmark);
                                    // $price = $product->get_price();
                                    $old_price = $price;
                                    $quantity = $item->get_quantity();
    
                                    $in = "vendor_id=$vendor_id&device_from=3&product_name=$name&price=$price&old_price=$old_price&first_name=$fname&last_name=$lname&mobile_no=$phone&address=$address&state=$state&city=$city&zip_code=$zipcode&landmark=$landmark&payment_method=$payment_method&qty=$quantity&email=$email&custom_order_id=$postid&sku=$sku_orig";
                                    $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT, $in, $access_token);
									
                                    if (isset($res['selloship_order_id'])) {
                                        $sentOrders[] = ['name' => $name, 'order_id' => $res['selloship_order_id'], 'url' => $res['selloship_url']];
                                        update_post_meta($postid, '_is_order_sent_to_sello_ship', $res['selloship_order_id']);
                                    }else{
										echo json_encode(['status' => '0', 'message' => $res['msg']]);
										exit;
									}
                                }
                            }
                        }else{
							
                            $name = "";
							$totalqty = 0;
                        	foreach ($items as $item) {
								$product = wc_get_product($item->get_product_id());
								$variant_name = '';
								$variation_id = $item->get_variation_id();
								if( $variation_id!=0 ){
									$variation = wc_get_product($variation_id);
									if( $variation ){
										$attr = $variation->get_variation_attributes();
										$attr_str = array();
										if( !empty($attr) ){
											foreach( $attr as $k=>$v ){
												$attr_str[] = str_replace('attribute_pa_','',$k).':'.$v;
											}
										}
										$variant_name = '[ '.implode(', ',$attr_str).' ]';
									}
								}
								$sku_orig = $product->get_sku();
								$sku = !empty( $product->get_sku() ) ? '( SKU : '.$product->get_sku().' )' : '';
								$name .= $product->get_name()." - ".$sku." - ".$variant_name." - (QTY ".$item->get_quantity()."),";
								$totalqty += $item->get_quantity();
                        	}
							$name = str_replace('&','and',$name);
							$fname = str_replace('&','and',$fname);
							$lname = str_replace('&','and',$lname);
							$address = str_replace('&','and',$address);
							$city = str_replace('&','and',$city);
							$state = str_replace('&','and',$state);
							$landmark = str_replace('&','and',$landmark);
                            $quantity = $totalqty;
                            $old_price = $price;

                            $in = "vendor_id=$vendor_id&device_from=3&product_name=$name&price=$price&old_price=$old_price&first_name=$fname&last_name=$lname&mobile_no=$phone&address=$address&state=$state&city=$city&zip_code=$zipcode&landmark=$landmark&payment_method=$payment_method&qty=$quantity&email=$email&custom_order_id=$postid&sku=$sku_orig";
                            $res = $this->callAPI(SELLOSHIP_WC_ACCOUNT_CREATE_ORDER_ENDPOINT, $in, $access_token);
                            if (isset($res['selloship_order_id'])) {
                                $sentOrders[] = ['name' => $name, 'order_id' => $res['selloship_order_id'], 'url' => $res['selloship_url']];
                                update_post_meta($postid, '_is_order_sent_to_sello_ship', $res['selloship_order_id']);
                            }else{
								echo json_encode(['status' => '0', 'message' => $res['msg']]);
								exit;
							}
                        }
                        update_post_meta($postid, '_selloship_order_id', $sentOrders);

                        echo json_encode(['status' => '1', 'message' => $res]);
                        exit;
                    } else {
                        echo json_encode(['status' => '0', 'message' => 'Error']);
                        exit;
                    }
                } else {
                    echo json_encode(['status' => '0', 'message' => 'Error']);
                    exit;
                }
            } else {
                echo json_encode(['status' => '0', 'message' => 'Error']);
                exit;
            }
        }

        function track_order_with_selloship_callback()
        {
            $orderid = sanitize_text_field($_POST['order_id']);
            if (isset($orderid) && $orderid != '') {
                $vendor_id = get_option('selloship_vendor_id');
                $email = get_option('selloship_emailid');

                if (empty($vendor_id) || empty($email) || $vendor_id == '' || $email == '') {
                    echo json_encode(['status' => '0', 'message' => 'Error']);
                    exit;
                }

                $in = "order_id=$orderid&vendor_id=$vendor_id";
                $res = $this->callAPI('https://selloship.com/web_api/wordpress_track', $in, '');

                if (!empty($res)) {
                    if ($res['success'] == '1' && isset($res['data'])) {
                        $data = $res['data'];

                        $tracking_url = $data[0]->tracking_url;

                        echo json_encode(['status' => '1', 'url' => $tracking_url]);
                        exit;
                    } else {
                        echo json_encode(['status' => '0', 'message' => 'No Courier Assigned!']);
                        exit;
                    }
                } else {
                    echo json_encode(['status' => '0', 'message' => 'Error']);
                    exit;
                }
            } else {
                echo json_encode(['status' => '0', 'message' => 'Error']);
                exit;
            }
        }

        function ak_custom_scripts()
        {
            ?>
            <script>
                function sendOrder(postid, instance) {
                    jQuery.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        data: {
                            action: 'send_order_to_selloship',
                            postid: postid
                        },
                        method: 'POST',
                        dataType: 'JSON',
                        success: function (data) {
                            if (data.status == 1) {
                                alert('Order Sent Successfully!');
                                window.open('https://selloship.com/v2/seller/welcome/dashboard.html', '_blank');
                                location.reload();
                            } else {
                                alert(data.message);
                            }
                        },
                        error: function (data) {
                            alert('Some errors occurred!');
                        }
                    })
                }

                function trackOrder(orderid, instance) {
                    jQuery.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        data: {
                            action: 'track_order_with_selloship',
                            order_id: orderid
                        },
                        method: 'POST',
                        dataType: 'JSON',
                        success: function (data) {
                            if (data.status == 1) {
                                if (data.url == null || data.url == '') {
                                    alert("No courier service is assigned to this order yet!")
                                } else {
                                    window.open(data.url, '_blank');
                                }
                            } else {
                                alert(data.message);
                            }
                        },
                        error: function (data) {
                            alert('Some errors occurred!');
                        }
                    })
                }
            </script>
            <?php
        }

        public function selloship_add_scripts()
        {
            
        }

        /**
         * Plugin configuration.
         *
         * @return array
         */
        public static function selloship_plugin_configuration()
        {
            return array(
                'id' => 'selloship_woocommerce_shipping',
                'method_title' => __('SelloShip App Configuration', 'selloship-woocommerce'),
                'method_description' => __("")
            );
        }

        /**
         * Plugin action links on Plugin page.
         *
         * @param array $links available links
         *
         * @return array
         */
        public function selloship_plugin_action_links($links)
        {
            $plugin_links = array(
                '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=selloship_woocommerce_shipping') . '">' . __('Settings', 'selloship-woocommerce') . '</a>',
                '<a href="#">' . __('Documentation', 'selloship-woocommerce') . '</a>',
            );
            return array_merge($plugin_links, $links);
        }

        /**
         * Shipping Initialization.
         *
         * @return null
         */
        public function selloship_woocommerce_shipping_init()
        {
            if (!class_exists("SelloShip_Woocommerce_Shipping_Method")) {
                require_once 'includes/class-selloship-woocommerce-shipping-method.php';
            }

            new SelloShip_Woocommerce_Shipping_Method();
        }
    }

    new SelloShip_Woocommerce_Shipping();
}

add_action('woocommerce_order_status_changed', 'so_status_completed', 10, 3);
function so_status_completed($order_id, $old_status, $new_status){
	if($new_status=='processing'){
		$selloship_sync_enable = get_option('selloship_sync_enable');
		if( $selloship_sync_enable=='yes' ){
			$obj = new SelloShip_Woocommerce_Shipping();
			sleep(0.5);
			$obj->send_order_to_selloship_callback_cron($order_id);
		}
	}
}
