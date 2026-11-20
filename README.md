# RaggieSoft Narratives (`raggiesoft-narratives`)

[![Code License: MIT](https://img.shields.io/badge/Scripts_License-MIT-0369a1.svg)](./LICENSE)
[![Content License: CC BY-SA 4.0](https://img.shields.io/badge/Creative_Content-CC_BY--SA_4.0-f59e0b.svg)](https://books.raggiesoft.com)

Structured Markdown manuscript, YAML frontmatter, and lore source repository powering **Ocean View Archives** (`https://books.raggiesoft.com`), **RaggieSoft Story Crossroads** interactive branching fiction, and **RaggieSoft Backstage** (`https://backstage.raggiesoft.com`).

## Publishing Pipeline

- **`books/`**: Multi-era serialized linear novels and **RaggieSoft Story Crossroads** interactive branching routes organized by deterministic era/chapter/part hierarchies (`eXXX/cXXX/pXXX.md`) with strict YAML frontmatter chronology.
- **`backstage/` & `universal-lore/`**: Supplemental production dossiers and cross-book world-building references compiled into static HTML routes and `/api/v1/*.json` endpoints for `https://backstage.raggiesoft.com`.
- **Static Compilation**: Validated and compiled at build time by `raggiesoft-deployment-tools/paige.php` into JSON route trees (`catalog.json`, `toc.json`), client-side full-text `search-index.json` payloads, and downloadable EPUB/PDF editions.

## Licensing & Fictitious Entities Disclaimer

- **Compilation Scripts (`scripts/`)**: MIT License
- **Creative Manuscripts & Lore**: Creative Commons Attribution-ShareAlike 4.0 International (`CC BY-SA 4.0`)
- **Fictitious Persons & Entities Disclaimer**: All characters, bands, biographies, universities (including CPI, VDU, The Forgers, and The Crucible), corporations, transit agencies, legal filings, and events in this repository are works of fiction.
