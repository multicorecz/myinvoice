-- MyInvoice.cz — per-firemní přístup uživatelů (CUSTOM / fork multicorecz).
--
-- Vazba uživatel ↔ firma (supplier). Určuje, ke kterým firmám má uživatel přístup.
-- Vynucení je gated feature-flagem `access.per_supplier_enabled` (default false) a je
-- FAIL-OPEN: flag OFF nebo prázdná tabulka → allow-all (viz api/src/Access/SupplierAccess.php).
--
-- Role 'admin' = super-admin (vidí všechny firmy, junction se ignoruje).
-- Izolovaná custom vrstva (MyInvoice\Access) — snadno odebratelné / migrovatelné na upstream.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_supplier (
  user_id     BIGINT UNSIGNED   NOT NULL,
  supplier_id TINYINT UNSIGNED  NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, supplier_id),
  KEY idx_us_supplier (supplier_id),
  CONSTRAINT fk_us_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_us_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GRANDFATHER: existující uživatelé dostanou VŠECHNY existující firmy → chování se nemění,
-- i kdyby se flag zapnul. Nové instalace mají tabulku prázdnou → fail-open (allow-all).
-- INSERT IGNORE (ne ON DUPLICATE) — duplicitní PK přeskočí; navíc ON DUPLICATE po CROSS JOIN
-- koliduje s parserem (ON se bere jako JOIN podmínka).
INSERT IGNORE INTO user_supplier (user_id, supplier_id)
SELECT u.id, s.id FROM users u CROSS JOIN supplier s;
