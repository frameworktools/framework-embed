<?php

declare(strict_types=1);

namespace Framework\Embed;

use stdClass;

/**
 * framework.embed/v1 -> HTML. A line-for-line port of js/src/render.js: both
 * produce the same bytes for every document in spec/fixtures.
 */
final class Renderer
{
    public const ROW_LIMIT = 25;

    private const KINDS = ['model', 'table', 'test', 'image', 'vega-lite', 'page'];

    private const COLUMN_LABELS = [
        'term' => 'Term', 'estimate' => 'Estimate', 'std_error' => 'Std. error', 'statistic' => 'Statistic', 'p_value' => 'p-value',
        'conf_low' => 'CI lower', 'conf_high' => 'CI upper', 'group' => 'Group', 'variance' => 'Variance', 'std_dev' => 'Std. dev.',
        'correlation' => 'Correlation', 'feature' => 'Feature', 'importance' => 'Importance', 'edf' => 'EDF', 'ref_df' => 'Ref. df', 'df' => 'df',
    ];

    private const METRIC_LABELS = [
        'nobs' => 'Observations', 'df_resid' => 'Residual df', 'df_model' => 'Model df', 'aic' => 'AIC', 'bic' => 'BIC',
        'rsquared' => 'R²', 'r.squared' => 'R²', 'rsquared_adj' => 'Adjusted R²', 'adj.r.squared' => 'Adjusted R²', 'pseudo_rsquared' => 'Pseudo R²',
        'sigma' => 'Residual std. dev.', 'llf' => 'Log likelihood', 'log_likelihood' => 'Log likelihood', 'deviance' => 'Deviance',
        'null.deviance' => 'Null deviance', 'dispersion' => 'Dispersion', 'converged' => 'Converged', 'reml' => 'REML',
        'confidence_level' => 'Confidence level', 'interval_method' => 'Interval method', 'vcov_type' => 'Standard errors',
        'n_estimators' => 'Trees', 'max_depth' => 'Maximum depth', 'learning_rate' => 'Learning rate', 'n_features' => 'Features',
        'importance_type' => 'Importance type', 'objective' => 'Objective', 'n_events' => 'Events', 'concordance' => 'Concordance',
        'theta' => 'Theta', 'command' => 'Command',
    ];

    private const INTERVAL_METHODS = ['wald-t' => 'Wald t', 'wald-normal' => 'Wald normal', 'profile' => 'Profile likelihood', 'bootstrap' => 'Bootstrap'];

    private const MODEL_TYPES = [
        'linear' => 'Linear model', 'glm' => 'Generalized linear model', 'mixed' => 'Mixed model', 'survival' => 'Survival model',
        'ordinal' => 'Ordinal model', 'multinomial' => 'Multinomial model', 'additive' => 'Additive model', 'fixed_effects' => 'Fixed-effects model',
        'quantile' => 'Quantile regression', 'linear_ml' => 'Linear model', 'tree_ensemble' => 'Tree ensemble',
    ];

    private const ALTERNATIVES = ['two.sided' => 'Two-sided alternative', 'less' => 'One-sided alternative (less)', 'greater' => 'One-sided alternative (greater)'];

    private const COEFFICIENT_COLUMNS = ['term', 'estimate', 'std_error', 'statistic', 'p_value', 'conf_low', 'conf_high', 'df'];

    /** Render a decoded embed document. Pass `spec` as a stdClass (Document::decode does) to keep empty JSON objects. */
    public static function render(array|stdClass $doc): string
    {
        if ($doc instanceof stdClass) {
            $spec = $doc->spec ?? null;
            $doc = json_decode((string) json_encode($doc), true);
            $doc['spec'] = $spec;
        }
        if ($doc !== [] && array_is_list($doc)) {
            return '';
        }
        $kind = in_array($doc['kind'] ?? null, self::KINDS, true) ? $doc['kind'] : 'page';
        $body = match ($kind) {
            'model' => self::model($doc),
            'table' => self::tableKind($doc),
            'test' => self::test($doc),
            'image' => self::image($doc),
            'vega-lite' => self::chart($doc),
            default => null,
        };
        if ($body === null) {
            $kind = 'page';
            $body = self::page($doc);
        }
        $open = '<figure class="fw-embed fw-embed--' . $kind . '" data-fw-embed-kind="' . $kind . '"';
        if (self::text($doc['key'] ?? null) !== null) {
            $open .= ' data-fw-embed-key="' . self::e($doc['key']) . '"';
        }
        if (Format::isInteger($doc['version'] ?? null)) {
            $open .= ' data-fw-embed-version="' . self::integer($doc['version']) . '"';
        }

        return implode("\n", [$open . '>', '<div class="fw-embed__body">', $body, '</div>', self::caption($doc, $kind), '</figure>', '']);
    }

