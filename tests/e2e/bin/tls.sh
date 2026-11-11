#!/usr/bin/env bash
# A throwaway CA and a certificate for api.sandbox.two.inc (and localhost,
# where the browser reaches the stub's hosted checkout), so the shop's
# cURL reaches the E2E stub over HTTPS exactly as it reaches Two. The shop
# container trusts only this CA's copy in tests/e2e/.tls (gitignored); nothing
# outside the compose network resolves that name to the stub. Idempotent: a
# certificate with more than a day left is kept, else both are made afresh
# (they last 30 days), so a local run a month on does not fail TLS.
set -euo pipefail

dir="$(cd "$(dirname "$0")/.." && pwd)/.tls"
host=api.sandbox.two.inc
mkdir -p "$dir"
if [ -s "$dir/stub.crt" ] && openssl x509 -checkend 86400 -noout -in "$dir/stub.crt" >/dev/null 2>&1; then
  exit 0
fi

openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=Two E2E stub CA" \
  -keyout "$dir/ca.key" -out "$dir/ca.crt" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=${host}" \
  -keyout "$dir/stub.key" -out "$dir/stub.csr" 2>/dev/null
printf 'subjectAltName=DNS:%s,DNS:localhost\nextendedKeyUsage=serverAuth\n' "$host" > "$dir/stub.ext"
openssl x509 -req -in "$dir/stub.csr" -CA "$dir/ca.crt" -CAkey "$dir/ca.key" -CAcreateserial \
  -days 30 -extfile "$dir/stub.ext" -out "$dir/stub.crt" 2>/dev/null
chmod 644 "$dir"/*.key
echo "tls: CA and ${host} certificate in ${dir}"
