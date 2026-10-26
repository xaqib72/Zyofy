<?php
/**
 * Reading an Amazon Associates export.
 *
 * Shared by the analyse step (which only looks at the file) and the import step
 * (which writes it), so the two can never disagree about what the file contains.
 *
 * Function names are prefixed dd_ because the old uploader still defines its own
 * copies of these and both must be able to exist in the codebase during the rebuild.
 */

function dd_normalize_header($value)
{
    $value = (string) $value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);   // strip a UTF-8 BOM
    $value = strtolower(trim($value));
    $value = str_replace(array('_', '-'), ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function dd_find_header_index($normalizedHeader, $acceptedNames)
{
    foreach ($acceptedNames as $acceptedName) {
        $index = array_search($acceptedName, $normalizedHeader, true);
        if ($index !== false) {
            return $index;
        }
    }

    return false;
}

/**
 * Amazon writes money with the local thousands/decimal separator and sometimes
 * wraps negatives in brackets. Anything unreadable becomes 0 rather than throwing,
 * because one odd cell should not stop a whole month importing.
 */
function dd_numeric_value($row, $index)
{
    if ($index === false || !isset($row[$index])) {
        return 0.0;
    }

    $raw = trim((string) $row[$index]);
    if ($raw === '' || $raw === '-' || $raw === '--') {
        return 0.0;
    }

    $negative = strlen($raw) > 1 && $raw[0] === '(' && substr($raw, -1) === ')';
    $clean = preg_replace('/[^0-9.\-]/', '', $raw);

    if ($clean === '' || $clean === '-' || !is_numeric($clean)) {
        return 0.0;
    }

    $number = (float) $clean;
    return $negative ? -abs($number) : $number;
}

function dd_column_letters_to_index($letters)
{
    $letters = strtoupper((string) $letters);
    $index = 0;
    $length = strlen($letters);

    for ($i = 0; $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
    }

    return $index - 1;
}

function dd_xml_from_string($xml, $label)
{
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($parsed === false) {
        throw new RuntimeException('Invalid XML inside the XLSX file (' . $label . ').');
    }

    return $parsed;
}

function dd_read_xlsx_rows($path)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled. Enable it or upload a CSV file.');
    }
    if (!class_exists('SimpleXMLElement')) {
        throw new RuntimeException('The PHP SimpleXML extension is not enabled. Enable it or upload a CSV file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The XLSX file is invalid, corrupted, or is an old .xls file renamed to .xlsx.');
    }

    try {
        $sharedStrings = array();
        $sharedXmlText = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedXmlText !== false) {
            $sharedXml = dd_xml_from_string($sharedXmlText, 'shared strings');
            $stringItems = $sharedXml->xpath('//*[local-name()="si"]');
            if (is_array($stringItems)) {
                foreach ($stringItems as $stringItem) {
                    $parts = $stringItem->xpath('.//*[local-name()="t"]');
                    $text = '';
                    if (is_array($parts)) {
                        foreach ($parts as $part) {
                            $text .= (string) $part;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXmlText = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXmlText === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $name)) {
                    $sheetXmlText = $zip->getFromName($name);
                    break;
                }
            }
        }

        if ($sheetXmlText === false) {
            throw new RuntimeException('No worksheet was found inside the XLSX file.');
        }

        $sheetXml = dd_xml_from_string($sheetXmlText, 'worksheet');
        $sheetRows = $sheetXml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        $rows = array();

        if (!is_array($sheetRows)) {
            return $rows;
        }

        foreach ($sheetRows as $sheetRow) {
            $rowData = array();
            $cells = $sheetRow->xpath('./*[local-name()="c"]');

            if (!is_array($cells)) {
                $rows[] = $rowData;
                continue;
            }

            foreach ($cells as $cell) {
                $attributes = $cell->attributes();
                $reference = isset($attributes['r']) ? (string) $attributes['r'] : '';
                $type = isset($attributes['t']) ? (string) $attributes['t'] : '';
                $columnIndex = count($rowData);

                if ($reference !== '' && preg_match('/^([A-Z]+)/i', $reference, $matches)) {
                    $columnIndex = dd_column_letters_to_index($matches[1]);
                }

                $value = '';
                if ($type === 'inlineStr') {
                    $textNodes = $cell->xpath('./*[local-name()="is"]//*[local-name()="t"]');
                    if (is_array($textNodes)) {
                        foreach ($textNodes as $textNode) {
                            $value .= (string) $textNode;
                        }
                    }
                } else {
                    $valueNodes = $cell->xpath('./*[local-name()="v"]');
                    if (is_array($valueNodes) && isset($valueNodes[0])) {
                        $value = (string) $valueNodes[0];
                    }
                    if ($type === 's') {
                        $sharedIndex = (int) $value;
                        $value = isset($sharedStrings[$sharedIndex]) ? $sharedStrings[$sharedIndex] : '';
                    }
                }

                $rowData[$columnIndex] = $value;
            }

            // Cells for empty columns are simply absent from the XML, so fill the gaps
            // before reindexing or every column after a blank one would shift left.
            if (!empty($rowData)) {
                $lastIndex = max(array_keys($rowData));
                for ($i = 0; $i <= $lastIndex; $i++) {
                    if (!array_key_exists($i, $rowData)) {
                        $rowData[$i] = '';
                    }
                }
                ksort($rowData);
                $rowData = array_values($rowData);
            }

            $rows[] = $rowData;
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

function dd_detect_csv_delimiter($handle)
{
    $firstLine = fgets($handle);
    if ($firstLine === false) {
        return ',';
    }

    rewind($handle);
    $candidates = array(
        ','  => substr_count($firstLine, ','),
        "\t" => substr_count($firstLine, "\t"),
        ';'  => substr_count($firstLine, ';'),
    );
    arsort($candidates);
    $delimiter = key($candidates);

    return reset($candidates) > 0 ? $delimiter : ',';
}

function dd_read_csv_rows($path)
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The uploaded CSV file could not be opened.');
    }

    $rows = array();
    try {
        $delimiter = dd_detect_csv_delimiter($handle);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
            if (count($rows) > 100001) {
                throw new RuntimeException('The report contains more than 100,000 data rows. Please split it into smaller files.');
            }
        }
    } finally {
        fclose($handle);
    }

    return $rows;
}

