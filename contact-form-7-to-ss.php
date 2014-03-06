<?php
/*
	Plugin Name: Contact Form 7 to SpreadSheet
	Plugin URI: https://github.com/miyakeryo/contact-form-7-to-ss
	Description: Save form submissions to the SpreadSheet from <a href='http://wordpress.org/extend/plugins/contact-form-7/'>Contact Form 7</a>. |<a href='options-general.php?page=contact-form-7-to-ss/contact-form-7-to-ss.php'>Settings</a>|
	Version: 1.0
	Author: Ryo Miyake
	Author URI: http://miyakeryo.com
	License: GPLv2
	Text Domain: contact-form-7-to-ss
	Domain Path: /languages
*/

class wpcf7_SpreadSheet {

	public $option_name; // wpcf7_spreadsheet_options
	public $plugin_name = 'Contact Form 7 to SpreadSheet';
	public $textdomain = 'contact-form-7-to-ss';
	public $nonceName = '_wpnonce';

	public $dirname, $basename, $dir_url;
	public $admin_action;

	public $skip_post = false;
	public $postdata = null;

	protected $defaults = array(
		'forms' => array(),
	);
	protected $form_defaults = array(
		'app_url' => '',
		'disable_email' => 0,
		'get_ip_address' => 0,
		'get_host_name' => 0,
		'get_referer' => 0,
		'get_user_agent' => 0,
	);

	protected $formdata = array();

	function __construct() {
		include 'config.php';
		$this->option_name = $wpcf7ss_config['option_name'];

		$this->dirname = basename(dirname(__FILE__));
		$this->basename = plugin_basename(__FILE__);
		$this->dir_url = plugins_url($this->dirname);
		$this->admin_action = admin_url('admin.php?page=' . $this->basename);

		load_plugin_textdomain($this->textdomain, false, $this->dirname . '/languages/');

		add_action('wpcf7_before_send_mail', array(&$this, 'saveFormData'));
		if ( is_admin() ) {
			add_action('admin_menu', array(&$this, 'admin_menu'));
			add_filter('plugin_action_links', array(&$this, 'action_links'), 10, 2 );
		}
	}

