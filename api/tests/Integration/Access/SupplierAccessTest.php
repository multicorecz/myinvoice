<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Access;

use MyInvoice\Access\SupplierAccess;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Per-firemní přístup (CUSTOM/fork) — fail-open semantika.
 * Vytvoří dočasného uživatele + přiřazení na existující firmu, uklízí po sobě.
 */
#[Group('integration')]
final class SupplierAccessTest extends TestCase
{
    private Connection $db;
    private int $userId = 0;
    /** @var int[] */
    private array $supplierIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $this->supplierIds = array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        if (count($this->supplierIds) < 2) {
            $this->markTestSkipped('potřebuji ≥ 2 firmy v dev DB');
        }

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, 'Test Access', 'readonly', 'cs', 1)"
        );
        $stmt->execute(['access-test-' . bin2hex(random_bytes(6)) . '@example.com', password_hash('x', PASSWORD_BCRYPT)]);
        $this->userId = (int) $this->db->pdo()->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            // CASCADE smaže i user_supplier
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
    }

    private function access(bool $enabled): SupplierAccess
    {
        return new SupplierAccess($this->db, new Config(['access' => ['per_supplier_enabled' => $enabled]]));
    }

    public function testFlagOffAllowsAll(): void
    {
        $a = $this->access(false);
        $ids = $a->allowedIds(['id' => $this->userId, 'role' => 'readonly']);
        self::assertEqualsCanonicalizing($this->supplierIds, $ids);
    }

    public function testSuperAdminAllowsAll(): void
    {
        $a = $this->access(true);
        $ids = $a->allowedIds(['id' => $this->userId, 'role' => 'admin']);
        self::assertEqualsCanonicalizing($this->supplierIds, $ids);
    }

    public function testEnabledNonAdminRestrictedToAssigned(): void
    {
        $assigned = $this->supplierIds[0];
        $this->db->pdo()->prepare('INSERT INTO user_supplier (user_id, supplier_id) VALUES (?, ?)')
            ->execute([$this->userId, $assigned]);

        $a = $this->access(true);
        $user = ['id' => $this->userId, 'role' => 'readonly'];

        self::assertSame([$assigned], $a->allowedIds($user));
        self::assertTrue($a->canAccess($user, $assigned));
        self::assertFalse($a->canAccess($user, $this->supplierIds[1])); // jiná firma → ne
    }
}
