#!/bin/sh
# Teste HTTP do endpoint de entrega, com curl contra o servidor embutido do PHP.
#
# Cobre: 200 com cabecalhos corretos, 304 por ETag e por Last-Modified,
# HEAD sem corpo, 404 de canal vazio e de canal inexistente, e 405.
#
# Uso (nao precisa de PHP instalado na maquina):
#   docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh tests/endpoint/run.sh
#
# @license GPL-3.0-or-later
set -e

PORT=8899
BASE="http://127.0.0.1:${PORT}"
STATE="$(php -r 'echo sys_get_temp_dir();')/wallpaper-endpoint-test"
FAIL=0

rm -rf "$STATE"
mkdir -p "$STATE/wallpaper"

# PNG real de 1x1 em bytes literais: a entrega nao usa GD, so o upload usa.
STATE="$STATE" php -r '
$png = base64_decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
);
file_put_contents(getenv("STATE") . "/wallpaper/producao.bin", $png);
'

# Estado: "producao" com imagem, "piloto" vazio.
STATE="$STATE" php -r '
$dir  = getenv("STATE");
$file = $dir . "/wallpaper/producao.bin";
$state = [
    "channels" => [
        "producao" => [
            "channel"  => "producao",
            "mime"     => "image/png",
            "filename" => "teste.png",
            "etag"     => hash_file("sha256", $file),
        ],
        "piloto" => ["channel" => "piloto"],
    ],
    "config" => ["cache_ttl" => "3600"],
];
file_put_contents($dir . "/state.json", json_encode($state));
'

php -S "127.0.0.1:${PORT}" tests/endpoint/router.php >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
sleep 1

check() {
    if [ "$2" = "$3" ]; then
        printf '  ok   %s\n' "$1"
    else
        printf '  FALHA %s: esperado [%s], obtido [%s]\n' "$1" "$3" "$2"
        FAIL=$((FAIL + 1))
    fi
}

echo "== 200 na rota com extensao =="
H=$(curl -sS -D - -o /dev/null "$BASE/plugins/wallpaper/producao.png")
check "status 200"           "$(printf '%s' "$H" | head -1 | tr -d '\r' | cut -d' ' -f2)" "200"
check "content-type"         "$(printf '%s' "$H" | grep -i '^content-type:' | tr -d '\r' | cut -d' ' -f2)" "image/png"
check "nosniff"              "$(printf '%s' "$H" | grep -i '^x-content-type-options:' | tr -d '\r' | cut -d' ' -f2)" "nosniff"
check "disposition inline"   "$(printf '%s' "$H" | grep -ci '^content-disposition: inline')" "1"
check "cache-control"        "$(printf '%s' "$H" | grep -ci '^cache-control: public, max-age=3600')" "1"
check "tem etag"             "$(printf '%s' "$H" | grep -ci '^etag:')" "1"
# Um segundo Cache-Control (o do session.cache_limiter do PHP) faz o Azure Front
# Door desistir de cachear, e o cookie de sessao nao tem o que fazer num endpoint
# anonimo. Os dois precisam ficar de fora.
check "cache-control unico"  "$(printf '%s' "$H" | grep -ci '^cache-control:')" "1"
check "sem set-cookie"       "$(printf '%s' "$H" | grep -ci '^set-cookie:')" "0"
check "tem last-modified"    "$(printf '%s' "$H" | grep -ci '^last-modified:')" "1"
check "content-length"       "$(printf '%s' "$H" | grep -i '^content-length:' | tr -d '\r' | cut -d' ' -f2)" "$(wc -c < "$STATE/wallpaper/producao.bin" | tr -d ' ')"

echo "== corpo entregue =="
curl -sS -o /tmp/wp-out.png "$BASE/plugins/wallpaper/producao.png"
check "bytes identicos" "$(cmp -s /tmp/wp-out.png "$STATE/wallpaper/producao.bin" && echo iguais || echo diferentes)" "iguais"

echo "== 304 condicional =="
ETAG=$(printf '%s' "$H" | grep -i '^etag:' | tr -d '\r' | cut -d' ' -f2)
LMOD=$(printf '%s' "$H" | grep -i '^last-modified:' | sed 's/^[Ll]ast-[Mm]odified: //' | tr -d '\r')
check "If-None-Match -> 304"      "$(curl -sS -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$BASE/plugins/wallpaper/producao.png")" "304"
check "If-None-Match W/ -> 304"   "$(curl -sS -o /dev/null -w '%{http_code}' -H "If-None-Match: W/$ETAG" "$BASE/plugins/wallpaper/producao.png")" "304"
check "If-Modified-Since -> 304"  "$(curl -sS -o /dev/null -w '%{http_code}' -H "If-Modified-Since: $LMOD" "$BASE/plugins/wallpaper/producao.png")" "304"
check "ETag divergente -> 200"    "$(curl -sS -o /dev/null -w '%{http_code}' -H 'If-None-Match: "outro"' "$BASE/plugins/wallpaper/producao.png")" "200"
check "304 sem corpo"             "$(curl -sS -o /dev/null -w '%{size_download}' -H "If-None-Match: $ETAG" "$BASE/plugins/wallpaper/producao.png")" "0"

echo "== HEAD =="
HH=$(curl -sS -I -o /dev/null -D - "$BASE/plugins/wallpaper/producao.png")
check "HEAD status 200"      "$(printf '%s' "$HH" | head -1 | tr -d '\r' | cut -d' ' -f2)" "200"
check "HEAD sem corpo"       "$(curl -sS -I -o /dev/null -w '%{size_download}' "$BASE/plugins/wallpaper/producao.png")" "0"
check "HEAD com length"      "$(printf '%s' "$HH" | grep -i '^content-length:' | tr -d '\r' | cut -d' ' -f2)" "$(wc -c < "$STATE/wallpaper/producao.bin" | tr -d ' ')"
check "HEAD com content-type" "$(printf '%s' "$HH" | grep -i '^content-type:' | tr -d '\r' | cut -d' ' -f2)" "image/png"

echo "== 404 e 405 =="
check "canal vazio -> 404"        "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/plugins/wallpaper/piloto.png")" "404"
check "canal inexistente -> 404"  "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/plugins/wallpaper/naoexiste.png")" "404"
check "metodo POST -> 405"        "$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$BASE/plugins/wallpaper/producao.png")" "405"

echo "== rota legada =="
check "legada 200"           "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/plugins/wallpaper/front/image.php?c=producao")" "200"
check "legada canal invalido" "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/plugins/wallpaper/front/image.php?c=../etc/passwd")" "404"

echo ""
if [ "$FAIL" -eq 0 ]; then
    echo "todos os testes de endpoint passaram"
else
    echo "$FAIL falha(s)"
    exit 1
fi
