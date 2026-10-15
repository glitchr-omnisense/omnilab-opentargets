# omnilab/opentargets

The Open Targets Platform for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab), through
its GraphQL API: a target by its Ensembl gene (`ENSG00000244734`, HBB) with its symbol, name,
biotype, function, location and class, its UniProt and HGNC ids, its best associated diseases with
their scores, its drugs and clinical candidates, its InterPro domains and Reactome pathways; a
disease or phenotype by its EFO, Mondo, HPO or Orphanet id (`MONDO_0011382`, sickle cell disease)
with its description, synonyms, therapeutic areas, parents, its OMIM and Orphanet ids, its best
associated targets and its drugs; a drug by its ChEMBL id with its type, clinical stage,
mechanisms of action and their targets, indications and trade names; a search over the three; the
associations themselves, paged. No key.

```php
$ot = (new OpenTargetsSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$hbb = $ot->entity('ENSG00000244734');
$hbb->property('association');            // Association with beta thalassemia: 0.842
$hbb->related('disease')->keys();         // mondo:0019402, mondo:0011382, ... (10 of 552)
$hbb->related('drug')->keys();            // chembl:CHEMBL18901, chembl:CHEMBL4650269, ...
$scd = $ot->entity('MONDO_0011382');      // or 'MONDO:0011382'
$scd->identifiers()->keys();              // mondo:0011382, efo:0000697, orphanet:232, omim:603903
$ot->entity('CHEMBL4101807')->value('trade_name');   // Oxbryta
$ot->associations('ENSG00000244734', 5, '1');         // Association objects: scores by data type
$ot->search(new Query('sickle cell', limit: 3));     // 264 hits
```

```yaml
omnilab:
    sources:
        opentargets:
            factory: opentargets
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                associations: 10               # associations and drugs an entity holds as properties
```

Diseases are named in the core's `efo`, `mondo`, `hp` and `orphanet` schemes, targets in
`ensembl`, drugs in `chembl`. The `Collector` reaches Open Targets from any source that gives an
Ensembl gene (`$collector->collect('hgnc', 'HBB')`) or a ChEMBL id.

## Terms of use

Open Targets Platform data are marked [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/)
([platform-docs.opentargets.org/licence](https://platform-docs.opentargets.org/licence)); cite
Buniello A. et al., "Open Targets Platform: facilitating therapeutic hypotheses building in drug
discovery", Nucleic Acids Res. 2025, [10.1093/nar/gkae1128](https://doi.org/10.1093/nar/gkae1128).
No rate is published.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Open Targets consortium. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
