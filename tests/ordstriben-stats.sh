#!/usr/bin/env bash
# Tester ordstriben/stats/index.php lokalt med PHPs indbyggede server. Kør fra repoets rod: tests/ordstriben-stats.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
PORT=$((20000 + RANDOM % 20000))
KEY="testnoegle-$RANDOM"
mkdir -p "$WORK/data/stats" "$WORK/web/ordstriben/stats"
cp "$ROOT/ordstriben/stats/index.php" "$WORK/web/ordstriben/stats/index.php"
php -r 'file_put_contents($argv[1], "<?php return " . var_export(["api_hash" => hash("sha256", $argv[2])], true) . ";\n");' "$WORK/data/config.php" "$KEY"

ORDSTRIBEN_DATA="$WORK/data" ORDSTATS_MAX_POST_HOUR=12 ORDSTATS_MAX_NEW_DAY=6 ORDSTATS_MAX_BADKEY_HOUR=3 ORDSTATS_SUMMARY_TTL=1 \
  php -S "127.0.0.1:$PORT" -t "$WORK/web" >/dev/null 2>&1 &
PID=$!
cleanup() { kill "$PID" 2>/dev/null || true; }
trap cleanup EXIT
for _ in $(seq 50); do curl -s "http://127.0.0.1:$PORT/" >/dev/null 2>&1 && break; sleep 0.1; done

URL="http://127.0.0.1:$PORT/ordstriben/stats/"
FAIL=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FEJL $1: ventede $2, fik $3"; FAIL=1; fi; }
post() { curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data-binary "$1" "$URL"; }
NOW=$(date -u +%Y-%m-%dT%H:%M:%SZ)
uid() { printf '%08x-0000-4000-8000-%012x' "$1" "$1"; }
ev() { echo "{\"t\":\"$NOW\",\"kind\":\"$1\",\"lang\":\"da\",\"engine\":\"whisper-small-da\",\"ok\":$2,\"error\":null,\"audio_ms\":60000,\"wait_ms\":2000,\"words\":100,\"chars\":600,\"mode\":\"DIRECT\",\"fallback\":false}"; }
batch() { echo "{\"user\":\"$(uid "$1")\",\"version\":\"0.1.9\",\"events\":[$(ev dictation true),$(ev file true)]}"; }
summ() { sleep 1.2; curl -s "$URL?summary"; }
js() { php -r '$d=json_decode($argv[1],true); $v=$argv[2]; foreach(explode(".",$v) as $k) $d=$d[$k]??null; echo json_encode($d);' "$1" "$2"; }

check "gyldigt kald giver 204" 204 "$(post "$(batch 1)")"
check "samme kald igen giver 204" 204 "$(post "$(batch 1)")"
check "forkert content type giver 415" 415 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: text/plain' --data-binary "$(batch 1)" "$URL")"
check "ugyldig json giver 400" 400 "$(post '{nej')"
check "ekstra felt i hændelse giver 400" 400 "$(post "{\"user\":\"$(uid 1)\",\"version\":\"0.1.9\",\"events\":[{\"t\":\"$NOW\",\"kind\":\"dictation\",\"lang\":\"da\",\"engine\":\"x\",\"ok\":true,\"error\":null,\"audio_ms\":1,\"wait_ms\":1,\"words\":1,\"chars\":1,\"mode\":\"DIRECT\",\"fallback\":false,\"tekst\":\"hej\"}]}")"
check "app navn giver 400" 400 "$(post "{\"user\":\"$(uid 1)\",\"version\":\"0.1.9\",\"app\":\"com.example\",\"events\":[$(ev dictation true)]}")"
check "ukendt kind giver 400" 400 "$(post "{\"user\":\"$(uid 1)\",\"version\":\"0.1.9\",\"events\":[$(ev video true)]}")"
check "ikke uuid giver 400" 400 "$(post '{"user":"abc","version":"0.1.9","events":[]}')"
check "gammel hændelse giver 400" 400 "$(post "{\"user\":\"$(uid 1)\",\"version\":\"0.1.9\",\"events\":[$(ev dictation true | sed 's/"t":"[^"]*"/"t":"2020-01-01T00:00:00Z"/')]}")"
BIG=$(php -r 'echo str_repeat(" ", 70000);')
check "over 64 KB giver 413" 413 "$(post "$BIG")"

S=$(summ)
check "under 5 brugere: ord skjules" null "$(js "$S" words)"
check "under 5 brugere: brugere i alt skjules" null "$(js "$S" users.total)"
check "under 5 brugere: sprog skjules" null "$(js "$S" languages)"

for n in 2 3 4 5; do post "$(batch $n)" >/dev/null; done
S=$(summ)
check "5 brugere: brugere i alt" 5 "$(js "$S" users.total)"
check "5 brugere: brugere i dag" 5 "$(js "$S" users.today)"
check "dobbelt kald tæller kun én gang: ord" 1000 "$(js "$S" words)"
check "dikteringer" 10 "$(js "$S" dictations)"
check "tastetryk sparet" 6000 "$(js "$S" keystrokes_saved)"
check "ord pr. minut" 100 "$(js "$S" words_per_minute)"
check "sparet tid i minutter" 16 "$(js "$S" minutes_saved)"
check "ventetid" 2000 "$(js "$S" avg_wait_ms)"
check "andel lydfiler" 0.5 "$(js "$S" file_share)"
check "sprog" '{"da":5}' "$(js "$S" languages)"
check "summary må hentes fra andre sider" 1 "$(curl -s -D - -o /dev/null "$URL?summary" | grep -ci 'access-control-allow-origin: \*')"

check "GET uden nøgle giver 401" 401 "$(curl -s -o /dev/null -w '%{http_code}' "$URL")"
check "GET med nøgle giver 5 brugere" 5 "$(curl -s -H "X-Api-Key: $KEY" "$URL" | php -r 'echo count(json_decode(stream_get_contents(STDIN),true)["users"]);')"
check "ingen fil i webroden" 0 "$(find "$WORK/web" -name '*.json' | wc -l | tr -d ' ')"
check "ingen IP adresse gemt" 0 "$(grep -rl '127.0.0.1' "$WORK/data" | wc -l | tr -d ' ')"

check "DELETE med ugyldigt id giver 400" 400 "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$URL?user=abc")"
check "DELETE giver 204" 204 "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$URL?user=$(uid 5)")"
S=$(summ)
check "efter DELETE er der under 5 igen" null "$(js "$S" users.total)"

check "ny bruger over dagsgrænsen giver 429" 429 "$(post "$(batch 6)" >/dev/null; post "$(batch 7)")"
check "for mange forkerte nøgler giver 429" 429 "$(for i in 1 2 3; do curl -s -o /dev/null -H 'X-Api-Key: forkert' "$URL"; done; curl -s -o /dev/null -w '%{http_code}' -H 'X-Api-Key: forkert' "$URL")"
check "nødbremsen giver 503" 503 "$(touch "$WORK/data/stats/lukket"; post "$(batch 1)")"

exit $FAIL
