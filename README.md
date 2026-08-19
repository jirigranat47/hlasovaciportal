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
   * Webová aplikace: [http://localhost:8000](http://localhost:8000)
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

Pro usnadnění lokálního vývoje bez nutnosti přihlašování přes reálný SkautIS můžete simulovat přihlášení různých rolí pomocí následujících URL adres:

* **Zakladatel / Administrátor jednotky** (zakládá hlasování, není v radě):
  [http://localhost:8000/sign/dev-login?unitId=123&personId=9999&personName=Admin+Zakladatel&roleKey=administrator&roleName=Administrátor](http://localhost:8000/sign/dev-login?unitId=123&personId=9999&personName=Admin+Zakladatel&roleKey=administrator&roleName=Administrátor)
* **Člen rady č. 1 (Jan Novak)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1001&personName=Jan+Novak&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1001&personName=Jan+Novak&roleKey=clened&roleName=Člen)
* **Člen rady č. 2 (Petr Svoboda)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1002&personName=Petr+Svoboda&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1002&personName=Petr+Svoboda&roleKey=clened&roleName=Člen)
* **Člen rady č. 3 (Marie Dvořáková)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1003&personName=Marie+Dvořáková&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1003&personName=Marie+Dvo%C5%99%C3%A1kov%C3%A1&roleKey=clened&roleName=%C4%8Clen)
* **Člen rady č. 4 (Tomáš Kučera)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1004&personName=Tomáš+Kučera&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1004&personName=Tom%C3%A1%C5%A1+Ku%C4%8Dera&roleKey=clened&roleName=%C4%8Clen)
* **Člen rady č. 5 (Lucie Černá)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1005&personName=Lucie+Černá&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1005&personName=Lucie+%C4%8Cern%C3%A1&roleKey=clened&roleName=%C4%8Clen)
* **Host / Běžný člen** (není v radě, nezaložil hlasování):
  [http://localhost:8000/sign/dev-login?unitId=123&personId=5555&personName=Host+Skaut&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=5555&personName=Host+Skaut&roleKey=clened&roleName=Člen)

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
