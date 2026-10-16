-- superadmin manages admins and staff, and nobody manages it.
-- INSERT IGNORE so a database that already has the role is left alone.

INSERT IGNORE INTO `user_type` (`ut_name`, `ut_description`)
VALUES ('superadmin', 'Full access, and manages admins and staff');
