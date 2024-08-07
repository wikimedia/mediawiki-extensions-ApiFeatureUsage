-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: ../extensions/ApiFeatureUsage/schema/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/api_feature_usage (
  afu_feature BLOB NOT NULL,
  afu_agent BLOB NOT NULL,
  afu_date BLOB NOT NULL,
  afu_hits INTEGER UNSIGNED NOT NULL,
  PRIMARY KEY(afu_date, afu_feature, afu_agent)
);
