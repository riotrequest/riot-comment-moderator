<?php
defined( 'ABSPATH' ) || exit;

function rcm_action_labels() {
	return array( 'approve' => 'Approve clear comments', 'spam' => 'Mark clear spam', 'trash' => 'Move obvious junk to Trash' );
}

function rcm_parse_actions( $actions ) {
	return array_values( array_intersect( array_keys( rcm_action_labels() ), is_array( $actions ) ? array_filter( $actions, 'is_string' ) : array() ) );
}

function rcm_model_presets( $provider ) {
	if ( 'openrouter_decisions' === $provider ) {
		return array(
			'microsoft/microsoft-decision-1.1' => 'Microsoft Decision 1.1',
			'typesafe/jev-1.14'                => 'TypeSafe Jev 1.14',
			'google/gemini-decision-1'         => 'Gemini Decision 1',
		);
	}
	return 'openrouter' === $provider
		? array( 'z-ai/glm-5.3-flash' => 'GLM 5.3 Flash', 'deepseek/deepseek-v4.1-flash' => 'DeepSeek V4.1 Flash' )
		: array( 'gpt-6-luna' => 'GPT-6 Luna', 'gpt-6.2-sol' => 'GPT-6.2 Sol' );
}

add_action( 'admin_menu', static function () {
	$hook = add_submenu_page( 'edit-comments.php', 'Riot Comment Moderator', 'Riot Moderator', 'manage_options', 'riot-comment-moderator', 'rcm_settings_page' );
	if ( $hook ) {
		add_action( 'load-' . $hook, 'rcm_enqueue_dashboard' );
	}
} );

add_action( 'admin_init', static function () {
	if ( current_user_can( 'manage_options' ) ) {
		rcm_migrate_legacy_keys();
	}
	register_setting( 'rcm', 'rcm_settings', array(
		'type' => 'array',
		'sanitize_callback' => static function ( $input ) {
			$old = rcm_options();
			$input = is_array( $input ) ? $input : array();
			foreach ( array( 'openai', 'openrouter' ) as $provider ) {
				$key = isset( $input[ $provider . '_key' ] ) && is_string( $input[ $provider . '_key' ] ) ? trim( $input[ $provider . '_key' ] ) : '';
				$forget = ! empty( $input[ $provider . '_forget' ] );
				if ( ( $forget || '' !== $key ) && ! is_ssl() ) {
					add_settings_error( 'rcm_settings', $provider . '_key', 'Open this page over HTTPS to change provider keys.' );
				} elseif ( $forget ) {
					delete_option( 'rcm_key_' . $provider );
				} elseif ( '' !== $key ) {
					if ( ! rcm_valid_key( $key ) ) {
						add_settings_error( 'rcm_settings', $provider . '_key', ucfirst( $provider ) . ' key format was invalid. The saved key was kept.' );
					} elseif ( false === ( $encrypted = rcm_encrypt_key( $provider, $key ) ) ) {
						add_settings_error( 'rcm_settings', $provider . '_key', 'Configure encrypted key storage on the server before saving an API key.' );
					} elseif ( ! update_option( 'rcm_key_' . $provider, $encrypted, false ) ) {
						add_settings_error( 'rcm_settings', $provider . '_key', 'The API key could not be saved.' );
					}
				}
			}
			$providers = rcm_providers();
			foreach ( $providers as $provider => $details ) {
				if ( isset( $details['fixed_model'] ) ) {
					continue;
				}
				$field = $provider . '_model';
				$preset = $input[ $provider . '_preset' ] ?? '';
				$presets = rcm_model_presets( $provider );
				$model = is_string( $preset ) && isset( $presets[ $preset ] ) ? $preset : ( $input[ $field ] ?? '' );
				if ( is_string( $model ) && preg_match( '#\A[A-Za-z0-9_.:/~-]{2,120}\z#', $model ) ) {
					$old[ $field ] = $model;
				}
				if ( empty( $details['decisions'] ) ) {
					$effort = $provider . '_reasoning_effort';
					$old[ $effort ] = rcm_reasoning_effort( $provider, $old[ $field ], $input[ $effort ] ?? $old[ $effort ] );
				}
			}
			$prompt = isset( $input['prompt'] ) && is_string( $input['prompt'] ) ? sanitize_textarea_field( $input['prompt'] ) : '';
			$provider = $input['provider'] ?? '';
			return array(
				'enabled'          => empty( $input['enabled'] ) ? 0 : 1,
				'incoming'         => empty( $input['incoming'] ) ? 0 : 1,
				'gate'             => empty( $input['gate'] ) ? 0 : 1,
				'auto'             => rcm_parse_actions( $input['auto'] ?? array() ),
				'full_auto'        => empty( $input['full_auto'] ) ? 0 : 1,
				'provider'         => is_string( $provider ) && isset( $providers[ $provider ] ) ? $provider : 'openai',
				'openai_model'     => $old['openai_model'],
				'openrouter_model' => $old['openrouter_model'],
				'openai_reasoning_effort' => $old['openai_reasoning_effort'],
				'openrouter_reasoning_effort' => $old['openrouter_reasoning_effort'],
				'openrouter_decisions_model' => $old['openrouter_decisions_model'],
				'prompt'           => $prompt ? wp_html_excerpt( $prompt, 5000, '' ) : rcm_default_prompt(),
			);
		},
	) );
} );

