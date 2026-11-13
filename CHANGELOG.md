# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Open Targets: a target by its Ensembl gene, a disease or phenotype by its EFO, Mondo, HPO
  or Orphanet id, a drug by its ChEMBL id - names, descriptions, locations, classes, therapeutic
  areas, parents, mechanisms of action, indications - their associations with scores, drugs and
  clinical candidates, UniProt, HGNC, OMIM and Orphanet ids, InterPro domains and Reactome pathways,
  a search, and the associations paged, through the Open Targets Platform's GraphQL API, no key
  needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/opentargets` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
