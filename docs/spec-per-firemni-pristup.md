# Zadávací dokumentace: Per-firemní přístup uživatelů (FINAL, runnable)

**Feature:** Omezit přístup uživatele jen na **vybrané firmy (suppliers)**, ne na všechny.
**Repo:** fork `multicorecz/myinvoice`, větev `custom-faktura-design` (upstream `radekhulan/myinvoice`).
**Nasazení:** vlastní image `myinvoice:custom` přes `cmd/deploy-custom.sh` (FE i BE v image).
**Cíl dokumentu:** AI/vývojář to podle něj naimplementuje **s minimální kolizí s upstreamem** a
s **jasnou cestou migrace**, kdyby to autor časem dodělal do originálu.

---

## 0. TL;DR
Aplikace **už je multi-firemní** (tabulka `supplier`, `supplier_id` všude, scoping přes jeden
`SupplierScopeMiddleware`). Chybí jen **vazba user↔firma** a její **vynucení**. Stavíme to tak,
aby maximum logiky bylo v **nových souborech** a do upstreamu šly jen **drobné háčky** (1–3 řádky),
celé **za feature-flagem**, izolované v namespace `MyInvoice\Access`.

**Scope:** binární přístup „má/nemá firmu" + globální **super-admin** (role `admin` vidí vše).
Per-firemní role = mimo rozsah (viz §11).

> ⚠️ **Bezpečnostně kritické.** Uživatel se **nikdy** nesmí dostat k datům nepřidělené firmy —
> ani přes `X-Supplier-Id`, ani `?supplier_id=`, ani API token. Každá změna má cross-tenant test.

---

## 1. Současná architektura (ověřeno v kódu)

| Co | Kde | Chování |
|---|---|---|
| Firmy | `supplier` (`db/migrations/0001_init.sql:128`), `id TINYINT UNSIGNED` | víc firem v instanci |
| Scoping firmy | `api/src/Middleware/SupplierScopeMiddleware.php` | čte `X-Supplier-Id`/`?supplier_id=`, vystaví `supplier.current_id`; **ověří jen existenci**, fallback `MIN(id)` |
| Uživatel | `users.role ENUM('admin','accountant','readonly')` | **globální role**, žádná vazba na firmu |
| Přihlášený user | `AuthMiddleware::ATTR_USER` = `auth.user` (`id`, `role`, `is_active`) | dostupný **před** SupplierScope |
| Seznam firem (FE switcher) | `api/src/Action/Auth/MeAction.php` → `GET /api/auth/me` | vrací **všechny** firmy |
| Switcher (FE) | `web/src/stores/supplier.ts`, hlavička `X-Supplier-Id` | plní se z `MeAction.suppliers` |
| Správa uživatelů | `api/src/Action/Admin/UserAdminAction.php`, routy `Routes.php:425-428` | CRUD users, jen `admin` (`guard()`) |
| Pořadí MW | `Bootstrap.php:108-122` | `Auth → … → Role → SupplierScope → ApiScope → …` |

**Mezera:** `SupplierScopeMiddleware::resolve()` neřeší usera; `MeAction` vrací všechny firmy;
neexistuje `user_supplier`.

---

## 2. Conflict-resistant architektura (PRINCIP)

> Každý **editovaný** upstream soubor = riziko konfliktu při merge. Maximum logiky → **nové soubory**,
> do upstreamu jen drobné háčky, vše **gated** feature-flagem, izolované v `MyInvoice\Access`.

| Vrstva | Soubor | Typ | Konflikt |
|---|---|---|---|
| Migrace `user_supplier` | `db/migrations/0090_user_supplier.sql` | **NOVÝ** | 🟢 nula |
| Access logika | `api/src/Access/SupplierAccess.php` | **NOVÝ** | 🟢 nula |
| Vynucení scope | `api/src/Access/SupplierAccessMiddleware.php` | **NOVÝ** | 🟢 nula |
| Registrace MW | `api/src/Bootstrap.php` | edit **1 řádek** | 🟡 malý |
| Seznam firem | `api/src/Action/Auth/MeAction.php` | edit **~3 řádky** (SQL) | 🟡 malý |
| Přiřazení firem (API) | `UserAdminAction.php` (nové metody) + `Routes.php` (nové routy) | edit (jen *přidání*) | 🟡 malý |
| FE správa | nová komponenta/sekce | převážně nový | 🟡 malý |
| Feature flag | `cfg.sample.php` (1 klíč) + čtení v kódu | edit malý | 🟡 malý |
| Seznam háčků | `CUSTOM-PATCHES.md` | **NOVÝ** | 🟢 nula |

**Klíč:** vynucení přes **NOVÝ middleware** (po `SupplierScopeMiddleware`) → `SupplierScopeMiddleware.php`
**needitujeme vůbec**. Z ~8 dotčených věcí je většina nových, editace jsou 1–3 řádky.

---

## 3. Datový model

### 3.1 Migrace `db/migrations/0090_user_supplier.sql`
> Ověř nejvyšší existující číslo (aktuálně upstream 0089) a použij další.

