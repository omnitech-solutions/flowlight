# Flowlight

**Flowlight** is a lightweight workflow orchestration library for PHP. It provides a clean, composable pattern for chaining actions into pipelines, handling success and failure consistently, and keeping business logic organized and testable. Inspired by Ruby’s [LightService](https://github.com/adomokos/light-service).

---

## ✨ Features

- **Composable pipelines** — chain multiple actions together with clarity.
- **Success & failure handling** — uniform error propagation.
- **Testable** — encourages isolated and unit-tested business logic.
- **Lightweight** — minimal dependencies, built for PHP 8.3+.

---

## 📂 Project Structure

```
├── src/              # Library source code
├── tests/            # Test suite (Pest + PHPUnit)
│   ├── Unit/         # Unit tests
│   ├── Feature/      # Feature/integration tests
│   └── Pest.php      # Pest configuration
├── Makefile          # Automation commands
├── composer.json     # Dependencies & scripts
└── phpunit.xml       # PHPUnit configuration
```

---

## 🚀 Getting Started

### Installation

```bash
composer require omnitech-solutions/flowlight
```

### Development setup

```bash
make install
```

---

## 🧪 Testing

This project uses [Pest](https://pestphp.com/) for testing.

Run the test suite:

```bash
make test
```

Run a specific test:

```bash
make test TEST=tests/Unit/ExampleTest.php
```

### Coverage

Generate coverage reports (requires Xdebug or PCOV):

```bash
make coverage
```

Open the HTML report:

```bash
make coverage-show
```

---

## 🧹 Code Quality

### Linting / Formatting

```bash
make lint           # Auto-fix with Pint
make format:check   # Check formatting only
```

### Static Analysis

```bash
make analyse
```

Update the PHPStan baseline:

```bash
make analyse:update-baseline
```

---

## 🛠 Tooling

- **Testing**: [PestPHP](https://pestphp.com/)
- **Coverage**: Xdebug / PCOV
- **Code style**: [Laravel Pint](https://laravel.com/docs/pint)
- **Static analysis**: [PHPStan](https://phpstan.org/)
- **Mutation testing**: [Infection](https://infection.github.io/)

---

## 🔧 Makefile Targets

| Command                        | Description                                          |
| ------------------------------ | ---------------------------------------------------- |
| `make install`                 | Install dependencies                                 |
| `make test`                    | Run tests (supports `TEST=...`)                      |
| `make coverage`                | Run tests with coverage                              |
| `make coverage-show`           | Open HTML coverage report                            |
| `make lint`                    | Run Pint to auto-fix style                           |
| `make static`                  | Run static analysis (PHPStan + Pint check)           |
| `make analyse`                 | Run PHPStan                                          |
| `make analyse:update-baseline` | Regenerate PHPStan baseline                          |
| `make clean`                   | Remove caches & artifacts                            |
| `make ci`                      | Run full pipeline (lint + static + tests + coverage) |

---

## 🤝 Contributing

1. Fork the repo and create a branch
2. Install dependencies with `make install`
3. Run `make ci` before submitting a PR

---

## 📄 License

MIT © [Desmond O’Leary](https://github.com/desoleary)

