<?php

namespace Omnilab\OpenTargets\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\OpenTargets\OpenTargetsSource;
use Omnilab\OpenTargets\OpenTargetsSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.platform.opentargets.org on 2026-10-15 (API
 * 26.9.0, data 26.09): HBB as a target (ENSG00000244734) with its ten best
 * diseases and its four drugs, sickle cell disease (MONDO_0011382) with its
 * ten best targets and its 163 drugs, voxelotor (CHEMBL4101807), an
 * unknown target; the search for "sickle cell" in two pages of three (264
 * hits), and for a text nothing matches; HBB's diseases from the sixth
 * (page 1 of five), sickle cell disease's first five targets; a query
 * GraphQL refused (400).
 */
final class OpenTargetsSourceTest extends TestCase
{
    /** @var list<string> the operations asked, with their variables */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): OpenTargetsSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.platform.opentargets.org/api/v4/graphql', $url);
            $body = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);
            preg_match('~^query (\w+)~', $body['query'], $m);
            $operation = $m[1].(str_contains($body['query'], 'target(ensemblId') ? '-target' : (str_contains($body['query'], 'disease(efoId') ? '-disease' : ''));
            $this->calls[] = $call = $operation.' '.json_encode($body['variables']);
            if ($response) {
                return $response;
            }
            $v = $body['variables'];
            $fixture = match ($m[1]) {
                'target' => 'ENSG00000244734' === $v['id'] ? 'target-ENSG00000244734.json' : 'target-notfound.json',
                'disease' => 'MONDO_0011382' === $v['id'] ? 'disease-MONDO_0011382.json' : null,
                'drug' => 'CHEMBL4101807' === $v['id'] ? 'drug-CHEMBL4101807.json' : null,
                'search' => 'sickle cell' === $v['q'] ? 'search-sickle-'.$v['index'].'.json' : 'search-notfound.json',
                'associations' => 'associations-'.$v['id'].'-'.$v['index'].'.json',
            };

            return new MockResponse(null === $fixture ? '{"data":{"'.$m[1].'":null}}' : (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new OpenTargetsSourceFactory($http))->create($options);
        self::assertInstanceOf(OpenTargetsSource::class, $source);

        return $source;
    }

    public function testHbbAsATarget(): void
    {
        $target = $this->source()->entity('ENSG00000244734');

        self::assertSame('ensembl:ENSG00000244734', $target->id->key());
        self::assertSame('HBB', $target->label);
        self::assertSame(Domain::BIOLOGY, $target->domain);
        self::assertSame('target', $target->kind);
        self::assertSame('https://platform.opentargets.org/target/ENSG00000244734', $target->url);
        self::assertStringStartsWith('Involved in oxygen transport from the lung to the various peripheral tissues. LVV-hemorphin-7', (string) $target->description, 'the stray " ." taken off');
        self::assertSame('hemoglobin subunit beta', $target->value('gene_name'));
        self::assertSame('protein_coding', $target->value('biotype'));
        self::assertSame('11:5225464-5229395 (minus strand)', $target->value('genomic_location'));
        self::assertSame('Secreted protein', $target->value('target_class'));
        self::assertSame(552, $target->value('associated_diseases'));
        self::assertSame('Association with beta thalassemia', $target->property('association')->label);
        self::assertEqualsWithDelta(0.842, $target->value('association'), 0.001);
        self::assertCount(10, $target->properties('association'));
        self::assertSame(['mondo:0019402', 'mondo:0011382'], \array_slice($target->related('disease')->keys(), 0, 2));
        self::assertSame(4, $target->value('drugs'));
        self::assertSame(['EFAPROXIRAL (CHEMBL18901): approval', 'LOVOTIBEGLOGENE AUTOTEMCEL (CHEMBL4650269): approval', 'VOXELOTOR (CHEMBL4101807): withdrawal', 'BETIBEGLOGENE AUTOTEMCEL (CHEMBL4594311): withdrawal'], array_map('strval', $target->properties('drug')), 'the furthest in the clinic first');
        self::assertCount(4, $target->related('drug'));
        self::assertSame(['ensembl:ENSG00000244734', 'uniprot:P68871', 'hgnc:4827'], $target->identifiers()->keys());
        self::assertCount(5, $target->related('domain'));
        self::assertContains('interpro:IPR000971', $target->related('domain')->keys());
        self::assertCount(10, $target->related('pathway'));
        self::assertSame(['ncbi.taxon:9606'], $target->related('organism')->keys());
        self::assertSame(['target-target {"id":"ENSG00000244734","size":10}'], $this->calls);
    }

    public function testSickleCellDisease(): void
    {
        $disease = $this->source(null, ['associations' => 5])->entity('MONDO:0011382');

        self::assertSame('mondo:0011382', (string) $disease->id);
        self::assertSame('sickle cell disease', $disease->label);
        self::assertSame('disease', $disease->kind);
        self::assertStringStartsWith('Sickle cell anemias are chronic hemolytic diseases', (string) $disease->description);
        self::assertContains('sickle cell anemia', $disease->synonyms);
        self::assertSame('https://platform.opentargets.org/disease/MONDO_0011382', $disease->url);
        self::assertSame(['genetic, familial or congenital disease', 'hematologic disorder'], array_map('strval', $disease->properties('therapeutic_area')));
        self::assertSame(['mondo:0005570'], $disease->related('therapeutic_area')->keys(), 'OTAR_0000018 is in no scheme');
        self::assertSame(['inherited hemoglobinopathy (MONDO:0019050)', 'autosomal recessive disease (MONDO:0006025)'], array_map('strval', $disease->properties('parent')));
        self::assertSame(['mondo:0019050', 'mondo:0006025'], $disease->related('parent')->keys());
        self::assertSame(['mondo:0011382', 'efo:0000697', 'orphanet:232', 'omim:603903'], $disease->identifiers()->keys(), 'its former ids, then the other ontologies\'');
        self::assertContains('MESH:D000755', array_map('strval', $disease->properties('cross_reference')));
        self::assertSame(1643, $disease->value('associated_targets'));
        self::assertSame('Association with HBB', $disease->property('association')->label);
        self::assertCount(5, $disease->properties('association'));
        self::assertSame('ensembl:ENSG00000244734', (string) $disease->related('target')->all()[0]);
        self::assertSame(163, $disease->value('drugs'));
        self::assertCount(5, $disease->properties('drug'));
        self::assertStringEndsWith(': approval', (string) $disease->property('drug'));
        self::assertCount(163, $disease->related('drug'));
        self::assertSame(['disease-disease {"id":"MONDO_0011382","size":5}'], $this->calls, 'Open Targets writes MONDO_0011382');
    }

    public function testVoxelotor(): void
    {
        $drug = $this->source()->entity('CHEMBL4101807');

        self::assertSame('chembl:CHEMBL4101807', (string) $drug->id);
        self::assertSame('VOXELOTOR', $drug->label);
        self::assertSame(Domain::CHEMISTRY, $drug->domain);
        self::assertSame('drug', $drug->kind);
        self::assertSame('Small molecule', $drug->value('drug_type'));
        self::assertSame('approval', $drug->value('maximum_clinical_stage'));
        self::assertSame('Oxbryta', $drug->value('trade_name'));
        self::assertSame(['Oxbryta', 'GBT-440', 'GBT440', 'GTX-011', 'Gbt440'], $drug->synonyms);
        self::assertSame('Hemoglobin HbA positive modulator', $drug->value('mechanism_of_action'));
        self::assertSame('POSITIVE MODULATOR', $drug->value('action_type'));
        self::assertSame(['ensembl:ENSG00000206172', 'ensembl:ENSG00000188536', 'ensembl:ENSG00000244734'], $drug->related('target')->keys());
        self::assertSame('Hemolytic anemia (HP:0001878): approval', $drug->value('indication'));
        self::assertCount(7, $drug->related('indication'));
        self::assertSame('https://platform.opentargets.org/drug/CHEMBL4101807', $drug->url);
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('ENSG00000000000'));
        self::assertNull($this->source()->entity('EFO_0000000'));
        self::assertNull($this->source()->entity('CHEMBL1'));
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach (['ENSP00000333994', 'P68871', 'CHEBI:15422', 'GO:0005829', 'OTAR_0000018', 'sickle cell disease', Identifier::of(Scheme::OMIM, '603903')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testASearchInPages(): void
    {
        $source = $this->source();
        $first = $source->search(new Query('sickle cell', limit: 3));

        self::assertSame(['mondo:0011382', 'mondo:0016669', 'mondo:0016670'], array_map(static fn ($e) => (string) $e->id, $first->items));
        self::assertSame('sickle cell disease', $first->first()->label);
        self::assertSame('disease', $first->first()->kind);
        self::assertSame(264, $first->total);
        self::assertSame('1', $first->next);
        $second = $source->search(new Query('sickle cell', limit: 3, cursor: $first->next));
        self::assertGreaterThan(0, \count($second));
        self::assertCount(0, $source->search(new Query('zzqqxx')));
        self::assertCount(0, $source->search(new Query('sickle cell', Domain::ASTRONOMY)));
        self::assertCount(3, $this->calls);
    }

    public function testAssociationsInPages(): void
    {
        $source = $this->source();
        $page = $source->associations('ENSG00000244734', 5, '1');

        self::assertSame(552, $page->total);
        self::assertSame('2', $page->next);
        self::assertCount(5, $page);
        self::assertSame('ensembl:ENSG00000244734', (string) $page->first()->target);
        self::assertSame('mondo:0013517', (string) $page->first()->disease, 'the sixth: beta-thalassemia HBB/LCRB');
        self::assertSame('beta-thalassemia HBB/LCRB', $page->first()->diseaseName);
        self::assertGreaterThan(0.7, $page->first()->score);
        self::assertArrayHasKey('genetic_association', $page->first()->datatypes);

        $targets = $source->associations('MONDO_0011382', 5);
        self::assertSame(1643, $targets->total);
        self::assertSame('HBB', $targets->first()->targetName);
        self::assertSame('mondo:0011382', (string) $targets->first()->disease);
        $scores = array_values($targets->first()->datatypes);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores, 'the strongest data type first');
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/error-400.json'), ['http_code' => 400]))->entity('ENSG00000244734');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('must have a sub selection', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('{"errors":[{"message":"Internal error"}]}'))->entity('ENSG00000244734');
            self::fail('a GraphQL error answered 200');
        } catch (ProviderException $e) {
            self::assertStringContainsString('Internal error', $e->getMessage());
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('ENSG00000244734');
    }

    public function testTheFactory(): void
    {
        $factory = new OpenTargetsSourceFactory(new MockHttpClient());

        self::assertSame('opentargets', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $factory->create()->capabilities());
    }
}
