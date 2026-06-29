# D.I.S. Bare-Metal Deployment

D.I.S. (Drone Inzet Systeem) is deployed as a bare-metal Ubuntu application under `/opt/dis`.
This repository contains only the files required to install, run, update and uninstall the platform.

## Requirements

- Ubuntu 26.04 LTS
- Root or sudo access
- HTTP deployment, no SSL certificate required
- DNS record pointing to the server
- Fresh clone path: `/opt/dis`

## First Install

Clone the repository directly into `/opt/dis`:

```bash
sudo git clone https://github.com/wrdmarco/dis.git /opt/dis
cd /opt/dis
sudo bash setup.sh --domain dis.example.nl
```

Replace `dis.example.nl` with the hostname or IP address you want to use.

The installer will:

- install required Ubuntu packages
- create the `dis` system user and group
- create `/opt/dis/.env`
- generate secure local secrets
- provision PostgreSQL
- install PHP, Nginx and systemd configuration
- build frontend assets
- run Laravel migrations and seeders
- start queue, scheduler and websocket services
- install the global `update` command

## Web Setup Wizard

After the CLI install finishes, open:

```text
http://dis.example.nl/setup
```

The setup wizard configures:

- tenant name
- public server URL
- first system administrator
- SMTP mail settings
- Firebase app configuration

The setup wizard is only available before the first user exists. After completion, further configuration is done in the admin panel.

## Updates

After installation you can update the server and application with:

```bash
sudo update
```

This will:

- run `apt-get update`
- run `apt-get upgrade -y`
- run `apt-get autoremove -y`
- pull the latest Git source
- rebuild backend and frontend
- run pending migrations only
- refresh Nginx, PHP and systemd configuration
- restart services
- run a local health check

Useful options:

```bash
sudo update --skip-system
sudo update --skip-source
sudo update --skip-app
sudo update --skip-healthcheck
```

The implementation lives in:

```text
/opt/dis/scripts/update.sh
```

The root `/opt/dis/update.sh` file is only a wrapper.

Database seeders are intentionally not run during updates, so admin-managed teams, roles and settings are not overwritten. For an intentional reseed during a manual deploy, run:

```bash
sudo RUN_SEEDERS=1 bash /opt/dis/scripts/deploy.sh
```

## Public APK Page

The Android APK can be offered publicly at:

```text
http://dis.example.nl/download
```

Upload APK releases from the admin update portal. The public page will show the latest uploaded APK and its SHA-256 hash.

## Backup

Create a backup:

```bash
sudo bash /opt/dis/scripts/backup.sh
```

Backups are stored under:

```text
/opt/dis/backup
```

Verify a backup:

```bash
sudo bash /opt/dis/scripts/verify-backup.sh /opt/dis/backup/<timestamp>
```

Restore a backup:

```bash
sudo bash /opt/dis/scripts/restore.sh /opt/dis/backup/<timestamp>
```

## Maintenance Mode

Enable maintenance mode:

```bash
sudo bash /opt/dis/scripts/maintenance.sh enable
```

Disable maintenance mode:

```bash
sudo bash /opt/dis/scripts/maintenance.sh disable
```

## Uninstall

Default uninstall removes service/config integration but keeps data:

```bash
cd /opt/dis
sudo bash uninstall.sh
```

Remove the local database as well:

```bash
sudo bash uninstall.sh --remove-database
```

Remove application files too:

```bash
sudo bash uninstall.sh --remove-app-dir
```

Full removal, excluding Ubuntu package purge:

```bash
sudo bash uninstall.sh --all
```

Package purge is intentionally separate and should only be used on a dedicated server:

```bash
sudo bash uninstall.sh --purge-packages
```

## Git Layout

Only the `Deploy` folder is intended to be a Git repository source.
Development prompt files, Android source files, Docker files, local build output, secrets and generated artifacts are not part of this deployment repository.
