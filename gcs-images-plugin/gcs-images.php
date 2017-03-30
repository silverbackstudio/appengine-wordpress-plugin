<?php
/**
 * @package WP GCS images
 * @version 0.1
 */
/*
Plugin Name: Google Cloud Storage Images
Plugin URI: https://github.com/silverbackstudio/wp-gcs-images
Description: Use Google Image Servers for image resizing.
Author: Silverback Studio
Version: 0.1
Author URI: https://www.silverbackstudio.com/
*/

namespace Svbk\WP\Plugins\GCS\Images;

use google\appengine\api\cloud_storage\CloudStorageException;
use google\appengine\api\cloud_storage\CloudStorageTools;

defined('ABSPATH') or die('No direct access!');

add_filter( 'image_downsize', __NAMESPACE__.'\\get_intermediate_url', 100, 3 );
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
add_filter( 'delete_attachment', __NAMESPACE__.'\\delete_attachment_serving_image', 10, 1 );
add_action( 'admin_init', __NAMESPACE__.'\\settings_api_init' );
add_filter( 'wp_get_attachment_image_attributes', __NAMESPACE__.'\\attachment_image_srcset', 10, 3);

function settings_api_init() {

	 add_settings_section(
		'wp_gcs_images',
		'Google Cloud Storage',
		__NAMESPACE__.'\\setting_section_callback_function',
		'media'
	);

	if(!is_direct_api_access_available()){
	 add_settings_field(
		'wp_gcs_images_service_url',
		'GCS Image Service URL',
		__NAMESPACE__.'\\setting_url_callback_function',
		'media',
		'wp_gcs_images'
	);

	 register_setting( 'media', 'wp_gcs_images_service_url' );
 	}

	add_settings_field(
	 'wp_gcs_images_service_quality',
	 'GCS Image Service Quality',
	 __NAMESPACE__.'\\setting_quality_callback_function',
	 'media',
	 'wp_gcs_images'
 );

	register_setting( 'media', 'wp_gcs_images_service_url' );
	register_setting( 'media', 'wp_gcs_images_service_quality' );

}


function setting_section_callback_function() {
	echo '<p>Integrates WP with Google Cloud Storage</p>';
}

function setting_url_callback_function() {
 	echo '<input name="wp_gcs_images_service_url" id="wp_gcs_images_service_url" type="text" value="'.esc_attr(get_option( 'wp_gcs_images_service_url' )).'" class="code" placeholder="image-dot-projectname.appspot.com" /> Eg. [service]-dot-[project].appspot.com';
}

function setting_quality_callback_function() {
 	echo '<input name="wp_gcs_images_service_quality" id="wp_gcs_images_service_quality" type="number" min="1" max="100" value="'.esc_attr( get_option( 'wp_gcs_images_service_quality', 90) ).'" class="code" placeholder="" />';
}

function get_intermediate_url( $data, $id, $size ) {

	$baseurl = get_attachment_serving_url($id);

	if(!$baseurl) {
		remove_filter( 'image_downsize', __NAMESPACE__.'\\get_intermediate_url', 100 );
		$data = image_downsize( $id, $size );
		add_filter( 'image_downsize', __NAMESPACE__.'\\get_intermediate_url', 100, 3 );
		return $data;
	}

	$sizes = image_sizes();

	if ( is_array( $size ) ) {
		$sizeParams = ['width' => $size[0], 'height' => $size[1], 'crop' => false];
	} else {
		$sizeParams = $sizes[ $size ];
	}

	$metadata  = wp_get_attachment_metadata($id);
	list($width, $height) = wp_constrain_dimensions($metadata['width'], $metadata['height'], $sizeParams['width'], $sizeParams['height']);

	$intermediate = !(($width === $metadata['width']) && ($height === $metadata['height']));

	$url = resize_serving_url( $baseurl, $intermediate ? $sizeParams : $metadata );

	if($intermediate) {
		$width = $sizeParams['width'];
		$height = $sizeParams['height'];
	}

    return [$url, $width, $height, $intermediate];
}

function is_direct_api_access_available(){
	return class_exists('\google\appengine\api\cloud_storage\CloudStorageTools');
}

