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
RE_FUGA='SQLSTATE|PDOException|Stack trace|Fatal error|on line [0-9]+|You have an error in your SQL'
ID_INEXISTENTE=999999999

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
}
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
      "$CODE $(hasF "Valida $MARCA") $(hasF ana@example.com) $(hasF "Descripción de prueba $MARCA")"
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

TOTAL=$((PASS + FAIL + SKIP))
printf '\nResumen: %d casos — %d PASS, %d FAIL, %d SKIP\n' "$TOTAL" "$PASS" "$FAIL" "$SKIP"
if ((EVIDENCIA)); then echo "Evidencia: ${EVIDENCIA_FILE#"$SCRIPT_DIR/../"}"; fi
((FAIL == 0 && SKIP == 0))
