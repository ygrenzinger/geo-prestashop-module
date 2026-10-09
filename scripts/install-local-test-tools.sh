#!/usr/bin/env bash
set -euo pipefail

# Install the PHP toolchain used to lint and test the llmscatalog module.
# This script targets macOS with Homebrew. It does not install a PrestaShop shop.

if [[ "$(uname -s)" != "Darwin" ]]; then
  printf 'This installer currently supports macOS only (Homebrew).\n' >&2
  exit 1
fi

if ! command -v brew >/dev/null 2>&1; then
  printf 'Homebrew is required. Install it from https://brew.sh, then rerun this script.\n' >&2
  exit 1
fi

printf 'Installing PHP 8.3 and Composer with Homebrew...\n'
brew install php@8.3 composer

php_prefix="$(brew --prefix php@8.3)"
export PATH="$php_prefix/bin:$php_prefix/sbin:$PATH"

if [[ ! -x "$php_prefix/bin/php" ]]; then
  printf 'Could not find PHP at %s/bin/php after installation.\n' "$php_prefix" >&2
  exit 1
fi

php_version="$("$php_prefix/bin/php" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [[ "$php_version" != "8.3" ]]; then
  printf 'Expected PHP 8.3, found PHP %s.\n' "$php_version" >&2
  exit 1
fi

required_extensions=(
  curl dom fileinfo gd iconv intl json mbstring openssl pdo pdo_mysql
  simplexml xmlreader zip
)
missing_extensions=()
for extension in "${required_extensions[@]}"; do
  if ! "$php_prefix/bin/php" -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
    missing_extensions+=("$extension")
  fi
done

if ((${#missing_extensions[@]} > 0)); then
  printf 'PHP 8.3 is missing required extensions: %s\n' "${missing_extensions[*]}" >&2
  printf 'Check the Homebrew PHP installation at %s.\n' "$php_prefix" >&2
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  printf 'Composer was not found after Homebrew installation.\n' >&2
  exit 1
fi
composer --version

# PHP 8.3 is keg-only, so put it first on PATH in future login shells too.
profile_file=""
case "${SHELL##*/}" in
  zsh) profile_file="$HOME/.zprofile" ;;
  bash) profile_file="$HOME/.bash_profile" ;;
esac

if [[ -n "$profile_file" ]]; then
  begin_marker="# >>> llmscatalog PHP 8.3 >>>"
  end_marker="# <<< llmscatalog PHP 8.3 <<<"
  if ! grep -Fq "$begin_marker" "$profile_file" 2>/dev/null; then
    {
      printf '\n%s\n' "$begin_marker"
      printf 'export PATH="%s/bin:%s/sbin:$PATH"\n' "$php_prefix" "$php_prefix"
      printf '%s\n' "$end_marker"
    } >> "$profile_file"
    printf 'Added PHP 8.3 to PATH in %s. Open a new terminal to use it there.\n' "$profile_file"
  else
    printf 'PHP 8.3 PATH entry already exists in %s.\n' "$profile_file"
  fi
else
  printf 'Add this to your shell startup file to use PHP 8.3 in new terminals:\n'
  printf 'export PATH="%s/bin:%s/sbin:$PATH"\n' "$php_prefix" "$php_prefix"
fi

printf '\nInstalled toolchain:\n'
"$php_prefix/bin/php" --version | head -n 1
printf 'PHP executable: %s/bin/php\n' "$php_prefix"
printf 'Extensions: %s\n' "${required_extensions[*]}"
printf 'Composer executable: %s\n' "$(command -v composer)"
printf '\nPHP syntax and PHPUnit commands for this repository:\n'
printf "find llmscatalog tests -name '*.php' -print0 | xargs -0 -n1 php -l\n"
printf 'vendor/bin/phpunit -c phpunit.xml.dist\n'
printf 'Next: run "composer install" to fetch PHPUnit, then "composer test:unit".\n'
printf 'For the shop-dependent integration tests, use scripts/run-integration-tests.sh (Docker).\n'
