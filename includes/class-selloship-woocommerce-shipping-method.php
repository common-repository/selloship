<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shipping Method Class.
 */
if (!class_exists("SelloShip_Woocommerce_Shipping_Method")) {
    class SelloShip_Woocommerce_Shipping_Method extends WC_Shipping_Method
    {
        /**
         * Constructor.
         */
        public function __construct()
        {
            add_filter('woocommerce_get_sections_shipping', array($this, 'selloship_add_section'));
            add_filter('woocommerce_get_settings_shipping', array($this, 'selloship_all_settings'), 10, 2);
        }

        public function selloship_add_section($sections)
        {
            $sections['selloship_woocommerce_shipping'] = __('SelloShip App Configuration', 'selloship-woocommerce');
            return $sections;
        }
        
        /**
         * Settings Form fileds.
         */
        public function selloship_all_settings($settings, $current_section)
        {
            if ($current_section == 'selloship_woocommerce_shipping') {
                $settings_selloship = array();
                
                if( isset($_POST['selloship_enable']) ){
                    $selloship_emailid = get_option('selloship_emailid');
                    $selloship_password = get_option('selloship_password');
                    
                    $data = array(
                        'email'     => $selloship_emailid,
                        'password'     => $selloship_password,
                        'reg_form'     => '3',
                        'device_id'     => 'abcd',
                        'app_status'     => '3',
                        'device_from'     => '3',
                        'site_url'  => site_url()
                    );
                    $msg = '';
                    $response = wp_remote_post( SELLOSHIP_WC_ACCOUNT_REGISTER_ENDPOINT, array(
                        'body'    => $data,
                        'headers' => array(
                            'Authorization' => '1',
                        ),
                    ) );
                    if ( !is_wp_error( $response ) ) {
                        $api_response = json_decode( wp_remote_retrieve_body( $response ), true );
                        if( $api_response['success']==1 ){
                            $vendor_id = $api_response['data'][0]['vendor_id'];
                            update_option('selloship_vendor_id',$vendor_id);
                            $msg = '<b style="color:green">'.$api_response['msg'].'</b>';
                        }else{
                            $msg = '<b style="color:red">'.$api_response['msg'].'</b>';
                            update_option('selloship_vendor_id','');
                        }
                    }
                    //echo '<pre>';print_r($_POST);echo '</pre>';
                    $settings_selloship[] = array('name' => '', 'type' => 'title', 'desc' => $msg, 'id' => 'selloship_settings_1');
                }
                 
                // Add Title to the Settings
                $settings_selloship[] = array('name' => __('SelloShip Settings', 'selloship-woocommerce'), 'type' => 'title', 'desc' => __('', 'selloship-woocommerce'), 'id' => 'selloship_settings');

                $settings_selloship[] = array(
                    'name' => __('Enable', ''),
                    'desc_tip' => __('Enable/disable functionality', 'selloship-woocommerce'),
                    'id' => 'selloship_enable',
                    'type' => 'checkbox',
                    'css' => 'min-width:300px;',
                    'desc' => __('Enable Selloship', 'selloship-woocommerce'),
                );

                $settings_selloship[] = array(
                    'name' => __('Email', 'selloship-woocommerce'),
                    'desc_tip' => __('Email id registred with selloship', 'selloship-woocommerce'),
                    'id' => 'selloship_emailid',
                    'type' => 'email',
                );

                $settings_selloship[] = array(
                    'name' => __('Password', 'selloship-woocommerce'),
                    'desc_tip' => __('Password registred with selloship', 'selloship-woocommerce'),
                    'id' => 'selloship_password',
                    'type' => 'password',
                );
				
				$settings_selloship[] = array(
                    'name' => __('Auto Sent Orders To Selloship', ''),
                    'desc_tip' => __('', 'selloship-woocommerce'),
                    'id' => 'selloship_sync_enable',
                    'type' => 'checkbox',
                    'css' => 'min-width:300px;',
                    'desc' => __('Enable Automatically Sent "Processing" Order to Selloship when is place ', 'selloship-woocommerce'),
                );
				
				$settings_selloship[] = array(
                    'name' => __('Auto Sync Orders Cron', ''),
                    'desc_tip' => __('Is sync processing order ship to selloship automatically every hour', 'selloship-woocommerce'),
                    'id' => 'selloship_cron_enable',
                    'type' => 'checkbox',
                    'css' => 'min-width:300px;',
                    'desc' => __('Enable Automatically Cron', 'selloship-woocommerce'),
                );

                $settings_selloship[] = array('type' => 'sectionend', 'id' => 'selloship_settings');
                return $settings_selloship;
            }
        }
    }
}
