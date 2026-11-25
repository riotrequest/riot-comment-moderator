<?php
defined( 'ABSPATH' ) || exit;

function rcm_withheld_url( $post_id = 0 ) {
	$args = array( 'comment_status' => 'moderated', 'rcm_withheld' => '1' );
	if ( $post_id ) {
		$args['p'] = $post_id;
	}
	return add_query_arg( $args, admin_url( 'edit-comments.php' ) );
}

function rcm_withheld_count( $post_id = 0 ) {
	global $wpdb;
	$sql = "SELECT COUNT(DISTINCT c.comment_ID) FROM {$wpdb->comments} c
		INNER JOIN {$wpdb->commentmeta} m ON m.comment_id=c.comment_ID AND m.meta_key='_rcm_withheld'
		WHERE c.comment_approved='0'";
	if ( $post_id ) {
		$sql .= $wpdb->prepare( ' AND c.comment_post_ID=%d', $post_id );
	}
	return (int) $wpdb->get_var( $sql );
}

function rcm_is_withheld_view() {
	return '1' === ( $_REQUEST['rcm_withheld'] ?? '' );
}

add_filter( 'comments_list_table_query_args', static function ( $args ) {
	if ( 'hold' !== ( $args['status'] ?? '' ) || ! current_user_can( 'moderate_comments' ) ) {
		return $args;
	}
	$args['meta_query'] = array(
		'relation' => 'AND',
		$args['meta_query'] ?? array(),
		array( 'key' => '_rcm_withheld', 'compare' => rcm_is_withheld_view() ? 'EXISTS' : 'NOT EXISTS' ),
	);
	return $args;
} );

add_action( 'manage_comments_nav', static function ( $status, $which ) {
	if ( 'moderated' === $status && 'top' === $which && rcm_is_withheld_view() ) {
		echo '<input type="hidden" name="rcm_withheld" value="1">';
	}
}, 10, 2 );

add_filter( 'comment_status_links', static function ( $links ) {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return $links;
	}
	global $post_id;
	$post_id_filter = absint( $post_id );
	$count = rcm_withheld_count( $post_id_filter );
	if ( isset( $links['moderated'] ) && $count ) {
		$pending = max( 0, (int) wp_count_comments( $post_id_filter )->moderated - $count );
		$links['moderated'] = preg_replace_callback( '#(<span class="pending-count">)[^<]+(</span>)#',
			static function ( $match ) use ( $pending ) { return $match[1] . esc_html( number_format_i18n( $pending ) ) . $match[2]; },
			$links['moderated'], 1 );
		if ( rcm_is_withheld_view() ) {
			$links['moderated'] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $links['moderated'] );
		}
	}
	$links['rcm_withheld'] = sprintf( '<a href="%s"%s>Withheld <span class="count">(%s)</span></a>',
		esc_url( rcm_withheld_url( $post_id_filter ) ),
		rcm_is_withheld_view() ? ' class="current" aria-current="page"' : '',
		esc_html( number_format_i18n( $count ) ) );
	return $links;
} );

add_filter( 'comment_row_actions', static function ( $actions, $comment ) {
	$id = (int) $comment->comment_ID;
	if ( current_user_can( 'moderate_comments' ) && current_user_can( 'edit_comment', $id ) && get_comment_meta( $id, '_rcm_withheld', true ) ) {
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'rcm_release_withheld', 'comment_id' => $id ), admin_url( 'admin-post.php' ) ), 'rcm_release_' . $id );
		$actions['rcm_release_withheld'] = '<a href="' . esc_url( $url ) . '">Return to Pending</a>';
	}
	return $actions;
}, 10, 2 );

add_action( 'admin_post_rcm_release_withheld', static function () {
	$id = absint( $_GET['comment_id'] ?? 0 );
	if ( ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_comment', $id ) ) {
		wp_die( 'You cannot manage this comment.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rcm_release_' . $id );
	delete_comment_meta( $id, '_rcm_withheld' );
	rcm_clear_second_job( $id );
	wp_safe_redirect( admin_url( 'edit-comments.php?comment_status=moderated#comment-' . $id ) );
	exit;
} );