require __DIR__ . '/dashboard.php';
require __DIR__ . '/withheld.php';

add_filter( 'manage_edit-comments_columns', static function ( $columns ) {
	$columns['rcm_review'] = 'Riot review';
	return $columns;
} );

add_action( 'manage_comments_custom_column', static function ( $column, $id ) {
	if ( 'rcm_review' !== $column || ! current_user_can( 'moderate_comments' ) ) {
		return;
	}
	$result = get_comment_meta( $id, '_rcm_result', true );
	$withheld = get_comment_meta( $id, '_rcm_withheld', true );
	$queued = get_comment_meta( $id, '_rcm_job', true );
	if ( $queued ) {
		echo '<strong>Queued</strong><br>';
	}
	if ( is_array( $result ) ) {
		$labels = array( 'approve' => 'Recommend approval', 'review' => 'Needs an editor', 'withhold' => 'Keep private', 'spam' => 'Likely spam', 'trash' => 'Recommend Trash', 'error' => 'Review failed' );
		$comment = get_comment( $id );
		$stale   = ! $comment || hash( 'sha256', $comment->comment_content ) !== $result['digest'];
		printf( '<strong>%s</strong><br>%s<br><small>%s</small>',
			esc_html( $withheld ? 'Withheld by Full Auto' : ( $stale ? 'Comment edited since review' : ( $labels[ $result['decision'] ] ?? 'Reviewed' ) ) ),
			esc_html( $stale ? ( $withheld ? 'Return to Pending to request a fresh recommendation.' : 'Queue a fresh recommendation.' ) : $result['reason'] ),
			esc_html( $result['model'] . ( $withheld ? ' · ' . ( $labels[ $result['decision'] ] ?? 'Reviewed' ) :
				( 'withhold' === $result['action'] ? ' · Previously withheld' : ( $result['action'] ? ' · Applied: ' . $result['action'] : '' ) ) ) ) );
	} elseif ( ! $queued ) {
		echo '&mdash;';
	}
	$second = get_comment_meta( $id, '_rcm_second', true );
	if ( is_array( $second ) ) {
		$outcomes = array( 'approve' => 'Published', 'spam' => 'Marked spam', 'trash' => 'Moved to Trash', 'withhold' => 'Kept private' );
		printf( '<br><strong>%s</strong><br>%s<br><small>%s</small>',
			esc_html( 'Second opinion: ' . ( $outcomes[ $second['action'] ?? '' ] ?? ( 'error' === $second['decision'] ? 'not available yet' : 'reviewed' ) ) ),
			esc_html( $second['reason'] ?? '' ), esc_html( $second['model'] ?? '' ) );
	}
}, 10, 2 );

add_filter( 'bulk_actions-edit-comments', static function ( $actions ) {
	if ( current_user_can( 'moderate_comments' ) && rcm_ready() ) {
		$actions['rcm_review'] = 'Get Riot recommendations';
	}
	return $actions;
} );

add_filter( 'handle_bulk_actions-edit-comments', static function ( $redirect, $action, $ids ) {
	if ( 'rcm_review' !== $action || ! current_user_can( 'moderate_comments' ) ) {
		return $redirect;
	}
	$count = 0;
	foreach ( $ids as $id ) {
		if ( current_user_can( 'edit_comment', $id ) && rcm_enqueue( (int) $id, 'manual' ) ) {
			++$count;
		}
	}
	return add_query_arg( 'rcm_queued', $count, $redirect );
}, 10, 3 );

add_action( 'admin_notices', static function () {
	if ( isset( $_GET['rcm_queued'] ) && 'edit-comments' === get_current_screen()->id ) {
		printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( sprintf( 'Queued %d pending comments for Riot recommendations.', absint( $_GET['rcm_queued'] ) ) ) );
	}
} );
