#!/usr/bin/env bash
# Deploys plugin/mascha-ausleihe to the live site over SSH (design §11.2, §11.9).
#
#   tools/deploy.sh
#
# Asks once for the SSH password (from the IONOS control panel). Uploads the
# plugin as a tar stream (scp cannot see /home/www/public on this host),
# swaps the folder in one step, activates the plugin and refreshes the URL
# rules. Running it again updates the plugin; the data stays.
set -euo pipefail

HOST="su488679@access-5020149690.webspace-host.com"
WP_ROOT="/home/www/public"
PLUGIN="mascha-ausleihe"

cd "$(dirname "$0")/../plugin"
if [ ! -f "$PLUGIN/$PLUGIN.php" ]; then
    echo "Plugin folder not found: plugin/$PLUGIN" >&2
    exit 1
fi

trial=$(grep -Eo "'trial_mode' *=> *(true|false)" "$PLUGIN/includes/class-settings.php" | grep -Eo 'true|false')
echo "Deploying $PLUGIN (trial_mode = $trial) to $HOST"

# COPYFILE_DISABLE keeps macOS metadata files (._*) out of the archive.
COPYFILE_DISABLE=1 tar czf - --exclude '.DS_Store' "$PLUGIN" | ssh "$HOST" "
    set -e
    cd '$WP_ROOT/wp-content/plugins'
    rm -rf '$PLUGIN.new' '$PLUGIN.old'
    mkdir '$PLUGIN.new'
    tar xzf - -C '$PLUGIN.new'
    if [ -d '$PLUGIN' ]; then mv '$PLUGIN' '$PLUGIN.old'; fi
    mv '$PLUGIN.new/$PLUGIN' '$PLUGIN'
    rm -rf '$PLUGIN.new' '$PLUGIN.old'
    cd '$WP_ROOT'
    wp plugin is-active '$PLUGIN' || wp plugin activate '$PLUGIN'
    wp rewrite flush
    echo \"DB version: \$(wp option get maus_db_version 2>/dev/null || echo 'not installed yet')\"
"
echo "Done. Check https://machbarschaft-suedstadt.de/wp-admin/admin.php?page=maus"
