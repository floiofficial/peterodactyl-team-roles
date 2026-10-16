#!/usr/bin/env bash
# Team management (team-roles) for Pterodactyl 2.0 - menu: install / uninstall / update
# by floiofficial
#
# One command over SSH (as root):
#   bash <(curl -fsSL https://raw.githubusercontent.com/floiofficial/peterodactyl-team-roles/main/setup.sh)
#
# The script downloads the latest GitHub release, verifies the SHA-256 checksum and stores it in
# /opt/floiofficial-teamverwaltung. install.sh / uninstall.sh / update.sh then run from there.
# Uninstalling also works without internet once the files are there.
set -Eeuo pipefail

REPO="${TEAMROLES_REPO:-floiofficial/peterodactyl-team-roles}"
API_BASE="${TEAMROLES_API_BASE:-https://api.github.com}"
HOME_DIR="${TEAMROLES_HOME:-/opt/floiofficial-teamverwaltung}"
PANEL_DIR="${PANEL_DIR:-/var/www/pterodactyl}"

WORK_TO_CLEAN=""
trap '[ -n "$WORK_TO_CLEAN" ] && rm -rf "$WORK_TO_CLEAN"; true' EXIT

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Always read input from the terminal (also works with "curl ... | bash")
ask() {   # $1 = question, $2 = variable name
    local __ans=""
    if [ -r /dev/tty ] && [ -z "${TEAMROLES_STDIN:-}" ]; then read -r -p "$1" __ans < /dev/tty || true
    else read -r -p "$1" __ans || true; fi
    printf -v "$2" '%s' "$__ans"
}

[ "$(id -u)" -eq 0 ] || [ -n "${TEAMROLES_ALLOW_NONROOT:-}" ] || die "Please run as root (sudo -i, then the command again)."

