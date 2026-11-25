<?php
defined( 'ABSPATH' ) || exit;

function rcm_enqueue_dashboard() {
	wp_enqueue_style( 'rcm-dashboard', plugins_url( 'dashboard.css', __FILE__ ), array(), RCM_VERSION );
	wp_enqueue_script( 'rcm-dashboard', plugins_url( 'dashboard.js', __FILE__ ), array(), RCM_VERSION, true );
}

function rcm_dashboard_state() {
	$options = rcm_options();
	$batch   = rcm_batch();
	$keys    = array( 'openai' => '' !== rcm_key( 'openai' ), 'openrouter' => '' !== rcm_key( 'openrouter' ) );
	$counts  = array();
	foreach ( array( 'reviewed', 'approved', 'spammed', 'trashed', 'withheld', 'errors', 'skipped' ) as $name ) {
		$counts[ $name ] = (int) ( $batch[ $name ] ?? 0 );
	}

	$blocker = '';
	$providers = rcm_providers();
	$provider = $providers[ $options['provider'] ];
	$key = $provider['key'];
	if ( ! $keys[ $key ] ) {
		$blocker = 'Add and save an ' . $providers[ $key ]['label'] . ' API key first.';
	} elseif ( ! $options['enabled'] ) {
		$blocker = 'Enable provider processing and save your settings first.';
	}
	$now = time();
	$working = (int) get_option( 'rcm_worker_lock' ) > $now;
	$cooldown = (int) get_option( 'rcm_cooldown' );
	$next = (int) wp_next_scheduled( 'rcm_process_queue' );
	$running = ! empty( $batch['running'] );
	$paused  = ! empty( $batch['paused'] );
	$state   = 'idle';
	$label   = 'No batch yet';
	$detail  = $blocker ?: 'Choose a date range and start a batch when you’re ready.';
	if ( $running ) {
		$state  = $working ? 'working' : 'queued';
		$label  = $working ? 'Reviewing comments' : 'Running';
		$detail = $working ? 'The worker is checking comments. Counts update as reviews finish.' : 'The batch continues automatically. You can leave this page.';
		if ( $blocker ) {
			$state = 'blocked'; $label = 'Setup needed'; $detail = $blocker;
		} elseif ( $cooldown > $now ) {
			$state = 'paused'; $label = 'Waiting to retry'; $detail = $batch['retry_message'] ?? 'Provider processing will retry after the cooldown.';
		}
	} elseif ( $paused ) {
		$state = 'paused'; $label = 'Paused';
		$detail = $batch['stop_reason'] ?? 'The batch is paused. Resume it to continue from the last reviewed comment.';
	} elseif ( $batch ) {
		$state = 'finished'; $label = 'Finished';
		$detail = 'This batch is complete. See the outcomes below or open the pending queue.';
	} elseif ( $blocker ) {
		$state = 'blocked'; $label = 'Setup needed';
	}
	$processed = max( 0, array_sum( $counts ) - (int) ( $batch['unprocessed_errors'] ?? 0 ) );
	return array(
		'provider' => $provider['label'], 'model' => rcm_provider_model( $options ), 'keys' => $keys,
		'blocker' => $blocker, 'state' => $state, 'label' => $label, 'detail' => $detail, 'working' => $working,
		'running' => $running, 'resumable' => $paused && ! empty( $batch['remaining'] ),
		'batch_id' => $batch['id'] ?? '',
		'processed' => $processed, 'total' => $processed + (int) ( $batch['remaining'] ?? 0 ),
		'counts' => $counts, 'actions' => ! empty( $batch['full_auto'] )
			? array( 'Full Auto' ) : array_values( array_intersect_key( rcm_action_labels(), array_flip( $batch['auto'] ?? array() ) ) ),
		'pending' => number_format_i18n( max( 0, (int) wp_count_comments()->moderated - rcm_withheld_count() ) ),
		'next' => $next, 'now' => $now, 'cooldown' => $cooldown,
		'last' => (int) ( $batch['last_activity'] ?? 0 ),
	);
}

