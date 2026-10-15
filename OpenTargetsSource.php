<?php

namespace Omnilab\OpenTargets;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Open Targets Platform (EMBL-EBI, Wellcome Sanger, GSK and partners)
 * through its GraphQL API: a target by its Ensembl gene ("ENSG00000244734",
 * HBB) - its symbol, name, biotype, function, location, class, its UniProt
 * and HGNC ids, its best associated diseases with their scores, its drugs
 * and clinical candidates, its InterPro domains and Reactome pathways -; a
 * disease or phenotype by its EFO, Mondo, HPO or Orphanet id
 * ("MONDO_0011382", sickle cell disease) - its name, description,
 * synonyms, therapeutic areas, parents, its ids in other ontologies, its
 * best associated targets, its drugs -; a drug by its ChEMBL id - its type,
 * stage, mechanisms of action and their targets, indications, trade
 * names -; a search over the three; the associations themselves, paged
 * (associations(), outside the contract).
 *
 * No key, no account, no published rate: no throttle by default. One call
 * an entity, one a search page.
 */
final class OpenTargetsSource extends HttpSource
{
    public const BASE_URI = 'https://api.platform.opentargets.org/api/v4/';

    /** The most hits a search page holds. */
    public const MAX_LIMIT = 100;

    private const TARGET = 'query target($id: String!, $size: Int!) { target(ensemblId: $id) { id approvedSymbol approvedName biotype functionDescriptions synonyms { label source } genomicLocation { chromosome start end strand } proteinIds { id source } dbXrefs { id source } subcellularLocations { location } targetClass { label level } associatedDiseases(page: {index: 0, size: $size}) { count rows { score disease { id name } datatypeScores { id score } } } drugAndClinicalCandidates { count rows { maxClinicalStage drug { id name drugType maximumClinicalStage } } } } }';
    private const DISEASE = 'query disease($id: String!, $size: Int!) { disease(efoId: $id) { id name description synonyms { relation terms } therapeuticAreas { id name } parents { id name } dbXRefs obsoleteTerms associatedTargets(page: {index: 0, size: $size}) { count rows { score target { id approvedSymbol } datatypeScores { id score } } } drugAndClinicalCandidates { count rows { maxClinicalStage drug { id name drugType } } } } }';
    private const DRUG = 'query drug($id: String!) { drug(chemblId: $id) { id name drugType maximumClinicalStage description synonyms { label source } tradeNames { label source } crossReferences { source ids } parentMolecule { id name } mechanismsOfAction { rows { mechanismOfAction actionType targetName targets { id approvedSymbol } } } indications { count rows { maxClinicalStage disease { id name } } } } }';
    private const SEARCH = 'query search($q: String!, $index: Int!, $size: Int!) { search(queryString: $q, entityNames: ["target", "disease", "drug"], page: {index: $index, size: $size}) { total hits { id entity name description } } }';
    private const TARGET_ASSOCIATIONS = 'query associations($id: String!, $index: Int!, $size: Int!) { target(ensemblId: $id) { id associatedDiseases(page: {index: $index, size: $size}) { count rows { score disease { id name } datatypeScores { id score } } } } }';
    private const DISEASE_ASSOCIATIONS = 'query associations($id: String!, $index: Int!, $size: Int!) { disease(efoId: $id) { id associatedTargets(page: {index: $index, size: $size}) { count rows { score target { id approvedSymbol } datatypeScores { id score } } } } }';

    /** Open Targets' clinical stages, the furthest first: how drugs are ordered. */
    private const STAGES = ['APPROVAL', 'WITHDRAWAL', 'PHASE_4', 'PHASE_3', 'PHASE_2_3', 'PHASE_2', 'PHASE_1_2', 'PHASE_1', 'EARLY_PHASE_1', 'PRECLINICAL', 'UNKNOWN'];

    /** The ontologies a disease is named in, and the prefix Open Targets writes them with. */
    private const DISEASES = ['efo' => 'EFO_', 'mondo' => 'MONDO_', 'hp' => 'HP_', 'orphanet' => 'Orphanet_'];