function dd_read_report_rows($path, $extension)
{
    return $extension === 'xlsx' ? dd_read_xlsx_rows($path) : dd_read_csv_rows($path);
}

/**
 * Where original uploads are kept.
 *
 * Outside public_html on purpose — an Amazon earnings export sitting under the web
 * root would let anybody who guessed the filename read every contributor's figures.
 */
function dd_report_archive_dir()
{
    // Anchored on the application folder rather than by counting levels from this
    // file — the first version counted from admin/lib and landed a directory short.
    $appRoot = dirname(__DIR__, 2);   // admin/lib -> admin -> pickwell-admin
    $home    = dirname($appRoot, 4);  // -> public_html -> pickwell.site -> domains -> home

    return $home . DIRECTORY_SEPARATOR . 'report-archive';
}

/**
 * Keeps a copy of the file an import was built from, named so it can be found again.
 *
 * Returns the stored filename, or '' if it could not be saved. A failure here must
 * never fail the import: the figures are already in, and losing the archive copy is
 * an inconvenience, not a data problem.
 */
function dd_archive_source_file($tmpPath, $uploadId, $reportMonth, $marketplace, $originalName)
{
    $directory = dd_report_archive_dir();

    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
        error_log('report archive: cannot create ' . $directory);
        return '';
    }

    $country = preg_replace('/[^a-z0-9]+/i', '-', str_replace('www.amazon.', '', $marketplace));
    $safeName = preg_replace('/[^a-z0-9._-]+/i', '-', $originalName);
    $stored = (int) $uploadId . '-' . $reportMonth . '-' . trim($country, '-') . '-' . $safeName;

    if (!@copy($tmpPath, $directory . DIRECTORY_SEPARATOR . $stored)) {
        error_log('report archive: cannot copy to ' . $directory . '/' . $stored);
        return '';
    }

    @chmod($directory . DIRECTORY_SEPARATOR . $stored, 0600);
    return $stored;
}

