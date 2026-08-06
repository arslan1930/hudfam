-- Drop removed features (Catalog, Emails, Orders, Published, Projects).
-- Safe to run multiple times. Order respects foreign keys.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS publication_orders;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS published_placements;
DROP TABLE IF EXISTS pitch_items;
DROP TABLE IF EXISTS pitches;
DROP TABLE IF EXISTS email_campaign_contacts;
DROP TABLE IF EXISTS country_catalog_sites;
DROP TABLE IF EXISTS sites;
DROP TABLE IF EXISTS project_admins;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS projects;

SET FOREIGN_KEY_CHECKS = 1;
