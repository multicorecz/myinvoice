# CUSTOM-PATCHES — fork multicorecz/myinvoice

Evidence všech zásahů do upstream kódu (`radekhulan/myinvoice`). Slouží k:
- rychlému řešení merge konfliktů (víš, co je naše),
- čistému **opuštění / migraci na upstream**, kdyby autor feature dodělal.

Princip: maximum logiky v **nových souborech**, do upstreamu jen **drobné háčky**.

---

## 1. PDF design faktury (bind-mount → nyní v image)
Soubory přepsané kompletně (náš redesign + dílčí upstream merge):
- `styles/invoice.css` — celý redesign.
- `api/templates/invoice/invoice.twig` — pay-band, party-h, meta-sep, UHRAZENO v pruhu.
- `api/src/Service/Pdf/PdfBranding.php` — pay-band branding + bandLabel.
- `api/src/Service/Pdf/InvoicePdfRenderer.php` — okraje 10mm (`margin_* => 10`).

Při merge: u konfliktů v `invoice.twig` (upstream aktivně vyvíjí) ponech NÁŠ layout meta-gridu,
porty upstream **datové** nuance (SK DPH, identifikovaná osoba) do našich buněk.

## 2. Přehled faktur — přepínač seznam ↔ po měsících
- `web/src/pages/invoices/InvoiceList.vue` — `groupByMonth` toggle + `displayGroups` (plochá skupina).
  Popisky inline podle locale (bez zásahu do i18n).

## 3. CSS utilita
- `web/src/styles/main.css` — `@layer utilities { .uppercase { width: 100% } }`.

## 4. Per-firemní přístup uživatelů (feature `access.per_supplier_enabled`)
> Izolováno v namespace `MyInvoice\Access` (složka `api/src/Access/`). Fail-open, default OFF.
> Spec: `docs/spec-per-firemni-pristup.md`.

**Nové soubory (0 konfliktů):**
- `db/migrations/0107_user_supplier.sql` — tabulka `user_supplier` + grandfather.
- `api/src/Access/SupplierAccess.php` — služba (allowedIds/canAccess, fail-open).
- `api/src/Access/SupplierAccessMiddleware.php` — vynucení scope (po SupplierScope).
- `web/src/pages/admin/...` — UI přiřazení firem (viz commit).
- `api/tests/.../SupplierAccessTest.php` — testy.

**Háčky do upstreamu (revert při migraci na upstream řešení):**
| Soubor | Změna |
|---|---|
| `api/src/Bootstrap.php` | `+ $app->add(\MyInvoice\Access\SupplierAccessMiddleware::class)` mezi ApiScope a SupplierScope |
| `api/src/Action/Auth/MeAction.php` | inject `SupplierAccess`, seznam firem scoped přes `allowedIds()` (WHERE id IN …) |
| `api/src/Action/Admin/UserAdminAction.php` | `supplier_ids` v list/create/update/fetchUser + helpery `suppliersOf`/`setSuppliers` |
| `cfg.sample.php` | sekce `'access' => ['per_supplier_enabled' => false]` |
| FE správa uživatelů (`web/src/pages/admin/Users*.vue` + `web/src/api/admin.ts`) | multiselect firem |

**Opuštění:** `cfg…access.per_supplier_enabled => false` → okamžitě allow-all. Pak volitelně
`DROP TABLE user_supplier`, smaž `api/src/Access/`, FE, testy a revert háčků výše.

**Migrace na upstream (kdyby dodělali):** flag OFF → mapping migrace
`INSERT INTO <jejich_tabulka> SELECT … FROM user_supplier` (nebo rename) → revert háčků.

## 5. Logo firmy v hlavičce SPA (místo „MyInvoice.cz")
> Zobrazí `supplier.logo_path` aktuální firmy vlevo nahoře; když logo není → fallback MyInvoice.
> Reuse stávajícího nahrávání loga (Nastavení → e-mail branding) + `SafeLogoPath`.

**Nové soubory (0 konfliktů):**
- `api/src/Action/Branding/SupplierLogoAction.php` — `GET /api/branding/logo` servíruje obrázek
  aktuální firmy (scoped přes SupplierScope + náš SupplierAccess); bez loga → 404.

**Háčky do upstreamu:**
| Soubor | Změna |
|---|---|
| `api/src/Routes.php` | `+ $app->get('/api/branding/logo', SupplierLogoAction::class)` (1 řádek vedle email-branding rout) |
| `web/src/components/layout/AppLayout.vue` | v topbaru `<img>` loga firmy s `@error` fallbackem na MyInvoice + 4 řádky ve `<script setup>` (`supplierLogoUrl`/`showSupplierLogo`/`supplierLogoError`) |

**Opuštění:** smaž akci + route + revert bloku v AppLayout.vue → hlavička zpět na MyInvoice.cz.
