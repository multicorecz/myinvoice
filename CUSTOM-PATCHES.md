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
- `api/src/Service/Pdf/InvoicePdfRenderer.php` — okraje 10mm (`margin_* => 10`) + `logoDisplayBox()`
  (fit-to-box rozměry loga `logo_w`/`logo_h` v mm — mPDF ignoruje max-width/max-height na `<img>`).

Při merge: u konfliktů v `invoice.twig` (upstream aktivně vyvíjí) ponech NÁŠ layout meta-gridu,
porty upstream **datové** nuance (SK DPH, identifikovaná osoba) do našich buněk.

**Fonty (od merge 4.35.1):** přebíráme upstream typografii — `MpdfFontConfig.php` registruje
**Montserrat** (`DEFAULT_FONT`) + **JetBrains Mono** (číselné pasáže), DejaVu Sans zůstává jen jako
`backupSubsFont` pro symboly; **DejaVu Sans Mono už NENÍ registrováno** (maže ho `cleanup-mpdf-fonts.php`).
V `invoice.css` proto piš `font-family: 'jetbrainsmono', 'DejaVu Sans Mono', monospace` (NE jen DejaVu).
Patička dokladu je upstream markup (`.footer-name`/`.footer-dot`/`.footer-link`) — při dalších merzích
ber upstream stranu fontů/patičky, náš zůstává jen LAYOUT (pay-band, meta-grid, `.order-ref`, party-h).

## 2. Přehled faktur — přepínač seznam ↔ po měsících
- `web/src/pages/invoices/InvoiceList.vue` — `groupByMonth` toggle + `displayGroups` (plochá skupina).
  Popisky inline podle locale (bez zásahu do i18n).

## 3. CSS utilita
- `web/src/styles/main.css` — `@layer utilities { .uppercase { width: 100% } }`.