function rcm_control( $action, $input ) {
	$batch = rcm_batch();
	if ( 'test' === $action ) {
		$options = rcm_options();
		$sample = array( 'City council approves park renovation', 'I am glad the playground will be repaired.' );
		$result = rcm_request_review( $options, $sample[0], $sample[1], ! empty( $options['full_auto'] ) || ! empty( $batch['full_auto'] ) );
		$failed = 'error' === $result['decision'] || ! empty( $result['fallback'] );
		update_option( 'rcm_test', array( 'time' => time(), 'result' => $result ), false );
		if ( ! $failed ) {
			delete_option( 'rcm_cooldown' );
			rcm_release_deferred();
			rcm_schedule();
		}
		return array( 'message' => $result['reason'], 'test' => $result, 'error' => $failed );
	}
	if ( 'pause' === $action ) {
		if ( ! $batch || empty( $batch['running'] ) ) {
			return array( 'message' => 'There is no running batch to pause.', 'error' => true );
		}
		$batch['running'] = false;
		$batch['paused'] = true;
		$batch['stop_reason'] = 'Paused by an editor.';
		update_option( 'rcm_batch', $batch, false );
		return array( 'message' => 'Batch paused.' );
	}
	if ( ! in_array( $action, array( 'start', 'resume', 'run' ), true ) ) {
		return array( 'message' => 'Unknown action.', 'error' => true );
	}
	if ( ! rcm_ready() ) {
		return array( 'message' => rcm_dashboard_state()['blocker'], 'error' => true );
	}
	if ( 'run' === $action ) {
		if ( empty( $batch['running'] ) || ( $input['rcm_batch_id'] ?? '' ) !== $batch['id'] ) {
			return array( 'message' => 'The batch changed. The dashboard will refresh its status.', 'error' => true );
		}
		if ( (int) get_option( 'rcm_cooldown' ) > time() ) {
			return array( 'message' => 'Waiting for the provider cooldown.', 'error' => true );
		}
		do_action( 'rcm_process_queue' );
		return array( 'message' => rcm_dashboard_state()['detail'] );
	}
	if ( ! empty( $batch['running'] ) ) {
		return array( 'message' => 'A historical batch is already running.', 'error' => true );
	}
	if ( 'resume' === $action ) {
		if ( empty( $batch['paused'] ) || empty( $batch['remaining'] ) ) {
			return array( 'message' => 'There is no paused batch to resume.', 'error' => true );
		}
		// Older versions advanced the cursor past the item that caused a provider pause.
		if ( empty( $batch['last_failed_id'] ) && 'Paused after a provider error. Check the saved connection, then resume.' === ( $batch['stop_reason'] ?? '' ) ) {
			$item = get_comment_meta( $batch['cursor'], '_rcm_result', true );
			if ( 'error' === ( $item['decision'] ?? '' ) && 'historical' === ( $item['source'] ?? '' ) ) {
				$batch['last_failed_id'] = $batch['cursor'];
				--$batch['cursor'];
				++$batch['remaining'];
				$batch['unprocessed_errors'] = 1 + (int) ( $batch['unprocessed_errors'] ?? 0 );
			}
		}
		$batch['id'] = wp_generate_uuid4();
		$batch['running'] = true;
		$batch['paused'] = false;
		unset( $batch['stop_reason'], $batch['retry_attempts'], $batch['retry_message'] );
		delete_option( 'rcm_cooldown' );
		update_option( 'rcm_batch', $batch, false );
		rcm_schedule();
		return array( 'message' => 'Batch resumed.' );
	}
	global $wpdb;
	$mode = $input['batch_mode'] ?? '';
	if ( ! in_array( $mode, array( 'full_auto', 'selected', 'recommend' ), true ) ) {
		return array( 'message' => 'Choose a moderation mode before starting the batch.', 'error' => true );
	}
	$auto = 'selected' === $mode ? rcm_parse_actions( $input['batch_auto'] ?? array() ) : array();
	if ( 'selected' === $mode && ! $auto ) {
		return array( 'message' => 'Choose at least one automatic action for this batch.', 'error' => true );
	}
	$days = min( 3650, absint( $input['days'] ?? 30 ) );
	$limit = max( 1, min( 5000, absint( $input['limit'] ?? 100 ) ) );
	$skip = empty( $input['skip_reviewed'] ) ? 0 : 1;
	$full_auto = (int) ( 'full_auto' === $mode );
	$since = $days ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '1000-01-01 00:00:00';
	$eligible = $wpdb->get_row( $wpdb->prepare( "SELECT MAX(c.comment_ID) AS last_id, COUNT(*) AS total FROM {$wpdb->comments} c
		WHERE c.comment_approved='0' AND c.comment_type IN ('','comment') AND c.comment_date_gmt >= %s
		AND (%d=1 OR NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} w WHERE w.comment_id=c.comment_ID AND w.meta_key='_rcm_withheld'))
		AND (%d=0 OR NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id=c.comment_ID AND m.meta_key='_rcm_reviewed'))", $since, $full_auto, $skip ) );
	if ( empty( $eligible->total ) ) {
		return array( 'message' => 'No eligible pending comments matched this date range.', 'error' => true );
	}
	update_option( 'rcm_batch', array(
		'id' => wp_generate_uuid4(), 'running' => true, 'paused' => false, 'cursor' => 0,
		'max' => (int) $eligible->last_id, 'since' => $since, 'remaining' => min( $limit, (int) $eligible->total ),
		'skip_reviewed' => $skip, 'auto' => $auto,
		'full_auto' => $full_auto, 'include_legacy' => $full_auto,
		'reviewed' => 0, 'approved' => 0, 'spammed' => 0, 'trashed' => 0, 'withheld' => 0, 'errors' => 0, 'skipped' => 0,
	), false );
	rcm_schedule();
	return array( 'message' => 'full_auto' === $mode ? 'Full Auto batch queued.' : 'Historical batch queued.' );
}

