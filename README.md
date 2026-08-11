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

V souboru `config/local.neon` nastavte vaše **AppID** získáne z [ws.skautis.cz/zadost](https://ws.skautis.cz/zadost):

```neon
parameters:
	skautis:
		appId: 'VAŠE-PRIDELENE-APP-ID'
		isTest: true # Pro vývoj nechte true (směřuje na test-is.skaut.cz)
```

## 🛠 Lokální simulace přihlášení (vývoj a testování)

Pro usnadnění lokálního vývoje bez nutnosti přihlašování přes reálný SkautIS můžete simulovat přihlášení různých rolí pomocí následujících URL adres:

* **Zakladatel / Administrátor jednotky** (zakládá hlasování, není v radě):
  [http://localhost:8000/sign/dev-login?unitId=123&personId=9999&personName=Admin+Zakladatel&roleKey=administrator&roleName=Administrátor](http://localhost:8000/sign/dev-login?unitId=123&personId=9999&personName=Admin+Zakladatel&roleKey=administrator&roleName=Administrátor)
* **Člen rady č. 1 (Jan Novák)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1001&personName=Jan+Novak&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1001&personName=Jan+Novak&roleKey=clened&roleName=Člen)
* **Člen rady č. 2 (Petr Svoboda)**:
  [http://localhost:8000/sign/dev-login?unitId=123&personId=1002&personName=Petr+Svoboda&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=1002&personName=Petr+Svoboda&roleKey=clened&roleName=Člen)
* **Host / Běžný člen** (není v radě, nezaložil hlasování):
  [http://localhost:8000/sign/dev-login?unitId=123&personId=5555&personName=Host+Skaut&roleKey=clened&roleName=Člen](http://localhost:8000/sign/dev-login?unitId=123&personId=5555&personName=Host+Skaut&roleKey=clened&roleName=Člen)
