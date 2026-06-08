<?php

declare(strict_types=1);

namespace MyInvoice\Access;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Per-firemní přístup (CUSTOM / fork). Rozhoduje, ke kterým firmám (supplier) má uživatel přístup.
 *
 * NÁVRH = FAIL-OPEN (nikdy nikoho omylem nezamkne, snadné opuštění):
 *   1. feature-flag `access.per_supplier_enabled` = false (default)  → allow-all (tabulka se nečte)
 *   2. flag ON + role 'admin'                                        → allow-all (super-admin)
 *   3. flag ON + tabulka user_supplier prázdná (0 řádků)             → allow-all (nenakonfigurováno)
 *   4. flag ON + tabulka má řádky                                    → jen přiřazené firmy uživatele
 *
 * Izolovaná vrstva — viz docs/spec-per-firemni-pristup.md a CUSTOM-PATCHES.md.
 * Při adopci upstream řešení stačí flag vypnout + odebrat MyInvoice\Access\.
 */
final class SupplierAccess
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /** Je per-firemní omezení vůbec zapnuté? */
    public function enabled(): bool
    {
        return (bool) $this->config->get('access.per_supplier_enabled', false);
    }

    /** Role 'admin' = super-admin (vidí všechny firmy). */
    public function isSuperAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /**
     * Povolená supplier_id pro daného uživatele (FAIL-OPEN).
     *
     * @param array $user  AuthMiddleware::ATTR_USER (potřebuje 'id', 'role')
     * @return int[]
     */
    public function allowedIds(array $user): array
    {
        // flag OFF nebo super-admin → všechny firmy
        if (!$this->enabled() || $this->isSuperAdmin($user)) {
            return $this->allSupplierIds();
        }

        try {
            // Fail-open: dokud je junction tabulka prázdná (nenakonfigurováno) → allow-all.
            $configured = (bool) $this->db->pdo()
                ->query('SELECT EXISTS(SELECT 1 FROM user_supplier)')
                ->fetchColumn();
            if (!$configured) {
                return $this->allSupplierIds();
            }

            $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM user_supplier WHERE user_id = ?');
            $stmt->execute([(int) ($user['id'] ?? 0)]);
            return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            // Tabulka chybí (např. po DROP při abandonu) nebo DB chyba → fail-open.
            return $this->allSupplierIds();
        }
    }

    /** Má uživatel přístup ke konkrétní firmě? */
    public function canAccess(array $user, int $supplierId): bool
    {
        return in_array($supplierId, $this->allowedIds($user), true);
    }

    /** @return int[] všechny existující supplier_id */
    private function allSupplierIds(): array
    {
        return array_map(
            'intval',
            $this->db->pdo()->query('SELECT id FROM supplier')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }
}
