# ⏰ Nastavení a spouštění Cronu pro Hlasovací Portál

Aplikace Hlasovací Portál využívá plánovač úloh (Cron) pro automatické e-mailové notifikace a vyhodnocování usnesení.

---

## 📬 Jak fungují e-mailové notifikace v čase

Systém je navržen tak, aby se cron mohl spouštět **každých 15 minut** (např. přes službu `cron-job.org` nebo systémový Linux crontab). Odesílání se řídí časovými branami a databázovými příznaky, takže se žádný e-mail neodešle předčasně ani duplicitně:

| Čas běhu cronu | Co se stane | Detail chování |
| :--- | :--- | :--- |
| **Přes den (06:00 – 17:59)** *(např. v 10:15)* | **Nic neodchází** | Správce má přes den čas publikovat usnesení a poslat hromadnou výzvu tlačítkem z Dashboardu. Upomínky ani výsledky v tuto dobu neodchází. |
| **V 18:00 (18:00 – 23:59)** *(např. v 18:00 nebo 18:15)* | **Odešlou se personalizované upomínky** | Pro usnesení končící zítra ve 23:59 (do 30 h) odejde každému nehlasujícímu členovi rady 1 osobní e-mail s výčtem chybějících hlasů. Nastaví se `reminder_sent = 1`. |
| **Zbytek večera (18:30 – 23:59)** | **Nic dalšího neodchází** | Všechna zítřejší usnesení již mají `reminder_sent = 1`, takže další běhy cronu nic neposílají. |
| **Po půlnoci (00:00 – 05:59)** *(např. v 00:15)* | **1. Souhrnné vyhodnocení výsledků**<br>**2. Noční fallback nových usnesení** | • **Výsledky:** Hlasování ze včerejška skončila ve 23:59 (`end_date <= NOW()`) a mají `results_sent = 0`. Odejde 1 souhrnný e-mail se všemi výsledky (PŘIJATO/NEPŘIJATO + počty hlasů) a nastaví se `results_sent = 1`.<br>• **Fallback:** Pokud správce přes den zapomněl kliknout na odeslání výzvy k novým usnesením, cron je pošle souhrnně sám. |

---

## 🔑 1. Konfigurace bezpečnostního tokenu

V souboru `config/local.neon` nastavte svůj tajný bezpečnostní token a produkční URL adresu:

```neon
parameters:
	baseUrl: 'https://hlasovani.vasedomena.cz'
	cron:
		token: 'moje-tajne-silne-heslo-pro-cron-12345'
```

---

## 🌐 2. Webový endpoint pro volání Cronu

Endpoint pro spouštění úloh přes HTTP:
```text
https://hlasovani.vasedomena.cz/cron/run?token=moje-tajne-silne-heslo-pro-cron-12345
```

---

## 🛠 3. Možnosti nastavení plánovače

### A. 🌟 Bezplatná služba Cron-Job.org (Doporučeno pro webhostingy)

1. Zaregistrujte se zdarma na [**cron-job.org**](https://cron-job.org).
2. Vytvořte nový cron job:
   * **Title:** `Hlasovací portál - Plánovač`
   * **URL:** `https://hlasovani.vasedomena.cz/cron/run?token=moje-tajne-silne-heslo-pro-cron-12345`
   * **Request Method:** `GET`
   * **Schedule (Interval):** Každých **15 minut** (nebo 1× za hodinu).
3. Uložte job.

---

### B. 🐧 Linux Server / VPS (Nativní crontab)

Při správě vlastního serveru spusťte `crontab -e` a vložte:

```bash
# Spouštění PHP skriptu přímo na serveru každých 15 minut
*/15 * * * * php /var/www/html/bin/cron.php > /dev/null 2>&1
```

Nebo volání přes `curl`:
```bash
*/15 * * * * curl -s "https://hlasovani.vasedomena.cz/cron/run?token=moje-tajne-silne-heslo-pro-cron-12345" > /dev/null 2>&1
```

---

### C. 🐙 GitHub Actions Workflow (Alternativa)

Vytvořte soubor `.github/workflows/cron.yml`:

```yaml
name: Cron Trigger

on:
  schedule:
    - cron: '*/15 * * * *' # Každých 15 minut

jobs:
  ping:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger Cron Endpoint
        run: curl -s "https://${{ secrets.DOMAIN }}/cron/run?token=${{ secrets.CRON_TOKEN }}"
```
