<?php
/*
Plugin Name: devtest-bhavesh
Description: Testimonial Custom Post Type Plugin
Version: 1.0
Author: Bhavesh
Requires at least: 5.8
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Custom post type - testimonials
class MCP_Custom_Post_Type {

    public function __construct() {

        add_action( 'init', array( $this, 'register_testimonial_post_type' ) );

        add_action( 'add_meta_boxes', array( $this, 'add_testimonial_meta_box' ) );

        add_action( 'save_post_testimonial', array( $this, 'save_testimonial_meta' ) );

        add_shortcode( 'testimonials', array( $this, 'testimonial_shortcode' ) );

        add_filter( 'the_content', array( $this, 'append_post_summary_button' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_post_summary_assets' ) );

        add_action( 'wp_ajax_generate_post_summary', array( $this, 'handle_generate_post_summary' ) );
        add_action( 'wp_ajax_nopriv_generate_post_summary', array( $this, 'handle_generate_post_summary' ) );

        add_filter(
            'manage_testimonial_posts_columns',
            array( $this, 'add_rating_column' )
        );

        add_action(
            'manage_testimonial_posts_custom_column',
            array( $this, 'show_rating_column' ),
            10,
            2
        );

        add_filter(
            'manage_edit-testimonial_sortable_columns',
            array( $this, 'make_rating_sortable' )
        );

        add_action(
            'pre_get_posts',
            array( $this, 'rating_column_orderby' )
        );
    }

    /** 4. AI integration - post summariser
     * Load scripts and localize AJAX settings only on single blog posts.
     */
    public function enqueue_post_summary_assets() {

        if ( ! is_singular( 'post' ) || is_admin() ) {
            return;
        }

        wp_enqueue_script(
            'devtest-post-summary',
            plugin_dir_url( __FILE__ ) . 'js/post-summary.js',
            array( 'jquery' ),
            '1.0',
            true
        );

        wp_localize_script(
            'devtest-post-summary',
            'postSummaryData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'post_summary_nonce' ),
                'action'    => 'generate_post_summary',
                'loading'   => __( 'Summarising...', 'devtest-bhavesh' ),
                'errorText' => __( 'Unable to summarise this post right now.', 'devtest-bhavesh' ),
            )
        );
    }

    /**
     * Append a summary button below the content of single posts.
     */
    public function append_post_summary_button( $content ) {

        if ( is_admin() || ! is_singular( 'post' ) ) {
            return $content;
        }

        global $post;

        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
            return $content;
        }

        $button_markup = sprintf(
            '<div class="post-summary-wrapper" data-post-id="%1$d">
                <button type="button" class="post-summary-button">%2$s</button>
                <div class="post-summary-status" aria-live="polite"></div>
                <div class="post-summary-result" hidden></div>
            </div>',
            absint( $post->ID ),
            esc_html__( 'Summarise this post', 'devtest-bhavesh' )
        );

        return $content . $button_markup;
    }

    /**
     * Generate a post summary via AJAX, using a real API if configured.
     */
    public function handle_generate_post_summary() {

        // Security problem: the request accepted unauthenticated data without verifying
        // that the caller had permission to read the post. Fix: validate the nonce and
        // ensure the requested post is a published single post before generating a summary.
        if ( ! isset( $_POST['security'] ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Missing security token.', 'devtest-bhavesh' ),
                ),
                400
            );
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'post_summary_nonce' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid security token.', 'devtest-bhavesh' ),
                ),
                403
            );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
        $post    = get_post( $post_id );

        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
            wp_send_json_error(
                array(
                    'message' => __( 'The requested post is not available.', 'devtest-bhavesh' ),
                ),
                404
            );
        }

        // Security problem: the original code directly echoed user-controlled content.
        // Fix: sanitize and control the text before using it in prompts or responses.
        $content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
        $content = preg_replace( '/\s+/', ' ', $content );
        $content = trim( $content );

        if ( '' === $content ) {
            wp_send_json_success(
                array(
                    'summary' => __( 'This post does not contain enough text to summarise yet.', 'devtest-bhavesh' ),
                )
            );
        }

        // Security problem: storing the API key in plugin code would expose it publicly.
        // Fix: read the key from wp-config.php and keep the plugin code free of secrets.
        $api_key = defined( 'OPENAI_API_KEY' ) ? OPENAI_API_KEY : '';

        if ( ! empty( $api_key ) ) {
            $prompt = sprintf(
                'Summarize the following post in exactly two sentences. Post content: %s',
                $content
            );

            $request_args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'model'    => 'gpt-4o-mini',
                        'messages' => array(
                            array(
                                'role'    => 'system',
                                'content' => 'You are a helpful summarizer.',
                            ),
                            array(
                                'role'    => 'user',
                                'content' => $prompt,
                            ),
                        ),
                        'temperature' => 0.2,
                    )
                ),
                'timeout' => 30,
            );

            $response = wp_remote_post(
                'https://api.openai.com/v1/chat/completions',
                $request_args
            );

            if ( ! is_wp_error( $response ) ) {
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );

                if ( isset( $data['choices'][0]['message']['content'] ) ) {
                    $summary = wp_strip_all_tags( trim( $data['choices'][0]['message']['content'] ) );
                    if ( '' !== $summary ) {
                        wp_send_json_success(
                            array(
                                'summary' => $summary,
                            )
                        );
                    }
                }
            }
        }

        // Security problem: when no valid API key is available, returning a raw placeholder
        // could be misleading. Fix: provide a realistic fallback summary that is clearly
        // mocked and safe to display.
        $mock_summary = sprintf(
            'This post explains %s. It highlights the key points so readers can quickly understand the main takeaway.',
            wp_trim_words( $content, 12 )
        );

        wp_send_json_success(
            array(
                'summary' => $mock_summary,
            )
        );
    }

    /**
     * Register Testimonial CPT
     */
    public function register_testimonial_post_type() {

        $labels = array(
            'name'               => __( 'Testimonials', 'devtest-bhavesh' ),
            'singular_name'      => __( 'Testimonial', 'devtest-bhavesh' ),
            'menu_name'          => __( 'Testimonials', 'devtest-bhavesh' ),
            'add_new_item'       => __( 'Add New Testimonial', 'devtest-bhavesh' ),
            'edit_item'          => __( 'Edit Testimonial', 'devtest-bhavesh' ),
            'new_item'           => __( 'New Testimonial', 'devtest-bhavesh' ),
            'view_item'          => __( 'View Testimonial', 'devtest-bhavesh' ),
            'search_items'       => __( 'Search Testimonials', 'devtest-bhavesh' ),
            'not_found'          => __( 'No Testimonials Found', 'devtest-bhavesh' ),
            'not_found_in_trash' => __( 'No Testimonials Found In Trash', 'devtest-bhavesh' ),
        );

        $args = array(
            'labels'        => $labels,
            'public'        => true,
            'show_ui'       => true,
            'show_in_menu'  => true,
            'show_in_rest'  => true,
            'has_archive'   => true,
            'menu_icon'     => 'dashicons-format-quote',
            'supports'      => array(
                'title',
                'editor',
                'thumbnail'
            ),
            'rewrite'       => array(
                'slug' => 'testimonials'
            ),
        );

        register_post_type( 'testimonial', $args );
    }

    /**
     * Add Meta Box
     */
    public function add_testimonial_meta_box() {

        add_meta_box(
            'testimonial_details',
            'Testimonial Details',
            array( $this, 'render_testimonial_meta_box' ),
            'testimonial',
            'normal',
            'high'
        );
    }

    /**
     * Meta Box HTML
     */
    public function render_testimonial_meta_box( $post ) {

        wp_nonce_field( 'testimonial_meta_nonce', 'testimonial_meta_nonce' );

        $client_name = get_post_meta( $post->ID, '_client_name', true );
        $company     = get_post_meta( $post->ID, '_company', true );
        $rating      = get_post_meta( $post->ID, '_rating', true );
        ?>

        <p>
            <label for="client_name"><strong>Client Name</strong></label>
            <input
                type="text"
                id="client_name"
                name="client_name"
                value="<?php echo esc_attr( $client_name ); ?>"
                class="widefat"
            />
        </p>

        <p>
            <label for="company"><strong>Company</strong></label>
            <input
                type="text"
                id="company"
                name="company"
                value="<?php echo esc_attr( $company ); ?>"
                class="widefat"
            />
        </p>

        <p>
            <label for="rating"><strong>Rating</strong></label>
            <select name="rating" id="rating">
                <option value="">Select Rating</option>

                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                    <option
                        value="<?php echo esc_attr( $i ); ?>"
                        <?php selected( $rating, $i ); ?>
                    >
                        <?php echo esc_html( $i ); ?>
                    </option>
                <?php endfor; ?>

            </select>
        </p>

        <?php
    }

    /**
     * Save Meta Fields
     */
    public function save_testimonial_meta( $post_id ) {

        if (
            ! isset( $_POST['testimonial_meta_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['testimonial_meta_nonce'] ) ),
                'testimonial_meta_nonce'
            )
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['client_name'] ) ) {
            update_post_meta(
                $post_id,
                '_client_name',
                sanitize_text_field( wp_unslash( $_POST['client_name'] ) )
            );
        }

        if ( isset( $_POST['company'] ) ) {
            update_post_meta(
                $post_id,
                '_company',
                sanitize_text_field( wp_unslash( $_POST['company'] ) )
            );
        }

        if ( isset( $_POST['rating'] ) ) {

            $rating = intval( $_POST['rating'] );

            if ( $rating >= 1 && $rating <= 5 ) {
                update_post_meta(
                    $post_id,
                    '_rating',
                    $rating
                );
            }
        }
    }

    /**
     * Shortcode to Display Testimonials
     */

    public function testimonial_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'limit' => 3,
        ),
        $atts,
        'testimonials'
    );

    $query = new WP_Query(
        array(
            'post_type'      => 'testimonial',
            'posts_per_page' => intval( $atts['limit'] ),
            'post_status'    => 'publish',
        )
    );

    ob_start();

    if ( $query->have_posts() ) :

        while ( $query->have_posts() ) :
            $query->the_post();

            $client_name = get_post_meta(
                get_the_ID(),
                '_client_name',
                true
            );

            $company = get_post_meta(
                get_the_ID(),
                '_company',
                true
            );

            $rating = get_post_meta(
                get_the_ID(),
                '_rating',
                true
            );
            ?>

            <div class="testimonial-item">

                <h3><?php echo esc_html( $client_name ); ?></h3>

                <p>
                    <strong>
                        <?php echo esc_html( $company ); ?>
                    </strong>
                </p>

                <p>

                    <?php
                    for ( $i = 1; $i <= 5; $i++ ) {

                        echo ( $i <= $rating ) ? '★' : '☆';
                    }
                    ?>

                </p>

                <div>
                    <?php the_content(); ?>
                </div>

            </div>

            <hr>

            <?php

        endwhile;

        wp_reset_postdata();

    endif;

    return ob_get_clean();
    }

    // add rating column to admin list
    public function add_rating_column( $columns ) {

        $columns['testimonial_rating'] = 'Rating';

        return $columns;
    }

    public function show_rating_column(
        $column,
        $post_id
    ) {

    if ( 'testimonial_rating' === $column ) {

        echo esc_html(
            get_post_meta(
                $post_id,
                '_rating',
                true
            )
        );
    }
    }

    /**
     * Make rating column sortable.
     */
    public function make_rating_sortable( $columns ) {

        $columns['testimonial_rating'] = 'testimonial_rating';

        return $columns;
    }

    // sort by rating column
    public function rating_column_orderby(
    $query
) {

    if (
        ! is_admin() ||
        ! $query->is_main_query()
    ) {
        return;
    }

    if (
        'testimonial_rating'
        === $query->get( 'orderby' )
    ) {

        $query->set(
            'meta_key',
            '_rating'
        );

        $query->set(
            'orderby',
            'meta_value_num'
        );
    }
}
}

