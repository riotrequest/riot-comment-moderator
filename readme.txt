=== Riot Comment Moderator ===
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 1.3.10
License: MIT

Review pending WordPress comments using OpenAI, OpenRouter, or either provider's Decisions API. Works with Riot Comments and with the native WordPress comment form.

== Start here ==

1. Download riot-comment-moderator.zip from https://github.com/riotrequest/riot-comment-moderator/releases/latest, upload it under Plugins > Add New Plugin > Upload Plugin, and activate it. Open Comments > Riot Moderator.
2. Set up encrypted key storage and open the dashboard over HTTPS. Choose a provider and model, add its API key, and save.
3. Click Send test comment. It uses a synthetic example and does not touch the queue.
4. Enable provider processing. Leave the automatic-action boxes unchecked to see recommendations and reasons in the Comments screen.

OpenRouter presets include GLM 5.3 Flash (z-ai/glm-5.3-flash) and DeepSeek V4.1 Flash (deepseek/deepseek-v4.1-flash). Model responses must support structured JSON output. API keys can also be configured as RIOT_COMMENT_MODERATOR_OPENAI_KEY and RIOT_COMMENT_MODERATOR_OPENROUTER_KEY constants in wp-config.php.

OpenAI Decisions uses GPT-6 Luna. OpenRouter Decisions uses the OpenRouter alpha endpoint and offers Microsoft Decision 1.1, TypeSafe Jev 1.14, and Gemini Decision 1 as presets. Both use the saved key for their respective provider and return a choice with a reason category.

The OpenAI and OpenRouter chat options save reasoning effort separately. Presets default to the lowest enabled effort and offer None when the model supports it. Custom models may support a different set of levels.

For dashboard key storage, define RIOT_COMMENT_MODERATOR_CREDENTIAL_KEY as a base64-encoded random 32-byte secret in private server config loaded by wp-config.php. Keep that secret outside the web root and back it up separately from the database. An administrator's next WordPress admin request encrypts previously saved Riot keys. Preserve the server secret when restoring the site's database.

== Updates ==

On an older install, upload version 1.3.10 or later once and choose Replace current with uploaded. Settings and existing key configuration stay on the site. Future stable GitHub releases appear under Plugins and Dashboard > Updates while Riot Comment Moderator is active in wp-content/plugins/riot-comment-moderator/. Update checks use the public GitHub Releases API.

== Editorial prompt ==

The prompt is editable. The default names the site and is designed for advertising-supported news discussion. It judges political viewpoints by the same rules. The model looks for off-topic promotional links, generic praise paired with a pitch, scam and contact solicitations, and commercial bot templates. It weighs context for criticism, brief reactions, and citations. This is an editorial aid. It cannot verify AdSense compliance.

== Pending comments and automation ==

The main checkboxes apply to newly pending comments. Choose a mode when starting a historical batch: Full Auto, selected automatic actions, or recommendations only. Use Days back = 0 to include all existing pending dates. Leave Skip comments with prior recommendations unchecked to process previously recommended comments in Full Auto. An editor can select rows in Comments and choose Get Riot recommendations to review those comments without applying automatic actions.

Automatic actions require a matching structured decision and local checks. A reply is reviewed with the published comment it answers. Links, contact details, and unusually long comments prevent automatic approval. In Selected automatic actions, Spam and Trash apply only to top-level comments, and Trash is limited to obvious character noise.

Require Riot review before publication (off by default) holds new reader comments that WordPress would otherwise publish immediately, including replies and comments from previously approved commenters. Staff who can moderate comments are exempt, and WordPress's own spam and moderation results are unchanged. It is inactive while provider processing or new-comment review is off. Pair it with Full Auto and the per-minute worker so Riot Comments can update the commenter's preview after a decision.

Full Auto uses one review per comment to choose Approve, Spam, or Trash. An approval or spam decision that fails a local check goes to WordPress Trash when retention is enabled. Provider failures and unusable responses leave the comment queued for retry. Review or restore actions in the WordPress Comments screens.

Full Auto creates no new Withheld entries. Existing Withheld comments stay private on upgrade and can be processed by starting a historical Full Auto batch in their date range. Leave Skip comments with prior recommendations unchecked to include them. Comments in Trash follow the site's WordPress Trash retention.

Provider failures never drop a new comment from the queue. Connection, rate-limit, credit, and key problems pause the queue and retry after a cooldown. A comment the provider rejects on its own waits with a growing delay while other comments continue. Saving settings or passing the provider test retries waiting comments immediately. Historical batches pause on persistent errors. Use the provider test after checking the key or model, then resume the batch. The historical batch can skip saved recommendations to pick up new work in later runs.

