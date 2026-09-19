-- ==========================================================
-- SQL SCRIPT: PEMBERSIHAN DATA TESTING DI SERVER (INNODB SAFE)
-- Menggunakan DELETE FROM + RESET AUTO_INCREMENT
-- (Menghindari MySQL Error #1701 pada Foreign Key Table)
-- ==========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Hapus Seluruh Riwayat Transaksi & Operasional Testing
DELETE FROM transaction_modifiers;
DELETE FROM stock_movements;
DELETE FROM transfer_items;
DELETE FROM transfers;
DELETE FROM transactions;
DELETE FROM shifts;
DELETE FROM shift_schedules;
DELETE FROM coin_transactions;
DELETE FROM batch_preps;
DELETE FROM opnames;
DELETE FROM operating_expenses;
DELETE FROM cash_transactions;
DELETE FROM waste_logs;
DELETE FROM urgent_notes;
DELETE FROM personal_access_tokens;

-- 2. Hapus Seluruh Resep, Menu & Bahan Baku Testing
DELETE FROM bundle_items;
DELETE FROM recipe_items;
DELETE FROM recipes;
DELETE FROM prep_recipe_items;
DELETE FROM prep_recipes;
DELETE FROM menu_modifier_groups;
DELETE FROM modifier_options;
DELETE FROM modifier_groups;
DELETE FROM outlet_menus;
DELETE FROM menus;
DELETE FROM outlet_ingredients;
DELETE FROM ingredients;

-- 3. Reset Penomoran ID (Auto Increment) Kembali ke 1
ALTER TABLE transaction_modifiers AUTO_INCREMENT = 1;
ALTER TABLE stock_movements AUTO_INCREMENT = 1;
ALTER TABLE transfer_items AUTO_INCREMENT = 1;
ALTER TABLE transfers AUTO_INCREMENT = 1;
ALTER TABLE transactions AUTO_INCREMENT = 1;
ALTER TABLE shifts AUTO_INCREMENT = 1;
ALTER TABLE shift_schedules AUTO_INCREMENT = 1;
ALTER TABLE coin_transactions AUTO_INCREMENT = 1;
ALTER TABLE batch_preps AUTO_INCREMENT = 1;
ALTER TABLE opnames AUTO_INCREMENT = 1;
ALTER TABLE operating_expenses AUTO_INCREMENT = 1;
ALTER TABLE cash_transactions AUTO_INCREMENT = 1;
ALTER TABLE waste_logs AUTO_INCREMENT = 1;
ALTER TABLE urgent_notes AUTO_INCREMENT = 1;
ALTER TABLE personal_access_tokens AUTO_INCREMENT = 1;

ALTER TABLE bundle_items AUTO_INCREMENT = 1;
ALTER TABLE recipe_items AUTO_INCREMENT = 1;
ALTER TABLE recipes AUTO_INCREMENT = 1;
ALTER TABLE prep_recipe_items AUTO_INCREMENT = 1;
ALTER TABLE prep_recipes AUTO_INCREMENT = 1;
ALTER TABLE menu_modifier_groups AUTO_INCREMENT = 1;
ALTER TABLE modifier_options AUTO_INCREMENT = 1;
ALTER TABLE modifier_groups AUTO_INCREMENT = 1;
ALTER TABLE outlet_menus AUTO_INCREMENT = 1;
ALTER TABLE menus AUTO_INCREMENT = 1;
ALTER TABLE outlet_ingredients AUTO_INCREMENT = 1;
ALTER TABLE ingredients AUTO_INCREMENT = 1;

-- 4. Hapus Outlet & Bisnis Dummy Luar Maroa
DELETE FROM outlets WHERE business_id != 1;
DELETE FROM businesses WHERE id != 1;

-- 5. Hapus User Testing Luar Maroa
DELETE FROM users 
WHERE email IN ('alien_b@competitor.com', 'owner-ref-6aa41403cd4ea@test.com')
   OR (business_id IS NOT NULL AND business_id != 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================
-- Selesai. Database server Anda sekarang bersih dari data testing.
-- ==========================================================
