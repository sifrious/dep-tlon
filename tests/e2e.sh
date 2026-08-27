#!/usr/bin/env bash
set -u
cd "$(dirname "$0")/.."
export TLON_STATE="$PWD/state.json"
DB=/tmp/library.sqlite
DB2=/tmp/replica.sqlite
rm -f "$TLON_STATE"
php tests/fixture.php "$DB" >/dev/null
php tests/fixture.php "$DB2" >/dev/null

pass=0; fail=0
demo() {
  local id="$1"; local desc="$2"; local expect="$3"; shift 3
  local out; out="$("$@" 2>&1)"
  if echo "$out" | grep -qF -- "$expect"; then
    printf '%-5s ok   %s\n' "$id" "$desc"; pass=$((pass+1))
  else
    printf '%-5s FAIL %s\n' "$id" "$desc"; echo "$out" | sed 's/^/        /'; fail=$((fail+1))
  fi
}

demo R1  "registering a source succeeds"            "source registered" ./tlon register library sqlite "$DB"
demo R1  "a registered source is listed"            "library"     ./tlon sources
demo R30 "mysql and postgresql also register"       "registered"  ./tlon register other postgresql "host=x"
demo R30 "an unknown engine is refused"             "unknown_engine" ./tlon register bad oracle dsn
demo R2  "reachability is reported"                 "reachable"   ./tlon reach library
demo R5  "tables and views are told apart"          "on_loan    view"  ./tlon inspect library
demo R6  "columns keep order, type, default"        "3  shelf"    ./tlon show library copies
demo R7  "composite keys are recorded"              "loans"       ./tlon show library loans
demo R8  "references record on delete"              "CASCADE"     ./tlon export
demo R9  "unique indexes are recorded"              "borrowers_email" ./tlon export
demo R12 "unavailable is recorded, not empty"       "row_estimate" grep -o row_estimate "$TLON_STATE"
demo R18 "a note is kept"                           "note added"  ./tlon note library borrowers - the people who borrow
demo R18 "a column note is kept"                    "note added"  ./tlon note library borrowers email used for holds
demo R21 "sources list their last inspection"       "library"     ./tlon sources
demo R24 "search finds tables and columns"          "borrowers"   ./tlon search borrow
./tlon register replica sqlite "$DB2" >/dev/null
./tlon inspect replica >/dev/null
demo R25 "two sources stay separate"                "replica"     ./tlon search borrowers

php -r '$p=new PDO("sqlite:/tmp/library.sqlite"); $p->exec("DROP INDEX borrowers_email"); $p->exec("ALTER TABLE borrowers DROP COLUMN email"); $p->exec("CREATE TABLE fines (id INTEGER PRIMARY KEY, amount TEXT)");'

demo R13 "re-inspection does not duplicate"         "borrowers  table  2"  ./tlon inspect library
demo R16 "the change report names added"            "fines"       ./tlon changes library
demo R14 "a dropped column shows in the report"     "email"       ./tlon changes library
demo R19 "a surviving note is still attached"       "the people who borrow" ./tlon notes library
demo R20 "a note on a dropped column reads absent"  "absent"      ./tlon notes library
demo R23 "reverse references are reported"          "loans"       ./tlon show library copies
demo R26 "the export carries the catalog"           '"source": "library"' ./tlon export
demo R3  "the export never leaks the connection"    "no-secret"   bash -c './tlon export | grep -q library.sqlite && echo leaked || echo no-secret'
demo R17 "a failed inspection is reported"          "inspection failed" bash -c './tlon register broken sqlite /nope/x.sqlite >/dev/null; ./tlon inspect broken'
demo R17 "a failure does not make things absent"    "(nothing)"   ./tlon absent library
demo R4  "removing a source reports what went"      "source removed" ./tlon remove replica
demo R31 "state survives between invocations"       "library"     ./tlon sources

printf '\n%d demonstrated, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
