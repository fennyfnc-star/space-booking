USE kukoolala;

ALTER TABLE wp_sb_bookings 
ADD COLUMN IF NOT EXISTS `extras` LONGTEXT NULL AFTER `notes`,
ADD COLUMN IF NOT EXISTS `duration_hours` DECIMAL(4,2) DEFAULT 0.00 AFTER `end_time`,
ADD COLUMN IF NOT EXISTS `base_price` DECIMAL(10,2) DEFAULT 0.00 AFTER `duration_hours`,
ADD COLUMN IF NOT EXISTS `extras_price` DECIMAL(10,2) DEFAULT 0.00 AFTER `base_price`,
ADD COLUMN IF NOT EXISTS `modifier_price` DECIMAL(10,2) DEFAULT 0.00 AFTER `extras_price`,
ADD COLUMN IF NOT EXISTS `total_price` DECIMAL(10,2) DEFAULT 0.00 AFTER `modifier_price`;

SELECT 'Schema fixed - extras and price columns added' as status;
DESCRIBE wp_sb_bookings;

