<?php
/**
 * Plugin Name: WPBackup Migrator
 * Plugin URI: https://wpbackup.org
 * Description: Helps migrate WordPress sites (backup and migration tooling)
 * Version: 1.3.0
 * Author: WPBackup
 * Text Domain: wpbackup-migrator
 * License: GPL-2.0+
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

define( 'WPBACKUP_MIGRATOR_FILE', __FILE__ );

function wpbackup_migrator() {
	return \Wpbackup\Migrator\Plugin::instance();
}

// Run plugin
wpbackup_migrator();
