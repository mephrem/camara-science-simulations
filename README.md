# Camara Science Simulations

An offline library of PhET interactive science and math simulations for school servers.
Students browse and filter sims by subject and grade level. An admin page downloads new and updated
sims from PhET whenever the server has internet.

Plain PHP: no database, no Composer, no build step. It works on any Apache + PHP 7.4+ server
(Ubuntu, or Laragon on Windows).

## Install on Ubuntu

```bash
sudo apt install apache2 php libapache2-mod-php php-curl php-mbstring git
cd /var/www/html
sudo git clone <repo-url> phet
sudo chown -R www-data:www-data phet/sims phet/data
sudo cp phet/config.local.example.php phet/config.local.php
sudo nano phet/config.local.php          # set admin_password
sudo systemctl restart apache2
```

Then open `http://<server-ip>/phet/admin.php`, log in, and click **Update now** to download the sims
(about 350 MB for English; needs internet once).

**Skipping the download:** copy an existing `sims/` folder (from another server or a USB drive) into
`phet/sims/`, run `sudo chown -R www-data:www-data phet/sims`, then click **Update now**. Files that are
already there are kept, and only their subject, grade and picture are filled in.

## Install on Windows (Laragon)

Clone or copy the folder into `C:\laragon\www\phet`, create `config.local.php` as above, then open
`http://localhost/phet/admin.php`.
If the update fails with *"SSL certificate problem"*, set `curl.cainfo = "C:\laragon\etc\ssl\cacert.pem"`
in Laragon's php.ini and restart Laragon.

## Updating the sims

- **Browser:** admin.php, then **Check for updates** / **Update now**.
- **Terminal:** `sudo -u www-data php /var/www/html/phet/update-cli.php` (add `--check` to only list changes).
- **Nightly, automatically** (runs only if the server is online then):

  ```bash
  echo '30 2 * * * www-data /usr/bin/php /var/www/html/phet/update-cli.php --quiet' | sudo tee /etc/cron.d/phet-update
  ```

## Updating the app itself

```bash
cd /var/www/html/phet && sudo git pull
```

Downloaded sims, the catalog and `config.local.php` are not in git, so a pull never touches them.

## Settings

`config.php` holds the defaults: site title, languages, thumbnails, "New" badge days.
Put per-server changes in `config.local.php`, which overrides `config.php` and is ignored by git.

Languages: `'locales' => ['en', 'am']`, then click **Update now**. A language menu appears on the page.
Sims that haven't been translated yet open in English.

## Recommended Apache hardening

`/etc/apache2/conf-available/phet.conf`, then run `sudo a2enmod expires && sudo a2enconf phet && sudo systemctl reload apache2`:

```apache
<Files "admin.php">
    Require local
</Files>
<Directory /var/www/html/phet/data>
    Require all denied
</Directory>
<Directory /var/www/html/phet/sims>
    ExpiresActive On
    ExpiresDefault "access plus 7 days"
</Directory>
```

`Require local` limits the admin page to the server itself (`http://localhost/phet/admin.php`).

## Project layout

| Path | Purpose |
|---|---|
| `index.php` | Landing page: search, subject and grade filters |
| `admin.php` | Password-protected update page |
| `update-cli.php` | Terminal / cron updater |
| `lib/common.php` | Settings, subject and grade taxonomy, catalog helpers |
| `lib/updater.php` | Talks to PhET's metadata service, downloads sims and pictures |
| `assets/` | CSS, JS, Camara logo |
| `sims/` | Downloaded sims (`<sim>_<lang>.html`) and `thumbs/` (not in git) |
| `data/` | `catalog.json` and the update log (not in git) |

Subject and grade IDs come from PhET's metadata service (see `phetsims/rosetta`, `SimMetadataTypes.ts`).

## Credits

Simulations by PhET Interactive Simulations, University of Colorado Boulder (https://phet.colorado.edu),
used under PhET's licensing terms. Check https://phet.colorado.edu/en/licensing before redistributing.
