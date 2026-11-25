<?php
/**
 * Plugin Name: Riot Comment Moderator
 * Plugin URI: https://github.com/riotrequest/riot-comment-moderator
 * Description: AI-assisted review of pending WordPress comments, with optional automatic approval.
 * Version: 1.3.10
 * Update URI: https://github.com/riotrequest/riot-comment-moderator
 * Author: riotrequest
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: MIT
 */

defined( 'ABSPATH' ) || exit;

define( 'RCM_VERSION', '1.3.10' );

function rcm_release_update( $update, $plugin_data, $plugin_file ) {
	$slug = 'riot-comment-moderator';
	$repo = "riotrequest/$slug";
	$repository = "https://github.com/$repo";
	// The ZIP must preserve the installed plugin directory.
	if ( $update || "$slug/$slug.php" !== $plugin_file || $repository !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
		return $update;
	}

	$response = wp_safe_remote_get( "https://api.github.com/repos/$repo/releases/latest", array(
		'timeout'     => 5,
		'redirection' => 0,
		'user-agent'  => $slug,
		'headers'     => array( 'Accept' => 'application/vnd.github+json' ),
	) );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $update;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	$tag = $release['tag_name'] ?? '';
	if ( ! is_string( $tag ) || ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', $tag, $version )
		|| ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
		return $update;
	}

	$filename = "$slug.zip";
	$package = "$repository/releases/download/$tag/$filename";
	foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
		if ( $filename === ( $asset['name'] ?? '' ) && 'uploaded' === ( $asset['state'] ?? '' )
			&& $package === ( $asset['browser_download_url'] ?? '' ) ) {
			return array(
				'slug'    => $slug,
				'version' => $version[1],
				'url'     => "$repository/releases/tag/$tag",
				'package' => $package,
			);
		}
	}
	return $update;
}
add_filter( 'update_plugins_github.com', 'rcm_release_update', 10, 3 );

require __DIR__ . '/credentials.php';
require __DIR__ . '/provider.php';
if ( is_admin() ) {
	require __DIR__ . '/admin.php';
}

function rcm_options() {
	$defaults = array(
		'enabled'          => 0,
		'incoming'         => 1,
		'gate'             => 0,
		'auto'             => array(),
		'full_auto'        => 0,
		'provider'         => 'openai',
		'openai_model'     => 'gpt-6-luna',
		'openrouter_model' => 'z-ai/glm-5.3-flash',
		'openai_reasoning_effort' => '',
		'openrouter_reasoning_effort' => '',
		'openrouter_decisions_model' => 'microsoft/microsoft-decision-1.1',
		'prompt'           => rcm_default_prompt(),
	);
	$options = wp_parse_args( get_option( 'rcm_settings', array() ), $defaults );
	if ( ! is_string( $options['provider'] ) || ! isset( rcm_providers()[ $options['provider'] ] ) ) {
		$options['provider'] = $defaults['provider'];
	}
	unset( $options['second'], $options['openai_second_model'], $options['openrouter_second_model'] );
	// Refresh the unused legacy preset while preserving configured OpenAI models.
	if ( 'gpt-4.1-mini' === $options['openai_model'] && '' === rcm_key( 'openai' ) ) {
		$options['openai_model'] = $defaults['openai_model'];
	}
	return $options;
}

function rcm_ready() {
	$options = rcm_options();
	return $options['enabled'] && '' !== rcm_key( $options['provider'] );
}

function rcm_schedule( $delay = 10 ) {
	if ( rcm_ready() && ! wp_next_scheduled( 'rcm_process_queue' ) ) {
		wp_schedule_single_event( time() + $delay, 'rcm_process_queue' );
	}
}

add_action( 'update_option_rcm_settings', static function () {
	rcm_release_deferred();
	rcm_schedule();
} );
add_action( 'add_option_rcm_settings', static function () { rcm_schedule(); } );
register_deactivation_hook( __FILE__, static function () { wp_clear_scheduled_hook( 'rcm_process_queue' ); } );

function rcm_enqueue( $id, $source ) {
	if ( ! rcm_ready() || 'unapproved' !== wp_get_comment_status( $id ) || get_comment_meta( $id, '_rcm_withheld', true ) ) {
		return false;
	}
	update_comment_meta( $id, '_rcm_job', $source );
	delete_comment_meta( $id, '_rcm_job_retries' );
	delete_comment_meta( $id, '_rcm_job_after' );
	rcm_schedule();
	return true;
}

