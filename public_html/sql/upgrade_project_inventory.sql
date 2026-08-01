-- Per-project inventory + our mailbox fields
-- Prefer running upgrade.php (idempotent). This file is a reference.

ALTER TABLE sites
  ADD COLUMN our_mailbox VARCHAR(190) NOT NULL DEFAULT '' AFTER publisher_email,
  ADD COLUMN our_contact_name VARCHAR(150) NOT NULL DEFAULT '' AFTER our_mailbox;