add_action( 'wp_ajax_rcm_dashboard', static function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'rcm_dashboard', '_ajax_nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Your dashboard session expired. Refresh to sign in again.' ), 403 );
	}
	$action = sanitize_key( $_POST['rcm_action'] ?? 'status' );
	$result = array();
	if ( 'save' === $action ) {
		$input = isset( $_POST['rcm_settings'] ) && is_array( $_POST['rcm_settings'] ) ? wp_unslash( $_POST['rcm_settings'] ) : array();
		update_option( 'rcm_settings', $input );
		rcm_schedule();
		$errors = get_settings_errors( 'rcm_settings' );
		$result = array( 'message' => $errors ? $errors[0]['message'] : 'Settings saved.', 'error' => (bool) $errors );
	} elseif ( 'status' !== $action ) {
		$result = rcm_control( $action, wp_unslash( $_POST ) );
	}
	wp_send_json_success( $result + array( 'dashboard' => rcm_dashboard_state() ) );
} );

add_action( 'admin_post_rcm_control', static function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You cannot manage these settings.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'rcm_control' );
	$result = rcm_control( sanitize_key( $_POST['rcm_action'] ?? '' ), wp_unslash( $_POST ) );
	set_transient( 'rcm_notice_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'page', 'riot-comment-moderator', admin_url( 'edit-comments.php' ) ) );
	exit;
} );

function rcm_action_fields( $name, $selected ) {
	foreach ( rcm_action_labels() as $value => $label ) {
		printf( '<label class="rcm-check"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
			esc_attr( $name ), esc_attr( $value ), checked( in_array( $value, $selected, true ), true, false ), esc_html( $label ) );
	}
}