== Background worker (recommended) ==

Without a host cron, new comments wait for WordPress Cron, which on many hosts runs every five minutes or only on traffic. Run the worker every minute as the site user instead. In cPanel, add this under Cron Jobs (Once Per Minute), adjusting the path:

  cd /home/ACCOUNT/public_html && /usr/bin/flock -n ../.riot-moderator-worker.lock /usr/bin/timeout 145 /usr/local/bin/php /usr/local/bin/wp --quiet riot-moderator tick >/dev/null 2>&1

The worker reviews new comments within about a minute and continues historical batches with up to 12 concurrent provider requests. It honors batch pauses, provider cooldowns, and the shared queue lock, and exits immediately when there is nothing to do.

== Data sent ==

The selected service receives the editorial prompt, article title, and comment text. WordPress author, account, and client IP fields are omitted. Riot redacts common links, inline email addresses, IP addresses, and phone numbers from submitted text. Typed text can still contain identifying details. Review your privacy disclosures and provider terms before enabling processing. Keys are entered in the WordPress admin or configured server-side.

== Changelog ==

= 1.3.10 =
* Add WordPress dashboard updates from stable GitHub releases.

= 1.3.9 =
* Add a model-aware reasoning effort selector for OpenAI and OpenRouter chat models.

= 1.3.8 =
* Add OpenAI Decisions and OpenRouter Decisions as selectable providers, with separate model settings for OpenRouter Decisions.

= 1.3.7 =
* Make one Full Auto review choose Approve, Spam, or Trash for new and historical comments.
* Encrypt provider keys with a separate server secret and require HTTPS for dashboard key changes.
* Keep authorization headers out of WordPress HTTP debug arguments.

= 1.3.6 =
* Integrate final approve-or-trash review into Full Auto for new and historical comments.
* Include older Withheld comments in newly started historical Full Auto batches.

= 1.3.5 =
* New setting, off by default: Second opinion for borderline comments. A stronger model rereads comments Full Auto withheld as ambiguous, an advertising risk, or unreadable, and can publish them. Abuse stays withheld.
* Queue existing Withheld comments for a second opinion from the dashboard or with wp riot-moderator second-opinion --queue.
* The Comments screen shows each second opinion and its outcome.

= 1.3.4 =
* New comments are reviewed within about a minute: the host-cron worker (wp riot-moderator tick) now handles new comments as well as historical batches, and works through the whole queue each run.
* A failed review no longer drops a new comment from the queue. Provider-wide problems pause and retry; a comment the provider rejects is retried later without blocking others.
* Replies are reviewed with the published comment they answer and can be approved automatically.
* The default editorial prompt uses the site's name, for use on other sites.
* New setting, off by default: Require Riot review before publication holds every new reader comment until Riot reviews it, including comments from previously approved commenters.

= 1.3.3 =
* Add a high-throughput mode for scheduled WP-CLI workers, with longer chunks and up to 12 concurrent provider requests.

= 1.3.2 =
* Require a batch mode choice and include prior recommendations by default when starting Full Auto.
* Use a dedicated Full Auto verdict and improve the model's guidance on spam, context, and publishable discussion.

= 1.3.1 =
* Increase healthy historical batches to six parallel requests and larger chunks.
* Retry temporary provider failures with backoff, honor Retry-After, and reuse completed responses from the same group. Persistent and configuration errors still pause the batch.

= 1.3.0 =
* Add optional Full Auto, a private Withheld queue, and live Withheld totals.

= 1.2.0 =
* Review up to three historical comments concurrently and commit results in order through the existing queue lock.

= 1.1.4 =
* Show all presets in native model dropdowns. Choose Custom model to enter another model ID.

= 1.1.3 =
* Continue historical work automatically in the open dashboard. WordPress Cron continues the batch when the dashboard is closed.
* Suggest GPT-6 Luna by default for OpenAI and offer GPT-6.2 Sol as an alternative.

= 1.1.2 =
* Retry unusable model output once and leave individual comments for editors when the model cannot classify them. Provider connection and quota errors still pause historical batches.

= 1.1.1 =
* Use the OpenRouter model’s reasoning defaults and allow space for reasoning.
* Identify common provider errors in the dashboard and retry the failed item when a batch resumes.

= 1.1.0 =
* Rebuilt the dashboard around provider selection and live historical batch progress.
* Settings, connection tests, and batch controls now use AJAX. Start and resume run a first chunk immediately while the site's background scheduler continues the batch.

= 1.0.0 =
* OpenAI and OpenRouter reviews, editable policy, historical queue, and separate automatic actions.
