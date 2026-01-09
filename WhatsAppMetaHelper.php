<?php

class OsWhatsAppMetaAddonHelper {

	public static function init() {
		// Register SMS Processor
		add_filter('latepoint_sms_processors', [__CLASS__, 'add_processor'], 10, 2);

		// Handle SMS Sending
		add_filter('latepoint_notifications_send_sms', [__CLASS__, 'send_sms'], 10, 3);
        
        // Fix for (#1) - Allow 'phone' as a valid delivery method for OTP (fixes core bug/feature)
        add_filter('latepoint_otp_delivery_methods_for_contact_types', [__CLASS__, 'allow_phone_delivery_method'], 10, 1);

        // Fix for (#2) - Handle the actual sending since Core sendOTP doesn't handle 'phone' delivery method
        add_filter('latepoint_notifications_send_otp_code', [__CLASS__, 'handle_otp_sending_manually'], 10, 3);
	}

	public static function add_processor($processors, $enabled_only = false) {
		$processors['whatsapp_meta'] = [
			'code'      => 'whatsapp_meta',
			'label'     => __('WhatsApp Meta Cloud API (Addon)', 'latepoint-whatsapp-addon'),
			'image_url' => '' // Can add an icon here
		];
		return $processors;
	}

    public static function allow_phone_delivery_method($methods) {
        $methods['phone'][] = 'phone';
        return $methods;
    }

    public static function handle_otp_sending_manually($result, $otp_code, $otp) {
        // If the status is already success, do nothing
        if ($result['status'] === LATEPOINT_STATUS_SUCCESS) {
            return $result;
        }

        // We only care if delivery method is 'phone' (which is what Core tries to use) or 'whatsapp'
        if (in_array($otp->delivery_method, ['phone', 'whatsapp'])) {
            
            // Fix for verification mismatch: 
            // The OTP was created with 'phone' method, but the verification form submits 'sms'.
            // We must update the DB record to 'sms' so verifyOTP finds it.
            if ($otp->delivery_method === 'phone') {
                $otp->update_attributes(['delivery_method' => 'sms']);
            }

            // Check if our processor is enabled
            if (OsSettingsHelper::get_settings_value('notifications_sms_processor') !== 'whatsapp_meta') {
                // Even if not sending via WhatsApp, we fixed the DB record so standard SMS might work if hooked? 
                // But generally this handles the send.
                return $result;
            }

            // Send via Meta API directly
            $api_response = self::send_whatsapp_api($otp->contact_value, $otp_code);

            if (is_wp_error($api_response)) {
                $result['status'] = LATEPOINT_STATUS_ERROR;
                $result['message'] = $api_response->get_error_message();
                error_log('WhatsApp Addon (Manual): Error: ' . $api_response->get_error_message());
            } else {
                $response_code = wp_remote_retrieve_response_code($api_response);
                if ($response_code === 200) {
                    $result['status'] = LATEPOINT_STATUS_SUCCESS;
                    $result['message'] = __('WhatsApp message sent successfully via Meta API.', 'latepoint-whatsapp-addon');
                    $result['processed_datetime'] = OsTimeHelper::now_datetime_in_db_format();
                } else {
                    $result['status'] = LATEPOINT_STATUS_ERROR;
                    $result['message'] = __('Meta API Error', 'latepoint-whatsapp-addon');
                    error_log('WhatsApp Addon (Manual): API Data: ' . wp_remote_retrieve_body($api_response));
                }
            }
        }
        return $result;
    }

    // WP Settings API Integration
    public static function init_wp_settings() {
        add_action('admin_menu', [__CLASS__, 'add_wp_admin_menu'], 100);
        add_action('admin_init', [__CLASS__, 'register_wp_settings']);
    }

