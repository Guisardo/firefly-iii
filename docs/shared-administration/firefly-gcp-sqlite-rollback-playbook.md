# firefly-gcp SQLite backup and rollback playbook

Use this playbook before promoting a shared-administration image to `firefly-gcp`
production and again when rolling back. The production VM stores SQLite on the
persistent data disk at `/mnt/firefly-data/database.db` and manages the
container through `firefly.service`. The matching `firefly-gcp` deployment docs
must be updated in the coordinated deploy repository when this playbook changes.

## Required release record

Record these values before the cutover starts:

| Item | Value |
| --- | --- |
| Production VM | `firefly-vm` in `us-central1-a` |
| GCP project | `promising-rock-434012-u6` |
| Database path | `/mnt/firefly-data/database.db` |
| Previous pinned image ref | `guisardo/firefly-iii@sha256:<previous-digest>` |
| New pinned image ref | `guisardo/firefly-iii@sha256:<new-digest>` or `guisardo/firefly-iii:shared-admin-<git-sha>` |
| Backup path | `/mnt/firefly-data/backups/database-<timestamp>.db` |
| Backup sha256 | `<sha256sum>` |

The rollback target must be the previous pinned image ref, not a moving tag.
Prefer the previous full digest. Keep the previous immutable tag as secondary
context, but restart production from the exact previous ref recorded above.

## Image parity

Production and local import must use the same immutable image:

- `firefly-gcp` production must not run `fireflyiii/core:latest`,
  `guisardo/firefly-iii:shared-admin-latest`, or any other moving tag.
- `scripts/local-import.sh` must use the same `guisardo/firefly-iii@sha256:<digest>`
  or the matching immutable `shared-admin-<git-sha>` tag used by production.
- Before cutover, run the local import smoke against that image and record the
  local image reference in the release record.

## Pre-cutover backup

The VM must have the SQLite CLI available before the maintenance window. If it
is missing, install it through the VM setup path and verify it with:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="command -v sqlite3 && sqlite3 --version"
```

Stop writes before taking a SQLite backup:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="sudo systemctl stop firefly.service"
```

Create the backup on the persistent disk and verify it while the service is
stopped:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    ts=\$(date -u +%Y%m%dT%H%M%SZ)
    sudo install -d -o root -g root -m 0750 /mnt/firefly-data/backups
    sudo sqlite3 /mnt/firefly-data/database.db \".backup '/mnt/firefly-data/backups/database-\${ts}.db'\"
    sudo sqlite3 /mnt/firefly-data/backups/database-\${ts}.db 'PRAGMA integrity_check;'
    sudo sha256sum /mnt/firefly-data/backups/database-\${ts}.db
    sudo stat -c '%U:%G %a %n' /mnt/firefly-data /mnt/firefly-data/database.db /mnt/firefly-data/backups/database-\${ts}.db"
```

The `PRAGMA integrity_check;` output must be exactly `ok`. Do not continue if
the backup is missing, has an unexpected owner or mode, or fails integrity.

Keep `firefly.service` stopped after the backup is verified. Do not restart the
old image between the backup and pinned-image cutover.

## Pinned-image cutover

Write the new pinned image ref, recreate the container, and start production
only after recording the backup path and checksum:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    new_image='guisardo/firefly-iii@sha256:<new-digest>'
    echo \"\$new_image\" | sudo tee /etc/firefly/image >/dev/null
    sudo docker pull \"\$new_image\"
    sudo docker rm -f firefly >/dev/null 2>&1 || true
    sudo systemctl reset-failed firefly.service || true
    sudo systemctl start firefly.service
    sudo systemctl status --no-pager firefly.service
    sudo docker inspect firefly --format '{{.Config.Image}} {{.Image}} {{.State.Running}}'"
```

Abort before or during cutover by explicitly restarting the previous recorded
image. Write the previous pinned image ref to `/etc/firefly/image` before
starting systemd:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    previous_image='guisardo/firefly-iii@sha256:<previous-digest>'
    echo \"\$previous_image\" | sudo tee /etc/firefly/image >/dev/null
    sudo docker pull \"\$previous_image\"
    sudo docker rm -f firefly >/dev/null 2>&1 || true
    sudo systemctl reset-failed firefly.service || true
    sudo systemctl start firefly.service
    sudo systemctl status --no-pager firefly.service
    sudo docker inspect firefly --format '{{.Config.Image}} {{.Image}} {{.State.Running}}'"
