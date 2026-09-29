#!/usr/bin/env bash
#
#  BatSignal — instal·lació i manteniment amb Docker, sense haver de saber Docker.
#
#  Ús:  sudo bash batsignal.sh               menú interactiu
#       sudo bash batsignal.sh <ordre>       vegeu: bash batsignal.sh help
#
set -uo pipefail

ORIG_ARGS=("$@")
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$APP_DIR/$(basename "${BASH_SOURCE[0]}")"
ENV_FILE="$APP_DIR/.env"
ENV_EXAMPLE="$APP_DIR/.env.example"
COMPOSE_FILE="$APP_DIR/docker-compose.yml"
OVERRIDE_FILE="$APP_DIR/docker-compose.override.yml"
CADDYFILE="$APP_DIR/docker/Caddyfile"
CADDYFILE_EXAMPLE="$APP_DIR/docker/Caddyfile.example"
BACKUP_DIR="$APP_DIR/backups"
LOG_DIR="$APP_DIR/logs"
LOG_FILE="$LOG_DIR/batsignal.log"
CRON_FILE="/etc/cron.d/batsignal"
DB_VOLUME="batsignal_db_data"
MIN_FREE_MB=3000
RUNNER_MAX_SILENCE=1200   # segons sense activitat del runner abans de considerar-lo encallat

AUTO=0      # mode no interactiu (cron): no pregunta res i pren l'opció segura
QUIET=0     # en mode automàtic només s'escriu quan hi ha problemes
FIXES=0
ACTION="menu"
COMPOSE=()