## 4. ~~Per-firemní přístup uživatelů~~ → PŘEVZATO UPSTREAMEM (4.52.0)
> **Tato customizace už neexistuje.** Upstream 4.52.0 (commit `0dbd32d3`, issue #246) dodal
> vlastní, plnohodnotnější per-firemní přístup, tak jsme na něj přešli a naši vrstvu smazali.

**Odstraněno při merge 4.53.2:** `api/src/Access/` (SupplierAccess + SupplierAccessMiddleware),
`api/tests/Integration/Access/SupplierAccessTest.php`, `docs/spec-per-firemni-pristup.md`,
flag `access.per_supplier_enabled` v `cfg.sample.php` a všechny háčky (Bootstrap, MeAction,
UserAdminAction, `web/src/api/admin.ts`, `web/src/pages/admin/Users.vue`) — ty jsou nyní 1:1 upstream.

**Přenos dat:** `db/migrations/0150_fork_user_supplier_to_upstream.sql` překlopí řádky
`user_supplier` → `user_suppliers` (upstream 0148) s `role = NULL` (= zdědit globální roli, což
odpovídá naší tabulce, která override neuměla) a legacy tabulku dropne. Migrace 0107 / 0115_0 / 0123
zůstávají v repu kvůli historické konzistenci — na nové instalaci tabulku založí a 0150 ji zase zruší.

**Co tím fork získal:** role override per firmu (`accountant`/`readonly`), tvrdé 403
`forbidden_supplier` místo našeho fail-open, scope i na PAT tokenech, 11 integračních testů.
Sémantika „0 řádků = bez omezení" zůstala stejná, takže se pro uživatele nic nemění.

## 5. Logo firmy v hlavičce SPA (místo „MyInvoice.cz")
> Zobrazí `supplier.logo_path` aktuální firmy vlevo nahoře; když logo není → fallback MyInvoice.
> Reuse stávajícího nahrávání loga (Nastavení → e-mail branding) + `SafeLogoPath`.

**Nové soubory (0 konfliktů):**
- `api/src/Action/Branding/SupplierLogoAction.php` — `GET /api/branding/logo` servíruje obrázek
  aktuální firmy (scoped přes SupplierScope, který přes SupplierAccessResolver vrátí 403 mimo
  membership uživatele); bez loga → 404.

**Háčky do upstreamu:**
| Soubor | Změna |
|---|---|
| `api/src/Routes.php` | `+ $app->get('/api/branding/logo', SupplierLogoAction::class)` (1 řádek vedle email-branding rout) |
| `api/src/Middleware/RoleMiddleware.php` | `+ 'GET #^/api/branding/logo$#'` v `READONLY_RULES` — od 4.25.0 (security audit) už není blanket `GET *`, takže bez tohoto by účetní/readonly dostali admin-only 403 a logo by jim spadlo na fallback |
| `web/src/components/layout/AppLayout.vue` | v topbaru `<img>` loga firmy s `@error` fallbackem na MyInvoice + 4 řádky ve `<script setup>` (`supplierLogoUrl`/`showSupplierLogo`/`supplierLogoError`) |

POZN.: `SupplierLogoAction` má `X-Content-Type-Options: nosniff` + CSP `sandbox` (logo může být SVG → XSS při přímé navigaci) — parita s upstream DownloadArchivedPdfAction/DocumentFileAction.

**Opuštění:** smaž akci + route + READONLY_RULES řádek + revert bloku v AppLayout.vue → hlavička zpět na MyInvoice.cz.

## 6. Admin-only mazání historie PDF u faktur
> Smazání jedné archivované verze PDF z historie faktury. **Pouze admin** — guard přímo
> v Action (RoleMiddleware `'* /api/invoices'` pouští i účetního, takže admin-only řešíme tady).

**Nové soubory (0 konfliktů):**
- `api/src/Action/Invoice/DeleteArchivedPdfAction.php` — `DELETE /api/invoices/{id}/pdfs/{archiveId}`,
  guard `role === 'admin'` + SupplierGuard + scoped delete.

**Háčky do upstreamu:**
| Soubor | Změna |
|---|---|
| `api/src/Service/Pdf/PdfArchiveService.php` | `+ deleteArchiveEntry(archiveId, invoiceId)` (smaže DB řádek + soubor). Cestu řeší upstream `archiveFilePath()` (měsíční shard od 4.39 + fallback na ploché `_archive`) — při merge ho v deleteArchiveEntry zachovej. |
| `api/src/Routes.php` | `+ $app->delete('/api/invoices/{id}/pdfs/{archiveId}', …)` + use import |
| `web/src/api/invoices.ts` | `+ deleteArchivedPdf(id, archiveId)` |
| `web/src/pages/invoices/InvoiceDetail.vue` | `deletePdfVersion()` + tlačítko Smazat v historii PDF `v-if="auth.isAdmin"` (inline popisky) |

**Opuštění:** smaž akci + route + `deleteArchiveEntry` + FE tlačítko/handler/api.

## 7. Číslo objednávky na faktuře (`invoices.order_number`)
> Per-faktura pole NEZÁVISLÉ na `project_number` (číslo navázaného projektu). Zadává se v editoru
> (karta DATUMY) a tiskne se v hlavičce PDF pod typem dokladu.

**Nové soubory (0 konfliktů):**
- `db/migrations/0109_invoice_order_number.sql` — `ALTER TABLE invoices ADD COLUMN order_number`.

**Háčky do upstreamu:**
| Soubor | Změna |
|---|---|
| `api/src/Repository/InvoiceRepository.php` | `order_number` v `createDraft` INSERT + `updateDraft` SET + helper `normalizeOrderNumber()` (`find()` ho bere přes `i.*`) |

> ⚠️ **`createDraft` — po každém merge přepočítat placeholdery.** Column list a `VALUES` jsou dva
> různé řádky. Column list je upstreamový (git ho při merge vezme od nich), ale `VALUES` je NÁŠ
> (má o jeden `?` navíc kvůli `order_number`), takže se nesladí sám. Když upstream přidá sloupec,
> INSERT tiše spadne na `SQLSTATE[21S01] Column count doesn't match value count` → **500 při
> zakládání nové faktury**. Přesně tak se to rozbilo v 4.4x, kdy upstream přidal `branding_profile_id`
> (commit `b13f1391`, 21. 7. 2026); odhaleno až 20. 8. Pozor: `"draft"` je literál, ne placeholder —
> počítej ho jako hodnotu pro sloupec `status`. `updateDraft` je imunní (pojmenované `sloupec = ?`).
| `api/src/Action/Invoice/UpdateInvoiceAction.php` | `'order_number'` v `diffFields()` (audit) |
| `web/src/api/invoices.ts` | `order_number` v `Invoice` + `InvoicePayload` |
| `web/src/pages/invoices/InvoiceEditor.vue` | form pole + input v kartě DATUMY (inline popisky) + load/save |
| `api/templates/invoice/invoice.twig` | `.order-ref` v hlavičkové `.meta` buňce |
| `styles/invoice.css` | `.order-ref` styl |

**POZN.:** příští migrace ber od **0151** (upstream zabral po 0150). Naše migrace: `0107_user_supplier`,
`0109_invoice_order_number`, `0115_0_user_supplier_drop_fk`, `0123_user_supplier_supplier_id_int`,
`0150_fork_user_supplier_to_upstream`. Sdílené prefixy s upstreamem koexistují (runner
`sort(SORT_STRING)` podle celého názvu — NEPŘEČÍSLOVÁVAT aplikované): `0107`, `0109`, `0115`
(náš `0115_0` se řadí PŘED upstream `0115_supplier_id_int`), `0123`, `0130`, `0140`/`0141`, `0145`,
`0150` (náš `0150_fork_…` se řadí PŘED upstream `0150_purchase_vat_classification_30_cleanup`;
nezávisí na sobě, náš je navíc dávno aplikovaný).

**supplier_id → INT (historie, migrace 0115):** upstream rozšířil `supplier.id` z TINYINT na INT
a dropoval/re-přidával jen SVÝCH 36 FK — naši `fk_us_supplier` (user_supplier) NEznal, takže by `MODIFY
supplier.id INT` spadl. Řešil to náš pár `0115_0` (drop FK před) + `0123` (MODIFY + re-add FK po).
Od 0150 už `user_supplier` neexistuje (§4), takže **tenhle problém se opakovat nemůže** — vlastní FK
na `supplier.id` už nemáme. Kdyby nějaká vznikla, zopakuj stejný drop-before / readd-after vzorec.

**Opuštění:** `DROP COLUMN order_number` + revert háčků výše.

## 8. Řádkové akce v seznamu faktur (inline flat ikony + „…" dropdown)
> Sloupec „Akce" vpravo: hlavní akce jako inline solid (flat) ikony — Upravit (admin) / Exportovat do
> PDF / Uhradit — + „…" dropdown s plnou nabídkou: Upravit / Odeslat / Exportovat do PDF / Uhradit /
> Částečná úhrada / Storno / Dobropis / Kopírovat + oddělená Smazat (admin).
> - **Odeslat** = modal s příjemci (to/cc/bcc předvyplněné z `recipients()`) + poznámka → `send()`.
> - **Uhradit** = modal s datem (default dnes) + poděkování → `markPaid(id, date, {sendThanks})`.
> - **Částečná úhrada** = prompt na částku (předvyplněno zbývající) → `createPayment()`.
> - **Storno / Dobropis** = `cancel(id, 'internal' | 'credit_note')`; dobropis naviguje do editoru.
>
> Uhradit/Částečná jen u nezaplacené; Storno/Dobropis jen u vystavené faktury/daň. dokladu. Reuse
> endpointů clone/pdfUrl/send/recipients/markPaid/createPayment/cancel/delete — žádné nové API.

**Same-as-detail dialogy:** akce s vyplňovacím dialogem (Odeslat/Uhradit/Částečná/Storno/Dobropis)
NEduplikují UI — navigují na `/invoices/{id}?action=…[&mode=…]` a detail otevře TENTÝŽ dialog
(`applyRouteAction()` v InvoiceDetail). Jednoduché akce (Upravit/PDF/Kopírovat/Smazat) běží přímo v listu.
Ikony outline + barevný akcent per akce (paleta primary/accent/success/warning/danger).

**Nové soubory (0 konfliktů):**
- `web/src/components/invoices/InvoiceRowActions.vue` — inline outline ikony + „…" dropdown teleportované
  do `<body>` (neořezává tabulka; flip nahoru; zavírá klik mimo/Esc/scroll). Gating canWrite/isAdmin.

**Háčky do upstreamu:**
| Soubor | Změna |
|---|---|
| `web/src/pages/invoices/InvoiceList.vue` | import + `<th>` Akce + `<td>`/mobil `<InvoiceRowActions :invoice @changed="load()" />` |
| `web/src/pages/invoices/InvoiceDetail.vue` | `applyRouteAction()` — `onMounted` po `load()` otevře dialog dle `?action=` (send/mark-paid/partial-payment/cancel+mode) a vyčistí query |

**Háčky do upstreamu:**
| Soubor | Změna |
|---|---|
| `web/src/pages/invoices/InvoiceList.vue` | import + `<th>` Akce + `<td>`/mobil `<InvoiceRowActions :invoice="inv" @changed="load()" />` |

**Opuštění:** smaž komponentu + 3 řádky v InvoiceList.vue.
