<?php
/**
 * Meta fields for literary_work, per the prototype mapping in brief §2.
 *
 * Split across two ACF field groups so REST exposure is a structural
 * guarantee rather than a per-field setting to remember: the public group
 * has show_in_rest enabled, the internal group (just _internal_notes) does
 * not and never should (brief §12, §17).
 *
 * _publications is a repeater, which needs ACF PRO — implemented instead as
 * a plain custom meta box + register_post_meta() below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function literary_archive_register_acf_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( [
		'key'      => 'group_literary_work_public',
		'title'    => __( 'সাহিত্যকর্মের তথ্য', 'literary-archive' ),
		'fields'   => [
			[
				'key'   => 'field_la_literary_id',
				'label' => __( 'আর্কাইভ আইডি', 'literary-archive' ),
				'name'  => '_literary_id',
				'type'  => 'text',
			],
			[
				'key'   => 'field_la_writing_year',
				'label' => __( 'লেখার সাল', 'literary-archive' ),
				'name'  => '_writing_year',
				'type'  => 'number',
			],
			[
				'key'   => 'field_la_writing_place',
				'label' => __( 'লেখার স্থান', 'literary-archive' ),
				'name'  => '_writing_place',
				'type'  => 'text',
			],
			[
				'key'           => 'field_la_book_selection_status',
				'label'         => __( 'নতুন বইয়ের জন্য নির্বাচন', 'literary-archive' ),
				'name'          => '_book_selection_status',
				'type'          => 'select',
				'choices'       => [
					'considering' => __( 'বিবেচনাধীন', 'literary-archive' ),
					'selected'    => __( 'নির্বাচিত', 'literary-archive' ),
					'editing'     => __( 'সম্পাদনাধীন', 'literary-archive' ),
					'final'       => __( 'চূড়ান্ত', 'literary-archive' ),
					'rejected'    => __( 'বাদ', 'literary-archive' ),
					'in_book'     => __( 'বইয়ে অন্তর্ভুক্ত', 'literary-archive' ),
				],
				'allow_null'    => 1,
				'default_value' => 'considering',
			],
			[
				'key'           => 'field_la_related_books',
				'label'         => __( 'সম্পর্কিত বই', 'literary-archive' ),
				'name'          => '_related_books',
				'type'          => 'relationship',
				'post_type'     => [ 'book' ],
				'filters'       => [ 'search' ],
			],
			[
				'key'   => 'field_la_manuscript_file',
				'label' => __( 'মূল পাণ্ডুলিপি ফাইল', 'literary-archive' ),
				'name'  => '_manuscript_file',
				'type'  => 'file',
			],
			[
				'key'       => 'field_la_series_id',
				'label'     => __( 'ধারাবাহিক', 'literary-archive' ),
				'name'      => '_series_id',
				'type'      => 'post_object',
				'post_type' => [ 'series' ],
			],
			[
				'key'   => 'field_la_episode_number',
				'label' => __( 'পর্ব নম্বর', 'literary-archive' ),
				'name'  => '_episode_number',
				'type'  => 'number',
			],
		],
		'location' => [
			[
				[
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'literary_work',
				],
			],
		],
		'show_in_rest' => 1,
	] );

	// Separate group, deliberately with no show_in_rest key: private editorial
	// note that must never reach the public REST API (brief §2, §12).
	acf_add_local_field_group( [
		'key'      => 'group_literary_work_internal',
		'title'    => __( 'অভ্যন্তরীণ নোট', 'literary-archive' ),
		'fields'   => [
			[
				'key'   => 'field_la_internal_notes',
				'label' => __( 'অভ্যন্তরীণ নোট (পাবলিকে দেখা যাবে না)', 'literary-archive' ),
				'name'  => '_internal_notes',
				'type'  => 'textarea',
			],
		],
		'location' => [
			[
				[
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'literary_work',
				],
			],
		],
	] );
}
add_action( 'acf/init', 'literary_archive_register_acf_fields' );

/**
 * _publications repeater (venue, issue, date, page, url) — brief §2, §3.
 * Publicly readable via REST: no sensitive data, and Provatferi (§5) needs it.
 */
function literary_archive_register_publications_meta() {
	register_post_meta( 'literary_work', '_publications', [
		'type'          => 'array',
		'single'        => true,
		'show_in_rest'  => [
			'schema' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'venue' => [ 'type' => 'string' ],
						'issue' => [ 'type' => 'string' ],
						'date'  => [ 'type' => 'string' ],
						'page'  => [ 'type' => 'string' ],
						'url'   => [ 'type' => 'string' ],
					],
				],
			],
		],
		'auth_callback' => '__return_true',
	] );
}
add_action( 'init', 'literary_archive_register_publications_meta' );

