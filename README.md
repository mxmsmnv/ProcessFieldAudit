# ProcessFieldAudit

A ProcessWire admin module that gives you a complete audit of all fields, their multilingual translation coverage, and template structure.

![ProcessFieldAudit](assets/readme-doodle.png)

It is made for multilingual and schema-heavy ProcessWire sites where developers and editors need a fast, navigable overview of fields, templates and translation gaps.

**Version:** 1.0.3

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## Features

### Fields tab

- Lists all fields with name, type, label and assigned templates
- Shows `FieldtypeMatrixType` metadata (slug identifier, display name)
- Audits every `FieldtypeRepeaterMatrix` field — all matrix types with IDs, display names and attached fields
- Cross-references where each field is used inside matrix types
- Panel button on every row (appears on hover) — opens the field edit page as a sliding panel
- Filter: text search by name/type, Matrix-only mode, hide system fields toggle

### Multilingual tab

**Overall progress bars** at the top (colour-coded: green ≥ 80 %, yellow ≥ 40 %, red < 40 %):

- **Metadata L+D+N** — percentage of field labels / descriptions / notes that are translated. Only fields where the default language value actually exists are counted; system fields are excluded.
- **Values** — percentage of page-level multilingual content that is translated (raw value counts, not field counts).

**Per-language summary pills** (L / D / N / V) use the same accurate denominator logic.

**Per-field rows** show:

| Column | Meaning |
|--------|---------|
| **L** | Field label translated for this language |
| **D** | Field description translated |
| **N** | Field notes translated |
| **V** | Page-level value translation coverage (see below) |

**V column** is populated for:

| Field type | What is tracked |
|------------|----------------|
| Text / Textarea with `useLanguages = 1` | Rows where the translated `data{langID}` column is filled |
| `FieldtypeOptions` | Translated option labels in `fieldtype_options` (`title{langID}`) |
| `FieldtypeCombo` | Language subfields (type ending in `_language`); reads `§·langID:value` serialised storage |
| `FieldtypeTable` | Columns of type `textLanguage` / `textareaLanguage` / `textareaCKELanguage`; reads `langID:value\r` serialised storage |

> `FieldtypeTextLanguage` and `FieldtypeTextareaLanguage` fields are **not** counted in V — they are inherently multilingual by type and don't carry page-level translation gaps the same way.

Filter: text search, hide system fields, incomplete-only mode.

### Templates tab

- Lists all non-system templates (repeater templates and admin templates excluded)
- Shows page count per template (single SQL query) and total field count
- All fields listed as clickable pills — hover shows field type and label, click opens field edit
- Panel button opens the template edit page as a sliding panel
- Filter: text search by template name

## Installation

1. Copy the `ProcessFieldAudit` folder to `/site/modules/ProcessFieldAudit/`
2. In the ProcessWire admin go to **Modules → Refresh**
3. Find and install **Field Audit**

## Usage

Open **Admin → Setup → Field Audit**.

The module has three tabs: **Fields**, **Multilingual**, **Templates**.

All field and template names are links. Hover over any row to reveal the **Edit** panel button — it opens the edit page as a sliding panel without leaving the audit view.

## Requirements

- ProcessWire 3.x
- `LanguageSupport` module — required for Multilingual tab data
- `FieldtypeRepeaterMatrix` — optional, enables matrix breakdown in Fields tab
- `FieldtypeMatrixType` from [InputfieldMatrixType](https://github.com/mxmsmnv/InputfieldMatrixType) — optional
- ProFields `FieldtypeCombo`, `FieldtypeTable` — optional, enables V column coverage for those types

## Author

**Maxim Semenov** — [smnv.org](https://smnv.org)

## License

MIT
