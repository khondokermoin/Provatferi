# Security policy

## Reporting a vulnerability or an exposed secret

Do **not** open a public issue, pull request or discussion for a vulnerability, and never paste a credential into one. Use GitHub's private route: **Security → Report a vulnerability** on this repository, or contact the maintainers directly and privately.

If you find a secret that has been committed (a password, token, key, `.env` content), report it privately and say what kind it is — not its value. The maintainers will rotate it first and then clean up.

## Secrets in this repository

Secrets never live in Git. The rules, the places real values belong, the automated checks (pre-commit hook and CI secret scan) and the rotation checklist are in [docs/SECRETS.md](docs/SECRETS.md).

## Supported versions

Only the current `main` branch is maintained; fixes are made there and deployed to production.
