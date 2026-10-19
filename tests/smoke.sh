#!/usr/bin/env bash
# smoke.sh — pruebas mínimas de la consigna (§4) + códigos HTTP del PLAN, por HTTP contra
# la app corriendo. Solo bash + curl. Ver tests/README.md (mapeo caso → prueba mínima).
#
# Uso: tests/smoke.sh [--evidencia] [--base-url URL] [-h]
# Exit: 0 todo PASS · 1 hay FAIL/SKIP · 2 no hay servidor o falta curl.
set -uo pipefail
shopt -s nocasematch

# --- Contrato asumido: ajustar acá si la implementación difiere (ver tests/README.md) ---
BASE_URL="${BASE_URL:-http://localhost:8080}"
CSRF_FIELD="${CSRF_FIELD:-csrf_token}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-Demo-UTN-2026!}"
F_NOMBRE=nombre F_EMAIL=email F_ASUNTO=asunto F_DESC=descripcion F_ESTADO=estado
F_LOGIN_EMAIL=email F_LOGIN_PASS=password
RE_CONFIRMACION='recibi|registrad|gracias'
RE_ERR_OBLIGATORIO='obligatori|requerid'
RE_ERR_EMAIL='(email|correo)[^<]{0,80}v.{1,2}lid|v.{1,2}lid[^<]{0,80}(email|correo)'
RE_LOGIN_ERROR='Email o contrase.{1,2}a incorrectos'
RE_FLASH_ESTADO='Estado actualizado'
RE_BLOQUEO='Demasiados intentos'
LIMITE_CUENTA=5 VENTANA_SEG=900   # D-38: app.login_max_por_cuenta y ventana de 15 min
RE_FUGA='SQLSTATE|PDOException|Stack trace|Fatal error|on line [0-9]+|You have an error in your SQL'
ID_INEXISTENTE=999999999
# Funcionalidades finales (PLAN §7, D-63). FORM_MAX y SEG_MAX deben coincidir con
# app.form_max_por_ip y app.seguimiento_max_por_ip del servidor bajo prueba (ver tests/README.md:
# una corrida completa hace ~35 envíos del formulario, más que el default 5).
FORM_MAX="${FORM_MAX:-5}" SEG_MAX="${SEG_MAX:-10}"
F_HONEYPOT=sitio_web
F_PW_ACTUAL=password_actual F_PW_NUEVA=password_nueva F_PW_CONF=password_confirmacion
F_SEG_NUM="${F_SEG_NUM:-numero}" F_SEG_COD="${F_SEG_COD:-codigo}"   # F-3: a confirmar en A1-17
RE_ENVIOS='Demasiados env.{1,2}os'
RE_SEG_ERROR='N.{1,2}mero o c.{1,2}digo incorrectos'
RE_PW_OK='Contrase.{1,2}a actualizada'
# Código de seguimiento en /gracias: grupos de caracteres base32 separados por guiones (≥ 26 sin separadores)
RE_CODIGO='[A-Z0-9]{4}(-[A-Z0-9]{1,4}){5,}'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_DIR="$SCRIPT_DIR/.tmp"
EVIDENCIAS_DIR="$SCRIPT_DIR/../doc/pruebas/evidencias"
EVIDENCIA=0

uso() { sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'; }
while (($#)); do
  case $1 in
    --evidencia) EVIDENCIA=1 ;;
    --base-url) [[ -n ${2:-} ]] || { echo "--base-url requiere una URL" >&2; exit 2; }; BASE_URL=$2; shift ;;
    -h|--help) uso; exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; uso >&2; exit 2 ;;
  esac
  shift
done
BASE_URL=${BASE_URL%/}

command -v curl >/dev/null || { echo "ERROR: falta curl" >&2; exit 2; }

if ((EVIDENCIA)); then
  mkdir -p "$EVIDENCIAS_DIR"
  EVIDENCIA_FILE="$EVIDENCIAS_DIR/smoke-$(date +%Y%m%d-%H%M%S).txt"
  exec > >(tee "$EVIDENCIA_FILE") 2>&1
fi

rm -rf "${TMP_DIR:?}"
mkdir -p "$TMP_DIR"
J_PUB="$TMP_DIR/publico.cookies"     # visitante del formulario
J_ANON="$TMP_DIR/anonimo.cookies"    # sin sesión de admin
J_BAD="$TMP_DIR/login-malo.cookies"  # intentos de login fallidos
J_ADM="$TMP_DIR/admin.cookies"       # admin autenticado
J_NUEVO="$TMP_DIR/nuevo.cookies"     # se vacía antes de cada uso (sin cookies previas)

MARCA="smoke-$(date +%s)-$$"         # identifica las filas de esta corrida en el panel
PASS=0 FAIL=0 SKIP=0