function get_attachment_serving_url($id){

	$file = get_attached_file( $id );

	if ( !in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/gif']) ) {
		return false;
	}

	$baseurl     = get_post_meta( $id, '_appengine_imageurl', true );
	$cached_file = get_post_meta( $id, '_appengine_imageurl_file', true );

	$secure_urls =  true;

	if ( empty( $baseurl ) && get_option('wp_gcs_images_service_url') ) {

		$bucket = '';	$gs_object = '';

		if(is_direct_api_access_available() && CloudStorageTools::parseFilename($file, $bucket, $gs_object)){
			$baseurl = CloudStorageTools::getImageServingUrl($file, ['secure_url' => $secure_urls]);
		} elseif ( get_option('wp_gcs_images_service_url') ) {
			$response_raw = wp_remote_request( get_image_service_url($file), array('method'=>'GET') );
			$response  = json_decode(wp_remote_retrieve_body($response_raw), true);
			$baseurl = isset($response['serving_url'])?$response['serving_url']:false;
		} else {
			$baseurl = false;
		}
		update_post_meta( $id, '_appengine_imageurl', $baseurl );
		update_post_meta( $id, '_appengine_imageurl_file', $file );
	}

	if ($secure_urls) {
		$baseurl = set_url_scheme($baseurl, 'https');
	}

	return $baseurl;
}

function delete_attachment_serving_image($attachment_id) {
	$file = get_attached_file( $attachment_id );

	wp_remote_request( get_image_service_url($file), array('method'=>'DELETE'));
}

function get_image_service_url($file){
    $filename = str_replace( 'gs://', '', $file );

    return trailingslashit(get_option('wp_gcs_images_service_url')).$filename;
}

function resize_serving_url($url, $p) {

	$defaults = array(
		'width'=>'',
		'height'=>'',
		'crop'=>'',
		'quality'=> get_option('wp_gcs_images_service_quality'), //1-100
		'stretch'=>false
	);

	$p = array_merge($defaults, $p);

	$params = array();

	if($p['width'] && $p['height']){
		$params[]= 'w'.$p['width'];
		$params[]= 'h'.$p['height'];
	} elseif($p['height']) {
		$params[]= 'h'.$p['height'];
	} elseif($p['width']) {
		$params[]= 'w'.$p['width'];
	}	else {
		$params[] = 's0';
	}

	if($p['crop']){
		$params[] = 'p';
	}

	if($p['quality']){
	$params[] = 'l'.$p['quality'];
	}

	if(!$p['stretch']){
	$params[] = 'nu';
	}

	return $url.'='.join('-', $params);
}

function image_sizes() {
	static $images_sizes = array();

	if (!empty($image_sizes) ) {
		return $image_sizes;
	}

	$default_image_sizes = array( 'thumbnail', 'medium', 'large' );

	$images = array();

	foreach ( $default_image_sizes as $size ) {
		$image[$size]['width']	= intval( get_option( "{$size}_size_w") );
		$image[$size]['height'] = intval( get_option( "{$size}_size_h") );
		$image[$size]['crop']	= get_option( "{$size}_crop" ) ? get_option( "{$size}_crop" ) : false;
	}

	$image_sizes = array_merge( $images, wp_get_additional_image_sizes() );

	return $image_sizes;
}

function attachment_image_srcset($attr, $attachment, $size){

	$baseurl = get_attachment_serving_url($attachment->ID);

	if(!$baseurl){
		return $attr;
	}

	$ratios = [0.25, 0.5, 1, 2];

	$srcset = '';
  $sizes = image_sizes();
	$metadata = wp_get_attachment_metadata($attachment->ID);

	if($size === 'full'){
		$sizeParams = ['width' => $metadata['width'], 'height' => $metadata['height'], 'crop' => false];
	} elseif ( is_array( $size ) ) {
    $sizeParams = ['width' => $size[0], 'height' => $size[1], 'crop' => false];
  } else {
    $sizeParams = $sizes[ $size ];
  }

  foreach($ratios as $key=>$ratio) {
	  list($width, $height) = wp_constrain_dimensions($metadata['width'], $metadata['height'], ceil($sizeParams['width'] * $ratio), ceil($sizeParams['height'] * $ratio) );
		$resizedImg = resize_serving_url($baseurl, array('width' =>  $width, 'height' => $height, 'crop' => $sizeParams['crop']) );
    $srcset .= str_replace( ' ', '%20', $resizedImg ) . ' ' . $width . 'w, ';
  }

	$attr['srcset'] = rtrim( $srcset, ', ' );

	return $attr;
}