    /** Render embed JSON as served by Framework Cloud. */
    public static function renderJson(string $json): string
    {
        $doc = Document::decode($json);

        return $doc === null ? '' : self::render($doc);
    }

    public static function e(mixed $value): string
    {
        return strtr((string) $value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    /** http(s) URLs without whitespace, quotes, angle brackets, backslashes, or control characters. */
    public static function safeUrl(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^https?:\/\/[^\x00-\x20\x7f"\'<>\\\\`]+$/iD', $value) === 1 ? $value : null;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    private static function obj(mixed $value): array
    {
        return self::isObject($value) ? $value : [];
    }

    private static function lst(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function integer(int|float $value): string
    {
        return is_int($value) ? (string) $value : Format::fixed($value, 0);
    }

    private static function caption(array $doc, string $kind): string
    {
        $parts = ['<figcaption class="fw-embed__caption">'];
        if ($kind !== 'page') {
            if (self::text($doc['title'] ?? null) !== null) {
                $parts[] = '<span class="fw-embed__title">' . self::e($doc['title']) . '</span>';
            }
            if (self::text($doc['description'] ?? null) !== null) {
                $parts[] = '<span class="fw-embed__description">' . self::e($doc['description']) . '</span>';
            }
            $author = self::obj($doc['author'] ?? null);
            if (self::text($author['name'] ?? null) !== null) {
                $href = self::safeUrl($author['url'] ?? null);
                $name = self::e($author['name']);
                $parts[] = '<span class="fw-embed__author">' . ($href === null ? $name : '<a href="' . self::e($href) . '">' . $name . '</a>') . '</span>';
            }
        }
        $attribution = self::obj($doc['attribution'] ?? null);
        $credit = self::e(self::text($attribution['text'] ?? null) ?? 'Framework');
        $href = self::safeUrl($attribution['url'] ?? null) ?? self::safeUrl($doc['url'] ?? null);
        $parts[] = $href === null
            ? '<span class="fw-embed__credit">' . $credit . '</span>'
            : '<a class="fw-embed__credit" href="' . self::e($href) . '" target="_blank" rel="noopener">' . $credit . '</a>';
        $parts[] = '</figcaption>';

        return implode("\n", $parts);
    }

    // --- data kinds ---------------------------------------------------------

    private static function usableTables(array $data): array
    {
        $tables = [];
        foreach (self::lst($data['tables'] ?? null) as $table) {
            if (! self::isObject($table) || ! is_array($table['rows'] ?? null) || ! array_is_list($table['rows'])
                || ! is_array($table['columns'] ?? null) || ! array_is_list($table['columns']) || $table['columns'] === []) {
                continue;
            }
            foreach ($table['columns'] as $column) {
                if (! self::isObject($column) || self::text($column['name'] ?? null) === null) {
                    continue 2;
                }
            }
            $tables[] = $table;
        }

        return $tables;
    }

    private static function usableMetrics(array $data): array
    {
        return array_values(array_filter(self::lst($data['metrics'] ?? null),
            fn ($metric) => self::isObject($metric) && self::text($metric['name'] ?? null) !== null));
    }

    private static function model(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data)) {
            return null;
        }
        $tables = self::usableTables($data);
        $metrics = self::usableMetrics($data);
        $formula = self::text($data['formula'] ?? null);
        if ($tables === [] && $metrics === [] && $formula === null) {
            return null;
        }
        $model = self::obj($data['model'] ?? null);
        $parts = [];
        $meta = self::modelMeta($model);
        if ($meta !== null) {
            $parts[] = '<p class="fw-embed__meta">' . self::e($meta) . '</p>';
        }
        if ($formula !== null) {
            $parts[] = '<p class="fw-embed__formula"><code>' . self::e($formula) . '</code></p>';
        }
        $level = null;
        foreach ($metrics as $metric) {
            if ($metric['name'] === 'confidence_level' && Format::isNumber($metric['value'] ?? null)) {
                $level = $metric['value'];
                break;
            }
        }
        foreach ($tables as $index => $table) {
            $role = $table['role'] ?? null;
            $coefficients = ($role === 'coefficients' || ($role === null && $index === 0))
                && self::columnIndex($table, 'term') >= 0 && self::columnIndex($table, 'estimate') >= 0;
            $parts[] = $coefficients ? self::coefficients($table, $model, $level, $doc) : self::table($table, true, true, $doc);
        }
        if ($metrics !== []) {
            $parts[] = self::metrics($metrics);
        }

        return implode("\n", $parts);
    }

    private static function tableKind(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data)) {
            return null;
        }
        $tables = self::usableTables($data);
        if ($tables === []) {
            return null;
        }
        $parts = [];
        foreach ($tables as $table) {
            $parts[] = self::table($table, count($tables) > 1, false, $doc);
        }
        $metrics = self::usableMetrics($data);
        if ($metrics !== []) {
            $parts[] = self::metrics($metrics);
        }

        return implode("\n", $parts);
    }

    private static function test(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data) || ! self::isObject($data['test'] ?? null) || self::text($data['test']['method'] ?? null) === null) {
            return null;
        }
        $test = $data['test'];
        $parts = ['<p class="fw-embed__method">' . self::e($test['method']) . '</p>'];
        $alternative = $test['alternative'] ?? null;
        if (is_string($alternative) && array_key_exists($alternative, self::ALTERNATIVES)) {
            $parts[] = '<p class="fw-embed__meta">' . self::ALTERNATIVES[$alternative] . '</p>';
        }
        $stats = [];
        $statistic = $test['statistic'] ?? null;
        if (self::isObject($statistic) && self::text($statistic['name'] ?? null) !== null) {
            $stats[] = [$statistic['name'], Format::number($statistic['value'] ?? null)];
        }
        foreach (self::named($test['parameters'] ?? null) as $entry) {
            $stats[] = [$entry['name'], Format::number($entry['value'] ?? null)];
        }
        if (Format::isNumber($test['p_value'] ?? null)) {
            $stats[] = ['p-value', Format::pValue($test['p_value'])];
        }
        foreach (self::named($test['estimates'] ?? null) as $entry) {
            $stats[] = [Format::asciiCapitalize($entry['name']), Format::number($entry['value'] ?? null)];
        }
        foreach (self::named($test['null_value'] ?? null) as $entry) {
            $stats[] = ['Null value: ' . $entry['name'], Format::number($entry['value'] ?? null)];
        }
        $interval = $test['conf_int'] ?? null;
        if (self::isObject($interval) && Format::isNumber($interval['level'] ?? null)) {
            $low = Format::isNumber($interval['low'] ?? null) ? Format::number($interval['low']) : '-∞';
            $high = Format::isNumber($interval['high'] ?? null) ? Format::number($interval['high']) : '∞';
            $stats[] = [Format::percent($interval['level']) . ' CI', $low . ' to ' . $high];
        }
        if ($stats !== []) {
            $parts[] = self::pairs($stats);
        }
        foreach (self::usableTables($data) as $table) {
            $parts[] = self::table($table, true, true, $doc);
        }
        $metrics = self::usableMetrics($data);
        if ($metrics !== []) {
            $parts[] = self::metrics($metrics);
        }

