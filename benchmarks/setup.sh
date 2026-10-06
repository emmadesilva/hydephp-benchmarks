#!/usr/bin/env bash
# Installs every generator at the exact version the published results used.
# Needs PHP 8.2+ with pcntl, Composer, Node 18+, Ruby 3+ with Bundler, and curl.
set -euo pipefail
cd "$(dirname "$0")"

HUGO_VERSION=0.167.0

echo "Hyde (the project this repository is)"
(cd .. && composer install --no-interaction --quiet)

echo "Jigsaw"
(cd generators/jigsaw && composer install --no-interaction --quiet)

echo "Sculpin"
(cd generators/sculpin && composer install --no-interaction --quiet)

echo "Eleventy"
(cd generators/eleventy && npm ci --silent)

echo "Jekyll"
(cd generators/jekyll && bundle config set --local path vendor/bundle && bundle install --quiet)

echo "Hugo $HUGO_VERSION"
case "$(uname -s)-$(uname -m)" in
    Linux-x86_64) asset="hugo_${HUGO_VERSION}_linux-amd64.tar.gz" ;;
    Linux-aarch64) asset="hugo_${HUGO_VERSION}_linux-arm64.tar.gz" ;;
    Darwin-*) asset="hugo_${HUGO_VERSION}_darwin-universal.tar.gz" ;;
    *) echo "Download Hugo $HUGO_VERSION to generators/hugo/hugo yourself"; exit 1 ;;
esac
curl -fsSL "https://github.com/gohugoio/hugo/releases/download/v${HUGO_VERSION}/${asset}" | tar -xz -C generators/hugo hugo

php -r 'extension_loaded("pcntl") || exit(print("Warning: the pcntl extension is missing, the runner needs it.\n"));'
echo "Done. Try: php bench compare --posts=100 --runs=1"