    /**
     * @param int $associations how many associations (and drugs) an entity holds as properties, the best first
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $associations = 10,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'opentargets';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A target by its Ensembl gene ("ENSG00000244734"), a disease or a
     * phenotype by its EFO, Mondo, HPO or Orphanet id ("MONDO_0011382",
     * "MONDO:0011382", "EFO_0000305", "HP_0001878", "Orphanet_232"), a drug
     * by its ChEMBL id ("CHEMBL4101807"); another base's identifier (an
     * Ensembl transcript or protein among them) is refused before any call;
     * null when Open Targets has no such thing.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::ENSEMBL, Scheme::EFO, Scheme::MONDO, Scheme::HP, Scheme::ORPHANET, Scheme::CHEMBL);
        if (null === $identifier || (Scheme::ENSEMBL === $identifier->scheme && !str_starts_with($identifier->value, 'ENSG'))) {
            throw NotSupportedException::identifier('opentargets', (string) $id);
        }
        $size = max(0, $this->associations);

        return match ($identifier->scheme) {
            Scheme::ENSEMBL => ($target = $this->query(self::TARGET, ['id' => $identifier->value, 'size' => $size])['target'] ?? null) ? $this->target($target) : null,
            Scheme::CHEMBL => ($drug = $this->query(self::DRUG, ['id' => $identifier->value])['drug'] ?? null) ? $this->drug($drug) : null,
            default => ($disease = $this->query(self::DISEASE, ['id' => self::diseaseId($identifier), 'size' => $size])['disease'] ?? null) ? $this->disease($disease, $identifier) : null,
        };
    }

    /**
     * Targets, diseases and drugs matching a text, Open Targets' ranking;
     * a domain other than biology answers nothing. An entity of a page holds
     * its name and description: entity() reads it whole. A hit in no
     * scheme of the core (a therapeutic area, OTAR_...) is left out.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::BIOLOGY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $index = max(0, (int) $query->cursor);
        $size = max(1, min(self::MAX_LIMIT, $query->limit));
        $search = $this->query(self::SEARCH, ['q' => $text, 'index' => $index, 'size' => $size])['search'] ?? [];
        $items = [];
        foreach ($search['hits'] ?? [] as $hit) {
            $id = \is_array($hit) ? self::parse((string) ($hit['id'] ?? '')) : null;
            if (null !== $id) {
                $items[] = new Entity(
                    id: $id,
                    label: self::text($hit['name'] ?? null) ?? $id->value,
                    domain: Domain::BIOLOGY,
                    kind: match ($hit['entity'] ?? null) { 'target' => 'target', 'drug' => 'drug', default => 'disease' },
                    description: self::text($hit['description'] ?? null),
                    url: self::page($id),
                    source: 'opentargets',
                );
            }
        }
        $total = isset($search['total']) ? (int) $search['total'] : null;

        return new Page($items, null !== $total && ($index + 1) * $size < $total ? (string) ($index + 1) : null, $total);
    }

    /**
     * The associations of a target with diseases, or of a disease with
     * targets, the best scored first, one page at a time (the cursor is the
     * page's index). Outside the contract: an association is two things and
     * a score.
     *
     * @return Page<Association>
     */
    public function associations(Identifier|string $id, int $limit = 25, ?string $cursor = null): Page
    {
        $identifier = self::identify($id, Scheme::ENSEMBL, Scheme::EFO, Scheme::MONDO, Scheme::HP, Scheme::ORPHANET);
        if (null === $identifier || (Scheme::ENSEMBL === $identifier->scheme && !str_starts_with($identifier->value, 'ENSG'))) {
            throw NotSupportedException::identifier('opentargets', (string) $id);
        }
        $index = max(0, (int) $cursor);
        $size = max(1, min(self::MAX_LIMIT, $limit));
        if (Scheme::ENSEMBL === $identifier->scheme) {
            $block = $this->query(self::TARGET_ASSOCIATIONS, ['id' => $identifier->value, 'index' => $index, 'size' => $size])['target']['associatedDiseases'] ?? null;
            $associations = array_map(fn (array $row) => $this->association($identifier, null, $row['disease'] ?? [], $row), array_filter($block['rows'] ?? [], 'is_array'));
        } else {
            $block = $this->query(self::DISEASE_ASSOCIATIONS, ['id' => self::diseaseId($identifier), 'index' => $index, 'size' => $size])['disease']['associatedTargets'] ?? null;
            $associations = [];
            foreach (array_filter($block['rows'] ?? [], 'is_array') as $row) {
                $target = Identifier::tryOf(Scheme::ENSEMBL, $row['target']['id'] ?? null);
                if (null !== $target) {
                    $associations[] = $this->association($target, self::text($row['target']['approvedSymbol'] ?? null), ['id' => self::diseaseId($identifier)], $row);
                }
            }
        }
        $count = isset($block['count']) ? (int) $block['count'] : null;

        return new Page(array_values($associations), null !== $count && ($index + 1) * $size < $count ? (string) ($index + 1) : null, $count);
    }