function literary_archive_add_publications_metabox() {
	add_meta_box(
		'literary_archive_publications',
		__( 'প্রকাশনার ইতিহাস', 'literary-archive' ),
		'literary_archive_render_publications_metabox',
		'literary_work',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'literary_archive_add_publications_metabox' );

function literary_archive_render_publications_metabox( $post ) {
	wp_nonce_field( 'literary_archive_save_publications', 'literary_archive_publications_nonce' );
	$publications = get_post_meta( $post->ID, '_publications', true );
	if ( ! is_array( $publications ) ) {
		$publications = [];
	}
	?>
	<table class="widefat literary-archive-publications-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'প্রকাশনা মাধ্যম', 'literary-archive' ); ?></th>
				<th><?php esc_html_e( 'সংখ্যা/ইস্যু', 'literary-archive' ); ?></th>
				<th><?php esc_html_e( 'তারিখ', 'literary-archive' ); ?></th>
				<th><?php esc_html_e( 'পৃষ্ঠা', 'literary-archive' ); ?></th>
				<th><?php esc_html_e( 'লিংক', 'literary-archive' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody id="literary-archive-publications-rows">
			<?php foreach ( $publications as $index => $row ) : ?>
				<?php literary_archive_render_publication_row( $index, $row ); ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<button type="button" class="button" id="literary-archive-add-publication">
			<?php esc_html_e( '+ নতুন প্রকাশনা যোগ করুন', 'literary-archive' ); ?>
		</button>
	</p>
	<template id="literary-archive-publication-row-template">
		<?php literary_archive_render_publication_row( '__INDEX__', [] ); ?>
	</template>
	<?php
}

function literary_archive_render_publication_row( $index, $row ) {
	$row = wp_parse_args( $row, [ 'venue' => '', 'issue' => '', 'date' => '', 'page' => '', 'url' => '' ] );
	?>
	<tr class="literary-archive-publication-row">
		<td><input type="text" name="literary_archive_publications[<?php echo esc_attr( $index ); ?>][venue]" value="<?php echo esc_attr( $row['venue'] ); ?>" class="widefat"></td>
		<td><input type="text" name="literary_archive_publications[<?php echo esc_attr( $index ); ?>][issue]" value="<?php echo esc_attr( $row['issue'] ); ?>" class="widefat"></td>
		<td><input type="text" name="literary_archive_publications[<?php echo esc_attr( $index ); ?>][date]" value="<?php echo esc_attr( $row['date'] ); ?>" class="widefat" placeholder="YYYY-MM-DD"></td>
		<td><input type="text" name="literary_archive_publications[<?php echo esc_attr( $index ); ?>][page]" value="<?php echo esc_attr( $row['page'] ); ?>" class="widefat"></td>
		<td><input type="url" name="literary_archive_publications[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" class="widefat"></td>
		<td><button type="button" class="button-link literary-archive-remove-publication" aria-label="<?php esc_attr_e( 'সরান', 'literary-archive' ); ?>">&times;</button></td>
	</tr>
	<?php
}

function literary_archive_save_publications( $post_id ) {
	if ( ! isset( $_POST['literary_archive_publications_nonce'] ) ||
		! wp_verify_nonce( $_POST['literary_archive_publications_nonce'], 'literary_archive_save_publications' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$rows = isset( $_POST['literary_archive_publications'] ) && is_array( $_POST['literary_archive_publications'] )
		? $_POST['literary_archive_publications']
		: [];

	$sanitized = [];
	foreach ( $rows as $row ) {
		$venue = isset( $row['venue'] ) ? sanitize_text_field( wp_unslash( $row['venue'] ) ) : '';
		$issue = isset( $row['issue'] ) ? sanitize_text_field( wp_unslash( $row['issue'] ) ) : '';
		$date  = isset( $row['date'] ) ? sanitize_text_field( wp_unslash( $row['date'] ) ) : '';
		$page  = isset( $row['page'] ) ? sanitize_text_field( wp_unslash( $row['page'] ) ) : '';
		$url   = isset( $row['url'] ) ? esc_url_raw( wp_unslash( $row['url'] ) ) : '';

		if ( '' === $venue && '' === $issue && '' === $date && '' === $page && '' === $url ) {
			continue; // skip fully empty rows
		}

		$sanitized[] = compact( 'venue', 'issue', 'date', 'page', 'url' );
	}

	update_post_meta( $post_id, '_publications', $sanitized );
}
add_action( 'save_post_literary_work', 'literary_archive_save_publications' );

function literary_archive_enqueue_admin_assets( $hook ) {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}
	if ( 'literary_work' !== get_current_screen()->post_type ) {
		return;
	}

	wp_enqueue_script(
		'literary-archive-publications',
		plugins_url( 'assets/admin-publications.js', LITERARY_ARCHIVE_PATH . 'literary-archive.php' ),
		[],
		LITERARY_ARCHIVE_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'literary_archive_enqueue_admin_assets' );
