# MS Hosting Check

A temporary WordPress plugin that checks whether the IONOS plan
("Hosting für WordPress Start") can run the Ausleihe app as a plugin
(design doc §6, Option A). **Remove it when you are done.**

What it checks (WordPress admin → *Werkzeuge / Tools → MS Hosting Check*):

| Check | Why it matters |
|---|---|
| Custom plugin runs at all | Option A depends on it |
| PHP version and extensions (curl, simplexml, gd, …) | BGG sync, QR codes |
| Own DB table (create/insert/drop), custom role, writable uploads | Data model, member roles, QR sheets |
| Outbound HTTPS to boardgamegeek.com | BGG sync |
| Loopback requests: REST route, `/c/<code>` pretty URL, `wp-cron.php` | App API, QR URL shape, external cron pinger |
| WP-Cron test job every 5 min (records when it ran) | Reminder emails, weekly sync |
| `wp_mail` test (form on the page) | Magic links, reminders |

## 1. Upload via SFTP/SSH

Server (IONOS "Hosting für WordPress Start"):

| | |
|---|---|
| Host | `access-5020149690.webspace-host.com` |
| User | `su488679` |
| Protocol | SSH / SFTP (password from the IONOS control panel, never stored in this repo) |

Find the WordPress root on the server (the folder that contains `wp-config.php`):

```sh
ssh su488679@access-5020149690.webspace-host.com
find ~ -maxdepth 4 -name wp-config.php 2>/dev/null
```

From your machine, in this repo, copy the plugin folder into `wp-content/plugins/`:

```sh
scp -r tools/ms-hosting-check su488679@access-5020149690.webspace-host.com:<wp-root>/wp-content/plugins/
```

(or with `sftp su488679@access-5020149690.webspace-host.com`, then
`put -r tools/ms-hosting-check <wp-root>/wp-content/plugins/`)

## 2. Activate

- WordPress admin → *Plugins*: activate **MS Hosting Check**.
  **Whether it shows up here at all is already the first result.**
- If it isn't listed, or activating fails, try WP-CLI over SSH (if available):
  ```sh
  cd <wp-root> && wp plugin activate ms-hosting-check
  ```

## 3. Run the checks

1. Open *Werkzeuge / Tools → MS Hosting Check*.
2. Send the test mail. Check that it arrives, and whether it lands in spam.
3. **Cron:** WP-Cron fires on page visits, including your own. To see how it
   behaves *without* visitors, leave the site alone for a few hours, then
   reload. The "gap between runs" shows how irregular it is.
4. Copy the plain-text summary at the bottom of the page and paste it back to
   Claude.

## 4. Extra checks over SSH

These can't be done from inside WordPress:

```sh
php -v                  # CLI PHP version (may differ from the web PHP)
wp --info               # is WP-CLI installed?
crontab -l              # are user crontabs allowed? ("no crontab for …" is fine, an error is not)
```

Test a real cron job (remove it again afterwards):

```sh
(crontab -l 2>/dev/null; echo "*/5 * * * * date >> \$HOME/mshc-cron.log") | crontab -
# wait 10–15 minutes, then:
cat ~/mshc-cron.log     # timestamps every 5 minutes = real cron works
# clean up: removes only the test line
crontab -l | grep -v mshc-cron.log | crontab - && rm ~/mshc-cron.log
```

From any browser, opening `https://machbarschaft-suedstadt.de/wp-cron.php` should show a blank page
rather than an error. If it does, an external pinger will work too.

## 5. Remove

1. *Plugins* → **deactivate** MS Hosting Check. This removes its cron job,
   options and test table.
2. Delete the folder:
   ```sh
   rm -r <wp-root>/wp-content/plugins/ms-hosting-check
   ```
