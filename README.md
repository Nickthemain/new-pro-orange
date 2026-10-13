# SwipeHire — sign up, sign in and profile setup

## Install (Ubuntu + Apache + MySQL/MariaDB)

```bash
sudo apt install apache2 mysql-server php php-mysql libapache2-mod-php   # if not installed yet
# put this folder at /var/www/your_domain/php_auth, then:
cd /var/www/your_domain/php_auth
sudo bash install.sh --dev      # on your own machine (shows error details)
sudo bash install.sh            # on a live server (hides error details)
```

Optional Google Maps key (Maps Embed API, free — without it a keyless Google embed is used):

```bash
sudo bash install.sh --dev --maps-key=AIza...your-key...
```

Optional demo data (sample employers, jobs and candidates, password `demo12345`):

```bash
sudo php seed_demo.php            # add
sudo php seed_demo.php --remove   # delete all demo accounts
```

Open `http://localhost/php_auth/`. With `--dev`, `http://localhost/php_auth/check.php` shows a health check.

**Copied new files over the folder? Run `sudo bash install.sh` again.** It is safe to repeat:
it keeps your data and your database password.

## What install.sh does
1. Creates the database and tables (never deletes anything).
2. Creates the MySQL user `jobapp` (no root) and saves its password in `config.local.php`.
3. Gives Apache write access to only `uploads/` and `/var/www/swipehire_private/` (CVs).
4. Adds Apache rules: no folder listings, `install.sh` / `.sql` / `config.local.php` can't be downloaded.

## Files
| File | Job |
|---|---|
| `register.php`, `login.php`, `logout.php` | Accounts |
| `choose_role.php` | Employer or job seeker |
| `setup.php` | Profile setup wizard (spec step 3) |
| `home.php` | Home: swipe cards (jobs for seekers, candidates for employers) |
| `matches.php` | Matches: search by name/job title (ranked by location + industry), filters, Google Map, video links |
| `assets/matches.js` | Live suggestions, filters and map for the Matches page |
| `swipe.php`, `feed.php` | Save a swipe / load more cards (JSON, used by `assets/swipe.js`) |
| `includes/app.php` | App shell, feed ranking, match helpers |
| `seed_demo.php` | Demo data (command line only) |
| `cv.php` | Shows a seeker their own CV (CVs are outside the web folder) |
| `check.php` | Setup health check (debug only) |
| `includes/bootstrap.php` | Error handling: friendly page, details only in debug |
| `includes/auth.php` | Sessions, CSRF, remember-me, layouts |
| `includes/upload.php` | Safe uploads |
| `includes/form.php`, `includes/options.php` | Form fields and allowed values |
| `config.php` | Settings (secrets go in `config.local.php`) |
| `job_matching_app.sql` | Database schema |

## Where errors go
Debug off: users see "Something went wrong"; details are in `/var/log/apache2/error.log`
(or your site's own error log).
