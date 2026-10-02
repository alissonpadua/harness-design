# ADR-0010: rustfs-vendored-pecl-tarballs-corporate-ca

Status: accepted
Date: 2026-10-02

## Context
Dev network performs Zscaler TLS interception; MinIO public images are gone from Docker Hub;
PECL https is broken from containers. Alpine base images unusable here (TLS trust).

## Decision
- Object storage: **RustFS** (S3-compatible, same AWS env contract) + `amazon/aws-cli` retry-loop bucket init; MinIO dropped.
- PHP extensions: PECL tarballs **vendored** in `docker/php/deps/` (refresh via `fetch-deps.sh`).
- Base image: `php:8.4-fpm-bookworm` (Debian), corporate **Zscaler root CA installed in image** from `docker/php/certs/`.

## Consequences
Builds are deterministic and proxy-proof. Root CA is public info (vendor-published); harmless on non-intercepting networks (CI).
