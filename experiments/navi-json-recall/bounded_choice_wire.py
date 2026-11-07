"""Literal bounded-choice wire protocol shared by Python experiments.

Mirrors the deliberately narrow PHP BoundedChoiceValidator grammar.
A model decision is only one JSON object with a literal "selected" property,
whose value is exactly "none" or an in-range canonical "cand_N" index.
Invalid and unavailable responses both map to None. Callers must count their
causes separately; None is not proof of semantic abstention.
"""
from __future__ import annotations

import re

_WIRE = re.compile(
    r'[ \t\r\n]*\{[ \t\r\n]*"selected"[ \t\r\n]*:[ \t\r\n]*'
    r'"(none|cand_(0|[1-9][0-9]*))"[ \t\r\n]*\}[ \t\r\n]*'
)


def validate_bounded_choice_response(raw: str, candidates: list[str]) -> str | None:
    """Return the verified source ID, or None on none/invalid/unavailable.

    The regex deliberately rejects duplicate keys, escaped JSON tokens,
    Unicode whitespace and noncanonical numbers *before* JSON decoding could
    silently collapse or normalize them.
    """
    try:
        if len(raw.encode("utf-8")) > 1024:
            return None
    except UnicodeError:
        return None
    match = _WIRE.fullmatch(raw)
    if match is None or match.group(1) == "none":
        return None
    digits = match.group(2)
    if digits is None or len(digits) > 9:
        return None
    index = int(digits)
    return candidates[index] if 0 <= index < len(candidates) else None
