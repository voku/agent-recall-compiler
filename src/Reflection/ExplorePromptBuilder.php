<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler\Reflection;

final class ExplorePromptBuilder
{
    public function build(): string
    {
        return <<<'PROMPT'
Ask yourself: "Is there anything here worth exploring further?"

Explore the context actually available, whether it comes from a conversation, research, architecture discussion, source investigation, or unfinished task. Do not assume that work is complete, that a repository is involved, or that the user wants another task. Exploration is curiosity-driven inquiry, not execution.

Identify one surprising connection, unresolved contradiction, hidden assumption, alternative explanation, or useful question that the available evidence makes worth investigating. Separate observed facts from hypotheses and unknowns; do not invent supporting facts, unrelated opportunities, or a reason to explore. Prefer an inexpensive, non-mutating way to learn something that could change our understanding.

Return either `EXPLORE` with the question, its specific evidence anchor, why the answer matters, and the smallest investigative probe, or `NOTHING_TO_EXPLORE` with a brief reason. Both are valid outcomes.

Do not plan or authorize implementation, modify artifacts, create backlog, treat curiosity as a new goal, or convert exploration into an execution Contract. If exploration suggests possible work, describe it only as an unapproved implication for a separate decision.
PROMPT;
    }
}