    /**
     * One GraphQL query's data; a GraphQL error is a ProviderException.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function query(string $query, array $variables): array
    {
        $answer = $this->postForJson('graphql', ['query' => $query, 'variables' => $variables]) ?? [];
        if (!\is_array($answer['data'] ?? null) && !empty($answer['errors'])) {
            $messages = array_map(static fn (mixed $e) => \is_array($e) ? (string) ($e['message'] ?? '') : (string) $e, (array) $answer['errors']);
            throw new ProviderException('opentargets', 'the query failed: '.implode('; ', array_filter($messages)));
        }

        return \is_array($answer['data'] ?? null) ? $answer['data'] : [];
    }

    /** @param array<string, mixed> $t */
    private function target(array $t): ?Entity
    {
        $id = Identifier::tryOf(Scheme::ENSEMBL, $t['id'] ?? null);
        if (null === $id) {
            return null;
        }
        [$properties, $add] = self::bag();
        $symbol = self::text($t['approvedSymbol'] ?? null);
        $add('gene', 'Gene symbol', $symbol);
        $add('gene_name', 'Gene name', self::text($t['approvedName'] ?? null));
        $add('biotype', 'Biotype', self::text($t['biotype'] ?? null));
        $location = $t['genomicLocation'] ?? null;
        if (\is_array($location) && isset($location['chromosome'], $location['start'], $location['end'])) {
            $add('chromosome', 'Chromosome', (string) $location['chromosome']);
            $add('genomic_location', 'Genomic location', \sprintf('%s:%d-%d (%s strand)', $location['chromosome'], $location['start'], $location['end'], match ($location['strand'] ?? null) { 'POSITIVE' => 'plus', 'NEGATIVE' => 'minus', default => '?' }));
        }
        foreach ((array) ($t['targetClass'] ?? []) as $class) {
            $add('target_class', 'Target class', \is_array($class) ? self::text($class['label'] ?? null) : null);
        }
        foreach ((array) ($t['subcellularLocations'] ?? []) as $place) {
            $add('subcellular_location', 'Subcellular location', \is_array($place) ? self::text($place['location'] ?? null) : null);
        }
        $related = ['disease' => [], 'drug' => [], 'domain' => [], 'pathway' => []];
        $this->associated($t['associatedDiseases'] ?? null, 'disease', $add, $related['disease']);
        $this->drugs($t['drugAndClinicalCandidates'] ?? null, $add, $related['drug']);

        $identifiers = [];
        foreach ((array) ($t['proteinIds'] ?? []) as $protein) {
            if ('uniprot_swissprot' === ($protein['source'] ?? null)) {
                $identifiers[] = Identifier::tryOf(Scheme::UNIPROT, $protein['id'] ?? null);
            }
        }
        foreach ((array) ($t['dbXrefs'] ?? []) as $xref) {
            match ($xref['source'] ?? null) {
                'HGNC' => $identifiers[] = Identifier::tryOf(Scheme::HGNC, $xref['id'] ?? null),
                'InterPro' => $related['domain'][] = Identifier::tryOf(Scheme::INTERPRO, $xref['id'] ?? null),
                'Reactome' => $related['pathway'][] = Identifier::tryOf(Scheme::REACTOME, $xref['id'] ?? null),
                default => null,
            };
        }
        $description = array_values(array_filter(array_map(static fn (mixed $d) => \is_string($d) ? self::text((string) preg_replace('~\s*\.(\s*\.)+\s*$~', '.', $d)) : null, (array) ($t['functionDescriptions'] ?? []))));
        $synonyms = array_values(array_unique(array_filter(array_map(static fn (mixed $s) => \is_array($s) ? self::text($s['label'] ?? null) : null, (array) ($t['synonyms'] ?? [])), static fn (?string $s) => null !== $s && $s !== $symbol)));

        return new Entity(
            id: $id,
            label: $symbol ?? $id->value,
            domain: Domain::BIOLOGY,
            kind: 'target',
            synonyms: $synonyms,
            description: $description ? implode(' ', $description) : null,
            properties: $properties(),
            identifiers: new Identifiers($identifiers),
            url: self::page($id),
            source: 'opentargets',
            related: self::related($related + ['organism' => [Identifier::of(Scheme::NCBI_TAXON, '9606')]]),
        );
    }

