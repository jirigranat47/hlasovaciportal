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
