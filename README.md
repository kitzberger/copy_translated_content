# TYPO3 Extension `copy_translated_content`

TYPO3 extension that allows copying content elements of ONE language from one page to another — without copying the translations!

## Features

- Adds a copy button to each language column header in the page module's "Languages" view
- Modal dialog lets you configure:
	- Which content elements to copy (checkbox per element)
	- Target page ID
	- Target language UID (allows copying content into a different language)
	- "Keep visible" option to prevent hiding elements on copy
- Preserves `colPos` and `sorting` of the original elements
- Respects permissions

## EXT:container support

When [`EXT:container`](https://github.com/b13/container) is installed, only top-level content elements (`tx_container_parent = 0`) are listed in the dialog. Container children are copied automatically as part of their parent container element by the DataHandler.

## Technical Details

- Uses the DataHandler command map (`process_cmdmap`) with the paste mechanism to copy records and apply field overrides in a single operation
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
