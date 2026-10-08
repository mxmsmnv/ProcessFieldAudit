<?php namespace ProcessWire;

/**
 * ProcessFieldAudit
 *
 * Admin tool that lists every field in the system showing:
 *  - Field name, type, label
 *  - For FieldtypeMatrixType fields: configured identifier and display name
 *  - For FieldtypeRepeaterMatrix fields: full breakdown of all types and their fields
 *  - Which templates each field belongs to
 *
 * Part of the InputfieldMatrixType module package.
 *
 * Install: copy folder to /site/modules/ProcessFieldAudit/
 *          Admin → Modules → Refresh → Install "Field Audit"
 * Access:  Admin → Setup → Field Audit
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.0.3
 */
class ProcessFieldAudit extends Process {

    public static function getModuleInfo() {
        return [
            'title'   => 'Field Audit',
            'version' => 103,
            'summary' => 'Lists all fields with types, MatrixType identifiers and matrix membership',
            'author'  => 'Maxim Semenov',
            'href'    => 'https://smnv.org',
            'icon'    => 'table',
            'page'    => [
                'name'   => 'field-audit',
                'parent' => 'setup',
                'title'  => 'Field Audit',
            ],
        ];
    }

    protected function fieldEditUrl(Field $field): string {
        return $this->wire('config')->urls->admin . 'setup/field/edit?id=' . $field->id;
    }

    protected function fieldNameCell(Field $field): string {
        $url  = $this->fieldEditUrl($field);
        $name = htmlspecialchars($field->name);
        return '<td class="fa-fname">'
             . '<a href="' . $url . '" class="fa-flink">' . $name . '</a>'
             . '<a href="' . $url . '" class="pw-panel fa-panel-btn" data-panel-width="75%">'
             . '<small class="ui-button-text"><i class="fa fa-fw fa-eye"></i><span>Edit</span></small>'
             . '</a>'
             . '</td>';
    }

    public function ___executeLanguages() {
        $out  = $this->styles();
        $out .= $this->renderNav('languages');
        $out .= $this->renderMultilingual();
        return $out;
    }

    public function ___executeTemplates() {
        $out  = $this->styles();
        $out .= $this->renderNav('templates');
        $out .= $this->renderTemplates();
        return $out;
    }

    public function ___execute() {

        // ── 1. Build matrix map ───────────────────────────────────────────────
        $matrixMap = [];
        foreach ($this->fields as $field) {
            if (!($field->type instanceof FieldtypeRepeaterMatrix)) continue;

            // getMatrixTypes() returns ['typeName' => typeId, ...]
            $types   = $field->type->getMatrixTypes($field);
            $tpl     = $this->templates->get('repeater_' . $field->name);

            // Read displayName directly from matrix{N}_label in field config
            $displayNames = [];
            $fdata = $field->getArray();
            foreach ($fdata as $k => $v) {
                if (preg_match('/^matrix(\d+)_name$/', $k, $m)) {
                    $n     = $m[1];
                    $label = $fdata["matrix{$n}_label"] ?? '';
                    $displayNames[(string)$v] = $label ?: $this->prettify((string)$v);
                }
            }

            // Read per-type field list from matrix field config (matrix{N}_fields)
            $typeFields = $this->readTypeFields($field, $types);

            $matrixMap[$field->name] = [];
            foreach ($types as $typeName => $typeId) {
                $matrixMap[$field->name][$typeName] = [
                    'id'          => (int)$typeId,
                    'displayName' => $displayNames[$typeName] ?? $this->prettify($typeName),
                    'fields'      => $typeFields[$typeName] ?? [],
                ];
            }
        }

        // ── 2. Collect all fields ─────────────────────────────────────────────
        $allFields = [];
        foreach ($this->fields as $field) {
            $entry = [
                'field'         => $field,
                'typeName'      => $field->type->className(),
                'label'         => $field->label ?: '',
                'matrixSlug'    => null,
                'matrixDisplay' => null,
                'usedInMatrix'  => [],
                'templates'     => [],
            ];

            if ($field->type instanceof FieldtypeMatrixType) {
                $inp = $field->getInputfield($this->wire('page'), $field);
                if ($inp) {
                    $entry['matrixSlug']    = $inp->matrixTypeName    ?? null;
                    $entry['matrixDisplay'] = $inp->matrixDisplayName ?? null;
                }
            }

            foreach ($this->templates as $tpl) {
                if ($tpl->fields->has($field)) {
                    $entry['templates'][] = $tpl->name;
                }
            }

            $allFields[$field->name] = $entry;
        }

        // ── 3. Cross-reference ────────────────────────────────────────────────
        foreach ($matrixMap as $mfName => $types) {
            foreach ($types as $typeName => $typeData) {
                foreach ($typeData['fields'] as $fname => $flabel) {
                    if (isset($allFields[$fname])) {
                        $allFields[$fname]['usedInMatrix'][$mfName] = $typeName;
                    }
                }
            }
        }

        ksort($allFields);

        $out  = $this->styles();
        $out .= $this->renderNav('fields');
        $out .= $this->renderMatrices($matrixMap);
        $out .= $this->renderTable($allFields);
        return $out;
    }

    // ── Read per-type field list from field config ────────────────────────────

    protected function readTypeFields(Field $mf, array $types) {
        $result = [];
        $data   = $mf->getArray();

        // Build N => typeName map from matrix{N}_name keys
        // N is the numeric suffix in the config key, NOT the sort order or typeId
        $nToName = [];
        foreach ($data as $k => $v) {
            if (preg_match('/^matrix(\d+)_name$/', $k, $m)) {
                $nToName[(int)$m[1]] = (string)$v;
            }
        }

        // Initialize result for all known types
        foreach ($types as $typeName => $typeId) {
            $result[$typeName] = [];
        }

        // Read fields per N
        foreach ($nToName as $n => $typeName) {
            $fieldIds = $data["matrix{$n}_fields"] ?? [];
            if (!is_array($fieldIds)) {
                $fieldIds = array_filter(explode(',', (string)$fieldIds));
            }
            $fields = [];
            foreach ($fieldIds as $fid) {
                $fid = (int)$fid;
                if (!$fid) continue;
                $f = $this->fields->get($fid);
                if ($f) $fields[$f->name] = $f->label ?: $f->name;
            }
            $result[$typeName] = $fields;
        }

        return $result;
    }

