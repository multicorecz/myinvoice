-- CUSTOM(fork): přechod z naší per-firemní vrstvy na upstream membership (0148).
--
-- Do 4.51.0 měl fork vlastní implementaci per-firemního přístupu: tabulka `user_supplier`
-- (migrace 0107 + 0115_0 + 0123), MyInvoice\Access\SupplierAccess(Middleware) a feature
-- flag `access.per_supplier_enabled`. Upstream 4.52.0 dodal vlastní, plnohodnotnější
-- řešení — `user_suppliers` (0148), SupplierAccessResolver, per-firmu role override,
-- 403 vynucení i na PAT tokenech a integrační testy. Sémantika „0 řádků = bez omezení"
-- je v obou shodná, takže přenos je 1:1; naši vrstvu jsme odstranili (viz CUSTOM-PATCHES.md).
--
-- role = NULL → uživatel v dané firmě dědí globální users.role, což přesně odpovídá
-- chování naší tabulky (ta žádný per-firmu override neuměla).
--
-- Pořadí: běží po 0148 ('0148_user_suppliers' < '0150_fork'), tedy až cílová tabulka
-- existuje. Zdrojová `user_supplier` existuje na KAŽDÉ instalaci forku — 0107 ji zakládá
-- a v repu zůstává, takže se nemusíme jistit proti její absenci.
--
-- INSERT IGNORE → opakovaný běh ani ručně předvyplněné řádky nic nerozbijí. JOIN na users
-- a supplier zahodí případné osiřelé řádky (FK by je sice neměla pustit, ale 0115_0 FK
-- dočasně dropovala).

SET NAMES utf8mb4;

INSERT IGNORE INTO user_suppliers (user_id, supplier_id, role)
SELECT us.user_id, us.supplier_id, NULL
  FROM user_supplier us
  JOIN users u    ON u.id = us.user_id
  JOIN supplier s ON s.id = us.supplier_id;

-- Legacy tabulku ruší až po přenosu. FK fk_us_user / fk_us_supplier padnou s ní.
DROP TABLE IF EXISTS user_supplier;
