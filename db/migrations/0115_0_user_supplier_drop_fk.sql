-- CUSTOM(fork): MUSÍ proběhnout PŘED upstream 0115_supplier_id_int.sql (řadí se dřív:
-- '0115_0' < '0115_supplier').
--
-- Upstream 0115 rozšiřuje supplier.id na INT UNSIGNED a dropuje/re-přidává 36 ZNÁMÝCH FK,
-- ale naši FK user_supplier.supplier_id → supplier.id (z 0107) v seznamu NEMÁ. Bez tohoto
-- dropu by `ALTER TABLE supplier MODIFY id INT` selhal (FK drží sloupec na starém TINYINT).
-- Sloupec + FK srovnáme na INT a FK vrátíme v 0123 (až po 0115, kdy je supplier.id INT).
--
-- Idempotentní: DROP FOREIGN KEY IF EXISTS (MariaDB 10.6+) → opakovaný běh = no-op.

ALTER TABLE user_supplier DROP FOREIGN KEY IF EXISTS fk_us_supplier;
