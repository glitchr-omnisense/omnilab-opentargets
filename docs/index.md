# omnilab/opentargets

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

## Verified, and not

Against the real service on 2026-10-15 (API 26.9.0, data 26.09): HBB as a target (552 associated
diseases, beta thalassemia first at 0.842, four drugs), sickle cell disease (1,643 associated
targets, HBB first; 163 drugs; its former ids EFO_0000697 and Orphanet_232), voxelotor (Oxbryta;
HBA1, HBA2, HBB; seven indications), an unknown target (`null`), the search for "sickle cell" (264
hits, two pages), the associations paged both ways, a malformed query (400 and its messages), the
Collector's walk above. The fixtures in `Tests/Fixtures/` are its answers. Each call took 0.3 to
0.4 s.

Not verified: a GraphQL error answered 200 (the code reads one; none was met); a rate limit (none
published, none met).