```

## Roll back image only

Use this path when the database is healthy and only the container image needs to
return to the previous build.

1. Confirm the previous pinned image ref from the release record.
2. Recreate the container through systemd:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    previous_image='guisardo/firefly-iii@sha256:<previous-digest>'
    sudo systemctl stop firefly.service
    echo \"\$previous_image\" | sudo tee /etc/firefly/image >/dev/null
    sudo docker pull \"\$previous_image\"
    sudo docker rm -f firefly >/dev/null 2>&1 || true
    sudo systemctl reset-failed firefly.service || true
    sudo systemctl start firefly.service
    sudo systemctl status --no-pager firefly.service
    sudo docker inspect firefly --format '{{.Config.Image}} {{.Image}} {{.State.Running}}'
    sudo docker ps --filter name=firefly"
```

3. Verify boot, login, and API access:

```bash
curl -fsS https://dinero.violeta.com.ar/health
curl -fsS -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  https://dinero.violeta.com.ar/api/v1/about
```

Confirm external browser login succeeds before closing the rollback.

## Restore database and image

Use this path when the cutover may have changed or damaged data.

Stop the service and replace the database with the verified backup:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    backup=/mnt/firefly-data/backups/database-<timestamp>.db
    test -f \"\$backup\"
    sudo systemctl stop firefly.service
    sudo sqlite3 \"\$backup\" 'PRAGMA integrity_check;'
    sudo cp /mnt/firefly-data/database.db /mnt/firefly-data/database.db.failed-rollback-\$(date -u +%Y%m%dT%H%M%SZ)
    sudo install -o root -g root -m 0640 \"\$backup\" /mnt/firefly-data/database.db
    sudo stat -c '%U:%G %a %n' /mnt/firefly-data/database.db
    sudo sqlite3 /mnt/firefly-data/database.db 'PRAGMA integrity_check;'"
```

The restored database integrity check must return `ok`. If ownership or mode
differs from the pre-cutover record, fix it before restart.

Then roll production back to the previous pinned image ref and restart through
systemd:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    previous_image='guisardo/firefly-iii@sha256:<previous-digest>'
    echo \"\$previous_image\" | sudo tee /etc/firefly/image >/dev/null
    sudo docker pull \"\$previous_image\"
    sudo docker rm -f firefly >/dev/null 2>&1 || true
    sudo systemctl reset-failed firefly.service || true
    sudo systemctl start firefly.service
    sudo systemctl status --no-pager firefly.service
    sudo docker inspect firefly --format '{{.Config.Image}} {{.Image}} {{.State.Running}}'"
```

After boot, verify the restored database and application:

```bash
gcloud compute ssh firefly-vm \
  --zone=us-central1-a \
  --project=promising-rock-434012-u6 \
  --command="set -euo pipefail
    sudo sqlite3 /mnt/firefly-data/database.db 'PRAGMA integrity_check;'
    sudo stat -c '%U:%G %a %n' /mnt/firefly-data/database.db
    sudo docker logs firefly --tail=100"

curl -fsS https://dinero.violeta.com.ar/health
curl -fsS -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  https://dinero.violeta.com.ar/api/v1/about
```

Close the rollback only after:

- `firefly.service` is active.
- `/etc/firefly/image` contains the previous pinned image ref.
- The `firefly` container is running from the previous pinned image ref.
- `PRAGMA integrity_check;` returns `ok` against the restored database.
- Ownership and mode match the pre-cutover record.
- External `/health`, browser login, and `/api/v1/about` succeed.

## Patch plan for the external firefly-gcp checkout

The located checkout is `/Users/lucas.rancez/Documents/Code/firefly-gcp`, which
is outside this workspace's writable root. Apply these changes there:

1. Add `docs/ROLLBACK-SQLITE.md` with the same sections from this playbook:
   required release record, image parity, pre-cutover backup, image-only
   rollback, database-and-image rollback, and post-restore verification.
2. Update `docs/README-SQLITE.md`:
   - Replace the basic backup command with the stopped-service `.backup` flow.
   - Require `PRAGMA integrity_check;` on both backup and restored DB.
   - Require `stat` ownership/mode capture before and after restore.
   - Link to `docs/ROLLBACK-SQLITE.md` for production rollback.
3. Update `infra/deploy-vm.sh` so the VM installs the SQLite CLI:
   `sudo apt-get install -y -qq docker-ce docker-ce-cli containerd.io sqlite3`.
4. Update production image references in `infra/firefly-start.sh` and
   `infra/firefly-update.sh` so they read one pinned image value such as
   `FIREFLY_IMAGE="guisardo/firefly-iii@sha256:<digest>"` and never default to
   `fireflyiii/core:latest` or `shared-admin-latest`.
5. Update `scripts/local-import.sh` to use the same pinned image variable as
   production, or require `FIREFLY_IMAGE` to be supplied explicitly for the run.
6. Update `README.md` or the deployment docs to state that production and
   local-import must use the same immutable `guisardo/firefly-iii` image tag or
   digest, with production rollback pinned to the previous recorded image ref.
