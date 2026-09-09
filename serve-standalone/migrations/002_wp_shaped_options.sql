-- Give the options table WordPress's column names.
--
-- Not cosmetic. Ported domain code writes raw SQL against `{$wpdb->options}`
-- and `option_name` -- Metrics::flush() does exactly that to clear the
-- gift-distribution cache. With `name`/`value` columns that query is a syntax
-- error against a table that does not have those columns, and because
-- Db::query() returns false rather than throwing, it failed silently: the cache
-- was never invalidated and the gift panel could show stale figures
-- indefinitely.
--
-- Renaming the columns to match means such queries work in both builds without
-- a standalone-only fork of the domain file, and it closes the whole class of
-- future incompatibility rather than this one instance of it.
--
-- Safe to run twice: the rename is skipped when the old column is already gone.

ALTER TABLE `{prefix}options`
	CHANGE COLUMN IF EXISTS `name` `option_name` varchar(190) NOT NULL,
	CHANGE COLUMN IF EXISTS `value` `option_value` longtext NOT NULL;
