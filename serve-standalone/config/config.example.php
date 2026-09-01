<?php
/**
 * Copy this to config.php and edit it. config.php is not in version control.
 *
 * @package Serve
 */

declare(strict_types=1);

return array(

	/*
	 * Where this application is served from, with no trailing slash.
	 *
	 * Used to build confirmation links that go in email, so it has to be the
	 * URL a participant can actually reach -- not localhost, once this is real.
	 */
	'url' => 'http://localhost/serve-standalone/public',

	/*
	 * At least 32 random characters. Generate one with: bin/serve secret
	 *
	 * Session cookies and email confirmation tokens are derived from this. A
	 * predictable value here means forgeable confirmation links, so the
	 * application refuses to start until it is changed.
	 */
	'secret' => 'change-me',

	/*
	 * The church's timezone, for the dates leaders read.
	 *
	 * Everything is stored in UTC; this only affects display and the hour the
	 * daily digest is considered due.
	 */
	'timezone' => 'Asia/Dubai',

	'db' => array(
		'host'    => '127.0.0.1',
		'port'    => 3306,
		'name'    => 'serve',
		'user'    => 'serve',
		'pass'    => '',
		// Table names are always serve_*; this goes in front of that, for a
		// database shared with something else. Usually left empty.
		'prefix'  => '',
		'charset' => 'utf8mb4',
		'collate' => 'utf8mb4_unicode_520_ci',
	),

	'mail' => array(

		/*
		 * 'log' writes messages to var/mail.log instead of sending them, and is
		 * the default on purpose: a fresh install that silently mailed real
		 * volunteers from an unconfigured server would bounce or land in spam,
		 * and either way the failure would be invisible.
		 *
		 * Set to 'smtp' and fill in the rest when you have a mail account.
		 */
		'transport'  => 'log',

		'host'       => '',
		'port'       => 587,
		'username'   => '',
		'password'   => '',
		'encryption' => 'tls',
		'from'       => 'serve@example.org',
		'from_name'  => 'SERVE',
		'timeout'    => 15,
	),

	/*
	 * Extra hardening for a real deployment.
	 *
	 * debug shows exception messages in the browser. Leave it false anywhere a
	 * volunteer can reach: a stack trace names table columns and file paths.
	 */
	'debug' => false,
);
