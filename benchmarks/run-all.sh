#!/usr/bin/env bash
# Runs every suite, one after the other. Never run benchmarks in parallel with each other,
# or with anything else heavy: they compete for the same cores and memory bandwidth.
set -euo pipefail
cd "$(dirname "$0")"

for suite in scaling compare content php single-core scaling-large; do
    echo "### $suite ($(date -u +%H:%M:%S))"
    php bench "$suite"
done

php markdown.php 1000
echo "### done ($(date -u +%H:%M:%S))"
