# 🚀 Příručka pro produkční nasazení (Deployment Guide)

Tento návod popisuje postup nasazení **Hlasovacího Portálu skautských jednotek** na produkční webhosting nebo server.

---

## ⚡ 1. Nasazení na hosting přes FTP skript (`deploy.bat`)

Pro rychlé a bezpečné nasazení na server použijte připravený skript:

1. V kořenovém adresáři vytvořte soubor **`deploy-config.json`** s údaji k FTP/SFTP (podle šablony `deploy-config.example.json`).
2. Dvakrát klikněte na soubor **`deploy.bat`** (nebo v PowerShellu spusťte `.\deploy.ps1`).

**Co skript automaticky dělá:**
- Zkopíruje čistou produkční verzi (`app`, `vendor`, `www`, `config`, `composer.json`) do dočasné složky.
- Automaticky **vynechá lokální vývojový `config/local.neon`** (aby nepřepsal produkční hesla a nastavení na serveru). Pokud chcete nasadit novou produkční konfiguraci z lokálu, stačí ji připravit jako `config/local.production.neon`.
- Vynechá složku `bin/`, vývojové nástroje, testy i dokumentaci.
- Připojí se přes WinSCP (FTP/SFTP) a nahraje/aktualizuje pouze **nové a upravené soubory**.
- Po dokončení automaticky uklidí a smaže dočasnou složku.

---

## 2. Příprava databáze na hostingu

1. Ve webové administraci hostingu (např. phpMyAdmin / Adminer) vytvořte novou MySQL/MariaDB databázi (např. `hlasovaciportal`, kódování `utf8mb4_unicode_ci`).
2. Naimportujte strukturu ze souboru **`sql/structure.sql`**.

---

## 3. Důležité: Nastavení kořenového adresáře (DocumentRoot)

Z bezpečnostních důvodů musí webový server směřovat do podsložky **`/www/`**!

### Proč?
Složky `app/`, `config/`, `log/`, `temp/` a `vendor/` obsahují zdrojové kódy a konfigurační soubory s hesly. Pokud DocumentRoot směřuje do `www/`, tyto citlivé složky jsou pro návštěvníky z internetu zcela nedostupné.

### Jak nastavit:
* **Ve správě hostingu (cPanel, Wedos, Forpsi, ISPConfig):**
  * Nastavte *Kořenový adresář domény / DocumentRoot* na: `hlasovaciportal/www` (případně `/subdomena/www`).
* **Pokud hosting neumožňuje změnit DocumentRoot:**
  * V kořenovém adresáři (nad složkou `www`) ponechte soubor `.htaccess`, který automaticky přesměruje všechny požadavky do složky `www/`:
    ```apache
    RewriteEngine On
    RewriteRule ^$ www/ [L]
    RewriteRule (.*) www/$1 [L]
    ```

---

## 4. Konfigurace aplikace (`config/local.neon`)

Na produkčním serveru ve složce `config/` vytvořte soubor **`local.neon`** podle šablony `config/local.neon.template`:

```neon
parameters:
    skautis:
        appId: 'VASE_PRODUKCNI_SKAUTIS_APP_ID'
        isTest: false     # Na produkci VŽDY false!
        debugRoles: false

    cron:
        token: 'GENERUJTE_SILNE_TAJNE_HESLO_PRO_CRON'

database:
    dsn: 'mysql:host=localhost;dbname=NAZEV_PRODUKCNI_DB;charset=utf8mb4'
    user: 'UZIVATEL_DB'
    password: 'HESLO_K_PRODUKCNI_DB'
```

> [!IMPORTANT]
> Nezapomeňte nastavit `isTest: false`, aby se přihlášení ověřovalo proti ostrému SkautISu (`is.skaut.cz`), nikoliv testovacímu.

---

## 5. Oprávnění pro zápis (Práva souborů)

Zkontrolujte, zda webový server může zapisovat do adresářů:
* `temp/` (a `temp/cache/`)
* `log/`

*(Na linuxových serverech nastavte práva `chmod -R 775 temp log` nebo `chmod -R 777 temp log`).*

---

## 6. Nastavení plánovače (Cron)

Nastavte pravidelné volání cronu každých 15 minut:
```text
URL: https://hlasovani.vasedomena.cz/cron/run?token=GENERUJTE_SILNE_TAJNE_HESLO_PRO_CRON
Perioda: */15 * * * * (každých 15 minut)
```
Podrobnosti k nastavení naleznete v [CRON.md](file:///c:/__VYVOJ_SOUKR__/skaut_projekty/hlasovaciportal/CRON.md).

---

## 7. Kontrolní seznam před spuštěním pro uživatele

- [ ] Databáze byla vytvořena a naimportována z `sql/structure.sql`
- [ ] V `config/local.neon` jsou vyplněny přístupy k DB a produkční `appId`
- [ ] `isTest` je nastaveno na `false`
- [ ] Složky `temp/` a `log/` mají práva k zápisu
- [ ] DocumentRoot směřuje do složky `/www/`
- [ ] Cron běží každých 15 minut
- [ ] První administrátor se přihlásil a v sekci *Nastavení SMTP* nakonfiguroval odesílání e-mailů jednotky
