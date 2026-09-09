-- The initial schema.
--
-- Carried over from the WordPress plugin's dbDelta definitions, minus dbDelta's
-- formatting rules (the two spaces before PRIMARY KEY, the one-definition-per-
-- line requirement) which existed only to satisfy its parser.
--
-- Three tables are new, and they are the ones WordPress used to provide:
-- users, sessions and options.
--
-- {prefix} is replaced by the configured table prefix before this runs.

-- People who sign in. Replaces wp_users.
CREATE TABLE IF NOT EXISTS `{prefix}users` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	email varchar(190) NOT NULL,
	display_name varchar(190) NOT NULL DEFAULT '',
	role varchar(40) NOT NULL DEFAULT 'serve_ministry_leader',
	password_hash varchar(255) NOT NULL,
	is_active tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	last_login_at datetime DEFAULT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY email (email),
	KEY role (role),
	KEY is_active (is_active)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Live sign-ins. Only the hash of the token is stored, so a stolen database
-- does not hand over working sessions.
CREATE TABLE IF NOT EXISTS `{prefix}sessions` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL,
	token_hash char(64) NOT NULL,
	ip varchar(45) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	used_at datetime NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY token_hash (token_hash),
	KEY user_id (user_id),
	KEY used_at (used_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Settings. Replaces the WordPress options API.
-- Column names match WordPress's, because ported domain code writes raw SQL
-- against option_name. See 002 for what went wrong when they did not.
CREATE TABLE IF NOT EXISTS `{prefix}options` (
	option_name varchar(190) NOT NULL,
	option_value longtext NOT NULL,
	PRIMARY KEY (option_name)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Submissions. profile_json holds the full SHAPE payload; the broken out
-- columns exist purely so the leader list can filter and sort without
-- unpacking JSON for every row.
CREATE TABLE IF NOT EXISTS `{prefix}submissions` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	uuid char(36) NOT NULL,
	display_name varchar(190) NOT NULL DEFAULT '',
	email varchar(190) NOT NULL DEFAULT '',
	phone varchar(60) NOT NULL DEFAULT '',
	status varchar(32) NOT NULL DEFAULT 'submitted',
	tenure_months smallint(5) unsigned DEFAULT NULL,
	gifts_likely text NOT NULL,
	languages text NOT NULL,
	suggested_teams text NOT NULL,
	profile_json longtext NOT NULL,
	match_snapshot longtext DEFAULT NULL,
	match_version varchar(64) DEFAULT NULL,
	verified_at datetime DEFAULT NULL,
	verify_token char(64) DEFAULT NULL,
	verify_sent_at datetime DEFAULT NULL,
	invite_token char(64) DEFAULT NULL,
	invited_at datetime DEFAULT NULL,
	invite_method varchar(16) DEFAULT NULL,
	invite_response varchar(16) DEFAULT NULL,
	invite_responded_at datetime DEFAULT NULL,
	invite_note text NOT NULL,
	safeguarding_status varchar(32) NOT NULL DEFAULT 'not_required',
	safeguarding_verified_at datetime DEFAULT NULL,
	snooze_until date DEFAULT NULL,
	next_action_at date DEFAULT NULL,
	assigned_user_id bigint(20) unsigned DEFAULT NULL,
	submitted_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uuid (uuid),
	KEY status (status),
	KEY email (email),
	KEY next_action_at (next_action_at),
	KEY snooze_until (snooze_until),
	KEY assigned_user_id (assigned_user_id),
	KEY submitted_at (submitted_at),
	KEY verified_at (verified_at),
	KEY verify_token (verify_token),
	KEY invite_token (invite_token),
	KEY invite_response (invite_response)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Consent is recorded once per submission and never mutated. IP and user agent
-- are stored hashed, so the record proves consent was given without retaining
-- the raw identifiers.
CREATE TABLE IF NOT EXISTS `{prefix}consents` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	submission_id bigint(20) unsigned NOT NULL,
	policy_version varchar(32) NOT NULL,
	purpose_text text NOT NULL,
	retention_months smallint(5) unsigned NOT NULL DEFAULT 24,
	ip_hash char(64) NOT NULL DEFAULT '',
	user_agent_hash char(64) NOT NULL DEFAULT '',
	consented_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY submission_id (submission_id),
	KEY policy_version (policy_version)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Teams carry the capacity numbers the "team gaps" tile needs. A gap is
-- target_headcount minus current_headcount; without both numbers that tile has
-- nothing to render.
--
-- `keywords` is the vocabulary of what a team's work is about. Matching could
-- previously compare a person's passions, abilities and experience only against
-- the team's name, so a heart for Elementary Children said nothing about
-- Fellowship Kids and every dimension except spiritual gifts was dead weight.
CREATE TABLE IF NOT EXISTS `{prefix}teams` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	slug varchar(80) NOT NULL,
	name varchar(190) NOT NULL,
	gifts text NOT NULL,
	keywords text NOT NULL,
	target_headcount smallint(5) unsigned NOT NULL DEFAULT 0,
	min_headcount smallint(5) unsigned NOT NULL DEFAULT 0,
	current_headcount smallint(5) unsigned NOT NULL DEFAULT 0,
	headcount_checked_at datetime DEFAULT NULL,
	requires_safeguarding tinyint(1) NOT NULL DEFAULT 0,
	leader_user_id bigint(20) unsigned DEFAULT NULL,
	is_active tinyint(1) NOT NULL DEFAULT 1,
	PRIMARY KEY (id),
	UNIQUE KEY slug (slug),
	KEY leader_user_id (leader_user_id),
	KEY is_active (is_active)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS `{prefix}placements` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	submission_id bigint(20) unsigned NOT NULL,
	team_id bigint(20) unsigned NOT NULL,
	status varchar(32) NOT NULL DEFAULT 'submitted',
	source varchar(16) NOT NULL DEFAULT 'match',
	decline_reason varchar(190) NOT NULL DEFAULT '',
	leader_user_id bigint(20) unsigned DEFAULT NULL,
	notes text NOT NULL,
	next_action_at date DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY submission_team (submission_id, team_id),
	KEY team_id (team_id),
	KEY status (status),
	KEY next_action_at (next_action_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Conversation history. Append-only rather than one editable field, so "spoke
-- to her Tuesday, travelling until September" survives the next leader's update
-- instead of being overwritten by it.
CREATE TABLE IF NOT EXISTS `{prefix}notes` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	submission_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned DEFAULT NULL,
	body text NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY submission_id (submission_id),
	KEY created_at (created_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Unfinished journeys, held so a person can continue on another device.
--
-- Kept apart from submissions on purpose. A draft is not something the person
-- has agreed to share with anyone -- it is their own work in progress, saved at
-- their request, and no leader query touches this table.
CREATE TABLE IF NOT EXISTS `{prefix}drafts` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	token_hash char(64) NOT NULL,
	email varchar(190) NOT NULL,
	answers_json longtext NOT NULL,
	step smallint(5) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	expires_at datetime NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY token_hash (token_hash),
	KEY email (email),
	KEY expires_at (expires_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- Append only. Who looked at whose spiritual gifts, and who changed what.
-- Written to by nothing except Audit::log().
CREATE TABLE IF NOT EXISTS `{prefix}audit` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned DEFAULT NULL,
	action varchar(64) NOT NULL,
	object_type varchar(32) NOT NULL DEFAULT '',
	object_id bigint(20) unsigned DEFAULT NULL,
	meta_json text NOT NULL,
	ip_hash char(64) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY user_id (user_id),
	KEY action (action),
	KEY object (object_type, object_id),
	KEY created_at (created_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;

-- What leaders say is not working.
--
-- The figures count what happened, not whether a suggestion made sense. Kept
-- apart from notes deliberately: notes are pastoral and are deleted with the
-- person, this is about the tool and has to outlive them.
CREATE TABLE IF NOT EXISTS `{prefix}feedback` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL,
	area varchar(32) NOT NULL DEFAULT 'general',
	body text NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY created_at (created_at),
	KEY area (area)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