# ─────────────────────────────── sortida ───────────────────────────────
if [[ -t 1 ]]; then
    R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[34m'; M=$'\e[35m'; C=$'\e[36m'; W=$'\e[1m'; D=$'\e[2m'; N=$'\e[0m'
else
    R=''; G=''; Y=''; B=''; M=''; C=''; W=''; D=''; N=''
fi

have() { command -v "$1" >/dev/null 2>&1; }
ts()   { (( AUTO )) && printf '%s ' "$(date '+%F %T')"; }
log()  { mkdir -p "$LOG_DIR" 2>/dev/null; printf '%s [%s] %s\n' "$(date '+%F %T')" "$ACTION" "$*" >>"$LOG_FILE" 2>/dev/null || true; }
ok()   { (( QUIET )) || echo "$(ts)${G}✔${N} $*"; }
info() { (( QUIET )) || echo "$(ts)${C}ℹ${N} $*"; }
warn() { echo "$(ts)${Y}▲${N} $*"; log "AVÍS: $*"; }
err()  { echo "$(ts)${R}✖${N} $*" >&2; log "ERROR: $*"; }
fix()  { echo "$(ts)${M}⚒${N} $*"; log "ACCIÓ: $*"; FIXES=$((FIXES + 1)); }
step() { echo; echo "${W}${B}[$1]${N} ${W}$2${N}"; log "PAS $1: $2"; }
line() { echo "${D}────────────────────────────────────────────────────────────${N}"; }

confirm() { # confirm "pregunta" [s|n]  → 0 si sí
    local question=$1 def=${2:-n} answer hint="[s/N]"
    if (( AUTO )); then [[ $def == s ]]; return; fi
    [[ $def == s ]] && hint="[S/n]"
    read -r -p "$question $hint " answer || answer=""
    answer=${answer:-$def}
    [[ ${answer,,} == s* || ${answer,,} == y* ]]
}
prompt() { # prompt "text" [valor per defecte] → stdout
    local question=$1 def=${2:-} answer
    read -r -p "$question${def:+ [$def]}: " answer || answer=""
    echo "${answer:-$def}"
}
pause() { (( AUTO )) || { echo; read -r -p "${D}Prem Enter per continuar...${N}" _ || true; }; }

banner() {
    (( AUTO )) && return
    clear 2>/dev/null || true
    echo "${Y}"
    cat <<'BAT'
        _==/          i     i          \==_
      /XX/            |\___/|            \XX\
    /XXXX\            |XXXXX|            /XXXX\
   |XXXXXX\_         _XXXXXXX_         _/XXXXXX|
  XXXXXXXXXXXxxxxxxxXXXXXXXXXXXxxxxxxxXXXXXXXXXXX
 |XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX|
 XXXXXX/^^^^"\XXXXXXXXXXXXXXXXXXXXX/^^^^^\XXXXXX
  |XXX|       \XXX/^^\XXXXX/^^\XXX/       |XXX|
    \XX\       \X/    \XXX/    \X/       /XX/
       "\       "      \X/      "      /"
BAT
    echo "${N}${W}                  B A T S I G N A L${N}  ${D}· instal·lació i manteniment${N}"
    echo
}

# ─────────────────────────────── sistema ───────────────────────────────
require_root() {
    [[ $EUID -eq 0 ]] && return 0
    if have sudo; then
        echo "Cal permisos d'administrador; tornant a executar amb sudo..."
        exec sudo bash "$SCRIPT_PATH" "${ORIG_ARGS[@]}"
    fi
    err "Executa'l com a administrador: sudo bash batsignal.sh"
    exit 1
}

detect_os() {
    OS_ID="unknown"; OS_NAME="$(uname -s)"
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        OS_ID=$(. /etc/os-release && echo "${ID:-unknown}")
        OS_NAME=$(. /etc/os-release && echo "${PRETTY_NAME:-$OS_ID}")
    fi
}

pkg_install() {
    if have apt-get; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@"
    elif have dnf; then dnf install -y -q "$@"
    elif have yum; then yum install -y -q "$@"
    else err "No s'ha trobat cap gestor de paquets compatible (apt, dnf o yum)."; return 1
    fi
}

disk_pct() { df -P "${1:-$APP_DIR}" 2>/dev/null | awk 'NR==2 {gsub("%","",$5); print $5}'; }
free_mb()  { df -Pm "${1:-$APP_DIR}" 2>/dev/null | awk 'NR==2 {print $4}'; }
docker_root() { local d; d=$(docker info -f '{{.DockerRootDir}}' 2>/dev/null); [[ -d $d ]] && echo "$d" || echo "$APP_DIR"; }

server_ips() {
    local ips
    ips=$(hostname -I 2>/dev/null) || ips=$(ip -4 -o addr show scope global 2>/dev/null | awk '{sub("/.*","",$4); print $4}')
    echo "$ips" | tr ' ' '\n' | grep -E '^[0-9]+\.' | head -n 3
}

# Fitxers copiats des de Windows poden portar CRLF, que trenca scripts, el .env i el Dockerfile.
fix_line_endings() {
    local f fixed=0
    for f in "$ENV_FILE" "$COMPOSE_FILE" "$OVERRIDE_FILE" "$APP_DIR"/docker/* "$APP_DIR/db/schema.sql" "$APP_DIR"/db/migrations/*.sql; do
        [[ -f $f ]] || continue
        # -U: on Windows (Git Bash) grep would otherwise hide the CR it is looking for.
        if grep -qU $'\r' "$f" 2>/dev/null; then sed -i 's/\r$//' "$f"; fixed=1; fi
    done
    (( fixed )) && fix "Corregits finals de línia de Windows (CRLF) en fitxers de configuració."
    return 0
}

# ─────────────────────────────── docker ───────────────────────────────
docker_ok() { have docker && docker info >/dev/null 2>&1; }

detect_compose() {
    COMPOSE=()
    if have docker && docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
    elif have docker-compose && docker-compose version 2>/dev/null | grep -qE 'v?2\.'; then
        COMPOSE=(docker-compose)
    fi
    (( ${#COMPOSE[@]} > 0 ))
}

dc() {
    local files=(-f "$COMPOSE_FILE") profiles=()
    # El 443 només es publica quan hi ha HTTPS automàtic (docker-compose.override.yml el crea/esborra domain_config).
    [[ -f $OVERRIDE_FILE ]] && files+=(-f "$OVERRIDE_FILE")
    # El proxy (Caddy) només arrenca quan hi ha un domini configurat.
    [[ -n "$(env_get DOMAIN)" ]] && profiles=(--profile domain)
    "${COMPOSE[@]}" --project-directory "$APP_DIR" "${files[@]}" --env-file "$ENV_FILE" "${profiles[@]}" "$@"
}

start_docker() {
    docker_ok && return 0
    fix "El servei Docker està aturat; arrencant-lo..."
    if have systemctl; then systemctl start docker >/dev/null 2>&1; else service docker start >/dev/null 2>&1; fi
    local i
    for i in $(seq 1 30); do docker_ok && { ok "Docker en marxa."; return 0; }; sleep 1; done
    err "Docker no arrenca. Revisa: journalctl -u docker --no-pager | tail -50"
    return 1
}

install_docker_rhel() {
    local mgr=dnf; have dnf || mgr=yum
    $mgr install -y -q dnf-plugins-core yum-utils >/dev/null 2>&1 || true
    if have dnf; then dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
    else yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo; fi
    $mgr install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
}

ensure_docker() {
    if have docker; then
        ok "Docker instal·lat ($(docker --version 2>/dev/null | cut -d, -f1))."
    else
        warn "Docker no està instal·lat en aquest servidor."
        confirm "Vols instal·lar-lo ara? (script oficial de Docker)" s || { err "Sense Docker no es pot continuar."; return 1; }
        have curl || pkg_install curl ca-certificates >/dev/null || return 1
        fix "Instal·lant Docker (pot trigar uns minuts)..."
        if ! { curl -fsSL https://get.docker.com -o /tmp/get-docker.sh && sh /tmp/get-docker.sh; }; then
            if have dnf || have yum; then
                warn "L'script oficial no suporta aquesta distribució ($OS_NAME); provant el repositori de Docker per a RHEL/CentOS..."
                install_docker_rhel || { err "No s'ha pogut instal·lar Docker. Revisa els missatges de dalt."; return 1; }
            else
                err "No s'ha pogut instal·lar Docker. Revisa els missatges de dalt."; return 1
            fi
        fi
        rm -f /tmp/get-docker.sh
        have docker || { err "Docker continua sense estar disponible."; return 1; }
        ok "Docker instal·lat."
    fi
    start_docker || return 1
    # Que arrenqui sol quan es reiniciï el servidor (els contenidors tenen restart: unless-stopped).
    have systemctl && systemctl enable docker >/dev/null 2>&1
    if ! detect_compose; then
        warn "Falta Docker Compose v2."
        fix "Instal·lant el plugin de Docker Compose..."
        pkg_install docker-compose-plugin >/dev/null 2>&1 || true
        detect_compose || { err "No s'ha pogut instal·lar Docker Compose v2 (paquet 'docker-compose-plugin')."; return 1; }
    fi
    ok "Docker Compose $("${COMPOSE[@]}" version --short 2>/dev/null)."
}

# ─────────────────────────────── .env ───────────────────────────────
env_get() {
    [[ -f $ENV_FILE ]] || return 0
    grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | tr -d '\r'
}
env_set() {
    local key=$1 value=$2 tmp
    tmp=$(mktemp)
    if grep -qE "^$key=" "$ENV_FILE" 2>/dev/null; then
        awk -v k="$key" -v v="$value" 'index($0, k "=") == 1 { print k "=" v; next } { print }' "$ENV_FILE" >"$tmp"
    else
        cat "$ENV_FILE" >"$tmp" 2>/dev/null; echo "$key=$value" >>"$tmp"
    fi
    cat "$tmp" >"$ENV_FILE"; rm -f "$tmp"; chmod 600 "$ENV_FILE"
}
gen_secret() {
    if have openssl; then openssl rand -hex 16
    else LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32; echo
    fi
}
installed() { [[ -f $ENV_FILE ]]; }
volume_exists() { docker volume inspect "$DB_VOLUME" >/dev/null 2>&1; }

port_busy() {
    local p=$1
    if have ss; then ss -ltn "sport = :$p" 2>/dev/null | tail -n +2 | grep -q .
    elif have netstat; then netstat -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$p\$"
    else (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null
    fi
}
next_free_port() { local p=$1; while port_busy "$p"; do p=$((p + 1)); done; echo "$p"; }

ask_port() { # ask_port "etiqueta" [per defecte] → stdout
    local label=$1 def=${2:-8080} p
    def=$(next_free_port "$def")
    while :; do
        p=$(prompt "$label" "$def")
        if ! [[ $p =~ ^[0-9]+$ ]] || (( p < 1 || p > 65535 )); then warn "Port no vàlid." >&2; continue; fi
        if port_busy "$p"; then warn "El port $p ja l'està fent servir un altre programa." >&2; continue; fi
        echo "$p"; return
    done
}

ensure_env() {
    if installed; then
        sed -i 's/\r$//' "$ENV_FILE"
        if [[ -n $(env_get DB_PASSWORD) && -n $(env_get DB_ROOT_PASSWORD) ]]; then
            ok "Configuració existent (.env) reutilitzada."; return 0
        fi
        if volume_exists; then
            err "Al .env hi falten les contrasenyes de la BD però ja hi ha dades instal·lades. Recupera el .env original o fes una reinstal·lació neta."
            return 1
        fi
    else
        cp "$ENV_EXAMPLE" "$ENV_FILE" 2>/dev/null || : >"$ENV_FILE"
    fi
    env_set DB_PASSWORD "$(gen_secret)"
    env_set DB_ROOT_PASSWORD "$(gen_secret)"
    [[ $(next_free_port 8080) != 8080 ]] && warn "El port 8080 està ocupat (potser pel servidor web o l'aaPanel); se'n proposa un altre."
    env_set WEB_PORT "$(ask_port "Port on s'obrirà el panell" 8080)"
    env_set TZ "$(prompt "Zona horària" "$(env_get TZ | grep . || echo Europe/Madrid)")"
    [[ -n $(env_get BACKUP_KEEP) ]] || env_set BACKUP_KEEP 14
    [[ -n $(env_get DOMAIN_TLS) ]] || env_set DOMAIN_TLS "off"
    chmod 600 "$ENV_FILE"
    write_caddyfile
    ok "Configuració creada (.env amb contrasenyes aleatòries)."
}

# ─────────────────────────────── domini ───────────────────────────────
# Escriu docker/Caddyfile segons DOMAIN/DOMAIN_TLS. Sense domini configurat,
# deixa un Caddyfile de cortesia (el contenidor proxy no arrenca igualment).
write_caddyfile() {
    local domain tls
    domain=$(env_get DOMAIN)
    tls=$(env_get DOMAIN_TLS)
    if [[ -z $domain ]]; then
        cp "$CADDYFILE_EXAMPLE" "$CADDYFILE" 2>/dev/null && return 0
        cat >"$CADDYFILE" <<'EOF'
:80 {
	respond "BatSignal: configura un domini des del menú (opció 10 → Domini)." 200
}
EOF
        return 0
    fi
    if [[ $tls == auto ]]; then
        # Sense esquema: Caddy activa sol HTTPS automàtic (Let's Encrypt) per aquest domini.
        cat >"$CADDYFILE" <<EOF
$domain {
	reverse_proxy web:80 {
		flush_interval -1
	}
}
EOF
    else
        # Amb "http://": Caddy no intenta obtenir cap certificat, només serveix en pla.
        cat >"$CADDYFILE" <<EOF
http://$domain {
	reverse_proxy web:80 {
		flush_interval -1
	}
}
EOF
    fi
}

# El port 443 només es publica quan hi ha HTTPS automàtic; així un domini
# sense HTTPS no necessita tenir el 443 lliure.
write_override()  { printf 'services:\n  proxy:\n    ports:\n      - "${HTTPS_PORT:-443}:443"\n' >"$OVERRIDE_FILE"; }
remove_override()  { rm -f "$OVERRIDE_FILE"; }

domain_url() { # → stdout, buit si no hi ha domini configurat
    local domain tls port
    domain=$(env_get DOMAIN)
    [[ -z $domain ]] && return 1
    tls=$(env_get DOMAIN_TLS)
    if [[ $tls == auto ]]; then
        port=$(env_get HTTPS_PORT); port=${port:-443}
        [[ $port == 443 ]] && echo "https://$domain" || echo "https://$domain:$port"
    else
        port=$(env_get HTTP_PORT); port=${port:-80}
        [[ $port == 80 ]] && echo "http://$domain" || echo "http://$domain:$port"
    fi
}

# Prova l'accés pel domini sense dependre que el DNS ja resolgui des d'aquest
# servidor (--resolve el fa apuntar a localhost mantenint el nom correcte).
check_domain() {
    local domain tls port code
    domain=$(env_get DOMAIN)
    [[ -z $domain ]] && return 1
    tls=$(env_get DOMAIN_TLS)
    if [[ $tls == auto ]]; then
        port=$(env_get HTTPS_PORT); port=${port:-443}
        code=$(curl -k -s -o /dev/null -w '%{http_code}' -m 8 --resolve "$domain:$port:127.0.0.1" "https://$domain:$port/health.php" 2>/dev/null)
    else
        port=$(env_get HTTP_PORT); port=${port:-80}
        code=$(curl -s -o /dev/null -w '%{http_code}' -m 8 --resolve "$domain:$port:127.0.0.1" "http://$domain:$port/health.php" 2>/dev/null)
    fi
    [[ $code == 200 ]]
}

disable_domain() {
    if detect_compose && docker_ok; then
        fix "Aturant el proxy del domini..."
        dc stop proxy >/dev/null 2>&1
        dc rm -f proxy >/dev/null 2>&1
    fi
    env_set DOMAIN ""
    env_set DOMAIN_TLS "off"
    remove_override
    write_caddyfile
    ok "Accés per domini desactivat. El panell continua accessible per IP:port."
}

do_domain_config() {
    local current domain tls p ok_probe i
    current=$(env_get DOMAIN)
    if [[ -n $current ]]; then
        echo "  Domini actual: ${W}$(domain_url)${N} $( [[ $(env_get DOMAIN_TLS) == auto ]] && echo "(HTTPS automàtic)" || echo "(HTTP)" )"
        if confirm "Vols desactivar l'accés per domini?" n; then disable_domain; return 0; fi
    fi
    domain=$(prompt "Nom de domini (p. ex. batsignal.empresa.com; buit per no tocar res)")
    [[ -z $domain ]] && { info "Sense canvis."; return 0; }
    if ! [[ $domain =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]]; then
        err "«$domain» no sembla un nom de domini vàlid."
        return 1
    fi

    if confirm "Aquest domini té DNS públic apuntant a la IP d'aquest servidor i vols HTTPS automàtic (Let's Encrypt)?" n; then
        if port_busy 80 || port_busy 443; then
            err "Per a HTTPS automàtic calen els ports 80 i 443 lliures en aquest servidor. Allibera'ls (p. ex. atura un altre servidor web que hi hagi) o torna-ho a provar sense HTTPS."
            return 1
        fi
        tls=auto
        env_set HTTP_PORT 80
        env_set HTTPS_PORT 443
        write_override
    else
        p=$(ask_port "Port HTTP per al domini" 80)
        tls=off
        env_set HTTP_PORT "$p"
        remove_override
    fi
    env_set DOMAIN "$domain"
    env_set DOMAIN_TLS "$tls"
    write_caddyfile

    if ! detect_compose || ! docker_ok; then
        ok "Configuració desada; s'aplicarà quan Docker estigui en marxa."
        return 0
    fi
    fix "Activant l'accés per domini..."
    up_services || { err "No s'ha pogut arrencar el proxy."; return 1; }
    wait_health proxy 60 || warn "El proxy triga a estabilitzar-se; continuo comprovant l'accés."

    info "Comprovant l'accés per $(domain_url) (pot trigar una mica, sobretot amb HTTPS automàtic)..."
    ok_probe=0
    for i in 1 2 3 4 5 6; do check_domain && { ok_probe=1; break; }; sleep 5; done
    if (( ok_probe )); then
        ok "Domini actiu: ${W}$(domain_url)${N}"
    else
        warn "Encara no es pot confirmar l'accés per $(domain_url)."
        if [[ $tls == auto ]]; then
            info "Si el DNS de «$domain» encara no apunta a la IP d'aquest servidor, Let's Encrypt no podrà emetre el certificat. Comprova-ho i, quan apunti bé, l'opció 3 (reparar) ho reintentarà sol."
        else
            info "Comprova que «$domain» resolgui a la IP d'aquest servidor des dels ordinadors que hi accediran (DNS intern o fitxer hosts)."
        fi
    fi
}

# ─────────────────────────────── estat ───────────────────────────────
web_url()  { echo "http://127.0.0.1:$(env_get WEB_PORT | grep . || echo 8080)"; }
web_ok()   { curl -fsS -m "${1:-10}" -o /dev/null "$(web_url)/health.php" 2>/dev/null; }
web_time() { curl -fsS -m 10 -o /dev/null -w '%{time_total}' "$(web_url)/health.php" 2>/dev/null; }

svc_state() { # → "estat|salut", p. ex. "running|healthy", "exited|none", "absent|none"
    local id
    id=$(dc ps -a -q "$1" 2>/dev/null | head -n 1)
    [[ -z $id ]] && { echo "absent|none"; return; }
    docker inspect -f '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id" 2>/dev/null || echo "absent|none"
}
svc_uptime() { # segons des que va arrencar el contenidor
    local id started
    id=$(dc ps -q "$1" 2>/dev/null | head -n 1); [[ -z $id ]] && { echo 0; return; }
    started=$(docker inspect -f '{{.State.StartedAt}}' "$id" 2>/dev/null) || { echo 0; return; }
    echo $(( $(date +%s) - $(date -d "$started" +%s 2>/dev/null || date +%s) ))
}
wait_health() { # wait_health servei segons → 0 quan està "healthy"
    local svc=$1 max=${2:-90} t=0
    while (( t < max )); do
        [[ $(svc_state "$svc") == "running|healthy" ]] && return 0
        sleep 3; t=$((t + 3))
    done
    return 1
}
wait_web() { local max=${1:-90} t=0; while (( t < max )); do web_ok 5 && return 0; sleep 3; t=$((t + 3)); done; return 1; }

db_query() { dc exec -T -e MYSQL_PWD="$(env_get DB_ROOT_PASSWORD)" db mariadb -N -B -uroot batsignal -e "$1" 2>/dev/null; }
runner_age() { db_query "SELECT TIMESTAMPDIFF(SECOND, setting_value, NOW()) FROM settings WHERE setting_key='runner_heartbeat'" | tr -d '\r' | grep -E '^[0-9]+$'; }

human_age() { local s=$1; if (( s < 120 )); then echo "$s s"; elif (( s < 7200 )); then echo "$((s / 60)) min"; else echo "$((s / 3600)) h"; fi; }

quick_status() { # una línia per a la capçalera del menú
    if ! have docker; then echo "${Y}● Docker no instal·lat${N} — tria l'opció 1 per instal·lar-ho tot."; return; fi
    if ! docker_ok; then echo "${R}● Docker aturat${N} — tria l'opció 3 per reparar."; return; fi
    if ! installed || ! detect_compose; then echo "${Y}● BatSignal encara no està instal·lat${N} — tria l'opció 1."; return; fi
    local db web dom=""
    [[ $(svc_state db) == "running|healthy" ]] && db="${G}BD ✔${N}" || db="${R}BD ✖${N}"
    web_ok 3 && web="${G}Web ✔${N}" || web="${R}Web ✖${N}"
    # Sense comprovació de xarxa aquí (només mostra si hi ha domini configurat) perquè el menú no s'endarrereixi.
    [[ -n $(env_get DOMAIN) ]] && dom="  ${D}· $(domain_url)${N}"
    echo "● $db  $web  ${D}$(web_url | sed "s/127.0.0.1/$(server_ips | head -n 1 | grep . || echo 127.0.0.1)/")${N}$dom"
}

# ─────────────────────────────── bloqueig ───────────────────────────────
# Evita que el vigilant automàtic i una operació manual es trepitgin.
take_lock() { mkdir -p "$LOG_DIR"; exec 9>"$LOG_DIR/.batsignal.lock"; flock -n 9; }
release_lock() { exec 9>&- 2>/dev/null || true; }
lock_or_wait() {
    take_lock && return 0
    warn "Hi ha una altra operació en marxa (potser el vigilant automàtic). Esperant fins a 2 minuts..."
    flock -w 120 9 || { err "L'altra operació no acaba. Torna-ho a provar d'aquí a una estona."; return 1; }
}

# ─────────────────────────────── accions ───────────────────────────────
up_services() {
    local out p var np
    fix_line_endings
    if out=$(dc up -d --build 2>&1); then return 0; fi
    if grep -qiE 'port is already allocated|address already in use' <<<"$out"; then
        # El missatge de Docker inclou el port real en conflicte (0.0.0.0:N o [::]:N);
        # cal identificar-lo perquè ara hi ha tres ports possibles (panell, domini HTTP, domini HTTPS).
        p=$(grep -oE '(0\.0\.0\.0|\[::\]|::):[0-9]+' <<<"$out" | head -n 1 | grep -oE '[0-9]+$')
        var=""
        if [[ -n $p ]]; then
            case $p in
                "$(env_get WEB_PORT | grep . || echo 8080)") var=WEB_PORT ;;
                "$(env_get HTTP_PORT | grep . || echo 80)") var=HTTP_PORT ;;
                "$(env_get HTTPS_PORT | grep . || echo 443)") var=HTTPS_PORT ;;
            esac
        fi
        if [[ -z $var ]]; then
            err "Hi ha un port en conflicte${p:+ ($p)} però no l'he pogut identificar. Últimes línies:"
            echo "$out" | tail -n 15
            return 1
        fi
        warn "El port $p ($var) l'està fent servir un altre programa."
        if (( AUTO )); then err "Canvia el port en conflicte des del menú: Configuració."; return 1; fi
        if [[ $var == HTTPS_PORT ]]; then
            err "El port 443 cal que sigui exactament el 443 per a HTTPS automàtic. Allibera'l o desactiva el domini/HTTPS (menú → Configuració → Domini)."
            return 1
        fi
        np=$(next_free_port $((p + 1)))
        if confirm "Vols moure'l al port $np?" s; then
            env_set "$var" "$np"
            [[ $var == HTTP_PORT ]] && info "El domini caldrà escriure'l com http://domini:$np."
            dc up -d >/dev/null 2>&1 && { fix "$var ara és $np."; return 0; }
        fi
        return 1
    fi
    err "No s'han pogut arrencar els serveis. Últimes línies:"
    echo "$out" | tail -n 20
    return 1
}

create_admin() {
    local user p1 p2 out
    echo
    info "Crea l'usuari amb què entraràs al panell."
    user=$(prompt "Nom d'usuari" "admin")
    while :; do
        read -r -s -p "Contrasenya (mínim 8 caràcters): " p1; echo
        read -r -s -p "Repeteix la contrasenya: " p2; echo
        (( ${#p1} >= 8 )) || { warn "Massa curta."; continue; }
        [[ $p1 == "$p2" ]] || { warn "No coincideixen."; continue; }
        break
    done
    if out=$(printf '%s\n' "$p1" | dc exec -T web php bin/admin.php create "$user" 2>&1); then ok "$out"; else err "$out"; return 1; fi
}

do_install() {
    ACTION="install"
    banner
    lock_or_wait || return 1

    step "1/8" "Comprovant el servidor"
    detect_os
    ok "Sistema: $OS_NAME ($(uname -m))"
    [[ $(uname -m) =~ ^(x86_64|aarch64|arm64)$ ]] || warn "Arquitectura poc habitual ($(uname -m)); pot no funcionar."
    local mem; mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    (( mem >= 1000 )) && ok "Memòria: ${mem} MB" || warn "Memòria justa (${mem} MB). Es recomana 1 GB o més."
    local free; free=$(free_mb)
    (( free >= MIN_FREE_MB )) && ok "Disc lliure: ${free} MB" || { err "Només hi ha ${free} MB lliures (calen ${MIN_FREE_MB} MB)."; confirm "Continuar igualment?" n || return 1; }
    [[ -d /www/server/panel ]] && info "S'ha detectat aaPanel: BatSignal hi pot conviure; farà servir un port propi."
    have curl || { fix "Instal·lant curl..."; pkg_install curl ca-certificates >/dev/null || return 1; }
    have flock || pkg_install util-linux >/dev/null 2>&1 || true

    step "2/8" "Docker"
    ensure_docker || return 1

    step "3/8" "Configuració"
    if installed && volume_exists; then
        warn "BatSignal ja està instal·lat en aquest servidor."
        echo "  1) Reconstruir i tornar a arrencar ${G}(conserva totes les dades)${N}"
        echo "  2) Reinstal·lar des de zero ${R}(esborra webs, historial i usuaris)${N}"
        echo "  0) Cancel·lar"
        case $(prompt "Tria" "1") in
            1) ;;
            2) reinstall_clean || return 1 ;;
            *) info "Cancel·lat."; return 0 ;;
        esac
    fi
    ensure_env || return 1

    step "4/8" "Construint i arrencant BatSignal (la primera vegada triga uns minuts)"
    fix_line_endings
    if ! dc build; then err "La construcció de la imatge ha fallat. Revisa els missatges de dalt."; return 1; fi
    up_services || return 1

    step "5/8" "Esperant que tot respongui"
    if wait_health db 180 && wait_web 120; then
        ok "Base de dades i panell en marxa."
    else
        err "No arrenca del tot. Provant de reparar-ho automàticament..."
        do_repair || { err "Revisa els logs (menú → Veure logs)."; return 1; }
    fi
    dc exec -T web php bin/migrate.php >/dev/null 2>&1 || warn "No s'han pogut aplicar les migracions ara; el runner ho tornarà a provar."

    step "6/8" "Usuari administrador"
    local users; users=$(dc exec -T web php bin/admin.php count 2>/dev/null | tr -d '\r')
    if [[ ${users:-0} =~ ^[0-9]+$ ]] && (( users > 0 )); then ok "Ja hi ha $users usuari(s)."; else create_admin; fi

    step "7/8" "Vigilant automàtic"
    info "Cada 5 minuts comprova que tot funcioni i ho repara sol; cada nit fa una còpia de la BD."
    if [[ -f $CRON_FILE ]]; then ok "Ja està actiu."; elif confirm "Activar-lo?" s; then watchdog_install; fi

    step "8/8" "Domini (opcional)"
    if [[ -n $(env_get DOMAIN) ]]; then
        ok "Ja configurat: $(domain_url)"
    elif confirm "Vols que el panell també s'obri amb un nom de domini, en lloc de només IP:port?" n; then
        do_domain_config
    else
        info "Es pot afegir més endavant des de Configuració → Domini."
    fi

    local port ip; port=$(env_get WEB_PORT)
    echo; line
    echo "${G}${W}  BatSignal instal·lat i en marxa.${N}"
    for ip in $(server_ips); do echo "  Panell:  ${W}http://$ip:$port${N}"; done
    echo "  Local:   http://127.0.0.1:$port"
    [[ -n $(env_get DOMAIN) ]] && echo "  Domini:  ${W}$(domain_url)${N}"
    echo "  Per gestionar-lo: ${W}sudo bash $SCRIPT_PATH${N}"
    line
    [[ -d /www/server/panel ]] && info "Si no hi arribes des d'un altre ordinador, obre el port $port a aaPanel → Seguretat (i al tallafocs del proveïdor)."
    log "Instal·lació completada al port $port"
}

reinstall_clean() {
    warn "Això esborrarà TOTES les dades de BatSignal (webs, historial, incidents, usuaris)."
    confirm "Vols fer abans una còpia de seguretat?" s && { do_backup || true; }
    local answer; answer=$(prompt "Escriu ESBORRAR per confirmar")
    [[ $answer == "ESBORRAR" ]] || { info "Cancel·lat."; return 1; }
    fix "Esborrant contenidors i dades..."
    dc down -v >/dev/null 2>&1 || true
    docker volume rm "$DB_VOLUME" >/dev/null 2>&1 || true
    rm -f "$ENV_FILE" "$OVERRIDE_FILE"
    write_caddyfile
    ok "Instal·lació anterior eliminada."
}

do_status() {
    ACTION="status"
    banner
    echo "${W}  Estat de BatSignal${N}   ${D}$(date '+%F %T')${N}"; line
    if ! have docker; then printf '  %-17s %s\n' "Docker" "${R}✖ no instal·lat${N} → opció 1 del menú"; line; return 1; fi
    if ! docker_ok; then printf '  %-17s %s\n' "Docker" "${R}✖ aturat${N} → opció 3 (reparar)"; line; return 1; fi
    printf '  %-17s %s\n' "Docker" "${G}✔${N} $(docker version -f '{{.Server.Version}}' 2>/dev/null) en marxa"
    if ! installed || ! detect_compose; then printf '  %-17s %s\n' "BatSignal" "${Y}▲ no instal·lat${N} → opció 1"; line; return 1; fi

    local svc st name svcs=(db web runner)
    [[ -n $(env_get DOMAIN) ]] && svcs+=(proxy)
    for svc in "${svcs[@]}"; do
        case $svc in db) name="Base de dades";; web) name="Panell web";; runner) name="Runner (checks)";; proxy) name="Proxy (domini)";; esac
        st=$(svc_state "$svc")
        case $st in
            "running|healthy") printf '  %-17s %s\n' "$name" "${G}✔${N} en marxa · sa" ;;
            "running|starting") printf '  %-17s %s\n' "$name" "${Y}…${N} arrencant" ;;
            running*) printf '  %-17s %s\n' "$name" "${Y}▲${N} en marxa però ${st#*|}" ;;
            *) printf '  %-17s %s\n' "$name" "${R}✖${N} ${st%%|*}" ;;
        esac
    done

    if web_ok; then
        printf '  %-17s %s\n' "Accés web" "${G}✔${N} respon en $(web_time) s"
    else
        printf '  %-17s %s\n' "Accés web" "${R}✖ no respon${N} → opció 3 (reparar)"
    fi
    if [[ -n $(env_get DOMAIN) ]]; then
        if check_domain; then printf '  %-17s %s\n' "Accés per domini" "${G}✔${N} $(domain_url)"
        else printf '  %-17s %s\n' "Accés per domini" "${R}✖ no respon${N} $(domain_url) → opció 3 (reparar)"; fi
    fi
    local age; age=$(runner_age)
    if [[ -z $age ]]; then printf '  %-17s %s\n' "Últim check" "${Y}▲${N} encara sense activitat"
    elif (( age <= 300 )); then printf '  %-17s %s\n' "Últim check" "${G}✔${N} fa $(human_age "$age")"
    else printf '  %-17s %s\n' "Últim check" "${R}✖${N} fa $(human_age "$age") → opció 3 (reparar)"; fi

    local pct; pct=$(disk_pct "$(docker_root)"); pct=${pct:-0}
    (( pct < 85 )) && printf '  %-17s %s\n' "Disc" "${G}✔${N} ${pct}% ocupat" || printf '  %-17s %s\n' "Disc" "${Y}▲${N} ${pct}% ocupat"
    local nb last; nb=$(ls -1 "$BACKUP_DIR"/batsignal-*.sql.gz 2>/dev/null | wc -l)
    last=$(ls -1t "$BACKUP_DIR"/batsignal-*.sql.gz 2>/dev/null | head -n 1)
    printf '  %-17s %s\n' "Còpies" "$nb${last:+ · última: $(date -r "$last" '+%F %H:%M')}"
    [[ -f $CRON_FILE ]] && printf '  %-17s %s\n' "Vigilant auto." "${G}✔${N} actiu" || printf '  %-17s %s\n' "Vigilant auto." "${Y}▲ desactivat${N} → opció 11"

    local counts; counts=$(db_query "SELECT CONCAT((SELECT COUNT(*) FROM sites),' webs · ',(SELECT COUNT(*) FROM checks),' checks · ',(SELECT COUNT(*) FROM incidents WHERE status='open'),' incidents oberts')" | tr -d '\r')
    [[ -n $counts ]] && printf '  %-17s %s\n' "Dades" "$counts"
    line
    local ip port; port=$(env_get WEB_PORT)
    for ip in $(server_ips); do echo "  Panell: ${W}http://$ip:$port${N}"; done
    [[ -n $(env_get DOMAIN) ]] && echo "  Domini: ${W}$(domain_url)${N}"
}

free_disk() {
    fix "Alliberant espai: imatges i memòria cau de Docker que no es fan servir..."
    docker image prune -f >/dev/null 2>&1
    docker builder prune -f >/dev/null 2>&1
    rotate_backups
    find "$LOG_DIR" -name '*.log' -size +5M -exec sh -c 'tail -n 2000 "$1" > "$1.tmp" && mv "$1.tmp" "$1"' _ {} \; 2>/dev/null
    ok "Ara el disc està al $(disk_pct "$(docker_root)")%."
}

do_repair() {
    ACTION="repair"
    local problems=0 svc st age
    if (( AUTO )); then QUIET=1; take_lock || exit 0; else banner; lock_or_wait || return 1; step "🔍" "Diagnòstic i reparació automàtica"; fi

    # 1. Docker
    have docker || { err "Docker no està instal·lat. Fes la instal·lació (opció 1)."; return 1; }
    start_docker || return 1
    detect_compose || { err "Falta Docker Compose v2. Torna a fer la instal·lació (opció 1)."; return 1; }
    installed || { err "BatSignal no està instal·lat en aquesta carpeta (falta el fitxer .env). Fes la instal·lació (opció 1)."; return 1; }
    ok "Docker en marxa."
    fix_line_endings

    # 2. Disc
    local pct; pct=$(disk_pct "$(docker_root)"); pct=${pct:-0}
    if (( pct >= 90 )); then warn "El disc està ple al ${pct}%."; free_disk; (( $(disk_pct "$(docker_root)") >= 95 )) && problems=$((problems + 1))
    else ok "Disc: ${pct}% ocupat."; fi

    # 3. Contenidors aturats o inexistents
    local stopped=0
    for svc in db web runner; do
        st=$(svc_state "$svc")
        [[ ${st%%|*} == running ]] || { warn "El servei «$svc» no està en marxa (${st%%|*})."; stopped=1; }
    done
    if (( stopped )); then fix "Arrencant els serveis..."; up_services || problems=$((problems + 1)); fi

    # 4. Base de dades
    if wait_health db 60; then ok "Base de dades: respon."
    else
        warn "La base de dades no respon."
        fix "Reiniciant la base de dades..."
        dc restart db >/dev/null 2>&1
        if wait_health db 120; then ok "Base de dades recuperada."
        else err "La base de dades continua sense respondre. Últimes línies del log:"; dc logs --tail=25 db; problems=$((problems + 1)); fi
    fi

    # 5. Panell web
    if web_ok; then ok "Panell web: respon."
    else
        warn "El panell web no respon."
        fix "Reiniciant el servei web..."
        dc restart web >/dev/null 2>&1
        if wait_web 60; then ok "Panell web recuperat."
        else
            fix "Recreant el contenidor web des de zero (les dades no es toquen)..."
            dc up -d --build --force-recreate web >/dev/null 2>&1
            if wait_web 120; then ok "Panell web recuperat."
            else err "El panell continua sense respondre. Últimes línies del log:"; dc logs --tail=25 web; problems=$((problems + 1)); fi
        fi
    fi

    # 6. Runner (els checks s'executen?)
    age=$(runner_age)
    if [[ -n $age ]] && (( age <= RUNNER_MAX_SILENCE )); then ok "Runner: últim check fa $(human_age "$age")."
    elif [[ -z $age ]] && (( $(svc_uptime runner) < 300 )); then ok "Runner: acaba d'arrencar."
    else
        warn "Els checks no s'executen${age:+ des de fa $(human_age "$age")}."
        fix "Reiniciant el runner..."
        dc restart runner >/dev/null 2>&1
        local t=0; age=""
        while (( t < 120 )); do sleep 5; t=$((t + 5)); age=$(runner_age); [[ -n $age ]] && (( age < 90 )) && break; done
        if [[ -n $age ]] && (( age < 90 )); then ok "Runner recuperat."
        else err "El runner continua aturat. Últimes línies del log:"; dc logs --tail=25 runner; problems=$((problems + 1)); fi
    fi

    # 7. Domini (si n'hi ha un configurat)
    if [[ -n $(env_get DOMAIN) ]]; then
        write_caddyfile
        if [[ $(svc_state proxy) != running* ]]; then
            warn "El proxy del domini no està en marxa."
            fix "Arrencant-lo..."
            up_services || problems=$((problems + 1))
        fi
        if check_domain; then
            ok "Accés per domini ($(domain_url)): respon."
        else
            warn "L'accés per domini ($(domain_url)) no respon."
            fix "Reiniciant el proxy..."
            dc restart proxy >/dev/null 2>&1
            sleep 5
            if check_domain; then
                ok "Accés per domini recuperat."
            else
                # No compta com a problema greu: el panell segueix accessible per IP:port;
                # sovint és que el DNS encara no apunta aquí o Let's Encrypt encara no ha emès el certificat.
                warn "Encara no respon pel domini. Si acabes de configurar-lo, pot ser normal (DNS / certificat en procés)."
            fi
        fi
    fi

    # Resum
    if (( problems == 0 )); then
        (( AUTO && FIXES > 0 )) && echo "$(ts)${G}✔${N} Reparat automàticament ($FIXES acció/ns)."
        ok "${W}Tot funciona correctament.${N}"
        return 0
    fi
    err "Queden $problems problema/es sense resoldre. Mira els logs (menú → Veure logs) o $LOG_FILE."
    return 1
}

rotate_backups() {
    local keep; keep=$(env_get BACKUP_KEEP | grep -E '^[0-9]+$' || echo 14)
    ls -1t "$BACKUP_DIR"/batsignal-*.sql.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f
}

do_backup() {
    ACTION="backup"
    (( AUTO )) && { QUIET=1; take_lock || exit 0; }
    installed && detect_compose && docker_ok || { err "BatSignal no està en marxa; no es pot fer la còpia."; return 1; }
    wait_health db 60 || { err "La base de dades no respon; prova primer «Diagnosticar i reparar»."; return 1; }
    mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
    local file; file="$BACKUP_DIR/batsignal-$(date +%Y%m%d-%H%M%S).sql.gz"
    if dc exec -T -e MYSQL_PWD="$(env_get DB_ROOT_PASSWORD)" db mariadb-dump -uroot --single-transaction --routines batsignal | gzip >"$file" \
        && gzip -t "$file" 2>/dev/null && [[ $(gzip -dc "$file" | head -c 100000 | grep -c 'CREATE TABLE') -gt 0 ]]; then
        chmod 600 "$file"
        rotate_backups
        ok "Còpia feta: $file ($(du -h "$file" | cut -f1))"
        log "Còpia: $file"
        return 0
    fi
    rm -f "$file"
    err "La còpia de seguretat ha fallat."
    return 1
}

do_restore() {
    ACTION="restore"
    banner
    installed && detect_compose && docker_ok || { err "BatSignal ha d'estar instal·lat i en marxa per restaurar."; return 1; }
    lock_or_wait || return 1
    step "♻" "Restaurar una còpia o importar dades"
    info "També pots importar dades d'una instal·lació anterior: exporta la BD 'batsignal' (phpMyAdmin → Exportar, o mysqldump) i copia el fitxer .sql a $BACKUP_DIR/"
    mapfile -t files < <(ls -1t "$BACKUP_DIR"/*.sql.gz "$BACKUP_DIR"/*.sql 2>/dev/null)
    local i choice file
    if (( ${#files[@]} == 0 )); then warn "No hi ha cap còpia a $BACKUP_DIR."
    else for i in "${!files[@]}"; do printf '  %2d) %s  %s  %s\n' $((i + 1)) "$(basename "${files[$i]}")" "$(du -h "${files[$i]}" | cut -f1)" "$(date -r "${files[$i]}" '+%F %H:%M')"; done; fi
    choice=$(prompt "Número de la còpia, o camí complet d'un fitxer .sql/.sql.gz (buit per cancel·lar)")
    [[ -z $choice ]] && { info "Cancel·lat."; return 0; }
    if [[ $choice =~ ^[0-9]+$ ]] && (( choice >= 1 && choice <= ${#files[@]} )); then file=${files[$((choice - 1))]}; else file=$choice; fi
    [[ -f $file ]] || { err "No existeix: $file"; return 1; }
    if [[ $file == *.gz ]]; then gzip -t "$file" 2>/dev/null || { err "El fitxer està malmès."; return 1; }; fi

    warn "Se substituiran TOTES les dades actuals per les de $(basename "$file")."
    [[ $(prompt "Escriu RESTAURAR per confirmar") == "RESTAURAR" ]] || { info "Cancel·lat."; return 0; }
    info "Primer faig una còpia de seguretat de l'estat actual, per si de cas..."
    do_backup || { confirm "La còpia prèvia ha fallat. Continuar igualment?" n || return 1; }

    fix "Restaurant..."
    dc stop runner >/dev/null 2>&1
    local pw; pw=$(env_get DB_ROOT_PASSWORD)
    # Es recrea la BD sencera: així no queden taules d'una versió diferent a la de la còpia.
    dc exec -T -e MYSQL_PWD="$pw" db mariadb -uroot -e "DROP DATABASE IF EXISTS batsignal; CREATE DATABASE batsignal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
        || { err "No s'ha pogut preparar la base de dades."; dc start runner >/dev/null 2>&1; return 1; }
    local reader=(cat "$file"); [[ $file == *.gz ]] && reader=(gzip -dc "$file")
    if "${reader[@]}" | sed '/^CREATE DATABASE/d; /^USE /d' | dc exec -T -e MYSQL_PWD="$pw" db mariadb -uroot batsignal; then
        dc exec -T web php bin/migrate.php
        dc start runner >/dev/null 2>&1
        ok "Dades restaurades des de $(basename "$file")."
        info "Si has importat dades d'una instal·lació anterior, entra amb els mateixos usuaris i contrasenyes que tenies allà."
        return 0
    fi
    dc start runner >/dev/null 2>&1
    err "La restauració ha fallat. Tens la còpia de l'estat anterior a $BACKUP_DIR (la més recent)."
    return 1
}

do_update() {
    ACTION="update"
    banner
    installed && detect_compose && docker_ok || { err "BatSignal no està instal·lat o Docker està aturat."; return 1; }
    lock_or_wait || return 1
    step "1/4" "Còpia de seguretat abans d'actualitzar"
    do_backup || { confirm "La còpia ha fallat. Continuar igualment?" n || return 1; }
    step "2/4" "Codi nou"
    if [[ -d $APP_DIR/.git ]] && have git; then
        git -C "$APP_DIR" pull --ff-only || { err "git pull ha fallat (canvis locals?). Resol-ho i torna-ho a provar."; return 1; }
    else
        info "No és un repositori git: s'utilitzen els fitxers que hi ha ara a $APP_DIR (copia-hi abans la versió nova)."
    fi
    step "3/4" "Reconstruint i reiniciant"
    fix_line_endings
    dc build || { err "La construcció ha fallat; la versió anterior continua funcionant."; return 1; }
    up_services || return 1
    step "4/4" "Comprovant"
    wait_web 120 && dc exec -T web php bin/migrate.php && ok "Actualització completada." || { err "Alguna cosa no ha arrencat bé; executant la reparació..."; do_repair; }
}

do_logs() {
    local svc=${1:-}
    if [[ -z $svc ]]; then
        echo "  1) Panell web      2) Runner (checks)      3) Base de dades      4) Proxy (domini)"
        echo "  5) Accions d'aquest script      6) Vigilant automàtic"
        case $(prompt "Quin" "2") in
            1) svc=web ;; 2) svc=runner ;; 3) svc=db ;; 4) svc=proxy ;;
            5) tail -n 60 "$LOG_FILE" 2>/dev/null || info "Encara no hi ha registre."; return ;;
            6) tail -n 60 "$LOG_DIR/watchdog.log" 2>/dev/null || info "El vigilant encara no ha registrat res (bon senyal)."; return ;;
            *) return ;;
        esac
    fi
    info "Mostrant els logs en directe de «$svc». Prem Ctrl+C per tornar."
    trap 'true' INT
    dc logs --tail=100 -f "$svc"
    trap - INT
}

do_service() { # start | stop | restart
    installed && detect_compose || { err "BatSignal no està instal·lat."; return 1; }
    start_docker || return 1
    case $1 in
        start)   up_services && wait_web 90 && ok "BatSignal en marxa." ;;
        stop)    dc stop && ok "BatSignal aturat. Els checks no s'executaran fins que el tornis a arrencar." ;;
        restart) dc restart && wait_web 90 && ok "BatSignal reiniciat." ;;
    esac
}

do_users() {
    installed && detect_compose && web_ok 5 || { err "El panell ha d'estar en marxa (prova l'opció 3, reparar)."; return 1; }
    echo "  1) Llistar usuaris    2) Crear usuari    3) Canviar contrasenya (contrasenya oblidada)"
    local user p1 p2 out
    case $(prompt "Tria" "1") in
        1) dc exec -T web php bin/admin.php list ;;
        2) create_admin ;;
        3)
            dc exec -T web php bin/admin.php list
            user=$(prompt "Usuari")
            [[ -z $user ]] && return
            read -r -s -p "Contrasenya nova (mínim 8): " p1; echo
            read -r -s -p "Repeteix-la: " p2; echo
            [[ $p1 == "$p2" ]] || { err "No coincideixen."; return 1; }
            if out=$(printf '%s\n' "$p1" | dc exec -T web php bin/admin.php reset "$user" 2>&1); then ok "$out"; else err "$out"; fi
            ;;
    esac
}

do_config() {
    installed || { err "Primer cal instal·lar BatSignal."; return 1; }
    echo "  Port actual: ${W}$(env_get WEB_PORT)${N}   Domini: ${W}$(domain_url 2>/dev/null || echo '—')${N}"
    echo "  Zona horària: ${W}$(env_get TZ)${N}   Heartbeat: ${W}$(env_get HEARTBEAT_URL | grep . || echo '—')${N}   Còpies: ${W}$(env_get BACKUP_KEEP)${N}"
    echo "  1) Canviar el port   2) Domini   3) Zona horària   4) URL de heartbeat (Uptime Kuma)   5) Còpies a conservar"
    local v
    case $(prompt "Tria" "") in
        1) v=$(ask_port "Port on s'obrirà el panell" "$(env_get WEB_PORT)"); env_set WEB_PORT "$v" ;;
        2) do_domain_config; return ;;
        3) env_set TZ "$(prompt "Zona horària" "$(env_get TZ)")" ;;
        4) info "A Uptime Kuma crea un monitor de tipus «Push» i enganxa aquí la seva URL (buit per desactivar)."; env_set HEARTBEAT_URL "$(prompt "URL")" ;;
        5) v=$(prompt "Quantes còpies conservar" "$(env_get BACKUP_KEEP)"); [[ $v =~ ^[0-9]+$ ]] && env_set BACKUP_KEEP "$v" ;;
        *) return ;;
    esac
    detect_compose && docker_ok && { fix "Aplicant canvis..."; up_services && wait_web 90 && ok "Fet. Panell: $(web_url)"; }
}

watchdog_install() {
    if ! have cron && ! have crond; then
        fix "Instal·lant el servei cron..."
        pkg_install cron >/dev/null 2>&1 || pkg_install cronie >/dev/null 2>&1
    fi
    mkdir -p /etc/cron.d "$LOG_DIR"
    cat >"$CRON_FILE" <<CRON
# BatSignal: autoreparació cada 5 minuts i còpia de seguretat cada nit (gestionat per batsignal.sh)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * root bash "$SCRIPT_PATH" repair --auto >>"$LOG_DIR/watchdog.log" 2>&1
30 3 * * * root bash "$SCRIPT_PATH" backup --auto >>"$LOG_DIR/watchdog.log" 2>&1
CRON
    chmod 644 "$CRON_FILE"
    if have systemctl; then systemctl enable --now cron >/dev/null 2>&1 || systemctl enable --now crond >/dev/null 2>&1; fi
    ok "Vigilant automàtic activat (reparació cada 5 min, còpia cada nit a les 03:30)."
}

do_watchdog() {
    if [[ -f $CRON_FILE ]]; then
        ok "El vigilant automàtic està actiu."
        tail -n 5 "$LOG_DIR/watchdog.log" 2>/dev/null | sed 's/^/    /'
        confirm "Vols desactivar-lo?" n && { rm -f "$CRON_FILE"; ok "Vigilant desactivat."; }
    else
        warn "El vigilant automàtic està desactivat."
        confirm "Activar-lo?" s && watchdog_install
    fi
}

do_uninstall() {
    ACTION="uninstall"
    installed && detect_compose || { info "No hi ha res instal·lat."; return 0; }
    warn "S'aturarà i s'eliminarà BatSignal d'aquest servidor."
    confirm "Continuar?" n || return 0
    confirm "Fer una còpia de seguretat final abans?" s && do_backup

    fix "Desactivant el vigilant automàtic..."
    rm -f "$CRON_FILE"

    fix "Aturant i eliminant els contenidors (pot trigar un moment)..."
    if dc down; then ok "Contenidors eliminats."; else warn "Hi ha hagut algun problema aturant els contenidors; continuo igualment."; fi

    if confirm "Esborrar també la base de dades (webs, historial, usuaris)?" n; then
        if [[ $(prompt "Escriu ESBORRAR per confirmar") == "ESBORRAR" ]]; then
            fix "Esborrant els volums de dades..."
            docker volume rm "$DB_VOLUME" batsignal_caddy_data batsignal_caddy_config 2>&1 | sed 's/^/    /'
            rm -f "$ENV_FILE" "$OVERRIDE_FILE"
            write_caddyfile
            ok "Dades esborrades."
        fi
    else
        info "Les dades es conserven: si tornes a instal·lar, ho recuperaràs tot."
    fi
    info "El codi i les còpies ($BACKUP_DIR) no s'han tocat."
    ok "${W}BatSignal desinstal·lat.${N}"
}

# ─────────────────────────────── menú ───────────────────────────────
menu() {
    local choice
    while :; do
        banner
        echo "  $(quick_status)"
        line
        cat <<MENU
   ${W}1${N}) Instal·lar BatSignal ${D}(de zero: Docker, configuració, usuari...)${N}
   ${W}2${N}) Estat del sistema
   ${W}3${N}) ${Y}Diagnosticar i reparar automàticament${N} ${D}(no respon la web, el sistema...)${N}
   ${W}4${N}) Arrencar / aturar / reiniciar
   ${W}5${N}) Veure logs
   ${W}6${N}) Fer una còpia de seguretat ara
   ${W}7${N}) Restaurar una còpia ${D}(o importar dades d'una instal·lació anterior)${N}
   ${W}8${N}) Actualitzar BatSignal
   ${W}9${N}) Usuaris del panell ${D}(crear, contrasenya oblidada)${N}
  ${W}10${N}) Configuració ${D}(port, domini, zona horària, heartbeat)${N}
  ${W}11${N}) Vigilant automàtic ${D}(autoreparació + còpies diàries)${N}
  ${W}12${N}) Desinstal·lar
   ${W}0${N}) Sortir
MENU
        line
        read -r -p "  Tria una opció: " choice || exit 0
        case $choice in
            1) do_install; pause ;;
            2) do_status; pause ;;
            3) do_repair; pause ;;
            4) echo "  1) Arrencar   2) Aturar   3) Reiniciar"
               case $(prompt "Tria" "3") in 1) do_service start ;; 2) do_service stop ;; 3) do_service restart ;; esac; pause ;;
            5) do_logs; pause ;;
            6) ACTION=backup; do_backup; pause ;;
            7) do_restore; pause ;;
            8) do_update; pause ;;
            9) do_users; pause ;;
            10) do_config; pause ;;
            11) do_watchdog; pause ;;
            12) do_uninstall; pause ;;
            0|q|Q) echo "Fins aviat. 🦇"; exit 0 ;;
            *) ;;
        esac
        # Un menú obert no ha de bloquejar el vigilant automàtic.
        release_lock
    done
}

usage() {
    cat <<USAGE
BatSignal — instal·lació i manteniment

  sudo bash batsignal.sh                 Menú interactiu (recomanat)
  sudo bash batsignal.sh install         Instal·lació completa des de zero
  sudo bash batsignal.sh status          Estat del sistema
  sudo bash batsignal.sh repair          Diagnosticar i reparar
  sudo bash batsignal.sh repair --auto   Igual, sense preguntes (el fa servir el vigilant)
  sudo bash batsignal.sh backup          Còpia de seguretat de la base de dades
  sudo bash batsignal.sh restore         Restaurar una còpia / importar dades
  sudo bash batsignal.sh update          Actualitzar (còpia + reconstruir + migrar)
  sudo bash batsignal.sh logs [servei]   Logs en directe (web, runner, db)
  sudo bash batsignal.sh start|stop|restart
  sudo bash batsignal.sh users           Usuaris del panell
  sudo bash batsignal.sh config          Port, zona horària, heartbeat
  sudo bash batsignal.sh domain          Configurar l'accés per domini
  sudo bash batsignal.sh watchdog        Activar/desactivar el vigilant automàtic
  sudo bash batsignal.sh uninstall       Desinstal·lar
USAGE
}

main() {
    local cmd=${1:-menu}
    [[ $cmd == help || $cmd == -h || $cmd == --help ]] && { usage; exit 0; }
    shift || true
    [[ " $* " == *" --auto "* ]] && AUTO=1
    require_root
    detect_os
    have docker && detect_compose
    case $cmd in
        menu)      menu ;;
        install)   do_install ;;
        status)    do_status ;;
        repair)    do_repair ;;
        backup)    ACTION=backup; do_backup ;;
        restore)   do_restore ;;
        update)    do_update ;;
        logs)      do_logs "${1:-}" ;;
        start|stop|restart) do_service "$cmd" ;;
        users)     do_users ;;
        config)    do_config ;;
        domain)    do_domain_config ;;
        watchdog)  do_watchdog ;;
        uninstall) do_uninstall ;;
        *)         err "Ordre desconeguda: $cmd"; usage; exit 2 ;;
    esac
}

main "$@"
