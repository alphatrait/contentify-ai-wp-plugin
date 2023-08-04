<?php
/*
Plugin Name: Contentify AI
Description: This plugin is a Contentify Editor AI (Co-Editor). It publishes and optimize the content that Contentify Writer AI (Co-Writer) generates.
Version: 1.2.0
Author: Contentify Team
Text Domain: contentify-ai
*/

defined('ABSPATH') or die;

define('CONTENTIFY_AI_FILE', __FILE__);
define('CONTENTIFY_AI_VER', '1.0.0');

if (!class_exists('Contentify_AI_Class')) {
	class Contentify_AI_Class
	{
		public static function get_instance()
		{
			if (self::$instance == null) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private static $instance = null;

		private function __clone()
		{
		}

		public function __wakeup()
		{
		}

		private function __construct()
		{
			// Init
			add_action('init', array($this, 'init'));

			// REST API Routes
			add_action('rest_api_init', array($this, 'create_api_key_authentication'));
			add_action('rest_api_init', array($this, 'create_get_website_title_endpoint'));

			// Register the plugin's settings page
			add_action('admin_menu', array($this, 'contentify_settings'));

			// Register the plugin's settings
			add_action('admin_init', array($this, 'register_contentify_settings'));

			// New endpoint to get all the blogs
			add_action('rest_api_init', array($this, 'get_all_blogs_endpoint'));

			// Update an existing post
			add_action('rest_api_init', array($this, 'update_blog_endpoint'));

		}

		public function init()
		{
			load_plugin_textdomain('contentify-ai', false, dirname(plugin_basename(CONTENTIFY_AI_FILE)) . '/languages');
		}

		public function create_api_key_authentication()
		{
			register_rest_route(
				'contentify/v1',
				'/create-post/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'create_post_with_api_key'),
					'permission_callback' => '__return_true',
				)
			);
		}

		public function create_get_website_title_endpoint()
		{
			register_rest_route(
				'contentify/v1',
				'/website-title/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'get_website_title_with_api_key'),
					'permission_callback' => '__return_true',
				)
			);
		}

		// New endpoint to get all the blogs
		function get_all_blogs_endpoint()
		{
			register_rest_route('contentify/v1', '/all-blogs/', array(
				'methods' => 'POST',
				'callback' => array($this, 'get_all_blogs'),
				'permission_callback' => '__return_true',
			)
			);
		}

		// New endpoint to update an existing blog
		public function update_blog_endpoint()
		{
			register_rest_route(
				'contentify/v1',
				'/update-blog/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'update_blog'),
					'permission_callback' => '__return_true',
				)
			);
		}

		// Callback function to fetch all the blogs
		public function get_all_blogs($data)
		{
			// Get all published posts of post type 'post'
			$api_key = $data->get_param('api_key');

			// Validate the API key
			if (!$this->validate_api_key($api_key)) {
				return new WP_Error('invalid_api_key', __('The API key provided is invalid', 'contentify-ai'), array('status' => 401));
			}

			// Define args for WP_Query
			$args = array(
				'post_type' => 'post',
				'post_status' => 'publish',
				'posts_per_page' => -1 // Retrieve all posts
			);

			$query = new WP_Query($args);
			$blogs = array();
			if ($query->have_posts()) {
				while ($query->have_posts()) {
					$query->the_post();

					$blog_data = array(
						'ID' => get_the_ID(),
						'title' => get_the_title(),
						'content' => get_the_content(),
						'author' => get_the_author(),
						'status' => get_post_status(),
						'seo_title' => get_post_meta(get_the_ID(), '_yoast_wpseo_title', true),
						'seo_description' => get_post_meta(get_the_ID(), '_yoast_wpseo_metadesc', true),
						'keyword' => get_post_meta(get_the_ID(), '_yoast_wpseo_focuskw', true),
					);

					$blogs[] = $blog_data;
				}
				wp_reset_postdata();
			}

			return $blogs;
		}

		// Callback function to update an existing blog
		public function update_blog($data)
		{
			$post_id = $data->get_param('post_id');
			$api_key = $data->get_param('api_key');
			$title = $data->get_param('title');
			$content = $data->get_param('content');
			$status = $data->get_param('status');
			$keyword = $data->get_param('keyword');
			$seo_title = $data->get_param('seo_title');
			$seo_description = $data->get_param('seo_description');
			$category = $data->get_param('category');
			$image = $data->get_param('image');

			// Validate the API key
			if (!$this->validate_api_key($api_key)) {
				return new WP_Error('invalid_api_key', __('The API key provided is invalid', 'contentify-ai'), array('status' => 401));
			}

			// Check if the post exists
			$post = get_post($post_id);
			if (!$post) {
				return new WP_Error('post_not_found', __('The specified post ID does not exist', 'contentify-ai'), array('status' => 404));
			}

			// Get the user by login or email
			$user = get_user_by('login', $author);
			if (!$user) {
				$user = get_user_by('email', $author);
			}
			$author_id = $user->ID;

			// Validate post status
			$status = strtolower($status);
			if (!isset(get_post_statuses()[$status]))
				$status = 'publish';

			// Category
			$term_id = 0;
			if (!empty($category)) {
				$term = get_term_by('name', $category, 'category');
				if (!$term instanceof WP_Term) {
					$term = wp_insert_term($category, 'category');
					if (is_array($term))
						$term_id = $term['term_id'];
				} else {
					$term_id = $term->term_id;
				}
			}

			// Update the post
			$post_args = array(
				'ID' => $post_id,
				'post_title' => $title,
				'post_content' => $content,
				'post_status' => $status,
				'post_author' => $author_id,
				'post_category' => array($term_id)
			);
			wp_update_post($post_args);

			// Update Yoast SEO keyword, title, and description
			update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
			update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
			update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);

			if (!empty($image)) {
				$attachment_id = $this->cai_upload_from_url($image, $title);
				if ($attachment_id) {
					set_post_thumbnail($post_id, $attachment_id);
				}
			}

			// Return the updated post ID
			return array('updated_post_id' => $post_id);
		}



		public function get_website_title_with_api_key($data)
		{
			$api_key = $data->get_param('api_key');

			// Validate the API key
			if (!$this->validate_api_key($api_key)) {
				return new WP_Error('invalid_api_key', __('The API key provided is invalid', 'contentify-ai'), array('status' => 401));
			}

			// Use the WordPress function to get the current website title
			$title = get_option('blogname');

			// Return the title
			return array('website_title' => $title);
		}
		public function cai_upload_from_url($url, $title = null)
		{
			require_once(ABSPATH . '/wp-admin/includes/image.php');
			require_once(ABSPATH . '/wp-admin/includes/file.php');
			require_once(ABSPATH . '/wp-admin/includes/media.php');

			// Fetch the remote file
			$response = wp_remote_get($url);

			// Check for errors
			if (is_wp_error($response)) {
				return false;
			}

			// Get the filename and extension ("photo.png" => "photo", "png")
			$filename = pathinfo($url, PATHINFO_FILENAME);
			$extension = pathinfo($url, PATHINFO_EXTENSION);

			// Get the file contents
			$file_contents = wp_remote_retrieve_body($response);

			// Upload the file to the WordPress media library
			$upload = wp_upload_bits($filename . '.' . $extension, null, $file_contents);

			// Check for errors
			if ($upload['error']) {
				return false;
			}

			// Prepare the attachment
			$filetype = wp_check_filetype(basename($upload['file']), null);
			$attachment = array(
				'guid' => $upload['url'],
				'post_mime_type' => $filetype['type'],
				'post_title' => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
				'post_content' => '',
				'post_status' => 'inherit',
			);

			// Insert the attachment
			$attachment_id = wp_insert_attachment($attachment, $upload['file']);

			// Generate the metadata for the attachment
			$attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);

			// Update the attachment metadata
			wp_update_attachment_metadata($attachment_id, $attach_data);

			return (int) $attachment_id;
		}


		function create_post_with_api_key($data)
		{
			$author = $data->get_param('author');
			$api_key = $data->get_param('api_key');
			$title = $data->get_param('title');
			$status = $data->get_param('status');
			$content = $data->get_param('content');
			$keyword = $data->get_param('keyword');
			$seo_title = $data->get_param('seo_title');
			$seo_description = $data->get_param('seo_description');
			$category = $data->get_param('category');
			$image = $data->get_param('image');

			// Validate the API key
			if (!$this->validate_api_key($api_key)) {
				return new WP_Error('invalid_api_key', __('The API key provided is invalid', 'contentify-ai'), array('status' => 401));
			}

			// Get the user by login or email
			$user = get_user_by('login', $author);
			if (!$user) {
				$user = get_user_by('email', $author);
			}
			$author_id = $user->ID;

			// Validate post status
			$status = strtolower($status);
			if (!isset(get_post_statuses()[$status]))
				$status = 'publish';

			// Category
			$term_id = 0;

			if (!empty($category)) {
				$term = get_term_by('name', $category, 'category');
				if (!$term instanceof WP_Term) {
					$term = wp_insert_term($category, 'category');
					if (is_array($term))
						$term_id = $term['term_id'];
				} else {
					$term_id = $term->term_id;
				}
			}

			// Create the post
			$post_id = wp_insert_post(
				array(
					'post_title' => $title,
					'post_content' => $content,
					'post_status' => $status,
					'post_author' => $author_id,
					'post_category' => array($term_id)
				)
			);


			//Update Yoast SEO keyword, title, and description
			update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
			update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
			update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);

			if (!empty($image)) {
				$attachment_id = $this->cai_upload_from_url($image, $title);
				if ($attachment_id) {
					set_post_thumbnail($post_id, $attachment_id);
				}
			}

			// Return the post ID
			return array('post_id' => $post_id);
		}


		function contentify_settings_page()
		{
			// Check if the user has the necessary permissions
			if (!current_user_can('manage_options')) {
				return;
			}
			?>
			<div class="wrap">
				<h1>
					<?php echo esc_html(get_admin_page_title()); ?>
				</h1>
				<form method="post" action="options.php">
					<?php
					// Output nonce, action, and option_page fields for a settings page
					settings_fields('contentify_settings');
					// Output settings sections and their fields
					do_settings_sections('contentify_settings');
					submit_button();
					?>
				</form>
			</div>
			<?php
		}

		public function contentify_settings()
		{
			add_options_page(
				__('Contentify Settings', 'contentify-ai'),
				__('Contentify AI', 'contentify-ai'),
				'manage_options',
				'contentify_settings',
				array($this, 'contentify_settings_page')
			);
		}

		public function contentify_settings_section_callback()
		{
			echo __('Please enter your Contentify API key to allow the AI to post and optimize your blogs', 'contentify-ai');
		}


		public function register_contentify_settings()
		{
			register_setting('contentify_settings', 'contentify_api_key');
			add_settings_section(
				'contentify_settings_section',
				__('Contentify API Key', 'contentify-ai'),
				array($this, 'contentify_settings_section_callback'),
				'contentify_settings'
			);
			add_settings_field(
				'contentify_api_key',
				__('API Key', 'contentify-ai'),
				array($this, 'contentify_api_key_callback'),
				'contentify_settings',
				'contentify_settings_section'
			);
		}

		// Display the API key form field
		public function contentify_api_key_callback()
		{
			$api_key = get_option('contentify_api_key');
			echo "<input type='password' name='contentify_api_key' value='" . esc_attr($api_key) . "'/>";
		}


		public function validate_api_key($api_key)
		{
			// Get the user-defined API key
			$valid_api_key = get_option('contentify_api_key');
			if (!$api_key) {
				return false;
			}
			return $api_key === $valid_api_key;
		}
	}


	Contentify_AI_Class::get_instance();
}