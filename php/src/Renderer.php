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

    public const FEATURE_LIMIT = 10;

    /** Text values longer than this many characters take a full row of the statistics grid. */
    public const WIDE_TEXT = 24;

    private const KINDS = ['model', 'table', 'test', 'code_snippet', 'dag', 'tree', 'image', 'vega-lite', 'page'];

    private const DAG_ROLES = ['exposure' => 'Exposure', 'outcome' => 'Outcome', 'adjusted' => 'Adjusted', 'latent' => 'Latent', 'selected' => 'Selected'];

    private const DAG_LABEL_CHARS = 32;

    /** Deeper folders show as rows without their contents, and rows past the limit are left out. */
    private const TREE_DEPTH = 32;

    private const TREE_ROWS = 5000;

    /** The name column, in ch, never starts wider than this; a longer name pushes its note right. */
    private const TREE_NAMES = 48;

    private const TREE_COUNT_CAP = 10000;

    /** A tree showing more rows than this at first starts clipped, unless data.display.full is true. */
    private const TREE_CLIP_ROWS = 15;

    private const TREE_SOURCES = ['global' => 'global excludes file', 'info/exclude' => '.git/info/exclude'];

    private const SNIPPET_LANGUAGES = ['r' => 'R', 'python' => 'Python', 'stata' => 'Stata'];

    private const SNIPPET_BLOCKS = ['code' => 'Code', 'output' => 'Output', 'message' => 'Message', 'warning' => 'Warning', 'error' => 'Error'];

    private const SNIPPET_LINKS = ['notebook' => 'Full notebook', 'model' => 'Model'];

    private const COLUMN_LABELS = [
        'term' => 'Term', 'estimate' => 'Estimate', 'std_error' => 'Std. error', 'statistic' => 'Statistic', 'p_value' => 'p-value',
        'conf_low' => 'CI lower', 'conf_high' => 'CI upper', 'group' => 'Group', 'variance' => 'Variance', 'std_dev' => 'Std. dev.',
        'correlation' => 'Correlation', 'feature' => 'Feature', 'importance' => 'Importance', 'edf' => 'EDF', 'ref_df' => 'Ref. df', 'df' => 'df',
        'rhat' => 'R-hat', 'ess_bulk' => 'Bulk ESS', 'ess_tail' => 'Tail ESS', 'parameter' => 'Parameter', 'prior' => 'Prior', 'source' => 'Source',
    ];

    /** Bayesian models summarize the posterior: the same columns, named for what they hold. */
    private const BAYESIAN_LABELS = ['estimate' => 'Posterior mean', 'std_error' => 'Posterior SD', 'conf_low' => 'Interval lower', 'conf_high' => 'Interval upper'];

    private const METRIC_LABELS = [
        'nobs' => 'Observations', 'df_resid' => 'Residual df', 'df_model' => 'Model df', 'aic' => 'AIC', 'bic' => 'BIC',
        'rsquared' => 'R²', 'r.squared' => 'R²', 'rsquared_adj' => 'Adjusted R²', 'adj.r.squared' => 'Adjusted R²', 'pseudo_rsquared' => 'Pseudo R²',
        'sigma' => 'Residual SD', 'llf' => 'Log likelihood', 'log_likelihood' => 'Log likelihood', 'deviance' => 'Deviance',
        'null.deviance' => 'Null deviance', 'dispersion' => 'Dispersion', 'converged' => 'Converged', 'reml' => 'REML',
        'confidence_level' => 'Confidence level', 'interval_method' => 'Interval method', 'vcov_type' => 'Standard errors',
        'n_estimators' => 'Trees', 'max_depth' => 'Maximum depth', 'learning_rate' => 'Learning rate', 'n_features' => 'Features',
        'importance_type' => 'Importance type', 'objective' => 'Objective', 'n_events' => 'Events', 'concordance' => 'Concordance',
        'theta' => 'Theta', 'command' => 'Command',
        'chains' => 'Chains', 'draws' => 'Draws', 'warmup' => 'Warmup', 'sampler' => 'Sampler', 'divergent_transitions' => 'Divergent transitions',
        'max_treedepth_hits' => 'Max treedepth hits', 'bayes_r2' => 'Bayes R²', 'elpd_loo' => 'ELPD (LOO)', 'elpd_loo_se' => 'ELPD (LOO) SE',
        'looic' => 'LOOIC', 'p_loo' => 'p (LOO)', 'waic' => 'WAIC', 'rhat_max' => 'Max R-hat', 'acceptance_rate' => 'Acceptance rate',
        'efficiency_min' => 'Efficiency (min)', 'efficiency_avg' => 'Efficiency (avg)', 'efficiency_max' => 'Efficiency (max)', 'dic' => 'DIC',
        'log_marginal_likelihood' => 'Log marginal likelihood',
    ];

    private const INTERVAL_METHODS = ['wald-t' => 'Wald t', 'wald-normal' => 'Wald normal', 'profile' => 'Profile likelihood', 'bootstrap' => 'Bootstrap'];

    private const MODEL_TYPES = [
        'linear' => 'Linear model', 'glm' => 'Generalized linear model', 'mixed' => 'Mixed model', 'survival' => 'Survival model',
        'ordinal' => 'Ordinal model', 'multinomial' => 'Multinomial model', 'additive' => 'Additive model', 'fixed_effects' => 'Fixed-effects model',
        'quantile' => 'Quantile regression', 'linear_ml' => 'Linear model', 'tree_ensemble' => 'Tree ensemble model',
    ];

    private const ALTERNATIVES = ['two.sided' => 'Two-sided', 'less' => 'One-sided (less)', 'greater' => 'One-sided (greater)'];

    private const COEFFICIENT_COLUMNS = ['term', 'estimate', 'std_error', 'statistic', 'p_value', 'conf_low', 'conf_high', 'df',
        'rhat', 'ess_bulk', 'ess_tail', 'ratio_estimate', 'ratio_std_error', 'ratio_conf_low', 'ratio_conf_high'];

    /** Above this, R-hat says the chains may not have converged. */
    private const RHAT_LIMIT = 1.01;

    /** Effective sample sizes are counts of draws, shown whole. */
    private const WHOLE_COLUMNS = ['ess_bulk', 'ess_tail'];

    /** Whether the model being rendered was fit by posterior sampling. */
    private static bool $bayesian = false;

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
        self::$tips = ['prefix' => self::tipPrefix($doc), 'count' => 0];
        self::$bayesian = ($doc['kind'] ?? null) === 'model' && (self::obj(self::obj($doc['data'] ?? null)['model'] ?? null)['inference'] ?? null) === 'bayesian';
        $body = match ($kind) {
            'model' => self::model($doc),
            'table' => self::tableKind($doc),
            'test' => self::test($doc),
            'code_snippet' => self::snippet($doc),
            'dag' => self::dag($doc),
            'tree' => self::tree($doc),
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

        // The caption heads the embed: the title and the link back. The description and author follow the
        // content. A page's link card already shows all three, so its caption (the link back alone) follows the card.
        $caption = self::caption($doc, $kind);
        $inner = ['<div class="fw-embed__body">', $body, '</div>'];
        $captions = $caption === null ? [] : [$caption];
        if ($kind === 'page') {
            return implode("\n", [$open . '>', ...$inner, ...$captions, '</figure>', '']);
        }

        // A diagram's description opens in a section of its own, beside its analysis and code.
        $about = $kind === 'dag' ? self::about([...$doc, 'description' => null]) : self::about($doc);

        return implode("\n", [$open . '>', ...$captions, ...$inner, ...$about, '</figure>', '']);
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

    private static function caption(array $doc, string $kind): ?string
    {
        $parts = [];
        if ($kind !== 'page' && self::text($doc['title'] ?? null) !== null) {
            $parts[] = '<span class="fw-embed__title">' . self::e($doc['title']) . '</span>';
        }
        // The owner may turn the link back off on Framework Cloud: display.credit is false
        if ((self::obj($doc['display'] ?? null)['credit'] ?? null) !== false) {
            $attribution = self::obj($doc['attribution'] ?? null);
            $credit = self::e(self::text($attribution['text'] ?? null) ?? 'Framework');
            $href = self::safeUrl($attribution['url'] ?? null) ?? self::safeUrl($doc['url'] ?? null);
            $parts[] = $href === null
                ? '<span class="fw-embed__credit">' . $credit . '</span>'
                : '<a class="fw-embed__credit" href="' . self::e($href) . '" target="_blank" rel="noopener">' . $credit . '</a>';
        }
        if ($parts === []) {
            return null;
        }

        return implode("\n", ['<figcaption class="fw-embed__caption">', ...$parts, '</figcaption>']);
    }

    /**
     * The description and author, under the content.
     *
     * @return list<string>
     */
    private static function about(array $doc): array
    {
        $parts = [];
        if (self::text($doc['description'] ?? null) !== null) {
            $parts[] = '<span class="fw-embed__description">' . self::e($doc['description']) . '</span>';
        }
        $author = self::obj($doc['author'] ?? null);
        if (self::text($author['name'] ?? null) !== null) {
            $href = self::safeUrl($author['url'] ?? null);
            $name = self::e($author['name']);
            $parts[] = '<span class="fw-embed__author">' . ($href === null ? $name : '<a href="' . self::e($href) . '">' . $name . '</a>') . '</span>';
        }

        return $parts === [] ? [] : ['<div class="fw-embed__about">', ...$parts, '</div>'];
    }

    // --- variable tips ---
    // A term or column with something to tell gets a button that opens a popover with the variable's
    // label, its reference level, and a summary. The popover opens on click or tap without JavaScript;
    // the browser bundle adds hover and places it beside the button. Ids are unique within a page
    // for one copy of an embed; the bundle renumbers repeats.

    /** @var array{prefix: string, count: int} */
    private static array $tips = ['prefix' => 'fw-embed-tip-', 'count' => 0];

    private static function tipPrefix(array $doc): string
    {
        $key = self::text($doc['key'] ?? null);
        if ($key === null || preg_match('/^[A-Za-z0-9_-]+$/D', $key) !== 1) {
            return 'fw-embed-tip-';
        }

        return 'fw-embed-tip-' . $key . '-' . (Format::isInteger($doc['version'] ?? null) ? self::integer($doc['version']) : '0') . '-';
    }

    /** @param list<string> $blocks */
    private static function tip(string $label, array $blocks): string
    {
        $id = self::$tips['prefix'] . (++self::$tips['count']);

        return '<button type="button" class="fw-embed__term" popovertarget="' . $id . '">' . self::e($label) . '</button>'
            . '<span class="fw-embed__tip" id="' . $id . '" popover="">' . implode('', $blocks) . '</span>';
    }

    private static function tipBlock(string $name, ?string $label, string $detail): string
    {
        $html = '<span class="fw-embed__tip-name">' . self::e($name) . '</span>';
        if ($label !== null) {
            $html .= '<span class="fw-embed__tip-label">' . self::e($label) . '</span>';
        }
        if ($detail !== '') {
            $html .= '<span class="fw-embed__tip-detail">' . self::e($detail) . '</span>';
        }

        return '<span class="fw-embed__tip-block">' . $html . '</span>';
    }

    private static function plural(int|float $n, string $one, string $many): string
    {
        return Format::number($n) . ' ' . ($n == 1 ? $one : $many);
    }

    /**
     * Whether a top value is the level: a string as it is, an integer in digits, a boolean as true or false
     * in any ASCII case (R writes TRUE, Python True).
     */
    private static function isLevel(mixed $value, string $level): bool
    {
        if (is_string($value)) {
            return $value === $level;
        }
        if (is_bool($value)) {
            return strtolower($level) === ($value ? 'true' : 'false');
        }

        return is_int($value) && (string) $value === $level;
    }

    /**
     * Sentences about a variable or column: its spread or common values, then its rows.
     *
     * @param  list<string>  $levels
     */
    private static function summaryDetail(mixed $type, mixed $summary, array $levels, ?string $level, ?string $reference): string
    {
        $sentences = [];
        $s = self::obj($summary);
        $stat = fn (mixed $x) => Format::value($x, false, Format::RESULT_PLACES);
        if ($level !== null) {
            if ($reference !== null && $reference !== $level) {
                $sentences[] = 'Compared with ' . $reference . '.';
            }
            foreach (self::lst($s['top'] ?? null) as $entry) {
                if (self::isObject($entry) && self::isLevel($entry['value'] ?? null, $level) && Format::isInteger($entry['count'] ?? null)) {
                    if (Format::isInteger($s['count'] ?? null)) {
                        $sentences[] = Format::number($entry['count']) . ' of ' . self::plural($s['count'], 'row', 'rows') . '.';
                    }
                    break;
                }
            }

            return implode(' ', $sentences);
        }
        if ($type === 'number') {
            $parts = [];
            if (Format::isNumber($s['mean'] ?? null)) {
                $parts[] = 'mean ' . $stat($s['mean']);
            }
            if (Format::isNumber($s['sd'] ?? null)) {
                $parts[] = 'SD ' . $stat($s['sd']);
            }
            if (Format::isNumber($s['min'] ?? null) && Format::isNumber($s['max'] ?? null)) {
                // Both ends with the same decimals: 1.00 to 6.90, not 1 to 6.90
                $decimal = ! Format::isInteger($s['min']) || ! Format::isInteger($s['max']);
                $parts[] = 'range ' . Format::value($s['min'], $decimal, Format::RESULT_PLACES) . ' to ' . Format::value($s['max'], $decimal, Format::RESULT_PLACES);
            }
            if ($parts !== []) {
                $sentences[] = Format::asciiCapitalize(implode(', ', $parts)) . '.';
            }
        } elseif ($type === 'date' || $type === 'datetime') {
            if (is_string($s['min'] ?? null) && is_string($s['max'] ?? null)) {
                $sentences[] = 'From ' . $s['min'] . ' to ' . $s['max'] . '.';
            }
        } else {
            if ($levels !== []) {
                $sentences[] = self::plural(count($levels), 'level', 'levels') . ($reference !== null ? ', compared with ' . $reference : '') . '.';
            } elseif (Format::isInteger($s['distinct'] ?? null)) {
                $sentences[] = self::plural($s['distinct'], 'distinct value', 'distinct values') . '.';
            }
            $top = [];
            foreach (self::lst($s['top'] ?? null) as $entry) {
                if (self::isObject($entry) && Format::isInteger($entry['count'] ?? null)) {
                    $top[] = Format::value($entry['value'] ?? null) . ' (' . Format::number($entry['count']) . ')';
                    if (count($top) === 3) {
                        break;
                    }
                }
            }
            if ($top !== []) {
                $sentences[] = 'Most common: ' . implode(', ', $top) . '.';
            }
        }
        if (Format::isInteger($s['count'] ?? null)) {
            $missing = Format::isInteger($s['missing'] ?? null) && $s['missing'] > 0 ? ', ' . Format::number($s['missing']) . ' missing' : '';
            $sentences[] = self::plural($s['count'], 'row', 'rows') . $missing . '.';
        }

        return implode(' ', $sentences);
    }

    /** @return array<string, array<string, mixed>> */
    private static function modelVariables(array $doc): array
    {
        $variables = [];
        foreach (self::lst(self::obj($doc['data'] ?? null)['variables'] ?? null) as $variable) {
            if (self::isObject($variable) && self::text($variable['name'] ?? null) !== null && ! array_key_exists($variable['name'], $variables)) {
                $variables[$variable['name']] = $variable;
            }
        }

        return $variables;
    }

    /** A coefficient term, with a tip when the model says which variables it comes from. */
    private static function termCell(string $label, array $doc): string
    {
        $entry = null;
        foreach (self::lst(self::obj($doc['data'] ?? null)['terms'] ?? null) as $term) {
            if (self::isObject($term) && ($term['term'] ?? null) === $label) {
                $entry = $term;
                break;
            }
        }
        if ($entry === null) {
            return self::e($label);
        }
        $variables = self::modelVariables($doc);
        $blocks = [];
        foreach (self::lst($entry['parts'] ?? null) as $part) {
            $variable = self::isObject($part) && is_string($part['variable'] ?? null) ? ($variables[$part['variable']] ?? null) : null;
            if ($variable === null) {
                return self::e($label);
            }
            $level = self::text($part['level'] ?? null);
            $levels = array_values(array_filter(self::lst($variable['levels'] ?? null), 'is_string'));
            $name = $level === null ? $variable['name'] : $variable['name'] . ' = ' . $level;
            $variableLabel = self::text($variable['label'] ?? null);
            $blocks[] = self::tipBlock($name, $variableLabel === $variable['name'] ? null : $variableLabel,
                self::summaryDetail($variable['type'] ?? null, $variable['summary'] ?? null, $levels, $level, self::text($variable['reference'] ?? null)));
        }

        return $blocks === [] ? self::e($label) : self::tip($label, $blocks);
    }

    /** A data table's column header, with a tip when the column carries a label or summary. */
    private static function columnHeader(array $column): string
    {
        $label = self::columnLabel($column, false);
        $type = in_array($column['type'] ?? null, ['number', 'date', 'datetime'], true) ? $column['type'] : 'string';
        $detail = self::summaryDetail($type, $column['summary'] ?? null, [], null, null);
        $named = $label !== $column['name'];
        if ($detail === '' && ! $named) {
            return self::e($label);
        }

        return self::tip($label, [self::tipBlock($column['name'], $named ? $label : null, $detail)]);
    }

    // --- data kinds ---------------------------------------------------------
    // Models and tests show their main results; the rest goes in a closed <details>.

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

    /** A section the artifact's owner turned off on Framework Cloud: data.display.{name} is false. */
    private static function hidden(array $data, string $name): bool
    {
        return (self::obj($data['display'] ?? null)[$name] ?? null) === false;
    }

    private static function details(string $label, array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }

        return ['<details class="fw-embed__details">', '<summary>' . $label . '</summary>', ...$blocks, '</details>'];
    }

    private static function isCoefficientTable(array $table, int $index): bool
    {
        $role = $table['role'] ?? null;

        return ($role === 'coefficients' || ($role === null && $index === 0))
            && self::columnIndex($table, 'term') >= 0 && self::columnIndex($table, 'estimate') >= 0;
    }

    /** A Stata `command` metric with a string value. It stands in for a formula and never appears in the statistics. */
    private static function isCommand(array $metric): bool
    {
        return $metric['name'] === 'command' && self::text($metric['value'] ?? null) !== null;
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
        $stats = [];
        foreach ($metrics as $metric) {
            if (self::isCommand($metric)) {
                $formula ??= $metric['value'];
            } else {
                $stats[] = $metric;
            }
        }
        $model = self::obj($data['model'] ?? null);
        $parts = [];
        $meta = self::modelMeta($model);
        if ($meta !== null) {
            $parts[] = '<p class="fw-embed__meta">' . self::metaHtml($meta, self::text($model['response'] ?? null)) . '</p>';
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
        $coefficients = [];
        foreach ($tables as $index => $table) {
            $coefficients[$index] = self::isCoefficientTable($table, $index);
        }
        $importance = -1;
        if (! in_array(true, $coefficients, true)) {
            foreach ($tables as $index => $table) {
                if (($table['role'] ?? null) === 'importance') {
                    $importance = $index;
                    break;
                }
            }
        }
        $visible = [];
        $rest = [];
        foreach ($tables as $index => $table) {
            if ($coefficients[$index]) {
                $visible[] = self::coefficients($table, $model, $level, $doc);
            } elseif ($index === $importance) {
                $continued = ! self::hidden($data, 'details') && self::shownRows($table, self::FEATURE_LIMIT, self::ROW_LIMIT) !== [];
                $visible[] = self::table($table, true, true, $doc, Format::RESULT_PLACES, 0, self::FEATURE_LIMIT, $continued ? 'Model details' : null);
                if ($continued) {
                    $rest[] = self::table($table, true, true, $doc, Format::RESULT_PLACES, self::FEATURE_LIMIT, self::ROW_LIMIT, null);
                }
            } elseif (($table['role'] ?? null) === 'random_effects') {
                // Variance components are as much a result as the fixed effects; a posterior's read like its coefficients
                $visible[] = self::$bayesian && self::columnIndex($table, 'term') >= 0 && self::columnIndex($table, 'estimate') >= 0
                    ? self::coefficientTable($table, $doc, 'random-effects', self::posteriorColumns($table, $level, 'Posterior mean', false))
                    : self::table($table, true, true, $doc, Format::RESULT_PLACES, 0, self::ROW_LIMIT, null);
            } else {
                $rest[] = self::table($table, true, true, $doc, Format::RESULT_PLACES, 0, self::ROW_LIMIT, null);
            }
        }
        if ($stats !== []) {
            $rest[] = self::metrics($stats);
        }
        if ($visible === []) {
            return implode("\n", [...$parts, ...$rest]);
        }

        if (self::$bayesian) {
            array_push($visible, ...self::samplingWarnings($tables, $metrics));
        }
        // The model type, response, and formula are details: the coefficients show the terms, and the post names the model
        if (self::hidden($data, 'details')) {
            return implode("\n", $visible);
        }

        return implode("\n", [...$visible, ...self::details('Model details', [...$parts, ...$rest])]);
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
            $parts[] = self::table($table, count($tables) > 1, false, $doc, Format::DATA_PLACES, 0, self::ROW_LIMIT, null);
        }
        $metrics = self::usableMetrics($data);
        if ($metrics !== []) {
            $parts[] = self::metrics($metrics);
        }

        return implode("\n", $parts);
    }

    /** A test's interval; a missing end is unbounded. */
    private static function intervalText(mixed $low, mixed $high): string|array
    {
        $a = Format::isNumber($low) ? Format::number($low, true, Format::RESULT_PLACES) : '-∞';
        $b = Format::isNumber($high) ? Format::number($high, true, Format::RESULT_PLACES) : '∞';
        $full = fn ($x, $text) => Format::isNumber($x) ? Format::exact($x) : $text;

        return self::titled($a . ' to ' . $b, Format::dropsDigits($low, $a) || Format::dropsDigits($high, $b) ? $full($low, $a) . ' to ' . $full($high, $b) : null);
    }

    private static function result(mixed $value): string|array
    {
        return self::shown($value, Format::number($value, false, Format::RESULT_PLACES));
    }

    private static function test(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data) || ! self::isObject($data['test'] ?? null) || self::text($data['test']['method'] ?? null) === null) {
            return null;
        }
        $test = $data['test'];
        $main = [];
        $statistic = $test['statistic'] ?? null;
        if (self::isObject($statistic) && self::text($statistic['name'] ?? null) !== null) {
            $main[] = [$statistic['name'], self::result($statistic['value'] ?? null), true];
        }
        if (Format::isNumber($test['p_value'] ?? null)) {
            $main[] = ['p-value', self::shown($test['p_value'], Format::pValue($test['p_value'])), true];
        }
        $estimates = self::named($test['estimates'] ?? null);
        $interval = self::isObject($test['conf_int'] ?? null) && Format::isNumber($test['conf_int']['level'] ?? null) ? $test['conf_int'] : null;
        $ci = $interval === null ? null : Format::percent($interval['level']) . ' CI';
        if (count($estimates) === 1 && $interval !== null) {
            $estimate = self::result($estimates[0]['value'] ?? null);
            $range = self::intervalText($interval['low'] ?? null, $interval['high'] ?? null);
            $rounded = ! is_string($estimate) || ! is_string($range);
            $text = self::pieces($estimate)['text'] . ' (' . self::pieces($range)['text'] . ')';
            $main[] = [Format::asciiCapitalize($estimates[0]['name']) . ' (' . $ci . ')',
                self::titled($text, $rounded ? self::pieces($estimate)['title'] . ' (' . self::pieces($range)['title'] . ')' : null), true];
        } else {
            foreach ($estimates as $entry) {
                $main[] = [Format::asciiCapitalize($entry['name']), self::result($entry['value'] ?? null), true];
            }
            if ($interval !== null) {
                $main[] = [$ci, self::intervalText($interval['low'] ?? null, $interval['high'] ?? null), true];
            }
        }
        $extra = [];
        $alternative = $test['alternative'] ?? null;
        if (is_string($alternative) && array_key_exists($alternative, self::ALTERNATIVES)) {
            $extra[] = ['Alternative', self::ALTERNATIVES[$alternative], false];
        }
        foreach (self::named($test['parameters'] ?? null) as $entry) {
            $extra[] = [$entry['name'], self::result($entry['value'] ?? null), true];
        }
        foreach (self::named($test['null_value'] ?? null) as $entry) {
            $extra[] = ['Null value: ' . $entry['name'], self::result($entry['value'] ?? null), true];
        }
        $rest = [];
        if ($extra !== []) {
            $rest[] = self::pairs($extra);
        }
        foreach (self::usableTables($data) as $table) {
            $rest[] = self::table($table, true, true, $doc, Format::RESULT_PLACES, 0, self::ROW_LIMIT, null);
        }
        $metrics = self::usableMetrics($data);
        if ($metrics !== []) {
            $rest[] = self::metrics($metrics);
        }
        $parts = ['<p class="fw-embed__method">' . self::e($test['method']) . '</p>'];
        if ($main !== []) {
            $parts[] = self::pairs($main);
        }

        return implode("\n", [...$parts, ...(self::hidden($data, 'details') ? [] : self::details('Test details', $rest))]);
    }

    private static function named(mixed $value): array
    {
        return array_values(array_filter(self::lst($value), fn ($entry) => self::isObject($entry) && self::text($entry['name'] ?? null) !== null));
    }

    private const FAMILY_NAMES = ['gaussian' => 'Gaussian', 'tweedie' => 'Tweedie', 'poisson' => 'Poisson'];

    private const LINK_NAMES = ['sqrt' => 'square-root', 'cloglog' => 'complementary log-log'];

    private static function familyName(string $family): string
    {
        return self::FAMILY_NAMES[strtolower($family)] ?? $family;
    }

    private static function linkName(string $link): string
    {
        return self::LINK_NAMES[strtolower($link)] ?? $link;
    }

    private static function familyLink(?string $family, ?string $link): ?string
    {
        if ($family !== null && $link !== null) {
            return self::familyName($family) . ' family, ' . self::linkName($link) . ' link';
        }
        if ($family !== null) {
            return self::familyName($family) . ' family';
        }
        if ($link !== null) {
            return self::linkName($link) . ' link';
        }

        return null;
    }

    /**
     * The common name for a model, from its type, family, link, and exponentiate label.
     * The family and link appear only when the name does not imply them. Null for an unknown combination.
     */
    private static function modelName(array $model): ?string
    {
        $lower = fn (mixed $value): ?string => self::text($value) === null ? null : strtolower($value);
        $type = $lower($model['type'] ?? null);
        $family = $lower($model['family'] ?? null);
        $link = $lower($model['link'] ?? null);
        $withLink = fn (string $name, string $implied): string => $link === null || $link === $implied ? $name : $name . ' (' . self::linkName($link) . ' link)';
        if ($type === 'linear') {
            return 'Ordinary least squares regression';
        }
        if ($type === 'glm') {
            if ($family === 'gaussian' && ($link === null || $link === 'identity')) {
                return 'Ordinary least squares regression';
            }
            if ($family === 'binomial') {
                return match (true) {
                    $link === null || $link === 'logit' => 'Logistic regression',
                    $link === 'probit' => 'Probit regression',
                    $link === 'cloglog' => 'Complementary log-log regression',
                    default => 'Binomial regression (' . self::linkName($link) . ' link)',
                };
            }
            if ($family === 'poisson') {
                return $withLink('Poisson regression', 'log');
            }
            if ($family === 'negative binomial') {
                return $withLink('Negative binomial regression', 'log');
            }
            if ($family === 'gamma') {
                return $link === null ? 'Gamma regression' : 'Gamma regression (' . self::linkName($link) . ' link)';
            }
            if ($family !== null && str_starts_with($family, 'quasi')) {
                $rest = preg_replace('/^[- ]+/', '', substr($family, 5));

                return $rest === '' ? 'Quasi-likelihood regression' : 'Quasi-' . ($rest === 'poisson' ? 'Poisson' : $rest) . ' regression';
            }

            return null;
        }
        if ($type === 'mixed') {
            if ($family === null || $family === 'gaussian') {
                return $withLink('Linear mixed model', 'identity');
            }
            if ($family === 'binomial') {
                return match (true) {
                    $link === null || $link === 'logit' => 'Logistic mixed model',
                    $link === 'probit' => 'Probit mixed model',
                    default => 'Binomial mixed model (' . self::linkName($link) . ' link)',
                };
            }
            if ($family === 'poisson') {
                return $withLink('Poisson mixed model', 'log');
            }
            if ($family === 'negative binomial') {
                return $withLink('Negative binomial mixed model', 'log');
            }
            if ($family === 'gamma') {
                return $link === null ? 'Gamma mixed model' : 'Gamma mixed model (' . self::linkName($link) . ' link)';
            }

            return null;
        }
        if ($type === 'survival') {
            return self::text($model['exponentiate'] ?? null) === 'Hazard ratio' ? 'Cox proportional hazards model' : 'Parametric survival model';
        }
        if ($type === 'ordinal') {
            return match (true) {
                $link === null || $link === 'logit' => 'Ordinal logistic regression',
                $link === 'probit' => 'Ordinal probit regression',
                default => 'Ordinal regression (' . self::linkName($link) . ' link)',
            };
        }
        if ($type === 'multinomial') {
            return 'Multinomial logistic regression';
        }
        if ($type === 'additive') {
            $detail = $family === null || $family === 'gaussian' ? null : self::familyLink($family, $link);

            return $detail === null ? 'Generalized additive model' : 'Generalized additive model (' . $detail . ')';
        }
        if ($type === 'fixed_effects') {
            if ($family === 'poisson') {
                return 'Fixed-effects Poisson regression';
            }
            if ($family === 'binomial' && ($link === null || $link === 'logit')) {
                return 'Fixed-effects logistic regression';
            }

            return 'Fixed-effects regression';
        }

        return match ($type) {
            'quantile' => 'Quantile regression',
            'linear_ml' => 'Linear model',
            'tree_ensemble' => 'Tree ensemble model',
            default => null,
        };
    }

    /** The meta line, with the outcome (always last) set as a variable name. */
    private static function metaHtml(string $meta, ?string $response): string
    {
        if ($response === null || ! str_ends_with($meta, $response)) {
            return '<span>' . self::e($meta) . '</span>';
        }

        return '<span>' . self::e(substr($meta, 0, strlen($meta) - strlen($response))) . '</span><code class="fw-embed__var">' . self::e($response) . '</code>';
    }

    private static function modelMeta(array $model): ?string
    {
        $parts = [];
        $name = self::modelName($model);
        if ($name !== null) {
            $parts[] = $name;
        } else {
            // Unknown combinations: the type label, then the family and link as sent.
            $type = self::text($model['type'] ?? null);
            if ($type !== null && $type !== 'other') {
                $parts[] = self::MODEL_TYPES[$type] ?? $type;
            }
            $detail = self::familyLink(self::text($model['family'] ?? null), self::text($model['link'] ?? null));
            if ($detail !== null) {
                $parts[] = $detail;
            }
        }
        if (($model['inference'] ?? null) === 'bayesian') {
            // Proper nouns keep their capital: Bayesian Poisson regression
            if ($parts === []) {
                $parts[] = 'Bayesian model';
            } else {
                $parts[0] = 'Bayesian ' . (preg_match('/^(Poisson|Cox)\b/', $parts[0]) === 1 ? $parts[0] : strtolower(substr($parts[0], 0, 1)) . substr($parts[0], 1));
            }
        }
        if (self::text($model['response'] ?? null) !== null) {
            $parts[] = 'Outcome: ' . $model['response'];
        }

        return $parts === [] ? null : Format::asciiCapitalize(implode(' · ', $parts));
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

    private static function shownRows(array $table, int $start, int $end): array
    {
        return array_values(array_filter(array_slice($table['rows'], $start, max(0, $end - $start)), fn ($row) => is_array($row) && array_is_list($row)));
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

    private static function formatCell(mixed $value, array $column, bool $decimal, int $places): string|array
    {
        if (Format::isNumber($value) && Format::isPValueName($column['name'])) {
            return self::shown($value, Format::pValue($value));
        }
        if (Format::isNumber($value) && in_array($column['name'], self::WHOLE_COLUMNS, true)) {
            return self::shown($value, Format::number((int) round($value)));
        }
        if (Format::isNumber($value) && $column['name'] === 'rhat') {
            return self::shown($value, Format::value($value, true, Format::RESULT_PLACES));
        }

        return self::shown($value, Format::value($value, $decimal, $places));
    }

    private static function columnLabel(array $column, bool $modelLabels): string
    {
        if (self::text($column['label'] ?? null) !== null) {
            return $column['label'];
        }
        if ($modelLabels && self::$bayesian && array_key_exists($column['name'], self::BAYESIAN_LABELS)) {
            return self::BAYESIAN_LABELS[$column['name']];
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

    /** A cell's text, or [text, title] when the text rounds its number: the title holds the full value. */
    private static function td(string|array $value, bool $numeric): string
    {
        [$text, $title] = is_string($value) ? [$value, null] : $value;

        return '<td' . ($numeric ? ' class="fw-embed__num"' : '') . ($title === null ? '' : ' title="' . self::e($title) . '"') . '>' . self::e($text) . '</td>';
    }

    private static function titled(string $text, ?string $title): string|array
    {
        return $title === null ? $text : [$text, $title];
    }

    /** A number as shown, with its full value as the title when the text drops digits. */
    private static function shown(mixed $x, string $text): string|array
    {
        return self::titled($text, Format::dropsDigits($x, $text) ? Format::exact($x) : null);
    }

    /** "a to b", titled with both full values when either end drops digits. */
    private static function shownRange(mixed $a, mixed $b, \Closure $format): string|array
    {
        $low = $format($a);
        $high = $format($b);
        $full = fn ($x, $text) => Format::isNumber($x) ? Format::exact($x) : $text;

        return self::titled($low . ' to ' . $high, Format::dropsDigits($a, $low) || Format::dropsDigits($b, $high) ? $full($a, $low) . ' to ' . $full($b, $high) : null);
    }

    /** @return array{text: string, title: string} Text or [text, title] as plain parts, to build a longer value. */
    private static function pieces(string|array $value): array
    {
        return is_string($value) ? ['text' => $value, 'title' => $value] : ['text' => $value[0], 'title' => $value[1]];
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

    private static function numberSpan(int|float $count): string
    {
        return '<span class="fw-embed__num">' . self::e(Format::number($count)) . '</span>';
    }

    /**
     * $rest names the <details> that holds the remaining rows, or null to link to the artifact.
     * $start is the index of the first row shown; a continuation names its row range.
     */
    private static function tableNotes(array $table, array $rows, int $start, array $doc, ?string $rest): array
    {
        $notes = [];
        $total = Format::isInteger($table['row_count'] ?? null) ? $table['row_count'] : count($table['rows']);
        $columnTotal = Format::isInteger($table['column_count'] ?? null) ? $table['column_count'] : count($table['columns']);
        $href = self::safeUrl($doc['url'] ?? null);
        $link = $href === null ? '' : ' <a href="' . self::e($href) . '">View full table</a>';
        if ($start > 0) {
            $last = $start + count($rows);
            $notes[] = '<p class="fw-embed__note">Showing rows ' . self::numberSpan($start + 1) . ' to ' . self::numberSpan($last)
                . ' of ' . self::numberSpan($total) . '.' . ($total > $last ? $link : '') . '</p>';
        } else {
            $parts = [];
            if ($total > count($rows)) {
                $parts[] = self::numberSpan(count($rows)) . ' of ' . self::numberSpan($total) . ' rows';
            }
            if ($rest === null && $columnTotal > count($table['columns'])) {
                $parts[] = self::numberSpan(count($table['columns'])) . ' of ' . self::numberSpan($columnTotal) . ' columns';
            }
            if ($parts !== []) {
                $notes[] = '<p class="fw-embed__note">Showing ' . implode(' and ', $parts) . '.' . ($rest !== null ? ' The rest are in ' . $rest . '.' : $link) . '</p>';
            }
        }
        if ($rest === null && self::text($table['precision_note'] ?? null) !== null) {
            $notes[] = '<p class="fw-embed__note">' . self::e($table['precision_note']) . '</p>';
        }

        return $notes;
    }

    /**
     * Rows $start to $end (exclusive) of a data table. Decimal columns are judged on the first 25 rows,
     * so a table split across the figure and its details formats both parts alike.
     */
    private static function table(array $table, bool $showCaption, bool $modelLabels, array $doc, int $places, int $start, int $end, ?string $rest): string
    {
        $rows = self::shownRows($table, $start, $end);
        $sample = self::shownRows($table, 0, self::ROW_LIMIT);
        $columns = $table['columns'];
        $decimal = [];
        foreach ($columns as $index => $column) {
            $decimal[$index] = self::isDecimalColumn($sample, $index);
        }
        $lines = self::openTable($table, $showCaption, null);
        $head = '';
        foreach ($columns as $column) {
            $head .= $modelLabels
                ? self::th(self::columnLabel($column, true), self::isNumeric($column))
                : '<th scope="col"' . (self::isNumeric($column) ? ' class="fw-embed__num"' : '') . '>' . self::columnHeader($column) . '</th>';
        }
        $lines[] = '<thead><tr>' . $head . '</tr></thead>';
        $lines[] = '<tbody>';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($columns as $index => $column) {
                $cells .= self::td(self::formatCell(self::cell($row, $index), $column, $decimal[$index], $places), self::isNumeric($column));
            }
            $lines[] = '<tr>' . $cells . '</tr>';
        }
        array_push($lines, '</tbody>', '</table>', '</div>', ...self::tableNotes($table, $rows, $start, $doc, $rest));

        return implode("\n", $lines);
    }

    /** A coefficient table with the given columns: [header, numeric, fn (row) => text]. */
    private static function coefficientTable(array $table, array $doc, string $modifier, array $columns): string
    {
        $rows = self::shownRows($table, 0, self::ROW_LIMIT);
        $term = self::columnIndex($table, 'term');
        $lines = self::openTable($table, true, $modifier);
        $head = self::th(self::columnLabel($table['columns'][$term], true), false);
        foreach ($columns as [$header, $numeric]) {
            $head .= self::th($header, $numeric);
        }
        $lines[] = '<thead><tr>' . $head . '</tr></thead>';
        $lines[] = '<tbody>';
        foreach ($rows as $row) {
            $cells = '<th scope="row">' . self::termCell(Format::value(self::cell($row, $term)), $doc) . '</th>';
            foreach ($columns as [, $numeric, $value]) {
                $cells .= self::td($value($row), $numeric);
            }
            $lines[] = '<tr>' . $cells . '</tr>';
        }
        array_push($lines, '</tbody>', '</table>', '</div>', ...self::tableNotes($table, $rows, 0, $doc, null));

        return implode("\n", $lines);
    }

    /** Columns outside the standard set, such as a multinomial outcome, with their own formatting. */
    private static function extraColumns(array $table): array
    {
        $rows = self::shownRows($table, 0, self::ROW_LIMIT);
        $columns = [];
        foreach ($table['columns'] as $index => $column) {
            if (in_array($column['name'], self::COEFFICIENT_COLUMNS, true)) {
                continue;
            }
            $decimal = self::isDecimalColumn($rows, $index);
            $columns[] = [self::columnLabel($column, true), self::isNumeric($column),
                fn ($row) => self::formatCell(self::cell($row, $index), $column, $decimal, Format::RESULT_PLACES)];
        }

        return $columns;
    }

    private static function intervalLabel(int|float|null $level): string
    {
        // A credible interval is just the interval: readers needn't learn CrI
        $name = self::$bayesian ? 'interval' : 'CI';

        return $level === null ? $name : Format::percent($level) . ' ' . $name;
    }

    private static function decimalCell(int $index): \Closure
    {
        return fn ($row) => self::shown(self::cell($row, $index), Format::value(self::cell($row, $index), true, Format::RESULT_PLACES));
    }


    /** The log-scale label for each ratio, used as the estimate header and the switch's second option. */
    private const LOG_SCALES = ['Odds ratio' => 'Log odds', 'Hazard ratio' => 'Log hazard', 'Rate ratio' => 'Log rate', 'Incidence rate ratio' => 'Log rate', 'Time ratio' => 'Log time'];

    /**
     * The coefficient table. A model with an exponentiated scale (odds, hazard, rate ratios) gets the
     * same table twice, on the model scale and the ratio scale, and a switch between them, as Stata's
     * `or` option does: the estimate and interval are exponentiated, the std. error is the delta-method
     * one, exp(b) * se, and the statistic and p-value are unchanged. The switch is radio buttons in a
     * form of their own, so it works without JavaScript and each embed's buttons are separate. Where a
     * host strips the form controls, only the ratio view shows.
     */
    private static function coefficients(array $table, array $model, int|float|null $level, array $doc): string
    {
        $ratio = self::text($model['exponentiate'] ?? null);
        // A posterior's ratio scale comes from the exponentiated draws, which only the package has
        if (self::$bayesian && self::columnIndex($table, 'ratio_estimate') < 0) {
            $ratio = null;
        }
        if ($ratio === null) {
            return self::coefficientTable($table, $doc, 'coefficients', self::coefficientColumns($table, $level, self::$bayesian ? 'Posterior mean' : 'Estimate', false));
        }
        $logScale = self::LOG_SCALES[$ratio] ?? 'Coefficient';

        return implode("\n", [
            '<form class="fw-embed__scales">',
            '<div class="fw-embed__switch" role="radiogroup" aria-label="Scale">',
            '<label><input type="radio" name="fw-embed-scale" value="ratio" checked=""> ' . self::e($ratio) . '</label>',
            '<label><input type="radio" name="fw-embed-scale" value="model"> ' . self::e($logScale) . '</label>',
            '</div>',
            '<div class="fw-embed__scale fw-embed__scale--ratio">', self::coefficientTable($table, $doc, 'coefficients', self::coefficientColumns($table, $level, $ratio, true)), '</div>',
            '<div class="fw-embed__scale fw-embed__scale--model">', self::coefficientTable($table, $doc, 'coefficients', self::coefficientColumns($table, $level, $logScale, false)), '</div>',
            '</form>',
        ]);
    }

    /** The coefficient columns, on the model scale or exponentiated. */
    private static function coefficientColumns(array $table, int|float|null $level, string $estimateLabel, bool $exponentiated): array
    {
        if (self::$bayesian) {
            return self::posteriorColumns($table, $level, $estimateLabel, $exponentiated);
        }
        $estimate = self::columnIndex($table, 'estimate');
        $low = self::columnIndex($table, 'conf_low');
        $high = self::columnIndex($table, 'conf_high');
        // On the ratio scale the shown number is exp(value)
        $scaled = fn ($value) => $exponentiated && Format::isNumber($value) ? Format::exp($value) : $value;
        $text = fn ($value) => Format::value($value, true, Format::RESULT_PLACES);
        $columns = self::extraColumns($table);
        $columns[] = [$estimateLabel, true, fn ($row) => self::shown($scaled(self::cell($row, $estimate)), $text($scaled(self::cell($row, $estimate))))];
        if (($error = self::columnIndex($table, 'std_error')) >= 0) {
            $columns[] = ['Std. error', true, function ($row) use ($error, $estimate, $exponentiated): string|array {
                $se = self::cell($row, $error);
                $b = self::cell($row, $estimate);
                $value = $exponentiated && Format::isNumber($se) && Format::isNumber($b) ? Format::exp($b) * $se : $se;

                return self::shown($value, Format::value($value, true, Format::RESULT_PLACES));
            }];
        }
        if (($statistic = self::columnIndex($table, 'statistic')) >= 0) {
            $columns[] = ['Statistic', true, self::decimalCell($statistic)];
        }
        if (($p = self::columnIndex($table, 'p_value')) >= 0) {
            $columns[] = ['p-value', true, function ($row) use ($p): string|array {
                $value = self::cell($row, $p);

                return Format::isNumber($value) ? self::shown($value, Format::pValue($value)) : Format::value($value);
            }];
        }
        if ($low >= 0 && $high >= 0) {
            $columns[] = [self::intervalLabel($level), true, function ($row) use ($low, $high, $scaled, $text): string|array {
                $a = self::cell($row, $low);
                $b = self::cell($row, $high);

                return $a === null && $b === null ? Format::EMPTY : self::shownRange($scaled($a), $scaled($b), $text);
            }];
        }

        return $columns;
    }

    /**
     * A posterior summary: the package's own ratio-scale columns on the ratio scale, then the
     * sampling diagnostics.
     */
    private static function posteriorColumns(array $table, int|float|null $level, string $estimateLabel, bool $exponentiated): array
    {
        $at = fn (string $name) => self::columnIndex($table, $exponentiated ? 'ratio_' . $name : $name);
        $columns = self::extraColumns($table);
        if (($estimate = $at('estimate')) >= 0) {
            $columns[] = [$estimateLabel, true, self::decimalCell($estimate)];
        }
        if (($error = $at('std_error')) >= 0) {
            $columns[] = ['Posterior SD', true, self::decimalCell($error)];
        }
        $low = $at('conf_low');
        $high = $at('conf_high');
        if ($low >= 0 && $high >= 0) {
            $columns[] = [self::intervalLabel($level), true, function ($row) use ($low, $high): string|array {
                $a = self::cell($row, $low);
                $b = self::cell($row, $high);

                return $a === null && $b === null ? Format::EMPTY : self::shownRange($a, $b, fn ($x) => Format::value($x, true, Format::RESULT_PLACES));
            }];
        }
        if (($rhat = self::columnIndex($table, 'rhat')) >= 0) {
            $columns[] = ['R-hat', true, self::decimalCell($rhat)];
        }
        foreach (['ess_bulk', 'ess_tail'] as $name) {
            if (($index = self::columnIndex($table, $name)) >= 0) {
                $columns[] = [self::COLUMN_LABELS[$name], true, function ($row) use ($index): string|array {
                    $value = self::cell($row, $index);

                    return Format::isNumber($value) ? self::shown($value, Format::number((int) round($value))) : Format::value($value);
                }];
            }
        }

        return $columns;
    }

    /**
     * Warnings a reader should see beside a posterior: unconverged chains and divergent transitions.
     *
     * @return list<string>
     */
    private static function samplingWarnings(array $tables, array $metrics): array
    {
        $high = 0;
        foreach ($tables as $table) {
            $index = self::columnIndex($table, 'rhat');
            if ($index < 0) {
                continue;
            }
            foreach (self::lst($table['rows'] ?? null) as $row) {
                $value = self::cell($row, $index);
                if (Format::isNumber($value) && $value > self::RHAT_LIMIT) {
                    $high++;
                }
            }
        }
        $notes = [];
        if ($high > 0) {
            $notes[] = 'R-hat above 1.01 for ' . self::plural($high, 'parameter', 'parameters') . ': the chains may not have converged.';
        }
        foreach ($metrics as $metric) {
            if ($metric['name'] === 'divergent_transitions' && Format::isNumber($metric['value'] ?? null) && $metric['value'] > 0) {
                $notes[] = self::plural($metric['value'], 'divergent transition', 'divergent transitions') . ': the posterior may be biased.';
                break;
            }
        }

        return array_map(fn ($note) => '<p class="fw-embed__note fw-embed__warning">' . self::e($note) . '</p>', $notes);
    }



    private static function metricLabel(string $name): string
    {
        if (self::$bayesian && $name === 'confidence_level') {
            return 'Credible level';
        }
        if (array_key_exists($name, self::METRIC_LABELS)) {
            return self::METRIC_LABELS[$name];
        }
        if (str_starts_with($name, 'n_groups.') && strlen($name) > 9) {
            return 'Groups: ' . substr($name, 9);
        }

        return Format::asciiCapitalize(strtr($name, '_.', '  '));
    }

    private static function metricValue(string $name, mixed $value): string|array
    {
        if ($name === 'confidence_level' && Format::isNumber($value)) {
            return Format::percent($value);
        }
        if ($name === 'interval_method' && is_string($value) && array_key_exists($value, self::INTERVAL_METHODS)) {
            return self::INTERVAL_METHODS[$value];
        }
        if (Format::isNumber($value) && Format::isPValueName($name)) {
            return self::shown($value, Format::pValue($value));
        }

        return self::shown($value, Format::value($value, false, Format::RESULT_PLACES));
    }

    private static function metrics(array $metrics): string
    {
        return self::pairs(array_map(fn (array $metric) => [self::metricLabel($metric['name']), self::metricValue($metric['name'], $metric['value'] ?? null), Format::isNumber($metric['value'] ?? null)], $metrics));
    }

    private static function pairs(array $pairs): string
    {
        $lines = ['<dl class="fw-embed__stats">'];
        foreach ($pairs as [$term, $value, $numeric]) {
            // A value is text, or [text, title] when it rounds its number
            [$text, $title] = is_string($value) ? [$value, null] : $value;
            $tip = $title === null ? '' : ' title="' . self::e($title) . '"';
            if ($numeric) {
                $lines[] = '<div><dt>' . self::e($term) . '</dt><dd class="fw-embed__num"' . $tip . '>' . self::e($text) . '</dd></div>';
                continue;
            }
            $wide = preg_match_all('/./su', $text) > self::WIDE_TEXT ? ' fw-embed__stat--wide' : '';
            $lines[] = '<div class="fw-embed__stat--text' . $wide . '"><dt>' . self::e($term) . '</dt><dd' . $tip . '>' . self::e($text) . '</dd></div>';
        }
        $lines[] = '</dl>';

        return implode("\n", $lines);
    }

    // --- other kinds --------------------------------------------------------

    private static function image(array $doc): ?string
    {
        return self::imageMedia($doc['image'] ?? null, self::text($doc['alt'] ?? null) ?? self::text($doc['title'] ?? null) ?? '');
    }

    /** An image object as a figure: null without a safe URL. */
    private static function imageMedia(mixed $value, string $alt): ?string
    {
        $image = self::isObject($value) ? $value : null;
        $src = $image === null ? null : self::safeUrl($image['url'] ?? null);
        if ($src === null) {
            return null;
        }
        // Vector figures scale to the figure's width; raster figures stop at their natural size.
        $type = self::text($image['content_type'] ?? null);
        $vector = $type !== null && str_starts_with(strtolower($type), 'image/svg+xml');
        $tag = '<img class="fw-embed__img' . ($vector ? ' fw-embed__img--vector' : '') . '" src="' . self::e($src) . '" alt="' . self::e($alt) . '" loading="lazy" decoding="async"';
        $width = $image['width'] ?? null;
        $height = $image['height'] ?? null;
        if (Format::isInteger($width) && $width > 0 && Format::isInteger($height) && $height > 0) {
            $tag .= ' width="' . self::integer($width) . '" height="' . self::integer($height) . '"';
            // A tall vector figure narrows so its height stays within 80vh.
            if ($vector) {
                $tag .= ' style="max-width: min(100%, calc(80vh * ' . self::integer($width) . ' / ' . self::integer($height) . '))"';
            }
        }

        return '<div class="fw-embed__media">' . $tag . '></div>';
    }

    /**
     * The specification for the script to draw, over a fallback: the chart's static image when it has a safe
     * one, otherwise its alt text and a link back. The drawn chart replaces the fallback.
     */
    private static function chart(array $doc): ?string
    {
        $spec = $doc['spec'] ?? null;
        if (! ($spec instanceof stdClass) && ! self::isObject($spec)) {
            return null;
        }
        $alt = self::text($doc['alt'] ?? null) ?? self::text($doc['title'] ?? null) ?? 'Chart';
        $href = self::safeUrl($doc['url'] ?? null);
        $fallback = self::imageMedia($doc['image'] ?? null, $alt)
            ?? '<p class="fw-embed__fallback">' . self::e($alt) . ($href === null ? '' : ' <a href="' . self::e($href) . '">View chart</a>') . '</p>';

        return implode("\n", [
            '<div class="fw-embed__chart" data-fw-embed-chart>',
            '<script type="application/json">' . Json::forScript($spec) . '</script>',
            $fallback,
            '</div>',
        ]);
    }

    // --- code snippets ----------------------------------------------------------
    // Code and the text it printed, as a notebook showed them. Cloud colors the code and styles the
    // output; each block arrives as plain `text` and as `lines` of [classes, text] segments.

    private static function known(array $names, mixed $value): bool
    {
        return is_string($value) && array_key_exists($value, $names);
    }

    /** A segment's classes, each prefixed: "res bold" -> "fw-t-res fw-t-bold". Tokens outside [a-z0-9-] are dropped. */
    private static function segmentClasses(string $classes): string
    {
        $tokens = array_filter(explode(' ', $classes), fn (string $token) => preg_match('/^[a-z0-9-]+$/D', $token) === 1);

        return implode(' ', array_map(fn (string $token) => 'fw-t-' . $token, $tokens));
    }

    /** A block's lines as segments, or null when they don't fit the shape. */
    private static function snippetLines(array $block): ?array
    {
        $lines = $block['lines'] ?? null;
        if (! is_array($lines) || ! array_is_list($lines)) {
            return null;
        }
        foreach ($lines as $line) {
            if (! is_array($line) || ! array_is_list($line)) {
                return null;
            }
            foreach ($line as $segment) {
                if (! is_array($segment) || ! array_is_list($segment) || count($segment) !== 2 || ! is_string($segment[0]) || ! is_string($segment[1])) {
                    return null;
                }
            }
        }

        return $lines;
    }

    /** A block's HTML lines: styled segments, or its plain text when the segments are missing or malformed. */
    private static function snippetHtml(array $block): ?array
    {
        $lines = self::snippetLines($block);
        if ($lines === null) {
            return is_string($block['text'] ?? null) ? array_map(self::e(...), explode("\n", $block['text'])) : null;
        }

        return array_map(fn (array $line) => implode('', array_map(function (array $segment) {
            $names = self::segmentClasses($segment[0]);

            return $names === '' ? self::e($segment[1]) : '<span class="' . $names . '">' . self::e($segment[1]) . '</span>';
        }, $line)), $lines);
    }

    private static function snippetHead(array $source, bool $output, bool $shown): string
    {
        $parts = ['<div class="fw-embed__snippet-head">',
            '<span class="fw-embed__lang">' . (self::known(self::SNIPPET_LANGUAGES, $source['language'] ?? null) ? self::SNIPPET_LANGUAGES[$source['language']] : 'Code') . '</span>'];
        $file = self::text($source['file'] ?? null);
        $name = array_values(array_filter([$file === null ? null : substr((string) strrchr('/' . $file, '/'), 1), self::text($source['label'] ?? null)],
            fn (?string $part) => $part !== null && $part !== ''));
        if ($name !== []) {
            $parts[] = '<span class="fw-embed__source">' . self::e(implode(' · ', $name)) . '</span>';
        }
        $range = self::lst($source['lines'] ?? null);
        if (count($range) === 2 && Format::isInteger($range[0]) && Format::isInteger($range[1]) && $range[0] >= 1 && $range[1] >= $range[0]) {
            $label = $range[0] == $range[1] ? 'Line ' . Format::number($range[0]) : 'Lines ' . Format::number($range[0]) . '–' . Format::number($range[1]);
            $href = self::safeUrl($source['permalink'] ?? null);
            $parts[] = $href === null
                ? '<span class="fw-embed__lines">' . $label . '</span>'
                : '<a class="fw-embed__lines" href="' . self::e($href) . '" target="_blank" rel="noopener">' . $label . '</a>';
        }
        if ($output) {
            $parts[] = '<button type="button" class="fw-embed__copy fw-embed__toggle" data-fw-output hidden>' . ($shown ? 'Hide output' : 'Show output') . '</button>';
        }
        $parts[] = '<button type="button" class="fw-embed__copy" data-fw-copy hidden>Copy</button>';
        $parts[] = '</div>';

        return implode("\n", $parts);
    }

    private static function snippetBlock(array $block, bool $shown): ?string
    {
        $html = self::snippetHtml($block);
        if ($html === null) {
            return null;
        }
        if ($block['type'] === 'code') {
            return '<pre class="fw-embed__code"><code>' . implode("\n", $html) . '</code></pre>';
        }
        $truncated = self::obj($block['truncated'] ?? null);
        $truncated = Format::isInteger($truncated['lines'] ?? null) && $truncated['lines'] > 0 ? $truncated['lines'] : 0;
        $parts = ['<div class="fw-embed__block fw-embed__block--' . $block['type'] . '"' . ($shown ? '' : ' hidden') . '>'];
        $body = ['<pre class="fw-embed__output"><code>' . implode("\n", $html) . '</code></pre>'];
        if ($truncated > 0) {
            $body[] = '<p class="fw-embed__more">' . self::plural($truncated, 'more line', 'more lines') . ' not shown.</p>';
        }
        if (($block['folded'] ?? null) === true) {
            $parts = [...$parts, '<details class="fw-embed__fold">', '<summary>' . self::SNIPPET_BLOCKS[$block['type']] . ', ' . self::plural(count($html) + $truncated, 'line', 'lines') . '</summary>', ...$body, '</details>'];
        } else {
            $parts = [...$parts, ...$body];
        }
        $parts[] = '</div>';

        return implode("\n", $parts);
    }

    private static function snippet(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data) || ! self::isObject($data['snippet'] ?? null)) {
            return null;
        }
        $source = self::obj($data['source'] ?? null);
        // display.output false: the output starts hidden, and the toggle shows it
        $shown = (self::obj($data['display'] ?? null)['output'] ?? null) !== false;
        $blocks = [];
        $code = false;
        $output = false;
        foreach (self::lst($data['snippet']['blocks'] ?? null) as $block) {
            if (! self::isObject($block) || ! self::known(self::SNIPPET_BLOCKS, $block['type'] ?? null)) {
                continue;
            }
            $html = self::snippetBlock($block, $shown);
            if ($html === null) {
                continue;
            }
            $code = $code || $block['type'] === 'code';
            $output = $output || $block['type'] !== 'code';
            $blocks[] = $html;
        }
        if (! $code) {
            return null;
        }
        $parts = ['<div class="fw-embed__snippet">', self::snippetHead($source, $output, $shown), ...$blocks];
        $links = [];
        foreach (self::lst(self::obj($data['display'] ?? null)['links'] ?? null) as $link) {
            if (! self::isObject($link) || ! self::known(self::SNIPPET_LINKS, $link['rel'] ?? null)) {
                continue;
            }
            $href = self::safeUrl($link['url'] ?? null);
            if ($href !== null) {
                $links[] = '<a href="' . self::e($href) . '" target="_blank" rel="noopener">' . self::SNIPPET_LINKS[$link['rel']] . '</a>';
            }
        }
        if ($links !== []) {
            $parts[] = '<p class="fw-embed__links">' . implode(' ', $links) . '</p>';
        }
        $parts[] = '</div>';

        return implode("\n", $parts);
    }

    // --- DAGs: Framework Cloud lays the graph out; renderers write the boxes and edge paths it sends ---

    private static function dagSize(mixed $value): bool
    {
        return Format::isInteger($value) && $value >= 0 && $value <= 100000;
    }

    private static function dagPath(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[MLQZ0-9 .-]{1,400}$/D', $value) === 1;
    }

    /** At most 32 code points, cut to 31 with trailing spaces dropped and an ellipsis added. */
    private static function dagShown(string $label): string
    {
        return mb_strlen($label, 'UTF-8') > self::DAG_LABEL_CHARS
            ? rtrim(mb_substr($label, 0, self::DAG_LABEL_CHARS - 1, 'UTF-8'), ' ') . '…'
            : $label;
    }

    /** @return list<array{name: string, label: string, roles: list<string>, x: int|float, y: int|float, width: int|float, height: int|float}> */
    private static function dagNodes(array $graph): array
    {
        $nodes = [];
        foreach (self::lst($graph['nodes'] ?? null) as $node) {
            if (! self::isObject($node) || self::text($node['name'] ?? null) === null) {
                continue;
            }
            $sizes = [$node['x'] ?? null, $node['y'] ?? null, $node['width'] ?? null, $node['height'] ?? null];
            if (count(array_filter($sizes, self::dagSize(...))) !== 4) {
                continue;
            }
            $given = self::lst($node['roles'] ?? null);
            $roles = array_values(array_filter(array_keys(self::DAG_ROLES), fn (string $role) => in_array($role, $given, true)));
            $nodes[] = ['name' => $node['name'], 'label' => self::text($node['label'] ?? null) ?? $node['name'], 'roles' => $roles,
                'x' => $node['x'], 'y' => $node['y'], 'width' => $node['width'], 'height' => $node['height']];
        }

        return $nodes;
    }

    private static function dagSvg(array $doc, array $graph, array $nodes): string
    {
        $label = self::text($doc['alt'] ?? null) ?? self::text($doc['title'] ?? null) ?? 'Causal diagram';
        $width = self::integer($graph['width']);
        $height = self::integer($graph['height']);
        $lines = ['<svg class="fw-embed__dag-graph" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '"'
            . ' style="min-width: ' . self::integer(floor($graph['width'] * 3 / 4)) . 'px" role="img" aria-label="' . self::e($label) . '">'];
        foreach (self::lst($graph['edges'] ?? null) as $edge) {
            if (! self::isObject($edge) || ! self::dagPath($edge['path'] ?? null)) {
                continue;
            }
            $lines[] = '<path class="fw-embed__dag-edge' . (($edge['type'] ?? null) === '<->' ? ' fw-embed__dag-edge--bidirected' : '') . '" d="' . $edge['path'] . '"/>';
            foreach (self::lst($edge['heads'] ?? null) as $head) {
                if (self::dagPath($head)) {
                    $lines[] = '<path class="fw-embed__dag-head" d="' . $head . '"/>';
                }
            }
        }
        foreach ($nodes as $node) {
            $shown = self::dagShown($node['label']);
            $classes = implode(' ', ['fw-embed__dag-node', ...array_map(fn (string $role) => 'fw-embed__dag-node--' . $role, $node['roles'])]);
            $lines[] = '<g class="' . $classes . '">' . ($shown === $node['label'] ? '' : '<title>' . self::e($node['label']) . '</title>')
                . '<rect x="' . self::integer($node['x']) . '" y="' . self::integer($node['y']) . '" width="' . self::integer($node['width']) . '" height="' . self::integer($node['height']) . '" rx="6"/>'
                . '<text x="' . self::integer($node['x'] + floor($node['width'] / 2)) . '" y="' . self::integer($node['y'] + floor($node['height'] / 2)) . '">' . self::e($shown) . '</text></g>';
        }
        $lines[] = '</svg>';

        return implode("\n", $lines);
    }

    /** @param  array<array-key, string>  $labels */
    private static function dagNames(array $names, array $labels): string
    {
        return implode(', ', array_map(fn (string $name) => '<code class="fw-embed__var">' . self::e($labels[$name] ?? $name) . '</code>', $names));
    }

    private static function dagNameList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $name) {
            if (! is_string($name)) {
                return null;
            }
        }

        return $value;
    }

    /** Names the analysis section by what it holds: adjustment sets, testable implications, or both. */
    private static function dagAnalysisLabel(array $blocks): string
    {
        $sets = array_filter($blocks, fn (string $block) => str_contains($block, 'adjustment sets')) !== [];
        $implications = in_array('<p class="fw-embed__note">Testable implications:</p>', $blocks, true);
        if ($sets && $implications) {
            return 'Adjustment sets and testable implications';
        }

        return $implications ? 'Testable implications' : 'Causal adjustment sets';
    }

    private static function dagAnalysis(mixed $analysis, array $labels): array
    {
        if (! self::isObject($analysis)) {
            return [];
        }
        $blocks = [];
        $exposures = self::dagNameList($analysis['exposures'] ?? null) ?? [];
        $outcomes = self::dagNameList($analysis['outcomes'] ?? null) ?? [];
        $sets = $analysis['adjustment_sets'] ?? null;
        if ($exposures !== [] && $outcomes !== [] && is_array($sets) && array_is_list($sets)) {
            $sets = array_values(array_filter(array_map(self::dagNameList(...), $sets), fn (?array $set) => $set !== null));
            $effect = ($analysis['effect'] ?? null) === 'direct' ? 'direct' : 'total';
            $blocks[] = '<p class="fw-embed__note">Minimal sufficient adjustment sets for the ' . $effect . ' effect of ' . self::dagNames($exposures, $labels) . ' on ' . self::dagNames($outcomes, $labels) . ':</p>';
            if ($sets === []) {
                $blocks[] = '<p class="fw-embed__dag-result">No set of observed variables identifies the effect.</p>';
            } elseif (count($sets) === 1 && $sets[0] === []) {
                $blocks[] = '<p class="fw-embed__dag-result">No adjustment is needed.</p>';
            } else {
                $blocks = [...$blocks, '<ul class="fw-embed__dag-list">', ...array_map(fn (array $set) => '<li>{ ' . self::dagNames($set, $labels) . ' }</li>', $sets), '</ul>'];
            }
        }
        $implications = array_values(array_filter(self::lst($analysis['implications'] ?? null), fn (mixed $entry) => self::isObject($entry)
            && is_string($entry['x'] ?? null) && is_string($entry['y'] ?? null) && self::dagNameList($entry['given'] ?? null) !== null));
        if ($implications !== []) {
            $blocks = [...$blocks, '<p class="fw-embed__note">Testable implications:</p>', '<ul class="fw-embed__dag-list">',
                ...array_map(fn (array $entry) => '<li>' . self::dagNames([$entry['x']], $labels) . ' ⫫ ' . self::dagNames([$entry['y']], $labels)
                    . ($entry['given'] !== [] ? ' | ' . self::dagNames($entry['given'], $labels) : '') . '</li>', $implications), '</ul>'];
        }

        return $blocks;
    }

    private static function dagCode(string $label, mixed $block): ?string
    {
        if (! self::isObject($block)) {
            return null;
        }
        $html = self::snippetHtml($block);
        if ($html === null) {
            return null;
        }

        return implode("\n", ['<div class="fw-embed__snippet">', '<div class="fw-embed__snippet-head">', '<span class="fw-embed__lang">' . $label . '</span>',
            '<button type="button" class="fw-embed__copy" data-fw-copy hidden>Copy</button>', '</div>',
            '<pre class="fw-embed__code"><code>' . implode("\n", $html) . '</code></pre>', '</div>']);
    }

    private static function dag(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data) || ! self::isObject($data['dag'] ?? null)) {
            return null;
        }
        $graph = $data['dag'];
        if (! self::dagSize($graph['width'] ?? null) || ! self::dagSize($graph['height'] ?? null) || $graph['width'] == 0 || $graph['height'] == 0) {
            return null;
        }
        $nodes = self::dagNodes($graph);
        if ($nodes === []) {
            return null;
        }
        $labels = [];
        foreach ($nodes as $node) {
            $labels[$node['name']] = $node['label'];
        }
        $parts = ['<div class="fw-embed__dag">', self::dagSvg($doc, $graph, $nodes)];
        $roles = array_values(array_filter(array_keys(self::DAG_ROLES), fn (string $role) => array_filter($nodes, fn (array $node) => in_array($role, $node['roles'], true)) !== []));
        if ($roles !== [] && ! self::hidden($data, 'legend')) {
            $parts[] = '<p class="fw-embed__dag-legend">' . implode(' ', array_map(fn (string $role) => '<span class="fw-embed__dag-key fw-embed__dag-key--' . $role . '">' . self::DAG_ROLES[$role] . '</span>', $roles)) . '</p>';
        }
        $parts[] = '</div>';
        $code = $data['code'] ?? null;
        $language = self::isObject($code) && self::known(self::SNIPPET_LANGUAGES, $code['language'] ?? null) ? self::SNIPPET_LANGUAGES[$code['language']] : null;
        $code = $language === null ? null : self::dagCode($language, $code);
        $dagitty = self::dagCode('DAGitty', $data['dagitty'] ?? null);
        $description = self::text($doc['description'] ?? null) === null ? [] : ['<p class="fw-embed__note">' . self::e($doc['description']) . '</p>'];
        $analysis = self::dagAnalysis($data['analysis'] ?? null, $labels);
        $parts = [...$parts, ...self::details('Description', $description),
            ...self::details(self::dagAnalysisLabel($analysis), $analysis),
            ...self::details('DAGitty specification', $dagitty === null ? [] : [$dagitty]),
            ...self::details($language . ' code', $code === null ? [] : [$code])];

        return implode("\n", $parts);
    }

    // --- project structures ---------------------------------------------------------
    // A folder tree, drawn as fw_structure() prints it: box-drawing guides, folders as native
    // disclosures, and notes in a column. Framework Cloud sorts the tree and strips what the
    // owner turned off; renderers draw what they get.

    private static function treeLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private static function treeCountValid(mixed $value): bool
    {
        return Format::isInteger($value) && $value >= 0;
    }

    /** 1 file, 12 files, 1,520 files; 10,000 or more reads 10,000+ files. */
    private static function treeCount(int|float $count): string
    {
        if ($count >= self::TREE_COUNT_CAP) {
            return '10,000+ files';
        }

        return number_format($count) . ($count == 1 ? ' file' : ' files');
    }

    /** The rule that ignores a path, when it names one. */
    private static function treeIgnored(mixed $value): ?array
    {
        return self::isObject($value) && self::text($value['rule'] ?? null) !== null ? $value : null;
    }

    /** Where a rule comes from: ".gitignore line 7", or null when the document doesn't say. */
    private static function treeSource(array $ignored): ?string
    {
        $source = self::text($ignored['source'] ?? null);
        $parts = $source === null ? [] : [self::known(self::TREE_SOURCES, $source) ? self::TREE_SOURCES[$source] : $source];
        $line = $ignored['line'] ?? null;
        if (Format::isInteger($line) && $line > 0 && $line <= 1000000) {
            $parts[] = 'line ' . self::integer($line);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** A list's usable nodes, in document order. */
    private static function treeNodes(mixed $children, array $data): array
    {
        $showIgnored = ! self::hidden($data, 'ignored');
        $nodes = [];
        foreach (self::lst($children) as $node) {
            if (! self::isObject($node)) {
                continue;
            }
            $ignored = $showIgnored ? self::treeIgnored($node['ignored'] ?? null) : null;
            $hiddenCount = self::treeCountValid($node['hidden'] ?? null) ? $node['hidden'] : null;
            $shared = ['note' => self::text($node['note'] ?? null), 'highlight' => ($node['highlight'] ?? null) === true, 'hidden' => $hiddenCount];
            $type = $node['type'] ?? null;
            $name = self::text($node['name'] ?? null);
            if ($type === 'file') {
                if ($name !== null) {
                    $nodes[] = [...$shared, 'type' => 'file', 'name' => $name, 'ignored' => null, 'private' => false];
                }

                continue;
            }
            if ($type === 'ignored') {
                if (! $showIgnored) {
                    continue;
                }
                $name ??= $ignored === null ? null : self::text(rtrim($ignored['rule'], '/'));
                if ($name !== null) {
                    $nodes[] = [...$shared, 'type' => 'group', 'name' => $name, 'ignored' => $ignored, 'private' => false];
                }

                continue;
            }
            if ($type !== 'dir' || $name === null) {
                continue;
            }
            $private = ($node['private'] ?? null) === true;
            // An ignored folder that isn't private leaves with the other ignored paths
            if (! $showIgnored && self::treeIgnored($node['ignored'] ?? null) !== null && ! $private) {
                continue;
            }
            $stub = $hiddenCount !== null || $private || $ignored !== null || ($node['depth'] ?? null) === true;
            $nodes[] = [...$shared, 'type' => 'dir', 'name' => $name, 'ignored' => $ignored, 'private' => $private, 'stub' => $stub,
                'open' => ($node['open'] ?? null) === true, 'children' => $stub ? [] : ($node['children'] ?? null)];
        }

        return $nodes;
    }

    /** The badge on a stub or an ignored group: "ignored · *.rds · 4 files", "private · 3 files", "12 files". */
    private static function treeBadge(array $node): string
    {
        $parts = [];
        if ($node['private']) {
            $parts[] = 'private';
        }
        if ($node['type'] === 'group' || $node['ignored'] !== null) {
            $parts[] = 'ignored';
        }
        if ($node['ignored'] !== null) {
            $parts[] = self::e($node['ignored']['rule']);
        }
        if ($node['hidden'] !== null) {
            $parts[] = self::treeCount($node['hidden']);
        }
        if ($parts === []) {
            return '';
        }
        $source = $node['ignored'] === null ? null : self::treeSource($node['ignored']);

        return '<span class="fw-embed__tree-badge"' . ($source === null ? '' : ' title="' . self::e($source) . '"') . '>' . implode(' · ', $parts) . '</span>';
    }

    private static function treeRow(string $tag, array $node, string $guide, array $data): string
    {
        $stub = $node['type'] === 'group' || ($node['type'] === 'dir' && $node['stub']);
        $classes = ['fw-embed__tree-row', 'fw-embed__tree-row--' . $node['type']];
        if ($stub) {
            $classes[] = 'fw-embed__tree-row--stub';
        }
        if ($node['highlight']) {
            $classes[] = 'fw-embed__tree-row--highlight';
        }
        $label = $node['type'] === 'dir' ? $node['name'] . '/' : $node['name'];
        $note = $node['note'] === null || self::hidden($data, 'notes') ? '' : '<span class="fw-embed__tree-note">' . self::e($node['note']) . '</span>';

        return '<' . $tag . ' class="' . implode(' ', $classes) . '"><span class="fw-embed__tree-name">'
            . '<span class="fw-embed__tree-guide" aria-hidden="true">' . $guide . '</span>' . self::e($label) . '</span>'
            . ($stub ? self::treeBadge($node) : '') . $note . '</' . $tag . '>';
    }

    /** Rows for one folder's contents. `$prefix` carries the guides of the folders above, and `$visible` says whether every folder above starts open. */
    private static function treeRows(array $nodes, string $prefix, int $depth, array $data, array &$state, array &$out, bool $visible): void
    {
        $count = count($nodes);
        foreach ($nodes as $index => $node) {
            if ($state['rows'] >= self::TREE_ROWS) {
                return;
            }
            $state['rows']++;
            if ($visible) {
                $state['visible']++;
            }
            $last = $index === $count - 1;
            $children = $node['type'] === 'dir' && ! $node['stub'] && $depth < self::TREE_DEPTH ? self::treeNodes($node['children'], $data) : [];
            if ($children === []) {
                $guide = $prefix . ($last ? '└── ' : '├── ');
                $state['width'] = max($state['width'], self::treeLength($guide) + self::treeLength($node['name']) + ($node['type'] === 'dir' ? 1 : 0));
                $out[] = self::treeRow('div', $node, $guide, $data);

                continue;
            }
            // A folder's guide leaves a cell for its disclosure marker: "├─  R/"
            $guide = $prefix . ($last ? '└─  ' : '├─  ');
            $state['width'] = max($state['width'], self::treeLength($guide) + self::treeLength($node['name']) + 1);
            $out[] = '<details class="fw-embed__tree-dir"' . ($node['open'] ? ' open' : '') . '>';
            $out[] = self::treeRow('summary', $node, $guide, $data);
            self::treeRows($children, $prefix . ($last ? '    ' : '│   '), $depth + 1, $data, $state, $out, $visible && $node['open']);
            $out[] = '</details>';
        }
    }

    private static function tree(array $doc): ?string
    {
        $data = $doc['data'] ?? null;
        if (! self::isObject($data) || ! self::isObject($data['tree'] ?? null) || self::text($data['tree']['root'] ?? null) === null) {
            return null;
        }
        $tree = $data['tree'];
        // The breadcrumb: the folders above the published one, when it isn't the project root
        $base = self::text($tree['base'] ?? null) === null ? [] : array_values(array_filter(explode('/', $tree['base']), fn (string $part) => $part !== ''));
        if ($base !== [] && $base[count($base) - 1] === $tree['root']) {
            array_pop($base);
        }
        $crumb = $base === [] ? '' : implode('/', $base) . '/';
        $state = ['rows' => 0, 'visible' => 1, 'width' => self::treeLength($crumb) + self::treeLength($tree['root']) + 1];
        $rows = [];
        self::treeRows(self::treeNodes($tree['children'] ?? null, $data), '', 1, $data, $state, $rows, true);
        // A long tree starts clipped, with a button to show the rest; the JS bundle clips it and shows the button
        $clip = $state['visible'] > self::TREE_CLIP_ROWS && (self::obj($data['display'] ?? null)['full'] ?? null) !== true;

        return implode("\n", ['<div class="fw-embed__tree"' . ($clip ? ' data-fw-embed-clip' : '') . ' style="--fw-embed-tree-names: ' . min($state['width'], self::TREE_NAMES) . 'ch">',
            '<div class="fw-embed__tree-row fw-embed__tree-row--root"><span class="fw-embed__tree-name">'
                . ($crumb === '' ? '' : '<span class="fw-embed__tree-crumb">' . self::e($crumb) . '</span>') . self::e($tree['root']) . '/</span></div>',
            ...$rows, '</div>',
            ...($clip ? ['<button type="button" class="fw-embed__more" hidden aria-expanded="false">Show more</button>'] : [])]);
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
