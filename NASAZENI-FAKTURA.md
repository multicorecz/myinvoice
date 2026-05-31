# Nasazení custom designu faktury (Hostinger / Docker)

Tento dokument popisuje, jak nasadit změny **vlastního designu faktury** na produkční
server. Aplikace běží z hotového image z GHCR (`ghcr.io/radekhulan/myinvoice:latest`),
takže naše úpravy **nejsou v image** — jsou na serveru jako soubory a do kontejneru se
dostávají přes **bind-mount** (přežijí i `docker compose pull` / update image).

---

## 1. Které soubory jsou „naše" (bind-mountnuté)

| Lokálně (tvůj Mac, repo) | Na serveru (host) | V kontejneru |
|---|---|---|
| `styles/invoice.css` | `/opt/myinvoice/styles/invoice.css` | `/var/www/html/styles/invoice.css` |
| `api/templates/invoice/invoice.twig` | `/opt/myinvoice/api/templates/invoice/invoice.twig` | `/var/www/html/...` |
| `api/src/Service/Pdf/PdfBranding.php` | `/opt/myinvoice/api/src/Service/Pdf/PdfBranding.php` | `/var/www/html/...` |
| `api/src/Service/Pdf/InvoicePdfRenderer.php` | `/opt/myinvoice/api/src/Service/Pdf/InvoicePdfRenderer.php` | `/var/www/html/...` |

Bind-mounty jsou nadefinované v `/opt/myinvoice/docker-compose.production.yml` (sekce
`services.app.volumes`, řádky `...:...:ro`).

**Server detaily:**
- SSH alias: `hostinger` (klíč `~/.ssh/hostinger`)
- Projekt: `/opt/myinvoice`
- Kontejner: `myinvoice-app-1`
- Aktivní compose: `docker-compose.production.yml`

---

## 2. Jak se změny aplikují (důležité!)

| Typ souboru | Projeví se | Co je potřeba |
|---|---|---|
| `invoice.css`, `invoice.twig` | **okamžitě** | jen nahrát + otevřít/přegenerovat fakturu |
| `PdfBranding.php`, `InvoicePdfRenderer.php` (PHP) | až po **restartu** | nahrát + `docker restart myinvoice-app-1` (PHP opcache) |

PDF se cachuje (`storage/invoices/...`), ale cache se **sama invaliduje**, jakmile je
`invoice.css` / `invoice.twig` / `InvoicePdfRenderer.php` novější než cachované PDF.
Pro jistotu lze u konkrétní faktury přidat do URL PDF `?regenerate=1`.

---

## 3. Postup nasazení

### Krok 1 — úprava + git (lokálně)
```bash
cd ~/Documents/GIT/myinvoice
# ... uprav soubor(y) ...
git add -A
git commit -m "faktura: <popis změny>"
# (push do tvého remote, pokud nějaký máš)
```

### Krok 2 — přenos na server (scp)
Nahraj jen ty soubory, které jsi měnil:
```bash
# CSS
scp styles/invoice.css hostinger:/opt/myinvoice/styles/invoice.css

# Twig (šablona)
scp api/templates/invoice/invoice.twig hostinger:/opt/myinvoice/api/templates/invoice/invoice.twig

# PHP — branding
scp api/src/Service/Pdf/PdfBranding.php hostinger:/opt/myinvoice/api/src/Service/Pdf/PdfBranding.php

# PHP — renderer (okraje, mPDF nastavení)
scp api/src/Service/Pdf/InvoicePdfRenderer.php hostinger:/opt/myinvoice/api/src/Service/Pdf/InvoicePdfRenderer.php
```

### Krok 3 — aplikace
- Měnil jsi **jen CSS / Twig** → nic dalšího, jen otevři fakturu (`?regenerate=1`).
- Měnil jsi **PHP** → restartuj kontejner (vyčistí opcache):
```bash
ssh hostinger 'docker restart myinvoice-app-1'
```

### Krok 4 — ověření
Otevři fakturu v appce (případně `?regenerate=1`) a zkontroluj PDF.

---

## 4. „Vše najednou" (copy-paste)

Nahraje všechny 4 soubory a restartuje kontejner (bezpečné i když měníš jen CSS):
```bash
cd ~/Documents/GIT/myinvoice
scp styles/invoice.css                              hostinger:/opt/myinvoice/styles/invoice.css
scp api/templates/invoice/invoice.twig              hostinger:/opt/myinvoice/api/templates/invoice/invoice.twig
scp api/src/Service/Pdf/PdfBranding.php             hostinger:/opt/myinvoice/api/src/Service/Pdf/PdfBranding.php
scp api/src/Service/Pdf/InvoicePdfRenderer.php      hostinger:/opt/myinvoice/api/src/Service/Pdf/InvoicePdfRenderer.php
ssh hostinger 'docker exec myinvoice-app-1 php -l /var/www/html/api/src/Service/Pdf/PdfBranding.php \
  && docker exec myinvoice-app-1 php -l /var/www/html/api/src/Service/Pdf/InvoicePdfRenderer.php \
  && docker restart myinvoice-app-1'
```
> Tip: `php -l` zkontroluje syntaxi PHP **před** restartem — když je chyba, restart se neprovede.

---

## 5. Záloha a rollback

Před každou změnou si na serveru udělej zálohu (volitelné, git je taky záloha):
```bash
ssh hostinger 'cd /opt/myinvoice && cp styles/invoice.css styles/invoice.css.bak.$(date +%Y%m%d%H%M)'
```

Rollback (vrátí poslední zálohu a aplikuje):
```bash
ssh hostinger 'cd /opt/myinvoice && cp $(ls -t styles/invoice.css.bak.* | head -1) styles/invoice.css'
# u PHP navíc: ssh hostinger 'docker restart myinvoice-app-1'
```

Na serveru už nějaké zálohy `*.bak.*` jsou (z předchozího nasazení) — pro jistotu nech.

---

## 6. Po updatu image (`docker compose pull`)

Bind-mount způsobí, že tvoje 4 soubory **přepíšou** verzi z nového image → tvůj design
zůstane. Nemusíš nic dělat. (Ověřeno — po updatu faktura OK.)

⚠️ Tvé 4 soubory jsou ale „zamrzlé" kopie: **nedostanou** upstream změny *v těchto
konkrétních souborech*. Když upstream výrazně přepíše šablonu/renderer, případně budeš
chtít jejich novou funkcionalitu, porovnej svou verzi s upstreamem a zmerguj ručně:
```bash
# co je v image vs. tvoje verze
ssh hostinger 'cd /opt/myinvoice && git fetch && git diff HEAD origin/master -- \
  styles/invoice.css api/templates/invoice/invoice.twig \
  api/src/Service/Pdf/PdfBranding.php api/src/Service/Pdf/InvoicePdfRenderer.php'
```

---

## 7. Rychlá diagnostika

```bash
# běží kontejner?
ssh hostinger 'docker ps --filter name=myinvoice-app-1'

# jsou bind-mounty aktivní? (má vrátit 4)
ssh hostinger 'docker inspect myinvoice-app-1 --format "{{range .Mounts}}{{.Source}}{{\"\n\"}}{{end}}" | grep -cE "invoice.css|invoice.twig|PdfBranding|InvoicePdfRenderer"'

# logy (kdyby PDF házelo chybu)
ssh hostinger 'docker logs --tail=50 myinvoice-app-1'
```
