# TYPO3 Extension `copy_translated_content`

TYPO3 extension that allows copying translated content elements from one page to another without copying the default language.

## Features

- Adds a "Copy Translated Content" button in the page module when viewing a language other than default
- Opens a modal dialog to specify the target page ID
- Copies only content elements from the selected language
- Does not copy default language content or trigger automatic translation of copied elements
- Respects workspace and permissions

## Technical Details

- Uses DataHandler::copyRecord() with `ignoreLocalization = true` to prevent copying child localizations
- Only copies content elements with `sys_language_uid > 0`
- Checks read permissions on source page and edit permissions on target page

## Requirements

- TYPO3 12.4 or higher
- PHP 8.2 or higher

## Installation

```
composer require kitzberger/copy-translated-content
```

## Author

Philipp Kitzberger <typo3@kitze.net>