	public function saveFormData($cf7) {

		$options = $this->_getOptions();

		$option = $options['forms'][$cf7->id];
		
		if ( ! empty($option) ) {
			$option = array_merge($this->form_defaults, $option);
			$app_url = $option['app_url'];
			$this->postdata = array();

			foreach ($cf7->posted_data as $key => $value) {
				if ( strpos($key, '_wp') !== 0 ) {
					if ( is_array($value) ) {
						$this->postdata[$key] = implode(',', $value);
					} else {
						$this->postdata[$key] = $value;
					}
				}
			}
			if ( !isset($this->postdata['ip_address']) && $option['get_ip_address'] ) {
				$this->postdata['ip_address'] = $_SERVER['REMOTE_ADDR'];
			}
			if ( !isset($this->postdata['host_name']) && $option['get_host_name'] ) {
				$this->postdata['host_name'] = $_SERVER['REMOTE_HOST'];
			}
			if ( !isset($this->postdata['referer']) && $option['get_referer'] ) {
				$this->postdata['referer'] = $_SERVER['HTTP_REFERER'];
			}
			if ( !isset($this->postdata['user_agent']) && $option['get_user_agent'] ) {
				$this->postdata['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
			}

			do_action_ref_array( 'wpcf7ss_before_post', array(&$this) );

			if ( !$this->skip_post ) {
				//送信
				$context = stream_context_create(
					array(
						'http' => array(
							'method' => 'POST',
							'header' => implode("\r\n", array(
								'Content-Type: application/x-www-form-urlencoded',
							)),
							'content' => http_build_query($this->postdata),
						)
					)
				);
				$res = file_get_contents($app_url, FILE_USE_INCLUDE_PATH, $context);
			}

			$cf7->skip_mail = !!$option['disable_email'];
		}
	}

	public function admin_menu() {
		add_options_page( $this->plugin_name, $this->plugin_name, 'manage_options', __FILE__, array(&$this, 'options_page'));
		add_action('admin_head',  array(&$this, 'add_admin_style'));
	}

	public function action_links($links, $file) {
		if ($file == $this->basename) {
			$settings_link = '<a href="' . $this->admin_action . '">' . __('Settings') . '</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	public function add_admin_style() {
		echo '<link rel="stylesheet" type="text/css" href="' . $this->dir_url . '/css/style.css" />';
	}

	public function options_page() {
		$out = '';
		$nonce = wp_create_nonce();
		$selected_cf7 = $_POST['cf7_select'];
		unset($_POST['cf7_select']);

		$options = $this->_getOptions();

		if ( ! empty($_POST) ) {
			if ( $this->_validatePost() ) {
				$selected_cf7 = $this->formdata['cf7_id'];
				$options['forms'][$selected_cf7] = $this->formdata;
				update_option($this->option_name, $options);
				$this->message = array(
					'class' => 'updated',
					'text' => __('Settings saved.', $this->textdomain),
				);
			}
		}

		$cf7posts = get_posts( array( 'post_type' => 'wpcf7_contact_form' ) );
		if ( count($cf7posts) == 0 ){
			$this->message = array(
				'class' => 'notfound',
				'text' => __('Contact Form is not found.', $this->textdomain),
			);
		}

		$out .= '<div class="wrap" id="contact-form-7-to-spreadsheet-option">';

		$out .= '<div id="icon-options-general" class="icon32"><br /></div>';
		$out .= '<h2>Contact Form 7 to SpreadSheet Options</h2>';
		$out .= '<p>' . __('Save form submissions to the SpreadSheet from Contact Form 7.', $this->textdomain) . '</p>';
		$out .= '<a href="http://miyakeryo.com/?p=771" target="_blank" style="display:block;float:right;">' . __("How to make ''Google Apps Script Web Application''", $this->textdomain) . '</a>';

		if ( !empty($this->message) ) {
			$out .= $this->_getMessage();
		}

		if ( count($cf7posts) > 0 ){
			$out .= '<p>';
			$out .= '<form method="post" name="cf7_select_form" action="' . $this->admin_action . '">'."\n";
			$out .= '<select name="cf7_select" onchange="javascript:document.cf7_select_form.submit();" >';
			$out .= '<option value="0">'. __('Select Contact Form', $this->textdomain) .'</option>';

			$cf7_id = 0;
			foreach ($cf7posts as $cf7post) {
				if ( $selected_cf7 == $cf7post->ID ){
					$selected = ' selected="selected"';
					$cf7_id = $cf7post->ID;
				}else{
					$selected = '';
				}
				$out .= '<option value="'. $cf7post->ID .'"'. $selected .'>'. $cf7post->post_title .'</option>';
			}
			$out .= '</select>';
			$out .= '</form>';
			$out .= '</p>';

			if ( !empty($cf7_id) ) {
				
				if ( !empty($options['forms'][$cf7_id]) ) {
					$option = $options['forms'][$cf7_id];
				} else {
					$option = array();
				}
				$option = array_merge($this->form_defaults, $option);

				$app_url = $option['app_url'];
				$disable_email = $option['disable_email'];
				$get_ip_address = $option['get_ip_address'];
				$get_host_name = $option['get_host_name'];
				$get_referer = $option['get_referer'];
				$get_user_agent = $option['get_user_agent'];

				$out .= '<p>';
				$out .= '<form method="post" id="update_options" action="' . $this->admin_action . '">'."\n";

				$out .= '<input type="hidden" name="cf7_id" value="'. $cf7_id .'" />';

				$out .= wp_nonce_field(-1, $this->nonceName, true, false);

				$out .= '<p>'. __('Google Apps Script Web Application URL', $this->textdomain) .'</p>';
				$out .= '<p>';
				$out .= '<input type="url" name="app_url" value="'. $app_url .'" style="width:600px;" />';
				$out .= '</p>';

				$checked = empty($disable_email) ? '' : ' checked="checked"';
				$out .= '<p>';
				$out .= '<input type="checkbox" name="disable_email" id="disable_email" value="1"' . $checked . ' />';
				$out .= '<label for="disable_email"> ' . __('Disable Email', $this->textdomain) . '</label>';
				$out .= '</p>';

				$checked = empty($get_ip_address) ? '' : ' checked="checked"';
				$out .= '<p>';
				$out .= '<input type="checkbox" name="get_ip_address" id="get_ip_address" value="1"' . $checked . ' />';
				$out .= '<label for="get_ip_address"> ' . __('Get IP Address', $this->textdomain) . '</label>';
				$out .= '</p>';

				$checked = empty($get_host_name) ? '' : ' checked="checked"';
				$out .= '<p>';
				$out .= '<input type="checkbox" name="get_host_name" id="get_host_name" value="1"' . $checked . ' />';
				$out .= '<label for="get_host_name"> ' . __('Get Host Name', $this->textdomain) . '</label>';
				$out .= '</p>';

				$checked = empty($get_referer) ? '' : ' checked="checked"';
				$out .= '<p>';
				$out .= '<input type="checkbox" name="get_referer" id="get_referer" value="1"' . $checked . ' />';
				$out .= '<label for="get_referer"> ' . __('Get Referer', $this->textdomain) . '</label>';
				$out .= '</p>';

				$checked = empty($get_user_agent) ? '' : ' checked="checked"';
				$out .= '<p>';
				$out .= '<input type="checkbox" name="get_user_agent" id="get_user_agent" value="1"' . $checked . ' />';
				$out .= '<label for="get_user_agent"> ' . __('Get User Agent', $this->textdomain) . '</label>';
				$out .= '</p>';

				$out .= '<p>';
				$out .= '<input type="submit" class="button button-primary" value="' . __('Save Changes', $this->textdomain) . '" />';
				$out .= '</p>';
				
				$out .= '</form>';
				$out .= '</p>';
			} else {
				// not select Contact Form 7
			}
		}

		echo $out;

		//var_dump($options);
	}

	protected function _validatePost() {
		if ( empty($_POST) || ! $this->_validateFormData($_POST) || ! $this->_verifyNonce() ) {
			$this->_setError(__('Invalid post.', $this->textdomain));
			return false;
		}

		if ( !$this->_hasGASWebAppURL($_POST) ) {
			$this->_setError(__('Please enter "Google Apps Script Web Application URL".', $this->textdomain));
			return false;
		}

		return true;
	}

	protected function _hasGASWebAppURL($data) {
		if ( ! empty($data['app_url']) ) {
			$this->formdata['app_url'] = $data['app_url'];
			return true;
		}
		return false;
	}

	protected function _validateFormData($data) {
		if ( isset($data['cf7_id']) ) {
			$this->formdata['cf7_id'] = $data['cf7_id'];
			$names = array('disable_email','get_ip_address','get_host_name','get_referer','get_user_agent');
			foreach ($names as $name) {
				if ( !isset($data[$name]) || empty($data[$name]) ) {
					$this->formdata[$name] = 0;
				} else {
					$this->formdata[$name] = 1;
				}
			}
			return true;
		}
		return false;
	}

	protected function _setError($text) {
		$this->message = array(
			'class' => 'error',
			'text' => $text,
		);
	}

	protected function _verifyNonce() {
		if ( ! empty($_POST[$this->nonceName]) ) {
			if ( wp_verify_nonce($_POST[$this->nonceName]) ) {
				return true;
			}
		}
		return false;
	}

	protected function _getOptions() {
		$options = get_option($this->option_name);
		return $this->_mergeDefaults($options, $options);
	}

	protected function _mergeDefaults($data) {
		if ( ! is_array($data) ) {
			$data = array();
		}
		return array_merge($this->defaults, $data);
	}

	protected function _getMessage() {
		if ( empty($this->message) ) {
			return false;
		}

		if ( is_string($this->message) ) {
			$class = 'updated';
			$text = $this->message;
		} else {
			if ( empty($this->message['text']) ) {
				return false;
			}
			$text = $this->message['text'];

			if ( empty($this->message['class']) ) {
				$class = 'updated';
			} else {
				$class = $this->message['class'];
			}
		}

		return '<div id="message" class="' . $class . '"><p>' . $text . '</p></div>';
	}

}// end of 'class wpcf7_SpreadSheet'

$wpcf7_SpreadSheet = new wpcf7_SpreadSheet();