# --- HTTP ---------------------------------------------------------------------------
# req <jar> <método> <ruta> [args curl...] → deja CODE, BODY (archivo), HDRS (archivo)
REQ_N=0
req() {
  local jar=$1 method=$2 path=$3; shift 3
  REQ_N=$((REQ_N + 1))
  BODY="$TMP_DIR/r$REQ_N.body" HDRS="$TMP_DIR/r$REQ_N.hdrs"
  : > "$BODY"; : > "$HDRS"
  CODE=$(curl -s --connect-timeout 3 --max-time 15 -X "$method" -b "$jar" -c "$jar" \
    -o "$BODY" -D "$HDRS" -w '%{http_code}' "$@" "$BASE_URL$path") || true
  [[ -n $CODE ]] || CODE=000
  # F-1: cuenta los envíos del formulario que pasaron el CSRF (los 429 no cuentan: se borran)
  if [[ $method == POST && $path == /solicitudes ]]; then
    if [[ $CODE == 302 || $CODE == 422 ]]; then FORM_N=$((FORM_N + 1)); fi
    if [[ $CODE == 429 && -z ${F1_EN_CURSO:-} && -z ${AVISO_FORM:-} ]]; then
      AVISO_FORM=1
      echo "AVISO: POST /solicitudes → 429 antes de la sección F-1 (envío n.º $((FORM_N + 1))). El servidor tiene app.form_max_por_ip menor que lo que hace una corrida: configurar ≥ 40 y exportar FORM_MAX con ese valor (tests/README.md)."
    fi
  fi
}
FORM_N=0
# post <jar> <ruta> [campo=valor...] — valores url-encodeados por curl
post() {
  local jar=$1 path=$2; shift 2
  local args=(--data-urlencode "_smoke=1") kv
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  req "$jar" POST "$path" "${args[@]}"
}
nuevo_jar() { : > "$J_NUEVO"; }
# urlenc <texto> → percent-encoding byte a byte (para armar cuerpos crudos con --data)
urlenc() {
  local LC_ALL=C s=$1 out='' c i
  for ((i = 0; i < ${#s}; i++)); do
    c=${s:i:1}
    case $c in
      [a-zA-Z0-9.~_-]) out+=$c ;;
      *) printf -v c '%%%02X' "'$c"; out+=$c ;;
    esac
  done
  printf '%s' "$out"
}
header() { grep -i "^$1:" "$HDRS" | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
# Location relativa al sitio (quita esquema y host si vienen)
loc() { header Location | sed -E 's#^https?://[^/]+##'; }
has()  { grep -Eiq -- "$1" "$BODY" && echo sí || echo no; }
hasF() { grep -Fq -- "$1" "$BODY" && echo sí || echo no; }
# hasT: como hasF pero sobre el texto visible: ignora <wbr> (cortes de línea sugeridos, p. ej. en
# emails largos del detalle), que parten el dato en el HTML sin cambiar lo que se ve.
hasT() { sed 's/<wbr>//g' "$BODY" | grep -Fq -- "$1" && echo sí || echo no; }
fuga() { grep -Eiq -- "$RE_FUGA" "$BODY" && echo sí || echo no; }

# Token CSRF del primer <input name="$CSRF_FIELD"> del HTML
csrf_de() {
  tr '\n' ' ' < "$1" | grep -oiE '<input[^>]*>' | grep -E "name=[\"']${CSRF_FIELD}[\"']" \
    | grep -oE "value=[\"'][^\"']*[\"']" | head -1 | sed -E "s/^value=[\"']//; s/[\"']$//"
}
# Estado actual en el detalle: <option value="..." selected> del form de cambio de estado
estado_en() {
  tr '\n' ' ' < "$1" | grep -oiE '<option[^>]*>' | grep -iE '[[:space:]]selected' \
    | grep -oE 'value="[a-z_]+"' | head -1 | cut -d'"' -f2
}
# ID de la fila del listado (tr/li/article) que contiene la marca
id_por_marca() {
  tr '\n' ' ' < "$1" | sed -E 's#<(tr|li|article)[ >]#\n&#g' | grep -F -- "$2" \
    | grep -oE '/admin/solicitudes/[0-9]+' | head -1 | grep -oE '[0-9]+$'
}
max_id() {
  local m
  m=$(grep -oE '/admin/solicitudes/[0-9]+' "$1" | grep -oE '[0-9]+$' | sort -n | tail -1)
  echo "${m:-0}"
}
cookie_vals() { awk -F'\t' 'NF >= 7 && ($1 !~ /^#/ || $1 ~ /^#HttpOnly_/) {print $6"="$7}' "$1" | sort; }
# estado_de <id>: estado persistido, leído desde el detalle del panel (sesión admin)
estado_de() {
  local c=$CODE b=$BODY h=$HDRS e
  req "$J_ADM" GET "/admin/solicitudes/$1"
  e=$(estado_en "$BODY")
  CODE=$c BODY=$b HDRS=$h
  echo "${e:-?}"
}

# --- Resultados ---------------------------------------------------------------------
# check <id> <descripción> <esperado (regex ERE, anclada)> <obtenido>
check() {
  local id=$1 desc=$2 exp=$3 got=$4
  if [[ $got =~ ^($exp)$ ]]; then
    PASS=$((PASS + 1)); printf 'PASS  %-6s %s  [obtenido: %s]\n' "$id" "$desc" "$got"
  else
    FAIL=$((FAIL + 1)); printf 'FAIL  %-6s %s  [obtenido: %s | esperado: %s]\n' "$id" "$desc" "$got" "$exp"
  fi
}
skip() {
  local motivo=$1 id; shift
  for id in "$@"; do SKIP=$((SKIP + 1)); printf 'SKIP  %-6s (%s)\n' "$id" "$motivo"; done
}
seccion() { printf '\n== %s ==\n' "$1"; }

echo "smoke.sh — $(date '+%Y-%m-%d %H:%M:%S') — BASE_URL=$BASE_URL — marca=$MARCA"

# --- Preflight ----------------------------------------------------------------------
req "$J_PUB" GET /
if [[ $CODE == 000 ]]; then
  echo "ERROR: no responde $BASE_URL (curl no pudo conectar; timeout 3 s)." >&2
  echo "Levantá la app desde la raíz del repo:" >&2
  echo "  php -S localhost:8080 -t public_html public_html/index.php" >&2
  exit 2
fi

seccion "S — Headers de seguridad (GET /)"
check S.1 "X-Frame-Options" 'DENY' "$(header X-Frame-Options)"
check S.2 "X-Content-Type-Options" 'nosniff' "$(header X-Content-Type-Options)"
check S.3 "Content-Security-Policy con default-src 'self'" ".*default-src 'self'.*" "$(header Content-Security-Policy)"

seccion "P1 — Solicitud válida"
T_PUB=$(csrf_de "$BODY")
check P1.1 "GET / → 200 con form y token CSRF" "200 sí" "$CODE $([[ -n $T_PUB ]] && echo sí || echo no)"

seccion "P3 — Acceso al panel sin sesión"
for p in "P3.1 /admin" "P3.2 /admin/solicitudes/1" "P3.3 /admin?estado=pendiente"; do
  req "$J_ANON" GET "${p#* }"
  check "${p%% *}" "GET ${p#* } sin sesión → 302 a /admin/login" "302 /admin/login" "$CODE $(loc)"
done
post "$J_ANON" /admin/logout
check P3.4 "POST /admin/logout sin sesión → 401 (D-17)" "401" "$CODE"

seccion "P4 — Credenciales incorrectas"
req "$J_BAD" GET /admin/login
T_BAD=$(csrf_de "$BODY")
post "$J_BAD" /admin/login "$CSRF_FIELD=$T_BAD" "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=incorrecta-$MARCA"
check P4.1 "login con password incorrecta → 401 + mensaje genérico" "401 sí" "$CODE $(has "$RE_LOGIN_ERROR")"
post "$J_BAD" /admin/login "$CSRF_FIELD=$T_BAD" "$F_LOGIN_EMAIL=noexiste-$MARCA@example.com" "$F_LOGIN_PASS=incorrecta"
check P4.2 "login con email inexistente → 401 + mismo mensaje (anti-enumeración)" "401 sí" "$CODE $(has "$RE_LOGIN_ERROR")"
req "$J_BAD" GET /admin
check P4.3 "GET /admin tras login fallido → 302 a /admin/login" "302 /admin/login" "$CODE $(loc)"
post "$J_BAD" /admin/login "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
check H.1 "login con credenciales correctas pero sin CSRF → 419" "419" "$CODE"
req "$J_BAD" GET /admin
check H.2 "GET /admin tras login sin CSRF → sigue sin acceso" "302 /admin/login" "$CODE $(loc)"
# bcrypt corta en el primer NUL: "password correcta + \0 + basura" no debe autenticar.
# El NUL no entra en una variable de bash: se manda ya codificado (%00) con --data.
nuevo_jar
req "$J_NUEVO" GET /admin/login
T_NUL=$(csrf_de "$BODY")
req "$J_NUEVO" POST /admin/login --data-urlencode "$CSRF_FIELD=$T_NUL" \
  --data-urlencode "$F_LOGIN_EMAIL=$ADMIN_EMAIL" --data "$F_LOGIN_PASS=$(urlenc "$ADMIN_PASSWORD")%00$MARCA"
CODE_NUL=$CODE
req "$J_NUEVO" GET /admin
check H.16 "login con password correcta + NUL + sufijo → 401 y sin acceso" "401 302 /admin/login" \
  "$CODE_NUL $CODE $(loc)"

seccion "Login correcto (prerequisito de P1.5, P2.4, P5, P6, P7)"
ADMIN_OK=0
req "$J_ADM" GET /admin/login
T_LOGIN=$(csrf_de "$BODY")
SET_COOKIE_GUEST=$(header Set-Cookie)
COOKIES_ANTES=$(cookie_vals "$J_ADM")
post "$J_ADM" /admin/login "$CSRF_FIELD=$T_LOGIN" "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
check P5.1 "login correcto → 302 a /admin" "302 /admin" "$CODE $(loc)"
SET_COOKIE=$(header Set-Cookie)
SET_COOKIE=${SET_COOKIE:-$SET_COOKIE_GUEST}  # sin regenerate no hay Set-Cookie: eso ya lo marca S.4
req "$J_ADM" GET /admin
check P5.2 "GET /admin con sesión → 200" "200" "$CODE"
if [[ $CODE == 200 ]]; then ADMIN_OK=1; fi
COOKIES_DESPUES=$(cookie_vals "$J_ADM")
ROTA=no
if [[ -n $COOKIES_ANTES && $COOKIES_ANTES != "$COOKIES_DESPUES" ]]; then ROTA=sí; fi
check S.4 "el ID de sesión cambia al loguear (session_regenerate_id)" "sí" "$ROTA"
check S.5 "Set-Cookie de sesión con HttpOnly y SameSite=Lax" "sí sí" \
  "$([[ $SET_COOKIE == *httponly* ]] && echo sí || echo no) $([[ $SET_COOKIE == *samesite=lax* ]] && echo sí || echo no)"
MAX0=0
if ((ADMIN_OK)); then MAX0=$(max_id "$BODY"); fi

seccion "P1 / P2 — Formulario público"
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=Ana Prueba" "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=Valida $MARCA" "$F_DESC=Descripción de prueba $MARCA"
check P1.2 "POST /solicitudes válida → 302 a /gracias" "302 /gracias" "$CODE $(loc)"
req "$J_PUB" GET /gracias
check P1.3 "GET /gracias → 200 con confirmación visible" "200 sí" "$CODE $(has "$RE_CONFIRMACION")"
req "$J_PUB" GET /gracias
check P1.4 "GET /gracias otra vez (flash consumido) → 302 a /" "302 /" "$CODE $(loc)"

post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=" "$F_EMAIL=" "$F_ASUNTO=" "$F_DESC="
check P2.1 "POST con campos vacíos → 422 + mensajes de obligatorio" "422 sí" "$CODE $(has "$RE_ERR_OBLIGATORIO")"
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=Ana" "$F_EMAIL=no-es-un-email" \
  "$F_ASUNTO=Invalida $MARCA-email" "$F_DESC=x"
check P2.2 "POST con email inválido → 422 + mensaje de email" "422 sí" "$CODE $(has "$RE_ERR_EMAIL")"
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=   " "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=   " "$F_DESC=   "
check P2.3 "POST con campos solo espacios → 422 (trim antes de validar)" "422" "$CODE"
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=$(printf '\xc2\xa0')" "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=Nbsp $MARCA-nbsp" "$F_DESC=x"
check P2.5 "POST con nombre de solo espacio Unicode (NBSP) → 422" "422" "$CODE"
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=$(printf '\xffAna')" "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=Utf8 $MARCA-utf8" "$F_DESC=x"
check H.14 "POST con UTF-8 inválido en nombre → 422 sin fugas, no 500" "422 no" "$CODE $(fuga)"
post "$J_PUB" /solicitudes "$F_NOMBRE=Ana" "$F_EMAIL=ana@example.com" "$F_ASUNTO=SinCsrf $MARCA-sincsrf" "$F_DESC=x"
check H.3 "POST /solicitudes sin token CSRF → 419" "419" "$CODE"
post "$J_PUB" /solicitudes "$CSRF_FIELD=token-falso" "$F_NOMBRE=Ana" "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=SinCsrf $MARCA-sincsrf" "$F_DESC=x"
check H.4 "POST /solicitudes con token CSRF falso → 419" "419" "$CODE"
nuevo_jar
post "$J_NUEVO" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=Ana" "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=SinCsrf $MARCA-sincsrf" "$F_DESC=x"
check H.5 "POST /solicitudes con token de otra sesión (sin cookie) → 419" "419" "$CODE"

ID=""
if ((ADMIN_OK)); then
  req "$J_ADM" GET /admin
  ID=$(id_por_marca "$BODY" "Valida $MARCA")
  MAX1=$(max_id "$BODY")
  check P1.5 "la solicitud válida aparece en el listado del panel" "sí" "$([[ -n $ID ]] && echo sí || echo no)"
  check P2.4 "ninguna alta por inválidos/CSRF (marcas ausentes, única fila nueva = la válida)" "no no no no 1" \
    "$(hasF "$MARCA-email") $(hasF "$MARCA-sincsrf") $(hasF "$MARCA-nbsp") $(hasF "$MARCA-utf8") $((MAX1 - MAX0))"
  if [[ -n $ID ]]; then
    req "$J_ADM" GET "/admin/solicitudes/$ID"
    check P1.6 "detalle persistido (asunto, email, descripción)" "200 sí sí sí" \
      "$CODE $(hasT "Valida $MARCA") $(hasT ana@example.com) $(hasT "Descripción de prueba $MARCA")"
  else
    skip "sin ID de la solicitud válida" P1.6
  fi
else
  skip "sin sesión de admin" P1.5 P2.4 P1.6
fi

seccion "P5 — Cambio de estado autorizado"
T_ADM=""
if ((ADMIN_OK)) && [[ -n $ID ]]; then
  req "$J_ADM" GET "/admin/solicitudes/$ID"
  T_ADM=$(csrf_de "$BODY")
  check P5.3 "estado inicial = pendiente" "pendiente" "$(estado_en "$BODY")"
  post "$J_ADM" "/admin/solicitudes/$ID/estado" "$CSRF_FIELD=$T_ADM" "$F_ESTADO=en_proceso"
  check P5.4 "POST estado=en_proceso con sesión + CSRF → 302 al detalle (D-19)" \
    "302 /admin/solicitudes/$ID" "$CODE $(loc)"
  req "$J_ADM" GET "/admin/solicitudes/$ID"
  check P5.5 "el detalle muestra el nuevo estado y el flash" "en_proceso sí" \
    "$(estado_en "$BODY") $(has "$RE_FLASH_ESTADO")"
  req "$J_ADM" GET "/admin/solicitudes/$ID"
  check P5.6 "el flash no se repite al recargar" "no" "$(has "$RE_FLASH_ESTADO")"
  req "$J_ADM" GET "/admin?estado=en_proceso"
  check F6.1 "filtro ?estado=en_proceso incluye la solicitud" "200 sí" "$CODE $(hasF "Valida $MARCA")"
  req "$J_ADM" GET "/admin?estado=pendiente"
  check F6.2 "filtro ?estado=pendiente no la incluye" "200 no" "$CODE $(hasF "Valida $MARCA")"
  req "$J_ADM" GET "/admin?estado="
  check F6.3 "filtro ?estado= vacío → 200 con todas (D-18)" "200 sí" "$CODE $(hasF "Valida $MARCA")"
  req "$J_ADM" GET "/admin?estado=cerrada"
  check F6.4 "filtro ?estado=cerrada (fuera de lista) → 400 (D-18)" "400" "$CODE"
  req "$J_ADM" GET /admin/login
  check S.6 "GET /admin/login con sesión → 302 a /admin" "302 /admin" "$CODE $(loc)"
else
  skip "sin sesión de admin o sin ID" P5.3 P5.4 P5.5 P5.6 F6.1 F6.2 F6.3 F6.4 S.6
fi

seccion "P6 — Cambio de estado no autorizado (el estado no debe cambiar)"
# intento <id> <desc> <códigos esperados> <jar> [campos...]: compara contra el estado leído
# justo antes, para que un defecto no arrastre FAILs a los casos siguientes
intento() {
  local id=$1 desc=$2 exp=$3 jar=$4 antes got; shift 4
  antes=$(estado_de "$ID")
  post "$jar" "/admin/solicitudes/$ID/estado" "$@"
  got=$CODE
  if [[ $CODE == 302 ]]; then got+=" $(loc)"; fi
  check "$id" "$desc; sin cambio" "($exp) ${antes//\?/\\?}" "$got $(estado_de "$ID")"
}
if ((ADMIN_OK)) && [[ -n $ID && -n $T_ADM ]]; then
  nuevo_jar
  req "$J_NUEVO" GET /admin/login
  T_GUEST=$(csrf_de "$BODY")
  intento P6.1 "sin sesión, con CSRF válido de invitado → 401 (D-17)" 401 \
    "$J_NUEVO" "$CSRF_FIELD=$T_GUEST" "$F_ESTADO=resuelta"
  intento P6.2 "con sesión, sin CSRF → 419" 419 "$J_ADM" "$F_ESTADO=resuelta"
  intento P6.3 "con sesión, token CSRF de otra sesión → 419" 419 \
    "$J_ADM" "$CSRF_FIELD=$T_GUEST" "$F_ESTADO=resuelta"
  intento P6.4 "estado fuera de lista blanca (cerrada) → 422" 422 \
    "$J_ADM" "$CSRF_FIELD=$T_ADM" "$F_ESTADO=cerrada"
  intento P6.5 "estado con inyección SQL → 422" 422 \
    "$J_ADM" "$CSRF_FIELD=$T_ADM" "$F_ESTADO=resuelta' OR '1'='1"
  intento P6.6 "sin campo estado → 422" 422 "$J_ADM" "$CSRF_FIELD=$T_ADM"
  nuevo_jar
  intento P6.7 "sin sesión y sin token → 401: auth antes que CSRF (D-16)" 401 "$J_NUEVO" "$F_ESTADO=resuelta"
  intento P6.8 "estado como array (estado[]=resuelta) → 422, no 500" 422 \
    "$J_ADM" "$CSRF_FIELD=$T_ADM" "$F_ESTADO[]=resuelta"
else
  skip "sin sesión de admin, ID o token" P6.1 P6.2 P6.3 P6.4 P6.5 P6.6 P6.7 P6.8
fi

seccion "P7 — HTML/SQL malicioso"
P_XSS='<script>alert(1)</script>'
P_SQL="' OR '1'='1' -- "
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=$P_SQL" "$F_EMAIL=xss@example.com" \
  "$F_ASUNTO=$P_XSS $MARCA-xss" "$F_DESC=<img src=x onerror=alert(2)> $P_SQL 1=1"
check P7.1 "POST con <script> y ' OR '1'='1' -- en campos → 302 a /gracias (se acepta como texto)" "302 /gracias" "$CODE $(loc)"
RE_SQL_ESC='(&#0?39;|&apos;) OR (&#0?39;|&apos;)1(&#0?39;|&apos;)=(&#0?39;|&apos;)1(&#0?39;|&apos;) --'
if ((ADMIN_OK)); then
  req "$J_ADM" GET /admin
  ID_XSS=$(id_por_marca "$BODY" "$MARCA-xss")
  check P7.2 "listado: <script> escapado (&lt;script&gt;) y nunca crudo" "sí no" \
    "$(hasF "&lt;script&gt;alert(1)&lt;/script&gt; $MARCA-xss") $(hasF "$P_XSS")"
  POS_NUEVA=$(grep -boF -- "$MARCA-xss" "$BODY" | head -1 | cut -d: -f1)
  POS_VIEJA=$(grep -boF -- "Valida $MARCA" "$BODY" | head -1 | cut -d: -f1)
  check F5.1 "listado por fecha descendente: la solicitud más nueva aparece primero (D-20)" "sí" \
    "$([[ -n $POS_NUEVA && -n $POS_VIEJA ]] && ((POS_NUEVA < POS_VIEJA)) && echo sí || echo no)"
  if [[ -n $ID_XSS ]]; then
    req "$J_ADM" GET "/admin/solicitudes/$ID_XSS"
    check P7.3 "detalle: <script> e <img onerror> escapados, nunca crudos" "sí sí no no" \
      "$(hasF '&lt;script&gt;alert(1)&lt;/script&gt;') $(hasF '&lt;img src=x onerror=alert(2)&gt;') $(hasF "$P_XSS") $(hasF '<img src=x')"
    check P7.4 "detalle: payload SQL guardado literal y escapado" "sí" "$(has "$RE_SQL_ESC")"
  else
    skip "la solicitud con payloads no aparece en el listado" P7.3 P7.4
  fi
else
  skip "sin sesión de admin" P7.2 F5.1 P7.3 P7.4
fi
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE=<b>Ana</b>" "$F_EMAIL=no-es-un-email" \
  "$F_ASUNTO=Reflejo $MARCA" "$F_DESC=x"
check P7.5 "422 re-renderiza valores previos escapados (sin XSS reflejado)" "422 sí no" \
  "$CODE $(hasF '&lt;b&gt;Ana&lt;/b&gt;') $(hasF '<b>Ana</b>')"
if ((ADMIN_OK)); then
  req "$J_ADM" GET "/admin?estado=%27%20OR%20%271%27%3D%271"
  check P7.6 "filtro ?estado=' OR '1'='1 → 400 sin error SQL (D-18)" "400 no" "$CODE $(fuga)"
  req "$J_ADM" GET "/admin/solicitudes/1%20OR%201%3D1"
  check P7.7 "id con inyección en la ruta → 404" "404" "$CODE"
else
  skip "sin sesión de admin" P7.6 P7.7
fi

seccion "H — Método incorrecto, inexistentes y fugas"
if ((ADMIN_OK)) && [[ -n $ID ]]; then
  req "$J_ADM" GET "/admin/solicitudes/$ID/estado"
  check H.6 "GET /admin/solicitudes/{id}/estado → 405 + Allow con POST" "405 .*POST.*" "$CODE $(header Allow)"
  req "$J_ADM" GET "/admin/solicitudes/$ID_INEXISTENTE"
  check H.7 "GET detalle de id inexistente → 404 sin fugas" "404 no" "$CODE $(fuga)"
  req "$J_ADM" GET /admin/solicitudes/abc
  check H.8 "GET /admin/solicitudes/abc → 404" "404" "$CODE"
  post "$J_ADM" "/admin/solicitudes/$ID_INEXISTENTE/estado" "$CSRF_FIELD=$T_ADM" "$F_ESTADO=resuelta"
  check H.9 "POST estado sobre id inexistente → 404" "404" "$CODE"
else
  skip "sin sesión de admin o sin ID" H.6 H.7 H.8 H.9
fi
req "$J_PUB" GET /solicitudes
check H.10 "GET /solicitudes → 405 + Allow con POST" "405 .*POST.*" "$CODE $(header Allow)"
req "$J_PUB" GET "/no-existe-$MARCA"
check H.11 "ruta inexistente → 404 sin fugas" "404 no" "$CODE $(fuga)"
req "$J_PUB" GET /.htaccess
check H.15 "GET /.htaccess no se sirve (ni con php -S) → 403/404" "403|404" "$CODE"
post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_PUB" "$F_NOMBRE[]=Ana" "$F_EMAIL=ana@example.com" \
  "$F_ASUNTO=Array $MARCA-array" "$F_DESC=x"
check H.12 "POST /solicitudes con nombre[] (array) → 422 sin fugas, no 500" "422 no" "$CODE $(fuga)"
if ((ADMIN_OK)); then
  req "$J_ADM" GET "/admin?estado%5B%5D=pendiente"
  check H.13 "filtro ?estado[]=pendiente (array) → 400 sin fugas, no 500" "400 no" "$CODE $(fuga)"
else
  skip "sin sesión de admin" H.13
fi

seccion "B — Búsqueda, paginación, historial y sesión perezosa (M-2, M-3, M-5)"
# Filas propias de la corrida por el formulario público (no dependen del seed): 21 con
# "pag-<MARCA>" (2 páginas de 20) y 2 para el escape de comodines de LIKE. Sin logins fallidos:
# no cambia el presupuesto por IP. Usa la sesión de admin antes del logout de S.
PAG="pag-$MARCA"
ESC_SI="escA-$MARCA-a%b_c" ESC_NO="escB-$MARCA-aZZbYc"
req "$J_PUB" GET /
T_B=$(csrf_de "$BODY")
CODES_ALTA=""
for i in $(seq -w 1 21); do
  post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_B" "$F_NOMBRE=Paginación $i" "$F_EMAIL=pag$i@example.com" \
    "$F_ASUNTO=$PAG fila $i" "$F_DESC=Fila $i para la paginación de la corrida"
  CODES_ALTA+="$CODE "
done
for asunto in "$ESC_SI" "$ESC_NO"; do
  post "$J_PUB" /solicitudes "$CSRF_FIELD=$T_B" "$F_NOMBRE=Comodines" "$F_EMAIL=comodin@example.com" \
    "$F_ASUNTO=$asunto" "$F_DESC=Escape de LIKE"
  CODES_ALTA+="$CODE "
done
[[ $CODES_ALTA =~ ^(302\ ){23}$ ]] || echo "AVISO: altas de la sección B: $CODES_ALTA"
# n_items <regex> → cantidad de líneas del BODY que matchean
n_items() { grep -Ec -- "$1" "$BODY" || true; }

if ((ADMIN_OK)); then
  req "$J_ADM" GET "/admin?q=$(urlenc "$PAG")"
  check B.1 "búsqueda q=pag-<marca> → 200, 21 resultados, página 1 con 20 filas" "200 sí 20" \
    "$CODE $(has "21 resultados para «$PAG»") $(n_items "data-label=\"Asunto\".*$PAG fila")"

  req "$J_ADM" GET "/admin?q=$(urlenc "-$MARCA-a%b_c")"
  check B.2 "q con % y _ literales: encuentra «a%b_c» y no «aZZbYc» (comodines escapados)" "200 sí no" \
    "$CODE $(hasF "escA-$MARCA-a%b_c") $(hasF "escB-$MARCA")"

  req "$J_ADM" GET "/admin?q=$(urlenc "<script>alert('b3')</script>")"
  check B.3 "q=<script> reflejado escapado (estado, caption e input), nunca crudo" "200 sí sí no" \
    "$CODE $(hasF "«&lt;script&gt;alert(&#039;b3&#039;)&lt;/script&gt;»") $(hasF "value=\"&lt;script&gt;alert(&#039;b3&#039;)&lt;/script&gt;\"") $(hasF "<script>alert('b3')")"

  B4=""
  for q in "q=$(printf 'x%.0s' $(seq 1 101))" "q=%FFabc" "q=a%01b" "q=a%0Ab" "q[]=x"; do
    req "$J_ADM" GET "/admin?$q"
    B4+="$CODE$( [[ $(fuga) == no ]] || echo +fuga) "
  done
  check B.4 "q inválido (101 caracteres, UTF-8 inválido, control, salto de línea, array) → 400 sin fugas" \
    "400 400 400 400 400" "${B4% }"
  req "$J_ADM" GET "/admin?q=$(printf 'x%.0s' $(seq 1 100))"
  check B.4b "q de exactamente 100 caracteres → 200" "200" "$CODE"

  B5=""
  for pg in 0 abc 01 -1 1.5 '%20' 1e2; do
    req "$J_ADM" GET "/admin?q=$(urlenc "$PAG")&pagina=$pg"
    B5+="$CODE "
  done
  req "$J_ADM" GET "/admin?pagina[]=2"
  B5+="$CODE"
  check B.5 "pagina 0, abc, 01, -1, 1.5, espacio, 1e2, array → 400" \
    "400 400 400 400 400 400 400 400" "$B5"

  req "$J_ADM" GET "/admin?q=$(urlenc "$PAG")&pagina=2"
  B6="$CODE $(n_items "data-label=\"Asunto\".*$PAG fila") $(has 'Página 2 de 2')"
  req "$J_ADM" GET "/admin?q=$(urlenc "$PAG")&pagina=3"
  B6+=" $CODE"
  req "$J_ADM" GET "/admin?q=$(urlenc "$PAG")&pagina=999999999"
  B6+=" $CODE"
  req "$J_ADM" GET "/admin?q=$(urlenc "sin-resultados-$MARCA")&pagina=3"
  B6+=" $CODE $(has 'No hay solicitudes que coincidan')"
  check B.6 "pagina=2 → 1 fila «Página 2 de 2»; pagina=3 y 999999999 → 404; sin resultados, pagina=3 → 200 vacío" \
    "200 1 sí 404 404 200 sí" "$B6"

  req "$J_ADM" GET "/admin?estado=pendiente&q=$(urlenc "$PAG")"
  QENC=$(urlenc "$PAG")
  check B.7 "links de paginación y de filtros conservan q y estado (escapados con &amp;)" "200 sí sí sí" \
    "$CODE $(hasF "href=\"/admin?estado=pendiente&amp;q=$QENC&amp;pagina=2\"") $(hasF "href=\"/admin?estado=resuelta&amp;q=$QENC\"") $(hasF "href=\"/admin?q=$QENC\"")"

  if [[ -n $ID && -n $T_ADM ]]; then
    req "$J_ADM" GET "/admin/solicitudes/$ID"
    N_ANTES=$(n_items 'class="timeline-item')
    HIST="$(has 'Historial de estados') $(has 'badge-pendiente">Pendiente</span>') $N_ANTES"
    post "$J_ADM" "/admin/solicitudes/$ID/estado" "$CSRF_FIELD=$T_ADM" "$F_ESTADO=en_proceso"
    HIST+=" $CODE"
    req "$J_ADM" GET "/admin/solicitudes/$ID"
    HIST+=" $(n_items 'class="timeline-item') $(has "$RE_FLASH_ESTADO")"
    check B.8 "historial visible tras P5 (1 evento pendiente→en proceso); no-op al mismo estado → 302 + flash, sin evento nuevo" \
      "sí sí 1 302 1 sí" "$HIST"
  else
    skip "sin ID o token de admin" B.8
  fi
