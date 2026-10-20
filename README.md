# ⚜ Skautský Hlasovací Portál

Aplikace pro online hlasování v prostředí Junák - český skaut, postavená na **Nette Frameworku 3.2** (PHP 8.4) a integrovaná s autentizačním systémem **SkautIS**.

## 🚀 Jak spustit aplikaci přes Docker

1. **Spuštění kontejnerů:**
   ```bash
   docker compose up -d --build
   ```

2. **Instalace PHP závislostí v kontejneru:**
   ```bash
   docker compose exec web composer install
   ```

3. **Prístup k aplikaci:**
   * Webová aplikace: [http://localhost:8089](http://localhost:8089)
   * Správce databáze Adminer: [http://localhost:8080](http://localhost:8080)

## 🔑 Konfigurace SkautISu

V souboru `config/local.neon` nastavte vaše **AppID** získané z [ws.skautis.cz/zadost](https://ws.skautis.cz/zadost):

```neon
parameters:
	skautis:
		appId: 'VAŠE-PRIDELENE-APP-ID'
		isTest: false # Pro vývoj true (směřuje na test-is.skaut.cz), pro produkci false
```

---

## 📋 Požadavky na SkautIS API (Podklady pro žádost o produkční AppID)

Při vyplňování žádosti o nové / produkční **AppID** na [ws.skautis.cz](https://ws.skautis.cz) uveďte následující webové služby a operace:

### 1. Webová služba `UserManagement` (Uživatelé a autentizace)
* **`UserDetail`** – získání údajů o přihlášeném uživateli (jméno, příjmení, Person ID, uživatelské jméno, e-mail).
* **`UserRoleAll`** – načtení všech rolí uživatele (pro přepínání jednotek/rolí v horní liště a detekci administrátorských oprávnění).
* **`LoginUpdate`** – přepnutí aktivní role uživatele v relaci SkautISu.
* **`LoginUpdateRefresh`** – udržování aktivní přihlašovací relace (Keep-Alive mechanismus).

### 2. Webová služba `OrganizationUnit` (Jednotky a členové)
* **`MembershipAll`** – načtení seznamu členů aktivní jednotky (pro našeptávač a správu hlasujících členů Rady jednotky: Person ID, jméno, datum narození, typ členství).
* **`PersonDetail`** – načtení základních údajů o vybrané osobě (jméno, přezdívka, výchozí e-mail) při zařazení do Rady jednotky.
* **`PersonContactAll`** – dohledání e-mailové adresy z kontaktů osoby ve SkautISu pro doručování e-mailových výzev a upomínek k hlasování.

### 📝 Vzorový text popisu aplikace pro schvalovatele SkautISu:
> *„Webová aplikace Hlasovací Portál slouží pro elektronické hlasování (per rollam) rad skautských jednotek (středisek, okresů, krajů). Uživatelé se přihlašují svými skautIS účty, aplikace ověřuje jejich role v jednotce a umožňuje členům rady hlasovat o návrzích usnesení a správcům spravovat Radu jednotky s dohledáním kontaktů.“*

---

## 🛠 Lokální simulace přihlášení (vývoj a testování)

Pro usnadnění lokálního vývoje bez nutnosti přihlašování přes reálný SkautIS můžete simulovat přihlášení různých rolí pomocí níže uvedených odkazů (nebo přímo kliknutím na uvítací stránce po spuštění portálu):

### 🔄 Testovací uživatelé s rolemi ve VÍCE JEDNOTKÁCH (přepínání jednotek)
U těchto uživatelů se v horní liště zobrazí rozbalovací nabídka **„Přepnout jednotku a roli“** (`fa-arrows-rotate`), kde můžete libovolně přepínat aktivní jednotku a sledovat dynamickou změnu oprávnění i načtených usnesení:

* ⭐ **Člen rady s více jednotkami – Jan Novák** (`Person ID = 1001`):
  * **1. středisko Dvojka (#123):** Člen rady střediska (může hlasovat o usneseních střediska).
  * **Okres Hradec Králové (#456):** Správce / Administrátor okresu (vidí správu rady, audit i možnost založit usnesení).
  * **Královéhradecký kraj (#25784):** Činovník kraje (host / nečlen krajské rady, vidí krajská usnesení).
  * 👉 **[Přihlásit se jako Jan Novák (více jednotek)](http://localhost:8089/sign/dev-login?unitId=123&personId=1001&personName=Jan+Novak&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka)**

* ⭐ **Administrátor s více jednotkami – Admin Zakladatel** (`Person ID = 9999`):
  * **1. středisko Dvojka (#123):** Vedoucí / administrátor střediska.
  * **Okres Hradec Králové (#456):** Aktivní činovník okresu.
  * **Královéhradecký kraj (#25784):** Vedoucí / administrátor kraje.
  * 👉 **[Přihlásit se jako Admin Zakladatel (více jednotek)](http://localhost:8089/sign/dev-login?unitId=123&personId=9999&personName=Admin+Zakladatel&roleKey=vedouciStredisko&roleName=St%C5%99edisko%3A+vedouc%C3%AD%2Fadmin&unitName=1.+st%C5%99edisko+Dvojka)**

* 👑 **Superadministrátor aplikace** (`Person ID = 1`):
  * Přístup do Superadmin portálu + role v jednotkách 10001, 123 a 25784.
  * 👉 **[Přihlásit se jako Superadministrátor](http://localhost:8089/sign/dev-login?unitId=10001&personId=1&personName=Superadmin+Aplikace&roleKey=vedouciStredisko&roleName=St%C5%99edisko%3A+vedouc%C3%AD%2Fadmin&unitName=St%C5%99edisko+Superadmin)**

### 👤 Testovací uživatelé s JEDNOU JEDNOTKOU (bez přepínání)
Tito uživatelé mají roli pouze v jedné jednotce (v horní liště se rozbalovací nabídka pro přepínání nezobrazuje):

* **Člen rady č. 2 (Petr Svoboda)**:
  [http://localhost:8089/sign/dev-login?unitId=123&personId=1002&personName=Petr+Svoboda&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka](http://localhost:8089/sign/dev-login?unitId=123&personId=1002&personName=Petr+Svoboda&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka)
* **Člen rady č. 3 (Marie Dvořáková)**:
  [http://localhost:8089/sign/dev-login?unitId=123&personId=1003&personName=Marie+Dvo%C5%99%C3%A1kov%C3%A1&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka](http://localhost:8089/sign/dev-login?unitId=123&personId=1003&personName=Marie+Dvo%C5%99%C3%A1kov%C3%A1&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka)
* **Člen rady č. 4 (Tomáš Kučera)**:
  [http://localhost:8089/sign/dev-login?unitId=123&personId=1004&personName=Tom%C3%A1%C5%A1+Ku%C4%8Dera&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka](http://localhost:8089/sign/dev-login?unitId=123&personId=1004&personName=Tom%C3%A1%C5%A1+Ku%C4%8Dera&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka)
* **Člen rady č. 5 (Lucie Černá)**:
  [http://localhost:8089/sign/dev-login?unitId=123&personId=1005&personName=Lucie+%C4%8Cern%C3%A1&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka](http://localhost:8089/sign/dev-login?unitId=123&personId=1005&personName=Lucie+%C4%8Cern%C3%A1&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka)
* **Host / Běžný člen** (není v radě, nezaložil hlasování):
  [http://localhost:8089/sign/dev-login?unitId=123&personId=5555&personName=Host+Skaut&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka](http://localhost:8089/sign/dev-login?unitId=123&personId=5555&personName=Host+Skaut&roleKey=cinovnikStredisko&roleName=St%C5%99edisko%3A+aktivn%C3%AD+%C4%8Dinovn%C3%ADk&unitName=1.+st%C5%99edisko+Dvojka)

---

## 👑 Superadmin Portál a správa superadministrátorů

Portál slouží pro centrální správu a globální přehled o využívání aplikace napříč celou organizací Junák – český skaut. Je přístupný pouze oprávněným superadministrátorům v horní liště aplikace (zlaté tlačítko s korunkou **Superadmin**) nebo přímo na adrese [http://localhost:8089/superadmin](http://localhost:8089/superadmin).

### ⚙ Konfigurace superadministrátorů
Superadministrátoři jsou definováni v konfiguračním souboru `config/local.neon` (případně v produkční konfiguraci) pomocí seznamu **SkautIS Person ID**:

```neon
parameters:
	superadmins:
		- 1      # Lokální dev superadmin (pro lokální testování, viz odkaz níže)
		- 12345  # Reálné SkautIS Person ID hlavního správce
		- 67890  # Další pověřený administrátor
```

> **Princip oprávnění:** Oprávnění superadmina je pevně vázáno na konkrétní fyzickou osobu (její `Person ID` ve SkautISu). Uživatel má přístup do portálu bez ohledu na to, za jakou skautskou jednotku (středisko, okres, kraj) nebo v jaké roli je právě přihlášen. Neoprávněný uživatel je při pokusu o vstup automaticky odmítnut chybou 403 Forbidden.

### 📊 Funkce a přehledy portálu
* **Klíčové metriky (KPI):**
  * Celkový počet zapojených jednotek.
  * Počet usnesení v systému (aktivní vs. uzavřená).
  * Celkový počet odevzdaných hlasů.
  * Globální průměrná volební účast (napříč všemi jednotkami a usneseními).
* **Rozdělení výsledků usnesení:**
  * Procentuální a vizuální rozpad usnesení: Schváleno (Přijato), Neschváleno (Nepřijato), Zrušeno a Probíhající.
* **Katalog a aktivita jednotek:**
  * Přehledná tabulka všech jednotek evidovaných v systému:
    * Typ jednotky (Středisko, Okres, Kraj).
    * Počet členů rady jednotky.
    * Počet usnesení celkem.
    * Průměrná volební účast v dané jednotce.
    * Datum poslední aktivity v systému.
  * Možnost filtrování a okamžitého vyhledávání podle názvu, čísla nebo typu jednotky.
* **Detail jednotky (`/superadmin/unit/<id>`):**
  * Detailní statistiky konkrétní jednotky, aktuální složení její rady a kompletní archiv jejích usnesení včetně výsledků a volební účasti.
* **Auditní protokol:**
  * Záznam přístupů superadministrátorů do portálu (datum a čas, jméno, SkautIS Person ID, IP adresa a klientský prohlížeč / User-Agent).

### 🔗 Rychlý odkaz pro lokální testování jako Superadmin:
* **[Přihlásit se jako Superadministrátor](http://localhost:8089/sign/dev-login?unitId=10001&personId=1&personName=Superadmin+Aplikace&roleKey=vedouciStredisko&roleName=Administrátor&unitName=Středisko+Superadmin)**
* Po kliknutí budete přihlášeni jako uživatel s `Person ID = 1` a v horní navigační liště se vám zobrazí zlaté tlačítko **👑 Superadmin**, které vás přesměruje do portálu.

---

## ✉️ E-mailové notifikace, SMTP a Nastavení E-mailu

V aplikaci existují dva způsoby posílání e-mailů a možnost individuálního nastavení adres:

1. **Vlastní e-mail uživatele**:
   Po přihlášení do aplikace může každý uživatel kliknout na své jméno nebo na ikonku "Nastavení" v pravém horním rohu. Zde si může prohlédnout e-mail, který byl načten ze SkautISu. Pokud mu tento e-mail nevyhovuje, může si nastavit **vlastní e-mailovou adresu**, na kterou mu budou chodit notifikace, a může si odeslat zkušební testovací zprávu.

2. **Centrální systémový účet** (`config/local.neon`):

Aplikace rozesílá výzvy k zahájení hlasování, denní upomínky pro nehlasující členy a vyhodnocení výsledků po uzavření usnesení.

### 🌐 Centrální odesílání (`hlasovani@skaut.cz`)
* Všechny notifikace jsou pro celou aplikaci odesílány **centrálně z oficiálního účtu `hlasovani@skaut.cz`** (Google Workspace).
* **Nulová konfigurace pro střediska:** Běžní administrátoři jednotek nemusí nastavovat žádné SMTP servery, porty, SPF/DKIM záznamy ani generovat hesla aplikací – systém funguje ihned po přihlášení.
* **Vlastní jméno odesílatele:** V sekci **Nastavení jednotky** si každé středisko může upravit pole *Jméno odesílatele v e-mailu* (např. *Rada 1. střediska Kostelec*). Členům rady tak e-maily dorazí s hlavičkou jejich střediska.
* **Konfigurace serveru:** Technické parametry SMTP jsou nastaveny v `config/common.neon` a 16místné heslo aplikace pro účet `hlasovani@skaut.cz` se zadává v `config/local.neon`:
  ```neon
  parameters:
  	smtp:
  		password: 'vložte-16místné-heslo-aplikace-k-hlasovani@skaut.cz'
  ```

### 🔮 Plán do budoucna (Volitelné vlastní SMTP jednotky)
Do budoucna je připravena možnost přepínání mezi dvěma režimy:
1. **Globální nastavení (výchozí):** Využití centrálního účtu `hlasovani@skaut.cz`.
2. **Vlastní SMTP jednotky:** Možnost, aby si konkrétní středisko v administraci zapnulo a nakonfigurovalo vlastní poštovní server (vlastní doménu, Seznam, Google Workspace střediska apod.).
> **Poznámka:** Kompletní databázová struktura (tabulka `smtp_settings`), šifrování hesel pomocí AES-256 i formulářové komponenty pro individuální SMTP zůstávají v kódu zachovány a budou moci být v budoucnu jednoduše znovu zpřístupněny jako volitelná volba v nastavení jednotky.

---

## 🔒 Bezpečnost a integrita dat

Podrobná bezpečnostní analýza a argumentace ohledně ochrany proti manipulaci s hlasy, důvěryhodnosti databázových záznamů a izolace jednotek je zpracována v samostatném dokumentu [`ANALYZA-BEZPECNOSTI.md`](ANALYZA-BEZPECNOSTI.md).

---

## ⏰ Nastavení a automatizace Cronu (Plánovače)

Aplikace využívá plánovač úloh pro:
* **Hromadné výzvy k novým hlasováním** (automatický noční fallback pro zapomenutá usnesení)
* **Denní personalizované upomínky v 18:00** (den před ukončením hlasování pro nehlasující členy)
* **Noční vyhodnocení výsledků po půlnoci** (souhrnný e-mail s výsledky PŘIJATO / NEPŘIJATO)

Cron je navržen tak, aby jej bylo možné bezpečně volat **každých 15 minut** (např. přes službu [cron-job.org](https://cron-job.org) nebo systémový Linux crontab).

Kompletní návod k nastavení, tabulku časování a příklady konfigurace naleznete v dokumentu **[`CRON.md`](CRON.md)**.

---

## 🧪 Automatizované testy (Nette Tester)

Aplikace využívá pro testování integrační testy postavené na **[Nette Tester](https://tester.nette.org/)** s oddělenou testovací databází.

### ⚙ Příprava testovacího prostředí
1. V databázovém serveru (MySQL) musí existovat testovací databáze (výchozí název `skautis_voting_test`) s naimportovanou strukturou ze souboru `sql/structure.sql`.
2. V souboru `tests/config/local.neon` je nakonfigurováno připojení pro testy:
   ```neon
   database:
       dsn: 'mysql:host=db;dbname=skautis_voting_test;charset=utf8mb4'
       user: 'root'
       password: 'root_secret_pass'
   ```

### ▶ Spuštění testů
Testy se spouštějí uvnitř běžícího webového Docker kontejneru:
```bash
docker compose exec web composer test
```
*(případně `docker exec -t hlasovaciportal_web composer test`)*

### ⚠️ Zásada pro další vývoj (Pravidlo testování)
> **DŮLEŽITÉ:** Veškeré budoucí změny a nové funkce v aplikaci (zejména práce s databází, změny v `VotingRepository`, logika oprávnění, validace a pravidel hlasování) **musí být pokryty a ověřeny automatizovanými testy**.
> Před commitem nebo nasazením do produkce musí vždy projít celá sada testů (`OK`).

---

## 🗄️ Smazání jednotky a navazujících záznamů (SQL)

Pokud je potřeba z databáze kompletně odstranit testovací, duplicitní nebo zrušenou jednotku včetně všech jejích souvisejících dat, je nutné promazat záznamy v následujících tabulkách:

1. **`elections` (Hlasování / Usnesení):**
   * Při smazání usnesení dané jednotky se díky cizím klíčům s `ON DELETE CASCADE` automaticky promažou i podřízené záznamy:
     * `options` (možnosti hlasování),
     * `votes` (odevzdané hlasy),
     * `vote_history` (historie průběhu hlasování),
     * `election_audit_logs` (auditní záznamy akcí nad hlasováním).
   * *Pro maximální bezpečnost a nezávislost na nastavení cizích klíčů skript obsahuje i explicitní smazání těchto podřízených tabulek.*
2. **`council_members`:** Seznam členů rady dané jednotky.
3. **`unit_settings`:** Individuální nastavení jednotky (vlastní jméno odesílatele, min. délka hlasování apod.).
4. **`smtp_settings`:** Původní / vlastní SMTP konfigurace jednotky.
5. **`email_logs`:** Historie odeslaných e-mailových notifikací a chybových hlášení vázaných na jednotku.
6. **`user_login_logs`:** Historie auditních záznamů o přihlášení uživatelů pod danou jednotkou.

> **Upozornění k tabulce `users`:**
> Tabulka `users` se **nemaže**, protože obsahuje globální uživatelské účty fyzických osob (vázané na SkautIS `person_id`), které mohou mít oprávnění nebo působit i v jiných jednotkách.

---

### 📝 Obecný SQL skript (šablona)

```sql
START TRANSACTION;

-- Zadejte ID jednotky (nebo seznam ID oddělených čárkami v klauzuli IN)
-- Příklad pro jednu jednotku: unit_id IN (12345)
-- Příklad pro více jednotek: unit_id IN (12345, 67890)

-- 1. Smazání podřízených záznamů hlasování
DELETE FROM election_audit_logs WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (<ID_JEDNOTEK>));
DELETE FROM vote_history WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (<ID_JEDNOTEK>));
DELETE FROM votes WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (<ID_JEDNOTEK>));
DELETE FROM options WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (<ID_JEDNOTEK>));

-- 2. Smazání samotných hlasování / usnesení
DELETE FROM elections WHERE unit_id IN (<ID_JEDNOTEK>);

-- 3. Smazání členů rady jednotky
DELETE FROM council_members WHERE unit_id IN (<ID_JEDNOTEK>);

-- 4. Smazání konfigurace a nastavení jednotky
DELETE FROM unit_settings WHERE unit_id IN (<ID_JEDNOTEK>);
DELETE FROM smtp_settings WHERE unit_id IN (<ID_JEDNOTEK>);

-- 5. Smazání auditních logů vázaných na jednotku
DELETE FROM email_logs WHERE unit_id IN (<ID_JEDNOTEK>);
DELETE FROM user_login_logs WHERE unit_id IN (<ID_JEDNOTEK>);

COMMIT;
```

---

### 🚀 Připravený SQL skript pro zadané jednotky:
**Jednotky:** `26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662`

```sql
START TRANSACTION;

-- 1. Smazání podřízených záznamů hlasování (auditní logy, historie, hlasy, možnosti)
DELETE FROM election_audit_logs WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662));
DELETE FROM vote_history WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662));
DELETE FROM votes WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662));
DELETE FROM options WHERE election_id IN (SELECT id FROM elections WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662));

-- 2. Smazání samotných hlasování
DELETE FROM elections WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662);

-- 3. Smazání členů rady
DELETE FROM council_members WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662);

-- 4. Smazání nastavení jednotky a SMTP konfigurace
DELETE FROM unit_settings WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662);
DELETE FROM smtp_settings WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662);

-- 5. Smazání logů vázaných na jednotku (e-maily a přihlášení)
DELETE FROM email_logs WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662);
DELETE FROM user_login_logs WHERE unit_id IN (26405, 26474, 27294, 28914, 26458, 23200, 26424, 26462, 26456, 31662);

COMMIT;
```


