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

    public function testValidatorParsesDirectCandidateId(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        $selected = $this->validator->validate('{"selected": "deletion-first"}', $candidates);
        self::assertSame('deletion-first', $selected);
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

    public function testValidatorFailsClosedOnMalformedOrEmptyOutput(): void
    {
        $candidates = ['reproduce-before-fix', 'deletion-first'];

        self::assertNull($this->validator->validate('', $candidates));
        self::assertNull($this->validator->validate('I think the best option is maybe something else entirely.', $candidates));
    }
}
