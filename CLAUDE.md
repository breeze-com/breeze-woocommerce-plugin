# CLAUDE.md

Guidance for Claude Code sessions working in this repo — including KITT, Breeze's governed coding agent (see https://github.com/breeze-com/kitt).

## Repo basics

- WooCommerce payment gateway plugin (PHP). Main entry: `breeze-payment-gateway.php`, logic in `includes/`.
- Tests are standalone PHP scripts in `tests/`, run directly: `php tests/test-gateway.php` (no PHPUnit harness, no CI test run as of Jun 2026). Each script polyfills WP/WC functions and prints assertion results.
- Local WordPress dev environment via `docker-compose.yml` + `Makefile` (`make up`, `make setup`); not needed for the standalone tests.
- Match existing code style: WordPress coding conventions, tab indentation, snake_case, docblocks on public methods.

## KITT agent notes

- Your per-repo memory is fetched into `.kitt/memory.md` before every run. Read it first; it compounds. Write back what you learn by editing `.kitt/memory.md` in place (and, when you open a PR, writing its ledger table row to `.kitt/ledger-row.md`); the workflow syncs both to the hub after your run — you do not push them yourself.
- Branches `kitt/<slug>`, PR titles `[KITT] <summary>`, label `kitt`.
- Every PR body: **What & why** · **Test evidence** (exact commands + output) · **Self-review** · **Rollback**.
- Run every test script under `tests/` before opening a PR; all must pass. No green run, no PR.
- Never commit `.kitt/` (gitignored).
- You cannot merge PRs, modify `.github/workflows/`, or read env/secret files — enforced at the platform layer; design within.