/**
 * True when the background worker has something to do: queued comments or a running batch.
 */
function rcm_has_work() {
	global $wpdb;
	if ( ! rcm_ready() ) {
		return false;
	}
	if ( ! empty( rcm_batch()['running'] ) ) {
		return true;
	}
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id
		WHERE m.meta_key='_rcm_job' AND c.comment_approved='0' LIMIT 1" );
}

/**
 * Entry point for the per-minute host cron. New comments are reviewed within about a minute
 * instead of waiting for the next WP-Cron run. Use: wp riot-moderator tick
 */
function rcm_worker_tick() {
	if ( 'cli' !== PHP_SAPI ) {
		return;
	}
	if ( ! defined( 'RCM_BACKGROUND_WORKER' ) ) {
		define( 'RCM_BACKGROUND_WORKER', true );
	}
	if ( rcm_has_work() ) {
		do_action( 'rcm_process_queue' );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'riot-moderator tick', static function () {
		rcm_worker_tick();
	}, array( 'shortdesc' => 'Review queued comments and continue a running batch. Run every minute from the host cron.' ) );
}

/**
 * Require Riot review before publication. A comment WordPress would publish immediately
 * (for example from a previously approved commenter) is held instead, so Riot sees every
 * reader comment first. Spam, trash, holds, and errors from WordPress and other plugins
 * are left exactly as they are. Off unless enabled, and inactive while Riot is not set up.
 */
add_filter( 'pre_comment_approved', static function ( $approved, $commentdata ) {
	if ( 1 !== $approved && '1' !== $approved ) {
		return $approved;
	}
	$options = rcm_options();
	if ( empty( $options['gate'] ) || empty( $options['incoming'] ) || ! rcm_ready() ) {
		return $approved;
	}
	if ( ! in_array( (string) ( $commentdata['comment_type'] ?? '' ), array( '', 'comment' ), true ) ) {
		return $approved;
	}
	$user = (int) ( $commentdata['user_id'] ?? 0 );
	if ( $user && user_can( $user, 'moderate_comments' ) ) {
		return $approved;
	}
	return 0;
}, PHP_INT_MAX - 10, 2 );

add_action( 'wp_insert_comment', static function ( $id, $comment ) {
	if ( '0' === (string) $comment->comment_approved && in_array( $comment->comment_type, array( '', 'comment' ), true ) && rcm_options()['incoming'] ) {
		rcm_enqueue( $id, 'incoming' );
	}
}, 10, 2 );

add_action( 'transition_comment_status', static function ( $new, $old, $comment ) {
	if ( 'unapproved' !== $new && 'hold' !== $new ) {
		delete_comment_meta( $comment->comment_ID, '_rcm_job' );
		delete_comment_meta( $comment->comment_ID, '_rcm_job_retries' );
		delete_comment_meta( $comment->comment_ID, '_rcm_job_after' );
		delete_comment_meta( $comment->comment_ID, '_rcm_withheld' );
		rcm_clear_second_job( $comment->comment_ID );
	}
}, 10, 3 );

function rcm_batch() {
	wp_cache_delete( 'rcm_batch', 'options' );
	return get_option( 'rcm_batch', array() );
}

function rcm_review_snapshot( $id, $include_legacy = false ) {
	wp_cache_delete( $id, 'comment' );
	$comment = get_comment( $id );
	if ( ! $comment || '0' !== (string) $comment->comment_approved || ! in_array( $comment->comment_type, array( '', 'comment' ), true ) ||
		( ! $include_legacy && get_comment_meta( $id, '_rcm_withheld', true ) ) ) {
		return null;
	}
	$post = get_post( $comment->comment_post_ID );
	if ( ! $post || 'publish' !== $post->post_status || $post->post_password ) {
		return null;
	}
	return array( $comment, $post, rcm_parent_text( $comment ) );
}

/**
 * A reply is judged together with the published comment it answers, so replies can be approved too.
 */
function rcm_parent_text( $comment ) {
	if ( ! $comment->comment_parent ) {
		return '';
	}
	$parent = get_comment( $comment->comment_parent );
	return $parent && '1' === (string) $parent->comment_approved && (int) $parent->comment_post_ID === (int) $comment->comment_post_ID
		? (string) $parent->comment_content : '';
}