else
  skip "sin sesión de admin" B.1 B.2 B.3 B.4 B.4b B.5 B.6 B.7 B.8
fi

# B.9 — M-5: 404, 405 y estáticos no crean sesión (sin Set-Cookie); una ruta resuelta sí
B9=""
for ruta in "GET /no-existe-$MARCA" "GET /solicitudes" "GET /assets/css/base/tokens.css" "GET /admin/solicitudes/0"; do
  nuevo_jar
  req "$J_NUEVO" ${ruta%% *} "${ruta#* }"
  B9+="$CODE:$( [[ -z $(header Set-Cookie) ]] && echo sin || echo con) "
done
nuevo_jar
req "$J_NUEVO" GET /
B9+="$CODE:$( [[ -n $(header Set-Cookie) ]] && echo con || echo sin)"
check B.9 "404, 405, estático e id inválido sin Set-Cookie; GET / sí crea sesión (M-5)" \
  "404:sin 405:sin 200:sin 404:sin 200:con" "$B9"

seccion "S — Logout"
if ((ADMIN_OK)) && [[ -n $T_ADM ]]; then
  post "$J_ADM" /admin/logout
  CODE_SIN=$CODE
  req "$J_ADM" GET /admin
  check S.7 "POST /admin/logout sin CSRF → 419 y la sesión sigue" "419 200" "$CODE_SIN $CODE"
  post "$J_ADM" /admin/logout "$CSRF_FIELD=$T_ADM"
  CODE_OUT=$CODE
  req "$J_ADM" GET /admin
  check S.8 "POST /admin/logout con CSRF → 302; después /admin → login" "302 302 /admin/login" "$CODE_OUT $CODE $(loc)"