/**
 * Works out which spreadsheet column holds which figure.
 *
 * Amazon exports these twelve columns in a fixed order whatever the account
 * language, so when the sheet has exactly twelve columns position is trusted over
 * name. That matters because French reuses the same wording for the "revenue" and
 * "earnings" columns, and name matching alone cannot tell those two apart.
 */
function dd_map_columns($normalizedHeader)
{
    $map = array(
        'tracking_id' => dd_find_header_index($normalizedHeader, array(
            'tracking id', 'tracking number',
            'id de seguimiento', 'numero de seguimiento',
            'sendungsverfolgungsnummer', 'tracking nummer',
            'numero de suivi', 'id de suivi',
            'id di tracciamento', 'numero di tracciamento',
        )),
        'clicks' => dd_find_header_index($normalizedHeader, array(
            'clicks', 'clics', 'klicks', 'clic',
        )),
        'items_ordered' => dd_find_header_index($normalizedHeader, array(
            'items ordered', 'productos pedidos', 'articulos pedidos',
            'bestellte artikel', 'articles commandes', 'articoli ordinati',
        )),
        'ordered_revenue' => dd_find_header_index($normalizedHeader, array(
            'ordered revenue', 'ingresos de pedidos', 'ingresos por pedidos',
            'bestellte umsatze', 'revenus commandes', 'ricavi ordinati',
        )),
        'items_shipped' => dd_find_header_index($normalizedHeader, array(
            'items shipped', 'productos enviados', 'articulos enviados',
            'versendete artikel', 'articles expedies', 'articoli spediti',
        )),
        'items_returned' => dd_find_header_index($normalizedHeader, array(
            'items returned', 'productos devueltos', 'articulos devueltos',
            'zuruckgegebene artikel', 'articles retournes', 'articoli restituiti',
        )),
        'items_shipped_revenue' => dd_find_header_index($normalizedHeader, array(
            'items shipped revenue', 'ingresos por productos enviados',
            'umsatz mit versendeten artikeln', 'revenus des articles expedies',
            'ricavi articoli spediti',
        )),
        'items_returned_revenue' => dd_find_header_index($normalizedHeader, array(
            'items returned revenue', 'ingresos por productos devueltos',
            'umsatz mit zuruckgegebenen artikeln', 'revenus des articles retournes',
            'ricavi articoli restituiti',
        )),
        'total_earnings' => dd_find_header_index($normalizedHeader, array(
            'total earnings', 'ganancias totales',
            'gesamteinnahmen', 'revenus totaux', 'guadagni totali',
        )),
        'bonus' => dd_find_header_index($normalizedHeader, array(
            'bonus', 'bonificaciones', 'bonifications', 'bonus totali',
        )),
        'items_shipped_earnings' => dd_find_header_index($normalizedHeader, array(
            'items shipped earnings', 'items dispatched earnings',
            'ganancias por productos enviados',
            'einnahmen aus versendeten artikeln',
            'guadagni articoli spediti',
        )),
        'items_returned_earnings' => dd_find_header_index($normalizedHeader, array(
            'items returned earnings', 'ganancias por productos devueltos',
            'einnahmen aus zuruckgegebenen artikeln',
            'guadagni articoli restituiti',
        )),
    );

    $standardOrder = array(
        'tracking_id', 'clicks', 'items_ordered', 'ordered_revenue',
        'items_shipped', 'items_returned', 'items_shipped_revenue',
        'items_returned_revenue', 'total_earnings', 'bonus',
        'items_shipped_earnings', 'items_returned_earnings',
    );

    if (count($normalizedHeader) === count($standardOrder)) {
        foreach ($standardOrder as $position => $field) {
            $map[$field] = $position;
        }
    }

    return $map;
}
