#!/usr/bin/env bash
# Javadoc of a Java project as JSON, with CodeIntel's JSON doclet (no Maven, no Lombok: the
# demo has no dependencies). Usage: extract-java.sh <project dir> <identifier> > out.json
set -euo pipefail
ROOT="$(cd "${1:?project dir}" && pwd)"
PROJECT="${2:?identifier}"
DOCLET="${CODEINTEL_JAVADOC_DOCLET:-$HOME/workspace/dev/javadoc/doclet}"
[[ -d "$DOCLET/src" ]] || { echo "Set CODEINTEL_JAVADOC_DOCLET to CodeIntel's javadoc/doclet" >&2; exit 1; }
if [[ ! -f "$DOCLET/build/com/divengine/codeintel/javadoc/JsonDoclet.class" ]]; then
  mkdir -p "$DOCLET/build"
  javac -d "$DOCLET/build" --add-modules jdk.javadoc $(find "$DOCLET/src" -name '*.java')
fi
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
# The doclet relativizes the original sources against a copy: give it two absolute paths.
cp -R "$ROOT/src/main/java" "$WORK/src"
javadoc -quiet -encoding UTF-8 -doclet com.divengine.codeintel.javadoc.JsonDoclet -docletpath "$DOCLET/build" \
  -sourcepath "$WORK/src" -subpackages com -out "$WORK/out.json" -original "$ROOT/src/main/java" \
  -project "$PROJECT" -commit "$(git -C "$ROOT" rev-parse HEAD)" -branch main >"$WORK/log" 2>&1 \
  || { grep -v ': warning' "$WORK/log" >&2; exit 1; }
cat "$WORK/out.json"