    public static function add_wp_admin_menu() {
         add_submenu_page(
            'latepoint', 
            __('WhatsApp Addon Settings', 'latepoint-whatsapp-addon'), 
            __('WhatsApp Addon Settings', 'latepoint-whatsapp-addon'), 
            'manage_options', 
            'latepoint-whatsapp-settings', 
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_wp_settings() {
        register_setting('latepoint_whatsapp_group', 'latepoint_whatsapp_template_name');
        register_setting('latepoint_whatsapp_group', 'latepoint_whatsapp_template_has_button');
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <div class="notice notice-info inline">
                <p>
                    <?php _e('This addon uses the API Credentials configured in the <strong>"Whatsapp by Meta"</strong> addon.', 'latepoint-whatsapp-addon'); ?>
                </p>
            </div>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'latepoint_whatsapp_group' );
                do_settings_sections( 'latepoint_whatsapp_group' );
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Template Name</th>
                        <td><input type="text" name="latepoint_whatsapp_template_name" value="<?php echo esc_attr( get_option('latepoint_whatsapp_template_name') ); ?>" class="regular-text" />
                        <p class="description">The name of the WhatsApp template to use for OTPs (e.g., <code>otp_verification</code>). It must have one variable <code>{{1}}</code> for the code.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Template has URL Button?</th>
                        <td>
                            <input type="checkbox" name="latepoint_whatsapp_template_has_button" value="1" <?php checked(1, get_option('latepoint_whatsapp_template_has_button'), true); ?> />
                            <p class="description">
                                <strong>Check this</strong> if your template has a button that requires the code (e.g. "Copy Code" or URL button).<br>
                                <em>Tip: If you see error <code>(#131008) Required parameter is missing</code>, you MUST check this box.</em>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }



	public static function send_sms($result, $to, $content) {
        // Logging for debug - keeping minimal now
        
        // Only run if our processor is selected
        $processor = OsSettingsHelper::get_settings_value('notifications_sms_processor');
        
        if ($processor !== 'whatsapp_meta') {
            return $result;
        }

        // Extract OTP
        $otp_code = self::extract_otp($content);

        if (!$otp_code) {
             // Just return result, don't error out everything if we are not the target
             return $result;
        }

        // Send via Meta API
        $api_response = self::send_whatsapp_api($to, $otp_code);

        if (is_wp_error($api_response)) {
            $result['status'] = LATEPOINT_STATUS_ERROR;
            $result['message'] = $api_response->get_error_message();
             // Log error for debugging
             error_log('LatePoint WhatsApp Error: ' . $api_response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($api_response);
            $body = wp_remote_retrieve_body($api_response);
            $data = json_decode($body, true);

            if ($response_code === 200) {
                $result['status'] = LATEPOINT_STATUS_SUCCESS;
                $result['message'] = __('WhatsApp message sent successfully via Meta API.', 'latepoint-whatsapp-addon');
                $result['processor_code'] = 'whatsapp_meta';
                // You might want to store more details in extra_data
                $result['extra_data']['whatsapp_response'] = $data;
            } else {
                $result['status'] = LATEPOINT_STATUS_ERROR;
                // Try to get error message from Facebook response
                $error_msg = isset($data['error']['message']) ? $data['error']['message'] : __('Unknown API error', 'latepoint-whatsapp-addon');
                $result['message'] = $error_msg;
                
                // Only log if it's not the parameter missing error (which the user can fix via settings) or if needed
                error_log('LatePoint WhatsApp API Error: ' . $body);
            }
        }

		return $result;
	}

    private static function extract_otp($content) {
        // Regex to find 4-8 digit number surrounded by word boundaries
        if (preg_match('/\b(\d{4,8})\b/', $content, $matches)) {
            return $matches[1];
        }
        return false;
    }

    private static function send_whatsapp_api($to, $code) {
        // Fetch credentials from LatePoint settings (managed by latepoint-whatsapp-meta)
        $phone_id = OsSettingsHelper::get_settings_value('notifications_whatsapp_meta_phone_number_id');
        // Note: The addon uses 'notifications_whatsapp_meta_system_user_access_token' for access token
        $access_token = OsSettingsHelper::get_settings_value('notifications_whatsapp_meta_system_user_access_token');
        
        // Fetch our settings using WP Options
        $template_name = get_option('latepoint_whatsapp_template_name');
        $has_button = get_option('latepoint_whatsapp_template_has_button');

        if (empty($phone_id) || empty($access_token)) {
            return new WP_Error('missing_config', __('WhatsApp by Meta addon is not configured. Please check LatePoint Settings > Integrations.', 'latepoint-whatsapp-addon'));
        }
        
        if (empty($template_name)) {
             return new WP_Error('missing_config', __('WhatsApp Template Name is missing. Please check LatePoint > WhatsApp Addon Settings.', 'latepoint-whatsapp-addon'));
        }

        $url = "https://graph.facebook.com/v21.0/{$phone_id}/messages";
        
        // Ensure "to" is numbers only
        $to = preg_replace('/\D/', '', $to);

        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => $code
                    ]
                ]
            ]
        ];

        // Add button component if enabled
        if ($has_button) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => 0,
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => $code
                    ]
                ]
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template_name,
                'language' => [
                    'code' => 'en_US' // You might want to make this configurable
                ],
                'components' => $components
            ]
        ];
        
        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 8
        ];

        return wp_remote_post($url, $args);
    }
}

// Initialize WP Settings hooks separately
OsWhatsAppMetaAddonHelper::init_wp_settings();
