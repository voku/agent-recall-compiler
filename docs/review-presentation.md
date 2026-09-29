# Review prompt presentation

The review CLI accepts presentation options without changing deterministic audit findings or their report identity:

```bash
agent-recall-compiler review blindspots TASK-1 --language de --tone direct --focus 'rollback after timeout'
agent-recall-compiler review code TASK-1 --language de
```

`--language` accepts a BCP 47 language tag and asks the receiving reviewer to write translated headings and explanatory prose in that language. Source excerpts, identifiers, paths, citations, and required machine-readable status tokens remain exact. The generated prompt keeps its canonical internal instructions and evidence in their original language.

`--tone direct` asks for candid, evidence-based criticism. `--tone unflinching` asks reviewers to challenge unsupported confidence and name uncomfortable verified failures in forceful terms. Neither setting permits personal attacks, invented findings, or speculation about motives. `--focus` is an optional one-line scope hint, not an instruction or a filter that hides material defects. It is limited to 240 bytes. The defaults are `measured` tone and no focus.

The deterministic JSON and Markdown audit reports remain canonical evidence in English. The options affect generated semantic review prompts and the language of the receiving agent's answer. They do not execute the review or alter lifecycle authority.