else
  skip "sin sesión de admin o token" S.7 S.8
fi

# retry_ok → "sí" si el último response trae Retry-After entero en [1, VENTANA_SEG]
retry_ok() {
  local ra; ra=$(header Retry-After)
  [[ $ra =~ ^[0-9]+$ ]] && ((ra >= 1 && ra <= VENTANA_SEG)) && echo sí || echo no
}

seccion "F3 — Seguimiento público (F-3, D-63)"
# Alta propia → número y código en /gracias → POST /seguimiento. Los intentos fallidos cuentan en
# el límite propio del seguimiento (SEG_MAX por IP en 15 min); al final se agota a propósito.
J_F3="$TMP_DIR/f3.cookies"; : > "$J_F3"
req "$J_F3" GET /
T_F3=$(csrf_de "$BODY")
F3_DESC="Descripción privada $MARCA-f3"
post "$J_F3" /solicitudes "$CSRF_FIELD=$T_F3" "$F_NOMBRE=Seguimiento Prueba" "$F_EMAIL=seguimiento-$MARCA@example.com" \
  "$F_ASUNTO=Seguimiento $MARCA-f3" "$F_DESC=$F3_DESC"
F3_ALTA="$CODE $(loc)"
req "$J_F3" GET /gracias
F3_NUM=$(grep -oE '#[0-9]+' "$BODY" | head -1 | tr -d '#')
F3_COD=$(grep -oE "$RE_CODIGO" "$BODY" | head -1)
F3_COD_LIMPIO=$(tr -d -- '- ' <<< "$F3_COD")
check F3.1 "alta → /gracias muestra el número y un código de seguimiento legible (≥ 26 caracteres base32)" \
  "302 /gracias 200 sí sí" \
  "$F3_ALTA $CODE $([[ -n $F3_NUM ]] && echo sí || echo no) $([[ ${#F3_COD_LIMPIO} -ge 26 ]] && echo sí || echo no)"