```sql
CREATE TABLE IF NOT EXISTS user_supplier (
  user_id     BIGINT UNSIGNED   NOT NULL,
  supplier_id TINYINT UNSIGNED  NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, supplier_id),
  KEY idx_us_supplier (supplier_id),
  CONSTRAINT fk_us_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_us_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GRANDFATHER: existující uživatelé dostanou VŠECHNY firmy → chování se nemění.
INSERT INTO user_supplier (user_id, supplier_id)
SELECT u.id, s.id FROM users u CROSS JOIN supplier s
ON DUPLICATE KEY UPDATE user_id = user_id;
```

### 3.2 Pravidla
- `admin` = super-admin → všechny firmy (junction se ignoruje).
- `accountant`/`readonly` → jen firmy z `user_supplier`.
- Feature flag `access.per_supplier_enabled` (default `false`): když OFF → chová se jako dnes (vše všem).

---

## 4. Backend — implementace

### 4.1 `api/src/Access/SupplierAccess.php` (NOVÝ)
```php
final class SupplierAccess
{
    public function __construct(private Connection $db, private Config $config) {}

    public function enabled(): bool {
        return (bool) $this->config->get('access.per_supplier_enabled', false);
    }
    public function isSuperAdmin(array $user): bool {
        return ($user['role'] ?? '') === 'admin';
    }
    /** @return int[] povolené supplier_id; prázdné = žádná firma */
    public function allowedIds(array $user): array {
        if (!$this->enabled() || $this->isSuperAdmin($user)) {
            return array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier')->fetchAll(\PDO::FETCH_COLUMN));
        }
        $st = $this->db->pdo()->prepare('SELECT supplier_id FROM user_supplier WHERE user_id = ?');
        $st->execute([(int) $user['id']]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }
    public function canAccess(array $user, int $sid): bool {
        return in_array($sid, $this->allowedIds($user), true);
    }
}
```

### 4.2 `api/src/Access/SupplierAccessMiddleware.php` (NOVÝ) — VYNUCENÍ
Běží **po** `SupplierScopeMiddleware`, přečte `supplier.current_id` + `auth.user`. Když firma není
povolená → přepíše atribut na **první povolenou** (graceful; nikdy nevrátí cizí data). Prázdná
množina → `supplier.current_id = 0`.
```php
public function process(Request $req, Handler $h): Response {
    if (!$this->access->enabled()) return $h->handle($req);
    $user = (array) $req->getAttribute(AuthMiddleware::ATTR_USER, []);
    if (!$user) return $h->handle($req); // public path
    $cur = (int) $req->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    $allowed = $this->access->allowedIds($user);
    if (!in_array($cur, $allowed, true)) {
        $cur = $allowed[0] ?? 0;
        $req = $req->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $cur);
    }
    return $h->handle($req);
}
```
**Háček do upstreamu:** `Bootstrap.php` — přidat `$app->add($container->get(SupplierAccessMiddleware::class));`
**hned za** řádek se `SupplierScopeMiddleware` (aby běžel uvnitř/po něm). 1 řádek.

> Pozn.: Bearer token je v `SupplierScopeMiddleware` forcovaný na `token.supplier_id`. Náš MW to
> respektuje; navíc při tvorbě tokenu (`CreateTokenAction`) ověř `access->canAccess` (viz §4.5).

### 4.3 `MeAction` — seznam firem (edit ~3 řádky)
Místo `FROM supplier ORDER BY id` použij scoped dotaz, když je flag ON a user není admin:
```php
$ids = $this->access->allowedIds($user);            // injektni SupplierAccess
$in  = $ids ? implode(',', array_map('intval',$ids)) : '0';
$sql = "... FROM supplier WHERE id IN ($in) ORDER BY id";   // při OFF/admin vrátí allowedIds vše
```
(Při flagu OFF i pro admina `allowedIds` vrátí všechny → identické chování jako dnes.)

### 4.4 `UserAdminAction` + `Routes.php` — přiřazení firem
- `list()`/detail: doplnit `supplier_ids: int[]` (`SELECT supplier_id FROM user_supplier WHERE user_id=?`).
- `create()`/`update()`: přijmout `supplier_ids` → přepsat množinu (DELETE+INSERT v transakci, validovat existenci v `supplier`).
- Routy: stačí rozšířit stávající `PUT /api/admin/users/{id}` o `supplier_ids` (bez nové routy), nebo
  přidat `PUT /api/admin/users/{id}/suppliers` (jen *přidání* řádku do `Routes.php`).
- Audit log `user.suppliers_changed` (stávající `log()`).

### 4.5 Audit ostatních `FROM supplier`
Projít a ověřit (z grepu): `Settings/SettingsAction`, `EmailBrandingAction`, `SigningCertAction`,
`Tax/TaxAction`, `Project/ProjectStatsAction`, `Auth/ApiMeAction`, `Auth/Tokens/CreateTokenAction`,
`Admin/Import/AnthropicCredentialsAction`. Většina čte **aktuální** firmu → po MW OK. Kdokoli listuje
**všechny** firmy → scopovat přes `SupplierAccess`. `CreateTokenAction` → `canAccess` guard.

---