function rcm_settings_page() {
	$options = rcm_options();
	$view = rcm_dashboard_state();
	$providers = rcm_providers();
	$key_provider = $providers[ $options['provider'] ]['key'];
	$key_entry = is_ssl() && false !== rcm_credential_master_key();
	$test = get_option( 'rcm_test' );
	$notice_key = 'rcm_notice_' . get_current_user_id();
	$notice = get_transient( $notice_key );
	if ( $notice ) {
		delete_transient( $notice_key );
	}
	$control_url = admin_url( 'admin-post.php' );
	?>
	<div class="wrap rcm" data-rcm data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rcm_dashboard' ) ); ?>" data-state="<?php echo esc_attr( wp_json_encode( $view ) ); ?>">
		<header class="rcm-hero">
			<div><h1>Riot Comment Moderator</h1><p>Review reader comments, set the editorial policy, and decide what goes live.</p></div>
			<div class="rcm-hero-actions">
				<a class="rcm-hero-link" href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>">Pending queue <span aria-hidden="true">↗</span></a>
				<?php if ( rcm_withheld_count() ) : ?><a class="rcm-hero-link" href="<?php echo esc_url( rcm_withheld_url() ); ?>">Legacy Withheld <span aria-hidden="true">↗</span></a><?php endif; ?>
			</div>
		</header>
		<div role="status" aria-live="polite" aria-atomic="true" class="rcm-toast" data-toast <?php echo $notice ? '' : 'hidden'; ?>><?php echo esc_html( $notice['message'] ?? '' ); ?></div>
		<div class="rcm-layout">
			<section class="rcm-card rcm-batch">
				<div class="rcm-card-head"><div><h2>Historical moderation</h2><p>Review the comments already in your queue.</p></div><span class="rcm-pill" data-run-state="<?php echo esc_attr( $view['state'] ); ?>"><i aria-hidden="true"></i><span data-run-label><?php echo esc_html( $view['label'] ); ?></span></span></div>
				<div class="rcm-progress">
					<div class="rcm-progress-head"><strong data-progress-label><?php echo esc_html( $view['processed'] . ' of ' . $view['total'] . ' processed' ); ?></strong><span><b data-pending><?php echo esc_html( $view['pending'] ); ?></b> pending on site</span></div>
					<progress data-progress aria-label="Historical batch progress" value="<?php echo esc_attr( $view['processed'] ); ?>" max="<?php echo esc_attr( max( $view['total'], 1 ) ); ?>"></progress>
					<p data-run-detail aria-live="polite"><?php echo esc_html( $view['detail'] ); ?></p>
					<p class="rcm-meta" data-timing></p>
				</div>
				<dl class="rcm-counts">
					<?php foreach ( array( 'reviewed' => 'Recommendations', 'approved' => 'Approved', 'spammed' => 'Spam', 'trashed' => 'Trashed', 'withheld' => 'Legacy Withheld', 'errors' => 'Errors', 'skipped' => 'Skipped' ) as $key => $label ) : ?>
						<div <?php echo 'withheld' === $key && ! $view['counts'][ $key ] ? 'hidden' : ''; ?>><dt><?php echo esc_html( $label ); ?></dt><dd data-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $view['counts'][ $key ] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<form action="<?php echo esc_url( $control_url ); ?>" method="post" data-rcm-form>
					<input type="hidden" name="action" value="rcm_control">
					<?php wp_nonce_field( 'rcm_control' ); ?>
					<div class="rcm-running-controls" data-running <?php echo $view['running'] ? '' : 'hidden'; ?>>
						<button class="rcm-btn rcm-btn-secondary" name="rcm_action" value="pause">Pause batch</button>
						<span class="rcm-meta" data-active-actions></span>
					</div>
					<div data-start-panel <?php echo $view['running'] ? 'hidden' : ''; ?>>
						<p data-resumable <?php echo $view['resumable'] ? '' : 'hidden'; ?>><button class="rcm-btn rcm-btn-secondary" name="rcm_action" value="resume" data-resume>Resume previous batch</button> <span class="rcm-meta" data-paused-actions></span></p>
						<div class="rcm-fields">
							<label>Days back <input name="days" type="number" value="30" min="0" max="3650"><small>0 includes all dates</small></label>
							<label>Comments in this run <input name="limit" type="number" value="100" min="1" max="5000"><small>Maximum 5,000</small></label>
						</div>
						<div class="rcm-full-auto">
							<div class="rcm-field"><label for="rcm-batch-mode">Batch mode</label><select id="rcm-batch-mode" name="batch_mode" required data-batch-mode>
								<option value="">Choose a moderation mode</option>
								<option value="full_auto">Full Auto</option>
								<option value="selected">Selected automatic actions</option>
								<option value="recommend">Recommendations only</option>
							</select></div>
							<p data-mode-detail>Full Auto approves, marks spam, or moves each comment to Trash after one review. This includes older withheld comments in the selected range.</p>
						</div>
						<label class="rcm-check"><input type="checkbox" name="skip_reviewed" value="1" data-skip-reviewed> Skip comments with prior recommendations</label>
						<details class="rcm-disclosure" data-batch-actions><summary>Automatic actions for this batch</summary><?php rcm_action_fields( 'batch_auto', array() ); ?></details>
						<div class="rcm-submit"><button class="rcm-btn rcm-btn-primary" name="rcm_action" value="start" data-start <?php disabled( (bool) $view['blocker'] ); ?>>Start batch</button><span data-batch-blocker><?php echo esc_html( $view['blocker'] ?: 'Uses the saved ' . $view['provider'] . ' settings.' ); ?></span></div>
					</div>
				</form>
			</section>

			<section class="rcm-card rcm-setup">
				<div class="rcm-card-head"><div><h2>Provider setup</h2><p>Choose a provider to see its model and API key.</p></div></div>
				<?php if ( ! $key_entry ) : ?><p>HTTPS and a server encryption key are required to save API keys here.</p><?php endif; ?>
				<?php settings_errors( 'rcm_settings' ); ?>
				<form action="options.php" method="post" data-rcm-form data-settings>
					<?php settings_fields( 'rcm' ); ?>
					<div class="rcm-field"><label for="rcm-provider">Provider</label><select id="rcm-provider" name="rcm_settings[provider]" data-provider>
						<?php foreach ( $providers as $id => $details ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" data-key-provider="<?php echo esc_attr( $details['key'] ); ?>" <?php selected( $options['provider'], $id ); ?>><?php echo esc_html( $details['label'] ); ?></option>
						<?php endforeach; ?>
					</select></div>
					<?php foreach ( $providers as $id => $details ) : ?>
						<div data-provider-model="<?php echo esc_attr( $id ); ?>" <?php echo $options['provider'] !== $id ? 'hidden' : ''; ?>>
							<?php if ( isset( $details['fixed_model'] ) ) : ?>
								<p class="rcm-field">Model <strong>GPT-6 Luna</strong></p>
							<?php else : ?>
								<?php
								$presets = rcm_model_presets( $id );
								$model = $options[ $id . '_model' ];
								$efforts = rcm_reasoning_efforts( $id, $model );
								?>
								<div class="rcm-field">
									<label for="rcm-model-<?php echo esc_attr( $id ); ?>">Model</label>
									<select id="rcm-model-<?php echo esc_attr( $id ); ?>" name="rcm_settings[<?php echo esc_attr( $id ); ?>_preset]" data-model-preset>
										<?php foreach ( $presets as $slug => $name ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" data-efforts="<?php echo esc_attr( implode( ' ', rcm_reasoning_efforts( $id, $slug ) ) ); ?>" data-default-effort="<?php echo esc_attr( rcm_default_reasoning_effort( $id, $slug ) ); ?>" <?php selected( $model, $slug ); ?>><?php echo esc_html( $name ); ?></option>
										<?php endforeach; ?>
										<option value="custom" data-efforts="<?php echo esc_attr( implode( ' ', rcm_reasoning_efforts( $id, '' ) ) ); ?>" <?php selected( ! isset( $presets[ $model ] ) ); ?>>Custom model…</option>
									</select>
								</div>
								<label class="rcm-field" data-custom-model>Custom model ID <input name="rcm_settings[<?php echo esc_attr( $id ); ?>_model]" value="<?php echo esc_attr( isset( $presets[ $model ] ) ? '' : $model ); ?>" maxlength="120" spellcheck="false"></label>
								<?php if ( $efforts ) : ?>
									<?php
									$labels = rcm_reasoning_labels();
									$effort = rcm_reasoning_effort( $id, $model, $options[ $id . '_reasoning_effort' ] );
									$default = isset( $presets[ $model ] ) ? rcm_default_reasoning_effort( $id, $model ) : '';
									?>
									<div class="rcm-field">
										<label for="rcm-effort-<?php echo esc_attr( $id ); ?>">Reasoning effort</label>
										<select id="rcm-effort-<?php echo esc_attr( $id ); ?>" name="rcm_settings[<?php echo esc_attr( $id ); ?>_reasoning_effort]" data-reasoning-effort>
											<option value="" <?php selected( $effort, '' ); ?>><?php echo esc_html( isset( $labels[ $default ] ) ? 'Default (' . $labels[ $default ] . ')' : 'Default' ); ?></option>
											<?php foreach ( $labels as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php echo in_array( $value, $efforts, true ) ? '' : 'hidden disabled'; ?> <?php selected( $effort, $value ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php foreach ( array( 'openai', 'openrouter' ) as $key ) : ?>
						<div class="rcm-provider-fields" data-provider-panel="<?php echo esc_attr( $key ); ?>" <?php echo $key_provider !== $key ? 'hidden' : ''; ?>>
							<label class="rcm-field"><?php echo esc_html( $providers[ $key ]['label'] ); ?> API key <input name="rcm_settings[<?php echo esc_attr( $key ); ?>_key]" type="password" autocomplete="new-password" data-key="<?php echo esc_attr( $key ); ?>" placeholder="<?php echo $view['keys'][ $key ] ? 'Configured' : 'Enter key'; ?>" <?php disabled( ! $key_entry ); ?>></label>
							<label class="rcm-check rcm-muted"><input type="checkbox" name="rcm_settings[<?php echo esc_attr( $key ); ?>_forget]" value="1" <?php disabled( ! is_ssl() ); ?>> Remove saved key</label>
						</div>
					<?php endforeach; ?>
					<div class="rcm-setup-options">
						<label class="rcm-check"><input type="checkbox" name="rcm_settings[enabled]" value="1" <?php checked( $options['enabled'] ); ?>> Enable provider processing</label>
						<label class="rcm-check"><input type="checkbox" name="rcm_settings[incoming]" value="1" <?php checked( $options['incoming'] ); ?>> Review new pending comments</label>
						<div class="rcm-full-auto">
							<label class="rcm-check"><input type="checkbox" name="rcm_settings[gate]" value="1" <?php checked( $options['gate'] ); ?>> <strong>Require Riot review before publication</strong></label>
							<p>Holds every new reader comment, including replies and comments from previously approved commenters, until Riot reviews it. Staff who can moderate comments are exempt. Pair with Full Auto so approved comments go live within about a minute.</p>
						</div>
						<div class="rcm-full-auto">
							<label class="rcm-check"><input type="checkbox" name="rcm_settings[full_auto]" value="1" data-full-auto <?php checked( $options['full_auto'] ); ?>> <strong>Full Auto for new comments</strong></label>
							<p>One review decides whether to publish the comment, mark it as spam, or move it to Trash.</p>
						</div>
						<details class="rcm-disclosure" data-individual-actions><summary>Individual automatic actions on new comments</summary><?php rcm_action_fields( 'rcm_settings[auto]', $options['auto'] ); ?></details>
						<details class="rcm-disclosure"><summary>Editorial prompt</summary>
							<label class="rcm-field"><span class="screen-reader-text">Editorial prompt</span><textarea name="rcm_settings[prompt]" maxlength="5000" rows="7"><?php echo esc_textarea( $options['prompt'] ); ?></textarea></label>
							<p>The default uses conservative advertising guidelines for news comments. <a href="https://support.google.com/adsense/answer/10008391">Google Publisher guidance</a></p>
						</details>
					</div>
					<button class="rcm-btn rcm-btn-primary" type="submit">Save settings</button>
				</form>
				<form action="<?php echo esc_url( $control_url ); ?>" method="post" data-rcm-form class="rcm-test">
					<input type="hidden" name="action" value="rcm_control">
					<?php wp_nonce_field( 'rcm_control' ); ?>
					<button class="rcm-btn rcm-btn-secondary" name="rcm_action" value="test" data-test-button>Send test comment</button>
					<p>Uses a sample comment with the saved model.</p>
					<p class="rcm-test-result" data-test-outcome <?php echo is_array( $test ) ? '' : 'hidden'; ?>><?php echo esc_html( is_array( $test ) ? $test['result']['model'] . ' · ' . $test['result']['reason'] : '' ); ?></p>
				</form>
			</section>
		</div>
	</div>
	<?php
}
