<?php

namespace Omnilab\OpenTargets;

use Omnilab\Model\Identifier;

/**
 * One association of Open Targets: a target (an Ensembl gene) and a disease
 * or phenotype (EFO, Mondo, HPO, Orphanet), the overall score (0 to 1, the
 * harmonic sum of the evidence) and the score of each data type that
 * supports it (genetic_association, somatic_mutation, known_drug,
 * affected_pathway, literature, rna_expression, animal_model,
 * genetic_literature, clinical...).
 */
final readonly class Association
{
    /**
     * @param Identifier|null      $disease  null when the disease's id is in no scheme of the core (OTAR_...)
     * @param array<string, float> $datatypes the data types' scores, the strongest first
     */
    public function __construct(
        public Identifier $target,
        public ?string $targetName,
        public ?Identifier $disease,
        public string $diseaseId,
        public ?string $diseaseName,
        public float $score,
        public array $datatypes = [],
    ) {
    }
}