    /** @param array<string, mixed> $d */
    private function disease(array $d, Identifier $asked): ?Entity
    {
        $id = self::parse((string) ($d['id'] ?? ''));
        if (null === $id) {
            return null;
        }
        [$properties, $add] = self::bag();
        $synonyms = [];
        foreach ((array) ($d['synonyms'] ?? []) as $group) {
            foreach ((array) ($group['terms'] ?? []) as $term) {
                if ('hasExactSynonym' === ($group['relation'] ?? null) && \is_string($term)) {
                    $synonyms[] = $term;
                }
            }
        }
        $related = ['parent' => [], 'therapeutic_area' => [], 'target' => [], 'drug' => []];
        foreach ((array) ($d['therapeuticAreas'] ?? []) as $area) {
            $add('therapeutic_area', 'Therapeutic area', \is_array($area) ? self::text($area['name'] ?? null) : null);
            $related['therapeutic_area'][] = \is_array($area) ? self::parse((string) ($area['id'] ?? '')) : null;
        }
        foreach ((array) ($d['parents'] ?? []) as $parent) {
            $parentId = \is_array($parent) ? self::parse((string) ($parent['id'] ?? '')) : null;
            $add('parent', 'Parent', \is_array($parent) ? self::text($parent['name'] ?? null).(null === $parentId ? '' : ' ('.$parentId->display().')') : null);
            $related['parent'][] = $parentId;
        }
        $this->associated($d['associatedTargets'] ?? null, 'target', $add, $related['target']);
        $this->drugs($d['drugAndClinicalCandidates'] ?? null, $add, $related['drug']);
        // Its former ids, then the other ontologies' ids for it; the ids of no scheme of the core (MeSH, ICD-10, UMLS, NCIt, DOID...) as properties.
        $identifiers = $id->equals($asked) ? [] : [$asked];
        foreach ((array) ($d['obsoleteTerms'] ?? []) as $obsolete) {
            $identifiers[] = \is_string($obsolete) ? self::parse($obsolete) : null;
        }
        foreach ((array) ($d['dbXRefs'] ?? []) as $xref) {
            $parsed = \is_string($xref) && preg_match('~^(OMIM|Orphanet|MONDO|EFO|HP):~i', $xref) ? Identifier::parse($xref) : null;
            if (null !== $parsed) {
                $identifiers[] = $parsed;
            } elseif (\is_string($xref)) {
                $add('cross_reference', 'Cross-reference', $xref);
            }
        }

        return new Entity(
            id: $id,
            label: self::text($d['name'] ?? null) ?? $id->display(),
            domain: Domain::BIOLOGY,
            kind: Scheme::HP === $id->scheme ? 'phenotype' : 'disease',
            synonyms: array_values(array_unique(array_diff($synonyms, [(string) ($d['name'] ?? '')]))),
            description: self::text($d['description'] ?? null),
            properties: $properties(),
            identifiers: new Identifiers($identifiers),
            url: self::page($id),
            source: 'opentargets',
            related: self::related($related),
        );
    }

