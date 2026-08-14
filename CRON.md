# Nastavení a spouštění Cronu pro Hlasovací Portál

Aplikace Hlasovací Portál využívá Cron pro automatické úlohy:
1. **Odesílání notifikačních e-mailů na pozadí** (po publikaci či stornování hlasování).
2. **Vyhodnocování ukončených hlasování** (po vypršení termínu odesílá členům rady e-mail s celkovými výsledky).

Endpoint pro spouštění úloh:
```
https://[vas-domain]/cron/run?token=TVUJ_BEZPECNOSTNI_TOKEN
```
*(Bezpečnostní token se nastavuje v souboru `config/local.neon` pod klíčem `parameters.cronToken`)*.

---

## Možnosti spouštění Cronu (pokud webhosting nemá CLI Cron)

Pokud váš webhosting nemá k dispozici nativní plánovač úloh (CLI cron), můžete využít jednu z následujících metod pro volání HTTP endpointu každých 15 minut.

---

### 1. 🌟 Bezplatná služba Cron-Job.org (Nejjednodušší)

1. Zaregistrujte se zdarma na [**cron-job.org**](https://cron-job.org).
2. Vytvořte nový cron job.
3. Zadáním URL adresy: `https://[vas-domain]/cron/run?token=TVUJ_BEZPECNOSTNI_TOKEN`.
4. Nastavte interval spouštění (např. *každých 15 minut*).
5. Uložte job.

**Výhody:** Bezplatné, spolehlivé, obsahuje přehledné logy volání a e-mailová upozornění při výpadku webu.

---

### 2. 🚆 Mini Docker na Railway

Pokud využíváte platformu Railway, můžete vytvořit lehký Docker kontejner, který volá cron ve smyčce.

**Soubor `Dockerfile`:**
```dockerfile
FROM alpine:latest
RUN apk add --no-cache curl
CMD while true; do curl -s "https://[vas-domain]/cron/run?token=TVUJ_BEZPECNOSTNI_TOKEN"; sleep 900; done
```

---

### 3. 🐙 GitHub Actions (Workflow)

Pokud máte repozitář na GitHubu, můžete spouštět cron automaticky pomocí GitHub Actions workflow.

**Soubor `.github/workflows/cron.yml`:**
```yaml
name: Cron Trigger

on:
  schedule:
    - cron: '*/15 * * * *' # Každých 15 minut

jobs:
  ping:
    runs-on: ubuntu-latest
    steps:
      - name: Volání Cron endpointu
        run: curl -s "https://${{ secrets.DOMAIN }}/cron/run?token=${{ secrets.CRON_TOKEN }}"
```

---

### 4. ⚡ Pasivní "Lazy Cron" v PHP (Záložní řešení)

V Nette lze zaregistrovat `register_shutdown_function`, která při návštěvě uživatele zkontroluje čas od posledního spuštění a při odstupu > 15 minut spustí `CronManager::run()` na pozadí.
