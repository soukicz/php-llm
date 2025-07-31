# GitHub Actions Workflows

This directory contains GitHub Actions workflows for the PHP LLM library.

## Workflows

### php-tests.yml
Runs on every push and pull request to main/master branches.
- **PHPStan Analysis**: Static analysis on src/ and tests/ (excluding Integration tests)
- **Unit Tests**: Runs unit tests on PHP 8.1, 8.2, and 8.3 (excludes integration tests)
- **Lowest Dependencies Test**: Tests with minimum dependency versions (excludes integration tests)

### integration-tests.yml
Runs only when:
- Manually triggered via workflow_dispatch
- Push to master branch (requires environment approval)
- Pull requests with 'integration-tests' label

Requires GitHub secrets:
- `ANTHROPIC_API_KEY`
- `OPENAI_API_KEY`
- `GEMINI_API_KEY`

## Important Notes

1. Integration tests are explicitly excluded from regular test runs using `--exclude-group integration`
2. The `integration-tests` environment should have protection rules requiring manual approval
3. Integration tests have a 10-minute timeout and cost tracking ($5 limit per run)