    /** @param array<string, mixed> $d */
    private function drug(array $d): ?Entity
    {
        $id = Identifier::tryOf(Scheme::CHEMBL, $d['id'] ?? null);
        if (null === $id) {
            return null;
        }
        [$properties, $add] = self::bag();
        $add('drug_type', 'Drug type', self::text($d['drugType'] ?? null));
        $add('maximum_clinical_stage', 'Maximum clinical stage', self::stage($d['maximumClinicalStage'] ?? null));
        $names = [];
        foreach ((array) ($d['tradeNames'] ?? []) as $trade) {
            $add('trade_name', 'Trade name', $name = \is_array($trade) ? self::text($trade['label'] ?? null) : null);
            $names[] = $name;
        }
        foreach ((array) ($d['synonyms'] ?? []) as $synonym) {
            $names[] = \is_array($synonym) ? self::text($synonym['label'] ?? null) : null;
        }
        $related = ['target' => [], 'indication' => [], 'parent' => [self::parse((string) ($d['parentMolecule']['id'] ?? ''))]];
        foreach ((array) ($d['mechanismsOfAction']['rows'] ?? []) as $row) {
            $add('mechanism_of_action', 'Mechanism of action', self::text($row['mechanismOfAction'] ?? null));
            $add('action_type', 'Action type', self::text($row['actionType'] ?? null));
            foreach ((array) ($row['targets'] ?? []) as $target) {
                $related['target'][] = \is_array($target) ? Identifier::tryOf(Scheme::ENSEMBL, $target['id'] ?? null) : null;
            }
        }
        $indications = array_values(array_filter((array) ($d['indications']['rows'] ?? []), 'is_array'));
        usort($indications, static fn (array $a, array $b) => self::rank($a['maxClinicalStage'] ?? null) <=> self::rank($b['maxClinicalStage'] ?? null));
        foreach ($indications as $row) {
            $disease = self::parse((string) ($row['disease']['id'] ?? ''));
            $add('indication', 'Indication', self::text($row['disease']['name'] ?? null).' ('.($disease?->display() ?? ($row['disease']['id'] ?? '?')).'): '.self::stage($row['maxClinicalStage'] ?? null));
            $related['indication'][] = $disease;
        }
        $label = self::text($d['name'] ?? null) ?? $id->value;

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::CHEMISTRY,
            kind: 'drug',
            synonyms: array_values(array_unique(array_filter($names, static fn (?string $n) => null !== $n && strcasecmp($n, $label) !== 0))),
            description: self::text($d['description'] ?? null),
            properties: $properties(),
            url: self::page($id),
            source: 'opentargets',
            related: self::related($related),
        );
    }

    /**
     * The best associations of an entity as properties ("Association with
     * beta thalassemia": 0.842), and the associated things in $related.
     *
     * @param list<?Identifier> $related
     */
    private function associated(mixed $block, string $side, \Closure $add, array &$related): void
    {
        if (!\is_array($block)) {
            return;
        }
        $add('disease' === $side ? 'associated_diseases' : 'associated_targets', 'disease' === $side ? 'Associated diseases' : 'Associated targets', isset($block['count']) ? (int) $block['count'] : null);
        foreach (\array_slice(array_values(array_filter((array) ($block['rows'] ?? []), 'is_array')), 0, max(0, $this->associations)) as $row) {
            $thing = (array) ($row[$side] ?? []);
            $id = 'disease' === $side ? self::parse((string) ($thing['id'] ?? '')) : Identifier::tryOf(Scheme::ENSEMBL, $thing['id'] ?? null);
            $name = self::text($thing['disease' === $side ? 'name' : 'approvedSymbol'] ?? null) ?? (string) ($thing['id'] ?? '?');
            if (is_numeric($row['score'] ?? null)) {
                $add('association', 'Association with '.$name, (float) $row['score']);
            }
            $related[] = $id;
        }
    }

    /**
     * The drugs and clinical candidates, the furthest in the clinic first:
     * as properties up to the configured number, every one in $related.
     *
     * @param list<?Identifier> $related
     */
    private function drugs(mixed $block, \Closure $add, array &$related): void
    {
        if (!\is_array($block)) {
            return;
        }
        $add('drugs', 'Drugs and clinical candidates', isset($block['count']) ? (int) $block['count'] : null);
        $rows = array_values(array_filter((array) ($block['rows'] ?? []), static fn (mixed $r) => \is_array($r) && \is_array($r['drug'] ?? null)));
        usort($rows, static fn (array $a, array $b) => self::rank($a['maxClinicalStage'] ?? null) <=> self::rank($b['maxClinicalStage'] ?? null));
        foreach ($rows as $i => $row) {
            $drug = Identifier::tryOf(Scheme::CHEMBL, $row['drug']['id'] ?? null);
            if ($i < max(0, $this->associations)) {
                $add('drug', 'Drug or clinical candidate', (self::text($row['drug']['name'] ?? null) ?? '?').' ('.($drug?->value ?? '?').'): '.self::stage($row['maxClinicalStage'] ?? null));
            }
            $related[] = $drug;
        }
    }

    /** @param array<string, mixed> $thing a disease, with its id */
    private function association(Identifier $target, ?string $targetName, array $thing, array $row): Association
    {
        $datatypes = [];
        foreach ((array) ($row['datatypeScores'] ?? []) as $score) {
            if (\is_array($score) && isset($score['id']) && is_numeric($score['score'] ?? null) && $score['score'] > 0) {
                $datatypes[(string) $score['id']] = (float) $score['score'];
            }
        }
        arsort($datatypes);

        return new Association($target, $targetName, self::parse((string) ($thing['id'] ?? '')), (string) ($thing['id'] ?? ''), self::text($thing['name'] ?? null), (float) ($row['score'] ?? 0), $datatypes);
    }

    /** A disease as Open Targets names it: MONDO_0011382, Orphanet_232. */
    private static function diseaseId(Identifier $disease): string
    {
        return self::DISEASES[$disease->scheme->value].$disease->value;
    }

    /** An id Open Targets writes (ENSG..., MONDO_0011382, Orphanet_232, CHEMBL...) in a scheme of the core; null for the others (OTAR_...). */
    private static function parse(string $id): ?Identifier
    {
        $parsed = '' === $id ? null : Identifier::parse($id);

        return null !== $parsed && \in_array($parsed->scheme, [Scheme::ENSEMBL, Scheme::EFO, Scheme::MONDO, Scheme::HP, Scheme::ORPHANET, Scheme::CHEMBL, Scheme::GO], true) ? $parsed : null;
    }

    /** The platform's page of a target, a disease or a drug. */
    private static function page(Identifier $id): string
    {
        return match ($id->scheme) {
            Scheme::ENSEMBL => 'https://platform.opentargets.org/target/'.$id->value,
            Scheme::CHEMBL => 'https://platform.opentargets.org/drug/'.$id->value,
            default => 'https://platform.opentargets.org/disease/'.(self::DISEASES[$id->scheme->value] ?? strtoupper($id->scheme->value).'_').$id->value,
        };
    }

    /** "PHASE_2_3" as "phase 2/3", "APPROVAL" as "approval". */
    private static function stage(mixed $stage): ?string
    {
        if (!\is_string($stage) || '' === $stage) {
            return null;
        }

        return strtolower((string) preg_replace(['~^PHASE_(\d)_(\d)$~', '~_~'], ['phase $1/$2', ' '], $stage));
    }

    private static function rank(mixed $stage): int
    {
        $rank = array_search($stage, self::STAGES, true);

        return false === $rank ? \count(self::STAGES) : $rank;
    }

    /**
     * A list of properties and the closure that adds to it.
     *
     * @return array{0: \Closure(): list<Property>, 1: \Closure(string, string, mixed): void}
     */
    private static function bag(): array
    {
        $properties = new \ArrayObject();

        return [
            static fn (): array => $properties->getArrayCopy(),
            static function (string $key, string $label, mixed $value) use ($properties): void {
                if (null !== $value && '' !== $value) {
                    $properties[] = new Property($key, $label, $value, 'opentargets');
                }
            },
        ];
    }

    /**
     * @param array<string, list<?Identifier>> $related
     *
     * @return array<string, Identifiers>
     */
    private static function related(array $related): array
    {
        return array_filter(array_map(static fn (array $ids) => new Identifiers($ids), $related), static fn (Identifiers $ids) => \count($ids) > 0);
    }
}