fetch_release() {   # downloads + verifies the latest release into $HOME_DIR
    for c in curl unzip sha256sum php; do command -v "$c" >/dev/null || die "'$c' is missing. Install it with: apt install -y $c"; done
    [[ "$REPO" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "Repo '$REPO' is invalid."
    local work; work="$(mktemp -d)"
    WORK_TO_CLEAN="$work"
    local auth=()
    if [ -n "${GITHUB_TOKEN:-}" ]; then
        ( umask 077; printf 'Authorization: Bearer %s\n' "$GITHUB_TOKEN" > "$work/auth" )
        auth=(-H "@$work/auth")
    fi
    get() { curl -fsSL --max-time 120 -H "Accept: $3" "${auth[@]}" "$1" -o "$2"; }

    say "Looking for the latest release ($REPO)"
    get "$API_BASE/repos/$REPO/releases/latest" "$work/rel.json" "application/vnd.github+json" \
        || die "No release found. Do the repo $REPO and a release (tag v1.x.x) exist? Private repo: set GITHUB_TOKEN."
    mapfile -t REL < <(php -r '
        $r = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($r) || empty($r["tag_name"])) { exit(1); }
        $zip = ""; $sha = "";
        foreach (($r["assets"] ?? []) as $a) {
            if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip$/", (string) $a["name"])) { $zip = (string) $a["url"]; }
            if (preg_match("/^floiofficial-teamverwaltung-[0-9][0-9A-Za-z.+-]*\.zip\.sha256$/", (string) $a["name"])) { $sha = (string) $a["url"]; }
        }
        echo $r["tag_name"], "\n", $zip, "\n", $sha, "\n";' "$work/rel.json") || die "Release response not readable."
    local tag="${REL[0]:-}" zip_url="${REL[1]:-}" sha_url="${REL[2]:-}"
    [[ "$tag" =~ ^v[0-9]+(\.[0-9]+){2}([-+][0-9A-Za-z.-]+)?$ ]] || die "Unexpected release name '$tag'."
    [ -n "$zip_url" ] && [ -n "$sha_url" ] || die "Release $tag has no zip or no checksum - nothing installed."

    say "Downloading $tag"
    get "$zip_url" "$work/pkg.zip" "application/octet-stream" || die "Download failed."
    get "$sha_url" "$work/pkg.sha256" "application/octet-stream" || die "Checksum could not be downloaded."
    local expected actual
    expected="$(awk 'NR==1{print tolower($1)}' "$work/pkg.sha256")"
    actual="$(sha256sum "$work/pkg.zip" | awk '{print $1}')"
    [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || die "Checksum file invalid."
    [ "$expected" = "$actual" ] || die "CHECKSUM MISMATCH - aborting, nothing installed."
    say "Checksum OK"
    if unzip -Z1 "$work/pkg.zip" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then die "Zip contains unsafe paths - aborting."; fi

    mkdir "$work/x"; unzip -q "$work/pkg.zip" -d "$work/x"
    local pkg="$work/x/floiofficial-teamverwaltung"
    [ -f "$pkg/install.sh" ] && [ -f "$pkg/uninstall.sh" ] && [ -f "$pkg/extension/team-roles/extension.json" ] || die "Package incomplete."

    mkdir -p "$HOME_DIR/backups"          # backups/ is kept across updates
    for item in extension tools tests .github; do rm -rf "${HOME_DIR:?}/$item"; [ -e "$pkg/$item" ] && cp -a "$pkg/$item" "$HOME_DIR/$item"; true; done
    for f in install.sh uninstall.sh update.sh diagnose.sh setup.sh README.md README.de.md; do [ -f "$pkg/$f" ] && cp -f "$pkg/$f" "$HOME_DIR/$f"; true; done
    chmod +x "$HOME_DIR"/*.sh
    printf '%s\n' "$REPO" > "$HOME_DIR/.update-repo"
    say "Files are in $HOME_DIR"
}

ask_panel() {
    local p=""
    ask "Path to the panel [$PANEL_DIR]: " p
    [ -n "$p" ] && PANEL_DIR="$p"
    [ -f "$PANEL_DIR/artisan" ] || die "There is no Pterodactyl panel at $PANEL_DIR (artisan missing)."
}

do_install() {
    ask_panel
    fetch_release
    say "Starting the installation"
    bash "$HOME_DIR/install.sh" "$PANEL_DIR"
}

do_uninstall() {
    ask_panel
    if [ ! -f "$HOME_DIR/uninstall.sh" ]; then
        warn "No local files in $HOME_DIR - downloading the package in order to uninstall."
        fetch_release
    fi
    say "Starting the uninstallation (first demotes all non-Owners, then removes)"
    bash "$HOME_DIR/uninstall.sh" "$PANEL_DIR"
}

do_update() {
    ask_panel
    if [ ! -f "$HOME_DIR/update.sh" ]; then fetch_release; fi
    bash "$HOME_DIR/update.sh" "$PANEL_DIR" --repo "$REPO"
}

do_diagnose() {
    ask_panel
    if [ ! -f "$HOME_DIR/diagnose.sh" ]; then fetch_release; fi
    bash "$HOME_DIR/diagnose.sh" "$PANEL_DIR"
}

choice="${1:-}"
case "$choice" in install|1) choice=1 ;; uninstall|2) choice=2 ;; update|3) choice=3 ;; diagnose|4) choice=4 ;; "") ;; *) die "Unknown option '$choice' (install | uninstall | update | diagnose)." ;; esac

if [ -z "$choice" ]; then
    echo
    echo "  Team management for Pterodactyl 2.0  -  by floiofficial"
    echo "  --------------------------------------------------------"
    echo "   1) Install"
    echo "   2) Uninstall"
    echo "   3) Update"
    echo "   4) Diagnose (why is it not visible?)"
    echo "   0) Exit"
    echo
    ask "Choice [0-4]: " choice
fi

case "$choice" in
    1) do_install ;;
    2) do_uninstall ;;
    3) do_update ;;
    4) do_diagnose ;;
    0|"") echo "Exited. Nothing was changed." ;;
    *) die "Invalid choice '$choice'." ;;
esac
