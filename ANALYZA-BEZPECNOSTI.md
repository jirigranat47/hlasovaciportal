# Analýza bezpečnosti dat a důvěryhodnosti Hlasovacího Portálu

Tento dokument zpracovává bezpečnostní analýzu a bezpečnostní argumentaci aplikace **Hlasovací Portál**. Slouží jako podklad pro odpovědi na případné námitky uživatelů či revizních orgánů ohledně možnosti manipulace s daty, integrity hlasování a ochrany důvěrných informací.

---

## 1. Kontext a účel aplikace

1. **Pomocný nástroj pro hlasování Per Rollam**:
   Hlasovací portál slouží pro operativní elektronické schvalování usnesení Rady jednotky mezi fyzickými/online zasedáními (per rollam).
2. **Vazba na oficiální právní dokumenty**:
   Každé schválené usnesení se zapisuje do oficiálního, podepsaného **Zápisu z jednání rady**, který je konečným právním a archivačním dokumentem jednotky.
3. **Transparentnost vůči členům**:
   Portál zajišťuje, že všichni členové rady mají stejné informace, okamžitou notifikaci do e-mailu a možnost ověřit si svůj hlas.

---

## 2. Rizikový model a odpovědi na námitky

### Námitka 1: „Co když správce aplikace / databáze tichou úpravou v DB změní znění usnesení v průběhu hlasování?“

#### Současné bezpečnostní záruky:
* **E-mailový otisk (Imutabilní notifikace)**:
  Při publikaci hlasování se všem členům rady okamžitě odesílá e-mailová notifikace obsahující **přesný text usnesení** a **číslo usnesení**.
  - E-mail doručený na servery příjemců (Skautis / Google / Seznam atd.) **nelze z databáze portálu nijak upravit ani smazat**.
  - Pokud by správce změnil text v DB, libovolný člen rady může porovnat text v aplikaci s textem doručeným e-mailem a změnu ihned odhalit.
* **Procesní pravidlo Stornování (Varianta A)**:
  Aplikace neumožňuje správci přímo upravovat text již publikovaného hlasování skrze rozhraní. Správce musí hlasování **stornovat s udáním důvodu** (což opět odešle e-mail) a založit nové usnesení.
* **Auditní log (`vote_history`)**:
  Veškeré změny a hlasování se zaznamenávají s přesným časovým razítkem (TIMESTAMP) a jménem uživatele.

#### Doporučené technické vylepšení (Kryptografický kontrolní součet - SHA256):
Pro 100% matematický důkaz integrity lze zavedením jednoduché funkce generovat při publikaci **SHA-256 hash** ze znění usnesení:
- Hash `SHA256(unit_id + resolution_number + title + description + created_at)` se uloží do DB a odešle v e-mailu.
- Jakákoliv ruční změna jediného znaku v databázi by zneplatnila kontrolní součet a aplikace by okamžitě zobrazila varování: *„Varování: Integrita usnesení byla porušena!“*.

---

### Námitka 2: „Co když někdo nechce, aby se veřejně či v rámci organizace vědělo, o čem daná rada hlasuje?“

#### Současné bezpečnostní záruky:
* **Striktní izolace jednotek (Multi-tenancy)**:
  Přístup k hlasováním je v databázových dotazech striktně filtrován podle `unit_id` přihlášeného uživatele ze SkautISu. Uživatel z jiné jednotky (ani z jiné rady) se k usnesením dané jednotky nedostane.
* **Ochrana před veřejností (Hosté a nečlenové)**:
  Běžný člen Junáka nebo neregistrovaný uživatel nevidí probíhající hlasování ani rozpracované návrhy. Vidí pouze uzavřená hlasování, u kterých je sám uveden v seznamu odevzdaných hlasů.
* **Autentizace přes SkautIS OAuth2**:
  Do aplikace se nelze přihlásit anonymně ani vymyslet uživatelské jméno/heslo. Identita je garantována centrálním ověřovacím serverem Junáka (SkautIS).

#### Doporučení pro citlivá usnesení:
- Pro usnesení obsahující citlivé osobní údaje (např. finanční podpory konkrétním osobám, řešení sporů) lze do Poznámky vložit pouze odkaz na šifrovaný disk/úložiště jednotky nebo odkazovat na anonymizované identifikátory (např. *„Poskytnutí slevy členovi ev. č. XXX“*).

---

### Námitka 3: „Co když správce změní hlasy člena rady v databázi?“

#### Současné bezpečnostní záruky:
* **Viditelnost jmenného seznamu hlasujících**:
  Každý člen rady vidí v detailu hlasování **jmenný přehled všech hlasů** včetně svého vlastního.
* **Zpětná kontrola po uzavření**:
  Po skončení hlasování odejde všem členům rady e-mailový výpis obsahující **kompletní tabulku jmen a odevzdaných hlasů**.
* **Odhalitelnost**:
  Pokud by správce změnil v DB hlas Jana Nováka z „Proti“ na „Pro“, Jan Novák si toho ihned všimne v aplikaci i v závěrečném e-mailu a vznese protest doložený zápisem z jednání.

---

## 3. Matice rizik a opatření

| Riziko | Pravděpodobnost | Dopad | Opatření v aplikaci |
| :--- | :---: | :---: | :--- |
| **Ruční změna textu v DB** | Nízká | Střední | E-mailové notifikace s plným textem; zákaz editace publikovaných usnesení v UI; možnost zavedení SHA-256 checksumu. |
| **Únik informací mimo radu** | Nízká | Střední | Striktní kontrola oprávnění na `unit_id` a roli ze SkautISu; nečlenové rady probíhající hlasování nevidí. |
| **Neoprávněné hlasování za jiného** | Velmi nízká | Vysoká | Přihlašování je chráněno centrálním SkautIS SSO a možnou dvoufázovou autentizací SkautISu. |
| **Zpochybnění výsledku per-rollam** | Nízká | Nízká | Závěrečný e-mail s kompletní tabulkou hlasů; přepis schváleného usnesení do oficiálního zápisu rady. |

---

## 4. Závěrečné shrnutí pro revizní orgány a uživatele

> **Aplikace Hlasovací Portál je navržena tak, aby poskytovala maximální transparentnost a neovlivnitelnost:**
> 1. Všechny klíčové kroky (publikace, stornování, vyhodnocení) generují **okamžitý nezměnitelný e-mailový otisk** odesílaný na osobní e-maily členů rady.
> 2. Žádnou úpravu znění probíhajícího hlasování nelze provést skrytě – aplikace vyžaduje **storno s udáním důvodu** a odesláním notifikace.
> 3. Konečným garantem správnosti rozhodnutí zůstává schválený **Zápis z jednání rady**, proti kterému si může každý člen rady ověřit doručené notifikace.
