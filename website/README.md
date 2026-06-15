# Saathi Website (PHP + MySQL)

Deployed on **Railway** from this folder.

## Railway settings
- **Root Directory:** `website`
- Builder: Dockerfile (auto-detected)
- Add a **MySQL** database to the project and reference its variables on this
  service (Railway provides `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`,
  `MYSQLPASSWORD`, `MYSQLDATABASE`, and `MYSQL_URL`). `config.php` reads them
  automatically (it also accepts `DATABASE_URL` or generic `DB_*`).

## Local development (sandbox)
Falls back to local MariaDB: `127.0.0.1:3306`, db `saathi_site`, user `saathi`.

Every push to GitHub redeploys automatically on Railway.