        return implode("\n", $parts);
    }

    private static function named(mixed $value): array
    {
        return array_values(array_filter(self::lst($value), fn ($entry) => self::isObject($entry) && self::text($entry['name'] ?? null) !== null));
    }

    private static function modelMeta(array $model): ?string
    {
        $parts = [];
        $type = self::text($model['type'] ?? null);
        if ($type !== null && $type !== 'other') {
            $parts[] = self::MODEL_TYPES[$type] ?? $type;
        }
        $family = self::text($model['family'] ?? null);
        $link = self::text($model['link'] ?? null);
        if ($family !== null && $link !== null) {
            $parts[] = $family . ' family, ' . $link . ' link';
        } elseif ($family !== null) {
            $parts[] = $family . ' family';
        } elseif ($link !== null) {
            $parts[] = $link . ' link';
        }
        if (self::text($model['response'] ?? null) !== null) {
            $parts[] = 'Response: ' . $model['response'];
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function columnIndex(array $table, string $name): int
    {
        foreach ($table['columns'] as $index => $column) {
            if ($column['name'] === $name) {
                return $index;
            }
        }

        return -1;
    }

    private static function cell(mixed $row, int $index): mixed
    {
        return is_array($row) && $index >= 0 && $index < count($row) ? $row[$index] : null;
    }

    private static function shownRows(array $table): array
    {
        return array_values(array_filter(array_slice($table['rows'], 0, self::ROW_LIMIT), fn ($row) => is_array($row) && array_is_list($row)));
    }

    private static function isDecimalColumn(array $rows, int $index): bool
    {
        foreach ($rows as $row) {
            $value = self::cell($row, $index);
            if (Format::isNumber($value) && ! Format::isInteger($value)) {
                return true;
            }
        }

        return false;
    }

    private static function formatCell(mixed $value, array $column, bool $decimal): string
    {
        if (Format::isNumber($value) && Format::isPValueName($column['name'])) {
            return Format::pValue($value);
        }

        return Format::value($value, $decimal);
    }

    private static function columnLabel(array $column, bool $modelLabels): string
    {
        if (self::text($column['label'] ?? null) !== null) {
            return $column['label'];
        }
        if ($modelLabels && array_key_exists($column['name'], self::COLUMN_LABELS)) {
            return self::COLUMN_LABELS[$column['name']];
        }

        return $column['name'];
    }

    private static function th(string $label, bool $numeric): string
    {
        return '<th scope="col"' . ($numeric ? ' class="fw-embed__num"' : '') . '>' . self::e($label) . '</th>';
    }

    private static function td(string $value, bool $numeric): string
    {
        return '<td' . ($numeric ? ' class="fw-embed__num"' : '') . '>' . self::e($value) . '</td>';
    }

    private static function isNumeric(array $column): bool
    {
        return ($column['type'] ?? null) === 'number';
    }

    private static function openTable(array $table, bool $showCaption, ?string $modifier): array
    {
        $title = self::text($table['title'] ?? null) ?? 'Table';
        $lines = ['<div class="fw-embed__scroll" role="region" tabindex="0" aria-label="' . self::e($title) . '">',
            '<table class="fw-embed__table' . ($modifier !== null ? ' fw-embed__table--' . $modifier : '') . '">'];
        if ($showCaption) {
            $lines[] = '<caption>' . self::e($title) . '</caption>';
        }

        return $lines;
    }

    private static function tableNotes(array $table, array $rows, array $doc): array
    {
        $notes = [];
        $total = Format::isInteger($table['row_count'] ?? null) ? $table['row_count'] : count($table['rows']);
        $columnTotal = Format::isInteger($table['column_count'] ?? null) ? $table['column_count'] : count($table['columns']);
        $parts = [];
        if ($total > count($rows)) {
            $parts[] = Format::number(count($rows)) . ' of ' . Format::number($total) . ' rows';
        }
        if ($columnTotal > count($table['columns'])) {
            $parts[] = Format::number(count($table['columns'])) . ' of ' . Format::number($columnTotal) . ' columns';
        }
        if ($parts !== []) {
            $href = self::safeUrl($doc['url'] ?? null);
            $notes[] = '<p class="fw-embed__note">Showing ' . implode(' and ', $parts) . '.'
                . ($href === null ? '' : ' <a href="' . self::e($href) . '">View the full table</a>') . '</p>';
        }
        if (self::text($table['precision_note'] ?? null) !== null) {
            $notes[] = '<p class="fw-embed__note">' . self::e($table['precision_note']) . '</p>';
        }

        return $notes;
    }

    private static function table(array $table, bool $showCaption, bool $modelLabels, array $doc): string
    {
        $rows = self::shownRows($table);
        $columns = $table['columns'];
        $decimal = [];
        foreach ($columns as $index => $column) {
            $decimal[$index] = self::isDecimalColumn($rows, $index);
        }
        $lines = self::openTable($table, $showCaption, null);
        $head = '';
        foreach ($columns as $column) {
            $head .= self::th(self::columnLabel($column, $modelLabels), self::isNumeric($column));
        }
        $lines[] = '<thead><tr>' . $head . '</tr></thead>';
        $lines[] = '<tbody>';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($columns as $index => $column) {
                $cells .= self::td(self::formatCell(self::cell($row, $index), $column, $decimal[$index]), self::isNumeric($column));
            }
            $lines[] = '<tr>' . $cells . '</tr>';
        }
        array_push($lines, '</tbody>', '</table>', '</div>', ...self::tableNotes($table, $rows, $doc));

        return implode("\n", $lines);
    }

    private static function coefficients(array $table, array $model, int|float|null $level, array $doc): string
    {
        $rows = self::shownRows($table);
        $term = self::columnIndex($table, 'term');
        $estimate = self::columnIndex($table, 'estimate');
        $error = self::columnIndex($table, 'std_error');
        $pValue = self::columnIndex($table, 'p_value');
        $low = self::columnIndex($table, 'conf_low');
        $high = self::columnIndex($table, 'conf_high');
        $interval = $low >= 0 && $high >= 0;
        $ratio = self::text($model['exponentiate'] ?? null);
        $extras = [];
        $decimal = [];
        foreach ($table['columns'] as $index => $column) {
            if (! in_array($column['name'], self::COEFFICIENT_COLUMNS, true)) {
                $extras[] = $index;
            }
            $decimal[$index] = self::isDecimalColumn($rows, $index);
        }

        $head = [self::th(self::columnLabel($table['columns'][$term], true), false)];
        foreach ($extras as $index) {
            $head[] = self::th(self::columnLabel($table['columns'][$index], true), self::isNumeric($table['columns'][$index]));
        }
        $head[] = self::th('Estimate', true);
        $intervalLabel = $level === null ? 'CI' : Format::percent($level) . ' CI';
        if ($ratio !== null) {
            $head[] = self::th($interval ? $ratio . ' (' . $intervalLabel . ')' : $ratio, true);
        }
        if ($error >= 0) {
            $head[] = self::th('Std. error', true);
        }
        if ($interval) {
            $head[] = self::th($intervalLabel, true);
        }
        if ($pValue >= 0) {
            $head[] = self::th('p-value', true);
        }

        $lines = self::openTable($table, true, 'coefficients');
        $lines[] = '<thead><tr>' . implode('', $head) . '</tr></thead>';
        $lines[] = '<tbody>';
        foreach ($rows as $row) {
            $cells = ['<th scope="row">' . self::e(Format::value(self::cell($row, $term))) . '</th>'];
            foreach ($extras as $index) {
                $cells[] = self::td(self::formatCell(self::cell($row, $index), $table['columns'][$index], $decimal[$index]), self::isNumeric($table['columns'][$index]));
            }
            $value = self::cell($row, $estimate);
            $cells[] = self::td(Format::value($value, true), true);
            if ($ratio !== null) {
                $cells[] = self::td(self::ratioCell($value, $interval ? [self::cell($row, $low), self::cell($row, $high)] : null, self::cell($row, $term)), true);
            }
            if ($error >= 0) {
                $cells[] = self::td(Format::value(self::cell($row, $error), true), true);
            }
            if ($interval) {
                $a = self::cell($row, $low);
                $b = self::cell($row, $high);
                $cells[] = self::td($a === null && $b === null ? Format::EMPTY : Format::value($a, true) . ' to ' . Format::value($b, true), true);
            }
            if ($pValue >= 0) {
                $p = self::cell($row, $pValue);
                $cells[] = self::td(Format::isNumber($p) ? Format::pValue($p) : Format::value($p), true);
            }
            $lines[] = '<tr>' . implode('', $cells) . '</tr>';
        }
        array_push($lines, '</tbody>', '</table>', '</div>', ...self::tableNotes($table, $rows, $doc));

        return implode("\n", $lines);
    }

    /** An exponentiated estimate with its exponentiated interval. Intercepts show EMPTY. */
    private static function ratioCell(mixed $estimate, ?array $bounds, mixed $term): string
    {
        if (! Format::isNumber($estimate) || (is_string($term) && self::isIntercept($term))) {
            return Format::EMPTY;
        }
        $value = Format::number(Format::exp($estimate), true);
        if ($bounds === null) {
            return $value;
        }
        [$low, $high] = array_map(fn ($bound) => Format::isNumber($bound) ? Format::number(Format::exp($bound), true) : Format::EMPTY, $bounds);

        return $value . ' (' . $low . ' to ' . $high . ')';
    }

    private static function isIntercept(string $term): bool
    {
        return preg_match('/^(\(intercept\)|intercept|const|_cons)$/D', strtolower($term)) === 1;
    }

    private static function metricLabel(string $name): string
    {
        if (array_key_exists($name, self::METRIC_LABELS)) {
            return self::METRIC_LABELS[$name];
        }
        if (str_starts_with($name, 'n_groups.') && strlen($name) > 9) {
            return 'Groups: ' . substr($name, 9);
        }

        return Format::asciiCapitalize(strtr($name, '_.', '  '));
    }

    private static function metricValue(string $name, mixed $value): string
    {
        if ($name === 'confidence_level' && Format::isNumber($value)) {
            return Format::percent($value);
        }
        if ($name === 'interval_method' && is_string($value) && array_key_exists($value, self::INTERVAL_METHODS)) {
            return self::INTERVAL_METHODS[$value];
        }
        if (Format::isNumber($value) && Format::isPValueName($name)) {
            return Format::pValue($value);
        }

        return Format::value($value);
    }

    private static function metrics(array $metrics): string
    {
        return self::pairs(array_map(fn (array $metric) => [self::metricLabel($metric['name']), self::metricValue($metric['name'], $metric['value'] ?? null)], $metrics));
    }

    private static function pairs(array $pairs): string
    {
        $lines = ['<dl class="fw-embed__stats">'];
        foreach ($pairs as [$term, $value]) {
            $lines[] = '<div><dt>' . self::e($term) . '</dt><dd>' . self::e($value) . '</dd></div>';
        }
        $lines[] = '</dl>';

        return implode("\n", $lines);
    }

    // --- other kinds --------------------------------------------------------

    private static function image(array $doc): ?string
    {
        $image = self::isObject($doc['image'] ?? null) ? $doc['image'] : null;
        $src = $image === null ? null : self::safeUrl($image['url'] ?? null);
        if ($src === null) {
            return null;
        }
        $alt = self::text($doc['alt'] ?? null) ?? self::text($doc['title'] ?? null) ?? '';
        $tag = '<img class="fw-embed__img" src="' . self::e($src) . '" alt="' . self::e($alt) . '" loading="lazy" decoding="async"';
        $width = $image['width'] ?? null;
        $height = $image['height'] ?? null;
        if (Format::isInteger($width) && $width > 0 && Format::isInteger($height) && $height > 0) {
            $tag .= ' width="' . self::integer($width) . '" height="' . self::integer($height) . '"';
        }

        return '<div class="fw-embed__media">' . $tag . '></div>';
    }

    private static function chart(array $doc): ?string
    {
        $spec = $doc['spec'] ?? null;
        if (! ($spec instanceof stdClass) && ! self::isObject($spec)) {
            return null;
        }
        $href = self::safeUrl($doc['url'] ?? null);
        $alt = self::e(self::text($doc['alt'] ?? null) ?? self::text($doc['title'] ?? null) ?? 'Chart');

        return implode("\n", [
            '<div class="fw-embed__chart" data-fw-embed-chart>',
            '<script type="application/json">' . Json::forScript($spec) . '</script>',
            '<p class="fw-embed__fallback">' . $alt . ($href === null ? '' : ' <a href="' . self::e($href) . '">View the chart</a>') . '</p>',
            '</div>',
        ]);
    }

    private static function page(array $doc): string
    {
        $title = self::e(self::text($doc['title'] ?? null) ?? 'Untitled');
        $href = self::safeUrl($doc['url'] ?? null);
        $lines = ['<div class="fw-embed__card">',
            $href === null ? '<span class="fw-embed__card-title">' . $title . '</span>' : '<a class="fw-embed__card-title" href="' . self::e($href) . '">' . $title . '</a>'];
        if (self::text($doc['description'] ?? null) !== null) {
            $lines[] = '<p class="fw-embed__card-description">' . self::e($doc['description']) . '</p>';
        }
        $author = self::obj($doc['author'] ?? null);
        if (self::text($author['name'] ?? null) !== null) {
            $authorHref = self::safeUrl($author['url'] ?? null);
            $name = self::e($author['name']);
            $lines[] = '<p class="fw-embed__author">' . ($authorHref === null ? $name : '<a href="' . self::e($authorHref) . '">' . $name . '</a>') . '</p>';
        }
        $lines[] = '</div>';

        return implode("\n", $lines);
    }
}