function rcm_review_comment( $id, $source ) {
	$snapshot = rcm_review_snapshot( $id );
	if ( ! $snapshot ) {
		return 'skipped';
	}
	list( $comment, $post, $parent ) = $snapshot;
	$options = rcm_options();
	$full_auto = 'incoming' === $source && $options['incoming'] && ! empty( $options['full_auto'] );
	$inputs = array( array( $post->post_title, $comment->comment_content, $parent ) );
	$result = rcm_request_reviews( $options, $inputs, $full_auto )[0];
	return rcm_apply_review( $snapshot, $result, $options, $source );
}

function rcm_apply_review( $snapshot, $result, $options, $source, $batch_id = '' ) {
	list( $comment, $post, $parent ) = $snapshot;
	$id = (int) $comment->comment_ID;
	wp_cache_delete( $id, 'comment' );
	$current = get_comment( $id );
	if ( ! $current || '0' !== (string) $current->comment_approved ) {
		return 'skipped';
	}
	if ( $current->comment_content !== $comment->comment_content || (int) $current->comment_post_ID !== (int) $comment->comment_post_ID ) {
		$result['decision'] = 'error';
		$result['reason'] = 'The comment changed during review. Riot will retry.';
		$result['retryable'] = true;
	}

	$result['time']   = time();
	$result['source'] = $source;
	$result['action'] = '';
	$result['digest'] = hash( 'sha256', $comment->comment_content );
	update_comment_meta( $id, '_rcm_result', $result );
	if ( 'error' === $result['decision'] ) {
		return 'errors';
	}
	if ( empty( $result['fallback'] ) ) {
		update_comment_meta( $id, '_rcm_reviewed', '1' );
	}

	// Re-read controls after the call so pausing or changing policy takes effect before publishing.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'rcm_settings', 'options' );
	$active = rcm_options();
	$full_auto = false;
	if ( 'historical' === $source ) {
		$batch = rcm_batch();
		$running = ! empty( $batch['running'] ) && $batch_id === ( $batch['id'] ?? '' );
		$auto = $running ? $batch['auto'] : array();
		$full_auto = $running && ! empty( $batch['full_auto'] );
	} else {
		$incoming = 'incoming' === $source && $active['incoming'];
		$auto = $incoming ? $active['auto'] : array();
		$full_auto = $incoming && ! empty( $active['full_auto'] );
	}
	if ( $full_auto ) {
		$auto = array( 'approve', 'spam', 'trash' );
	}

	$decision = $result['decision'];
	wp_cache_delete( $post->ID, 'posts' );
	$current_post = get_post( $post->ID );
	$can_act = $active['enabled'] && $active === $options && $current_post && 'publish' === $current_post->post_status &&
		! $current_post->post_password && $post->post_title === $current_post->post_title;
	if ( $can_act && ( $full_auto || ( 'approve' === $decision && in_array( 'approve', $auto, true ) ) ) &&
		( $current->comment_parent || $comment->comment_parent ) ) {
		wp_cache_delete( $current->comment_parent, 'comment' );
		if ( (int) $current->comment_parent !== (int) $comment->comment_parent || $parent !== rcm_parent_text( $current ) ) {
			$result['decision'] = 'error';
			$result['reason'] = 'The reply context changed during review. Riot will retry.';
			$result['retryable'] = true;
			update_comment_meta( $id, '_rcm_result', $result );
			return 'errors';
		}
	}
	$apply = $can_act && in_array( $decision, $auto, true );
	$can_apply = $apply && rcm_can_auto_act( $decision, $current, $result, $full_auto );
	if ( $apply && $full_auto && ! $can_apply && 'trash' !== $decision ) {
		if ( 'approve' === $decision ) {
			$result['category'] = 'context';
			$result['reason'] = 'This comment did not pass the site’s automatic publishing checks.';
		}
		$decision = $result['decision'] = 'trash';
		$can_apply = rcm_can_auto_act( $decision, $current, $result, true );
	}
	if ( $can_apply ) {
		$statuses = array( 'approve' => 'approved', 'spam' => 'spam', 'trash' => 'trash' );
		$outcomes = array( 'approve' => 'approved', 'spam' => 'spammed', 'trash' => 'trashed' );
		$handlers = array( 'spam' => 'wp_spam_comment', 'trash' => 'wp_trash_comment' );
		$changed = isset( $handlers[ $decision ] ) ? $handlers[ $decision ]( $id ) : wp_set_comment_status( $id, 'approve' );
		if ( $changed && $statuses[ $decision ] === wp_get_comment_status( $id ) ) {
			$result['action'] = $decision;
			update_comment_meta( $id, '_rcm_result', $result );
			return $outcomes[ $decision ];
		}
	}

	if ( $can_act && $full_auto ) {
		wp_cache_delete( $id, 'comment' );
		$check = get_comment( $id );
		if ( ! $check || 'unapproved' !== wp_get_comment_status( $check ) || $check->comment_content !== $current->comment_content ) {
			return 'skipped';
		}
		$result['decision'] = 'error';
		$result['reason'] = 'WordPress could not apply the Full Auto decision. Check that comment Trash is enabled and retry.';
		$result['retryable'] = true;
		update_comment_meta( $id, '_rcm_result', $result );
		return 'errors';
	}

	return $full_auto ? 'skipped' : 'reviewed';
}

