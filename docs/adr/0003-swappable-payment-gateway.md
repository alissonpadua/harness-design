# ADR-0003: swappable-payment-gateway

Status: accepted
Date: 2026-10-02

## Context
Locked during feature-scope discussion (see ../../specs/feature-scope.md).

## Decision
Contracts\PaymentGateway with StripeGateway + FakeGateway; app code never touches Stripe SDK; own billing portal; local billing mirror.

## Consequences
See ../../specs/feature-scope.md module sections.
