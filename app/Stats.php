<?php
/**
 * webtrees: online genealogy
 * Copyright (C) 2019 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Fisharebest\Webtrees;

use Fisharebest\Webtrees\Functions\FunctionsDate;
use Fisharebest\Webtrees\Functions\FunctionsPrint;
use Fisharebest\Webtrees\Http\Middleware\PageHitCounter;
use Fisharebest\Webtrees\Module\FamilyTreeFavoritesModule;
use Fisharebest\Webtrees\Module\UserFavoritesModule;
use Fisharebest\Webtrees\Statistics\Age as StatisticAge;
use Fisharebest\Webtrees\Statistics\AgeDifferenceSiblings;
use Fisharebest\Webtrees\Statistics\AgeDifferenceSpouse;
use Fisharebest\Webtrees\Statistics\Birth;
use Fisharebest\Webtrees\Statistics\BirthPlaces;
use Fisharebest\Webtrees\Statistics\Children;
use Fisharebest\Webtrees\Statistics\Death;
use Fisharebest\Webtrees\Statistics\DeathPlaces;
use Fisharebest\Webtrees\Statistics\Google;
use Fisharebest\Webtrees\Statistics\Helper\Century;
use Fisharebest\Webtrees\Statistics\Helper\Country;
use Fisharebest\Webtrees\Statistics\Helper\Percentage;
use Fisharebest\Webtrees\Statistics\Helper\Sql;
use Fisharebest\Webtrees\Statistics\Individual as StatisticIndividual;
use Fisharebest\Webtrees\Statistics\FamilyRepository;
use Fisharebest\Webtrees\Statistics\Source as StatisticSource;
use Fisharebest\Webtrees\Statistics\Note as StatisticNote;
use Fisharebest\Webtrees\Statistics\Marriage;
use Fisharebest\Webtrees\Statistics\MarriageAge;
use Fisharebest\Webtrees\Statistics\MarriagePlaces;
use Fisharebest\Webtrees\Statistics\Places;
use Fisharebest\Webtrees\Statistics\Surname;
use PDOException;
use stdClass;
use const PREG_SET_ORDER;

/**
 * A selection of pre-formatted statistical queries.
 *
 * These are primarily used for embedded keywords on HTML blocks, but
 * are also used elsewhere in the code.
 */
class Stats
{
    /** @var Tree Generate statistics for a specified tree. */
    private $tree;

    /** @var string[] All public functions are available as keywords - except these ones */
    private $public_but_not_allowed = [
        '__construct',
        'embedTags',
        'iso3166',
        'getAllCountries',
        'getAllTagsTable',
        'getAllTagsText',
        'statsPlaces',
        'statsBirthQuery',
        'statsDeathQuery',
        'statsMarrQuery',
        'statsAgeQuery',
        'monthFirstChildQuery',
        'statsChildrenQuery',
        'statsMarrAgeQuery',
    ];

    /**
     * @var Surname
     */
    private $surname;

    /**
     * @var StatisticIndividual
     */
    private $individual;

    /**
     * @var FamilyRepository
     */
    private $family;

    /**
     * @var Children
     */
    private $children;

    /**
     * @var StatisticSource
     */
    private $source;

    /**
     * @var StatisticNote
     */
    private $note;

    /**
     * @var Google
     */
    private $google;

    /**
     * @var Century
     */
    private $centuryHelper;

    /**
     * @var Country
     */
    private $countryHelper;

    /**
     * @var Statistics\Media
     */
    private $media;

    /**
     * @var Statistics\Living
     */
    private $living;

    /**
     * @var Statistics\Deceased
     */
    private $deceased;

    /**
     * Create the statistics for a tree.
     *
     * @param Tree $tree Generate statistics for this tree
     */
    public function __construct(Tree $tree)
    {
        $this->tree          = $tree;
        $this->surname       = new Surname($tree);
        $this->individual    = new StatisticIndividual($tree);
        $this->family        = new FamilyRepository($tree);
        $this->children      = new Children($tree);
        $this->source        = new StatisticSource($tree);
        $this->note          = new StatisticNote($tree);
        $this->google        = new Google();
        $this->centuryHelper = new Century();
        $this->countryHelper = new Country();
        $this->media         = new Statistics\Media($tree);
        $this->living        = new Statistics\Living($tree);
        $this->deceased      = new Statistics\Deceased($tree);
    }

    /**
     * Return a string of all supported tags and an example of its output in table row form.
     *
     * @return string
     */
    public function getAllTagsTable(): string
    {
        $examples = [];
        foreach (get_class_methods($this) as $method) {
            $reflection = new \ReflectionMethod($this, $method);
            if ($reflection->isPublic() && !in_array($method, $this->public_but_not_allowed)) {
                $examples[$method] = $this->$method();
            }
        }
        ksort($examples);

        $html = '';
        foreach ($examples as $tag => $value) {
            $html .= '<dt>#' . $tag . '#</dt>';
            $html .= '<dd>' . $value . '</dd>';
        }

        return '<dl>' . $html . '</dl>';
    }

    /**
     * Return a string of all supported tags in plain text.
     *
     * @return string
     */
    public function getAllTagsText(): string
    {
        $examples = [];
        foreach (get_class_methods($this) as $method) {
            $reflection = new \ReflectionMethod($this, $method);
            if ($reflection->isPublic() && !in_array($method, $this->public_but_not_allowed)) {
                $examples[$method] = $method;
            }
        }
        ksort($examples);

        return implode('<br>', $examples);
    }

    /**
     * Get tags and their parsed results.
     *
     * @param string $text
     *
     * @return string[]
     */
    private function getTags(string $text): array
    {
        $tags = [];

        preg_match_all('/#([^#]+)#/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $params = explode(':', $match[1]);
            $method = array_shift($params);

            if (method_exists($this, $method)) {
                $tags[$match[0]] = $this->$method(...$params);
            }
        }

        return $tags;
    }

    /**
     * Embed tags in text
     *
     * @param string $text
     *
     * @return string
     */
    public function embedTags(string $text): string
    {
        if (strpos($text, '#') !== false) {
            $text = strtr($text, $this->getTags($text));
        }

        return $text;
    }

    /**
     * Get the name used for GEDCOM files and URLs.
     *
     * @return string
     */
    public function gedcomFilename(): string
    {
        return $this->tree->name();
    }

    /**
     * Get the internal ID number of the tree.
     *
     * @return int
     */
    public function gedcomId(): int
    {
        return $this->tree->id();
    }

    /**
     * Get the descriptive title of the tree.
     *
     * @return string
     */
    public function gedcomTitle(): string
    {
        return e($this->tree->title());
    }

    /**
     * Get information from the GEDCOM's HEAD record.
     *
     * @return string[]
     */
    private function gedcomHead(): array
    {
        $title   = '';
        $version = '';
        $source  = '';

        $head = GedcomRecord::getInstance('HEAD', $this->tree);
        $sour = $head->getFirstFact('SOUR');
        if ($sour !== null) {
            $source  = $sour->value();
            $title   = $sour->attribute('NAME');
            $version = $sour->attribute('VERS');
        }

        return [
            $title,
            $version,
            $source,
        ];
    }

    /**
     * Get the software originally used to create the GEDCOM file.
     *
     * @return string
     */
    public function gedcomCreatedSoftware(): string
    {
        $head = $this->gedcomHead();

        return $head[0];
    }

    /**
     * Get the version of software which created the GEDCOM file.
     *
     * @return string
     */
    public function gedcomCreatedVersion(): string
    {
        $head = $this->gedcomHead();
        // fix broken version string in Family Tree Maker
        if (strstr($head[1], 'Family Tree Maker ')) {
            $p       = strpos($head[1], '(') + 1;
            $p2      = strpos($head[1], ')');
            $head[1] = substr($head[1], $p, ($p2 - $p));
        }
        // Fix EasyTree version
        if ($head[2] == 'EasyTree') {
            $head[1] = substr($head[1], 1);
        }

        return $head[1];
    }

    /**
     * Get the date the GEDCOM file was created.
     *
     * @return string
     */
    public function gedcomDate(): string
    {
        $head = GedcomRecord::getInstance('HEAD', $this->tree);
        $fact = $head->getFirstFact('DATE');
        if ($fact) {
            $date = new Date($fact->value());

            return $date->display();
        }

        return '';
    }

    /**
     * When was this tree last updated?
     *
     * @return string
     */
    public function gedcomUpdated(): string
    {
        $row = Database::prepare(
            "SELECT d_year, d_month, d_day FROM `##dates` WHERE d_julianday1 = (SELECT MAX(d_julianday1) FROM `##dates` WHERE d_file =? AND d_fact='CHAN') LIMIT 1"
        )->execute([$this->tree->id()])->fetchOneRow();
        if ($row) {
            $date = new Date("{$row->d_day} {$row->d_month} {$row->d_year}");

            return $date->display();
        }

        return $this->gedcomDate();
    }

    /**
     * What is the significant individual from this tree?
     *
     * @return string
     */
    public function gedcomRootId(): string
    {
        return $this->tree->getPreference('PEDIGREE_ROOT_ID');
    }

    /**
     * Convert totals into percentages.
     *
     * @param int    $total
     * @param string $type
     *
     * @return string
     *
     * @deprecated
     */
    private function getPercentage(int $total, string $type): string
    {
        return (new Percentage($this->tree))->getPercentage($total, $type);
    }

    /**
     * How many GEDCOM records exist in the tree.
     *
     * @return string
     */
    public function totalRecords(): string
    {
        return I18N::number($this->individual->totalIndividualsQuery() + $this->family->totalFamiliesQuery() + $this->source->totalSourcesQuery());
    }

    /**
     * How many individuals exist in the tree.
     *
     * @return string
     */
    public function totalIndividuals(): string
    {
        return I18N::number($this->individual->totalIndividualsQuery());
    }