function rcm_can_auto_act( $decision, $comment, $result = array(), $full_auto = false ) {
	$text = html_entity_decode( $comment->comment_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( 'spam' === $decision ) {
		return strlen( $text ) <= 4000 && ! $comment->comment_parent;
	}
	if ( 'trash' === $decision ) {
		$text = trim( wp_strip_all_tags( $text ) );
		return defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0 && (
			$full_auto || ( ! $comment->comment_parent &&
			( '' === $text || preg_match( '/\A(?:[\p{P}\s]{4,100}|([a-zA-Z0-9])\1{11,100})\z/u', $text ) ) )
		);
	}
	if ( 'approve' !== $decision ) {
		return false;
	}
	if ( strlen( $text ) > 4000 || preg_match( '~http|www\.|<\s*(a|img|iframe)\b~i', $text ) ||
		preg_match( '/\[(?:link|email|IP address|phone)\]/', rcm_minimize( $text, 4000 ) ) ) {
		return false;
	}
	if ( ! $comment->comment_parent ) {
		return true;
	}
	// Approve a reply only when the model saw the comment it answers and that comment is still public.
	return ! empty( $result['parent_context'] ) && '' !== rcm_parent_text( $comment );
}

function rcm_lock() {
	$lease = time() + 180;
	if ( add_option( 'rcm_worker_lock', $lease, '', false ) ) {
		return $lease;
	}
	if ( (int) get_option( 'rcm_worker_lock' ) < time() ) {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name='rcm_worker_lock' AND CAST(option_value AS UNSIGNED)<%d", $lease, time() ) );
		wp_cache_delete( 'rcm_worker_lock', 'options' );
		if ( $updated ) {
			return $lease;
		}
	}
	return false;
}

add_action( 'rcm_process_queue', static function () {
	if ( ! rcm_ready() ) {
		return;
	}
	$delay = (int) get_option( 'rcm_cooldown', 0 ) - time();
	if ( $delay > 0 ) {
		rcm_schedule( $delay + 2 );
		return;
	}
	$lease = rcm_lock();
	if ( ! $lease ) {
		rcm_schedule( 60 );
		return;
	}
	try {
		rcm_process_jobs();
	} finally {
		if ( $lease === (int) get_option( 'rcm_worker_lock' ) ) {
			delete_option( 'rcm_worker_lock' );
		}
	}
} );

function rcm_process_jobs() {
	global $wpdb;
	$background = PHP_SAPI === 'cli' && defined( 'RCM_BACKGROUND_WORKER' ) && RCM_BACKGROUND_WORKER;
	$deadline = microtime( true ) + ( $background ? 50 : 25 );
	$chunk_size = $background ? 240 : 16;
	$max_parallel = rcm_can_parallel_requests() ? ( $background ? 12 : 6 ) : 1;
	$incoming = rcm_process_incoming( $deadline );
	if ( 'stop' === $incoming ) {
		return;
	}
	$batch = rcm_batch();
	$include_legacy = ! empty( $batch['include_legacy'] ) && ! empty( $batch['full_auto'] );
	for ( $processed = 0; $processed < $chunk_size && microtime( true ) < $deadline && ! empty( $batch['running'] ) && rcm_ready(); ) {
		$width = max( 1, min( $max_parallel, (int) ( $batch['parallel'] ?? 4 ) ) );
		$limit = min( $width, $chunk_size - $processed, (int) $batch['remaining'] );
		$ids = $limit > 0 ? $wpdb->get_col( $wpdb->prepare( "SELECT c.comment_ID FROM {$wpdb->comments} c
			WHERE c.comment_approved='0' AND c.comment_type IN ('','comment') AND c.comment_ID>%d AND c.comment_ID<=%d
			AND (%d=1 OR NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} w WHERE w.comment_id=c.comment_ID AND w.meta_key='_rcm_withheld'))
			AND c.comment_date_gmt>=%s AND (%d=0 OR NOT EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key='_rcm_reviewed'
			)) ORDER BY c.comment_ID ASC LIMIT %d", $batch['cursor'], $batch['max'], (int) $include_legacy, $batch['since'], empty( $batch['skip_reviewed'] ) ? 0 : 1, $limit ) ) : array();
		if ( ! $ids ) {
			$batch['running'] = false;
			$batch['remaining'] = 0;
			unset( $batch['buffer'] );
			update_option( 'rcm_batch', $batch, false );
			break;
		}
		$options = rcm_options();
		$full_auto = ! empty( $batch['full_auto'] );
		$snapshots = $inputs = $fingerprints = $results = array();
		foreach ( $ids as $id ) {
			$snapshot = rcm_review_snapshot( (int) $id, $include_legacy );
			if ( $snapshot ) {
				$snapshots[ $id ] = $snapshot;
				$input = array( $snapshot[1]->post_title, $snapshot[0]->comment_content, $snapshot[2] );
				$fingerprints[ $id ] = hash( 'sha256', serialize( array( $input, $snapshot[0]->comment_post_ID, $options, $full_auto, RCM_VERSION ) ) );
				$cached = $batch['buffer'][ $id ] ?? null;
				if ( $cached && $cached['fingerprint'] === $fingerprints[ $id ] ) {
					$results[ $id ] = $cached['result'];
				} else {
					$inputs[ $id ] = $input;
				}
			}
		}
		$fresh = rcm_request_reviews( $options, $inputs, $full_auto );
		if ( $width < $max_parallel && count( $inputs ) >= $width && ! in_array( 'error', array_column( $fresh, 'decision' ), true ) ) {
			$batch['parallel'] = $width + 1;
		}
		foreach ( $fresh as $id => $result ) {
			if ( 'error' !== $result['decision'] ) {
				$batch['buffer'][ $id ] = array( 'fingerprint' => $fingerprints[ $id ], 'result' => $result );
			}
		}
		$results += $fresh;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$latest  = rcm_batch();
			if ( empty( $latest['running'] ) || $latest['id'] !== $batch['id'] ) {
				rcm_schedule();
				return;
			}
			$outcome = isset( $snapshots[ $id ], $results[ $id ] ) ? rcm_apply_review( $snapshots[ $id ], $results[ $id ], $options, 'historical', $batch['id'] ) : 'skipped';
			unset( $batch['buffer'][ $id ] );
			$batch['last_activity'] = time();
			++$processed;
			$batch[ $outcome ] = 1 + (int) ( $batch[ $outcome ] ?? 0 );
			if ( 'errors' === $outcome ) {
				$attempt = (int) ( $batch['last_failed_id'] ?? 0 ) === $id ? 1 + (int) ( $batch['retry_attempts'] ?? 0 ) : 1;
				$batch['last_failed_id'] = $id;
				$batch['unprocessed_errors'] = 1 + (int) ( $batch['unprocessed_errors'] ?? 0 );
				$error = get_comment_meta( $id, '_rcm_result', true );
				$delay = rcm_retry_delay( $error, $attempt );
				if ( $delay ) {
					$batch['retry_attempts'] = $attempt;
					$batch['parallel'] = max( 1, intdiv( $width, 2 ) );
					$batch['retry_message'] = 'Temporary provider issue. Riot will retry automatically.';
					update_option( 'rcm_batch', $batch, false );
					rcm_backoff( $delay );
					return;
				}
				$batch['running'] = false;
				$batch['paused']  = true;
				$batch['stop_reason'] = 'Paused. ' . ( $error['reason'] ?? 'Check the saved connection, then resume.' );
				rcm_backoff( 300 );
			} else {
				unset( $batch['retry_attempts'], $batch['retry_message'] );
				$batch['cursor'] = $id;
				--$batch['remaining'];
			}
			if ( ! $batch['remaining'] ) {
				$batch['running'] = false;
				unset( $batch['buffer'] );
			}
			update_option( 'rcm_batch', $batch, false );
			if ( ! $batch['running'] ) {
				break;
			}
		}
	}
	if ( 'more' === $incoming || ! empty( $batch['running'] ) ) {
		rcm_schedule( ! empty( $batch['paused'] ) ? 305 : 15 );
	}
}

