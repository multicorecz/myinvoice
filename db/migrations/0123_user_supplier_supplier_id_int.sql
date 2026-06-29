-- CUSTOM(fork): dohra k upstream 0115_supplier_id_int.sql (supplier.id → INT UNSIGNED).
-- FK fk_us_supplier byla dočasně dropnuta v 0115_0_user_supplier_drop_fk.sql; teď, když je
-- supplier.id už INT, srovnáme typ našeho sloupce na INT a vrátíme FK (s ON DELETE CASCADE).
--
-- Idempotentní: MODIFY na cílový typ je samo idempotentní; FK přes ADD ... IF NOT EXISTS.

ALTER TABLE user_supplier MODIFY supplier_id INT UNSIGNED NOT NULL;

ALTER TABLE user_supplier
    ADD CONSTRAINT fk_us_supplier FOREIGN KEY IF NOT EXISTS (supplier_id)
    REFERENCES supplier(id) ON DELETE CASCADE;
