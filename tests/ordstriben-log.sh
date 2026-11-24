#!/usr/bin/env bash
# Tester ordstriben/log/index.php lokalt med PHPs indbyggede server. Kør fra repoets rod: tests/ordstriben-log.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
PORT=$((20000 + RANDOM % 20000))
KEY="testnoegle-$RANDOM"
mkdir -p "$WORK/data/log" "$WORK/web/ordstriben/log"
cp "$ROOT/ordstriben/log/index.php" "$WORK/web/ordstriben/log/index.php"
php -r 'file_put_contents($argv[1], "<?php return " . var_export(["api_hash" => hash("sha256", $argv[2])], true) . ";\n");' "$WORK/data/config.php" "$KEY"

ORDSTRIBEN_DATA="$WORK/data" ORDLOG_MAX_POST_HOUR=6 ORDLOG_MAX_NEW_DAY=3 ORDLOG_MAX_BADKEY_HOUR=3 \
  php -S "127.0.0.1:$PORT" -t "$WORK/web" >/dev/null 2>&1 &
PID=$!
cleanup() { kill "$PID" 2>/dev/null || true; }
trap cleanup EXIT
for _ in $(seq 50); do curl -s "http://127.0.0.1:$PORT/" >/dev/null 2>&1 && break; sleep 0.1; done

URL="http://127.0.0.1:$PORT/ordstriben/log/"
ID1="0f8fad5b-d9cb-469f-a165-70867728950e"
ID2="7c9e6679-7425-40de-944b-e07fc1f90ae7"
FAIL=0

check() { # navn forventet faktisk
  if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FEJL $1: ventede $2, fik $3"; FAIL=1; fi
}
post() { curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data-binary "$1" "$URL"; }

GOOD="{\"install\":\"$ID1\",\"version\":\"0.1.3\",\"lines\":[\"2026-11-24 20:00:00|INSERT|com.example.app|android.widget.EditText|ok\"]}"
check "gyldigt kald giver 204" 204 "$(post "$GOOD")"
check "andet kald samme id giver 204" 204 "$(post "$GOOD")"
check "forkert content type giver 415" 415 "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: text/plain' --data-binary "$GOOD" "$URL")"
check "ugyldig json giver 400" 400 "$(post '{nej')"
check "ekstra felt giver 400" 400 "$(post "{\"install\":\"$ID1\",\"version\":\"0.1.3\",\"lines\":[\"2026-11-24 20:00:00|A|x\"],\"tekst\":\"hej\"}")"
check "ikke uuid giver 400" 400 "$(post '{"install":"abc","version":"0.1.3","lines":["2026-11-24 20:00:00|A|x"]}')"
check "forkert linjeformat giver 400" 400 "$(post "{\"install\":\"$ID1\",\"version\":\"0.1.3\",\"lines\":[\"hej med dig\"]}")"
LONG=$(printf 'x%.0s' $(seq 300))
check "for lang linje giver 400" 400 "$(post "{\"install\":\"$ID1\",\"version\":\"0.1.3\",\"lines\":[\"2026-11-24 20:00:00|A|$LONG\"]}")"
MANY=$(php -r 'echo json_encode(["install"=>$argv[1],"version"=>"0.1.3","lines"=>array_fill(0,501,"2026-11-24 20:00:00|A|x")]);' "$ID1")
check "501 linjer giver 400" 400 "$(post "$MANY")"
BIG=$(php -r 'echo str_repeat(" ", 70000);')
check "over 64 KB giver 413" 413 "$(post "$BIG")"
check "tomme linjer giver 400" 400 "$(post "{\"install\":\"$ID1\",\"version\":\"0.1.3\",\"lines\":[]}")"
check "PUT giver 405" 405 "$(curl -s -o /dev/null -w '%{http_code}' -X PUT "$URL")"

check "GET uden nøgle giver 401" 401 "$(curl -s -o /dev/null -w '%{http_code}' "$URL")"
check "GET med forkert nøgle giver 401" 401 "$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Api-Key: forkert' "$URL")"
BODY=$(curl -s -H "X-Api-Key: $KEY" "$URL")
check "GET giver to kald" 2 "$(php -r '$d=json_decode($argv[1],true); echo count($d["installs"]);' "$BODY")"
check "GET har install, version, received og lines" "$ID1|0.1.3|1" "$(php -r '$d=json_decode($argv[1],true)["installs"][0]; echo $d["install"]."|".$d["version"]."|".count($d["lines"])."".(isset($d["received"])?"":"X");' "$BODY")"
check "since i fremtiden giver tom liste" 0 "$(curl -s -H "X-Api-Key: $KEY" "$URL?since=2099-01-01T00:00:00Z" | php -r 'echo count(json_decode(stream_get_contents(STDIN),true)["installs"]);')"
check "ugyldig since giver 400" 400 "$(curl -s -o /dev/null -w '%{http_code}' -H "X-Api-Key: $KEY" "$URL?since=hej")"

check "ingen fil i webroden" 0 "$(find "$WORK/web" -name '*.json' | wc -l | tr -d ' ')"
check "ingen IP adresse gemt" 0 "$(grep -rl '127.0.0.1' "$WORK/data" | wc -l | tr -d ' ')"

check "DELETE med ugyldigt id giver 400" 400 "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$URL?install=abc")"
check "DELETE giver 204" 204 "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$URL?install=$ID1")"
check "efter DELETE er der intet" 0 "$(curl -s -H "X-Api-Key: $KEY" "$URL" | php -r 'echo count(json_decode(stream_get_contents(STDIN),true)["installs"]);')"
check "DELETE af ukendt id giver 204" 204 "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "$URL?install=$ID2")"

# Grænsen er 6 gyldige kald i timen. To er brugt, så 4 mere går igennem, og det næste afvises
for i in 1 2 3 4; do post "$GOOD" >/dev/null; done
check "kald over grænsen giver 429" 429 "$(post "$GOOD")"
check "for mange forkerte nøgler giver 429" 429 "$(for i in 1 2 3; do curl -s -o /dev/null -H 'X-Api-Key: forkert' "$URL"; done; curl -s -o /dev/null -w '%{http_code}' -H 'X-Api-Key: forkert' "$URL")"

# Oprydning: et kald ældre end 30 dage slettes ved næste GET
F=$(ls "$WORK/data/log"/i_*.json | head -1)
php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["batches"] as &$b) $b["received"]="2020-01-01T00:00:00Z"; file_put_contents($argv[1], json_encode($d));' "$F"
check "gamle kald ryddes" 0 "$(curl -s -H "X-Api-Key: $KEY" "$URL" | php -r 'echo count(json_decode(stream_get_contents(STDIN),true)["installs"]);')"
check "tom fil slettes" 0 "$(ls "$WORK/data/log"/i_*.json 2>/dev/null | wc -l | tr -d ' ')"

check "nødbremsen giver 503" 503 "$(touch "$WORK/data/log/lukket"; post "$GOOD")"

exit $FAIL