## 5. Frontend
- **Správa uživatelů**: nová sekce/multiselect firem (create/edit), posílá `supplier_ids`. U role
  `admin` skrýt (vidí vše).
- **Switcher** (`stores/supplier.ts`) se plní z `MeAction` → **automaticky se zúží**. Ošetřit:
  1 firma → switcher skrýt; 0 firem (ne-admin) → prázdný stav „nemáte přístup k žádné firmě".
- i18n: **inline podle locale** v komponentě (jako u přepínače seznamu faktur) → bez zásahu do
  `cs.json`/`en.json` = méně konfliktů.

---

## 6. Feature flag
`cfg.sample.php` (+ `cfg.docker.php` na serveru): přidat
```php
'access' => [ 'per_supplier_enabled' => false ],
```
Čte se v `SupplierAccess::enabled()`. **OFF = dnešní chování** (vše všem) → bezpečné nasazení,
postupné zapnutí. Po ověření → `true`.

---

## 7. Migrace na upstream, kdyby to autor dodělal

1. **Schema co nejblíž očekávanému** — `user_supplier(user_id, supplier_id)` je nejpřímější návrh;
   upstream by zvolil totéž nebo `memberships`. Pak migrace dat = `INSERT … SELECT` / rename.
2. **Feature flag** — náš kód vypneš (`per_supplier_enabled=false`) a přejdeš na upstream.
3. **Izolace** — vše v `MyInvoice\Access\` + `api/src/Access/`. Odebrání = smazat složku + revert
   háčků dle `CUSTOM-PATCHES.md`.
4. **`CUSTOM-PATCHES.md`** (NOVÝ, udržovat) — přesný seznam háčků (soubor:řádek, co přidáno):
   - `Bootstrap.php` — `+ $app->add(SupplierAccessMiddleware)`
   - `MeAction.php` — scoped supplier dotaz
   - `UserAdminAction.php` + `Routes.php` — supplier_ids
   - `cfg.sample.php` — `access.per_supplier_enabled`
5. **`db/migrations/00xx_migrate_to_upstream_access.sql`** — připravit dle reálné upstream tabulky
   (rename / mapping `INSERT … SELECT`), pak `DROP TABLE user_supplier` až po ověření.

---

## 8. Bezpečnost / akceptační kritéria
1. Ne-admin s firmou A **nezíská** data B přes `X-Supplier-Id:B` ani `?supplier_id=B` → nascopuje se na A/0.
2. API token na nepovolenou firmu → tvorba zamítnuta (`canAccess`).
3. `GET /api/auth/me` ne-admina vrací jen povolené firmy (admin = vše).
4. Flag OFF → **chování beze změny** (grandfather).
5. Ne-admin bez firem → konzistentní prázdný stav, ne 500.

---

## 9. Testy (`api/tests/`)
- `SupplierAccess`: allowedIds (admin=vše, ne-admin=jen své, flag OFF=vše).
- `SupplierAccessMiddleware`: povolená→projde; nepovolená→přepsána na první; prázdná→0; flag OFF→no-op.
- **Cross-tenant integrace (klíčové):** user A volá list faktur s `X-Supplier-Id:B` → data A; PDF
  `?supplier_id=B` → data A; `me` ne-admina → jen A.
- UserAdmin: create/update se `supplier_ids` zapíše junction; neexistující sid → 400.

Spuštění: `docker exec myinvoice-app-1 vendor/bin/phpunit --filter Supplier` (po deploy custom image).

## 10. Postup implementace
1. Migrace `0090` + grandfather (§3).
2. `SupplierAccess` + `SupplierAccessMiddleware` + 1 řádek Bootstrap + flag (§4.1-4.2, §6).
3. Cross-tenant testy (§9) — **nejdřív bezpečnost**.
4. `MeAction` scoping (§4.3).
5. Audit ostatních `FROM supplier` (§4.5).
6. `UserAdminAction` + routy (§4.4).
7. FE správa + prázdné stavy (§5).
8. `CUSTOM-PATCHES.md` (§7).
9. Zapnout flag, manuální ověření 2 useři × 2 firmy.

## 11. Nasazení (máme pipeline)
```bash
# lokálně
git add -A && git commit -m "access: per-firemní přístup (flag access.per_supplier_enabled)"
git push origin custom-faktura-design
# server
ssh hostinger 'cmd/deploy-custom.sh'              # build myinvoice:custom → up -d → migrate
# zapnout v cfg.docker.php: access.per_supplier_enabled => true, pak:
ssh hostinger 'docker restart myinvoice-app-1'
```
Migrace `0090` proběhne automaticky v `migrate.php` (součást deploy skriptu).

## 12. Mimo rozsah / odhad
- **Per-firemní role** (admin na A, readonly na B): přesun role-resolution za SupplierScope + sloupec
  `role` v `user_supplier` + úprava `RoleMiddleware`. +2–4 dny.
- Pozvánky e-mailem na firmu; report „kdo má přístup k X".

**Odhad (conflict-resistant, binární):** ~1,5–2 týdne vč. testů a FE.
**Údržba při upstream syncu:** prakticky nulová (nové soubory se nemergují, háčky jsou drobné a za flagem).
