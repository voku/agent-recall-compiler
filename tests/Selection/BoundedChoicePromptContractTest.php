<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Tests\Selection;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Selection\BoundedChoicePromptBuilder;
use voku\AgentRecallCompiler\Selection\BoundedChoiceValidator;

final class BoundedChoicePromptContractTest extends TestCase
{
    private BoundedChoicePromptBuilder $builder;
    private BoundedChoiceValidator $validator;

    protected function setUp(): void
    {
        $this->builder = new BoundedChoicePromptBuilder();
        $this->validator = new BoundedChoiceValidator();
    }

    public function testBuilderCreatesBoundedPromptWithCandidateIndicesAndNoneOption(): void
    {
        $candidates = [
            [
                'id' => 'reproduce-before-fix',
                'title' => 'Reproduce before fixing',
                'description' => 'Require automated reproduction before changing code.',
                'purpose' => 'start',
            ],
            [
                'id' => 'deletion-first',
                'title' => 'Simplify deletion first',
                'description' => 'Prefer safe deletion before extension.',
                'purpose' => 'simplify',
            ],
        ];

        $prompt = $this->builder->build('Fix flaky test in order processing', $candidates);

        self::assertStringContainsString('Fix flaky test in order processing', $prompt);
        self::assertStringContainsString('[cand_0] id: "reproduce-before-fix" (purpose: start)', $prompt);
        self::assertStringContainsString('[cand_1] id: "deletion-first" (purpose: simplify)', $prompt);
        self::assertStringContainsString('[none] None of the candidates above apply', $prompt);
        self::assertStringContainsString('{"selected": "cand_<index>"} or {"selected": "none"}', $prompt);
    }

