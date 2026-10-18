#!/usr/bin/env python3
"""Publish extractor JSON to Studio. Python standard library only.

STUDIO_URL, STUDIO_USER and STUDIO_PASSWORD configure HTTP Basic authentication.
The account needs Publish code docs and membership in the target Development.
"""
import argparse
import base64
import json
import os
import sys
import urllib.error
import urllib.request
from urllib.parse import urlparse


def batches(document, keys, size):
    """Pack arrays without splitting a unit/type, measured as actual UTF-8 JSON."""
    chunk = {}
    used = 2
    for key in keys:
        for item in document.get(key, []):
            cost = len(json.dumps(item, ensure_ascii=False).encode("utf-8")) + len(key) + 6
            if chunk and used + cost > size:
                yield chunk
                chunk, used = {}, 2
            chunk.setdefault(key, []).append(item)
            used += cost
    if chunk:
        yield chunk
    elif not any(document.get(key) for key in keys):
        yield {key: [] for key in keys}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("file")
    parser.add_argument("--url", default=os.getenv("STUDIO_URL", ""))
    parser.add_argument("--format", choices=["auto", "contract", "javadoc", "tsdoc"], default="auto")
    parser.add_argument("--chunk-mb", type=float, default=1.0)
    parser.add_argument("--force", action="store_true")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    with open(args.file, encoding="utf-8") as stream:
        document = json.load(stream)
    fmt = args.format
    if fmt == "auto":
        fmt = "contract" if document.get("contract") == "divengine.codedocs" else "tsdoc" if "modules" in document else "javadoc" if "types" in document else ""
    keys = {"contract": ["units"], "javadoc": ["packages", "types"], "tsdoc": ["modules"]}.get(fmt)
    if not keys or args.chunk_mb <= 0:
        parser.error("Unknown JSON format or invalid chunk size.")
    size = int(args.chunk_mb * 1024 * 1024)
    # Count without keeping a second copy of every batch in memory.
    count = sum(1 for _ in batches(document, keys, size))
    print(f"{document.get('project', '?')}: {fmt}, {count} chunks")
    if args.dry_run:
        return 0
    user, password = os.getenv("STUDIO_USER"), os.getenv("STUDIO_PASSWORD")
    url = urlparse(args.url)
    if not user or not password or url.scheme not in ("http", "https") or not url.netloc or url.username or url.password:
        parser.error("Set STUDIO_URL, STUDIO_USER and STUDIO_PASSWORD.")
    if url.scheme != "https" and url.hostname not in ("localhost", "127.0.0.1", "::1"):
        parser.error("Use HTTPS for remote publishing.")
    authorization = "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode()

    # Do not forward credentials through redirects to another host.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    opener = urllib.request.build_opener(NoRedirect)

    def post(operation, body):
        request = urllib.request.Request(args.url.rstrip("/") + "/api/divengine/codedocs/" + operation, data=json.dumps(body, ensure_ascii=False).encode(), headers={"Content-Type": "application/json", "Authorization": authorization})
        with opener.open(request, timeout=300) as response:
            return json.load(response)

    header = {key: value for key, value in document.items() if key not in keys}
    sid = post("begin", {"header": header, "format": fmt, "chunks": count})["snapshot"]["id"]
    try:
        for sequence, chunk in enumerate(batches(document, keys, size)):
            result = post("chunk", {"snapshot": sid, "sequence": sequence, "data": chunk})
            print(f"  {sequence + 1}/{count}: {result['status']}")
        result = post("finalize", {"snapshot": sid, "force": args.force})
    except BaseException:
        try:
            post("discard", {"snapshot": sid})
        except Exception as cleanup:
            print(f"Upload {sid} may need manual discard: {cleanup}", file=sys.stderr)
        raise
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except urllib.error.HTTPError as error:
        print(f"HTTP {error.code}: {error.read().decode(errors='replace')}", file=sys.stderr)
        sys.exit(1)
