-- MyInvoice.cz (fork) — Číslo objednávky na vydané faktuře.
--
-- Per-faktura pole NEZÁVISLÉ na project_number (to je číslo navázaného projektu —
-- objednávka může mít jiné číslo). Zobrazuje se v hlavičce PDF pod typem dokladu.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS order_number VARCHAR(50) NULL
        COMMENT 'Číslo objednávky (per faktura, nezávislé na project_number)'
        AFTER note_below_items;
