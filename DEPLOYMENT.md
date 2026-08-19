# 🚀 Příručka pro produkční nasazení (Deployment Guide)

Tento návod popisuje postup nasazení **Hlasovacího Portálu skautských jednotek** na produkční webhosting nebo server.

---

## 1. Jak zabalit Nette pro nahrání na hosting

Nette aplikace obsahuje tisíce souborů ve složce `vendor/`, proto je **vždy nejlepší nahrávat 1 komprimovaný ZIP archiv** (nahrávání tisíců malých souborů přes FTP by trvalo desítky minut a mohlo by selhat).

### Vytvoření produkčního balíčku:

Pokud používáte **Docker**, spusťte v terminálu (PowerShell / CMD):
```bash
docker exec hlasovaciportal_web php /var/www/html/bin/build-release.php
```
nebo na Windows jednoduše **dvakrát klikněte** na soubor:
```text
bin/build-release.bat
```
*(Pokud máte PHP nainstalováno i přímo v systému, funguje také standardní `php bin/build-release.php`)*.

Tento skript:
1. Optimalizuje Composer knihovny (`--no-dev --optimize-autoloader`) – zmenší velikost a zrychlí načítání tříd.
2. Vyčistí dočasnou mezipaměť (`temp/cache`).
3. Vytvoří čistý soubor **`hlasovaci-portal-release.zip`** (cca 1,2 MB) v kořenovém adresáři, který obsahuje jen potřebné produkční soubory a vynechává vývojové nástroje a lokální hesla.

---

## 2. Příprava databáze na hostingu

1. Ve webové administraci hostingu (např. phpMyAdmin / Adminer) vytvořte novou MySQL/MariaDB databázi (např. `hlasovaciportal`, kódování `utf8mb4_unicode_ci`).
2. Naimportujte strukturu ze souboru **`sql/structure.sql`**.

---

## 3. Nahrání a rozbalení na serveru

1. Nahrajte soubor `hlasovaci-portal-release.zip` na hosting (přes FTP, SFTP nebo správce souborů v administraci hostingu).
2. **Rozbalte ZIP archiv** přímo na hostingu (většina administrací hostingu nabízí tlačítko *Rozbalit / Unzip*).

---

## 4. Důležité: Nastavení kořenového adresáře (DocumentRoot)

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

## 5. Konfigurace aplikace (`config/local.neon`)

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

## 6. Oprávnění pro zápis (Práva souborů)

Zkontrolujte, zda webový server může zapisovat do adresářů:
* `temp/` (a `temp/cache/`)
* `log/`

*(Na linuxových serverech nastavte práva `chmod -R 775 temp log` nebo `chmod -R 777 temp log`).*

---

## 7. Nastavení plánovače (Cron)

Nastavte pravidelné volání cronu každých 15 minut:
```text
URL: https://hlasovani.vasedomena.cz/cron/run?token=GENERUJTE_SILNE_TAJNE_HESLO_PRO_CRON
Perioda: */15 * * * * (každých 15 minut)
```
Podrobnosti k nastavení naleznete v [CRON.md](file:///c:/__VYVOJ_SOUKR__/skaut_projekty/hlasovaciportal/CRON.md).

---

## 8. Kontrolní seznam před spuštěním pro uživatele

- [ ] Databáze byla vytvořena a naimportována z `sql/structure.sql`
- [ ] V `config/local.neon` jsou vyplněny přístupy k DB a produkční `appId`
- [ ] `isTest` je nastaveno na `false`
- [ ] Složky `temp/` a `log/` mají práva k zápisu
- [ ] DocumentRoot směřuje do složky `/www/`
- [ ] Cron běží každých 15 minut
- [ ] První administrátor se přihlásil a v sekci *Nastavení SMTP* nakonfiguroval odesílání e-mailů jednotky