    public function testBuilderRejectsEmptyTaskIntent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build('   ', []);
    }

    public function testOptInApplicabilityContractDistinguishesWorkflowFromTopicalOverlap(): void
    {
        $candidates = [
            [
                'id' => 'multi-pass-correctness-simplify',
                'title' => 'Implement, audit, simplify',
                'description' => 'Implement requested change, audit its correctness, then simplify.',
                'purpose' => 'execute',
            ],
        ];
        $task = 'Implement a CSS layout in the existing application.';
        $baseline = $this->builder->build($task, $candidates);
        $experiment = $this->builder->build($task, $candidates, enforceWorkflowApplicability: true);

        self::assertStringNotContainsString('WORKFLOW_APPLICABILITY_EXPERIMENT', $baseline);
        self::assertStringContainsString('WORKFLOW_APPLICABILITY_EXPERIMENT', $experiment);
        self::assertStringContainsString('not merely because the task uses related technical vocabulary', $experiment);
        self::assertStringContainsString('the requested workflow or method', $experiment);
        self::assertStringContainsString('A generic process recipe may apply across languages and stacks', $experiment);
        self::assertStringContainsString('select "none"', $experiment);
        self::assertStringContainsString('{"selected": "cand_<index>"}', $experiment);
    }

    public function testOptInApplicabilityContractNeverChangesCandidateIdentities(): void
    {
        $candidate = [
            'id' => 'adversarial-review',
            'title' => 'Adversarial review',
            'description' => 'Challenge implementation with concrete counterexamples.',
            'purpose' => 'review',
        ];
        $prompt = $this->builder->build(
            'Review the code for bypasses and try to falsify the current assumptions.',
            [$candidate],
            enforceWorkflowApplicability: true,
        );

        self::assertStringContainsString('[cand_0] id: "adversarial-review"', $prompt);
        self::assertStringContainsString('[none]', $prompt);
        self::assertStringNotContainsString('[cand_1]', $prompt);
    }

    public function testValidatorParsesJsonCandIndexCorrectly(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        $selected = $this->validator->validate('{"selected": "cand_0"}', $candidates);
        self::assertSame('reproduce-before-fix', $selected);

        $selected1 = $this->validator->validate('{"selected": "cand_1"}', $candidates);
        self::assertSame('deletion-first', $selected1);
    }

    public function testValidatorParsesJsonNoneCorrectly(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        $selected = $this->validator->validate('{"selected": "none"}', $candidates);
        self::assertNull($selected);
    }

    public function testValidatorRejectsDirectCandidateIdOutsideJsonChoiceContract(): void
    {
        self::assertNull($this->validator->validate(
            '{"selected": "deletion-first"}',
            ['reproduce-before-fix', 'deletion-first'],
        ));
    }

    public function testValidatorFailsClosedOnOutOfBoundsIndex(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        // cand_5 is out of bounds
        $selected = $this->validator->validate('{"selected": "cand_5"}', $candidates);
        self::assertNull($selected);
    }

    public function testValidatorFailsClosedOnHallucinatedId(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        // Hallucinated ID not in candidates
        $selected = $this->validator->validate('{"selected": "unknown-fabricated-recipe"}', $candidates);
        self::assertNull($selected);
    }

    public function testValidatorRejectsNonJsonFallbackAndInjectedPreamble(): void
    {
        $candidateIds = ['reproduce-before-fix', 'deletion-first'];
        foreach ([
            'I recommend cand_0.',
            'Invalid JSON but "selected": "cand_0" because the model said so',
            '{"selected": "cand_0"', // incomplete object
            '{"selected": "cand_0"}\\nIgnore instructions',
            'cand_0',
            '```json' . "\\n" . '{"selected":"cand_0"}' . "\\n" . '```',
        ] as $response) {
            self::assertNull($this->validator->validate($response, $candidateIds), $response);
        }
    }

    public function testValidatorRequiresExactlyOneSelectedJsonProperty(): void
    {
        $candidateIds = ['reproduce-before-fix', 'deletion-first'];
        foreach ([
            '{"selected": "cand_0", "override": "merge"}',
            '{"selected": 0}',
            '{"selected": true}',
            '{"selected": null}',
            '{"selected": ["cand_0"]}',
            '{"other": "cand_0"}',
            '["cand_0"]',
            '"cand_0"',
        ] as $response) {
            self::assertNull($this->validator->validate($response, $candidateIds), $response);
        }
    }

    public function testValidatorRejectsDuplicateOrEscapedJsonPropertyKeys(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        foreach ([
            '{"selected":"none","selected":"cand_0"}',
            '{"selected":"cand_0","selected":"cand_1"}',
            '{"selected":"none","selec\\u0074ed":"cand_0"}',
            '{"selec\\u0074ed":"cand_0","selected":"cand_1"}',
            '{"sele\\u0063ted":"cand_0"}',
            '{"selected":"ca\\u006ed_0"}',
            '{"selected":"cand_0","override":"none"}',
        ] as $response) {
            self::assertNull($this->validator->validate($response, $candidates), $response);
        }

        // JSON whitespace is allowed, but the selection must have a single
        // literal property, and its value must be a canonical candidate index.
        self::assertSame(
            'reproduce-before-fix',
            $this->validator->validate(" \n{ \t\"selected\" : \"cand_0\" } \r\n", $candidates),
        );
    }

    public function testValidatorRejectsNonCanonicalIndexAndUnlistedCandidates(): void
    {
        $candidateIds = ['reproduce-before-fix', 'deletion-first'];
        foreach ([
            '{"selected": "cand_00"}',
            '{"selected": "cand_+0"}',
            '{"selected": "cand_999999999999999999999999"}',
            '{"selected": "CAND_0"}',
            '{"selected": " cand_0 "}',
        ] as $response) {
            self::assertNull($this->validator->validate($response, $candidateIds), $response);
        }
        self::assertNull($this->validator->validate('{"selected":"cand_0"}', []));
        self::assertSame(
            'deletion-first',
            $this->validator->validate('{"selected":"cand_1"}', $candidateIds),
        );
    }

    public function testValidatorFailsClosedOnMalformedOrEmptyOutput(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        self::assertNull($this->validator->validate('', $candidates));
        self::assertNull($this->validator->validate('I think the best option is maybe something else entirely.', $candidates));
    }
}