req "$J_F3" GET /gracias
check F3.2 "el código se muestra una sola vez (recargar /gracias no lo vuelve a mostrar)" "no" \
  "$([[ -n $F3_COD ]] && hasF "$F3_COD" || echo no)"

# El admin cambia el estado para que el seguimiento muestre historial
J_F3A="$TMP_DIR/f3admin.cookies"; : > "$J_F3A"
req "$J_F3A" GET /admin/login
post "$J_F3A" /admin/login "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
if [[ -n $F3_NUM ]]; then
  req "$J_F3A" GET "/admin/solicitudes/$F3_NUM"
  post "$J_F3A" "/admin/solicitudes/$F3_NUM/estado" "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_ESTADO=en_proceso"
fi

seg() { # seg <número> <código> → POST /seguimiento con sesión propia
  req "$J_F3" GET /seguimiento
  post "$J_F3" /seguimiento "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_SEG_NUM=$1" "$F_SEG_COD=$2"
}
if [[ -n $F3_NUM && -n $F3_COD ]]; then
  req "$J_F3" GET /seguimiento
  check F3.3 "GET /seguimiento → 200 con formulario y token CSRF" "200 sí" "$CODE $([[ -n $(csrf_de "$BODY") ]] && echo sí || echo no)"
  seg "$F3_NUM" "$F3_COD"
  check F3.4 "número + código correctos → 200 en la respuesta del POST (sin redirect): asunto, estado y historial" \
    "200 - sí sí sí" \
    "$CODE $( [[ -z $(header Location) ]] && echo - || echo con-location) $(hasF "Seguimiento $MARCA-f3") $(has 'En proceso') $(has 'Pendiente')"
  check F3.5 "el resultado no muestra email, descripción ni nombre del admin" "no no no" \
    "$(hasF "seguimiento-$MARCA@example.com") $(hasF "$F3_DESC") $(hasF 'Administrador Demo')"
  F3_VAR=$(tr '[:upper:]' '[:lower:]' <<< "$F3_COD")
  seg "$F3_NUM" "$F3_VAR"; N1=$CODE
  seg "$F3_NUM" "$F3_COD_LIMPIO"; N2=$CODE
  seg "$F3_NUM" "  $(sed 's/-/ /g' <<< "$F3_COD")  "; N3=$CODE
  check F3.6 "normalización: minúsculas, sin guiones y con espacios → 200" "200 200 200" "$N1 $N2 $N3"
  # Fallidos: todos el mismo 422 con el mismo mensaje (no distingue los casos)
  F3_MAL=""
  seg "$F3_NUM" "AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AA"; F3_MAL+="$CODE:$(has "$RE_SEG_ERROR") "
  seg "$ID_INEXISTENTE" "$F3_COD"; F3_MAL+="$CODE:$(has "$RE_SEG_ERROR") "
  seg 1 "$F3_COD"; F3_MAL+="$CODE:$(has "$RE_SEG_ERROR")"
  check F3.7 "código incorrecto, número inexistente y número sin código (seed) → mismo 422 «Número o código incorrectos.»" \
    "422:sí 422:sí 422:sí" "$F3_MAL"
  check F3.8 "el código nunca viaja en una URL (ningún Location ni el action del form lo contienen)" "no" \
    "$(grep -rlF -- "$F3_COD_LIMPIO" "$TMP_DIR"/*.hdrs 2>/dev/null | head -1 | grep -q . && echo sí || echo no)"
  # Agotar el límite: ya hubo 3 fallidos; SEG_MAX - 3 más todavía dan 422 y el siguiente, 429
  F3_LIM=""
  for ((i = 4; i <= SEG_MAX; i++)); do seg "$F3_NUM" "BBBB-BBBB-BBBB-BBBB-BBBB-BBBB-B$((i % 10))"; F3_LIM+="${CODE:0:1}"; done
  seg "$F3_NUM" "CCCC-CCCC-CCCC-CCCC-CCCC-CCCC-CC"
  check F3.9 "límite de fallidos: el intento SEG_MAX+1 → 429 con Retry-After" \
    "$(printf '4%.0s' $(seq 4 "$SEG_MAX")) 429 sí" "$F3_LIM $CODE $(retry_ok)"
  seg "$F3_NUM" "$F3_COD"
  check F3.10 "bloqueado, ni el código correcto se verifica → 429" "429" "$CODE"
