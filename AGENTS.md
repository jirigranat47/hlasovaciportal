# Pravidla a prostředí projektu

- **Prostředí:** Tento projekt běží výhradně v **Dockeru** (kontejner `hlasovaciportal_web` / služba `web`).
- **PHP na hostiteli:** Na lokálním PC (hostiteli) **není nainstalováno PHP**.
- **Spouštění testů a příkazů:** Veškeré PHP skripty, migrace, Composer a testy (např. Nette Tester) se musí spouštět uvnitř Docker kontejneru:
  - Např.: `docker compose exec web vendor/bin/tester tests`