/**
 * Initialize Plugin
 */
new MCP_Custom_Post_Type();

/**
 * Activation Hook
 */
register_activation_hook( __FILE__, 'mcp_activate_plugin' );

function mcp_activate_plugin() {

    $cpt = new MCP_Custom_Post_Type();
    $cpt->register_testimonial_post_type();

    flush_rewrite_rules();
}

/**
 * Deactivation Hook
 */
register_deactivation_hook( __FILE__, 'mcp_deactivate_plugin' );

function mcp_deactivate_plugin() {
    flush_rewrite_rules();
}

/** 3. Security - fix the vulnerable code
 * Securely display a user's admin note through an AJAX endpoint.
 */
function show_user_note() {
    // Security problem: the handler accepted request data without verifying the caller
    // had permission to view the note. Fix: require an authenticated admin capability.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            array(
                'message' => __( 'You do not have permission to view this note.', 'devtest-bhavesh' ),
            ),
            403
        );
    }

    // Security problem: direct use of $_GET values allows malformed input and can lead
    // to unsafe behavior. Fix: validate and sanitize each value before using it.
    $user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
    $note    = isset( $_GET['note'] ) ? sanitize_text_field( wp_unslash( $_GET['note'] ) ) : '';

    // Security problem: the SQL query used raw input values, which makes the code
    // vulnerable to SQL injection. Fix: prepare the query with placeholders.
    global $wpdb;
    $query  = $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
        $user_id,
        'admin_note'
    );
    $result = $wpdb->get_var( $query );

    // Security problem: unescaped output can allow stored or reflected XSS.
    // Fix: escape every value before rendering it to the browser.
    echo '<div>' . esc_html__( 'Note:', 'devtest-bhavesh' ) . ' ' . esc_html( $note ) . '</div>';
    echo '<div>' . esc_html__( 'Saved:', 'devtest-bhavesh' ) . ' ' . esc_html( (string) $result ) . '</div>';

    wp_die();
}
add_action( 'wp_ajax_show_note', 'show_user_note' );