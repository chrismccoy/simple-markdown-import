<?php
/**
 * Plugin Name: Simple Markdown Import
 * Description: AJAX-powered tool to import Markdown files or pasted text as WordPress posts.
 * Version: 1.0.0
 * Author: Chris McCoy
 * Text Domain: simple-markdown-import
 *
 * @package SimpleMarkdownImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class.
 */
class Simple_Markdown_Import {

	/**
	 * Holds the hook suffix for the admin page to ensure assets only load there.
	 */
	private $plugin_screen_hook_suffix = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_smi_process_import', array( $this, 'ajax_process_import' ) );
	}

	/**
	 * Registers the plugin page under the "Tools" menu.
	 */
	public function add_admin_menu() {
		$this->plugin_screen_hook_suffix = add_management_page(
			__( 'Import Markdown', 'simple-markdown-import' ),
			__( 'Import Markdown', 'simple-markdown-import' ),
			'manage_options',
			'simple-markdown-import',
			array( $this, 'display_import_page' )
		);
	}

	/**
	 * Enqueue CSS and JS only on the plugin page.
	 */
	public function enqueue_assets( $hook ) {
		// Only load assets on our specific plugin page.
		if ( $this->plugin_screen_hook_suffix !== $hook ) {
			return;
		}

		// Enqueue CSS.
		wp_enqueue_style(
			'smi-admin-css',
			plugin_dir_url( __FILE__ ) . 'assets/css/admin.css',
			array(),
			'1.0.0'
		);

		// Enqueue JS.
		wp_enqueue_script(
			'smi-admin-js',
			plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
			array( 'jquery' ),
			'1.0.0',
			true // Load in footer.
		);

		// Pass Nonce, URLs, Strings to JavaScript.
		wp_localize_script(
			'smi-admin-js',
			'smiSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'smi_ajax_nonce' ),
				'strings' => array(
					'noFile'       => __( 'No file chosen', 'simple-markdown-import' ),
					'selectMethod' => __( 'Please select an Import Method (Upload or Paste).', 'simple-markdown-import' ),
					'initializing' => __( 'Initializing...', 'simple-markdown-import' ),
					'serverError'  => __( 'Server Error:', 'simple-markdown-import' ),
					'errorLabel'   => __( 'Error:', 'simple-markdown-import' ),
					'unknownError' => __( 'Unknown error occurred.', 'simple-markdown-import' ),
				),
			)
		);
	}

	/**
	 * AJAX Handler.
	 */
	public function ajax_process_import() {
		check_ajax_referer( 'smi_ajax_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'simple-markdown-import' ) ) );
		}

		$logs   = array();
		$logs[] = __( 'Request received. Starting process...', 'simple-markdown-import' );

		if ( empty( $_POST['post_title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Post title is required.', 'simple-markdown-import' ) ) );
		}
		$post_title = sanitize_text_field( $_POST['post_title'] );
		/* translators: %s: Post Title */
		$logs[] = sprintf( __( 'Title set to: %s', 'simple-markdown-import' ), $post_title );

		$import_method    = isset( $_POST['import_method'] ) ? sanitize_text_field( $_POST['import_method'] ) : '';
		$markdown_content = '';

		if ( 'upload' === $import_method ) {
			if ( ! isset( $_FILES['markdown_file'] ) || empty( $_FILES['markdown_file']['name'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'simple-markdown-import' ) ) );
			}

			$file         = $_FILES['markdown_file'];
			$filename     = sanitize_file_name( $file['name'] );
			$file_ext     = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$allowed_exts = array( 'md', 'markdown', 'txt' );

			if ( ! in_array( $file_ext, $allowed_exts, true ) ) {
				/* translators: %s: File Extension */
				wp_send_json_error( array( 'message' => sprintf( __( 'Invalid file extension: .%s. Allowed: .md, .markdown, .txt', 'simple-markdown-import' ), $file_ext ) ) );
			}

			if ( $file['error'] !== UPLOAD_ERR_OK ) {
				/* translators: %d: Error Code */
				wp_send_json_error( array( 'message' => sprintf( __( 'File upload error code: %d', 'simple-markdown-import' ), $file['error'] ) ) );
			}

			$logs[]           = sprintf( __( 'File uploaded successfully: %s', 'simple-markdown-import' ), $file['name'] );
			$markdown_content = file_get_contents( $file['tmp_name'] );

		} elseif ( 'paste' === $import_method ) {
			if ( empty( $_POST['markdown_paste'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No markdown text was pasted.', 'simple-markdown-import' ) ) );
			}
			$logs[]           = __( 'Processing pasted text content.', 'simple-markdown-import' );
			$markdown_content = $_POST['markdown_paste'];
		} else {
			wp_send_json_error( array( 'message' => __( 'Please select an import method (Upload or Paste).', 'simple-markdown-import' ) ) );
		}

		if ( empty( trim( $markdown_content ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Content is empty.', 'simple-markdown-import' ) ) );
		}

		$line_count = count( explode( "\n", $markdown_content ) );
		$byte_count = strlen( $markdown_content );
		/* translators: %s: File Size */
		$logs[] = sprintf( __( 'Content loaded. Size: %s', 'simple-markdown-import' ), size_format( $byte_count ) );
		/* translators: %d: Line Count */
		$logs[] = sprintf( __( 'Processing %d lines of Markdown...', 'simple-markdown-import' ), $line_count );

		try {
			require_once plugin_dir_path( __FILE__ ) . 'inc/Parsedown.php';
			$parsedown    = new Parsedown();
			$html_content = $parsedown->text( $markdown_content );
			$logs[]       = __( 'Markdown converted to HTML successfully.', 'simple-markdown-import' );
		} catch ( Exception $e ) {
			/* translators: %s: Error Message */
			wp_send_json_error( array( 'message' => sprintf( __( 'Parsing error: %s', 'simple-markdown-import' ), $e->getMessage() ) ) );
		}

		$post_category = isset( $_POST['post_category'] ) ? intval( $_POST['post_category'] ) : 0;
		$category_name = ( $post_category > 0 ) ? get_cat_name( $post_category ) : __( 'Uncategorized', 'simple-markdown-import' );

		$new_post = array(
			'post_title'    => $post_title,
			'post_content'  => $html_content,
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_author'   => get_current_user_id(),
			'post_category' => array( $post_category ),
		);

		$post_id = wp_insert_post( $new_post );

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			/* translators: %s: Category Name */
			$logs[] = sprintf( __( 'Assigning Category: %s', 'simple-markdown-import' ), $category_name );
			/* translators: %d: Post ID */
			$logs[] = sprintf( __( '<strong>Success!</strong> Post created (ID: %d).', 'simple-markdown-import' ), $post_id );

			$success_message = sprintf(
				'%s <a href="%s" target="_blank">%s</a> | <a href="%s" target="_blank">%s</a>',
				__( 'Post imported successfully!', 'simple-markdown-import' ),
				get_edit_post_link( $post_id ),
				__( 'Edit Post', 'simple-markdown-import' ),
				get_permalink( $post_id ),
				__( 'View Post', 'simple-markdown-import' )
			);

			wp_send_json_success(
				array(
					'message' => $success_message,
					'logs'    => $logs,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'WordPress database error: Could not insert post.', 'simple-markdown-import' ) ) );
		}
	}

	/**
	 * Renders the HTML interface.
	 */
	public function display_import_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Markdown to Post', 'simple-markdown-import' ); ?></h1>

			<div id="smi-notice-area" class="smi-notice"></div>

			<div class="smi-card">
				<form id="smi-import-form">

					<table class="form-table" role="presentation">
						<tbody>
							<!-- Post Title -->
							<tr>
								<th scope="row"><label for="post_title"><?php esc_html_e( 'Post Title', 'simple-markdown-import' ); ?> <span class="description"><?php esc_html_e( '(required)', 'simple-markdown-import' ); ?></span></label></th>
								<td>
									<input name="post_title" type="text" id="post_title" class="regular-text" required placeholder="<?php esc_attr_e( 'Enter post title', 'simple-markdown-import' ); ?>">
								</td>
							</tr>

							<!-- Categories -->
							<tr>
								<th scope="row"><label for="post_category"><?php esc_html_e( 'Category', 'simple-markdown-import' ); ?></label></th>
								<td>
									<?php
									wp_dropdown_categories(
										array(
											'name'              => 'post_category',
											'id'                => 'post_category',
											'class'             => 'regular-text',
											'show_option_none'  => __( 'Select Category', 'simple-markdown-import' ),
											'option_none_value' => '0',
											'hide_empty'        => 0,
										)
									);
									?>
								</td>
							</tr>

							<!-- Import Method Selection -->
							<tr>
								<th scope="row"><?php esc_html_e( 'Import Method', 'simple-markdown-import' ); ?></th>
								<td>
									<fieldset>
										<label title="<?php esc_attr_e( 'Upload a Markdown file', 'simple-markdown-import' ); ?>" style="margin-right: 20px;">
											<input type="radio" name="import_method" value="upload">
											<strong><?php esc_html_e( 'Upload File', 'simple-markdown-import' ); ?></strong>
										</label>

										<label title="<?php esc_attr_e( 'Paste Markdown text', 'simple-markdown-import' ); ?>">
											<input type="radio" name="import_method" value="paste">
											<strong><?php esc_html_e( 'Paste Text', 'simple-markdown-import' ); ?></strong>
										</label>
									</fieldset>
								</td>
							</tr>

							<!-- File Upload Section (Hidden by default) -->
							<tr id="smi-upload-section" class="smi-section">
								<th scope="row"><?php esc_html_e( 'Select File', 'simple-markdown-import' ); ?></th>
								<td>
									<div class="smi-file-upload-wrapper">
										<label for="markdown_file" class="button button-primary smi-file-btn">
											<?php esc_html_e( 'Choose File', 'simple-markdown-import' ); ?>
										</label>
										<span id="smi-file-name"><?php esc_html_e( 'No file chosen', 'simple-markdown-import' ); ?></span>
										<input type="file" name="markdown_file" id="markdown_file" class="smi-hidden-input" accept=".md,.markdown,.txt">
									</div>
									<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Accepted formats: .md, .markdown, .txt', 'simple-markdown-import' ); ?></p>
								</td>
							</tr>

							<!-- Paste Text Section (Hidden by default) -->
							<tr id="smi-paste-section" class="smi-section">
								<th scope="row"><label for="markdown_paste"><?php esc_html_e( 'Paste Content', 'simple-markdown-import' ); ?></label></th>
								<td>
									<textarea name="markdown_paste" id="markdown_paste" class="large-text code" placeholder="<?php esc_attr_e( '# Your Markdown here...', 'simple-markdown-import' ); ?>"></textarea>
								</td>
							</tr>

						</tbody>
					</table>

					<p class="submit">
						<button type="submit" id="smi-submit-btn" class="button button-primary"><?php esc_html_e( 'Import Markdown', 'simple-markdown-import' ); ?></button>
						<span class="spinner" id="smi-spinner"></span>
					</p>
				</form>

				<div id="smi-console" class="smi-console"></div>
			</div>
		</div>
		<?php
	}
}

new Simple_Markdown_Import();
