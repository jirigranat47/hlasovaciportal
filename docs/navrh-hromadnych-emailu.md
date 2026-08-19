# Návrh systému souhrnných (dávkových) e-mailových notifikací

Tento dokument specifikuje návrh úpravy e-mailových notifikací v **Hlasovacím portálu skautských jednotek**. Cílem je eliminovat zahlcení členů rady velkým množstvím e-mailů (*e-mail fatigue*) při přípravě a publikování více usnesení současně a přejít na systém **souhrnných (digest) zpráv**.

---

## 1. Souhrn konceptu

| Typ e-mailu | Způsob odeslání | Příjemci | Obsah |
| :--- | :--- | :--- | :--- |
| **1. Hromadná výzva k novým hlasováním** | Manuálně tlačítkem správce (+ volitelně noční fallback) | Všichni členové rady jednotky | Seznam všech nově vyhlášených usnesení za daný den / dávku s odkazy a termíny. |
| **2. Denní souhrnná upomínka** | Automaticky denním cronem (např. v 18:00) | Pouze členové rady s **neodhlasovanými** usneseními | 1 personalizovaný e-mail se seznamem usnesení, kde daný člen ještě neodevzdal hlas a blíží se termín. |
| **3. Noční souhrnné výsledky** | Automaticky nočním cronem (např. v 00:05) | Všichni členové rady jednotky | 1 e-mail se souhrnem všech usnesení ukončených v předešlém dni (výsledky PŘIJATO / NEPŘIJATO, počty hlasů). |
| **4. Okamžité storno** | Ihned při akci správce | Všichni členové rady jednotky | Upozornění na stornování konkrétního hlasování včetně zadaného důvodu. |

---

## 2. Detailní specifikace jednotlivých zpráv

### 1. Hromadná výzva k nově vyhlášeným hlasováním 🚀

#### Workflow pro správce:
1. Administrátor si v aplikaci připraví libovolný počet návrhů (Draftů) – např. 5 usnesení.
2. Návrhy postupně publikuje (stav `published`, `notification_sent = 0`). Při samotné publikaci **neodchází žádný e-mail**.
3. Na hlavní stránce (Dashboardu) se administrátorovi zobrazí zvýrazněný pruh:
   > 📬 **Máte 5 nově publikovaných usnesení, o kterých členové rady ještě nebyli notifikováni.**  
   > `[ ✉ Odeslat členům rady souhrnnou notifikaci (5 usnesení) ]`
4. Po kliknutí administrátor může volitelně zkontrolovat náhled a potvrdit odeslání.
5. Členům rady dorazí **jeden e-mail**:
   * **Předmět:** `Nová hlasování rady: Byla vyhlášena 4 nová usnesení – [Název jednotky]`
   * **Obsah:** Přehledná tabulka s číslem usnesení, názvem, termínem do kdy hlasovat a tlačítkem „Přejít k hlasování“.

---

### 2. Denní souhrnná upomínka pro nehlasující členy ⏳

#### Workflow:
1. Cron běží jednou denně v definovaný čas (např. každý den v 18:00).
2. Pro každou jednotku projde všechna aktivní usnesení, kterým zbývá do konce méně než 48 hodin (nebo 24 hodin).
3. Pro každého člena rady zjistí, u kterých z těchto usnesení **ještě nehlasoval**.
4. Pokud má člen alespoň 1 neodhlasované usnesení, odešle se mu **jeden souhrnný e-mail**:
   * **Předmět:** `Připomenutí: Zbývá vám odhlasovat 3 usnesení – [Název jednotky]`
   * **Obsah:** Seznam konkrétních usnesení, která na člena čekají, s termínem konce (např. *zítra ve 23:59*) a přímými odkazy.
5. Členové, kteří již mají vše odhlasováno, e-mail vůbec nedostanou.

---

### 3. Noční vyhodnocení výsledků po půlnoci 🏁

#### Workflow:
1. Všechna usnesení mají termín nastaven do konce dne (`23:59:59`).
2. Noční cron se spustí v **00:05** po půlnoci.
3. Shromáždí všechna usnesení, která včera ve 23:59 skončila a nemají odeslané výsledky (`results_sent = 0`).
4. Pokud skončilo více usnesení naráz (např. 5 usnesení), odejde **jeden souhrnný e-mail**:
   * **Předmět:** `Výsledky hlasování rady za den 18. 8. 2026 (5 usnesení) – [Název jednotky]`
   * **Obsah:**
     * Přehled každého usnesení s barevným štítkem:
       * Usnesení č. 2026/01: **PŘIJATO** (Pro: 8, Proti: 1, Zdržel se: 1)
       * Usnesení č. 2026/02: **PŘIJATO** (Pro: 10, Proti: 0, Zdržel se: 0)
       * Usnesení č. 2026/03: **NEPŘIJATO** (Pro: 4, Proti: 5, Zdržel se: 1)
     * Odkaz na kompletní zápisy a detailní jmenné protokoly v portálu.

---

### 4. Mimořádné okamžité notifikace 🚫

* **Storno hlasování:** Odesílá se ihned při stornování usnesení správcem. Uvede číslo usnesení, jméno kdo stornoval a důvod storna.

---

## 3. Technické změny v kódu

1. **Databázové příznaky:**
   * Tabulka `elections` již obsahuje sloupce `notification_sent`, `reminder_sent`, `results_sent`.
   * Doplnění hromadného označování po odeslání dávky.

2. **Úpravy v `CronManager.php`:**
   * `sendBatchNewElectionsNotification(int $unitId, array $electionIds)`: Sestaví 1 e-mail pro zadaný seznam usnesení a rozešle radě.
   * `sendDailyReminders()`: Seskupí neodhlasovaná usnesení per uživatel.
   * `sendDailyResultsDigest()`: Seskupí včera ukončená usnesení per jednotka.

3. **Uživatelské rozhraní ([Home:default](file:///c:/__VYVOJ_SOUKR__/skaut_projekty/hlasovaciportal/app/Presentation/Home/default.latte)):**
   * Panel pro administrátora s počtem neodeslaných usnesení a tlačítkem pro odeslání dávky.

---

## 4. Body k diskuzi a připomínkování pro zítřek

Při připomínkování se zaměřte zejména na tyto otázky:

1. **Časování denní upomínky:** V kolik hodin má odcházet upomínka pro nehlasující? (Např. v 8:00 ráno v den ukončení hlasování, nebo 24h předem v 18:00?)
2. **Automatické odeslání nové dávky:** Pokud správce publikuje 5 usnesení a zapomene kliknout na tlačítko *„Odeslat hromadnou notifikaci“*, má noční/večerní cron tyto neodeslané notifikace automaticky poslat sám v souhrnném e-mailu?
3. **Předmět souhrnného e-mailu:** Vyhovuje formát např. `Nová hlasování: Byla vyhlášena 3 usnesení – Středisko Dvojka`?