/**
 * Review queued new comments until the deadline. A failed review never drops a comment:
 * a provider or account problem pauses the queue until it recovers, and a comment the
 * provider keeps rejecting waits with a growing delay while the rest of the queue moves on.
 */
function rcm_process_incoming( $deadline ) {
	global $wpdb;
	$tries = array();
	while ( microtime( true ) < $deadline ) {
		if ( ! rcm_ready() ) {
			return 'stop';
		}
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT c.comment_ID, m.meta_value AS source FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->commentmeta} m ON c.comment_ID=m.comment_id AND m.meta_key='_rcm_job'
			WHERE c.comment_approved='0' AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} a WHERE a.comment_id=c.comment_ID AND a.meta_key='_rcm_job_after' AND CAST(a.meta_value AS UNSIGNED)>%d
			) ORDER BY c.comment_ID ASC LIMIT 1", time() ) );
		if ( ! $job ) {
			$next = (int) $wpdb->get_var( "SELECT MIN(CAST(a.meta_value AS UNSIGNED)) FROM {$wpdb->commentmeta} a
				INNER JOIN {$wpdb->commentmeta} m ON m.comment_id=a.comment_id AND m.meta_key='_rcm_job'
				INNER JOIN {$wpdb->comments} c ON c.comment_ID=a.comment_id AND c.comment_approved='0'
				WHERE a.meta_key='_rcm_job_after'" );
			if ( $next ) {
				rcm_schedule( max( 15, $next - time() + 1 ) );
			}
			return 'done';
		}
		$id = (int) $job->comment_ID;
		$tries[ $id ] = 1 + ( $tries[ $id ] ?? 0 );
		if ( $tries[ $id ] > 3 ) {
			// Safety net: never spin on one comment within a single run.
			update_comment_meta( $id, '_rcm_job_after', time() + 300 );
			continue;
		}
		$outcome = rcm_review_comment( $id, $job->source );
		if ( 'errors' !== $outcome ) {
			delete_comment_meta( $id, '_rcm_job', $job->source );
			delete_comment_meta( $id, '_rcm_job_retries' );
			delete_comment_meta( $id, '_rcm_job_after' );
			continue;
		}
		$error   = get_comment_meta( $id, '_rcm_result', true );
		$attempt = 1 + (int) get_comment_meta( $id, '_rcm_job_retries', true );
		update_comment_meta( $id, '_rcm_job_retries', $attempt );
		if ( ! empty( $error['retryable'] ) || in_array( (int) ( $error['http'] ?? 0 ), array( 401, 402, 403 ), true ) ) {
			// The provider or the account is the problem. Keep the comment queued and wait.
			rcm_backoff( rcm_retry_delay( $error, $attempt ) ?: 300 );
			return 'stop';
		}
		// The provider rejected this request (for example HTTP 400). Retry it later without blocking the queue.
		update_comment_meta( $id, '_rcm_job_after', time() + min( 6 * HOUR_IN_SECONDS, 300 * ( 2 ** min( 7, $attempt - 1 ) ) ) );
	}
	return 'more';
}

/**
 * After a settings change or a successful connection test, retry deferred comments right away.
 */
function rcm_release_deferred() {
	delete_metadata( 'comment', 0, '_rcm_job_after', '', true );
}

function rcm_clear_second_job( $id ) {
	delete_comment_meta( $id, '_rcm_second_job' );
	delete_comment_meta( $id, '_rcm_second_retries' );
	delete_comment_meta( $id, '_rcm_second_after' );
}

function rcm_retry_delay( $error, $attempt ) {
	$delays = array( 1 => 3, 2 => 8, 3 => 20 );
	return ! empty( $error['retryable'] ) && isset( $delays[ $attempt ] )
		? max( $delays[ $attempt ], (int) ( $error['retry_after'] ?? 0 ) ) : 0;
}

function rcm_backoff( $delay ) {
	update_option( 'rcm_cooldown', time() + $delay, false );
	rcm_schedule( $delay + 1 );
}
