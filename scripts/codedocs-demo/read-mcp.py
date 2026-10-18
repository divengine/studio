#!/usr/bin/env python3
"""Read the populated demo through Studio's real MCP endpoint, as its agent."""
import argparse
import base64
import json
import os
from pathlib import Path
import urllib.request
from urllib.parse import urlparse

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--url', default='http://127.0.0.1:8080')
parser.add_argument('--token-file', type=Path, default=Path(os.getenv('STUDIO_ROOT', Path(__file__).resolve().parents[2])) / 'private/divengine-mcp.token')
args = parser.parse_args()
parsed = urlparse(args.url)
if parsed.scheme != 'https' and not (parsed.scheme == 'http' and parsed.hostname in ('localhost', '127.0.0.1', '::1')):
    parser.error('Use HTTPS for remote MCP access.')
token = args.token_file.read_bytes().strip()
authorization = 'Basic ' + base64.b64encode(token).decode()

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

opener = urllib.request.build_opener(NoRedirect)

def rpc(method, params):
    request = urllib.request.Request(args.url.rstrip('/') + '/mcp/post', data=json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode(), headers={'Content-Type': 'application/json', 'Authorization': authorization})
    with opener.open(request, timeout=60) as response:
        result = json.load(response)
    if 'error' in result:
        raise SystemExit(json.dumps(result['error']))
    return result['result']

tools = rpc('tools/list', {})['tools']
print('Code Docs MCP tools: ' + ', '.join(tool['name'] for tool in tools if tool['name'].startswith('codedocs_')))
for name, arguments in [('codedocs_sets', {}), ('codedocs_symbols', {'project': 'demo-shop-php', 'q': 'total'}), ('codedocs_symbols', {'project': 'demo-shop-ts', 'q': 'cartTotal'}), ('codedocs_endpoints', {'project': 'demo-shop-ts'})]:
    print(name + ': ' + json.dumps(rpc('tools/call', {'name': name, 'arguments': arguments}), ensure_ascii=False))
