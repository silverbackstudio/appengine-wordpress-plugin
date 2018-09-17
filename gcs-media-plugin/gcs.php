<?php
/*
Plugin Name: Google Cloud Storage plugin
Plugin URI:  http://wordpress.org/plugins/gcs/
Description: A plugin for uploading media files to Google Cloud Storage
Version:     0.1.4
Author:      Google Inc
Author URI:  http://cloud.google.com/
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: gcp
Domain Path: /languages

Copyright 2017 Google Inc.

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc., 51 Franklin
Street, Fifth Floor, Boston, MA 02110-1301, USA.
*/

namespace Google\Cloud\Storage\WordPress;

use Google\Auth\Credentials\GCECredentials;

if(file_exists(__DIR__ . '/vendor/autoload.php')){
  require_once __DIR__ . '/vendor/autoload.php';
}

$storageClient = new \Google\Cloud\Storage\StorageClient();
$storageClient->registerStreamWrapper();

define(__NAMESPACE__ . '\\PLUGIN_DIR', __DIR__);
define(__NAMESPACE__ . '\\PLUGIN_PATH', __FILE__);

/**
 * Render the options page.
 */
function options_page_view()
{
    // check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    } ?>
    <div class="wrap">
        <h1><?= esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="POST">
            <?php
            // output security fields for the registered setting "gcs_settings"
            settings_fields('gcs_settings');
    // output setting sections and their fields (sections are
    // registered for "gcs", each field is registered to a specific
    // section)
    do_settings_sections('gcs');
    // output save settings button
    submit_button(__('Save Settings', 'gcs')); ?>
        </form>
    </div>
    <?php
}

if( ! is_appengine() ){
  $config = array();

  if( ! getenv('GOOGLE_APPLICATION_CREDENTIALS') ) {
    $config['credentialsFetcher'] = new GCECredentials();
  }

  $storageClient = new \Google\Cloud\Storage\StorageClient($config);
  $storageClient->registerStreamWrapper();
}

define(__NAMESPACE__ . '\\PLUGIN_DIR', __DIR__);
define(__NAMESPACE__ . '\\PLUGIN_PATH', __FILE__);

/**
 * Callback for registering the setting.
 */
function register_settings()
{
    do_action('gcs_register_settings');
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activation_hook');
add_action('admin_init', __NAMESPACE__ . '\\register_settings');

if(!class_exists('Google\Cloud\Storage\WordPress\Uploads')) {
    require_once(__DIR__ . '/src/Uploads.php');
}
if(!class_exists('Google\Cloud\Storage\WordPress\Images')) {
    require_once(__DIR__ . '/src/Images.php');
}

Uploads::bootstrap_settings();
Images::bootstrap_settings();

Uploads::bootstrap();

if( get_option(Images::ENABLED_OPTION, false) ){
    Images::bootstrap();
}

function is_appengine() {
  return isset( $_SERVER['SERVER_SOFTWARE'] ) && strpos( $_SERVER['SERVER_SOFTWARE'], 'Google App Engine' ) !== false;
}

function storage_tools_available(){
	return class_exists('\google\appengine\api\cloud_storage\CloudStorageTools');
}