    // ── Renderers ─────────────────────────────────────────────────────────────

    protected function renderMatrices(array $matrixMap) {
        if (empty($matrixMap)) return '';
        $out = '<h2 class="fa-h2">RepeaterMatrix fields (' . count($matrixMap) . ')</h2>' . "\n";
        foreach ($matrixMap as $mfName => $types) {
            $mf  = $this->fields->get($mfName);
            $out .= '<div class="fa-matrix">';
            $out .= '<div class="fa-mhdr">'
                  . '<a href="' . $this->fieldEditUrl($mf) . '" class="fa-mname">' . htmlspecialchars($mfName) . '</a>'
                  . ($mf->label ? '<span class="fa-mlabel">' . htmlspecialchars($mf->label) . '</span>' : '')
                  . '<span class="fa-mcount">' . count($types) . ' type' . (count($types) !== 1 ? 's' : '') . '</span>'
                  . '</div>';
            if (empty($types)) {
                $out .= '<div class="fa-empty">No types — set Matrix Type Identifier on FieldtypeMatrixType fields</div>';
            } else {
                $out .= '<div class="fa-tgrid">';
                foreach ($types as $typeName => $td) {
                    $out .= '<div class="fa-type">'
                          . '<div class="fa-thdr">'
                          . '<span class="fa-slug">' . htmlspecialchars($typeName) . '</span>'
                          . '<span class="fa-tdisplay">' . htmlspecialchars($td['displayName']) . '</span>'
                          . '<span class="fa-tid">#' . $td['id'] . '</span>'
                          . '</div>';
                    if (!empty($td['fields'])) {
                        $out .= '<div class="fa-tfields">';
                        foreach ($td['fields'] as $fn => $fl) {
                            $f = $this->fields->get($fn);
                            $href = $f ? $this->fieldEditUrl($f) : '#';
                            $out .= '<a href="' . $href . '" class="fa-fpill" title="' . htmlspecialchars($fl) . '">'
                                  . htmlspecialchars($fn) . '</a>';
                        }
                        $out .= '</div>';
                    } else {
                        $out .= '<div class="fa-none-small">no fields</div>';
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }
        return $out;
    }

    protected function renderTable(array $allFields) {
        $out  = '<h2 class="fa-h2 uk-margin-medium-top">All fields (' . count($allFields) . ')</h2>';
        $out .= '<div class="fa-bar">'
              . '<div class="uk-inline"><span class="uk-form-icon" uk-icon="icon:search"></span>'
              . '<input class="uk-input uk-form-small" style="width:240px" type="text" id="fa-q" placeholder="Filter by name or type…" oninput="faF()"></div>'
              . '<label class="uk-text-small"><input class="uk-checkbox" type="checkbox" id="fa-om" onchange="faF()"> MatrixType only</label>'
              . '<label class="uk-text-small"><input class="uk-checkbox" type="checkbox" id="fa-hs" onchange="faF()" checked> Hide system fields</label>'
              . '</div>';
        $out .= '<table class="uk-table uk-table-divider uk-table-hover uk-table-small fa-tbl" id="fa-tbl"><thead><tr>'
              . '<th>Field name</th><th>Type</th><th>Label</th>'
              . '<th>Matrix slug</th><th>Display name</th>'
              . '<th>Used in matrix → type</th><th>Templates</th>'
              . '</tr></thead><tbody>';

        $sysPfx = ['_', 'process_', 'admin_', 'roles', 'permissions'];

        foreach ($allFields as $name => $e) {
            $isMatrix  = str_ends_with($e['typeName'], 'MatrixType') || str_ends_with($e['typeName'], 'RepeaterMatrix');
            $shortType = preg_replace('/^Fieldtype/', '', $e['typeName']);
            $isSys     = preg_match('/^(repeater_|_)/', $name)
                      || in_array($name, ['title','name','status','sort','include','created','modified','createdUser','modifiedUser','email','pass','language','roles','permissions']);

            $slugCell    = $e['matrixSlug']
                         ? '<span class="fa-slug">' . htmlspecialchars($e['matrixSlug']) . '</span>'
                         : '<span class="fa-dash">—</span>';
            $displayCell = $e['matrixDisplay'] ? htmlspecialchars($e['matrixDisplay']) : '<span class="fa-dash">—</span>';

            $mxCell = '';
            foreach ($e['usedInMatrix'] as $mf => $mt) {
                $mxCell .= '<span class="fa-mf">' . htmlspecialchars($mf) . '</span> '
                         . '<span class="fa-mt">' . htmlspecialchars($mt) . '</span> ';
            }
            if (!$mxCell) $mxCell = '<span class="fa-dash">—</span>';

            $tc = count($e['templates']);
            $tplCell = $tc
                ? '<details><summary>' . $tc . ' tpl</summary><div class="fa-tlist">' . implode(', ', array_map('htmlspecialchars', $e['templates'])) . '</div></details>'
                : '<span class="fa-dash">unused</span>';

            $out .= '<tr'
                  . ' class="' . ($isMatrix ? 'fa-rm ' : '') . ($isSys ? 'fa-sys' : '') . '"'
                  . ' data-name="' . htmlspecialchars($name) . '"'
                  . ' data-type="' . htmlspecialchars(strtolower($shortType)) . '"'
                  . ' data-matrix="' . ($isMatrix ? '1' : '0') . '"'
                  . ' data-sys="' . ($isSys ? '1' : '0') . '">'
                  . $this->fieldNameCell($e['field'])
                  . '<td><span class="uk-label" style="font-family:monospace;font-size:10px;font-weight:400;text-transform:none">' . htmlspecialchars($shortType) . '</span></td>'
                  . '<td class="fa-flabel">' . htmlspecialchars($e['label']) . '</td>'
                  . '<td>' . $slugCell . '</td>'
                  . '<td>' . $displayCell . '</td>'
                  . '<td>' . $mxCell . '</td>'
                  . '<td>' . $tplCell . '</td>'
                  . '</tr>';
        }

        $out .= '</tbody></table>';
        $out .= '<script>
function faF(){
    var q=document.getElementById("fa-q").value.toLowerCase();
    var om=document.getElementById("fa-om").checked;
    var hs=document.getElementById("fa-hs").checked;
    document.querySelectorAll("#fa-tbl tbody tr").forEach(function(r){
        var show=true;
        if(q && !r.dataset.name.includes(q) && !r.dataset.type.includes(q)) show=false;
        if(om && r.dataset.matrix!=="1") show=false;
        if(hs && r.dataset.sys==="1") show=false;
        r.style.display=show?"":"none";
    });
}
faF();
</script>';
        return $out;
    }

    protected function renderNav(string $active) {
        $base = $this->wire('page')->url;
        $tabs = [
            'fields'    => ['url' => $base,                'label' => 'Fields'],
            'languages' => ['url' => $base . 'languages/', 'label' => 'Multilingual'],
            'templates' => ['url' => $base . 'templates/', 'label' => 'Templates'],
        ];
        $out = '<ul class="uk-tab uk-margin-small-bottom">';
        foreach ($tabs as $key => $tab) {
            $cls = $key === $active ? ' class="uk-active"' : '';
            $out .= '<li' . $cls . '><a href="' . $tab['url'] . '">' . $tab['label'] . '</a></li>';
        }
        $out .= '</ul>';
        return $out;
    }

    protected function renderMultilingual() {
        $languages = $this->wire('languages');
        if (!$languages || !$languages->count()) {
            return '<div class="fa-ml-notice">LanguageSupport module is not installed — no multilingual data available.</div>';
        }

        $otherLangs = [];
        foreach ($languages as $lang) {
            if (!$lang->isDefault()) $otherLangs[] = $lang;
        }

        if (empty($otherLangs)) {
            return '<div class="fa-ml-notice">Only the default language is configured.</div>';
        }

        $database = $this->wire('database');
        $prefix   = (string)($this->wire('config')->dbTablePrefix ?? '');

        $sysNames = ['title','name','status','sort','include','created','modified',
                     'createdUser','modifiedUser','email','pass','language','roles','permissions'];

        $rows = [];
        foreach ($this->fields as $field) {
            if (preg_match('/^(repeater_|_)/', $field->name)) continue;

            $isSys = in_array($field->name, $sysNames)
                  || ($field->flags & Field::flagSystem)
                  || ($field->flags & Field::flagPermanent);

            $fdata         = $field->getArray();
            $typeName      = $field->type->className();
            $usesLanguages = !empty($fdata['useLanguages']);
            $isOptions     = $typeName === 'FieldtypeOptions';
            $isCombo       = $typeName === 'FieldtypeCombo';
            $isTable       = $typeName === 'FieldtypeTable';

            $defaultTrans = [
                'label'       => trim((string)($field->label        ?? '')),
                'description' => trim((string)($fdata['description'] ?? '')),
                'notes'       => trim((string)($fdata['notes']       ?? '')),
            ];

            $trans = [];
            foreach ($otherLangs as $lang) {
                $lid = $lang->id;
                $trans[$lid] = [
                    'label'       => trim((string)($fdata['label'       . $lid] ?? '')),
                    'description' => trim((string)($fdata['description' . $lid] ?? '')),
                    'notes'       => trim((string)($fdata['notes'       . $lid] ?? '')),
                    'values'      => null, // null = not a multilingual value field
                ];
            }

            // Values coverage: query field DB table for data{langID} columns
            if ($usesLanguages) {
                $table = $prefix . 'field_' . $field->name;
                try {
                    $rawCols = $database->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
                    $cols    = array_column($rawCols, 'Field');

                    // FieldtypeTextareaLanguage / FieldtypeTextLanguage may store the default
                    // language value in data{defaultLangID} instead of the plain `data` column.
                    $defaultLang = $languages->getDefault();
                    if (in_array('data', $cols)) {
                        $defaultCol = 'data';
                    } elseif (in_array('data' . $defaultLang->id, $cols)) {
                        $defaultCol = 'data' . $defaultLang->id;
                    } else {
                        $defaultCol = null;
                    }

                    if ($defaultCol) {
                        $total = (int)$database->query(
                            "SELECT COUNT(*) FROM `{$table}` WHERE `{$defaultCol}` != '' AND `{$defaultCol}` IS NOT NULL"
                        )->fetchColumn();

                        foreach ($otherLangs as $lang) {
                            $col = 'data' . $lang->id;
                            if (!in_array($col, $cols)) continue;
                            $stmt = $database->prepare(
                                "SELECT COUNT(*) FROM `{$table}`
                                 WHERE `{$defaultCol}` != '' AND `{$defaultCol}` IS NOT NULL
                                   AND `{$col}` != '' AND `{$col}` IS NOT NULL"
                            );
                            $stmt->execute();
                            $trans[$lang->id]['values'] = [
                                'translated' => (int)$stmt->fetchColumn(),
                                'total'      => $total,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // table may not exist
                }
            }

            // Values coverage: FieldtypeOptions — translations in fieldtype_options table
            if ($isOptions) {
                $optTable = $prefix . 'fieldtype_options';
                try {
                    $rawCols  = $database->query("SHOW COLUMNS FROM `{$optTable}`")->fetchAll(\PDO::FETCH_ASSOC);
                    $cols     = array_column($rawCols, 'Field');
                    $stmtTot  = $database->prepare("SELECT COUNT(*) FROM `{$optTable}` WHERE `fields_id` = ?");
                    $stmtTot->execute([$field->id]);
                    $optTotal = (int)$stmtTot->fetchColumn();

                    foreach ($otherLangs as $lang) {
                        $col = 'title' . $lang->id;
                        if (!in_array($col, $cols)) continue;
                        $stmt = $database->prepare(
                            "SELECT COUNT(*) FROM `{$optTable}`
                             WHERE `fields_id` = ? AND `{$col}` != '' AND `{$col}` IS NOT NULL"
                        );
                        $stmt->execute([$field->id]);
                        $trans[$lang->id]['values'] = [
                            'translated' => (int)$stmt->fetchColumn(),
                            'total'      => $optTotal,
                        ];
                    }
                } catch (\Exception $e) {
                    // table may not exist
                }
            }

            // Values coverage: FieldtypeCombo — language subfields stored as "default§·langID:translation"
            if ($isCombo) {
                $table = $prefix . 'field_' . $field->name;
                try {
                    $rawCols     = $database->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
                    $existingCols = array_column($rawCols, 'Field');
                    $langCols    = [];
                    $maxN        = max(30, (int)($fdata['qty'] ?? 0));
                    for ($n = 1; $n <= $maxN; $n++) {
                        $subType = $fdata["i{$n}_type"] ?? '';
                        if (empty($subType) || stripos($subType, '_language') === false) continue;
                        $subName = trim((string)($fdata["i{$n}_name"] ?? ''));
                        // column is either "i{n}_{name}" or plain "i{n}"
                        foreach (($subName ? ["i{$n}_{$subName}", "i{$n}"] : ["i{$n}"]) as $c) {
                            if (in_array($c, $existingCols)) { $langCols[] = $c; break; }
                        }
                    }
                    if (!empty($langCols)) {
                        $total   = 0;
                        $doneMap = [];
                        foreach ($otherLangs as $lang) $doneMap[$lang->id] = 0;
                        foreach ($langCols as $col) {
                            $total += (int)$database->query(
                                "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` != '' AND `{$col}` IS NOT NULL"
                            )->fetchColumn();
                            foreach ($otherLangs as $lang) {
                                // ComboLanguagesValue stores translations as "§·{langID}:text"
                                $stmt = $database->prepare(
                                    "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE ?"
                                );
                                $stmt->execute(['%§·' . $lang->id . ':%']);
                                $doneMap[$lang->id] += (int)$stmt->fetchColumn();
                            }
                        }
                        foreach ($otherLangs as $lang) {
                            $trans[$lang->id]['values'] = ['translated' => $doneMap[$lang->id], 'total' => $total];
                        }
                    }
                } catch (\Exception $e) {}
            }

            // Values coverage: FieldtypeTable — language columns stored as "langID:text\rlangID:text"
            if ($isTable) {
                $table = $prefix . 'field_' . $field->name;
                try {
                    $rawCols     = $database->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
                    $existingCols = array_column($rawCols, 'Field');
                    $langTypes   = ['textLanguage', 'textareaLanguage', 'textareaCKELanguage'];
                    $langCols    = [];
                    $maxN        = max(30, (int)($fdata['maxCols'] ?? 0));
                    for ($n = 1; $n <= $maxN; $n++) {
                        $colType = $fdata["col{$n}type"] ?? '';
                        $colName = $fdata["col{$n}name"] ?? '';
                        if (empty($colType) || empty($colName)) continue;
                        if (!in_array($colType, $langTypes)) continue;
                        if (in_array($colName, $existingCols)) $langCols[] = $colName;
                    }
                    if (!empty($langCols)) {
                        $defaultLang = $languages->getDefault();
                        $dlid        = $defaultLang->id;
                        $total       = 0;
                        $doneMap     = [];
                        foreach ($otherLangs as $lang) $doneMap[$lang->id] = 0;
                        foreach ($langCols as $col) {
                            // Table stores all lang values as "langID:text\r..." — match default lang presence
                            $stmt = $database->prepare(
                                "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE ? OR `{$col}` LIKE ?"
                            );
                            $stmt->execute([$dlid . ':_%', '%' . "\r" . $dlid . ':_%']);
                            $total += (int)$stmt->fetchColumn();
                            foreach ($otherLangs as $lang) {
                                $stmt2 = $database->prepare(
                                    "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE ? OR `{$col}` LIKE ?"
                                );
                                $stmt2->execute([$lang->id . ':_%', '%' . "\r" . $lang->id . ':_%']);
                                $doneMap[$lang->id] += (int)$stmt2->fetchColumn();
                            }
                        }
                        foreach ($otherLangs as $lang) {
                            $trans[$lang->id]['values'] = ['translated' => $doneMap[$lang->id], 'total' => $total];
                        }
                    }
                } catch (\Exception $e) {}
            }

            $rows[$field->name] = [
                'field'        => $field,
                'trans'        => $trans,
                'defaultTrans' => $defaultTrans,
                'hasValues'    => $usesLanguages || $isOptions || $isCombo || $isTable,
                'isSys'        => $isSys,
            ];
        }

        ksort($rows);
        $total = count($rows);

        $out = '<h2 class="fa-h2">Translation coverage (' . $total . ' fields)</h2>';
        if (empty($rows)) {
            return $out . '<div class="fa-ml-notice">No fields found.</div>';
        }

        // ── Overall progress bar ──────────────────────────────────────────────
        // Only count L/D/N when default language has a value; skip system fields.
        $totalMeta = $doneMeta = $totalVals = $doneVals = 0;
        foreach ($otherLangs as $lang) {
            foreach ($rows as $r) {
                if ($r['isSys']) continue;
                $dt = $r['defaultTrans'];
                $t  = $r['trans'][$lang->id];
                if ($dt['label']       !== '') { $totalMeta++; if ($t['label']       !== '') $doneMeta++; }
                if ($dt['description'] !== '') { $totalMeta++; if ($t['description'] !== '') $doneMeta++; }
                if ($dt['notes']       !== '') { $totalMeta++; if ($t['notes']       !== '') $doneMeta++; }
                if ($t['values'] !== null) {
                    $totalVals += $t['values']['total'];
                    $doneVals  += $t['values']['translated'];
                }
            }
        }
        $pctMeta = $totalMeta > 0 ? round($doneMeta / $totalMeta * 100) : 100;
        $pctVals = $totalVals > 0 ? round($doneVals  / $totalVals * 100) : -1;

        $barClr = function(int $p): string {
            return $p >= 80 ? 'var(--pw-alert-success)' : ($p >= 40 ? 'var(--pw-alert-warning)' : 'var(--pw-alert-danger)');
        };

        $out .= '<div class="fa-ml-overall">'
              . '<div class="fa-ml-overall-row">'
              . '<span class="fa-ml-overall-lbl">Metadata <small style="opacity:.6">L+D+N</small></span>'
              . '<div class="fa-ml-bar-wrap"><div class="fa-ml-bar" style="width:' . $pctMeta . '%;background:' . $barClr($pctMeta) . '"></div></div>'
              . '<span class="fa-ml-overall-pct">' . $pctMeta . '%</span>'
              . '<span class="fa-ml-overall-sub">' . $doneMeta . ' / ' . $totalMeta . '</span>'
              . '</div>';
        if ($pctVals >= 0) {
            $out .= '<div class="fa-ml-overall-row">'
                  . '<span class="fa-ml-overall-lbl">Values</span>'
                  . '<div class="fa-ml-bar-wrap"><div class="fa-ml-bar" style="width:' . $pctVals . '%;background:' . $barClr($pctVals) . '"></div></div>'
                  . '<span class="fa-ml-overall-pct">' . $pctVals . '%</span>'
                  . '<span class="fa-ml-overall-sub">' . $doneVals . ' / ' . $totalVals . '</span>'
                  . '</div>';
        }
        $out .= '</div>';

        // ── Per-language summary bars ─────────────────────────────────────────
        $out .= '<div class="fa-ml-stats">';
        foreach ($otherLangs as $lang) {
            $lid   = $lang->id;
            $doneL = $doneD = $doneN = $doneV = 0;
            $totalL = $totalD = $totalN = $totalV = 0;
            foreach ($rows as $r) {
                if ($r['isSys']) continue;
                $dt = $r['defaultTrans'];
                $t  = $r['trans'][$lid];
                if ($dt['label']       !== '') { $totalL++; if ($t['label']       !== '') $doneL++; }
                if ($dt['description'] !== '') { $totalD++; if ($t['description'] !== '') $doneD++; }
                if ($dt['notes']       !== '') { $totalN++; if ($t['notes']       !== '') $doneN++; }
                if ($t['values'] !== null) {
                    $totalV++;
                    if ($t['values']['total'] > 0 && $t['values']['translated'] === $t['values']['total']) $doneV++;
                }
            }
            $pctL = $totalL ? round($doneL / $totalL * 100) : 100;
            $pctD = $totalD ? round($doneD / $totalD * 100) : 100;
            $pctN = $totalN ? round($doneN / $totalN * 100) : 100;
            $pctV = $totalV ? round($doneV / $totalV * 100) : -1;

            $clsL = $pctL === 100 ? 'full' : ($pctL > 0 ? 'part' : 'none');
            $out .= '<div class="fa-ml-stat">'
                  . '<span class="fa-ml-stat-lang">' . htmlspecialchars($lang->title ?: $lang->name) . '</span>'
                  . '<span class="fa-ml-stat-pills">'
                  . '<span class="fa-ml-sp fa-ml-sp-' . $clsL . '" title="Labels: ' . $doneL . '/' . $totalL . '">L ' . $pctL . '%</span>'
                  . '<span class="fa-ml-sp fa-ml-sp-' . ($pctD===100?'full':($pctD>0?'part':'none')) . '" title="Descriptions: ' . $doneD . '/' . $totalD . '">D ' . $pctD . '%</span>'
                  . '<span class="fa-ml-sp fa-ml-sp-' . ($pctN===100?'full':($pctN>0?'part':'none')) . '" title="Notes: ' . $doneN . '/' . $totalN . '">N ' . $pctN . '%</span>'
                  . ($pctV >= 0 ? '<span class="fa-ml-sp fa-ml-sp-' . ($pctV===100?'full':($pctV>0?'part':'none')) . '" title="Values: ' . $doneV . '/' . $totalV . ' fields fully translated">V ' . $pctV . '%</span>' : '')
                  . '</span>'
                  . '</div>';
        }
        $out .= '</div>';

        // ── Legend ────────────────────────────────────────────────────────────
        $out .= '<div class="fa-ml-legend">'
              . '<b>L</b> Label &nbsp; <b>D</b> Description &nbsp; <b>N</b> Notes &nbsp; <b>V</b> Values (page content)'
              . ' &nbsp;—&nbsp; <span class="fa-ml-sp fa-ml-sp-full">full</span> <span class="fa-ml-sp fa-ml-sp-part">partial</span> <span class="fa-ml-sp fa-ml-sp-none">missing</span>'
              . '</div>';

        // ── Filter bar ────────────────────────────────────────────────────────
        $out .= '<div class="fa-bar">'
              . '<div class="uk-inline"><span class="uk-form-icon" uk-icon="icon:search"></span>'
              . '<input class="uk-input uk-form-small" style="width:240px" type="text" id="fa-ml-q" placeholder="Filter by field name…" oninput="faML()"></div>'
              . '<label class="uk-text-small"><input class="uk-checkbox" type="checkbox" id="fa-ml-hs" onchange="faML()" checked> Hide system fields</label>'
              . '<label class="uk-text-small"><input class="uk-checkbox" type="checkbox" id="fa-ml-inc" onchange="faML()"> Incomplete only</label>'
              . '</div>';

        // ── Table ─────────────────────────────────────────────────────────────
        $out .= '<table class="uk-table uk-table-divider uk-table-hover uk-table-small fa-ml-tbl" id="fa-ml-tbl"><thead>';

        $defaultLang = $languages->getDefault();
        $defaultLangName = htmlspecialchars($defaultLang->title ?: $defaultLang->name);

        // Header row 1: grouped language headers
        $out .= '<tr><th rowspan="2">Field</th><th rowspan="2">Type</th><th rowspan="2">Default label</th>';
        $out .= '<th colspan="3" class="fa-ml-lhdr">' . $defaultLangName . '</th>';
        foreach ($otherLangs as $lang) {
            $out .= '<th colspan="4" class="fa-ml-lhdr">' . htmlspecialchars($lang->title ?: $lang->name) . '</th>';
        }
        $out .= '</tr>';

        // Header row 2: L D N for default, L D N V per other language
        $out .= '<tr>';
        $out .= '<th class="fa-ml-shdr" title="Label">L</th>'
              . '<th class="fa-ml-shdr" title="Description">D</th>'
              . '<th class="fa-ml-shdr" title="Notes">N</th>';
        foreach ($otherLangs as $lang) {
            $out .= '<th class="fa-ml-shdr" title="Label">L</th>'
                  . '<th class="fa-ml-shdr" title="Description">D</th>'
                  . '<th class="fa-ml-shdr" title="Notes">N</th>'
                  . '<th class="fa-ml-shdr" title="Values (page content)">V</th>';
        }
        $out .= '</tr></thead><tbody>';

        foreach ($rows as $fname => $row) {
            $field    = $row['field'];
            $hasV     = $row['hasValues'];
            $anyMiss  = false;
            $dt       = $row['defaultTrans'];

            // Default language cells (L D N only, no V)
            $cells  = $dt['label'] !== ''
                ? '<td><span class="uk-text-success" style="font-weight:700;cursor:default" title="' . htmlspecialchars($dt['label']) . '">✓</span></td>'
                : '<td><span class="fa-ml-dash">—</span></td>';
            $cells .= $dt['description'] !== ''
                ? '<td><span class="uk-text-primary" style="font-weight:700;cursor:default" title="' . htmlspecialchars(mb_substr($dt['description'], 0, 80)) . '">✓</span></td>'
                : '<td><span class="fa-ml-dash">—</span></td>';
            $cells .= $dt['notes'] !== ''
                ? '<td><span class="uk-text-secondary" style="font-weight:700;cursor:default" title="' . htmlspecialchars(mb_substr($dt['notes'], 0, 80)) . '">✓</span></td>'
                : '<td><span class="fa-ml-dash">—</span></td>';

            foreach ($otherLangs as $lang) {
                $t = $row['trans'][$lang->id];

                // L
                if ($t['label'] !== '') {
                    $cells .= '<td><span class="uk-text-success" style="font-weight:700;cursor:default" title="' . htmlspecialchars($t['label']) . '">✓</span></td>';
                } else {
                    $anyMiss = true;
                    $cells .= '<td><span class="uk-text-danger" style="font-weight:700;cursor:default">✗</span></td>';
                }

                // D
                if ($t['description'] !== '') {
                    $cells .= '<td><span class="uk-text-primary" style="font-weight:700;cursor:default" title="' . htmlspecialchars(mb_substr($t['description'], 0, 80)) . '">✓</span></td>';
                } else {
                    $cells .= '<td><span class="fa-ml-dash">—</span></td>';
                }

                // N
                if ($t['notes'] !== '') {
                    $cells .= '<td><span class="uk-text-secondary" style="font-weight:700;cursor:default" title="' . htmlspecialchars(mb_substr($t['notes'], 0, 80)) . '">✓</span></td>';
                } else {
                    $cells .= '<td><span class="fa-ml-dash">—</span></td>';
                }

                // V
                $v = $t['values'];
                if ($v === null) {
                    $cells .= '<td><span class="fa-ml-dash" title="Field values are not multilingual">—</span></td>';
                } elseif ($v['total'] === 0) {
                    $cells .= '<td><span class="fa-ml-dash" title="No page values exist yet">—</span></td>';
                } elseif ($v['translated'] === 0) {
                    $anyMiss = true;
                    $cells .= '<td><span class="uk-text-danger" style="font-weight:700;cursor:default" title="0/' . $v['total'] . ' values translated">✗</span></td>';
                } elseif ($v['translated'] < $v['total']) {
                    $anyMiss = true;
                    $pct = round($v['translated'] / $v['total'] * 100);
                    $cells .= '<td><span class="fa-ml-part-v" title="' . $v['translated'] . '/' . $v['total'] . '">' . $pct . '%</span></td>';
                } else {
                    $cells .= '<td><span class="uk-text-success" style="font-weight:700;cursor:default" title="All ' . $v['total'] . ' values translated">✓</span></td>';
                }
            }

            $out .= '<tr'
                  . ' data-name="' . htmlspecialchars($fname) . '"'
                  . ' data-full="' . ($anyMiss ? '0' : '1') . '"'
                  . ' data-sys="' . ($row['isSys'] ? '1' : '0') . '"'
                  . ($row['isSys'] ? ' class="fa-sys"' : '') . '>'
                  . $this->fieldNameCell($field)
                  . '<td><span class="fa-ttag">' . htmlspecialchars(preg_replace('/^Fieldtype/', '', $field->type->className())) . '</span></td>'
                  . '<td class="fa-flabel">' . htmlspecialchars($field->label ?: '') . '</td>'
                  . $cells
                  . '</tr>';
        }

        $out .= '</tbody></table>';
        $out .= '<script>
function faML(){
    var q=document.getElementById("fa-ml-q").value.toLowerCase();
    var hs=document.getElementById("fa-ml-hs").checked;
    var inc=document.getElementById("fa-ml-inc").checked;
    document.querySelectorAll("#fa-ml-tbl tbody tr").forEach(function(r){
        var show=true;
        if(q && !r.dataset.name.toLowerCase().includes(q)) show=false;
        if(hs && r.dataset.sys==="1") show=false;
        if(inc && r.dataset.full==="1") show=false;
        r.style.display=show?"":"none";
    });
}
faML();
</script>';
        return $out;
    }

    protected function renderTemplates() {
        $database = $this->wire('database');
        $adminUrl = $this->wire('config')->urls->admin;
        $sysNames = ['admin','user','role','permission','language','languages','form-builder'];

        $templates = [];
        foreach ($this->wire('templates') as $tpl) {
            if ($tpl->flags & Template::flagSystem) continue;
            if (preg_match('/^repeater_/', $tpl->name)) continue;
            if (in_array($tpl->name, $sysNames)) continue;
            $templates[$tpl->name] = $tpl;
        }
        ksort($templates);

        if (empty($templates)) {
            return '<div class="fa-ml-notice">No templates found.</div>';
        }

        // Bulk page counts via single SQL query
        $pageCounts = [];
        $ids = implode(',', array_map(fn($t) => (int)$t->id, $templates));
        if ($ids) {
            $prows = $database->query("SELECT templates_id, COUNT(*) as cnt FROM pages WHERE templates_id IN ($ids) GROUP BY templates_id")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($prows as $pr) $pageCounts[(int)$pr['templates_id']] = (int)$pr['cnt'];
        }

        $out  = '<h2 class="fa-h2">Templates (' . count($templates) . ')</h2>';
        $out .= '<div class="fa-bar">'
              . '<div class="uk-inline"><span class="uk-form-icon" uk-icon="icon:search"></span>'
              . '<input class="uk-input uk-form-small" style="width:240px" type="text" id="fa-tpl-q" placeholder="Filter by template name…" oninput="faTpl()"></div>'
              . '</div>';

        $out .= '<table class="uk-table uk-table-divider uk-table-hover uk-table-small fa-tbl" id="fa-tpl-tbl"><thead><tr>'
              . '<th>Template</th>'
              . '<th style="width:56px;text-align:center">Pages</th>'
              . '<th style="width:56px;text-align:center">Fields</th>'
              . '<th>Fields</th>'
              . '</tr></thead><tbody>';

        foreach ($templates as $name => $tpl) {
            $tplUrl    = $adminUrl . 'setup/template/edit?id=' . $tpl->id;
            $pgCount   = $pageCounts[$tpl->id] ?? 0;
            $tplFields = [];
            foreach ($tpl->fields as $f) {
                if ($f instanceof Field) $tplFields[] = $f;
            }

            $fieldPills = '';
            foreach ($tplFields as $f) {
                $shortType   = preg_replace('/^Fieldtype/', '', $f->type->className());
                $fieldPills .= '<a href="' . $this->fieldEditUrl($f) . '" class="fa-fpill" title="' . htmlspecialchars($shortType . ($f->label ? ': ' . $f->label : '')) . '">'
                             . htmlspecialchars($f->name) . '</a>';
            }
            if (!$fieldPills) $fieldPills = '<span class="fa-dash">—</span>';

            $label = $tpl->label ? ' <span class="fa-flabel" style="font-family:sans-serif;font-weight:400">' . htmlspecialchars($tpl->label) . '</span>' : '';

            $out .= '<tr data-name="' . htmlspecialchars($name) . '">'
                  . '<td class="fa-fname">'
                  . '<a href="' . $tplUrl . '" class="fa-flink">' . htmlspecialchars($name) . '</a>'
                  . $label
                  . '<a href="' . $tplUrl . '" class="pw-panel fa-panel-btn" data-panel-width="75%">'
                  . '<small class="ui-button-text"><i class="fa fa-fw fa-eye"></i><span>Edit</span></small>'
                  . '</a>'
                  . '</td>'
                  . '<td style="text-align:center"><span class="fa-badge">' . $pgCount . '</span></td>'
                  . '<td style="text-align:center"><span class="fa-badge">' . count($tplFields) . '</span></td>'
                  . '<td><div class="fa-tfields">' . $fieldPills . '</div></td>'
                  . '</tr>';
        }

        $out .= '</tbody></table>';
        $out .= '<script>
function faTpl(){
    var q=document.getElementById("fa-tpl-q").value.toLowerCase();
    document.querySelectorAll("#fa-tpl-tbl tbody tr").forEach(function(r){
        r.style.display=(!q||r.dataset.name.includes(q))?"":"none";
    });
}
</script>';
        return $out;
    }

    protected function prettify($s) {
        $s = preg_replace('/^(matrix_|media_|details_|type_|repeater_)/', '', $s);
        return ucwords(str_replace('_', ' ', $s));
    }

    // ── Styles ────────────────────────────────────────────────────────────────

    protected function styles() {
        return '<style>
/* Field Audit — supplemental only; UIkit + --pw-* vars loaded by admin theme */
.fa-h2{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--pw-muted-color);border-bottom:1px solid var(--pw-border-color);padding-bottom:8px;margin-bottom:16px;margin-top:0}
/* Matrix */
.fa-matrix{border:1px solid var(--pw-border-color);border-radius:4px;margin-bottom:8px;overflow:hidden}
.fa-mhdr{background:var(--pw-text-color);color:var(--pw-blocks-background);display:flex;align-items:center;gap:10px;padding:9px 14px}
.fa-mname{font-family:\'SFMono-Regular\',Consolas,monospace;font-size:12px;font-weight:700;color:var(--pw-blocks-background);text-decoration:none}
.fa-mname:hover{color:var(--pw-main-color)}
.fa-mlabel{font-size:12px;opacity:.5}
.fa-mcount{margin-left:auto;font-family:monospace;font-size:10px;opacity:.5;background:rgba(128,128,128,.25);padding:2px 8px;border-radius:10px}
.fa-tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1px;background:var(--pw-border-color)}
.fa-type{background:var(--pw-blocks-background);padding:10px 14px}
.fa-thdr{display:flex;align-items:center;gap:6px;margin-bottom:6px}
.fa-slug{font-family:monospace;font-size:10px;background:var(--pw-main-color);color:#fff;padding:2px 6px;border-radius:3px;white-space:nowrap}
.fa-tdisplay{font-size:12px;font-weight:500;color:var(--pw-text-color);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fa-tid{font-family:monospace;font-size:10px;color:var(--pw-muted-color)}
.fa-tfields{display:flex;flex-wrap:wrap;gap:3px}
.fa-fpill{font-family:monospace;font-size:10px;background:var(--pw-inputs-background);color:var(--pw-text-color);padding:2px 5px;border-radius:3px;text-decoration:none;border:1px solid var(--pw-border-color)}
.fa-fpill:hover{background:var(--pw-main-color);color:#fff;border-color:var(--pw-main-color)}
.fa-empty{font-size:11px;color:var(--pw-muted-color);font-style:italic;padding:10px 14px}
.fa-none-small{font-size:10px;color:var(--pw-muted-color);font-style:italic}
/* Filter bar */
.fa-bar{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px}
.fa-bar label{display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none}
/* Table */
.fa-tbl th{white-space:nowrap;position:sticky;top:0;z-index:1;background:var(--pw-inputs-background)}
.fa-rm>td{background:color-mix(in srgb,var(--pw-main-color) 5%,var(--pw-blocks-background)) !important}
.fa-sys{opacity:.4}
.fa-fname{font-family:\'SFMono-Regular\',Consolas,monospace;font-size:11px;font-weight:600;white-space:nowrap}
.fa-flink{color:inherit;text-decoration:none}
.fa-flink:hover{color:var(--pw-main-color)}
.fa-panel-btn{opacity:0;display:inline-flex!important;align-items:center;gap:3px;margin-left:6px;padding:1px 5px;position:static!important;right:auto!important;color:var(--pw-muted-color)!important;font-size:11px;text-decoration:none!important;vertical-align:middle;transition:opacity .15s;background:none!important;border:none!important}
.fa-fname:hover .fa-panel-btn,.fa-panel-btn:focus{opacity:1}
.fa-panel-btn:hover{color:var(--pw-main-color)!important}
.fa-panel-btn .pw-panel-button{display:none!important}
.fa-flabel{color:var(--pw-muted-color)}
.fa-ttag{font-family:monospace;font-size:10px;background:var(--pw-main-color);color:#fff;padding:2px 6px;border-radius:3px;white-space:nowrap}
.fa-mf{display:inline-block;font-family:monospace;font-size:10px;background:var(--pw-text-color);color:var(--pw-blocks-background);padding:1px 5px;border-radius:3px}
.fa-mt{display:inline-block;font-family:monospace;font-size:10px;background:var(--pw-alert-danger);color:var(--pw-error-inline-text-color);padding:1px 5px;border-radius:3px}
.fa-dash{color:var(--pw-muted-color);font-size:11px}
details summary{cursor:pointer;color:var(--pw-main-color);font-size:11px;list-style:none}
.fa-tlist{font-family:monospace;font-size:10px;color:var(--pw-muted-color);margin-top:4px;line-height:1.8}
/* Multilingual */
.fa-ml-notice{font-size:13px;color:var(--pw-muted-color);font-style:italic;padding:16px 0}
.fa-ml-stats{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.fa-ml-stat{display:flex;align-items:center;gap:8px;background:var(--pw-inputs-background);border:1px solid var(--pw-border-color);border-radius:4px;padding:7px 12px}
.fa-ml-stat-lang{font-size:11px;font-weight:700;color:var(--pw-text-color);min-width:26px}
.fa-ml-stat-pills{display:flex;gap:4px}
.fa-ml-sp{font-family:monospace;font-size:10px;font-weight:600;padding:2px 6px;border-radius:3px;cursor:default}
.fa-ml-sp-full{background:var(--pw-alert-success);color:var(--pw-text-color)}
.fa-ml-sp-part{background:var(--pw-alert-warning);color:var(--pw-text-color)}
.fa-ml-sp-none{background:var(--pw-alert-danger);color:var(--pw-text-color)}
.fa-ml-legend{font-size:11px;color:var(--pw-muted-color);margin-bottom:10px}
.fa-ml-legend b{color:var(--pw-text-color)}
.fa-ml-tbl td{text-align:center;vertical-align:middle;padding:5px 6px}
.fa-ml-tbl td:nth-child(1),.fa-ml-tbl td:nth-child(2),.fa-ml-tbl td:nth-child(3){text-align:left;padding:5px 10px}
.fa-ml-lhdr{text-align:center!important;border-left:2px solid var(--pw-border-color);letter-spacing:0}
.fa-ml-shdr{text-align:center!important;font-size:9px;font-weight:700;color:var(--pw-muted-color);width:32px;border-left:1px solid var(--pw-border-color)}
.fa-ml-shdr:nth-child(4n+1){border-left:2px solid var(--pw-border-color)}
.fa-ml-part-v{font-family:monospace;font-size:10px;font-weight:700;cursor:default;color:var(--pw-alert-warning)}
.fa-ml-dash{font-size:11px;color:var(--pw-muted-color);cursor:default}
/* Overall progress bar */
.fa-ml-overall{background:var(--pw-inputs-background);border:1px solid var(--pw-border-color);border-radius:4px;padding:12px 16px;margin-bottom:14px}
.fa-ml-overall-row{display:flex;align-items:center;gap:10px;margin-bottom:7px}
.fa-ml-overall-row:last-child{margin-bottom:0}
.fa-ml-overall-lbl{font-size:11px;font-weight:600;color:var(--pw-muted-color);min-width:110px}
.fa-ml-bar-wrap{flex:1;height:8px;background:var(--pw-border-color);border-radius:4px;overflow:hidden}
.fa-ml-bar{height:100%;border-radius:4px;transition:width .4s ease}
.fa-ml-overall-pct{font-family:monospace;font-size:13px;font-weight:700;color:var(--pw-text-color);min-width:38px;text-align:right}
.fa-ml-overall-sub{font-family:monospace;font-size:10px;color:var(--pw-muted-color);white-space:nowrap}
/* Badge */
.fa-badge{font-family:monospace;font-size:10px;color:var(--pw-muted-color);padding:2px 7px;border-radius:10px;border:1px solid var(--pw-border-color)}
</style>';
    }
}