else
  skip "sin número o código en /gracias" F3.3 F3.4 F3.5 F3.6 F3.7 F3.8 F3.9 F3.10
fi

seccion "HP — Honeypot del formulario (F-1, D-63)"
J_HP="$TMP_DIR/hp.cookies"; : > "$J_HP"
req "$J_HP" GET /
check HP.1 "el formulario trae el campo trampa oculto (div.hp aria-hidden, tabindex -1, autocomplete off)" "sí sí sí" \
  "$(has 'class="hp"[^>]*aria-hidden="true"|aria-hidden="true"[^>]*class="hp"') $(has "name=\"$F_HONEYPOT\"[^>]*tabindex=\"-1\"|tabindex=\"-1\"[^>]*name=\"$F_HONEYPOT\"") $(has "name=\"$F_HONEYPOT\"[^>]*autocomplete=\"off\"|autocomplete=\"off\"[^>]*name=\"$F_HONEYPOT\"")"
post "$J_HP" /solicitudes "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_NOMBRE=Bot" "$F_EMAIL=bot@example.com" \
  "$F_ASUNTO=Honeypot $MARCA-hp" "$F_DESC=spam" "$F_HONEYPOT=https://spam.example"
HP_POST="$CODE $(loc)"
req "$J_HP" GET /gracias
check HP.2 "honeypot completo → 302 a /gracias genérica (sin número, sin «#», sin código)" "302 /gracias 200 sí no no" \
  "$HP_POST $CODE $(has 'Recibimos tu solicitud') $(has '#[0-9]+') $(has "$RE_CODIGO")"
