<?php
/**
 * Plugin Name: LatePoint WhatsApp OTP
 * Plugin URI: https://github.com/Hari-Bonda/latepoint-whatsapp-otp/
 * Description: An addon for LatePoint to send OTPs via WhatsApp using Meta Cloud API.
 * Version: 1.0.0
 * Author: Hari Bonda
 * Text Domain: latepoint-whatsapp-otp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'LATEPOINT_WHATSAPP_ADDON_PATH', plugin_dir_path( __FILE__ ) );
define( 'LATEPOINT_WHATSAPP_ADDON_URL', plugin_dir_url( __FILE__ ) );

require_once LATEPOINT_WHATSAPP_ADDON_PATH . 'WhatsAppMetaHelper.php';

// Initialize the addon
OsWhatsAppMetaAddonHelper::init();

