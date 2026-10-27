# omnilab/opentargets

The Open Targets Platform's GraphQL API (`https://api.platform.opentargets.org/api/v4/graphql`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): targets (Ensembl genes), diseases
(EFO, Mondo, HPO, Orphanet) and drugs (ChEMBL), with their associations and scores, drugs and
clinical candidates, domains, pathways, mechanisms and indications as related things, as entities;
a search; outside the contract, the associations paged. No key.

## Installation

```sh
composer require omnilab/opentargets
```

```php
use Omnilab\OpenTargets\OpenTargetsSourceFactory;

$ot = (new OpenTargetsSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'associations' => 10,              // optional: associations and drugs an entity holds as properties
]);
```

## Credentials

None: the Open Targets Platform's GraphQL API takes no key and no account.

## Options

The factory's options, with the defaults it applies (`OpenTargetsSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `associations` | `int` | `10` | no | how many associations (and drugs) an entity holds as properties, the best first; their counts are kept whatever it is |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: Open Targets publishes no rate |
| `base_uri` | `string` | `https://api.platform.opentargets.org/api/v4/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a target (an Ensembl gene), a disease or phenotype (Mondo, EFO, HPO, Orphanet), a drug (ChEMBL); one GraphQL call; another base refused before any call |
| `search` | yes | Open Targets' search over targets, diseases and drugs, 100 a page at most, with a `total` |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | a target's Swiss-Prot accession and HGNC id; a disease's former ids and its OMIM, Orphanet, EFO, Mondo or HPO cross-references |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\OpenTargets\OpenTargetsSource`: `associations(Identifier|string
$id, int $limit = 25, ?string $cursor = null): Page` - a target's or a disease's associations, the
best scored first, as `Association` objects ([below](#outside-the-contract-associations)).

## The schemes

| Thing | Scheme | Read from | Open Targets writes |
|---|---|---|---|
| a target | `ensembl` (genes, `ENSG...`) | its shape | `ENSG00000244734` |
| a disease or a phenotype | `mondo`, `efo`, `hp`, `orphanet` | `MONDO:0011382`, OBO's `MONDO_0011382`, `Orphanet_232`, `ORPHA:232` | `MONDO_0011382`, `EFO_0000305`, `HP_0001878`, `Orphanet_232` |
| a drug | `chembl` | its shape | `CHEMBL4101807` |

An Ensembl transcript or protein, Open Targets' own therapeutic areas (`OTAR_...`) and any other
base's identifier are refused before any call.

## Which API

`https://api.platform.opentargets.org/api/v4/graphql` (API 26.9.0, data release 26.09 on
2026-10-15; `meta { apiVersion dataVersion }` gives them): one POST a call, its query and
variables in JSON.

| Method | Query |
|---|---|
| `entity()`, a target | `target(ensemblId)`: symbol, name, biotype, function, synonyms, location, protein ids, cross-references, subcellular locations, class, `associatedDiseases` (first page, `associations` rows), `drugAndClinicalCandidates` |
| `entity()`, a disease | `disease(efoId)`: name, description, synonyms, therapeutic areas, parents, `dbXRefs`, `obsoleteTerms`, `associatedTargets`, `drugAndClinicalCandidates` |
| `entity()`, a drug | `drug(chemblId)`: type, maximum clinical stage, description, synonyms, trade names, parent, mechanisms of action, indications |
| `search()` | `search(queryString, entityNames: [target, disease, drug], page)` |
| `associations()` | `target { associatedDiseases(page) }` or `disease { associatedTargets(page) }` |

A GraphQL error is a `ProviderException` with its messages (Open Targets answers a malformed query
400, the errors in its body). An unknown id is `null` (`"target": null`).

## What an entity holds

A target: `id` (`ensembl:ENSG00000244734`), `label` (its symbol), `kind` `target`, `synonyms`,
`description` (its function, UniProt's), `url` (its page on the platform), and `gene`,
`gene_name`, `biotype`, `chromosome`, `genomic_location` (`11:5225464-5229395 (minus strand)`,
omnilab/ensembl's form), `target_class`, `subcellular_location`, `associated_diseases` (how many),
`association` (one per disease, the best first, up to `associations`: labelled `Association with
beta thalassemia`, its score 0 to 1), `drugs` (how many), `drug` (up to `associations`, the
furthest in the clinic first: `EFAPROXIRAL (CHEMBL18901): approval`). `identifiers`: its own, its
Swiss-Prot accession, its HGNC id. `related`: `disease` (the associated ones shown), `drug` (all),
`domain` (its InterPro entries), `pathway` (its Reactome pathways), `organism`. Its PDB structures
(348 for HBB) and DrugBank ids are left out.

A disease: `id` (`mondo:0011382`), `label`, `kind` (`disease`, `phenotype` for an HPO term),
`synonyms` (the exact ones), `description`, `url`, and `therapeutic_area`, `parent` (`inherited
hemoglobinopathy (MONDO:0019050)`), `associated_targets`, `association` (`Association with HBB`),
`drugs`, `drug`, `cross_reference` (the ids of no scheme of the core: `MESH:D000755`,
`ICD10CM:D57.2`, `UMLS:...`, `DOID:...`). `identifiers`: its own, the id it was asked by when Open
Targets answered another, its former ids (`obsoleteTerms`: `efo:0000697`, `orphanet:232`), its OMIM,
Orphanet, EFO, Mondo or HPO cross-references. `related`: `parent`, `therapeutic_area` (Open Targets'
`OTAR_` ones left out), `target`, `drug`.

A drug: `id` (`chembl:CHEMBL4101807`), `label` (Open Targets' name, upper case: `VOXELOTOR`),
`kind` `drug`, domain chemistry, `synonyms` (trade names and synonyms), `description`, `url`, and
`drug_type`, `maximum_clinical_stage` (`approval`, `phase 2/3`...), `trade_name`,
`mechanism_of_action`, `action_type`, `indication` (`sickle cell disease (MONDO:0011382):
approval`). `related`: `target` (the mechanisms' targets), `indication`, `parent`.

A clinical stage is Open Targets' word in lower case (`APPROVAL` is `approval`, `PHASE_2_3`
`phase 2/3`); the order is approval, withdrawal, phase 4 to early phase 1, preclinical, unknown.
A drug's stage for a target or a disease is that pair's (voxelotor: `withdrawal` for HBB, the drug
itself `approval`, Oxbryta having been withdrawn).

## Outside the contract: associations()

An association is a target, a disease and a score: `associations($id, $limit, $cursor)` gives a
`Page` of `Association` objects - `target`, `targetName`, `disease` (null for an id of no scheme),
`diseaseId` (as Open Targets writes it), `diseaseName`, `score`, `datatypes` (each data type's score:
`genetic_association`, `genetic_literature`, `animal_model`, `literature`, `clinical`,
`somatic_mutation`..., the strongest first) - its `next` the next page's index, its `total` Open
Targets' count.

```php
$page = $ot->associations('MONDO_0011382', 5);   // 1,643 targets on 2026-10-15
foreach ($page as $a) {
    printf("%s %.3f %s\n", $a->targetName, $a->score, implode(', ', array_keys($a->datatypes)));
}
```

## The Collector: HBB from HGNC into Open Targets

HGNC gives HBB's Ensembl gene; Open Targets reads it:

```php
$collector->collect('hgnc', 'HBB', 1, ['opentargets']);
```

Against the real services on 2026-10-15: two calls in 0.7 s, `sources` = hgnc, opentargets; the
associations, drugs, domains and pathways joined.

## What the API does not give here

The evidence behind an association (`evidences`), the genetics (variants, studies, credible sets:
Open Targets' genetics, `variant`, `study`), expression, safety liabilities, tractability, chemical
probes, mouse phenotypes, pharmacogenomics, adverse events, the literature (`literatureOcurrences`),
the clinical reports behind a drug's stage, and indirect associations (through descendants).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\OpenTargets\OpenTargetsSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$ot = (new OpenTargetsSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'associations' => 3]);

$scd = $ot->entity('MONDO_0011382');
echo $scd->label, ' (', $scd->id->display(), '): ', $scd->value('associated_targets'), ' targets, ', $scd->value('drugs'), " drugs; the strongest associations:\n";
foreach ($scd->properties('association') as $association) {
    echo '  ', $association->label, ' ', $association, "\n";
}

foreach ($ot->associations('MONDO_0011382', 2) as $a) {
    printf("  %s %.3f %s\n", $a->targetName, $a->score, implode(', ', array_slice(array_keys($a->datatypes), 0, 3)));
}

$page = $ot->search(new Query('sickle cell', limit: 3));
printf("%d hits for \"sickle cell\"; the first: %s %s (%s)\n", $page->total, $page->first()->id->display(), $page->first()->label, $page->first()->kind);
```

```
sickle cell disease (MONDO:0011382): 1643 targets, 163 drugs; the strongest associations:
  Association with HBB 0.8286564502
  Association with BCL11A 0.6414482538
  Association with RRM2B 0.5946157766
  HBB 0.829 clinical, genetic_literature, genetic_association
  BCL11A 0.641 clinical, genetic_association, literature
264 hits for "sickle cell"; the first: MONDO:0011382 sickle cell disease (disease)
```

(as answered on 2026-10-27: three GraphQL calls)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        opentargets: { factory: opentargets, options: { mailto: '%env(OMNILAB_MAILTO)%', associations: 10 } }
```

`associations()` is a method of `OpenTargetsSource`, not of the interface the bundle injects:
check the instance before calling it.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Open Targets' own: a malformed query is its 400, a `ProviderException` with its messages; errors
in a 200 without data a `ProviderException` too ("the query failed: ..."); errors beside data are
not read (the data answer). An unknown id is
`"target": null`, null. Before any call: an Ensembl transcript or protein, an `OTAR_` therapeutic
area, any other base's identifier (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15 (API 26.9.0, data 26.09): HBB as a target (552 associated
diseases, beta thalassemia first at 0.842, four drugs), sickle cell disease (1,643 associated
targets, HBB first; 163 drugs; its former ids EFO_0000697 and Orphanet_232), voxelotor (Oxbryta;
HBA1, HBA2, HBB; seven indications), an unknown target (`null`), the search for "sickle cell" (264
hits, two pages), the associations paged both ways, a malformed query (400 and its messages), the
Collector's walk above. The fixtures in `Tests/Fixtures/` are its answers. Each call took 0.3 to
0.4 s.

Again on 2026-10-27: sickle cell disease (1,643 targets, 163 drugs, HBB first), its associations
paged, the search for "sickle cell" (264 hits; the example above).

Not verified: a GraphQL error answered 200 (the code reads one; none was met); a rate limit (none
published, none met).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Open Targets. The platform's data are marked [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/)
and the platform asks to be cited (Buniello A. et al., Nucleic Acids Res. 2025,
10.1093/nar/gkae1128), as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-opentargets#terms-of-use) says. Whoever
runs the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-opentargets#responsibility) section.