    /**
     * How many individuals have one or more sources.
     *
     * @return int
     */
    private function totalIndisWithSourcesQuery(): int
    {
        return (int) Database::prepare(
            "SELECT COUNT(DISTINCT i_id)" .
            " FROM `##individuals` JOIN `##link` ON i_id = l_from AND i_file = l_file" .
            " WHERE l_file = :tree_id AND l_type = 'SOUR'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();
    }

    /**
     * How many individuals have one or more sources.
     *
     * @return string
     */
    public function totalIndisWithSources(): string
    {
        return I18N::number($this->totalIndisWithSourcesQuery());
    }

    /**
     * Create a chart showing individuals with/without sources.
     *
     * @param string|null $size        // Optional parameter, set from tag
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function chartIndisWithSources(string $size = null, string $color_from = null, string $color_to = null): string
    {
        $WT_STATS_CHART_COLOR1 = Theme::theme()->parameter('distribution-chart-no-values');
        $WT_STATS_CHART_COLOR2 = Theme::theme()->parameter('distribution-chart-high-values');
        $WT_STATS_S_CHART_X    = Theme::theme()->parameter('stats-small-chart-x');
        $WT_STATS_S_CHART_Y    = Theme::theme()->parameter('stats-small-chart-y');

        $size       = $size ?? ($WT_STATS_S_CHART_X . 'x' . $WT_STATS_S_CHART_Y);
        $color_from = $color_from ?? $WT_STATS_CHART_COLOR1;
        $color_to   = $color_to ?? $WT_STATS_CHART_COLOR2;

        $sizes    = explode('x', $size);
        $tot_indi = $this->individual->totalIndividualsQuery();
        if ($tot_indi == 0) {
            return '';
        }

        $tot_sindi_per = $this->totalIndisWithSourcesQuery() / $tot_indi;
        $with          = (int) (100 * $tot_sindi_per);
        $chd           = $this->google->arrayToExtendedEncoding([100 - $with, $with]);
        $chl           = I18N::translate('Without sources') . ' - ' . I18N::percentage(1 - $tot_sindi_per, 1) . '|' . I18N::translate('With sources') . ' - ' . I18N::percentage($tot_sindi_per, 1);
        $chart_title   = I18N::translate('Individuals with sources');

        $chart_url = 'https://chart.googleapis.com/chart?cht=p3&chd=e:' . $chd
            . '&chs=' . $size . '&chco=' . $color_from . ',' . $color_to . '&chf=bg,s,ffffff00&chl=' . $chl;

        return view(
            'statistics/other/chart-individuals-with-sources',
            [
                'chart_title' => $chart_title,
                'chart_url'   => $chart_url,
                'sizes'       => $sizes,
            ]
        );
    }

    /**
     * Show the total individuals as a percentage.
     *
     * @return string
     */
    public function totalIndividualsPercentage(): string
    {
        return $this->getPercentage($this->individual->totalIndividualsQuery(), 'all');
    }

    /**
     * Count the total families.
     *
     * @return string
     */
    public function totalFamilies(): string
    {
        return I18N::number($this->family->totalFamiliesQuery());
    }

    /**
     * Count the families with source records.
     *
     * @return int
     */
    private function totalFamsWithSourcesQuery(): int
    {
        return (int) Database::prepare(
            "SELECT COUNT(DISTINCT f_id)" .
            " FROM `##families` JOIN `##link` ON f_id = l_from AND f_file = l_file" .
            " WHERE l_file = :tree_id AND l_type = 'SOUR'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();
    }

    /**
     * Count the families with with source records.
     *
     * @return string
     */
    public function totalFamsWithSources(): string
    {
        return I18N::number($this->totalFamsWithSourcesQuery());
    }

    /**
     * Create a chart of individuals with/without sources.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function chartFamsWithSources(string $size = null, string $color_from = null, string $color_to = null): string
    {
        $WT_STATS_CHART_COLOR1 = Theme::theme()->parameter('distribution-chart-no-values');
        $WT_STATS_CHART_COLOR2 = Theme::theme()->parameter('distribution-chart-high-values');
        $WT_STATS_S_CHART_X    = Theme::theme()->parameter('stats-small-chart-x');
        $WT_STATS_S_CHART_Y    = Theme::theme()->parameter('stats-small-chart-y');

        $size       = $size ?? ($WT_STATS_S_CHART_X . 'x' . $WT_STATS_S_CHART_Y);
        $color_from = $color_from ?? $WT_STATS_CHART_COLOR1;
        $color_to   = $color_to ?? $WT_STATS_CHART_COLOR2;

        $sizes   = explode('x', $size);
        $tot_fam = $this->family->totalFamiliesQuery();

        if ($tot_fam == 0) {
            return '';
        }

        $tot_sfam_per = $this->totalFamsWithSourcesQuery() / $tot_fam;
        $with          = (int) (100 * $tot_sfam_per);
        $chd           = $this->google->arrayToExtendedEncoding([100 - $with, $with]);
        $chl           = I18N::translate('Without sources') . ' - ' . I18N::percentage(1 - $tot_sfam_per, 1) . '|' . I18N::translate('With sources') . ' - ' . I18N::percentage($tot_sfam_per, 1);
        $chart_title   = I18N::translate('Families with sources');

        $chart_url = 'https://chart.googleapis.com/chart?cht=p3&chd=e:' . $chd
            . '&chs=' . $size . '&chco=' . $color_from . ',' . $color_to . '&chf=bg,s,ffffff00&chl=' . $chl;

        return view(
            'statistics/other/chart-families-with-sources',
            [
                'chart_title' => $chart_title,
                'chart_url'   => $chart_url,
                'sizes'       => $sizes,
            ]
        );
    }

    /**
     * Show the total families as a percentage.
     *
     * @return string
     */
    public function totalFamiliesPercentage(): string
    {
        return $this->getPercentage($this->family->totalFamiliesQuery(), 'all');
    }

    /**
     * Count the total number of sources.
     *
     * @return string
     */
    public function totalSources(): string
    {
        return I18N::number($this->source->totalSourcesQuery());
    }

    /**
     * Show the number of sources as a percentage.
     *
     * @return string
     */
    public function totalSourcesPercentage(): string
    {
        return $this->getPercentage($this->source->totalSourcesQuery(), 'all');
    }

    /**
     * Count the number of notes.
     *
     * @return string
     */
    public function totalNotes(): string
    {
        return I18N::number($this->note->totalNotesQuery());
    }

    /**
     * Show the number of notes as a percentage.
     *
     * @return string
     */
    public function totalNotesPercentage(): string
    {
        return $this->getPercentage($this->note->totalNotesQuery(), 'all');
    }

    /**
     * Count the number of repositories.
     *
     * @return int
     */
    private function totalRepositoriesQuery(): int
    {
        return (int) Database::prepare(
            "SELECT COUNT(*) FROM `##other` WHERE o_type='REPO' AND o_file = :tree_id"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();
    }

    /**
     * Count the number of repositories
     *
     * @return string
     */
    public function totalRepositories(): string
    {
        return I18N::number($this->totalRepositoriesQuery());
    }

    /**
     * Show the total number of repositories as a percentage.
     *
     * @return string
     */
    public function totalRepositoriesPercentage(): string
    {
        return $this->getPercentage($this->totalRepositoriesQuery(), 'all');
    }

    /**
     * Count the surnames.
     *
     * @param string ...$params
     *
     * @return string
     */
    public function totalSurnames(...$params): string
    {
        if ($params) {
            $opt      = 'IN (' . implode(',', array_fill(0, count($params), '?')) . ')';
            $distinct = '';
        } else {
            $opt      = "IS NOT NULL";
            $distinct = 'DISTINCT';
        }
        $params[] = $this->tree->id();

        $total = (int) Database::prepare(
            "SELECT COUNT({$distinct} n_surn COLLATE '" . I18N::collation() . "')" .
            " FROM `##name`" .
            " WHERE n_surn COLLATE '" . I18N::collation() . "' {$opt} AND n_file=?"
        )->execute(
            $params
        )->fetchOne();

        return I18N::number($total);
    }

    /**
     * Count the number of distinct given names, or count the number of
     * occurrences of a specific name or names.
     *
     * @param string ...$params
     *
     * @return string
     */
    public function totalGivennames(...$params): string
    {
        if ($params) {
            $qs       = implode(',', array_fill(0, count($params), '?'));
            $params[] = $this->tree->id();
            $total    = (int) Database::prepare(
                "SELECT COUNT( n_givn) FROM `##name` WHERE n_givn IN ({$qs}) AND n_file=?"
            )->execute(
                $params
            )->fetchOne();
        } else {
            $total = (int) Database::prepare(
                "SELECT COUNT(DISTINCT n_givn) FROM `##name` WHERE n_givn IS NOT NULL AND n_file=?"
            )->execute([
                $this->tree->id(),
            ])->fetchOne();
        }

        return I18N::number($total);
    }

    /**
     * Count the number of events (with dates).
     *
     * @param string[] $events
     *
     * @return string
     */
    public function totalEvents(array $events = []): string
    {
        $sql  = "SELECT COUNT(*) AS tot FROM `##dates` WHERE d_file=?";
        $vars = [$this->tree->id()];

        $no_types = [
            'HEAD',
            'CHAN',
        ];
        if ($events) {
            $types = [];
            foreach ($events as $type) {
                if (substr($type, 0, 1) === '!') {
                    $no_types[] = substr($type, 1);
                } else {
                    $types[] = $type;
                }
            }
            if ($types) {
                $sql .= ' AND d_fact IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
                $vars = array_merge($vars, $types);
            }
        }
        $sql .= ' AND d_fact NOT IN (' . implode(', ', array_fill(0, count($no_types), '?')) . ')';
        $vars = array_merge($vars, $no_types);

        $n = (int) Database::prepare($sql)->execute($vars)->fetchOne();

        return I18N::number($n);
    }

    /**
     * Count the number of births.
     *
     * @return string
     */
    public function totalEventsBirth(): string
    {
        return $this->totalEvents(Gedcom::BIRTH_EVENTS);
    }

    /**
     * Count the number of births.
     *
     * @return string
     */
    public function totalBirths(): string
    {
        return $this->totalEvents(['BIRT']);
    }

    /**
     * Count the number of deaths.
     *
     * @return string
     */
    public function totalEventsDeath(): string
    {
        return $this->totalEvents(Gedcom::DEATH_EVENTS);
    }

    /**
     * Count the number of deaths.
     *
     * @return string
     */
    public function totalDeaths(): string
    {
        return $this->totalEvents(['DEAT']);
    }

    /**
     * Count the number of marriages.
     *
     * @return string
     */
    public function totalEventsMarriage(): string
    {
        return $this->totalEvents(Gedcom::MARRIAGE_EVENTS);
    }

    /**
     * Count the number of marriages.
     *
     * @return string
     */
    public function totalMarriages(): string
    {
        return $this->totalEvents(['MARR']);
    }

    /**
     * Count the number of divorces.
     *
     * @return string
     */
    public function totalEventsDivorce(): string
    {
        return $this->totalEvents(Gedcom::DIVORCE_EVENTS);
    }

    /**
     * Count the number of divorces.
     *
     * @return string
     */
    public function totalDivorces(): string
    {
        return $this->totalEvents(['DIV']);
    }

    /**
     * Count the number of other events.
     *
     * @return string
     */
    public function totalEventsOther(): string
    {
        $facts    = array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS);
        $no_facts = [];
        foreach ($facts as $fact) {
            $fact       = '!' . str_replace('\'', '', $fact);
            $no_facts[] = $fact;
        }

        return $this->totalEvents($no_facts);
    }

    /**
     * Count the number of males.
     *
     * @return int
     */
    private function totalSexMalesQuery(): int
    {
        return (int) Database::prepare(
            "SELECT COUNT(*) FROM `##individuals` WHERE i_file = :tree_id AND i_sex = 'M'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();
    }

    /**
     * Count the number of males.
     *
     * @return string
     */
    public function totalSexMales(): string
    {
        return I18N::number($this->totalSexMalesQuery());
    }

    /**
     * Count the number of males
     *
     * @return string
     */
    public function totalSexMalesPercentage(): string
    {
        return $this->getPercentage($this->totalSexMalesQuery(), 'individual');
    }

    /**
     * Count the number of females.
     *
     * @return int
     */
    private function totalSexFemalesQuery(): int
    {
        return (int) Database::prepare(
            "SELECT COUNT(*) FROM `##individuals` WHERE i_file = :tree_id AND i_sex = 'F'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();
    }

    /**
     * Count the number of females.
     *
     * @return string
     */
    public function totalSexFemales(): string
    {
        return I18N::number($this->totalSexFemalesQuery());
    }

    /**
     * Count the number of females.
     *
     * @return string
     */
    public function totalSexFemalesPercentage(): string
    {
        return $this->getPercentage($this->totalSexFemalesQuery(), 'individual');
    }

    /**
     * Count the number of individuals with unknown sex.
     *
     * @return int
     */
    private function totalSexUnknownQuery(): int
    {
        return (int) Database::prepare(
            "SELECT COUNT(*) FROM `##individuals` WHERE i_file = :tree_id AND i_sex = 'U'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();
    }

    /**
     * Count the number of individuals with unknown sex.
     *
     * @return string
     */
    public function totalSexUnknown(): string
    {
        return I18N::number($this->totalSexUnknownQuery());
    }

    /**
     * Count the number of individuals with unknown sex.
     *
     * @return string
     */
    public function totalSexUnknownPercentage(): string
    {
        return $this->getPercentage($this->totalSexUnknownQuery(), 'individual');
    }

    /**
     * Generate a chart showing sex distribution.
     *
     * @param string|null $size
     * @param string|null $color_female
     * @param string|null $color_male
     * @param string|null $color_unknown
     *
     * @return string
     */
    public function chartSex(string $size = null, string $color_female = null, string $color_male = null, string $color_unknown = null): string
    {
        $WT_STATS_S_CHART_X = Theme::theme()->parameter('stats-small-chart-x');
        $WT_STATS_S_CHART_Y = Theme::theme()->parameter('stats-small-chart-y');

        $size          = $size ?? ($WT_STATS_S_CHART_X . 'x' . $WT_STATS_S_CHART_Y);
        $color_female  = $color_female ?? 'ffd1dc';
        $color_male    = $color_male ?? '84beff';
        $color_unknown = $color_unknown ?? '777777';

        $sizes = explode('x', $size);
        // Raw data - for calculation
        $tot_f = $this->totalSexFemalesQuery();
        $tot_m = $this->totalSexMalesQuery();
        $tot_u = $this->totalSexUnknownQuery();
        $tot   = $tot_f + $tot_m + $tot_u;
        // I18N data - for display
        $per_f = $this->totalSexFemalesPercentage();
        $per_m = $this->totalSexMalesPercentage();
        $per_u = $this->totalSexUnknownPercentage();
        if ($tot == 0) {
            return '';
        }

        if ($tot_u > 0) {
            $chd = $this->google->arrayToExtendedEncoding([
                intdiv(4095 * $tot_u, $tot),
                intdiv(4095 * $tot_f, $tot),
                intdiv(4095 * $tot_m, $tot),
            ]);
            $chl =
                I18N::translateContext('unknown people', 'Unknown') . ' - ' . $per_u . '|' .
                I18N::translate('Females') . ' - ' . $per_f . '|' .
                I18N::translate('Males') . ' - ' . $per_m;
            $chart_title =
                I18N::translate('Males') . ' - ' . $per_m . I18N::$list_separator .
                I18N::translate('Females') . ' - ' . $per_f . I18N::$list_separator .
                I18N::translateContext('unknown people', 'Unknown') . ' - ' . $per_u;

            return "<img src=\"https://chart.googleapis.com/chart?cht=p3&amp;chd=e:{$chd}&amp;chs={$size}&amp;chco={$color_unknown},{$color_female},{$color_male}&amp;chf=bg,s,ffffff00&amp;chl={$chl}\" width=\"{$sizes[0]}\" height=\"{$sizes[1]}\" alt=\"" . $chart_title . '" title="' . $chart_title . '" />';
        }

        $chd = $this->google->arrayToExtendedEncoding([
            intdiv(4095 * $tot_f, $tot),
            intdiv(4095 * $tot_m, $tot),
        ]);
        $chl         =
            I18N::translate('Females') . ' - ' . $per_f . '|' .
            I18N::translate('Males') . ' - ' . $per_m;
        $chart_title = I18N::translate('Males') . ' - ' . $per_m . I18N::$list_separator .
                   I18N::translate('Females') . ' - ' . $per_f;

        return "<img src=\"https://chart.googleapis.com/chart?cht=p3&amp;chd=e:{$chd}&amp;chs={$size}&amp;chco={$color_female},{$color_male}&amp;chf=bg,s,ffffff00&amp;chl={$chl}\" width=\"{$sizes[0]}\" height=\"{$sizes[1]}\" alt=\"" . $chart_title . '" title="' . $chart_title . '" />';
    }

    /**
     * Count the number of living individuals.
     *
     * @return string
     */
    public function totalLiving(): string
    {
        return I18N::number($this->living->totalLivingQuery());
    }

    /**
     * Count the number of living individuals.
     *
     * @return string
     */
    public function totalLivingPercentage(): string
    {
        return $this->living->totalLivingPercentage();
    }

    /**
     * Count the number of dead individuals.
     *
     * @return string
     */
    public function totalDeceased(): string
    {
        return I18N::number($this->deceased->totalDeceasedQuery());
    }

    /**
     * Count the number of dead individuals.
     *
     * @return string
     */
    public function totalDeceasedPercentage(): string
    {
        return $this->deceased->totalDeceasedPercentage();
    }

    /**
     * Create a chart showing mortality.
     *
     * @param string|null $size
     * @param string|null $color_living
     * @param string|null $color_dead
     *
     * @return string
     */
    public function chartMortality(string $size = null, string $color_living = null, string $color_dead = null): string
    {
        return (new Google\ChartMortality($this->tree))
            ->chartMortality($size, $color_living, $color_dead);
    }

    /**
     * Count the number of users.
     *
     * @return string
     */
    public function totalUsers(): string
    {
        $total = count(User::all());

        return I18N::number($total);
    }

    /**
     * Count the number of administrators.
     *
     * @return string
     */
    public function totalAdmins(): string
    {
        return I18N::number(count(User::administrators()));
    }

    /**
     * Count the number of administrators.
     *
     * @return string
     */
    public function totalNonAdmins(): string
    {
        return I18N::number(count(User::all()) - count(User::administrators()));
    }

    /**
     * Count the number of media records.
     *
     * @return string
     */
    public function totalMedia(): string
    {
        return I18N::number($this->media->totalMediaType('all'));
    }

    /**
     * Count the number of media records with type "audio".
     *
     * @return string
     */
    public function totalMediaAudio(): string
    {
        return I18N::number($this->media->totalMediaType('audio'));
    }

    /**
     * Count the number of media records with type "book".
     *
     * @return string
     */
    public function totalMediaBook(): string
    {
        return I18N::number($this->media->totalMediaType('book'));
    }

    /**
     * Count the number of media records with type "card".
     *
     * @return string
     */
    public function totalMediaCard(): string
    {
        return I18N::number($this->media->totalMediaType('card'));
    }

    /**
     * Count the number of media records with type "certificate".
     *
     * @return string
     */
    public function totalMediaCertificate(): string
    {
        return I18N::number($this->media->totalMediaType('certificate'));
    }

    /**
     * Count the number of media records with type "coat of arms".
     *
     * @return string
     */
    public function totalMediaCoatOfArms(): string
    {
        return I18N::number($this->media->totalMediaType('coat'));
    }

    /**
     * Count the number of media records with type "document".
     *
     * @return string
     */
    public function totalMediaDocument(): string
    {
        return I18N::number($this->media->totalMediaType('document'));
    }

    /**
     * Count the number of media records with type "electronic".
     *
     * @return string
     */
    public function totalMediaElectronic(): string
    {
        return I18N::number($this->media->totalMediaType('electronic'));
    }

    /**
     * Count the number of media records with type "magazine".
     *
     * @return string
     */
    public function totalMediaMagazine(): string
    {
        return I18N::number($this->media->totalMediaType('magazine'));
    }

    /**
     * Count the number of media records with type "manuscript".
     *
     * @return string
     */
    public function totalMediaManuscript(): string
    {
        return I18N::number($this->media->totalMediaType('manuscript'));
    }

    /**
     * Count the number of media records with type "map".
     *
     * @return string
     */
    public function totalMediaMap(): string
    {
        return I18N::number($this->media->totalMediaType('map'));
    }

    /**
     * Count the number of media records with type "microfiche".
     *
     * @return string
     */
    public function totalMediaFiche(): string
    {
        return I18N::number($this->media->totalMediaType('fiche'));
    }

    /**
     * Count the number of media records with type "microfilm".
     *
     * @return string
     */
    public function totalMediaFilm(): string
    {
        return I18N::number($this->media->totalMediaType('film'));
    }

    /**
     * Count the number of media records with type "newspaper".
     *
     * @return string
     */
    public function totalMediaNewspaper(): string
    {
        return I18N::number($this->media->totalMediaType('newspaper'));
    }

    /**
     * Count the number of media records with type "painting".
     *
     * @return string
     */
    public function totalMediaPainting(): string
    {
        return I18N::number($this->media->totalMediaType('painting'));
    }

    /**
     * Count the number of media records with type "photograph".
     *
     * @return string
     */
    public function totalMediaPhoto(): string
    {
        return I18N::number($this->media->totalMediaType('photo'));
    }

    /**
     * Count the number of media records with type "tombstone".
     *
     * @return string
     */
    public function totalMediaTombstone(): string
    {
        return I18N::number($this->media->totalMediaType('tombstone'));
    }

    /**
     * Count the number of media records with type "video".
     *
     * @return string
     */
    public function totalMediaVideo(): string
    {
        return I18N::number($this->media->totalMediaType('video'));
    }

    /**
     * Count the number of media records with type "other".
     *
     * @return string
     */
    public function totalMediaOther(): string
    {
        return I18N::number($this->media->totalMediaType('other'));
    }

    /**
     * Count the number of media records with type "unknown".
     *
     * @return string
     */
    public function totalMediaUnknown(): string
    {
        return I18N::number($this->media->totalMediaType('unknown'));
    }

    /**
     * Create a chart of media types.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function chartMedia(string $size = null, string $color_from = null, string $color_to = null): string
    {
        return (new Google\ChartMedia($this->tree))
            ->chartMedia($size, $color_from, $color_to);
    }

    /**
     * Birth and Death
     *
     * @param string $type
     * @param string $life_dir
     * @param string $birth_death
     *
     * @return string
     */
    private function mortalityQuery($type, $life_dir, $birth_death): string
    {
        if ($birth_death == 'MARR') {
            $query_field = "'MARR'";
        } elseif ($birth_death == 'DIV') {
            $query_field = "'DIV'";
        } elseif ($birth_death == 'BIRT') {
            $query_field = "'BIRT'";
        } else {
            $query_field = "'DEAT'";
        }
        if ($life_dir == 'ASC') {
            $dmod = 'MIN';
        } else {
            $dmod = 'MAX';
        }
        $rows = $this->runSql(
            "SELECT d_year, d_type, d_fact, d_gid" .
            " FROM `##dates`" .
            " WHERE d_file={$this->tree->id()} AND d_fact IN ({$query_field}) AND d_julianday1=(" .
            " SELECT {$dmod}( d_julianday1 )" .
            " FROM `##dates`" .
            " WHERE d_file={$this->tree->id()} AND d_fact IN ({$query_field}) AND d_julianday1<>0 )" .
            " LIMIT 1"
        );
        if (!isset($rows[0])) {
            return '';
        }
        $row    = $rows[0];
        $record = GedcomRecord::getInstance($row->d_gid, $this->tree);
        switch ($type) {
            default:
            case 'full':
                if ($record->canShow()) {
                    $result = $record->formatList();
                } else {
                    $result = I18N::translate('This information is private and cannot be shown.');
                }
                break;
            case 'year':
                if ($row->d_year < 0) {
                    $row->d_year = abs($row->d_year) . ' B.C.';
                }
                $date   = new Date($row->d_type . ' ' . $row->d_year);
                $result = $date->display();
                break;
            case 'name':
                $result = '<a href="' . e($record->url()) . '">' . $record->getFullName() . '</a>';
                break;
            case 'place':
                $fact = GedcomRecord::getInstance($row->d_gid, $this->tree)->getFirstFact($row->d_fact);
                if ($fact) {
                    $result = FunctionsPrint::formatFactPlace($fact, true, true, true);
                } else {
                    $result = I18N::translate('Private');
                }
                break;
        }

        return $result;
    }

    /**
     * Places
     *
     * @param string $what
     * @param string $fact
     * @param int    $parent
     * @param bool   $country
     *
     * @return int[]|stdClass[]
     *
     * @deprecated Use \Fisharebest\Webtrees\Statistics\Places::statsPlaces instead
     */
    public function statsPlaces($what = 'ALL', $fact = '', $parent = 0, $country = false): array
    {
        return (new Places($this->tree))->statsPlaces($what, $fact, $parent, $country);
    }

    /**
     * Count total places.
     *
     * @return int
     */
    private function totalPlacesQuery(): int
    {
        return
            (int) Database::prepare("SELECT COUNT(*) FROM `##places` WHERE p_file=?")
                ->execute([$this->tree->id()])
                ->fetchOne();
    }

    /**
     * Count total places.
     *
     * @return string
     */
    public function totalPlaces(): string
    {
        return I18N::number($this->totalPlacesQuery());
    }

    /**
     * Create a chart showing where events occurred.
     *
     * @param string $chart_shows
     * @param string $chart_type
     * @param string $surname
     *
     * @return string
     */
    public function chartDistribution(
        string $chart_shows = 'world',
        string $chart_type  = '',
        string $surname     = ''
    ) : string {
        return (new Google\ChartDistribution($this->tree))
            ->chartDistribution($chart_shows, $chart_type, $surname);
    }

    /**
     * A list of common countries.
     *
     * @return string
     */
    public function commonCountriesList(): string
    {
        $countries = $this->statsPlaces();

        if (empty($countries)) {
            return '';
        }

        $top10 = [];
        $i     = 1;

        // Get the country names for each language
        $country_names = [];
        foreach (I18N::activeLocales() as $locale) {
            I18N::init($locale->languageTag());
            $all_countries = $this->countryHelper->getAllCountries();
            foreach ($all_countries as $country_code => $country_name) {
                $country_names[$country_name] = $country_code;
            }
        }

        I18N::init(WT_LOCALE);

        $all_db_countries = [];
        foreach ($countries as $place) {
            $country = trim($place->country);
            if (array_key_exists($country, $country_names)) {
                if (!isset($all_db_countries[$country_names[$country]][$country])) {
                    $all_db_countries[$country_names[$country]][$country] = (int) $place->tot;
                } else {
                    $all_db_countries[$country_names[$country]][$country] += (int) $place->tot;
                }
            }
        }
        // get all the user’s countries names
        $all_countries = $this->countryHelper->getAllCountries();

        foreach ($all_db_countries as $country_code => $country) {
            foreach ($country as $country_name => $tot) {
                $tmp     = new Place($country_name, $this->tree);

                $top10[] = [
                    'place' => $tmp,
                    'count' => $tot,
                    'name'  => $all_countries[$country_code],
                ];
            }

            if ($i++ === 10) {
                break;
            }
        }

        return view(
            'statistics/other/top10-list',
            [
                'records' => $top10,
            ]
        );
    }

    /**
     * A list of common birth places.
     *
     * @return string
     */
    public function commonBirthPlacesList(): string
    {
        return (string) new BirthPlaces($this->tree);
    }

    /**
     * A list of common death places.
     *
     * @return string
     */
    public function commonDeathPlacesList(): string
    {
        return (string) new DeathPlaces($this->tree);
    }

    /**
     * A list of common marriage places.
     *
     * @return string
     */
    public function commonMarriagePlacesList(): string
    {
        return (string) new MarriagePlaces($this->tree);
    }

    /**
     * Find the earliest birth.
     *
     * @return string
     */
    public function firstBirth(): string
    {
        return $this->mortalityQuery('full', 'ASC', 'BIRT');
    }

    /**
     * Find the earliest birth year.
     *
     * @return string
     */
    public function firstBirthYear(): string
    {
        return $this->mortalityQuery('year', 'ASC', 'BIRT');
    }

    /**
     * Find the name of the earliest birth.
     *
     * @return string
     */
    public function firstBirthName(): string
    {
        return $this->mortalityQuery('name', 'ASC', 'BIRT');
    }

    /**
     * Find the earliest birth place.
     *
     * @return string
     */
    public function firstBirthPlace(): string
    {
        return $this->mortalityQuery('place', 'ASC', 'BIRT');
    }

    /**
     * Find the latest birth.
     *
     * @return string
     */
    public function lastBirth(): string
    {
        return $this->mortalityQuery('full', 'DESC', 'BIRT');
    }

    /**
     * Find the latest birth year.
     *
     * @return string
     */
    public function lastBirthYear(): string
    {
        return $this->mortalityQuery('year', 'DESC', 'BIRT');
    }

    /**
     * Find the latest birth name.
     *
     * @return string
     */
    public function lastBirthName(): string
    {
        return $this->mortalityQuery('name', 'DESC', 'BIRT');
    }

    /**
     * Find the latest birth place.
     *
     * @return string
     */
    public function lastBirthPlace(): string
    {
        return $this->mortalityQuery('place', 'DESC', 'BIRT');
    }

    /**
     * Create a chart of birth places.
     *
     * @param bool $simple
     * @param bool $sex
     * @param int  $year1
     * @param int  $year2
     *
     * @return array
     */
    public function statsBirthQuery($simple = true, $sex = false, $year1 = -1, $year2 = -1): array
    {
        return (new Birth($this->tree))->query($sex, $year1, $year2);
    }

    /**
     * General query on births.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function statsBirth(string $size = null, string $color_from = null, string $color_to = null): string
    {
        return (new Google\ChartBirth($this->tree))
            ->chartBirth($size, $color_from, $color_to);
    }

    /**
     * Find the earliest death.
     *
     * @return string
     */
    public function firstDeath(): string
    {
        return $this->mortalityQuery('full', 'ASC', 'DEAT');
    }

    /**
     * Find the earliest death year.
     *
     * @return string
     */
    public function firstDeathYear(): string
    {
        return $this->mortalityQuery('year', 'ASC', 'DEAT');
    }

    /**
     * Find the earliest death name.
     *
     * @return string
     */
    public function firstDeathName(): string
    {
        return $this->mortalityQuery('name', 'ASC', 'DEAT');
    }

    /**
     * Find the earliest death place.
     *
     * @return string
     */
    public function firstDeathPlace(): string
    {
        return $this->mortalityQuery('place', 'ASC', 'DEAT');
    }

    /**
     * Find the latest death.
     *
     * @return string
     */
    public function lastDeath(): string
    {
        return $this->mortalityQuery('full', 'DESC', 'DEAT');
    }

    /**
     * Find the latest death year.
     *
     * @return string
     */
    public function lastDeathYear(): string
    {
        return $this->mortalityQuery('year', 'DESC', 'DEAT');
    }

    /**
     * Find the latest death name.
     *
     * @return string
     */
    public function lastDeathName(): string
    {
        return $this->mortalityQuery('name', 'DESC', 'DEAT');
    }

    /**
     * Find the place of the latest death.
     *
     * @return string
     */
    public function lastDeathPlace(): string
    {
        return $this->mortalityQuery('place', 'DESC', 'DEAT');
    }

    /**
     * Create a chart of death places.
     *
     * @param bool $simple
     * @param bool $sex
     * @param int  $year1
     * @param int  $year2
     *
     * @return array
     */
    public function statsDeathQuery($simple = true, $sex = false, $year1 = -1, $year2 = -1): array
    {
        return (new Death($this->tree))->query($sex, $year1, $year2);
    }

    /**
     * General query on deaths.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function statsDeath(string $size = null, string $color_from = null, string $color_to = null): string
    {
        return (new Google\ChartDeath($this->tree))
            ->chartDeath($size, $color_from, $color_to);
    }

    /**
     * Lifespan
     *
     * @param string $type
     * @param string $sex
     *
     * @return string
     */
    private function longlifeQuery($type, $sex): string
    {
        $sex_search = ' 1=1';
        if ($sex == 'F') {
            $sex_search = " i_sex='F'";
        } elseif ($sex == 'M') {
            $sex_search = " i_sex='M'";
        }

        $rows = $this->runSql(
            " SELECT" .
            " death.d_gid AS id," .
            " death.d_julianday2-birth.d_julianday1 AS age" .
            " FROM" .
            " `##dates` AS death," .
            " `##dates` AS birth," .
            " `##individuals` AS indi" .
            " WHERE" .
            " indi.i_id=birth.d_gid AND" .
            " birth.d_gid=death.d_gid AND" .
            " death.d_file={$this->tree->id()} AND" .
            " birth.d_file=death.d_file AND" .
            " birth.d_file=indi.i_file AND" .
            " birth.d_fact='BIRT' AND" .
            " death.d_fact='DEAT' AND" .
            " birth.d_julianday1<>0 AND" .
            " death.d_julianday1>birth.d_julianday2 AND" .
            $sex_search .
            " ORDER BY" .
            " age DESC LIMIT 1"
        );
        if (!isset($rows[0])) {
            return '';
        }
        $row    = $rows[0];
        $person = Individual::getInstance($row->id, $this->tree);
        switch ($type) {
            default:
            case 'full':
                if ($person->canShowName()) {
                    $result = $person->formatList();
                } else {
                    $result = I18N::translate('This information is private and cannot be shown.');
                }
                break;
            case 'age':
                $result = I18N::number((int) ($row->age / 365.25));
                break;
            case 'name':
                $result = '<a href="' . e($person->url()) . '">' . $person->getFullName() . '</a>';
                break;
        }

        return $result;
    }

    /**
     * Find the oldest individuals.
     *
     * @param string $type
     * @param string $sex
     * @param int    $total
     *
     * @return array
     */
    private function topTenOldestQuery(string $type, string $sex, int $total): array
    {
        if ($sex === 'F') {
            $sex_search = " AND i_sex='F' ";
        } elseif ($sex === 'M') {
            $sex_search = " AND i_sex='M' ";
        } else {
            $sex_search = '';
        }

        $rows = $this->runSql(
            "SELECT " .
            " MAX(death.d_julianday2-birth.d_julianday1) AS age, " .
            " death.d_gid AS deathdate " .
            "FROM " .
            " `##dates` AS death, " .
            " `##dates` AS birth, " .
            " `##individuals` AS indi " .
            "WHERE " .
            " indi.i_id=birth.d_gid AND " .
            " birth.d_gid=death.d_gid AND " .
            " death.d_file={$this->tree->id()} AND " .
            " birth.d_file=death.d_file AND " .
            " birth.d_file=indi.i_file AND " .
            " birth.d_fact='BIRT' AND " .
            " death.d_fact='DEAT' AND " .
            " birth.d_julianday1<>0 AND " .
            " death.d_julianday1>birth.d_julianday2 " .
            $sex_search .
            "GROUP BY deathdate " .
            "ORDER BY age DESC " .
            "LIMIT " . $total
        );

        if (!isset($rows[0])) {
            return [];
        }

        $top10 = [];
        foreach ($rows as $row) {
            $person = Individual::getInstance($row->deathdate, $this->tree);
            $age    = $row->age;

            if ((int) ($age / 365.25) > 0) {
                $age = (int) ($age / 365.25) . 'y';
            } elseif ((int) ($age / 30.4375) > 0) {
                $age = (int) ($age / 30.4375) . 'm';
            } else {
                $age .= 'd';
            }

            if ($person->canShow()) {
                $top10[] = [
                    'person' => $person,
                    'age'    => FunctionsDate::getAgeAtEvent($age),
                ];
            }
        }

        // TODO
//        if (I18N::direction() === 'rtl') {
//            $top10 = str_replace([
//                '[',
//                ']',
//                '(',
//                ')',
//                '+',
//            ], [
//                '&rlm;[',
//                '&rlm;]',
//                '&rlm;(',
//                '&rlm;)',
//                '&rlm;+',
//            ], $top10);
//        }

        return $top10;
    }

    /**
     * Find the oldest living individuals.
     *
     * @param string $sex
     * @param int    $total
     *
     * @return array
     */
    private function topTenOldestAliveQuery(string $sex = 'BOTH', int $total = 10): array
    {
        $total = (int) $total;

        // TODO
//        if (!Auth::isMember($this->tree)) {
//            return I18N::translate('This information is private and cannot be shown.');
//        }

        if ($sex === 'F') {
            $sex_search = " AND i_sex='F'";
        } elseif ($sex === 'M') {
            $sex_search = " AND i_sex='M'";
        } else {
            $sex_search = '';
        }

        $rows = $this->runSql(
            "SELECT" .
            " birth.d_gid AS id," .
            " MIN(birth.d_julianday1) AS age" .
            " FROM" .
            " `##dates` AS birth," .
            " `##individuals` AS indi" .
            " WHERE" .
            " indi.i_id=birth.d_gid AND" .
            " indi.i_gedcom NOT REGEXP '\\n1 (" . implode('|', Gedcom::DEATH_EVENTS) . ")' AND" .
            " birth.d_file={$this->tree->id()} AND" .
            " birth.d_fact='BIRT' AND" .
            " birth.d_file=indi.i_file AND" .
            " birth.d_julianday1<>0" .
            $sex_search .
            " GROUP BY id" .
            " ORDER BY age" .
            " ASC LIMIT " . $total
        );

        $top10 = [];

        foreach ($rows as $row) {
            $person = Individual::getInstance($row->id, $this->tree);
            $age    = (WT_CLIENT_JD - $row->age);

            if ((int) ($age / 365.25) > 0) {
                $age = (int) ($age / 365.25) . 'y';
            } elseif ((int) ($age / 30.4375) > 0) {
                $age = (int) ($age / 30.4375) . 'm';
            } else {
                $age .= 'd';
            }

            $top10[] = [
                'person' => $person,
                'age'    => FunctionsDate::getAgeAtEvent($age),
            ];
        }

        // TODO
//        if (I18N::direction() === 'rtl') {
//            $top10 = str_replace([
//                '[',
//                ']',
//                '(',
//                ')',
//                '+',
//            ], [
//                '&rlm;[',
//                '&rlm;]',
//                '&rlm;(',
//                '&rlm;)',
//                '&rlm;+',
//            ], $top10);
//        }

        return $top10;
    }

    /**
     * Find the average lifespan.
     *
     * @param string $sex
     * @param bool   $show_years
     *
     * @return string
     */
    private function averageLifespanQuery($sex = 'BOTH', $show_years = false): string
    {
        if ($sex === 'F') {
            $sex_search = " AND i_sex='F' ";
        } elseif ($sex === 'M') {
            $sex_search = " AND i_sex='M' ";
        } else {
            $sex_search = '';
        }
        $rows = $this->runSql(
            "SELECT IFNULL(AVG(death.d_julianday2-birth.d_julianday1), 0) AS age" .
            " FROM `##dates` AS death, `##dates` AS birth, `##individuals` AS indi" .
            " WHERE " .
            " indi.i_id=birth.d_gid AND " .
            " birth.d_gid=death.d_gid AND " .
            " death.d_file=" . $this->tree->id() . " AND " .
            " birth.d_file=death.d_file AND " .
            " birth.d_file=indi.i_file AND " .
            " birth.d_fact='BIRT' AND " .
            " death.d_fact='DEAT' AND " .
            " birth.d_julianday1<>0 AND " .
            " death.d_julianday1>birth.d_julianday2 " .
            $sex_search
        );

        $age = $rows[0]->age;
        if ($show_years) {
            if ((int) ($age / 365.25) > 0) {
                $age = (int) ($age / 365.25) . 'y';
            } elseif ((int) ($age / 30.4375) > 0) {
                $age = (int) ($age / 30.4375) . 'm';
            } elseif (!empty($age)) {
                $age .= 'd';
            }

            return FunctionsDate::getAgeAtEvent($age);
        }

        return I18N::number($age / 365.25);
    }

    /**
     * General query on ages.
     *
     * @param bool   $simple
     * @param string $related
     * @param string $sex
     * @param int    $year1
     * @param int    $year2
     *
     * @return array|string
     */
    public function statsAgeQuery($simple = true, $related = 'BIRT', $sex = 'BOTH', $year1 = -1, $year2 = -1)
    {
        return (new StatisticAge($this->tree))->query($related, $sex, $year1, $year2);
    }

    /**
     * General query on ages.
     *
     * @param string $size
     *
     * @return string
     */
    public function statsAge(string $size = '230x250'): string
    {
        return (new Google\ChartAge($this->tree))
            ->chartAge($size);
    }

    /**
     * Find the lognest lived individual.
     *
     * @return string
     */
    public function longestLife(): string
    {
        return $this->longlifeQuery('full', 'BOTH');
    }

    /**
     * Find the age of the longest lived individual.
     *
     * @return string
     */
    public function longestLifeAge(): string
    {
        return $this->longlifeQuery('age', 'BOTH');
    }

    /**
     * Find the name of the longest lived individual.
     *
     * @return string
     */
    public function longestLifeName(): string
    {
        return $this->longlifeQuery('name', 'BOTH');
    }

    /**
     * Find the oldest individuals.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenOldest(string $total = '10'): string
    {
        $records = $this->topTenOldestQuery('nolist', 'BOTH', (int) $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living individuals.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenOldestList(string $total = '10'): string
    {
        $records = $this->topTenOldestQuery('list', 'BOTH', (int) $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living individuals.
     *
     * @param string|null $total
     *
     * @return string
     */
    public function topTenOldestAlive(string $total = '10'): string
    {
        $records = $this->topTenOldestAliveQuery('BOTH', (int) $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living individuals.
     *
     * @param string|null $total
     *
     * @return string
     */
    public function topTenOldestListAlive(string $total = '10'): string
    {
        $records = $this->topTenOldestAliveQuery('BOTH', (int) $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the average lifespan.
     *
     * @param bool $show_years
     *
     * @return string
     */
    public function averageLifespan($show_years = false): string
    {
        return $this->averageLifespanQuery('BOTH', $show_years);
    }

    /**
     * Find the longest lived female.
     *
     * @return string
     */
    public function longestLifeFemale(): string
    {
        return $this->longlifeQuery('full', 'F');
    }

    /**
     * Find the age of the longest lived female.
     *
     * @return string
     */
    public function longestLifeFemaleAge(): string
    {
        return $this->longlifeQuery('age', 'F');
    }

    /**
     * Find the name of the longest lived female.
     *
     * @return string
     */
    public function longestLifeFemaleName(): string
    {
        return $this->longlifeQuery('name', 'F');
    }

    /**
     * Find the oldest females.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenOldestFemale(string $total = '10'): string
    {
        $records = $this->topTenOldestQuery('nolist', 'F', (int) $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living females.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenOldestFemaleList(string $total = '10'): string
    {
        $records = $this->topTenOldestQuery('list', 'F', (int) $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living females.
     *
     * @param string|null $total
     *
     * @return string
     */
    public function topTenOldestFemaleAlive(string $total = '10'): string
    {
        $records = $this->topTenOldestAliveQuery('F', (int) $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the oldest living females.
     *
     * @param string|null $total
     *
     * @return string
     */
    public function topTenOldestFemaleListAlive(string $total = '10'): string
    {
        $records = $this->topTenOldestAliveQuery('F', (int) $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the average lifespan of females.
     *
     * @param bool $show_years
     *
     * @return string
     */
    public function averageLifespanFemale($show_years = false): string
    {
        return $this->averageLifespanQuery('F', $show_years);
    }

    /**
     * Find the longest lived male.
     *
     * @return string
     */
    public function longestLifeMale(): string
    {
        return $this->longlifeQuery('full', 'M');
    }

    /**
     * Find the age of the longest lived male.
     *
     * @return string
     */
    public function longestLifeMaleAge(): string
    {
        return $this->longlifeQuery('age', 'M');
    }

    /**
     * Find the name of the longest lived male.
     *
     * @return string
     */
    public function longestLifeMaleName(): string
    {
        return $this->longlifeQuery('name', 'M');
    }

    /**
     * Find the longest lived males.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenOldestMale(string $total = '10'): string
    {
        $records = $this->topTenOldestQuery('nolist', 'M', (int) $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived males.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenOldestMaleList(string $total = '10'): string
    {
        $records = $this->topTenOldestQuery('list', 'M', (int) $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived living males.
     *
     * @param string|null $total
     *
     * @return string
     */
    public function topTenOldestMaleAlive(string $total = '10'): string
    {
        $records = $this->topTenOldestAliveQuery('M', (int) $total);

        return view(
            'statistics/individuals/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the longest lived living males.
     *
     * @param string|null $total
     *
     * @return string
     */
    public function topTenOldestMaleListAlive(string $total = '10'): string
    {
        $records = $this->topTenOldestAliveQuery('M', (int) $total);

        return view(
            'statistics/individuals/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the average male lifespan.
     *
     * @param bool $show_years
     *
     * @return string
     */
    public function averageLifespanMale($show_years = false): string
    {
        return $this->averageLifespanQuery('M', $show_years);
    }

    /**
     * Events
     *
     * @param string   $type
     * @param string   $direction
     * @param string[] $facts
     *
     * @return string
     */
    private function eventQuery(string $type, string $direction, array $facts): string
    {
        $eventTypes = [
            'BIRT' => I18N::translate('birth'),
            'DEAT' => I18N::translate('death'),
            'MARR' => I18N::translate('marriage'),
            'ADOP' => I18N::translate('adoption'),
            'BURI' => I18N::translate('burial'),
            'CENS' => I18N::translate('census added'),
        ];

        $fact_query = "IN ('" . implode("','", $facts) . "')";

        if ($direction !== 'ASC') {
            $direction = 'DESC';
        }
        $rows = $this->runSql(
            ' SELECT' .
            ' d_gid AS id,' .
            ' d_year AS year,' .
            ' d_fact AS fact,' .
            ' d_type AS type' .
            ' FROM' .
            " `##dates`" .
            ' WHERE' .
            " d_file={$this->tree->id()} AND" .
            " d_gid<>'HEAD' AND" .
            " d_fact {$fact_query} AND" .
            ' d_julianday1<>0' .
            ' ORDER BY' .
            " d_julianday1 {$direction}, d_type LIMIT 1"
        );

        if (!isset($rows[0])) {
            return '';
        }
        $row    = $rows[0];
        $record = GedcomRecord::getInstance($row->id, $this->tree);
        switch ($type) {
            default:
            case 'full':
                if ($record && $record->canShow()) {
                    $result = $record->formatList();
                } else {
                    $result = I18N::translate('This information is private and cannot be shown.');
                }
                break;
            case 'year':
                $date   = new Date($row->type . ' ' . $row->year);
                $result = $date->display();
                break;
            case 'type':
                if (isset($eventTypes[$row->fact])) {
                    $result = $eventTypes[$row->fact];
                } else {
                    $result = GedcomTag::getLabel($row->fact);
                }
                break;
            case 'name':
                $result = '<a href="' . e($record->url()) . '">' . $record->getFullName() . '</a>';
                break;
            case 'place':
                $fact = $record->getFirstFact($row->fact);
                if ($fact) {
                    $result = FunctionsPrint::formatFactPlace($fact, true, true, true);
                } else {
                    $result = I18N::translate('Private');
                }
                break;
        }

        return $result;
    }

    /**
     * Find the earliest event.
     *
     * @return string
     */
    public function firstEvent(): string
    {
        return $this->eventQuery('full', 'ASC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the year of the earliest event.
     *
     * @return string
     */
    public function firstEventYear(): string
    {
        return $this->eventQuery('year', 'ASC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the type of the earliest event.
     *
     * @return string
     */
    public function firstEventType(): string
    {
        return $this->eventQuery('type', 'ASC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the name of the individual with the earliest event.
     *
     * @return string
     */
    public function firstEventName(): string
    {
        return $this->eventQuery('name', 'ASC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the location of the earliest event.
     *
     * @return string
     */
    public function firstEventPlace(): string
    {
        return $this->eventQuery('place', 'ASC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the latest event.
     *
     * @return string
     */
    public function lastEvent(): string
    {
        return $this->eventQuery('full', 'DESC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the year of the latest event.
     *
     * @return string
     */
    public function lastEventYear(): string
    {
        return $this->eventQuery('year', 'DESC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the type of the latest event.
     *
     * @return string
     */
    public function lastEventType(): string
    {
        return $this->eventQuery('type', 'DESC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Find the name of the individual with the latest event.
     *
     * @return string
     */
    public function lastEventName(): string
    {
        return $this->eventQuery('name', 'DESC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * FInd the location of the latest event.
     *
     * @return string
     */
    public function lastEventPlace(): string
    {
        return $this->eventQuery('place', 'DESC', array_merge(Gedcom::BIRTH_EVENTS, Gedcom::MARRIAGE_EVENTS, Gedcom::DIVORCE_EVENTS, Gedcom::DEATH_EVENTS));
    }

    /**
     * Query the database for marriage tags.
     *
     * @param string $type
     * @param string $age_dir
     * @param string $sex
     * @param bool   $show_years
     *
     * @return string
     */
    private function marriageQuery(string $type, string $age_dir, string $sex, bool $show_years): string
    {
        if ($sex === 'F') {
            $sex_field = 'f_wife';
        } else {
            $sex_field = 'f_husb';
        }
        if ($age_dir !== 'ASC') {
            $age_dir = 'DESC';
        }
        $rows = $this->runSql(
            " SELECT fam.f_id AS famid, fam.{$sex_field}, married.d_julianday2-birth.d_julianday1 AS age, indi.i_id AS i_id" .
            " FROM `##families` AS fam" .
            " LEFT JOIN `##dates` AS birth ON birth.d_file = {$this->tree->id()}" .
            " LEFT JOIN `##dates` AS married ON married.d_file = {$this->tree->id()}" .
            " LEFT JOIN `##individuals` AS indi ON indi.i_file = {$this->tree->id()}" .
            " WHERE" .
            " birth.d_gid = indi.i_id AND" .
            " married.d_gid = fam.f_id AND" .
            " indi.i_id = fam.{$sex_field} AND" .
            " fam.f_file = {$this->tree->id()} AND" .
            " birth.d_fact = 'BIRT' AND" .
            " married.d_fact = 'MARR' AND" .
            " birth.d_julianday1 <> 0 AND" .
            " married.d_julianday2 > birth.d_julianday1 AND" .
            " i_sex='{$sex}'" .
            " ORDER BY" .
            " married.d_julianday2-birth.d_julianday1 {$age_dir} LIMIT 1"
        );
        if (!isset($rows[0])) {
            return '';
        }
        $row = $rows[0];
        if (isset($row->famid)) {
            $family = Family::getInstance($row->famid, $this->tree);
        }
        if (isset($row->i_id)) {
            $person = Individual::getInstance($row->i_id, $this->tree);
        }
        switch ($type) {
            default:
            case 'full':
                if ($family && $family->canShow()) {
                    $result = $family->formatList();
                } else {
                    $result = I18N::translate('This information is private and cannot be shown.');
                }
                break;
            case 'name':
                $result = '<a href="' . e($family->url()) . '">' . $person->getFullName() . '</a>';
                break;
            case 'age':
                $age = $row->age;
                if ($show_years) {
                    if ((int) ($age / 365.25) > 0) {
                        $age = (int) ($age / 365.25) . 'y';
                    } elseif ((int) ($age / 30.4375) > 0) {
                        $age = (int) ($age / 30.4375) . 'm';
                    } else {
                        $age .= 'd';
                    }
                    $result = FunctionsDate::getAgeAtEvent($age);
                } else {
                    $result = I18N::number((int) ($age / 365.25));
                }
                break;
        }

        return $result;
    }

    /**
     * General query on age at marriage.
     *
     * @param string $type
     * @param string $age_dir
     * @param int    $total
     *
     * @return string
     */
    private function ageOfMarriageQuery(string $type, string $age_dir, int $total): string
    {
        if ($age_dir !== 'ASC') {
            $age_dir = 'DESC';
        }
        $hrows = $this->runSql(
            " SELECT DISTINCT fam.f_id AS family, MIN(husbdeath.d_julianday2-married.d_julianday1) AS age" .
            " FROM `##families` AS fam" .
            " LEFT JOIN `##dates` AS married ON married.d_file = {$this->tree->id()}" .
            " LEFT JOIN `##dates` AS husbdeath ON husbdeath.d_file = {$this->tree->id()}" .
            " WHERE" .
            " fam.f_file = {$this->tree->id()} AND" .
            " husbdeath.d_gid = fam.f_husb AND" .
            " husbdeath.d_fact = 'DEAT' AND" .
            " married.d_gid = fam.f_id AND" .
            " married.d_fact = 'MARR' AND" .
            " married.d_julianday1 < husbdeath.d_julianday2 AND" .
            " married.d_julianday1 <> 0" .
            " GROUP BY family" .
            " ORDER BY age {$age_dir}"
        );
        $wrows = $this->runSql(
            " SELECT DISTINCT fam.f_id AS family, MIN(wifedeath.d_julianday2-married.d_julianday1) AS age" .
            " FROM `##families` AS fam" .
            " LEFT JOIN `##dates` AS married ON married.d_file = {$this->tree->id()}" .
            " LEFT JOIN `##dates` AS wifedeath ON wifedeath.d_file = {$this->tree->id()}" .
            " WHERE" .
            " fam.f_file = {$this->tree->id()} AND" .
            " wifedeath.d_gid = fam.f_wife AND" .
            " wifedeath.d_fact = 'DEAT' AND" .
            " married.d_gid = fam.f_id AND" .
            " married.d_fact = 'MARR' AND" .
            " married.d_julianday1 < wifedeath.d_julianday2 AND" .
            " married.d_julianday1 <> 0" .
            " GROUP BY family" .
            " ORDER BY age {$age_dir}"
        );
        $drows = $this->runSql(
            " SELECT DISTINCT fam.f_id AS family, MIN(divorced.d_julianday2-married.d_julianday1) AS age" .
            " FROM `##families` AS fam" .
            " LEFT JOIN `##dates` AS married ON married.d_file = {$this->tree->id()}" .
            " LEFT JOIN `##dates` AS divorced ON divorced.d_file = {$this->tree->id()}" .
            " WHERE" .
            " fam.f_file = {$this->tree->id()} AND" .
            " married.d_gid = fam.f_id AND" .
            " married.d_fact = 'MARR' AND" .
            " divorced.d_gid = fam.f_id AND" .
            " divorced.d_fact IN ('DIV', 'ANUL', '_SEPR', '_DETS') AND" .
            " married.d_julianday1 < divorced.d_julianday2 AND" .
            " married.d_julianday1 <> 0" .
            " GROUP BY family" .
            " ORDER BY age {$age_dir}"
        );
        $rows = [];
        foreach ($drows as $family) {
            $rows[$family->family] = $family->age;
        }
        foreach ($hrows as $family) {
            if (!isset($rows[$family->family])) {
                $rows[$family->family] = $family->age;
            }
        }
        foreach ($wrows as $family) {
            if (!isset($rows[$family->family])) {
                $rows[$family->family] = $family->age;
            } elseif ($rows[$family->family] > $family->age) {
                $rows[$family->family] = $family->age;
            }
        }
        if ($age_dir === 'DESC') {
            arsort($rows);
        } else {
            asort($rows);
        }
        $top10 = [];
        $i     = 0;
        foreach ($rows as $fam => $age) {
            $family = Family::getInstance($fam, $this->tree);
            if ($type === 'name') {
                return $family->formatList();
            }
            if ((int) ($age / 365.25) > 0) {
                $age = (int) ($age / 365.25) . 'y';
            } elseif ((int) ($age / 30.4375) > 0) {
                $age = (int) ($age / 30.4375) . 'm';
            } else {
                $age = $age . 'd';
            }
            $age = FunctionsDate::getAgeAtEvent($age);
            if ($type === 'age') {
                return $age;
            }
            $husb = $family->getHusband();
            $wife = $family->getWife();
            if ($husb && $wife && ($husb->getAllDeathDates() && $wife->getAllDeathDates() || !$husb->isDead() || !$wife->isDead())) {
                if ($family && $family->canShow()) {
                    if ($type === 'list') {
                        $top10[] = '<li><a href="' . e($family->url()) . '">' . $family->getFullName() . '</a> (' . $age . ')' . '</li>';
                    } else {
                        $top10[] = '<a href="' . e($family->url()) . '">' . $family->getFullName() . '</a> (' . $age . ')';
                    }
                }
                if (++$i === $total) {
                    break;
                }
            }
        }
        if ($type === 'list') {
            $top10 = implode('', $top10);
        } else {
            $top10 = implode('; ', $top10);
        }
        if (I18N::direction() === 'rtl') {
            $top10 = str_replace([
                '[',
                ']',
                '(',
                ')',
                '+',
            ], [
                '&rlm;[',
                '&rlm;]',
                '&rlm;(',
                '&rlm;)',
                '&rlm;+',
            ], $top10);
        }
        if ($type === 'list') {
            return '<ul>' . $top10 . '</ul>';
        }

        return $top10;
    }

    /**
     * Find the ages between spouses.
     *
     * @param string $type
     * @param string $age_dir
     * @param int    $total
     *
     * @return array
     */
    private function ageBetweenSpousesQuery(string $type, string $age_dir, int $total): array
    {
        $ageDiff = new AgeDifferenceSpouse($this->tree);
        return $ageDiff->query($type, $age_dir, $total);
    }

    /**
     * General query on parents.
     *
     * @param string $type
     * @param string $age_dir
     * @param string $sex
     * @param bool   $show_years
     *
     * @return string
     */
    private function parentsQuery(string $type, string $age_dir, string $sex, bool $show_years): string
    {
        if ($sex === 'F') {
            $sex_field = 'WIFE';
        } else {
            $sex_field = 'HUSB';
        }
        if ($age_dir !== 'ASC') {
            $age_dir = 'DESC';
        }
        $rows = $this->runSql(
            " SELECT" .
            " parentfamily.l_to AS id," .
            " childbirth.d_julianday2-birth.d_julianday1 AS age" .
            " FROM `##link` AS parentfamily" .
            " JOIN `##link` AS childfamily ON childfamily.l_file = {$this->tree->id()}" .
            " JOIN `##dates` AS birth ON birth.d_file = {$this->tree->id()}" .
            " JOIN `##dates` AS childbirth ON childbirth.d_file = {$this->tree->id()}" .
            " WHERE" .
            " birth.d_gid = parentfamily.l_to AND" .
            " childfamily.l_to = childbirth.d_gid AND" .
            " childfamily.l_type = 'CHIL' AND" .
            " parentfamily.l_type = '{$sex_field}' AND" .
            " childfamily.l_from = parentfamily.l_from AND" .
            " parentfamily.l_file = {$this->tree->id()} AND" .
            " birth.d_fact = 'BIRT' AND" .
            " childbirth.d_fact = 'BIRT' AND" .
            " birth.d_julianday1 <> 0 AND" .
            " childbirth.d_julianday2 > birth.d_julianday1" .
            " ORDER BY age {$age_dir} LIMIT 1"
        );
        if (!isset($rows[0])) {
            return '';
        }
        $row = $rows[0];
        if (isset($row->id)) {
            $person = Individual::getInstance($row->id, $this->tree);
        }
        switch ($type) {
            default:
            case 'full':
                if ($person && $person->canShow()) {
                    $result = $person->formatList();
                } else {
                    $result = I18N::translate('This information is private and cannot be shown.');
                }
                break;
            case 'name':
                $result = '<a href="' . e($person->url()) . '">' . $person->getFullName() . '</a>';
                break;
            case 'age':
                $age = $row->age;
                if ($show_years) {
                    if ((int) ($age / 365.25) > 0) {
                        $age = (int) ($age / 365.25) . 'y';
                    } elseif ((int) ($age / 30.4375) > 0) {
                        $age = (int) ($age / 30.4375) . 'm';
                    } else {
                        $age .= 'd';
                    }
                    $result = FunctionsDate::getAgeAtEvent($age);
                } else {
                    $result = (string) floor($age / 365.25);
                }
                break;
        }

        return $result;
    }

    /**
     * Find the earliest marriage.
     *
     * @return string
     */
    public function firstMarriage(): string
    {
        return $this->mortalityQuery('full', 'ASC', 'MARR');
    }

    /**
     * Find the year of the earliest marriage.
     *
     * @return string
     */
    public function firstMarriageYear(): string
    {
        return $this->mortalityQuery('year', 'ASC', 'MARR');
    }

    /**
     * Find the names of spouses of the earliest marriage.
     *
     * @return string
     */
    public function firstMarriageName(): string
    {
        return $this->mortalityQuery('name', 'ASC', 'MARR');
    }

    /**
     * Find the place of the earliest marriage.
     *
     * @return string
     */
    public function firstMarriagePlace(): string
    {
        return $this->mortalityQuery('place', 'ASC', 'MARR');
    }

    /**
     * Find the latest marriage.
     *
     * @return string
     */
    public function lastMarriage(): string
    {
        return $this->mortalityQuery('full', 'DESC', 'MARR');
    }

    /**
     * Find the year of the latest marriage.
     *
     * @return string
     */
    public function lastMarriageYear(): string
    {
        return $this->mortalityQuery('year', 'DESC', 'MARR');
    }

    /**
     * Find the names of spouses of the latest marriage.
     *
     * @return string
     */
    public function lastMarriageName(): string
    {
        return $this->mortalityQuery('name', 'DESC', 'MARR');
    }

    /**
     * Find the location of the latest marriage.
     *
     * @return string
     */
    public function lastMarriagePlace(): string
    {
        return $this->mortalityQuery('place', 'DESC', 'MARR');
    }

    /**
     * General query on marriages.
     *
     * @param bool $simple
     * @param bool $first
     * @param int  $year1
     * @param int  $year2
     *
     * @return array
     */
    public function statsMarrQuery($simple = true, $first = false, $year1 = -1, $year2 = -1): array
    {
        return (new Marriage($this->tree))->query($first, $year1, $year2);
    }

    /**
     * General query on marriages.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function statsMarr(string $size = null, string $color_from = null, string $color_to = null): string
    {
        return (new Google\ChartMarriage($this->tree))
            ->chartMarriage($size, $color_from, $color_to);
    }

    /**
     * Find the earliest divorce.
     *
     * @return string
     */
    public function firstDivorce(): string
    {
        return $this->mortalityQuery('full', 'ASC', 'DIV');
    }

    /**
     * Find the year of the earliest divorce.
     *
     * @return string
     */
    public function firstDivorceYear(): string
    {
        return $this->mortalityQuery('year', 'ASC', 'DIV');
    }

    /**
     * Find the names of individuals in the earliest divorce.
     *
     * @return string
     */
    public function firstDivorceName(): string
    {
        return $this->mortalityQuery('name', 'ASC', 'DIV');
    }

    /**
     * Find the location of the earliest divorce.
     *
     * @return string
     */
    public function firstDivorcePlace(): string
    {
        return $this->mortalityQuery('place', 'ASC', 'DIV');
    }

    /**
     * Find the latest divorce.
     *
     * @return string
     */
    public function lastDivorce(): string
    {
        return $this->mortalityQuery('full', 'DESC', 'DIV');
    }

    /**
     * Find the year of the latest divorce.
     *
     * @return string
     */
    public function lastDivorceYear(): string
    {
        return $this->mortalityQuery('year', 'DESC', 'DIV');
    }

    /**
     * Find the names of the individuals in the latest divorce.
     *
     * @return string
     */
    public function lastDivorceName(): string
    {
        return $this->mortalityQuery('name', 'DESC', 'DIV');
    }

    /**
     * Find the location of the latest divorce.
     *
     * @return string
     */
    public function lastDivorcePlace(): string
    {
        return $this->mortalityQuery('place', 'DESC', 'DIV');
    }

    /**
     * General divorce query.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     *
     * @return string
     */
    public function statsDiv(string $size = null, string $color_from = null, string $color_to = null): string
    {
        return (new Google\ChartDivorce($this->tree))
            ->chartDivorce($size, $color_from, $color_to);
    }

    /**
     * Find the youngest wife.
     *
     * @return string
     */
    public function youngestMarriageFemale(): string
    {
        return $this->marriageQuery('full', 'ASC', 'F', false);
    }

    /**
     * Find the name of the youngest wife.
     *
     * @return string
     */
    public function youngestMarriageFemaleName(): string
    {
        return $this->marriageQuery('name', 'ASC', 'F', false);
    }

    /**
     * Find the age of the youngest wife.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function youngestMarriageFemaleAge(string $show_years = ''): string
    {
        return $this->marriageQuery('age', 'ASC', 'F', (bool) $show_years);
    }

    /**
     * Find the oldest wife.
     *
     * @return string
     */
    public function oldestMarriageFemale(): string
    {
        return $this->marriageQuery('full', 'DESC', 'F', false);
    }

    /**
     * Find the name of the oldest wife.
     *
     * @return string
     */
    public function oldestMarriageFemaleName(): string
    {
        return $this->marriageQuery('name', 'DESC', 'F', false);
    }

    /**
     * Find the age of the oldest wife.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function oldestMarriageFemaleAge(string $show_years = ''): string
    {
        return $this->marriageQuery('age', 'DESC', 'F', (bool) $show_years);
    }

    /**
     * Find the youngest husband.
     *
     * @return string
     */
    public function youngestMarriageMale(): string
    {
        return $this->marriageQuery('full', 'ASC', 'M', false);
    }

    /**
     * Find the name of the youngest husband.
     *
     * @return string
     */
    public function youngestMarriageMaleName(): string
    {
        return $this->marriageQuery('name', 'ASC', 'M', false);
    }

    /**
     * Find the age of the youngest husband.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function youngestMarriageMaleAge(string $show_years = ''): string
    {
        return $this->marriageQuery('age', 'ASC', 'M', (bool) $show_years);
    }

    /**
     * Find the oldest husband.
     *
     * @return string
     */
    public function oldestMarriageMale(): string
    {
        return $this->marriageQuery('full', 'DESC', 'M', false);
    }

    /**
     * Find the name of the oldest husband.
     *
     * @return string
     */
    public function oldestMarriageMaleName(): string
    {
        return $this->marriageQuery('name', 'DESC', 'M', false);
    }

    /**
     * Find the age of the oldest husband.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function oldestMarriageMaleAge(string $show_years = ''): string
    {
        return $this->marriageQuery('age', 'DESC', 'M', (bool) $show_years);
    }

    /**
     * General query on ages at marriage.
     *
     * @param bool   $simple
     * @param string $sex
     * @param int    $year1
     * @param int    $year2
     *
     * @return array
     */
    public function statsMarrAgeQuery($simple = true, $sex = 'M', $year1 = -1, $year2 = -1): array
    {
        return (new MarriageAge($this->tree))->query($sex, $year1, $year2);
    }

    /**
     * General query on marriage ages.
     *
     * @param string $size
     *
     * @return string
     */
    public function statsMarrAge(string $size = '200x250'): string
    {
        return (new Google\ChartMarriageAge($this->tree))
            ->chartMarriageAge($size);
    }

    /**
     * Find the age between husband and wife.
     *
     * @param string $total
     *
     * @return string
     */
    public function ageBetweenSpousesMF(string $total = '10'): string
    {
        $records = $this->ageBetweenSpousesQuery('nolist', 'DESC', (int) $total);

        return view(
            'statistics/families/top10-nolist-spouses',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the age between husband and wife.
     *
     * @param string $total
     *
     * @return string
     */
    public function ageBetweenSpousesMFList(string $total = '10'): string
    {
        $records = $this->ageBetweenSpousesQuery('list', 'DESC', (int) $total);

        return view(
            'statistics/families/top10-list-spouses',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the age between wife and husband..
     *
     * @param string $total
     *
     * @return string
     */
    public function ageBetweenSpousesFM(string $total = '10'): string
    {
        $records = $this->ageBetweenSpousesQuery('nolist', 'ASC', (int) $total);

        return view(
            'statistics/families/top10-nolist-spouses',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the age between wife and husband..
     *
     * @param string $total
     *
     * @return string
     */
    public function ageBetweenSpousesFMList(string $total = '10'): string
    {
        $records = $this->ageBetweenSpousesQuery('list', 'ASC', (int) $total);

        return view(
            'statistics/families/top10-list-spouses',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * General query on marriage ages.
     *
     * @return string
     */
    public function topAgeOfMarriageFamily(): string
    {
        return $this->ageOfMarriageQuery('name', 'DESC', 1);
    }

    /**
     * General query on marriage ages.
     *
     * @return string
     */
    public function topAgeOfMarriage(): string
    {
        return $this->ageOfMarriageQuery('age', 'DESC', 1);
    }

    /**
     * General query on marriage ages.
     *
     * @param string $total
     *
     * @return string
     */
    public function topAgeOfMarriageFamilies(string $total = '10'): string
    {
        return $this->ageOfMarriageQuery('nolist', 'DESC', (int) $total);
    }

    /**
     * General query on marriage ages.
     *
     * @param string $total
     *
     * @return string
     */
    public function topAgeOfMarriageFamiliesList(string $total = '10'): string
    {
        return $this->ageOfMarriageQuery('list', 'DESC', (int) $total);
    }

    /**
     * General query on marriage ages.
     *
     * @return string
     */
    public function minAgeOfMarriageFamily(): string
    {
        return $this->ageOfMarriageQuery('name', 'ASC', 1);
    }

    /**
     * General query on marriage ages.
     *
     * @return string
     */
    public function minAgeOfMarriage(): string
    {
        return $this->ageOfMarriageQuery('age', 'ASC', 1);
    }

    /**
     * General query on marriage ages.
     *
     * @param string $total
     *
     * @return string
     */
    public function minAgeOfMarriageFamilies(string $total = '10'): string
    {
        return $this->ageOfMarriageQuery('nolist', 'ASC', (int) $total);
    }

    /**
     * General query on marriage ages.
     *
     * @param string $total
     *
     * @return string
     */
    public function minAgeOfMarriageFamiliesList(string $total = '10'): string
    {
        return $this->ageOfMarriageQuery('list', 'ASC', (int) $total);
    }

    /**
     * Find the youngest mother
     *
     * @return string
     */
    public function youngestMother(): string
    {
        return $this->parentsQuery('full', 'ASC', 'F', false);
    }

    /**
     * Find the name of the youngest mother.
     *
     * @return string
     */
    public function youngestMotherName(): string
    {
        return $this->parentsQuery('name', 'ASC', 'F', false);
    }

    /**
     * Find the age of the youngest mother.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function youngestMotherAge(string $show_years = ''): string
    {
        return $this->parentsQuery('age', 'ASC', 'F', (bool) $show_years);
    }

    /**
     * Find the oldest mother.
     *
     * @return string
     */
    public function oldestMother(): string
    {
        return $this->parentsQuery('full', 'DESC', 'F', false);
    }

    /**
     * Find the name of the oldest mother.
     *
     * @return string
     */
    public function oldestMotherName(): string
    {
        return $this->parentsQuery('name', 'DESC', 'F', false);
    }

    /**
     * Find the age of the oldest mother.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function oldestMotherAge(string $show_years = ''): string
    {
        return $this->parentsQuery('age', 'DESC', 'F', (bool) $show_years);
    }

    /**
     * Find the youngest father.
     *
     * @return string
     */
    public function youngestFather(): string
    {
        return $this->parentsQuery('full', 'ASC', 'M', false);
    }

    /**
     * Find the name of the youngest father.
     *
     * @return string
     */
    public function youngestFatherName(): string
    {
        return $this->parentsQuery('name', 'ASC', 'M', false);
    }

    /**
     * Find the age of the youngest father.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function youngestFatherAge(string $show_years = ''): string
    {
        return $this->parentsQuery('age', 'ASC', 'M', (bool) $show_years);
    }

    /**
     * Find the oldest father.
     *
     * @return string
     */
    public function oldestFather(): string
    {
        return $this->parentsQuery('full', 'DESC', 'M', false);
    }

    /**
     * Find the name of the oldest father.
     *
     * @return string
     */
    public function oldestFatherName(): string
    {
        return $this->parentsQuery('name', 'DESC', 'M', false);
    }

    /**
     * Find the age of the oldest father.
     *
     * @param string $show_years
     *
     * @return string
     */
    public function oldestFatherAge(string $show_years = ''): string
    {
        return $this->parentsQuery('age', 'DESC', 'M', (bool) $show_years);
    }

    /**
     * Number of husbands.
     *
     * @return string
     */
    public function totalMarriedMales(): string
    {
        $n = (int) Database::prepare(
            "SELECT COUNT(DISTINCT f_husb) FROM `##families` WHERE f_file = :tree_id AND f_gedcom LIKE '%\\n1 MARR%'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();

        return I18N::number($n);
    }

    /**
     * Number of wives.
     *
     * @return string
     */
    public function totalMarriedFemales(): string
    {
        $n = (int) Database::prepare(
            "SELECT COUNT(DISTINCT f_wife) FROM `##families` WHERE f_file = :tree_id AND f_gedcom LIKE '%\\n1 MARR%'"
        )->execute([
            'tree_id' => $this->tree->id(),
        ])->fetchOne();

        return I18N::number($n);
    }

    /**
     * General query on families.
     *
     * @param int $total
     *
     * @return array
     */
    private function topTenFamilyQuery(int $total): array
    {
        $rows = $this->runSql(
            "SELECT f_numchil AS tot, f_id AS id" .
            " FROM `##families`" .
            " WHERE" .
            " f_file={$this->tree->id()}" .
            " ORDER BY tot DESC" .
            " LIMIT " . $total
        );

        if (empty($rows)) {
            return [];
        }

        $top10 = [];
        foreach ($rows as $row) {
            $family = Family::getInstance($row->id, $this->tree);

            if ($family && $family->canShow()) {
                $top10[] = [
                    'family' => $family,
                    'count'  => (int) $row->tot,
                ];
            }
        }

        // TODO
//        if (I18N::direction() === 'rtl') {
//            $top10 = str_replace([
//                '[',
//                ']',
//                '(',
//                ')',
//                '+',
//            ], [
//                '&rlm;[',
//                '&rlm;]',
//                '&rlm;(',
//                '&rlm;)',
//                '&rlm;+',
//            ], $top10);
//        }

        return $top10;
    }

    /**
     * Find the ages between siblings.
     *
     * @param string $type
     * @param int    $total
     * @param bool   $one   Include each family only once if true
     *
     * @return array
     */
    private function ageBetweenSiblingsQuery(string $type, int $total, bool $one): array
    {
        $ageDiff = new AgeDifferenceSiblings($this->tree);
        return $ageDiff->query($type, $total, $one);
    }

    /**
     * Find the month in the year of the birth of the first child.
     *
     * @param bool $sex
     * @param int  $year1
     * @param int  $year2
     *
     * @return stdClass[]
     */
    public function monthFirstChildQuery($sex = false, $year1 = -1, $year2 = -1): array
    {
        if ($year1 >= 0 && $year2 >= 0) {
            $sql_years = " AND (d_year BETWEEN '{$year1}' AND '{$year2}')";
        } else {
            $sql_years = '';
        }

        if ($sex) {
            $sql_sex1 = ', i_sex';
            $sql_sex2 = " JOIN `##individuals` AS child ON child1.d_file = i_file AND child1.d_gid = child.i_id ";
        } else {
            $sql_sex1 = '';
            $sql_sex2 = '';
        }

        $sql =
            "SELECT d_month{$sql_sex1}, COUNT(*) AS total " .
            "FROM (" .
            " SELECT family{$sql_sex1}, MIN(date) AS d_date, d_month" .
            " FROM (" .
            "  SELECT" .
            "  link1.l_from AS family," .
            "  link1.l_to AS child," .
            "  child1.d_julianday2 AS date," .
            "  child1.d_month as d_month" .
            $sql_sex1 .
            "  FROM `##link` AS link1" .
            "  LEFT JOIN `##dates` AS child1 ON child1.d_file = {$this->tree->id()}" .
            $sql_sex2 .
            "  WHERE" .
            "  link1.l_file = {$this->tree->id()} AND" .
            "  link1.l_type = 'CHIL' AND" .
            "  child1.d_gid = link1.l_to AND" .
            "  child1.d_fact = 'BIRT' AND" .
            "  child1.d_month IN ('JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC')" .
            $sql_years .
            "  ORDER BY date" .
            " ) AS children" .
            " GROUP BY family, d_month{$sql_sex1}" .
            ") AS first_child " .
            "GROUP BY d_month";

        if ($sex) {
            $sql .= ', i_sex';
        }

        return $this->runSql($sql);
    }

    /**
     * Find the family with the most children.
     *
     * @return string
     */
    public function largestFamily(): string
    {
        return $this->family->familyQuery('full');
    }

    /**
     * Find the number of children in the largest family.
     *
     * @return string
     */
    public function largestFamilySize(): string
    {
        return $this->family->familyQuery('size');
    }

    /**
     * Find the family with the most children.
     *
     * @return string
     */
    public function largestFamilyName(): string
    {
        return $this->family->familyQuery('name');
    }

    /**
     * The the families with the most children.
     *
     * @param string $total
     *
     * @return string
     *
     * @deprecated
     */
    public function topTenLargestFamily(string $total = '10'): string
    {
        $records = $this->topTenFamilyQuery((int) $total);

        return view(
            'statistics/families/top10-nolist',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the families with the most children.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenLargestFamilyList(string $total = '10'): string
    {
        $records = $this->topTenFamilyQuery((int) $total);

        return view(
            'statistics/families/top10-list',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Create a chart of the largest families.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     * @param string      $total
     *
     * @return string
     */
    public function chartLargestFamilies(
        string $size       = null,
        string $color_from = null,
        string $color_to   = null,
        string $total      = '10'
    ): string {
        return (new Google\ChartFamily($this->tree))
            ->chartLargestFamilies($size, $color_from, $color_to, $total);
    }

    /**
     * Count the total children.
     *
     * @return string
     */
    public function totalChildren(): string
    {
        return $this->children->totalChildren();
    }

    /**
     * Find the average number of children in families.
     *
     * @return string
     */
    public function averageChildren(): string
    {
        return $this->children->averageChildren();
    }

    /**
     * General query on familes/children.
     *
     * @param bool   $simple
     * @param string $sex
     * @param int    $year1
     * @param int    $year2
     *
     * @return stdClass[]
     */
    public function statsChildrenQuery($simple = true, $sex = 'BOTH', $year1 = -1, $year2 = -1): array
    {
        return $this->children->query($sex, $year1, $year2);
    }

    /**
     * Genearl query on families/children.
     *
     * @param string $size
     *
     * @return string
     */
    public function statsChildren(string $size = '220x200'): string
    {
        return (new Google\ChartChildren($this->tree))
            ->chartChildren($size);
    }

    /**
     * Find the names of siblings with the widest age gap.
     *
     * @param string $total
     * @param string $one
     *
     * @return string
     */
    public function topAgeBetweenSiblingsName(string $total = '10', string $one = ''): string
    {
        // TODO
//        return $this->ageBetweenSiblingsQuery('name', (int) $total, (bool) $one);
        return 'topAgeBetweenSiblingsName';
    }

    /**
     * Find the widest age gap between siblings.
     *
     * @param string $total
     * @param string $one
     *
     * @return string
     */
    public function topAgeBetweenSiblings(string $total = '10', string $one = ''): string
    {
        // TODO
//        return $this->ageBetweenSiblingsQuery('age', (int) $total, (bool) $one);
        return 'topAgeBetweenSiblings';
    }

    /**
     * Find the name of siblings with the widest age gap.
     *
     * @param string $total
     * @param string $one
     *
     * @return string
     */
    public function topAgeBetweenSiblingsFullName(string $total = '10', string $one = ''): string
    {
        $record = $this->ageBetweenSiblingsQuery('nolist', (int) $total, (bool) $one);

        return view(
            'statistics/families/top10-nolist-age',
            [
                'record' => $record,
            ]
        );
    }

    /**
     * Find the siblings with the widest age gaps.
     *
     * @param string $total
     * @param string $one
     *
     * @return string
     */
    public function topAgeBetweenSiblingsList(string $total = '10', string $one = ''): string
    {
        $records = $this->ageBetweenSiblingsQuery('list', (int) $total, (bool) $one);

        return view(
            'statistics/families/top10-list-age',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the families with no children.
     *
     * @return int
     */
    private function noChildrenFamiliesQuery(): int
    {
        $rows = $this->runSql(
            " SELECT COUNT(*) AS tot" .
            " FROM  `##families`" .
            " WHERE f_numchil = 0 AND f_file = {$this->tree->id()}"
        );

        return (int) $rows[0]->tot;
    }

    /**
     * Find the families with no children.
     *
     * @return string
     */
    public function noChildrenFamilies(): string
    {
        return I18N::number($this->noChildrenFamiliesQuery());
    }

    /**
     * Find the families with no children.
     *
     * @param string $type
     *
     * @return string
     */
    public function noChildrenFamiliesList($type = 'list'): string
    {
        $rows = $this->runSql(
            " SELECT f_id AS family" .
            " FROM `##families` AS fam" .
            " WHERE f_numchil = 0 AND fam.f_file = {$this->tree->id()}"
        );
        if (!isset($rows[0])) {
            return '';
        }
        $top10 = [];
        foreach ($rows as $row) {
            $family = Family::getInstance($row->family, $this->tree);
            if ($family->canShow()) {
                if ($type == 'list') {
                    $top10[] = '<li><a href="' . e($family->url()) . '">' . $family->getFullName() . '</a></li>';
                } else {
                    $top10[] = '<a href="' . e($family->url()) . '">' . $family->getFullName() . '</a>';
                }
            }
        }
        if ($type == 'list') {
            $top10 = implode('', $top10);
        } else {
            $top10 = implode('; ', $top10);
        }
        if (I18N::direction() === 'rtl') {
            $top10 = str_replace([
                '[',
                ']',
                '(',
                ')',
                '+',
            ], [
                '&rlm;[',
                '&rlm;]',
                '&rlm;(',
                '&rlm;)',
                '&rlm;+',
            ], $top10);
        }
        if ($type === 'list') {
            return '<ul>' . $top10 . '</ul>';
        }

        return $top10;
    }

    /**
     * Create a chart of children with no families.
     *
     * @param string $size
     * @param string $year1
     * @param string $year2
     *
     * @return string
     */
    public function chartNoChildrenFamilies(string $size = '220x200', $year1 = '-1', $year2 = '-1'): string
    {
        $year1 = (int) $year1;
        $year2 = (int) $year2;

        $sizes = explode('x', $size);

        if ($year1 >= 0 && $year2 >= 0) {
            $years = " married.d_year BETWEEN '{$year1}' AND '{$year2}' AND";
        } else {
            $years = '';
        }

        $max  = 0;
        $tot  = 0;
        $rows = $this->runSql(
            "SELECT" .
            " COUNT(*) AS count," .
            " FLOOR(married.d_year/100+1) AS century" .
            " FROM" .
            " `##families` AS fam" .
            " JOIN" .
            " `##dates` AS married ON (married.d_file = fam.f_file AND married.d_gid = fam.f_id)" .
            " WHERE" .
            " f_numchil = 0 AND" .
            " fam.f_file = {$this->tree->id()} AND" .
            $years .
            " married.d_fact = 'MARR' AND" .
            " married.d_type IN ('@#DGREGORIAN@', '@#DJULIAN@')" .
            " GROUP BY century ORDER BY century"
        );

        if (empty($rows)) {
            return '';
        }

        foreach ($rows as $values) {
            $values->count = (int) $values->count;

            if ($max < $values->count) {
                $max = $values->count;
            }
            $tot += $values->count;
        }

        $unknown = $this->noChildrenFamiliesQuery() - $tot;

        if ($unknown > $max) {
            $max = $unknown;
        }

        $chm    = '';
        $chxl   = '0:|';
        $i      = 0;
        $counts = [];

        foreach ($rows as $values) {
            $chxl     .= $this->centuryHelper->centuryName($values->century) . '|';
            $counts[] = intdiv(4095 * $values->count, $max + 1);
            $chm      .= 't' . $values->count . ',000000,0,' . $i . ',11,1|';
            $i++;
        }

        $counts[] = intdiv(4095 * $unknown, $max + 1);
        $chd      = $this->google->arrayToExtendedEncoding($counts);
        $chm      .= 't' . $unknown . ',000000,0,' . $i . ',11,1';
        $chxl     .= I18N::translateContext('unknown century', 'Unknown') . '|1:||' . I18N::translate('century') . '|2:|0|';
        $step     = $max + 1;

        for ($d = (int) ($max + 1); $d > 0; $d--) {
            if (($max + 1) < ($d * 10 + 1) && fmod(($max + 1), $d) === 0) {
                $step = $d;
            }
        }

        if ($step === (int) ($max + 1)) {
            for ($d = (int) ($max); $d > 0; $d--) {
                if ($max < ($d * 10 + 1) && fmod($max, $d) === 0) {
                    $step = $d;
                }
            }
        }

        for ($n = $step; $n <= ($max + 1); $n += $step) {
            $chxl .= $n . '|';
        }

        $chxl .= '3:||' . I18N::translate('Total families') . '|';

        return "<img src=\"https://chart.googleapis.com/chart?cht=bvg&amp;chs={$sizes[0]}x{$sizes[1]}&amp;chf=bg,s,ffffff00|c,s,ffffff00&amp;chm=D,FF0000,0,0:" . ($i - 1) . ",3,1|{$chm}&amp;chd=e:{$chd}&amp;chco=0000FF,ffffff00&amp;chbh=30,3&amp;chxt=x,x,y,y&amp;chxl=" . rawurlencode($chxl) . "\" width=\"{$sizes[0]}\" height=\"{$sizes[1]}\" alt=\"" . I18N::translate('Number of families without children') . '" title="' . I18N::translate('Number of families without children') . '" />';
    }

    /**
     * Find the couple with the most grandchildren.
     *
     * @param int $total
     *
     * @return array
     */
    private function topTenGrandFamilyQuery(int $total): array
    {
        $rows = $this->runSql(
            "SELECT COUNT(*) AS tot, f_id AS id" .
            " FROM `##families`" .
            " JOIN `##link` AS children ON children.l_file = {$this->tree->id()}" .
            " JOIN `##link` AS mchildren ON mchildren.l_file = {$this->tree->id()}" .
            " JOIN `##link` AS gchildren ON gchildren.l_file = {$this->tree->id()}" .
            " WHERE" .
            " f_file={$this->tree->id()} AND" .
            " children.l_from=f_id AND" .
            " children.l_type='CHIL' AND" .
            " children.l_to=mchildren.l_from AND" .
            " mchildren.l_type='FAMS' AND" .
            " mchildren.l_to=gchildren.l_from AND" .
            " gchildren.l_type='CHIL'" .
            " GROUP BY id" .
            " ORDER BY tot DESC" .
            " LIMIT " . $total
        );

        if (!isset($rows[0])) {
            return [];
        }

        $top10 = [];

        foreach ($rows as $row) {
            $family = Family::getInstance($row->id, $this->tree);

            if ($family && $family->canShow()) {
                $total = (int) $row->tot;

                $top10[] = [
                    'family' => $family,
                    'count'  => $total,
                ];
            }
        }

        // TODO
//        if (I18N::direction() === 'rtl') {
//            $top10 = str_replace([
//                '[',
//                ']',
//                '(',
//                ')',
//                '+',
//            ], [
//                '&rlm;[',
//                '&rlm;]',
//                '&rlm;(',
//                '&rlm;)',
//                '&rlm;+',
//            ], $top10);
//        }

        return $top10;
    }

    /**
     * Find the couple with the most grandchildren.
     *
     * @param string $total
     *
     * @return string
     *
     * @deprecated
     */
    public function topTenLargestGrandFamily(string $total = '10'): string
    {
        $records = $this->topTenGrandFamilyQuery((int) $total);

        return view(
            'statistics/families/top10-nolist-grand',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find the couple with the most grandchildren.
     *
     * @param string $total
     *
     * @return string
     */
    public function topTenLargestGrandFamilyList(string $total = '10'): string
    {
        $records = $this->topTenGrandFamilyQuery((int) $total);

        return view(
            'statistics/families/top10-list-grand',
            [
                'records' => $records,
            ]
        );
    }

    /**
     * Find common surnames.
     *
     * @return string
     *
     * @deprecated
     */
    public function getCommonSurname(): string
    {
        return $this->surname->getCommonSurname();
    }

    /**
     * Find common surnames.
     *
     * @param string $threshold
     * @param string $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnames(string $threshold = '1', string $number_of_surnames = '10', string $sorting = 'alpha'): string
    {
        return $this->surname->commonSurnamesQuery('nolist', false, (int) $threshold, (int) $number_of_surnames, $sorting);
    }

    /**
     * Find common surnames.
     *
     * @param string $threshold
     * @param string $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnamesTotals(string $threshold = '1', string $number_of_surnames = '10', string $sorting = 'rcount'): string
    {
        return $this->surname->commonSurnamesQuery('nolist', true, (int) $threshold, (int) $number_of_surnames, $sorting);
    }

    /**
     * Find common surnames.
     *
     * @param string $threshold
     * @param string $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnamesList(string $threshold = '1', string $number_of_surnames = '10', string $sorting = 'alpha'): string
    {
        return $this->surname->commonSurnamesQuery('list', false, (int) $threshold, (int) $number_of_surnames, $sorting);
    }

    /**
     * Find common surnames.
     *
     * @param string $threshold
     * @param string $number_of_surnames
     * @param string $sorting
     *
     * @return string
     */
    public function commonSurnamesListTotals(string $threshold = '1', string $number_of_surnames = '10', string $sorting = 'rcount'): string
    {
        return $this->surname->commonSurnamesQuery('list', true, (int) $threshold, (int) $number_of_surnames, $sorting);
    }

    /**
     * Create a chart of common surnames.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     * @param string      $number_of_surnames
     *
     * @return string
     */
    public function chartCommonSurnames(string $size = null, string $color_from = null, string $color_to = null, string $number_of_surnames = '10'): string
    {
        return (new Google\ChartCommonSurname($this->tree))
            ->chartCommonSurnames($size, $color_from, $color_to, $number_of_surnames);
    }

    /**
     * Find common give names.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGiven(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('B', 'nolist', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('B', 'nolist', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenList(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('B', 'list', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenListTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('B', 'list', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenTable(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('B', 'table', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemale(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('F', 'nolist', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('F', 'nolist', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleList(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('F', 'list', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleListTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('F', 'list', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of females.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenFemaleTable(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('F', 'table', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenMale(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('M', 'nolist', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('M', 'nolist', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleList(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('M', 'list', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleListTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('M', 'list', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of males.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenMaleTable(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('M', 'table', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknown(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('U', 'nolist', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('U', 'nolist', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownList(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('U', 'list', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownListTotals(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('U', 'list', true, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Find common give names of unknown sexes.
     *
     * @param string $threshold
     * @param string $maxtoshow
     *
     * @return string
     */
    public function commonGivenUnknownTable(string $threshold = '1', string $maxtoshow = '10'): string
    {
        return $this->individual->commonGivenQuery('U', 'table', false, (int) $threshold, (int) $maxtoshow);
    }

    /**
     * Create a chart of common given names.
     *
     * @param string|null $size
     * @param string|null $color_from
     * @param string|null $color_to
     * @param string      $maxtoshow
     *
     * @return string
     */
    public function chartCommonGiven(string $size = null, string $color_from = null, string $color_to = null, string $maxtoshow = '7'): string
    {
        return (new Google\ChartCommonGiven($this->tree))
            ->chartCommonGiven($size, $color_from, $color_to, $maxtoshow);
    }

    /**
     * Who is currently logged in?
     *
     * @TODO - this is duplicated from the LoggedInUsersModule class.
     *
     * @param string $type
     *
     * @return string
     */
    private function usersLoggedInQuery($type = 'nolist'): string
    {
        $content = '';
        // List active users
        $NumAnonymous = 0;
        $loggedusers  = [];
        foreach (User::allLoggedIn() as $user) {
            if (Auth::isAdmin() || $user->getPreference('visibleonline')) {
                $loggedusers[] = $user;
            } else {
                $NumAnonymous++;
            }
        }
        $LoginUsers = count($loggedusers);
        if ($LoginUsers == 0 && $NumAnonymous == 0) {
            return I18N::translate('No signed-in and no anonymous users');
        }
        if ($NumAnonymous > 0) {
            $content .= '<b>' . I18N::plural('%s anonymous signed-in user', '%s anonymous signed-in users', $NumAnonymous, I18N::number($NumAnonymous)) . '</b>';
        }
        if ($LoginUsers > 0) {
            if ($NumAnonymous) {
                if ($type == 'list') {
                    $content .= '<br><br>';
                } else {
                    $content .= ' ' . I18N::translate('and') . ' ';
                }
            }
            $content .= '<b>' . I18N::plural('%s signed-in user', '%s signed-in users', $LoginUsers, I18N::number($LoginUsers)) . '</b>';
            if ($type == 'list') {
                $content .= '<ul>';
            } else {
                $content .= ': ';
            }
        }
        if (Auth::check()) {
            foreach ($loggedusers as $user) {
                if ($type == 'list') {
                    $content .= '<li>' . e($user->getRealName()) . ' - ' . e($user->getUserName());
                } else {
                    $content .= e($user->getRealName()) . ' - ' . e($user->getUserName());
                }
                if (Auth::id() !== $user->id() && $user->getPreference('contactmethod') !== 'none') {
                    if ($type == 'list') {
                        $content .= '<br>';
                    }
                    $content .= '<a href="' . e(route('message', ['to'  => $user->getUserName(), 'ged' => $this->tree->name()])) . '" class="btn btn-link" title="' . I18N::translate('Send a message') . '">' . view('icons/email') . '</a>';
                }
                if ($type == 'list') {
                    $content .= '</li>';
                }
            }
        }
        if ($type == 'list') {
            $content .= '</ul>';
        }

        return $content;
    }

    /**
     * NUmber of users who are currently logged in?
     *
     * @param string $type
     *
     * @return int
     */
    private function usersLoggedInTotalQuery($type = 'all'): int
    {
        $anon    = 0;
        $visible = 0;
        foreach (User::allLoggedIn() as $user) {
            if (Auth::isAdmin() || $user->getPreference('visibleonline')) {
                $visible++;
            } else {
                $anon++;
            }
        }
        if ($type == 'anon') {
            return $anon;
        }

        if ($type == 'visible') {
            return $visible;
        }

        return $visible + $anon;
    }

    /**
     * Who is currently logged in?
     *
     * @return string
     */
    public function usersLoggedIn(): string
    {
        return $this->usersLoggedInQuery('nolist');
    }

    /**
     * Who is currently logged in?
     *
     * @return string
     */
    public function usersLoggedInList(): string
    {
        return $this->usersLoggedInQuery('list');
    }

    /**
     * Who is currently logged in?
     *
     * @return int
     */
    public function usersLoggedInTotal(): int
    {
        return $this->usersLoggedInTotalQuery('all');
    }

    /**
     * Which visitors are currently logged in?
     *
     * @return int
     */
    public function usersLoggedInTotalAnon(): int
    {
        return $this->usersLoggedInTotalQuery('anon');
    }

    /**
     * Which visitors are currently logged in?
     *
     * @return int
     */
    public function usersLoggedInTotalVisible(): int
    {
        return $this->usersLoggedInTotalQuery('visible');
    }

    /**
     * Get the current user's ID.
     *
     * @return string
     */
    public function userId(): string
    {
        return (string) Auth::id();
    }

    /**
     * Get the current user's username.
     *
     * @param string $visitor_text
     *
     * @return string
     */
    public function userName(string $visitor_text = ''): string
    {
        if (Auth::check()) {
            return e(Auth::user()->getUserName());
        }

        // if #username:visitor# was specified, then "visitor" will be returned when the user is not logged in
        return e($visitor_text);
    }

    /**
     * Get the current user's full name.
     *
     * @return string
     */
    public function userFullName(): string
    {
        return Auth::check() ? '<span dir="auto">' . e(Auth::user()->getRealName()) . '</span>' : '';
    }

    /**
     * Find the newest user on the site.
     *
     * If no user has registered (i.e. all created by the admin), then
     * return the current user.
     *
     * @return User
     */
    private function latestUser(): User
    {
        static $user;

        if (!$user instanceof User) {
            $user_id = (int) Database::prepare(
                "SELECT u.user_id" .
                " FROM `##user` u" .
                " LEFT JOIN `##user_setting` us ON (u.user_id=us.user_id AND us.setting_name='reg_timestamp') " .
                " ORDER BY us.setting_value DESC LIMIT 1"
            )->execute()->fetchOne();

            $user = User::find($user_id) ?? Auth::user();
        }

        return $user;
    }

    /**
     * Get the newest registered user's ID.
     *
     * @return string
     */
    public function latestUserId(): string
    {
        return (string) $this->latestUser()->id();
    }

    /**
     * Get the newest registered user's username.
     *
     * @return string
     */
    public function latestUserName(): string
    {
        return e($this->latestUser()->getUserName());
    }

    /**
     * Get the newest registered user's real name.
     *
     * @return string
     */
    public function latestUserFullName(): string
    {
        return e($this->latestUser()->getRealName());
    }

    /**
     * Get the date of the newest user registration.
     *
     * @param string|null $format
     *
     * @return string
     */
    public function latestUserRegDate(string $format = null): string
    {
        $format = $format ?? I18N::dateFormat();

        $user = $this->latestUser();

        return FunctionsDate::timestampToGedcomDate((int) $user->getPreference('reg_timestamp'))->display(false, $format);
    }

    /**
     * Find the timestamp of the latest user to register.
     *
     * @param string|null $format
     *
     * @return string
     */
    public function latestUserRegTime(string $format = null): string
    {
        $format = $format ?? str_replace('%', '', I18N::timeFormat());

        $user = $this->latestUser();

        return date($format, (int) $user->getPreference('reg_timestamp'));
    }

    /**
     * Is the most recently registered user logged in right now?
     *
     * @param string|null $yes
     * @param string|null $no
     *
     * @return string
     */
    public function latestUserLoggedin(string $yes = null, string $no = null): string
    {
        $yes = $yes ?? I18N::translate('yes');
        $no  = $no ?? I18N::translate('no');

        $user = $this->latestUser();

        $is_logged_in = (bool) Database::prepare(
            "SELECT 1 FROM `##session` WHERE user_id = :user_id LIMIT 1"
        )->execute([
            'user_id' => $user->id()
        ])->fetchOne();

        return $is_logged_in ? $yes : $no;
    }

    /**
     * Create a link to contact the webmaster.
     *
     * @return string
     */
    public function contactWebmaster()
    {
        $user_id = $this->tree->getPreference('WEBMASTER_USER_ID');
        $user    = User::find((int) $user_id);

        if ($user instanceof User) {
            return Theme::theme()->contactLink($user);
        }

        return $user_id;
    }

    /**
     * Create a link to contact the genealogy contact.
     *
     * @return string
     */
    public function contactGedcom()
    {
        $user_id = $this->tree->getPreference('CONTACT_USER_ID');
        $user    = User::find((int) $user_id);

        if ($user instanceof User) {
            return Theme::theme()->contactLink($user);
        }

        return $user_id;
    }

    /**
     * What is the current date on the server?
     *
     * @return string
     */
    public function serverDate(): string
    {
        return FunctionsDate::timestampToGedcomDate(WT_TIMESTAMP)->display();
    }

    /**
     * What is the current time on the server (in 12 hour clock)?
     *
     * @return string
     */
    public function serverTime(): string
    {
        return date('g:i a');
    }

    /**
     * What is the current time on the server (in 24 hour clock)?
     *
     * @return string
     */
    public function serverTime24(): string
    {
        return date('G:i');
    }

    /**
     * What is the timezone of the server.
     *
     * @return string
     */
    public function serverTimezone(): string
    {
        return date('T');
    }

    /**
     * What is the client's date.
     *
     * @return string
     */
    public function browserDate(): string
    {
        return FunctionsDate::timestampToGedcomDate(WT_TIMESTAMP)->display();
    }

    /**
     * What is the client's timestamp.
     *
     * @return string
     */
    public function browserTime(): string
    {
        return date(str_replace('%', '', I18N::timeFormat()), WT_TIMESTAMP + WT_TIMESTAMP_OFFSET);
    }

    /**
     * What is the browser's tiemzone.
     *
     * @return string
     */
    public function browserTimezone(): string
    {
        return date('T', WT_TIMESTAMP + WT_TIMESTAMP_OFFSET);
    }

    /**
     * What is the current version of webtrees.
     *
     * @return string
     */
    public function webtreesVersion(): string
    {
        return Webtrees::VERSION;
    }

    /**
     * These functions provide access to hitcounter for use in the HTML block.
     *
     * @param string $page_name
     * @param string $page_parameter
     *
     * @return string
     */
    private function hitCountQuery($page_name, string $page_parameter = ''): string
    {
        if ($page_name === '') {
            // index.php?ctype=gedcom
            $page_name      = 'index.php';
            $page_parameter = 'gedcom:' . $this->tree->id();
        } elseif ($page_name == 'index.php') {
            // index.php?ctype=user
            $user           = User::findByIdentifier($page_parameter);
            $page_parameter = 'user:' . ($user ? $user->id() : Auth::id());
        }

        $hit_counter = new PageHitCounter(Auth::user(), $this->tree);

        return '<span class="odometer">' . I18N::digits($hit_counter->getCount($this->tree, $page_name, $page_parameter)) . '</span>';
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCount(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountUser(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('index.php', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountIndi(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('individual.php', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountFam(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('family.php', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountSour(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('source.php', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountRepo(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('repo.php', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountNote(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('note.php', $page_parameter);
    }

    /**
     * How many times has a page been viewed.
     *
     * @param string $page_parameter
     *
     * @return string
     */
    public function hitCountObje(string $page_parameter = ''): string
    {
        return $this->hitCountQuery('mediaviewer.php', $page_parameter);
    }

    /**
     * Run an SQL query and cache the result.
     *
     * @param string $sql
     *
     * @return stdClass[]
     */
    private function runSql($sql): array
    {
        return Sql::runSql($sql);
    }

    /**
     * Find the favorites for the tree.
     *
     * @return string
     */
    public function gedcomFavorites(): string
    {
        $module = Module::getModuleByName('gedcom_favorites');

        if ($module instanceof FamilyTreeFavoritesModule) {
            $block = new FamilyTreeFavoritesModule(Webtrees::MODULES_PATH . 'gedcom_favorites');

            return $block->getBlock($this->tree, 0, '');
        }

        return '';
    }

    /**
     * Find the favorites for the user.
     *
     * @return string
     */
    public function userFavorites(): string
    {
        if (Auth::check() && Module::getModuleByName('user_favorites')) {
            $block = new UserFavoritesModule(Webtrees::MODULES_PATH . 'gedcom_favorites');

            return $block->getBlock($this->tree, 0, '');
        }

        return '';
    }

    /**
     * Find the number of favorites for the tree.
     *
     * @return string
     */
    public function totalGedcomFavorites(): string
    {
        $count = 0;

        $module = Module::getModuleByName('gedcom_favorites');

        if ($module instanceof FamilyTreeFavoritesModule) {
            $count = count($module->getFavorites($this->tree));
        }

        return I18N::number($count);
    }

    /**
     * Find the number of favorites for the user.
     *
     * @return string
     */
    public function totalUserFavorites(): string
    {
        $count = 0;

        $module = Module::getModuleByName('user_favorites');

        if ($module instanceof UserFavoritesModule) {
            $count = count($module->getFavorites($this->tree, Auth::user()));
        }

        return I18N::number($count);
    }

    /**
     * Create any of the other blocks.
     * Use as #callBlock:block_name#
     *
     * @param string $block
     * @param string ...$params
     *
     * @return string
     */
    public function callBlock(string $block = '', ...$params): string
    {
        $all_blocks = Module::activeBlocks($this->tree);

        if (!array_key_exists($block, $all_blocks) || ($block === 'html')) {
            return '';
        }

        // Build the config array
        $cfg = [];
        foreach ($params as $config) {
            $bits = explode('=', $config);

            if (count($bits) < 2) {
                continue;
            }

            $v       = array_shift($bits);
            $cfg[$v] = implode('=', $bits);
        }

        $block   = $all_blocks[$block];
        $content = $block->getBlock($this->tree, 0, '', $cfg);

        return $content;
    }

    /**
     * How many messages in the user's inbox.
     *
     * @return string
     */
    public function totalUserMessages(): string
    {
        $total = (int) Database::prepare("SELECT COUNT(*) FROM `##message` WHERE user_id = ?")
            ->execute([Auth::id()])
            ->fetchOne();

        return I18N::number($total);
    }

    /**
     * How many blog entries exist for this user.
     *
     * @return string
     */
    public function totalUserJournal(): string
    {
        try {
            $number = (int) Database::prepare("SELECT COUNT(*) FROM `##news` WHERE user_id = ?")
                ->execute([Auth::id()])
                ->fetchOne();
        } catch (PDOException $ex) {
            // The module may not be installed, so the table may not exist.
            $number = 0;
        }

        return I18N::number($number);
    }

    /**
     * How many news items exist for this tree.
     *
     * @return string
     */
    public function totalGedcomNews(): string
    {
        try {
            $number = (int) Database::prepare("SELECT COUNT(*) FROM `##news` WHERE gedcom_id = ?")
                ->execute([$this->tree->id()])
                ->fetchOne();
        } catch (PDOException $ex) {
            // The module may not be installed, so the table may not exist.
            $number = 0;
        }

        return I18N::number($number);
    }
}