req "$J_F3A" GET "/admin?q=$(urlenc "$MARCA-hp")"
check HP.3 "honeypot: no se guarda nada (búsqueda de la marca en el panel → 0)" "200 sí" "$CODE $(has '(^|[^0-9])0 resultados')"

seccion "PW — Cambio de contraseña del admin (F-5, D-63)"
J_PW_A="$TMP_DIR/pwa.cookies" J_PW_B="$TMP_DIR/pwb.cookies" J_PW_C="$TMP_DIR/pwc.cookies"
: > "$J_PW_A"; : > "$J_PW_B"; : > "$J_PW_C"
nuevo_jar
req "$J_NUEVO" GET /admin/cuenta/password
F5_ANON="$CODE $(loc)"
post "$J_NUEVO" /admin/cuenta/password "$F_PW_ACTUAL=x" "$F_PW_NUEVA=y" "$F_PW_CONF=y"
check PW.1 "sin sesión: GET → 302 a login; POST → 401" "302 /admin/login 401" "$F5_ANON $CODE"
for j in "$J_PW_A" "$J_PW_B"; do
  req "$j" GET /admin/login
  post "$j" /admin/login "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
done
req "$J_PW_A" GET /admin
F5_LINK=$(has 'href="/admin/cuenta/password"')
req "$J_PW_A" GET /admin/cuenta/password
T_PW=$(csrf_de "$BODY")
check PW.2 "con sesión: link «Cambiar contraseña» en el panel y GET del formulario → 200 con CSRF" "sí 200 sí" \
  "$F5_LINK $CODE $([[ -n $T_PW ]] && echo sí || echo no)"
PW_NUEVA="Nueva-$MARCA-xyz"
pw() { # pw <actual> <nueva> <confirmación> (valores url-encodeados por curl)
  post "$J_PW_A" /admin/cuenta/password "$CSRF_FIELD=$T_PW" "$F_PW_ACTUAL=$1" "$F_PW_NUEVA=$2" "$F_PW_CONF=$3"
}
F5_ERR=""
pw "incorrecta-$MARCA" "$PW_NUEVA" "$PW_NUEVA"; F5_ERR+="$CODE:$(hasF "incorrecta-$MARCA")$(hasF "$PW_NUEVA") "
pw "$ADMIN_PASSWORD" "Corta-11ch!" "Corta-11ch!"; F5_ERR+="$CODE:$(hasF 'Corta-11ch!') "
pw "$ADMIN_PASSWORD" "$ADMIN_PASSWORD" "$ADMIN_PASSWORD"; F5_ERR+="$CODE "
pw "$ADMIN_PASSWORD" "$PW_NUEVA" "$PW_NUEVA-distinta"; F5_ERR+="$CODE"
check PW.3 "actual incorrecta, nueva < 12, igual a la actual y confirmación distinta → 422, sin re-mostrar contraseñas" \
  "422:nono 422:no 422 422" "$F5_ERR"
req "$J_PW_A" POST /admin/cuenta/password --data-urlencode "$CSRF_FIELD=$T_PW" \
  --data-urlencode "$F_PW_ACTUAL=$ADMIN_PASSWORD" --data "$F_PW_NUEVA=Nueva-con-nulo%00xx&$F_PW_CONF=Nueva-con-nulo%00xx"
F5_NUL=$CODE
req "$J_PW_A" POST /admin/cuenta/password --data-urlencode "$CSRF_FIELD=$T_PW" \
  --data-urlencode "$F_PW_ACTUAL=$ADMIN_PASSWORD" --data-urlencode "$F_PW_NUEVA=$(printf 'x%.0s' $(seq 1 73))" \
  --data-urlencode "$F_PW_CONF=$(printf 'x%.0s' $(seq 1 73))"
check PW.4 "nueva con NUL o de más de 72 bytes → 422" "422 422" "$F5_NUL $CODE"
pw "$ADMIN_PASSWORD" "$PW_NUEVA" "$PW_NUEVA"
F5_OK="$CODE $(loc)"
req "$J_PW_A" GET /admin
F5_OK+=" $CODE $(has "$RE_PW_OK")"
check PW.5 "cambio correcto → 302 al panel con flash «Contraseña actualizada.»" "302 /admin 200 sí" "$F5_OK"
req "$J_PW_B" GET /admin
F5_B="$CODE $(loc)"
req "$J_PW_A" GET /admin
check PW.6 "la otra sesión (abierta antes del cambio) queda afuera; la que cambió sigue" "302 /admin/login 200" "$F5_B $CODE"
req "$J_PW_C" GET /admin/login
post "$J_PW_C" /admin/login "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=$PW_NUEVA"
check PW.7 "login con la contraseña nueva → 302 /admin" "302 /admin" "$CODE $(loc)"
# Restaurar la contraseña demo para lo que sigue (L.2, L.3 y corridas futuras)
req "$J_PW_A" GET /admin/cuenta/password
T_PW=$(csrf_de "$BODY")
pw "$PW_NUEVA" "$ADMIN_PASSWORD" "$ADMIN_PASSWORD"
check PW.8 "restaurar la contraseña demo → 302" "302 /admin" "$CODE $(loc)"
[[ $CODE == 302 ]] || echo "AVISO: la contraseña del admin demo quedó en «$PW_NUEVA»: reinstalar la DB."

seccion "F1 — Límite de envíos del formulario público (F-1, D-63)"
# Hasta acá la corrida hizo FORM_N envíos que pasaron el CSRF (302/422). Se completan hasta
# FORM_MAX con envíos inválidos (422, no crean filas) y el siguiente tiene que dar 429.
F1_EN_CURSO=1
J_F1="$TMP_DIR/f1.cookies"; : > "$J_F1"
req "$J_F1" GET /
T_F1=$(csrf_de "$BODY")
F1_FALTAN=$((FORM_MAX - FORM_N))
if ((F1_FALTAN < 0)); then
  check F1.1 "FORM_MAX ($FORM_MAX) alcanza para los $FORM_N envíos de la corrida" "sí" "no"
  skip "FORM_MAX menor que los envíos de la corrida" F1.2
else
  F1_PREV=""
  for ((i = 1; i <= F1_FALTAN; i++)); do
    post "$J_F1" /solicitudes "$CSRF_FIELD=$T_F1" "$F_NOMBRE=F1 $i" "$F_EMAIL=no-es-email" "$F_ASUNTO=F1 $MARCA" "$F_DESC=x"
    F1_PREV+="${CODE:0:1}"
  done
  post "$J_F1" /solicitudes "$CSRF_FIELD=$T_F1" "$F_NOMBRE=<b>F1-$MARCA</b>" "$F_EMAIL=f1@example.com" \
    "$F_ASUNTO=Asunto F1 $MARCA" "$F_DESC=x"
  check F1.1 "envío n.º FORM_MAX+1 ($((FORM_MAX + 1))) en la ventana → 429 + Retry-After + «Demasiados envíos»" \
    "$(printf '4%.0s' $(seq 1 "$F1_FALTAN")) 429 sí sí" "$F1_PREV $CODE $(retry_ok) $(has "$RE_ENVIOS")"
  check F1.2 "el 429 re-muestra los valores escapados (role=alert) y nunca crudos" "sí sí no" \
    "$(hasF '&lt;b&gt;F1-') $(has 'role="alert"') $(hasF '<b>F1-')"
