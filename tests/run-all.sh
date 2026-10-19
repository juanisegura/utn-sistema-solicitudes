#!/usr/bin/env bash
# Corre toda la verificación local en orden y corta en el primer fallo (D-51: sin CI en GitHub):
#   1. php -l de app/, public_html/, scripts/ y tests/
#   2. tests unitarios
#   3. instalar-db (schema + seed)
#   4. php -S propio con PHP_CLI_SERVER_WORKERS=8 + tests de integración (SIN_SKIP=1)
#   5. instalar-db otra vez + smoke HTTP contra ese mismo servidor
# Uso: bash tests/run-all.sh [--puerto N] [--evidencia] [--continuar] [-h]
#   --continuar: no corta en el primer fallo; corre todas las etapas y sale ≠ 0 si alguna falló
# No toca el servidor de desarrollo (:8080) ni lee app/config/config.local.php: la DB la usa la
# app con su propia config. Requiere turno exclusivo de DB (D-42): borra y recrea las tablas.
set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUERTO=8090
EVIDENCIA=()
CONTINUAR=0
FALLAS=0
WORKERS=8

uso() { sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; }
while (($#)); do
  case $1 in
    --puerto) [[ ${2:-} =~ ^[0-9]+$ ]] || { echo "--puerto requiere un número" >&2; exit 2; }; PUERTO=$2; shift 2 ;;
    --evidencia) EVIDENCIA=(--evidencia); shift ;;
    --continuar) CONTINUAR=1; shift ;;
    -h|--help) uso; exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; uso >&2; exit 2 ;;
  esac
done
if [[ $PUERTO == 8080 ]]; then
  echo "El puerto 8080 es el servidor de desarrollo: elegí otro con --puerto." >&2
  exit 2
fi

BASE_URL="http://127.0.0.1:$PUERTO"
LOG_SERVIDOR="$RAIZ/tests/.tmp/run-all-servidor.log"
mkdir -p "$RAIZ/tests/.tmp"
PID_SERVIDOR=""
declare -a RESUMEN=()

bajar_servidor() {
  if [[ -n $PID_SERVIDOR ]] && kill -0 "$PID_SERVIDOR" 2>/dev/null; then
    # Con workers, php -S tiene hijos: se baja el grupo entero.
    kill -- "-$PID_SERVIDOR" 2>/dev/null || kill "$PID_SERVIDOR" 2>/dev/null
    wait "$PID_SERVIDOR" 2>/dev/null
  fi
  PID_SERVIDOR=""
}
imprimir_resumen() {
  printf '\n== Resumen de run-all ==\n'
  local linea
  for linea in "${RESUMEN[@]}"; do printf '%s\n' "$linea"; done
}
al_salir() {
  local codigo=$?
  bajar_servidor
  imprimir_resumen
  exit "$codigo"
}
trap al_salir EXIT
trap 'exit 130' INT TERM

# etapa <nombre> <comando...>: corre, registra OK/FALLÓ con la duración y corta si falla
etapa() {
  local nombre=$1 inicio fin codigo; shift
  printf '\n######## %s ########\n' "$nombre"
  inicio=$(date +%s)
  "$@"
  codigo=$?
  fin=$(date +%s)
  if ((codigo == 0)); then
    RESUMEN+=("OK     $nombre ($((fin - inicio)) s)")
  else
    RESUMEN+=("FALLÓ  $nombre (exit $codigo, $((fin - inicio)) s)")
    FALLAS=$((FALLAS + 1))
    ((CONTINUAR)) || exit 1
  fi
}

sintaxis() {
  local errores=0 archivo salida
  while IFS= read -r -d '' archivo; do
    if ! salida=$(php -l "$archivo" 2>&1); then
      printf '%s\n' "$salida"
      errores=$((errores + 1))
    fi
  done < <(find "$RAIZ/app" "$RAIZ/public_html" "$RAIZ/scripts" "$RAIZ/tests" -name '*.php' -print0)
  echo "php -l: $errores archivo(s) con errores"
  ((errores == 0))
}

levantar_servidor() {
  if curl -s -o /dev/null --max-time 1 "$BASE_URL/"; then
    echo "El puerto $PUERTO ya está en uso: elegí otro con --puerto." >&2
    return 1
  fi
  # setsid: grupo de procesos propio para poder bajar también los workers.
  (cd "$RAIZ" && PHP_CLI_SERVER_WORKERS=$WORKERS exec setsid php -S "127.0.0.1:$PUERTO" \
    -t public_html public_html/index.php) > "$LOG_SERVIDOR" 2>&1 &
  PID_SERVIDOR=$!
  local i
  for i in $(seq 1 50); do
    if curl -s -o /dev/null --max-time 1 "$BASE_URL/"; then
      echo "Servidor de prueba en $BASE_URL ($WORKERS workers, PID $PID_SERVIDOR)"
      return 0
    fi
    kill -0 "$PID_SERVIDOR" 2>/dev/null || break
    sleep 0.2
  done
  echo "El servidor no respondió en $BASE_URL. Log:" >&2
  cat "$LOG_SERVIDOR" >&2
  return 1
}

integracion() {
  levantar_servidor || return 1
  BASE_URL=$BASE_URL SIN_SKIP=1 php "$RAIZ/tests/integration/run.php"
}

cd "$RAIZ" || exit 2
etapa "1. Sintaxis (php -l)" sintaxis
etapa "2. Tests unitarios" php tests/unit/run.php
etapa "3. Instalar DB" php scripts/instalar-db.php
etapa "4. Tests de integración ($BASE_URL)" integracion
etapa "5a. Reinstalar DB (el smoke exige DB recién instalada)" php scripts/instalar-db.php
etapa "5b. Smoke HTTP ($BASE_URL)" bash tests/smoke.sh --base-url "$BASE_URL" "${EVIDENCIA[@]}"
if ((FALLAS > 0)); then
  RESUMEN+=("$FALLAS etapa(s) fallaron (--continuar).")
  exit 1
fi
RESUMEN+=("Todo OK. La DB quedó con el seed recién instalado + las filas del smoke (reinstalar si hace falta).")
