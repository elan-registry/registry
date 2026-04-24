-- Turnstile hook registration
-- Equivalent to adding these via Admin → Plugin Manager → Hooker → Configure
--
-- Table: us_plugin_hooks
-- Columns: page, folder, position, hook, disabled
--
-- Run once after deploying v2.18.0. Safe to re-run: INSERT IGNORE skips duplicates.

INSERT IGNORE INTO `us_plugin_hooks` (`page`, `folder`, `position`, `hook`, `disabled`) VALUES
  ('login.php',        'hooker', 'form', 'hooks/login_form_turnstile.php', 0),
  ('login.php',        'hooker', 'post', 'hooks/post_turnstile.php',       0),
  ('join.php',         'hooker', 'form', 'hooks/join_form_turnstile.php',  0),
  ('joinAttempt',      'hooker', 'body', 'hooks/post_turnstile.php',       0),
  ('forgot_password.php', 'hooker', 'post', 'hooks/post_turnstile.php',    0);