fi
F1_EN_CURSO=

seccion "L — Límite de intentos de login (D-38; requiere DB recién reinstalada)"
# Los fallos persisten 15 min en login_intentos: una segunda corrida sin reinstalar encuentra al
# admin demo bloqueado por L.3. Presupuesto por IP (límite 20): fallos válidos de toda la corrida
# = P4.1, P4.2, H.16 + 1 de PW.3 (actual incorrecta, D-63) + 5 de L.1 + 5 de L.3 + hasta 2 de
# L.6 = 16 (los 429 no se registran,
# PLAN §Extra 1 "Precisiones"); P5.1, L.2 y L.7 además borran los fallos del admin. L.4 reutiliza
# la secuencia de L.1 para no gastar 5 más. L.3 bloquea al admin demo: después solo va L.6, que
# justamente necesita al admin bloqueado.

# fallo_login <jar> <token|''> <email> <password> → deja CODE (sin token: no manda el campo)
fallo_login() {
  local jar=$1 tok=$2 email=$3 pass=$4
  if [[ -n $tok ]]; then
    post "$jar" /admin/login "$CSRF_FIELD=$tok" "$F_LOGIN_EMAIL=$email" "$F_LOGIN_PASS=$pass"
  else
    post "$jar" /admin/login "$F_LOGIN_EMAIL=$email" "$F_LOGIN_PASS=$pass"
  fi
}

# L.1 + L.4 — cuenta inexistente propia de la corrida: 4 fallos, 3 POST sin CSRF, 5.º fallo, 6.º
J_LIM="$TMP_DIR/limite.cookies"; : > "$J_LIM"
EMAIL_BLOQ="bloqueo-$MARCA@example.com"
req "$J_LIM" GET /admin/login
T_LIM=$(csrf_de "$BODY")
CODES_FALLO=""
for i in 1 2 3 4; do
  fallo_login "$J_LIM" "$T_LIM" "$EMAIL_BLOQ" "incorrecta-$i"
  CODES_FALLO+="$CODE "
done
[[ $CODES_FALLO == 429* ]] && echo "AVISO: el primer intento ya da 429 — ¿DB sin reinstalar desde otra corrida?"
CODES_419=""
for tok in "" "token-falso-$MARCA" ""; do
  fallo_login "$J_LIM" "$tok" "$EMAIL_BLOQ" "incorrecta-sincsrf"
  CODES_419+="$CODE "
done
fallo_login "$J_LIM" "$T_LIM" "$EMAIL_BLOQ" "incorrecta-5"
CODES_FALLO+="$CODE"
CODE_QUINTO=$CODE
fallo_login "$J_LIM" "$T_LIM" "$EMAIL_BLOQ" "incorrecta-6"
check L.1 "5 fallos (email inexistente) → 401 c/u; 6.º → 429 + Retry-After 1..${VENTANA_SEG} + mensaje" \
  "401 401 401 401 401 429 sí sí" "$CODES_FALLO $CODE $(retry_ok) $(has "$RE_BLOQUEO")"
check L.4 "3 POST sin CSRF entre los fallos → 419 y no cuentan (el 5.º fallo sigue en 401)" \
  "419 419 419 401" "$CODES_419$CODE_QUINTO"

# L.2 — con esa cuenta bloqueada, el admin demo desde la misma IP sigue entrando (límite por cuenta)
nuevo_jar
req "$J_NUEVO" GET /admin/login
post "$J_NUEVO" /admin/login "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_LOGIN_EMAIL=$ADMIN_EMAIL" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
L2="$CODE $(loc)"
req "$J_NUEVO" GET /admin
L2+=" $CODE"
post "$J_NUEVO" /admin/logout "$CSRF_FIELD=$(csrf_de "$BODY")"
L2+=" $CODE"
check L.2 "otra cuenta bloqueada no frena al admin demo de la misma IP: login, /admin y logout" \
  "302 /admin 200 302" "$L2"

# L.7 — la normalización a minúsculas sigue funcionando: ADMIN@EXAMPLE.COM entra (D-41)
nuevo_jar
req "$J_NUEVO" GET /admin/login
post "$J_NUEVO" /admin/login "$CSRF_FIELD=$(csrf_de "$BODY")" \
  "$F_LOGIN_EMAIL=$(tr '[:lower:]' '[:upper:]' <<< "$ADMIN_EMAIL")" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
L7="$CODE $(loc)"
req "$J_NUEVO" GET /admin
L7+=" $CODE"
post "$J_NUEVO" /admin/logout "$CSRF_FIELD=$(csrf_de "$BODY")"
check L.7 "email del admin en mayúsculas + password correcta → entra (302, /admin 200) y sale" \
  "302 /admin 200 302" "$L7 $CODE"

# L.3 — admin demo: 5 fallos y el 6.º con la password CORRECTA → 429 sin verificarla
nuevo_jar
req "$J_NUEVO" GET /admin/login
T_L3=$(csrf_de "$BODY")
CODES_L3=""
for i in 1 2 3 4 5; do
  fallo_login "$J_NUEVO" "$T_L3" "$ADMIN_EMAIL" "incorrecta-L3-$i-$MARCA"
  CODES_L3+="$CODE "
done
fallo_login "$J_NUEVO" "$T_L3" "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
CODES_L3+="$CODE $(retry_ok) $(has "$RE_BLOQUEO")"
req "$J_NUEVO" GET /admin
check L.3 "admin: 5 fallos → 401; 6.º con password correcta → 429 + Retry-After + mensaje; /admin sigue en login" \
  "401 401 401 401 401 429 sí sí 302 /admin/login" "$CODES_L3 $CODE $(loc)"
# L.6 — con el admin bloqueado, variantes con acento no son otra cuenta (D-41): con la password
# correcta nunca autentican. Hoy la colación unicode_ci las resolvía al mismo admin con otro contador.
L6=""
for variante in "ádmin@example.com" "ÁDMIN@example.com"; do
  nuevo_jar
  req "$J_NUEVO" GET /admin/login
  post "$J_NUEVO" /admin/login "$CSRF_FIELD=$(csrf_de "$BODY")" "$F_LOGIN_EMAIL=$variante" "$F_LOGIN_PASS=$ADMIN_PASSWORD"
  L6+="$CODE "
  req "$J_NUEVO" GET /admin
  L6+="$CODE $(loc) "
done
check L.6 "admin bloqueado: ádmin@ / ÁDMIN@ + password correcta → 401|429 y /admin sigue en login" \
  "(401|429) 302 /admin/login (401|429) 302 /admin/login" "${L6% }"
echo "Nota: el admin demo queda bloqueado ${VENTANA_SEG}s desde esta IP; reinstalar la DB (php scripts/instalar-db.php)."

TOTAL=$((PASS + FAIL + SKIP))
printf '\nResumen: %d casos — %d PASS, %d FAIL, %d SKIP\n' "$TOTAL" "$PASS" "$FAIL" "$SKIP"
if ((EVIDENCIA)); then echo "Evidencia: ${EVIDENCIA_FILE#"$SCRIPT_DIR/../"}"; fi
((FAIL == 0 && SKIP == 0))
