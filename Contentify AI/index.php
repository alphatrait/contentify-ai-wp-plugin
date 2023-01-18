<?php
/**
 * Plugin Name: Contentify AI
 * Description: Allows Co-Writer to write blogs and optimize the posts
 * Version: 1.0
 * Author: Contentify Team
 */

add_action( 'rest_api_init', 'create_api_key_authentication' );
function create_api_key_authentication() {
    register_rest_route( 'contentify/v1', '/create-post/', array(
        'methods' => 'POST',
        'callback' => 'create_post_with_api_key',
    ) );
}

function create_post_with_api_key( $data ) {
    $author = $data->get_param( 'author' );
    $api_key = $data->get_param( 'api_key' );
    $title = $data->get_param( 'title' );
    $status = $data->get_param( 'status' );
    $content = $data->get_param( 'content' );
    $keyword = $data->get_param( 'keyword' );
    $seo_title = $data->get_param( 'seo_title' );
    $seo_description = $data->get_param( 'seo_description' );

    // Validate the API key
    if ( !validate_api_key( $api_key ) ) {
        return new WP_Error( 'invalid_api_key', 'The API key provided is invalid', array( 'status' => 401 ) );
    }

    // Get the user by login or email
    $user = get_user_by('login', $author);
    if (!$user) {
        $user = get_user_by('email', $author);
    }
    $author_id = $user->ID;


    // Create the post
    $post_id = wp_insert_post( array(
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => $status,
        'post_author' => $author_id,
    ) );


    //Update Yoast SEO keyword, title, and description
    update_post_meta($post_id, '_yoast_wpseo_focuskw', $keyword);
    update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
    update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_description);

    // Return the post ID
    return array( 'post_id' => $post_id );
}

function contentify_settings_page() {
    // Check if the user has the necessary permissions
    if ( !current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form method="post" action="options.php">
            <?php
            // Output nonce, action, and option_page fields for a settings page
            settings_fields( 'contentify_settings' );
            // Output settings sections and their fields
            do_settings_sections( 'contentify_settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register the plugin's settings page
add_action( 'admin_menu', 'contentify_settings' );
function contentify_settings() {
    add_options_page(
        'Contentify Settings',
        'Contentify AI',
        'manage_options',
        'contentify_settings',
        'contentify_settings_page'
    );
}

// Register the plugin's settings
add_action( 'admin_init', 'register_contentify_settings' );
function register_contentify_settings() {
    register_setting( 'contentify_settings', 'contentify_api_key' );
    add_settings_section(
        'contentify_settings_section',
        'Please enter your Contentify API key to allow the AI to post and optimize your blogs',
        'contentify_settings_section_callback',
        'contentify_settings'
    );
    add_settings_field(
        'contentify_api_key',
        'API Key',
        'contentify_api_key_callback',
        'contentify_settings',
        'contentify_settings_section'
    );
}

// Display the API key form field
function contentify_api_key_callback() {
    $api_key = get_option( 'contentify_api_key' );
    ?>
    <input type="password" name="contentify_api_key" value="<?php echo esc_attr( $api_key ); ?>">
    <?php
}

function validate_api_key( $api_key ) {
    // Get the user-defined API key
    $valid_api_key = get_option( 'contentify_api_key' );
    if(!$api_key){
        return false;
    }
    return $api_key === $valid_api_key;
}
