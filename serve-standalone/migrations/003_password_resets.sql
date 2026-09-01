-- Password reset tokens.
--
-- Only the hash of a token is stored, for the same reason the session and
-- email-verification tokens elsewhere are hashed: a stolen database should not
-- hand over the ability to take over every account.
--
-- One row per request rather than one per account, so a second request does not
-- silently invalidate a link already in somebody's inbox. Used and expired rows
-- are cleared by the retention sweep.

CREATE TABLE IF NOT EXISTS `{prefix}password_resets` (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL,
	token_hash char(64) NOT NULL,
	requested_ip varchar(45) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	used_at datetime DEFAULT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY token_hash (token_hash),
	KEY user_id (user_id),
	KEY created_at (created_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
