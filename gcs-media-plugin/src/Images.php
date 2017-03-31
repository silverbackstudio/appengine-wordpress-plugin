<?php
namespace Google\Cloud\Storage\WordPress;

use google\appengine\api\cloud_storage\CloudStorageException;
use google\appengine\api\cloud_storage\CloudStorageTools;

defined('ABSPATH') or die('No direct access!');

class Images {

	const ENABLED_OPTION = 'gcs_media_images_enabled';
	const SERVICE_URL_OPTION = 'gcs_media_images_service_url';
	const QUALITY_OPTION = 'gcs_media_image_quality';

	public static function bootstrap(){
		add_filter( 'image_downsize', array(__CLASS__, 'get_intermediate_url'), 100, 3 );
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		add_filter( 'delete_attachment', array(__CLASS__, 'delete_attachment_serving_image'), 10, 1 );
		add_filter( 'wp_get_attachment_image_attributes', array(__CLASS__, 'attachment_image_srcset'), 10, 3);		
	}

	public static function bootstrap_settings(){
		add_action( 'gcs_register_settings', array(__CLASS__, 'settings_api_init') );
	}

	public static function settings_api_init() {
	
		add_settings_section(
			'gcs_media',
			__('Google Cloud Storage', 'gcs'),
			array(__CLASS__, 'setting_section_callback_function'),
			'media'
		);
		
		add_settings_field(
			self::ENABLED_OPTION,
			__('Enable GCS Image Service', 'gcs'),
			array(__CLASS__, 'setting_enabled_callback_function'),
			'media',
			'gcs_media'
	    );		
	
		register_setting( 'media', self::ENABLED_OPTION );
	
		if( ! self::is_direct_api_access_available() ){
			
			add_settings_field(
				self::SERVICE_URL_OPTION,
				__('GCS Image Service URL', 'gcs'),
				array(__CLASS__, 'setting_url_callback_function'),
				'media',
				'gcs_media'
			);
	
			register_setting( 'media', self::SERVICE_URL_OPTION );
	 	}
	
		add_settings_field(
			self::QUALITY_OPTION,
			__('GCS Image Service Quality', 'gcs'),
			array(__CLASS__, 'setting_quality_callback_function'),
			'media',
			'gcs_media'
	    );
	
		register_setting( 'media', self::QUALITY_OPTION );
	
	}
	
	
	public static function setting_section_callback_function() {
		echo '<p>'.__('Integrates WP with Google Cloud Storage', 'gcs').'</p>';
	}
	
	public static function setting_enabled_callback_function() {
        echo sprintf(
            '<input id="%1$s" name="%1$s" type="checkbox" %2$s />',
            self::ENABLED_OPTION,
            checked( (bool) get_option( self::ENABLED_OPTION ), true, false)
        );		
        echo '<p class="description">'. __( 'Enable GCP Image Serving Service', 'gcs') . '</p>';	 	
	}
	
	public static function setting_url_callback_function() {
	 	echo '<input name="'.esc_attr( self::SERVICE_URL_OPTION ).'" id="gcs_media_service_url" type="text" value="'.esc_attr( get_option( self::SERVICE_URL_OPTION ) ).'" class="code" placeholder="image-dot-projectname.appspot.com" /> Eg. [service]-dot-[project].appspot.com';
	}
	
	public static function setting_quality_callback_function() {
	 	echo '<input name="'.esc_attr( self::QUALITY_OPTION ).'" id="gcs_media_service_quality" type="number" min="1" max="100" value="'.esc_attr( get_option( self::QUALITY_OPTION, 90) ).'" class="code" placeholder="" />';
	}
	
	public static function get_intermediate_url( $data, $id, $size ) {
	
		$baseurl = self::get_attachment_serving_url($id);
	
		if(!$baseurl) {
			remove_filter( 'image_downsize', array(__CLASS__, 'get_intermediate_url'), 100 );
			$data = image_downsize( $id, $size );
			add_filter( 'image_downsize', array(__CLASS__, 'get_intermediate_url'), 100, 3 );
			return $data;
		}
	
		$sizes = self::image_sizes();
	
		if ( is_array( $size ) ) {
			$sizeParams = ['width' => $size[0], 'height' => $size[1], 'crop' => false];
		} else {
			$sizeParams = $sizes[ $size ];
		}
	
		$metadata  = wp_get_attachment_metadata($id);
		list($width, $height) = wp_constrain_dimensions($metadata['width'], $metadata['height'], $sizeParams['width'], $sizeParams['height']);
	
		$intermediate = !(($width === $metadata['width']) && ($height === $metadata['height']));
	
		$url = self::resize_serving_url( $baseurl, $intermediate ? $sizeParams : $metadata );
	
		if($intermediate) {
			$width = $sizeParams['width'];
			$height = $sizeParams['height'];
		}
	
	    return [$url, $width, $height, $intermediate];
	}
	
	public static function is_direct_api_access_available(){
		return class_exists('\google\appengine\api\cloud_storage\CloudStorageTools');
	}
	
	public static function get_attachment_serving_url($id){
	
		$file = get_attached_file( $id );
	
		if ( !in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/gif']) ) {
			return false;
		}
	
		$baseurl     = get_post_meta( $id, '_appengine_imageurl', true );
		$cached_file = get_post_meta( $id, '_appengine_imageurl_file', true );
	
		$secure_urls = get_option(Uploads::USE_HTTPS_OPTION, false);
	
		if ( empty( $baseurl ) && get_option(self::SERVICE_URL_OPTION) ) {
	
			$bucket = '';	$gs_object = '';
	
			if(self::is_direct_api_access_available() && CloudStorageTools::parseFilename($file, $bucket, $gs_object)){
				$baseurl = CloudStorageTools::getImageServingUrl($file, ['secure_url' => $secure_urls]);
			} elseif ( get_option(self::SERVICE_URL_OPTION) ) {
				$response_raw = wp_remote_request( self::get_image_service_url($file), array('method'=>'GET') );
				$response  = json_decode( wp_remote_retrieve_body($response_raw), true );
				$baseurl = isset( $response['serving_url'] ) ? $response['serving_url'] : false;
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
	
	public static function delete_attachment_serving_image($attachment_id) {
		$file = get_attached_file( $attachment_id );
	
		wp_remote_request( self::get_image_service_url($file), array('method'=>'DELETE') );
	}
	
	public static function get_image_service_url($file){
	    $filename = str_replace( 'gs://', '', $file );
	
	    return trailingslashit( get_option(self::SERVICE_URL_OPTION) ).$filename;
	}
	
	public static function resize_serving_url($url, $p) {
	
		$defaults = array(
			'width'=>'',
			'height'=>'',
			'crop'=>'',
			'quality'=> get_option(self::QUALITY_OPTION), //1-100
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
	
	public static function image_sizes() {
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
	
	public static function attachment_image_srcset($attr, $attachment, $size){
	
		$baseurl = self::get_attachment_serving_url($attachment->ID);
	
		if(!$baseurl){
			return $attr;
		}
	
		$ratios = [0.25, 0.5, 1, 2];
	
		$srcset = '';
		$sizes = self::image_sizes();
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
			$resizedImg = self::resize_serving_url($baseurl, array('width' =>  $width, 'height' => $height, 'crop' => $sizeParams['crop']) );
	    	$srcset .= str_replace( ' ', '%20', $resizedImg ) . ' ' . $width . 'w, ';
		}
	
		$attr['srcset'] = rtrim( $srcset, ', ' );
	
		return $attr;
	}

}