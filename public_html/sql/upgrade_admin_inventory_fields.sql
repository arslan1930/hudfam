-- Admin confidential inventory fields
ALTER TABLE sites
  ADD COLUMN inventory_client_name VARCHAR(255) NOT NULL DEFAULT '' AFTER our_contact_name,
  ADD COLUMN order_status VARCHAR(40) NOT NULL DEFAULT '' AFTER inventory_client_name,
  ADD COLUMN admin_comments TEXT NULL AFTER order_status;
